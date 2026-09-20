<?php
/**
 * Admin / Super Admin: audit trail for VTU wallet holds.
 * POST/GET action=list — optional status, reference, limit, offset.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/wallet_pool.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

$sessionUser = ec_require_session($mysqli);
ec_require_min_role($sessionUser, 2); // Admin or Super Admin
$sessionRole = (int) ($sessionUser['role'] ?? 0);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$action = strtolower(trim((string) ($data['action'] ?? $_GET['action'] ?? 'list')));
if ($action !== 'list' && $action !== '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// Ensure face_amount exists for older installs.
$col = $mysqli->query("SHOW COLUMNS FROM `vtu_wallet_holds` LIKE 'face_amount'");
if ($col && $col->num_rows === 0) {
    $mysqli->query(
        "ALTER TABLE `vtu_wallet_holds`
         ADD COLUMN `face_amount` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `amount`"
    );
}

$status = strtolower(trim((string) ($data['status'] ?? $_GET['status'] ?? '')));
$reference = trim((string) ($data['reference'] ?? $_GET['reference'] ?? ''));
$limit = (int) ($data['limit'] ?? $_GET['limit'] ?? 50);
$offset = (int) ($data['offset'] ?? $_GET['offset'] ?? 0);
if ($limit < 1) {
    $limit = 50;
}
if ($limit > 200) {
    $limit = 200;
}
if ($offset < 0) {
    $offset = 0;
}

$where = ['1=1'];
$types = '';
$params = [];

if ($status !== '' && in_array($status, ['held', 'completed', 'refunded'], true)) {
    $where[] = 'h.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($reference !== '') {
    $where[] = 'h.reference LIKE ?';
    $types .= 's';
    $params[] = '%' . $reference . '%';
}
// Admin must not see holds belonging to external (or elevated) accounts.
if ($sessionRole === 2) {
    $where[] = 'COALESCE(u.is_external, 0) = 0';
    $where[] = 'COALESCE(u.role, 0) < 3';
}

$sql = 'SELECT h.id, h.reference, h.receipt_id, h.user_id, h.wallet_product, h.amount,
               h.face_amount, h.product_label, h.phone, h.served_by, h.status,
               h.created_at, h.updated_at,
               u.full_name, u.email, u.phone AS user_phone,
               c.commission_amount
        FROM vtu_wallet_holds h
        LEFT JOIN users u ON u.id = h.user_id
        LEFT JOIN vtu_commission_credits c ON c.reference = h.reference
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY h.id DESC
        LIMIT ? OFFSET ?';
$types .= 'ii';
$params[] = $limit;
$params[] = $offset;

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not query holds']);
    exit;
}

$bind = [$types];
foreach ($params as $i => $v) {
    $bind[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $amount = (float) ($row['amount'] ?? 0);
    $face = isset($row['face_amount']) && (float) $row['face_amount'] > 0
        ? (float) $row['face_amount']
        : $amount;
    $rows[] = [
        'id' => (int) $row['id'],
        'reference' => (string) $row['reference'],
        'receipt_id' => (string) $row['receipt_id'],
        'user_id' => (int) $row['user_id'],
        'user_name' => (string) ($row['full_name'] ?? ''),
        'user_email' => (string) ($row['email'] ?? ''),
        'wallet_product' => (string) $row['wallet_product'],
        'amount' => $amount,
        'face_amount' => $face,
        'discount' => round(max(0.0, $face - $amount), 2),
        'product_label' => (string) $row['product_label'],
        'phone' => (string) $row['phone'],
        'served_by' => (string) $row['served_by'],
        'status' => (string) $row['status'],
        'commission_amount' => isset($row['commission_amount'])
            ? (float) $row['commission_amount']
            : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}
$stmt->close();

$countWhere = ['1=1'];
$countTypes = '';
$countParams = [];
if ($status !== '' && in_array($status, ['held', 'completed', 'refunded'], true)) {
    $countWhere[] = 'status = ?';
    $countTypes .= 's';
    $countParams[] = $status;
}
if ($reference !== '') {
    $countWhere[] = 'reference LIKE ?';
    $countTypes .= 's';
    $countParams[] = '%' . $reference . '%';
}
$total = 0;
$cstmt = $mysqli->prepare(
    'SELECT COUNT(*) AS c FROM vtu_wallet_holds WHERE ' . implode(' AND ', $countWhere)
);
if ($cstmt) {
    if ($countTypes !== '') {
        $cbind = [$countTypes];
        foreach ($countParams as $i => $v) {
            $cbind[] = &$countParams[$i];
        }
        call_user_func_array([$cstmt, 'bind_param'], $cbind);
    }
    $cstmt->execute();
    $crow = $cstmt->get_result()->fetch_assoc();
    $cstmt->close();
    $total = (int) ($crow['c'] ?? 0);
}

echo json_encode([
    'success' => true,
    'holds' => $rows,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
$mysqli->close();
