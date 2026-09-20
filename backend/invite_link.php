<?php
/**
 * Invite link for the signed-in user.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/referral_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];

$code = user_ensure_referral_code($mysqli, $sessionId);
if ($code === null || $code === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create invite code']);
    exit;
}

$underCount = 0;
$stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM users WHERE registered_by = ?');
if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $underCount = (int) ($row['c'] ?? 0);
}

echo json_encode([
    'success' => true,
    'referral_code' => $code,
    'invite_url' => referral_invite_url($code),
    'registered_under_count' => $underCount,
    'message' => 'Share this link. Anyone who registers through it will be under your account.',
]);
$mysqli->close();
