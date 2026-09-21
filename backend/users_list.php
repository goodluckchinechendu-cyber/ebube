<?php
require_once 'config.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/referral_util.php';
require_once __DIR__ . '/user_visibility_util.php';

ensure_app_tables($mysqli);
ensure_user_visibility_columns($mysqli);

$sessionUser = ec_require_session($mysqli);
if ((int) $sessionUser['role'] < 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$sessionRole = (int) $sessionUser['role'];
$isSuperAdmin = $sessionRole >= 3;

// Admin must never learn about elevated accounts via registered_by name/id.
$regBySelect = $sessionRole === 2
    ? "CASE WHEN COALESCE(rb.role, 0) >= 3 THEN 0 ELSE COALESCE(u.registered_by, 0) END AS registered_by,
        CASE WHEN COALESCE(rb.role, 0) >= 3 THEN '' ELSE COALESCE(rb.full_name, '') END AS registered_by_name"
    : "COALESCE(u.registered_by, 0) AS registered_by,
        COALESCE(rb.full_name, '') AS registered_by_name";

$visSelect = $isSuperAdmin
    ? 'COALESCE(u.is_external, 0) AS is_external, COALESCE(u.wallet_id, \'\') AS wallet_id'
    : '0 AS is_external, \'\' AS wallet_id';

$sql = "SELECT u.id, u.full_name, u.phone, u.location, u.gender, u.email, u.role,
        {$regBySelect},
        {$visSelect},
        COALESCE(u.referral_code, '') AS referral_code,
        COALESCE(u.account_name, '') AS account_name,
        COALESCE(u.bank_name, '') AS bank_name,
        COALESCE(u.account_number, '') AS account_number,
        COALESCE(u.momo_balance, 0) AS momo_balance,
        COALESCE(u.vtu_balance, 0) AS vtu_balance,
        COALESCE(u.logical_balance, 0) AS logical_balance,
        COALESCE(u.data_bundle_balance, 0) AS data_bundle_balance,
        COALESCE(u.commission_balance, 0) AS commission_balance,
        COALESCE(u.last_seen_announcement_id, 0) AS last_seen_announcement_id
        FROM users u
        LEFT JOIN users rb ON rb.id = u.registered_by";

$where = [];
if ($sessionRole === 2) {
    // Admins: never Super Admins, never external users.
    $where[] = 'u.role < 3';
    $where[] = 'COALESCE(u.is_external, 0) = 0';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.id ASC';
$result = $mysqli->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$users = [];
while ($row = $result->fetch_assoc()) {
    $uid = (int) $row['id'];
    $code = trim((string) ($row['referral_code'] ?? ''));
    if ($code === '') {
        $code = (string) (user_ensure_referral_code($mysqli, $uid) ?? '');
    }

    $isExternal = $isSuperAdmin && (int) ($row['is_external'] ?? 0) === 1;
    $walletId = '';
    if ($isSuperAdmin) {
        $walletId = trim((string) ($row['wallet_id'] ?? ''));
        if ($isExternal && ($walletId === '' || user_wallet_id_is_legacy_format($walletId))) {
            $walletId = (string) (user_ensure_wallet_id($mysqli, $uid) ?? $walletId);
        }
    }

    $entry = [
        'id' => $uid,
        'full_name' => $row['full_name'],
        'phone' => $row['phone'],
        'location' => $row['location'],
        'gender' => $row['gender'],
        'email' => $row['email'],
        'role' => (int) $row['role'],
        'registered_by' => (int) ($row['registered_by'] ?? 0),
        'registered_by_name' => (string) ($row['registered_by_name'] ?? ''),
        'referral_code' => $code,
        'momo_balance' => (float) $row['momo_balance'],
        'vtu_balance' => (float) $row['vtu_balance'],
        'logical_balance' => (float) $row['logical_balance'],
        'data_bundle_balance' => (float) $row['data_bundle_balance'],
        'commission_balance' => (float) $row['commission_balance'],
        'account_name' => $row['account_name'],
        'bank_name' => $row['bank_name'],
        'account_number' => $row['account_number'],
        'last_seen_announcement_id' => (int) ($row['last_seen_announcement_id'] ?? 0),
    ];

    // Visibility metadata is Super Admin only — never leak labels to Admin.
    if ($isSuperAdmin) {
        $entry['is_external'] = $isExternal;
        $entry['wallet_id'] = $walletId;
    }

    $users[] = $entry;
}

http_response_code(200);
echo json_encode(['success' => true, 'users' => $users]);
$mysqli->close();
