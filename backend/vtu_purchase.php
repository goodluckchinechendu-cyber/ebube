<?php
/**
 * Buy airtime/data:
 * 1) Debit user's chosen injected wallet (momo/vtu/logical)
 * 2) Purchase on SMobile master wallet
 * 3) Refund local wallet if provider fails
 * 4) Record agent_transactions row
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/smobile_vtu_client.php';
require_once __DIR__ . '/wallet_pool.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/commission_tiers_util.php';
require_once __DIR__ . '/transaction_limits_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode([
        'response_code' => 500,
        'success' => false,
        'message' => 'Database setup failed: ' . $schemaError,
    ]);
    exit;
}

$sessionUser = ec_require_session($mysqli);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'response_code' => 400,
        'success' => false,
        'message' => 'Invalid JSON body',
    ]);
    exit;
}

$userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
$walletProduct = strtolower(trim((string) ($data['wallet_product'] ?? '')));
$type = strtolower(trim((string) ($data['type'] ?? '')));
$network = smobile_vtu_normalize_network($data['network'] ?? null);
$phone = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));

if ($userId <= 0) {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'user_id is required',
    ]);
    exit;
}

// Purchases must run as the authenticated user (no spoofing another wallet).
if ($userId !== (int) $sessionUser['id']) {
    http_response_code(403);
    echo json_encode([
        'response_code' => 403,
        'success' => false,
        'message' => 'user_id must match authenticated session',
    ]);
    exit;
}

$txnPin = trim((string) ($data['transaction_pin'] ?? $data['txn_pin'] ?? ''));
if ($txnPin === '' || !preg_match('/^\d{4}$/', $txnPin)) {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'transaction_pin (4 digits) is required',
        'needs_transaction_pin' => true,
    ]);
    exit;
}

require_once __DIR__ . '/txn_pin_guard.php';
$lockStatus = txn_pin_guard_status($mysqli, $userId);
if (!$lockStatus['ok']) {
    http_response_code(423);
    echo json_encode([
        'response_code' => 423,
        'success' => false,
        'message' => $lockStatus['message'] ?? 'Transaction PIN locked',
        'locked_until' => $lockStatus['locked_until'] ?? null,
    ]);
    exit;
}

$pinStmt = $mysqli->prepare('SELECT transaction_pin_hash FROM users WHERE id = ? LIMIT 1');
$pinStmt->bind_param('i', $userId);
$pinStmt->execute();
$pinRow = $pinStmt->get_result()->fetch_assoc();
$pinStmt->close();
$pinHash = trim((string) ($pinRow['transaction_pin_hash'] ?? ''));
if ($pinHash === '') {
    http_response_code(403);
    echo json_encode([
        'response_code' => 403,
        'success' => false,
        'message' => 'Create a transaction PIN before buying',
        'needs_setup' => true,
    ]);
    exit;
}
if (!password_verify($txnPin, $pinHash)) {
    $fail = txn_pin_guard_fail($mysqli, $userId);
    http_response_code(!empty($fail['locked']) ? 423 : 422);
    echo json_encode([
        'response_code' => !empty($fail['locked']) ? 423 : 422,
        'success' => false,
        'message' => $fail['message'],
        'remaining_attempts' => $fail['remaining_attempts'] ?? 0,
        'locked' => !empty($fail['locked']),
    ]);
    exit;
}
txn_pin_guard_clear($mysqli, $userId);

if (wallet_column_for_product($walletProduct) === null) {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'wallet_product must be momo, vtu, or logical',
    ]);
    exit;
}

if ($type !== 'airtime' && $type !== 'data') {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'type must be airtime or data',
    ]);
    exit;
}

if ($network === null || $network === '') {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'network is required',
    ]);
    exit;
}

// EbubeConnect is MTN-only for airtime and data.
$networkKey = is_string($network) ? strtoupper(trim($network)) : (string) $network;
if ($networkKey !== 'MTN' && $networkKey !== '1') {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'Only MTN is supported on EbubeConnect',
    ]);
    exit;
}
$network = 'MTN';

$phoneLen = strlen((string) $phone);
if ($phoneLen < 10 || $phoneLen > 15) {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'phone must be 10–15 digits',
    ]);
    exit;
}

$user = fetch_user_role_row($mysqli, $userId);
if (!$user) {
    http_response_code(404);
    echo json_encode([
        'response_code' => 404,
        'success' => false,
        'message' => 'User not found',
    ]);
    exit;
}

$payload = [
    'type' => $type,
    'network' => $network,
    'phone' => $phone,
];

$faceAmount = 0.0;
$productLabel = '';

if ($type === 'airtime') {
    $amount = isset($data['amount']) ? (int) $data['amount'] : 0;
    if ($amount < 50) {
        http_response_code(422);
        echo json_encode([
            'response_code' => 422,
            'success' => false,
            'message' => 'amount must be at least ₦50',
        ]);
        exit;
    }
    $payload['amount'] = $amount;
    $faceAmount = (float) $amount;
    $productLabel = 'Airtime ' . (is_string($network) ? $network : ('Net' . $network));
} else {
    $planId = trim((string) ($data['plan_id'] ?? ''));
    if ($planId === '') {
        http_response_code(422);
        echo json_encode([
            'response_code' => 422,
            'success' => false,
            'message' => 'plan_id is required for data purchases',
        ]);
        exit;
    }
    $payload['plan_id'] = $planId;

    // Never trust client plan_amount — resolve catalog price from SMobile.
    $resolved = smobile_resolve_plan_price($planId, is_string($network) ? $network : null);
    if ($resolved === null || (float) $resolved['amount'] <= 0) {
        http_response_code(422);
        echo json_encode([
            'response_code' => 422,
            'success' => false,
            'message' => 'Could not verify data plan price from provider catalog',
            'plan_id' => $planId,
        ]);
        exit;
    }
    $faceAmount = (float) $resolved['amount'];
    $planName = trim((string) ($resolved['name'] ?? $data['plan_name'] ?? $planId));
    $productLabel = 'Data ' . (is_string($network) ? $network : ('Net' . $network)) . ' ' . $planName;
}

// Per-wallet transaction limits (VTU / MoMo / Logical) — Super Admin configures.
$userRole = (int) ($user['role'] ?? 0);
$buyerId = (int) ($user['id'] ?? 0);
$limitCheck = product_transaction_limits_check(
    $mysqli,
    $buyerId,
    $userRole,
    $walletProduct,
    $faceAmount,
    (string) $phone
);
if (is_array($limitCheck) && empty($limitCheck['ok'])) {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => $limitCheck['message'] ?? 'Recharge limit exceeded',
        'wallet_product' => $walletProduct,
        'max_per_transaction' => $limitCheck['max_per_transaction'] ?? null,
        'daily_limit' => $limitCheck['daily_limit'] ?? null,
        'max_per_sim' => $limitCheck['max_per_sim'] ?? null,
        'used' => $limitCheck['used'] ?? null,
        'remaining' => $limitCheck['remaining'] ?? null,
        'sim_used' => $limitCheck['sim_used'] ?? null,
        'sim_remaining' => $limitCheck['sim_remaining'] ?? null,
    ]);
    exit;
}

// Ebube Commission & Discount rules control injected-wallet charge (not SMobile bill).
$pricing = resolve_vtu_pricing($mysqli, $type, $faceAmount, $userRole);
$discountAmount = 0.0;
$chargeAmount = $faceAmount;
if (is_array($pricing)) {
    $discountAmount = (float) ($pricing['discount'] ?? 0);
    $chargeAmount = (float) ($pricing['wallet_charge'] ?? $faceAmount);
}
if ($chargeAmount < 0) {
    $chargeAmount = 0.0;
}

$col = wallet_column_for_product($walletProduct);
$localBal = (float) ($user[$col] ?? 0);
// Hard rule: buy only against injected wallet — SMobile master balance is irrelevant here.
if ($localBal + 0.00001 < $chargeAmount) {
    http_response_code(400);
    echo json_encode([
        'response_code' => 400,
        'success' => false,
        'message' => 'Insufficient ' . wallet_product_label($walletProduct) . ' wallet balance',
        'wallet_product' => $walletProduct,
        'wallet_balance' => $localBal,
        'face_value' => $faceAmount,
        'discount' => $discountAmount,
        'required' => $chargeAmount,
    ]);
    exit;
}

$servedBy = (string) ($user['full_name'] ?? 'Agent');
$productFull = $productLabel . ' via ' . wallet_product_label($walletProduct);
$txnAt = date('Y-m-d H:i:s');

// Client idempotency key — retries with the same id must not double-debit.
$clientRequestId = trim((string) ($data['client_request_id'] ?? ''));
if ($clientRequestId === '' || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $clientRequestId)) {
    $clientRequestId = 'srv-' . bin2hex(random_bytes(16));
}

$existingHold = vtu_hold_find_by_client_request($mysqli, $clientRequestId);
if ($existingHold) {
    $holdStatus = strtolower((string) ($existingHold['status'] ?? ''));
    $existingRef = (string) ($existingHold['reference'] ?? '');
    $existingReceipt = (string) ($existingHold['receipt_id'] ?? '');
    $updated = fetch_user_role_row($mysqli, $userId);
    if ($holdStatus === 'completed') {
        smobile_vtu_respond([
            'http_code' => 200,
            'body' => [
                'success' => true,
                'status' => 'success',
                'message' => 'Purchase already completed',
                'idempotent_replay' => true,
                'reference' => $existingRef,
                'receipt_id' => $existingReceipt,
                'amount_charged' => (float) ($existingHold['amount'] ?? $chargeAmount),
                'face_value' => (float) ($existingHold['face_amount'] ?? $faceAmount),
                'transaction_at' => $existingHold['created_at'] ?? $txnAt,
                'wallet_product' => $walletProduct,
                'momo_balance' => (float) ($updated['momo_balance'] ?? 0),
                'vtu_balance' => (float) ($updated['vtu_balance'] ?? 0),
                'logical_balance' => (float) ($updated['logical_balance'] ?? 0),
            ],
        ]);
        $mysqli->close();
        exit;
    }
    if ($holdStatus === 'refunded') {
        smobile_vtu_respond([
            'http_code' => 200,
            'body' => [
                'success' => false,
                'status' => 'failed',
                'message' => 'This purchase already failed and was refunded',
                'idempotent_replay' => true,
                'reference' => $existingRef,
                'receipt_id' => $existingReceipt,
                'local_wallet_refunded' => true,
                'wallet_product' => $walletProduct,
                'transaction_at' => $existingHold['created_at'] ?? $txnAt,
                'momo_balance' => (float) ($updated['momo_balance'] ?? 0),
                'vtu_balance' => (float) ($updated['vtu_balance'] ?? 0),
                'logical_balance' => (float) ($updated['logical_balance'] ?? 0),
            ],
        ]);
        $mysqli->close();
        exit;
    }
    // Still held — try to settle (provider poll) or TTL-refund unrekeyed PEND-* holds.
    $existingRef = trim((string) ($existingHold['reference'] ?? ''));
    $existingReceipt = trim((string) ($existingHold['receipt_id'] ?? ''));
    $settle = vtu_hold_try_settle($mysqli, $existingHold);
    $settleOutcome = (string) ($settle['outcome'] ?? 'still_held');
    if ($settleOutcome === 'completed' || $settleOutcome === 'refunded') {
        $fresh = $existingHold;
        $fresh['status'] = $settle['status'] ?? $settleOutcome;
        vtu_sync_agent_transaction_from_hold($mysqli, $fresh);
        $updated = fetch_user_role_row($mysqli, $userId);
        $ok = $settleOutcome === 'completed';
        smobile_vtu_respond([
            'http_code' => 200,
            'body' => [
                'success' => $ok,
                'status' => $ok ? 'success' : 'failed',
                'message' => (string) ($settle['message'] ?? ($ok ? 'Purchase completed' : 'Purchase failed')),
                'idempotent_replay' => true,
                'reference' => $existingRef,
                'receipt_id' => $existingReceipt,
                'local_wallet_refunded' => !$ok,
                'amount_charged' => (float) ($existingHold['amount'] ?? $chargeAmount),
                'face_value' => (float) ($existingHold['face_amount'] ?? $faceAmount),
                'wallet_product' => $walletProduct,
                'transaction_at' => $existingHold['created_at'] ?? $txnAt,
                'momo_balance' => (float) ($updated['momo_balance'] ?? 0),
                'vtu_balance' => (float) ($updated['vtu_balance'] ?? 0),
                'logical_balance' => (float) ($updated['logical_balance'] ?? 0),
            ],
        ]);
        $mysqli->close();
        exit;
    }

    smobile_vtu_respond([
        'http_code' => 202,
        'body' => [
            'success' => false,
            'status' => 'processing',
            'message' => 'Purchase already in progress. Check Transactions; do not buy again.',
            'idempotent_replay' => true,
            'reference' => $existingRef,
            'receipt_id' => $existingReceipt,
            'local_wallet_held' => true,
            'amount_charged' => (float) ($existingHold['amount'] ?? $chargeAmount),
            'wallet_product' => $walletProduct,
            'transaction_at' => $existingHold['created_at'] ?? $txnAt,
            'momo_balance' => (float) ($updated['momo_balance'] ?? 0),
            'vtu_balance' => (float) ($updated['vtu_balance'] ?? 0),
            'logical_balance' => (float) ($updated['logical_balance'] ?? 0),
        ],
    ]);
    $mysqli->close();
    exit;
}

// Serialize concurrent retries of the same client_request_id.
$purchaseLockName = 'ebube_vtu_' . substr(hash('sha256', $clientRequestId), 0, 40);
$purchaseLock = $mysqli->query("SELECT GET_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "', 20) AS got");
$purchaseLockRow = $purchaseLock ? $purchaseLock->fetch_assoc() : null;
if (!$purchaseLockRow || (int) $purchaseLockRow['got'] !== 1) {
    http_response_code(503);
    echo json_encode([
        'response_code' => 503,
        'success' => false,
        'status' => 'uncertain',
        'message' => 'Purchase already in progress. Check Transactions; do not buy again.',
    ]);
    exit;
}

$existingHold = vtu_hold_find_by_client_request($mysqli, $clientRequestId);
if ($existingHold) {
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");
    $holdStatus = strtolower((string) ($existingHold['status'] ?? ''));
    $existingRef = trim((string) ($existingHold['reference'] ?? ''));
    $existingReceipt = trim((string) ($existingHold['receipt_id'] ?? ''));

    if ($holdStatus === 'held' || $holdStatus === '') {
        $settle = vtu_hold_try_settle($mysqli, $existingHold);
        $settleOutcome = (string) ($settle['outcome'] ?? 'still_held');
        if ($settleOutcome === 'completed' || $settleOutcome === 'refunded') {
            $fresh = $existingHold;
            $fresh['status'] = $settle['status'] ?? $settleOutcome;
            vtu_sync_agent_transaction_from_hold($mysqli, $fresh);
            $updated = fetch_user_role_row($mysqli, $userId);
            $ok = $settleOutcome === 'completed';
            smobile_vtu_respond([
                'http_code' => 200,
                'body' => [
                    'success' => $ok,
                    'status' => $ok ? 'success' : 'failed',
                    'message' => (string) ($settle['message'] ?? ($ok ? 'Purchase completed' : 'Purchase failed')),
                    'idempotent_replay' => true,
                    'reference' => $existingRef,
                    'receipt_id' => $existingReceipt,
                    'local_wallet_refunded' => !$ok,
                    'amount_charged' => (float) ($existingHold['amount'] ?? $chargeAmount),
                    'face_value' => (float) ($existingHold['face_amount'] ?? $faceAmount),
                    'wallet_product' => $walletProduct,
                    'transaction_at' => $existingHold['created_at'] ?? $txnAt,
                    'momo_balance' => (float) ($updated['momo_balance'] ?? 0),
                    'vtu_balance' => (float) ($updated['vtu_balance'] ?? 0),
                    'logical_balance' => (float) ($updated['logical_balance'] ?? 0),
                ],
            ]);
            $mysqli->close();
            exit;
        }
        $holdStatus = 'held';
    }

    $updated = fetch_user_role_row($mysqli, $userId);
    $replayStatus = $holdStatus === 'completed'
        ? 'success'
        : ($holdStatus === 'refunded' ? 'failed' : 'processing');
    smobile_vtu_respond([
        'http_code' => $replayStatus === 'success' ? 200 : ($replayStatus === 'failed' ? 200 : 202),
        'body' => [
            'success' => $replayStatus === 'success',
            'status' => $replayStatus === 'success' ? 'success' : $replayStatus,
            'message' => $replayStatus === 'success'
                ? 'Purchase already completed'
                : ($replayStatus === 'failed'
                    ? 'This purchase already failed and was refunded'
                    : 'Purchase already in progress. Check Transactions; do not buy again.'),
            'idempotent_replay' => true,
            'reference' => $existingRef,
            'receipt_id' => $existingReceipt,
            'amount_charged' => (float) ($existingHold['amount'] ?? $chargeAmount),
            'wallet_product' => $walletProduct,
            'transaction_at' => $existingHold['created_at'] ?? $txnAt,
            'momo_balance' => (float) ($updated['momo_balance'] ?? 0),
            'vtu_balance' => (float) ($updated['vtu_balance'] ?? 0),
            'logical_balance' => (float) ($updated['logical_balance'] ?? 0),
        ],
    ]);
    $mysqli->close();
    exit;
}

// Provisional hold key so a crash after debit still has a refundable hold.
$provisionalRef = 'PEND-' . $clientRequestId;
$provisionalReceipt = 'VTU-' . preg_replace('/[^A-Za-z0-9\-]/', '', $clientRequestId);
if (strlen($provisionalReceipt) > 64) {
    $provisionalReceipt = 'VTU-' . substr(hash('sha256', $clientRequestId), 0, 40);
}

// Ensure hold schema before the money transaction (DDL would commit mid-flight).
vtu_holds_ensure_client_request_id($mysqli);
$colFace = $mysqli->query("SHOW COLUMNS FROM `vtu_wallet_holds` LIKE 'face_amount'");
if ($colFace && $colFace->num_rows === 0) {
    $mysqli->query(
        "ALTER TABLE `vtu_wallet_holds`
         ADD COLUMN `face_amount` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `amount`"
    );
}
if ($colFace) {
    $colFace->free();
}

// Serialize limit check + debit + hold so parallel buys can't race past wallet/SIM caps.
if (!$mysqli->begin_transaction()) {
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");
    http_response_code(500);
    echo json_encode([
        'response_code' => 500,
        'success' => false,
        'message' => 'Could not start purchase transaction',
    ]);
    exit;
}

if (!product_limits_lock_scopes($mysqli, $buyerId, $walletProduct, (string) $phone)) {
    $mysqli->rollback();
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");
    http_response_code(503);
    echo json_encode([
        'response_code' => 503,
        'success' => false,
        'status' => 'uncertain',
        'message' => 'Could not lock purchase limits. Please try again.',
    ]);
    exit;
}

$limitCheckLocked = product_transaction_limits_check(
    $mysqli,
    $buyerId,
    $userRole,
    $walletProduct,
    $faceAmount,
    (string) $phone
);
if (is_array($limitCheckLocked) && empty($limitCheckLocked['ok'])) {
    $mysqli->rollback();
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => $limitCheckLocked['message'] ?? 'Recharge limit exceeded',
        'wallet_product' => $walletProduct,
        'max_per_transaction' => $limitCheckLocked['max_per_transaction'] ?? null,
        'daily_limit' => $limitCheckLocked['daily_limit'] ?? null,
        'max_per_sim' => $limitCheckLocked['max_per_sim'] ?? null,
        'used' => $limitCheckLocked['used'] ?? null,
        'remaining' => $limitCheckLocked['remaining'] ?? null,
        'sim_used' => $limitCheckLocked['sim_used'] ?? null,
        'sim_remaining' => $limitCheckLocked['sim_remaining'] ?? null,
    ]);
    exit;
}

if (!wallet_debit($mysqli, $userId, $walletProduct, $chargeAmount)) {
    $mysqli->rollback();
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");
    http_response_code(400);
    echo json_encode([
        'response_code' => 400,
        'success' => false,
        'message' => 'Could not debit ' . wallet_product_label($walletProduct) . ' wallet',
    ]);
    exit;
}

if (!vtu_hold_save(
    $mysqli,
    $provisionalRef,
    $provisionalReceipt,
    $userId,
    $walletProduct,
    $chargeAmount,
    $productLabel,
    $phone,
    $servedBy,
    'held',
    $faceAmount,
    $clientRequestId
)) {
    $mysqli->rollback();
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");
    http_response_code(500);
    echo json_encode([
        'response_code' => 500,
        'success' => false,
        'message' => 'Could not create wallet hold; debit was reversed',
        'local_wallet_refunded' => true,
    ]);
    exit;
}

if (!$mysqli->commit()) {
    $mysqli->rollback();
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");
    http_response_code(500);
    echo json_encode([
        'response_code' => 500,
        'success' => false,
        'message' => 'Could not finalize wallet hold',
    ]);
    exit;
}

// Hold is durable — release lock before slow provider I/O so other keys are not blocked.
$mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($purchaseLockName) . "')");

vtu_upsert_agent_transaction(
    $mysqli,
    $provisionalReceipt,
    $userId,
    $phone,
    $productFull,
    $chargeAmount,
    'Processing',
    $servedBy,
    $phone,
    'MTN'
);

$result = smobile_vtu_request('POST', '/v1/purchase', $payload);
$body = $result['body'] ?? [];
$status = strtolower(trim((string) ($body['status'] ?? '')));
$providerSuccess = !empty($body['success']) || $status === 'success';
$processing = in_array($status, ['processing', 'pending', 'queued'], true);

if ($processing && !empty($body['reference'])) {
    $ref = (string) $body['reference'];
    for ($i = 0; $i < 6; $i++) {
        usleep(1500000);
        $poll = smobile_vtu_request('GET', '/v1/transaction/' . rawurlencode($ref));
        $body = $poll['body'] ?? $body;
        $result = $poll;
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        $providerSuccess = !empty($body['success']) || $status === 'success';
        $processing = in_array($status, ['processing', 'pending', 'queued'], true);
        if (!$processing) {
            break;
        }
    }
}

$reference = trim((string) ($body['reference'] ?? ''));

// Without a provider reference we cannot auto-complete/refund via webhook or poll.
if ($reference === '') {
    vtu_hold_refund($mysqli, $provisionalRef);
    $updated = fetch_user_role_row($mysqli, $userId);
    smobile_vtu_respond([
        'http_code' => (int) ($result['http_code'] ?? 502),
        'body' => [
            'success' => false,
            'status' => 'failed',
            'message' => (string) ($body['message'] ?? 'Provider returned no reference; injected wallet was refunded'),
            'local_wallet_refunded' => true,
            'wallet_product' => $walletProduct,
            'receipt_id' => $provisionalReceipt,
            'client_request_id' => $clientRequestId,
            'transaction_at' => $txnAt,
            'momo_balance' => (float) ($updated['momo_balance'] ?? 0),
            'vtu_balance' => (float) ($updated['vtu_balance'] ?? 0),
            'logical_balance' => (float) ($updated['logical_balance'] ?? 0),
        ],
    ]);
    $mysqli->close();
    exit;
}

$receiptId = 'VTU-' . preg_replace('/[^A-Za-z0-9\-]/', '', $reference);
if (strlen($receiptId) > 64) {
    $receiptId = 'VTU-' . substr(hash('sha256', $reference), 0, 40);
}

// Re-key provisional hold to the provider reference for webhook/poll settlement.
if (!vtu_hold_rekey($mysqli, $provisionalRef, $reference, $receiptId)) {
    // Provider ref may already be stored (rare race); keep working with provider ref.
    $holdRow = vtu_hold_find_by_client_request($mysqli, $clientRequestId);
    if ($holdRow && trim((string) ($holdRow['reference'] ?? '')) !== '') {
        $reference = (string) $holdRow['reference'];
        $receiptId = (string) ($holdRow['receipt_id'] ?? $receiptId);
    }
}

vtu_upsert_agent_transaction(
    $mysqli,
    $receiptId,
    $userId,
    $phone,
    $productFull,
    $chargeAmount,
    $processing ? 'Processing' : ($providerSuccess ? 'Completed' : 'Failed'),
    $servedBy,
    $phone,
    'MTN'
);
vtu_purge_provisional_processing_orphans($mysqli, $reference, $receiptId);

// Still processing after polls: keep local debit held (do NOT refund).
if ($processing) {
    $updated = fetch_user_role_row($mysqli, $userId);
    $body['success'] = false;
    $body['status'] = 'processing';
    $body['wallet_product'] = $walletProduct;
    $body['local_wallet_held'] = true;
    $body['amount_charged'] = $chargeAmount;
    $body['reference'] = $reference;
    $body['receipt_id'] = $receiptId;
    $body['client_request_id'] = $clientRequestId;
    $body['transaction_at'] = $txnAt;
    $body['momo_balance'] = (float) ($updated['momo_balance'] ?? 0);
    $body['vtu_balance'] = (float) ($updated['vtu_balance'] ?? 0);
    $body['logical_balance'] = (float) ($updated['logical_balance'] ?? 0);
    if (empty($body['message'])) {
        $body['message'] = 'Purchase is still processing. Injected wallet was debited and will stay held until final status.';
    }
    smobile_vtu_respond([
        'http_code' => 202,
        'body' => $body,
    ]);
    $mysqli->close();
    exit;
}

if (!$providerSuccess) {
    vtu_hold_refund($mysqli, $reference);
    $updated = fetch_user_role_row($mysqli, $userId);
    $body['local_wallet_refunded'] = true;
    $body['wallet_product'] = $walletProduct;
    $body['reference'] = $reference;
    $body['receipt_id'] = $receiptId;
    $body['client_request_id'] = $clientRequestId;
    $body['transaction_at'] = $txnAt;
    $body['momo_balance'] = (float) ($updated['momo_balance'] ?? 0);
    $body['vtu_balance'] = (float) ($updated['vtu_balance'] ?? 0);
    $body['logical_balance'] = (float) ($updated['logical_balance'] ?? 0);
    if (empty($body['message'])) {
        $body['message'] = 'Purchase failed; local wallet was refunded';
    }
    $body['success'] = false;
    if ($status === '' || $status === 'success') {
        $body['status'] = 'failed';
    }
    smobile_vtu_respond([
        'http_code' => (int) ($result['http_code'] ?? 400),
        'body' => $body,
    ]);
    $mysqli->close();
    exit;
}

// Keep injected-wallet charge as Ebube-priced amount. Do not rewrite it to
// SMobile's amount_charged (provider discount/pricing is separate).
$providerBilled = isset($body['amount_charged']) ? (float) $body['amount_charged'] : null;
$walletCharged = $chargeAmount;

// Completing the hold credits commission once (idempotent by reference).
vtu_hold_complete($mysqli, $reference, $walletCharged);

$commissionEarned = 0.0;
$commissionCredited = false;
$creditStmt = $mysqli->prepare(
    'SELECT commission_amount FROM vtu_commission_credits WHERE reference = ? LIMIT 1'
);
if ($creditStmt) {
    $creditStmt->bind_param('s', $reference);
    $creditStmt->execute();
    $creditRow = $creditStmt->get_result()->fetch_assoc();
    $creditStmt->close();
    if ($creditRow) {
        $commissionEarned = (float) $creditRow['commission_amount'];
        $commissionCredited = $commissionEarned > 0;
    }
}

$updated = fetch_user_role_row($mysqli, $userId);
$body['success'] = true;
$body['status'] = 'success';
$body['wallet_product'] = $walletProduct;
$body['face_value'] = $faceAmount;
$body['discount'] = $discountAmount;
$body['amount_charged'] = $walletCharged;
$body['provider_amount_charged'] = $providerBilled;
$body['commission_earned'] = $commissionEarned;
$body['commission_credited'] = $commissionCredited;
$body['reference'] = $reference;
$body['receipt_id'] = $receiptId;
$body['client_request_id'] = $clientRequestId;
$body['transaction_at'] = $txnAt;
$body['momo_balance'] = (float) ($updated['momo_balance'] ?? 0);
$body['vtu_balance'] = (float) ($updated['vtu_balance'] ?? 0);
$body['logical_balance'] = (float) ($updated['logical_balance'] ?? 0);
$body['commission_balance'] = (float) ($updated['commission_balance'] ?? 0);
if (empty($body['message'])) {
    $body['message'] = 'Purchase successful';
}
if ($discountAmount > 0.009) {
    $body['message'] = 'Purchase successful. Face value ₦' .
        number_format($faceAmount, 2) .
        ', Ebube discount ₦' . number_format($discountAmount, 2) .
        ', wallet charged ₦' . number_format($walletCharged, 2) . '.';
}

smobile_vtu_respond([
    'http_code' => 200,
    'body' => $body,
]);
$mysqli->close();
