<?php
/**
 * Verify transaction PIN for the authenticated user (e.g. before purchases).
 */
require_once 'config.php';
require_once 'auth_util.php';
require_once 'schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/txn_pin_guard.php';

ensure_app_tables($mysqli);
$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$pin = trim((string) ($data['pin'] ?? $data['transaction_pin'] ?? ''));
if (!is_valid_pin($pin)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Transaction PIN must be exactly 4 digits']);
    exit;
}

$lockStatus = txn_pin_guard_status($mysqli, $sessionId);
if (!$lockStatus['ok']) {
    http_response_code(423);
    echo json_encode([
        'success' => false,
        'message' => $lockStatus['message'] ?? 'Transaction PIN locked',
        'locked_until' => $lockStatus['locked_until'] ?? null,
    ]);
    exit;
}

$stmt = $mysqli->prepare('SELECT transaction_pin_hash FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hash = trim((string) ($row['transaction_pin_hash'] ?? ''));
if ($hash === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'needs_setup' => true,
        'message' => 'Create a transaction PIN first',
    ]);
    exit;
}

if (!password_verify($pin, $hash)) {
    $fail = txn_pin_guard_fail($mysqli, $sessionId);
    http_response_code(!empty($fail['locked']) ? 423 : 422);
    echo json_encode([
        'success' => false,
        'message' => $fail['message'],
        'remaining_attempts' => $fail['remaining_attempts'] ?? 0,
        'locked' => !empty($fail['locked']),
    ]);
    exit;
}

txn_pin_guard_clear($mysqli, $sessionId);
echo json_encode(['success' => true, 'message' => 'Transaction PIN verified']);
$mysqli->close();
