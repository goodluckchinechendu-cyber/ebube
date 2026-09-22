<?php
/**
 * Helpers for Ebube injected wallets vs SMobile master balance.
 * MoMo / VTU / Logical are local ledgers. SMobile is the real provider wallet
 * (Super Admin only).
 */

/**
 * Sum of user wallet balances currently injected.
 * Returns null on query failure (callers must fail closed — never treat as 0).
 */
function wallet_sum_injected(mysqli $mysqli): ?float
{
    $sql = 'SELECT
        COALESCE(SUM(momo_balance), 0) +
        COALESCE(SUM(vtu_balance), 0) +
        COALESCE(SUM(logical_balance), 0) AS total
        FROM users';
    $result = $mysqli->query($sql);
    if (!$result) {
        return null;
    }
    $row = $result->fetch_assoc();
    return (float) ($row['total'] ?? 0);
}

/**
 * Amount still reserved in open purchase holds (already debited from user wallets).
 * Must be counted in pool capacity so Super Admin cannot re-inject the gap.
 */
function wallet_sum_held(mysqli $mysqli): ?float
{
    $result = $mysqli->query(
        "SELECT COALESCE(SUM(amount), 0) AS total
         FROM vtu_wallet_holds
         WHERE status = 'held'"
    );
    if (!$result) {
        return null;
    }
    $row = $result->fetch_assoc();
    return (float) ($row['total'] ?? 0);
}

/**
 * Float reserved against SMobile: live balances + in-flight holds.
 * @return array{ok:bool,injected:float,held:float,reserved:float,message?:string}
 */
function wallet_reserved_float(mysqli $mysqli): array
{
    $injected = wallet_sum_injected($mysqli);
    if ($injected === null) {
        return [
            'ok' => false,
            'injected' => 0.0,
            'held' => 0.0,
            'reserved' => 0.0,
            'message' => 'Could not sum injected wallets',
        ];
    }
    $held = wallet_sum_held($mysqli);
    if ($held === null) {
        return [
            'ok' => false,
            'injected' => $injected,
            'held' => 0.0,
            'reserved' => 0.0,
            'message' => 'Could not sum held purchase wallets',
        ];
    }
    return [
        'ok' => true,
        'injected' => $injected,
        'held' => $held,
        'reserved' => $injected + $held,
    ];
}

/**
 * @return array{smobile_balance:float, injected_total:float, held_total:float, available_to_inject:float, currency:string, success:bool, message?:string}
 */
function wallet_pool_snapshot(mysqli $mysqli): array
{
    $reserved = wallet_reserved_float($mysqli);
    if (empty($reserved['ok'])) {
        return [
            'success' => false,
            'message' => (string) ($reserved['message'] ?? 'Could not calculate reserved float'),
            'smobile_balance' => 0.0,
            'injected_total' => (float) ($reserved['injected'] ?? 0),
            'held_total' => (float) ($reserved['held'] ?? 0),
            'available_to_inject' => 0.0,
            'currency' => 'NGN',
            'response_code' => 500,
        ];
    }

    $injected = (float) $reserved['injected'];
    $held = (float) $reserved['held'];
    $reservedTotal = (float) $reserved['reserved'];

    $smobileResult = smobile_vtu_request('GET', '/v1/balance');
    $body = $smobileResult['body'] ?? [];
    $ok = !empty($body['success']);
    $smobile = isset($body['balance']) ? (float) $body['balance'] : 0.0;
    $currency = (string) ($body['currency'] ?? 'NGN');

    if (!$ok) {
        return [
            'success' => false,
            'message' => (string) ($body['message'] ?? 'Could not read SMobile wallet balance'),
            'smobile_balance' => $smobile,
            'injected_total' => $injected,
            'held_total' => $held,
            'available_to_inject' => 0.0,
            'currency' => $currency,
            'response_code' => (int) ($body['response_code'] ?? $smobileResult['http_code'] ?? 502),
        ];
    }

    $available = $smobile - $reservedTotal;
    if ($available < 0) {
        $available = 0.0;
    }

    return [
        'success' => true,
        'smobile_balance' => $smobile,
        'injected_total' => $injected,
        'held_total' => $held,
        'available_to_inject' => $available,
        'currency' => $currency,
        'response_code' => 200,
    ];
}

function wallet_column_for_product(string $product): ?string
{
    $product = strtolower(trim($product));
    return match ($product) {
        'momo' => 'momo_balance',
        'vtu' => 'vtu_balance',
        'logical' => 'logical_balance',
        default => null,
    };
}

function wallet_product_label(string $product): string
{
    return match (strtolower(trim($product))) {
        'momo' => 'MoMo Airtime',
        'vtu' => 'VTU Airtime',
        'logical' => 'Logical Airtime',
        default => strtoupper($product),
    };
}

function fetch_user_role_row(mysqli $mysqli, int $userId): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT id, role, full_name, momo_balance, vtu_balance, logical_balance,
                data_bundle_balance, commission_balance
         FROM users WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Atomically debit a local wallet. Returns true if deducted.
 */
function wallet_debit(mysqli $mysqli, int $userId, string $product, float $amount): bool
{
    $col = wallet_column_for_product($product);
    if ($col === null || $amount <= 0) {
        return false;
    }
    $sql = "UPDATE users SET `$col` = `$col` - ? WHERE id = ? AND `$col` >= ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('did', $amount, $userId, $amount);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

/**
 * Credit/refund a local wallet.
 */
function wallet_credit(mysqli $mysqli, int $userId, string $product, float $amount): bool
{
    $col = wallet_column_for_product($product);
    if ($col === null || $amount <= 0) {
        return false;
    }
    $sql = "UPDATE users SET `$col` = `$col` + ? WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('di', $amount, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Resolve a data plan's catalog amount from SMobile (ignores client-supplied price).
 *
 * @return array{amount:float,name:string}|null
 */
function smobile_resolve_plan_price(string $planId, ?string $network = null): ?array
{
    $planId = trim($planId);
    if ($planId === '') {
        return null;
    }

    $query = [];
    if ($network !== null && $network !== '') {
        $query['network'] = $network;
    }

    $result = smobile_vtu_request('GET', '/v1/plans', null, $query);
    $body = is_array($result['body'] ?? null) ? $result['body'] : [];
    $plans = smobile_vtu_flatten_plans($body, $network);

    foreach ($plans as $plan) {
        if (!is_array($plan)) {
            continue;
        }
        $id = trim((string) ($plan['plan_id'] ?? $plan['id'] ?? $plan['planId'] ?? ''));
        if ($id === '' || strcasecmp($id, $planId) !== 0) {
            continue;
        }
        $amountRaw = $plan['amount']
            ?? $plan['price']
            ?? $plan['plan_amount']
            ?? $plan['selling_price']
            ?? $plan['amount_naira']
            ?? null;
        if ($amountRaw === null) {
            return null;
        }
        $amount = (float) $amountRaw;
        $name = trim((string) (
            $plan['name']
            ?? $plan['plan_name']
            ?? $plan['label']
            ?? $plan['data']
            ?? $plan['description']
            ?? $planId
        ));
        return [
            'amount' => $amount,
            'name' => $name !== '' ? $name : $planId,
        ];
    }

    return null;
}

/**
 * Upsert an agent_transactions row for a VTU purchase.
 */
function vtu_upsert_agent_transaction(
    mysqli $mysqli,
    string $receiptId,
    int $userId,
    string $customerName,
    string $product,
    float $total,
    string $status,
    string $servedBy,
    string $phone = '',
    string $network = 'MTN'
): void {
    $txnAt = date('Y-m-d H:i:s');
    $qty = 1;
    $customerId = 0;
    if ($phone !== '' && ($customerName === '' || $customerName === $phone)) {
        $customerName = $phone;
    }
    if ($network === '') {
        $network = 'MTN';
    }
    $ins = $mysqli->prepare(
        'INSERT INTO agent_transactions
        (receipt_id, wallet_user_id, customer_id, customer_name, phone, network, product, qty, total, status, served_by, transaction_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        customer_name = VALUES(customer_name),
        phone = VALUES(phone),
        network = VALUES(network),
        product = VALUES(product),
        total = VALUES(total),
        status = VALUES(status),
        served_by = VALUES(served_by),
        transaction_at = VALUES(transaction_at)'
    );
    if (!$ins) {
        return;
    }
    $ins->bind_param(
        'siissssidsss',
        $receiptId,
        $userId,
        $customerId,
        $customerName,
        $phone,
        $network,
        $product,
        $qty,
        $total,
        $status,
        $servedBy,
        $txnAt
    );
    if (!$ins->execute()) {
        error_log('vtu_upsert_agent_transaction failed: ' . $ins->error);
    }
    $ins->close();
}

/**
 * Move a provisional statement receipt_id to the final one (kills orphan "Processing" rows).
 */
function vtu_agent_transaction_rekey(
    mysqli $mysqli,
    string $fromReceiptId,
    string $toReceiptId,
    ?string $status = null
): void {
    $fromReceiptId = trim($fromReceiptId);
    $toReceiptId = trim($toReceiptId);
    if ($fromReceiptId === '' || $toReceiptId === '' || $fromReceiptId === $toReceiptId) {
        return;
    }

    if ($status !== null && $status !== '') {
        $stmt = $mysqli->prepare(
            'UPDATE agent_transactions
             SET receipt_id = ?, status = ?
             WHERE receipt_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('sss', $toReceiptId, $status, $fromReceiptId);
            $ok = $stmt->execute();
            $err = $stmt->errno;
            $stmt->close();
            if ($ok) {
                return;
            }
            // Duplicate key: final receipt already exists — drop the provisional Processing row.
            if ($err === 1062) {
                $upd = $mysqli->prepare(
                    'UPDATE agent_transactions SET status = ? WHERE receipt_id = ?'
                );
                if ($upd) {
                    $upd->bind_param('ss', $status, $toReceiptId);
                    $upd->execute();
                    $upd->close();
                }
                $del = $mysqli->prepare('DELETE FROM agent_transactions WHERE receipt_id = ? LIMIT 1');
                if ($del) {
                    $del->bind_param('s', $fromReceiptId);
                    $del->execute();
                    $del->close();
                }
            }
            return;
        }
    }

    $stmt = $mysqli->prepare(
        'UPDATE agent_transactions SET receipt_id = ? WHERE receipt_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('ss', $toReceiptId, $fromReceiptId);
        $ok = $stmt->execute();
        $err = $stmt->errno;
        $stmt->close();
        if ($ok) {
            return;
        }
        if ($err === 1062) {
            $del = $mysqli->prepare('DELETE FROM agent_transactions WHERE receipt_id = ? LIMIT 1');
            if ($del) {
                $del->bind_param('s', $fromReceiptId);
                $del->execute();
                $del->close();
            }
        }
    }
}

/**
 * Create or refresh a wallet hold keyed by provider reference.
 */
function vtu_hold_save(
    mysqli $mysqli,
    string $reference,
    string $receiptId,
    int $userId,
    string $walletProduct,
    float $amount,
    string $productLabel,
    string $phone,
    string $servedBy,
    string $status = 'held',
    ?float $faceAmount = null,
    ?string $clientRequestId = null
): bool {
    // Optional face_amount column (sale face before Ebube discount).
    $col = $mysqli->query("SHOW COLUMNS FROM `vtu_wallet_holds` LIKE 'face_amount'");
    if ($col && $col->num_rows === 0) {
        $mysqli->query(
            "ALTER TABLE `vtu_wallet_holds`
             ADD COLUMN `face_amount` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `amount`"
        );
    }
    vtu_holds_ensure_client_request_id($mysqli);

    $face = $faceAmount !== null && $faceAmount > 0 ? $faceAmount : $amount;
    $clientRequestId = $clientRequestId !== null ? trim($clientRequestId) : '';
    if ($clientRequestId === '') {
        $clientRequestId = null;
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO vtu_wallet_holds
        (reference, client_request_id, receipt_id, user_id, wallet_product, amount, face_amount, product_label, phone, served_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        receipt_id = VALUES(receipt_id),
        amount = VALUES(amount),
        face_amount = VALUES(face_amount),
        product_label = VALUES(product_label),
        phone = VALUES(phone),
        served_by = VALUES(served_by),
        status = IF(status = \'refunded\' OR status = \'completed\', status, VALUES(status))'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'sssisddssss',
        $reference,
        $clientRequestId,
        $receiptId,
        $userId,
        $walletProduct,
        $amount,
        $face,
        $productLabel,
        $phone,
        $servedBy,
        $status
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Ensure optional client_request_id column exists for purchase idempotency.
 */
function vtu_holds_ensure_client_request_id(mysqli $mysqli): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $col = $mysqli->query("SHOW COLUMNS FROM `vtu_wallet_holds` LIKE 'client_request_id'");
    if ($col && $col->num_rows === 0) {
        $mysqli->query(
            "ALTER TABLE `vtu_wallet_holds`
             ADD COLUMN `client_request_id` VARCHAR(64) NULL DEFAULT NULL AFTER `reference`,
             ADD UNIQUE KEY `uniq_hold_client_request` (`client_request_id`)"
        );
    }
    $done = true;
}

/**
 * Find a hold by client request id (idempotent purchase replay).
 */
function vtu_hold_find_by_client_request(mysqli $mysqli, string $clientRequestId): ?array
{
    $clientRequestId = trim($clientRequestId);
    if ($clientRequestId === '') {
        return null;
    }
    vtu_holds_ensure_client_request_id($mysqli);
    $stmt = $mysqli->prepare(
        'SELECT id, reference, receipt_id, user_id, wallet_product, amount, face_amount,
                product_label, phone, served_by, status, client_request_id, created_at
         FROM vtu_wallet_holds WHERE client_request_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $clientRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Move a provisional hold reference to the provider reference.
 */
function vtu_hold_rekey(mysqli $mysqli, string $fromReference, string $toReference, string $receiptId): bool
{
    $fromReference = trim($fromReference);
    $toReference = trim($toReference);
    if ($fromReference === '' || $toReference === '' || $fromReference === $toReference) {
        return $fromReference !== '' && $toReference !== '';
    }

    $oldReceipt = '';
    $sel = $mysqli->prepare(
        'SELECT receipt_id FROM vtu_wallet_holds WHERE reference = ? AND status = \'held\' LIMIT 1'
    );
    if ($sel) {
        $sel->bind_param('s', $fromReference);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        $oldReceipt = trim((string) ($row['receipt_id'] ?? ''));
    }

    $stmt = $mysqli->prepare(
        'UPDATE vtu_wallet_holds
         SET reference = ?, receipt_id = ?
         WHERE reference = ? AND status = \'held\''
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $toReference, $receiptId, $fromReference);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if ($ok && $oldReceipt !== '' && $oldReceipt !== $receiptId) {
        vtu_agent_transaction_rekey($mysqli, $oldReceipt, $receiptId, null);
    }
    // Always drop leftover provisional Processing rows for this hold's client key.
    vtu_purge_provisional_processing_orphans($mysqli, $toReference, $receiptId);
    return $ok;
}

/**
 * Remove leftover provisional statement rows after a hold was rekeyed/completed.
 * Provisional receipt is VTU-{client_request_id}; final is VTU-{provider_ref}.
 */
function vtu_purge_provisional_processing_orphans(
    mysqli $mysqli,
    ?string $providerReference = null,
    ?string $finalReceiptId = null
): int {
    $purged = 0;

    // 1) Holds that settled under a different receipt than VTU-{client_request_id}.
    $sql = "DELETE t FROM agent_transactions t
            INNER JOIN vtu_wallet_holds h
              ON h.client_request_id IS NOT NULL
             AND h.client_request_id <> ''
             AND t.receipt_id = CONCAT('VTU-', h.client_request_id)
            WHERE t.status IN ('Processing', 'processing', 'Pending', 'pending')
              AND h.receipt_id <> t.receipt_id
              AND h.status IN ('completed', 'refunded', 'held')";
    if ($mysqli->query($sql)) {
        $purged += (int) $mysqli->affected_rows;
    }

    // 2) Explicit final receipt: drop any other Processing row that shares the hold.
    if ($finalReceiptId !== null && $finalReceiptId !== '') {
        $stmt = $mysqli->prepare(
            "DELETE t FROM agent_transactions t
             INNER JOIN vtu_wallet_holds h ON h.receipt_id = ?
             WHERE t.receipt_id <> ?
               AND t.status IN ('Processing', 'processing', 'Pending', 'pending')
               AND (
                 (h.client_request_id IS NOT NULL AND t.receipt_id = CONCAT('VTU-', h.client_request_id))
                 OR (h.reference IS NOT NULL AND t.receipt_id = CONCAT('VTU-', h.reference))
               )"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $finalReceiptId, $finalReceiptId);
            if ($stmt->execute()) {
                $purged += (int) $stmt->affected_rows;
            }
            $stmt->close();
        }
    }

    return $purged;
}

/**
 * Mark hold completed (idempotent).
 * Credits commission while still held, then flips status — crash-safe for retries.
 */
function vtu_hold_complete(mysqli $mysqli, string $reference, ?float $finalAmount = null): bool
{
    $stmt = $mysqli->prepare(
        'SELECT id, receipt_id, user_id, wallet_product, amount, face_amount, product_label, phone, served_by, status
         FROM vtu_wallet_holds WHERE reference = ? LIMIT 1'
    );
    if (!$stmt) {
        // Older schema without face_amount.
        $stmt = $mysqli->prepare(
            'SELECT id, receipt_id, user_id, wallet_product, amount, product_label, phone, served_by, status
             FROM vtu_wallet_holds WHERE reference = ? LIMIT 1'
        );
    }
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    if (($row['status'] ?? '') === 'completed') {
        return true;
    }
    if (($row['status'] ?? '') === 'refunded') {
        return false;
    }

    $amount = $finalAmount !== null && $finalAmount > 0
        ? $finalAmount
        : (float) $row['amount'];
    $faceForCommission = isset($row['face_amount']) && (float) $row['face_amount'] > 0
        ? (float) $row['face_amount']
        : $amount;
    $id = (int) $row['id'];

    $product = (string) $row['product_label'] . ' via ' . wallet_product_label((string) $row['wallet_product']);
    vtu_upsert_agent_transaction(
        $mysqli,
        (string) $row['receipt_id'],
        (int) $row['user_id'],
        (string) $row['phone'],
        $product,
        $amount,
        'Completed',
        (string) $row['served_by'],
        (string) $row['phone'],
        'MTN'
    );

    if (!function_exists('credit_vtu_commission')) {
        require_once __DIR__ . '/commission_tiers_util.php';
    }
    $role = 1;
    $roleStmt = $mysqli->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    if ($roleStmt) {
        $uid = (int) $row['user_id'];
        $roleStmt->bind_param('i', $uid);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        if ($roleRow) {
            $role = (int) ($roleRow['role'] ?? 1);
        }
    }
    // Commission first (idempotent by reference). Status flip last so retries recover.
    credit_vtu_commission(
        $mysqli,
        (int) $row['user_id'],
        vtu_product_type_from_label((string) $row['product_label']),
        $faceForCommission,
        $reference,
        $role
    );

    $upd = $mysqli->prepare(
        'UPDATE vtu_wallet_holds SET status = \'completed\', amount = ? WHERE id = ? AND status = \'held\''
    );
    if (!$upd) {
        return false;
    }
    $upd->bind_param('di', $amount, $id);
    $upd->execute();
    $upd->close();
    // If another worker already completed, commission was still safe (idempotent).
    vtu_purge_provisional_processing_orphans($mysqli, $reference, (string) $row['receipt_id']);
    return true;
}

/**
 * Refund held wallet on provider failure (idempotent).
 * Credits first, then marks refunded; reverts to held if credit fails.
 */
function vtu_hold_refund(mysqli $mysqli, string $reference): bool
{
    $stmt = $mysqli->prepare(
        'SELECT id, receipt_id, user_id, wallet_product, amount, product_label, phone, served_by, status
         FROM vtu_wallet_holds WHERE reference = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    if (($row['status'] ?? '') === 'refunded') {
        return true;
    }
    if (($row['status'] ?? '') === 'completed') {
        return false;
    }

    $amount = (float) $row['amount'];
    $userId = (int) $row['user_id'];
    $walletProduct = (string) $row['wallet_product'];
    $id = (int) $row['id'];

    if (!wallet_credit($mysqli, $userId, $walletProduct, $amount)) {
        // Leave status=held so a later retry can still refund.
        return false;
    }

    $upd = $mysqli->prepare(
        'UPDATE vtu_wallet_holds SET status = \'refunded\' WHERE id = ? AND status = \'held\''
    );
    if (!$upd) {
        // Credit already applied — do not reverse here; mark failed for ops.
        error_log('vtu_hold_refund: credited but could not mark refunded for hold id ' . $id);
        return false;
    }
    $upd->bind_param('i', $id);
    $upd->execute();
    $won = $upd->affected_rows === 1;
    $upd->close();
    if (!$won) {
        // Another worker settled concurrently after our credit — reverse the extra credit.
        $col = wallet_column_for_product($walletProduct);
        if ($col !== null) {
            $rev = $mysqli->prepare(
                "UPDATE users SET `$col` = `$col` - ? WHERE id = ? AND `$col` >= ?"
            );
            if ($rev) {
                $rev->bind_param('did', $amount, $userId, $amount);
                $rev->execute();
                $rev->close();
            }
        }
        return true;
    }

    $product = (string) $row['product_label'] . ' via ' . wallet_product_label($walletProduct);
    vtu_upsert_agent_transaction(
        $mysqli,
        (string) $row['receipt_id'],
        $userId,
        (string) $row['phone'],
        $product,
        $amount,
        'Failed',
        (string) $row['served_by'],
        (string) $row['phone'],
        'MTN'
    );
    return true;
}

/** Seconds before an unrekeyed PEND-* hold is auto-refunded. */
function vtu_hold_provisional_ttl_seconds(): int
{
    return 900;
}

/**
 * Try to settle a held wallet row: TTL-refund provisional PEND-* refs, or poll
 * the provider for a real reference and complete/refund.
 *
 * @return array{outcome:string,message:?string,status:?string}
 *   outcome: completed|refunded|still_held|skipped
 */
function vtu_hold_try_settle(mysqli $mysqli, array $hold): array
{
    $status = strtolower(trim((string) ($hold['status'] ?? '')));
    if ($status === 'completed') {
        return ['outcome' => 'completed', 'message' => null, 'status' => 'completed'];
    }
    if ($status === 'refunded') {
        return ['outcome' => 'refunded', 'message' => null, 'status' => 'refunded'];
    }
    if ($status !== 'held' && $status !== '') {
        return ['outcome' => 'skipped', 'message' => 'Hold not in held state', 'status' => $status];
    }

    $reference = trim((string) ($hold['reference'] ?? ''));
    if ($reference === '') {
        return ['outcome' => 'still_held', 'message' => 'Missing hold reference', 'status' => 'held'];
    }

    $createdAt = strtotime((string) ($hold['created_at'] ?? '')) ?: time();
    $ageSec = max(0, time() - $createdAt);
    $isProvisional = str_starts_with($reference, 'PEND-');

    if ($isProvisional) {
        if ($ageSec < vtu_hold_provisional_ttl_seconds()) {
            return [
                'outcome' => 'still_held',
                'message' => 'Provisional hold waiting for provider reference',
                'status' => 'held',
            ];
        }
        $ok = vtu_hold_refund($mysqli, $reference);
        return [
            'outcome' => $ok ? 'refunded' : 'still_held',
            'message' => $ok
                ? 'Purchase timed out before provider confirmation; injected wallet was refunded.'
                : 'Could not refund timed-out provisional hold',
            'status' => $ok ? 'refunded' : 'held',
        ];
    }

    if (!function_exists('smobile_vtu_request')) {
        require_once __DIR__ . '/smobile_vtu_client.php';
    }

    $poll = smobile_vtu_request('GET', '/v1/transaction/' . rawurlencode($reference));
    $pBody = is_array($poll['body'] ?? null) ? $poll['body'] : [];
    $info = smobile_vtu_status_info($pBody, (int) ($poll['http_code'] ?? 0), true);
    $pStatus = $info['class'];
    $pStatusRaw = $info['raw'];

    if ($info['success']) {
        $amount = isset($hold['amount']) ? (float) $hold['amount'] : null;
        $ok = vtu_hold_complete($mysqli, $reference, $amount !== null && $amount > 0 ? $amount : null);
        return [
            'outcome' => $ok ? 'completed' : 'still_held',
            'message' => $ok ? 'Purchase completed' : 'Provider succeeded but local complete failed',
            'status' => $ok ? 'completed' : 'held',
        ];
    }

    if ($info['failed']) {
        $ok = vtu_hold_refund($mysqli, $reference);
        return [
            'outcome' => $ok ? 'refunded' : 'still_held',
            'message' => $ok
                ? (string) ($pBody['message'] ?? 'Purchase failed; local wallet was refunded')
                : 'Provider failed but local refund failed',
            'status' => $ok ? 'refunded' : 'held',
        ];
    }

    // Provider still processing, or unknown/empty status — leave held.
    // Very old non-provisional holds with no usable provider status: refund after 2h.
    if ($ageSec >= 7200 && ($pStatusRaw === '' || in_array($pStatusRaw, ['unknown', 'not_found', 'not found'], true))) {
        $ok = vtu_hold_refund($mysqli, $reference);
        return [
            'outcome' => $ok ? 'refunded' : 'still_held',
            'message' => $ok
                ? 'Hold expired with no provider confirmation; injected wallet was refunded.'
                : 'Could not refund expired hold',
            'status' => $ok ? 'refunded' : 'held',
        ];
    }

    return [
        'outcome' => 'still_held',
        'message' => 'Purchase still processing at provider',
        'status' => 'held',
    ];
}

/**
 * Align agent_transactions.status with a settled hold (fixes orphan Processing UI).
 */
function vtu_sync_agent_transaction_from_hold(mysqli $mysqli, array $hold): void
{
    $receiptId = trim((string) ($hold['receipt_id'] ?? ''));
    if ($receiptId === '') {
        return;
    }
    $holdStatus = strtolower(trim((string) ($hold['status'] ?? '')));
    $txnStatus = null;
    if ($holdStatus === 'completed') {
        $txnStatus = 'Completed';
    } elseif ($holdStatus === 'refunded') {
        $txnStatus = 'Failed';
    }
    if ($txnStatus === null) {
        return;
    }
    $upd = $mysqli->prepare(
        "UPDATE agent_transactions
         SET status = ?
         WHERE receipt_id = ?
           AND status IN ('Processing', 'processing', 'Pending', 'pending')"
    );
    if ($upd) {
        $upd->bind_param('ss', $txnStatus, $receiptId);
        $upd->execute();
        $upd->close();
    }
}
