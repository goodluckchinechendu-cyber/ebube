<?php
/**
 * Create or change transaction PIN (4 digits). Required after first login if missing.
 */
require_once 'config.php';
require_once 'auth_util.php';
require_once 'schema.php';
require_once __DIR__ . '/session_auth.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$action = trim((string) ($data['action'] ?? 'set'));
$pin = trim((string) ($data['pin'] ?? $data['transaction_pin'] ?? ''));
$confirm = trim((string) ($data['confirm_pin'] ?? $data['pin_confirm'] ?? $pin));
$currentPin = trim((string) ($data['current_pin'] ?? ''));

if (!is_valid_pin($pin)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Transaction PIN must be exactly 4 digits']);
    exit;
}

if ($pin !== $confirm) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'PIN confirmation does not match']);
    exit;
}

$stmt = $mysqli->prepare('SELECT id, password_hash, transaction_pin_hash FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$existing = trim((string) ($user['transaction_pin_hash'] ?? ''));

if ($action === 'change' || $existing !== '') {
    if ($action === 'set' && $existing !== '') {
        // Creating when already set requires current pin unless explicit change.
        if ($currentPin === '' || !password_verify($currentPin, $existing)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Current transaction PIN required to change it']);
            exit;
        }
    } elseif ($action === 'change') {
        if ($currentPin === '' || !password_verify($currentPin, $existing)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Current transaction PIN is incorrect']);
            exit;
        }
    }
}

// Transaction PIN must differ from login PIN.
if (password_verify($pin, (string) $user['password_hash'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Transaction PIN must be different from your login PIN',
    ]);
    exit;
}

$hash = password_hash($pin, PASSWORD_DEFAULT);
$upd = $mysqli->prepare('UPDATE users SET transaction_pin_hash = ? WHERE id = ?');
$upd->bind_param('si', $hash, $sessionId);
if (!$upd->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save transaction PIN']);
    $upd->close();
    exit;
}
$upd->close();

echo json_encode([
    'success' => true,
    'message' => $existing === '' ? 'Transaction PIN created' : 'Transaction PIN updated',
    'has_transaction_pin' => true,
]);
$mysqli->close();
