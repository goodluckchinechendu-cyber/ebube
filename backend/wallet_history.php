<?php
/**
 * Wallet History — combined inflow/outflow for the signed-in user.
 * Sources: funding received, funding sent, peer transfers, VTU purchases.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) ($sessionUser['role'] ?? 0);
$maskExternal = $sessionRole < 3;

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(10, (int) ($_GET['per_page'] ?? 25)));
// Fetch a wider window then sort/paginate in PHP for the union.
$window = min(400, max($perPage * $page + $perPage, 80));

$entries = [];

/**
 * Display label for an external wallet when the viewer must not see PII.
 */
function wh_external_label(array $row, string $nameKey, string $walletKey, string $fallback): string
{
    $walletId = strtoupper(trim((string) ($row[$walletKey] ?? '')));
    if ($walletId !== '') {
        return 'Wallet ' . $walletId;
    }
    $existing = trim((string) ($row[$nameKey] ?? ''));
    if ($existing !== '' && stripos($existing, 'Wallet ') === 0) {
        return $existing;
    }
    return $fallback;
}

// Funding received (credit) — exclude Admin transfer-out mirror rows.
$stmt = $mysqli->prepare(
    "SELECT id, receipt_id, wallet_product, wallet_name, amount, funded_by, funded_at, status
     FROM wallet_funding_history
     WHERE wallet_user_id = ?
       AND amount > 0
       AND LOWER(COALESCE(status, '')) <> 'transferred'
     ORDER BY funded_at DESC, id DESC
     LIMIT ?"
);
if ($stmt) {
    $stmt->bind_param('ii', $sessionId, $window);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $entries[] = [
            'id' => 'F' . (int) $row['id'],
            'kind' => 'funding_in',
            'is_credit' => true,
            'label' => 'Wallet funded · ' . ($row['wallet_name'] ?: strtoupper((string) $row['wallet_product'])),
            'amount' => (float) $row['amount'],
            'wallet_product' => (string) $row['wallet_product'],
            'reference' => (string) $row['receipt_id'],
            'performed_by_name' => (string) ($row['funded_by'] ?: 'Administrator'),
            'status' => (string) ($row['status'] ?? 'Active'),
            'created_at' => (string) $row['funded_at'],
            'source' => 'Funding',
        ];
    }
    $stmt->close();
}

// Funding sent (debit) — when this user funded someone else (recipient credit rows).
$stmt = $mysqli->prepare(
    "SELECT h.id, h.receipt_id, h.wallet_product, h.wallet_name, h.amount, h.user_name, h.funded_at, h.status,
            h.wallet_user_id,
            COALESCE(wu.is_external, 0) AS target_is_external,
            COALESCE(wu.wallet_id, '') AS target_wallet_id
     FROM wallet_funding_history h
     LEFT JOIN users wu ON wu.id = h.wallet_user_id
     WHERE h.funded_by_user_id = ? AND h.wallet_user_id <> ?
       AND h.amount > 0
     ORDER BY h.funded_at DESC, h.id DESC
     LIMIT ?"
);
if ($stmt) {
    $stmt->bind_param('iii', $sessionId, $sessionId, $window);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $targetName = trim((string) ($row['user_name'] ?: 'user'));
        if ($maskExternal && (int) ($row['target_is_external'] ?? 0) === 1) {
            $targetName = wh_external_label($row, 'user_name', 'target_wallet_id', 'Wallet');
        }
        $entries[] = [
            'id' => 'FS' . (int) $row['id'],
            'kind' => 'funding_out',
            'is_credit' => false,
            'label' => 'Funded ' . $targetName .
                ' · ' . ($row['wallet_name'] ?: strtoupper((string) $row['wallet_product'])),
            'amount' => (float) $row['amount'],
            'wallet_product' => (string) $row['wallet_product'],
            'reference' => (string) $row['receipt_id'],
            'performed_by_name' => 'You',
            'status' => (string) ($row['status'] ?? 'Active'),
            'created_at' => (string) $row['funded_at'],
            'source' => 'Fund Wallet',
        ];
    }
    $stmt->close();
}

// Peer transfers.
$stmt = $mysqli->prepare(
    "SELECT t.id, t.reference, t.from_user_id, t.to_user_id, t.wallet_product, t.amount, t.status, t.created_at,
            fu.full_name AS from_name,
            tu.full_name AS to_name,
            COALESCE(fu.is_external, 0) AS from_is_external,
            COALESCE(tu.is_external, 0) AS to_is_external,
            COALESCE(fu.wallet_id, '') AS from_wallet_id,
            COALESCE(tu.wallet_id, '') AS to_wallet_id
     FROM wallet_transfers t
     LEFT JOIN users fu ON fu.id = t.from_user_id
     LEFT JOIN users tu ON tu.id = t.to_user_id
     WHERE t.from_user_id = ? OR t.to_user_id = ?
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT ?"
);
if ($stmt) {
    $stmt->bind_param('iii', $sessionId, $sessionId, $window);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $isOut = (int) $row['from_user_id'] === $sessionId;
        $other = $isOut ? (string) $row['to_name'] : (string) $row['from_name'];
        if ($maskExternal) {
            if ($isOut && (int) ($row['to_is_external'] ?? 0) === 1) {
                $other = wh_external_label($row, 'to_name', 'to_wallet_id', 'Wallet');
            } elseif (!$isOut && (int) ($row['from_is_external'] ?? 0) === 1) {
                $other = wh_external_label($row, 'from_name', 'from_wallet_id', 'Wallet');
            }
        }
        $product = strtoupper((string) $row['wallet_product']);
        $entries[] = [
            'id' => 'T' . (int) $row['id'],
            'kind' => $isOut ? 'transfer_out' : 'transfer_in',
            'is_credit' => !$isOut,
            'label' => ($isOut ? 'Transfer to ' : 'Transfer from ') . ($other !== '' ? $other : 'user') .
                ' · ' . $product,
            'amount' => (float) $row['amount'],
            'wallet_product' => (string) $row['wallet_product'],
            'reference' => (string) $row['reference'],
            'performed_by_name' => $isOut ? 'You' : ($other !== '' ? $other : 'User'),
            'status' => (string) ($row['status'] ?? 'completed'),
            'created_at' => (string) $row['created_at'],
            'source' => 'Wallet Transfer',
        ];
    }
    $stmt->close();
}

// Purchases (debit).
$stmt = $mysqli->prepare(
    "SELECT id, receipt_id, product, total, status, served_by, transaction_at, phone
     FROM agent_transactions
     WHERE wallet_user_id = ?
     ORDER BY transaction_at DESC, id DESC
     LIMIT ?"
);
if ($stmt) {
    $stmt->bind_param('ii', $sessionId, $window);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $status = strtolower((string) ($row['status'] ?? ''));
        if (in_array($status, ['failed', 'refunded', 'cancelled'], true)) {
            // Still show as debit reversed conceptually — skip failed for cleaner history.
            continue;
        }
        $entries[] = [
            'id' => 'P' . (int) $row['id'],
            'kind' => 'purchase',
            'is_credit' => false,
            'label' => (string) ($row['product'] ?: 'Purchase'),
            'amount' => (float) $row['total'],
            'wallet_product' => '',
            'reference' => (string) $row['receipt_id'],
            'performed_by_name' => (string) ($row['served_by'] ?: 'You'),
            'status' => (string) ($row['status'] ?? 'Completed'),
            'created_at' => (string) $row['transaction_at'],
            'source' => 'Purchase',
            'phone' => (string) ($row['phone'] ?? ''),
        ];
    }
    $stmt->close();
}

usort($entries, static function ($a, $b) {
    return strcmp((string) $b['created_at'], (string) $a['created_at']);
});

$total = count($entries);
$offset = ($page - 1) * $perPage;
$slice = array_slice($entries, $offset, $perPage);
$hasMore = ($offset + $perPage) < $total;

echo json_encode([
    'success' => true,
    'entries' => array_values($slice),
    'meta' => [
        'page' => $page,
        'per_page' => $perPage,
        'has_more' => $hasMore,
    ],
]);
$mysqli->close();
