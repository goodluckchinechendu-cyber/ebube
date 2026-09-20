<?php
require_once 'config.php';
require_once 'auth_util.php';

function ensure_reset_table(mysqli $mysqli): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL UNIQUE,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $mysqli->query($sql) === true;
}

if (!ensure_reset_table($mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not prepare reset storage']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$token = trim((string) ($data['token'] ?? ''));
$pin = (string) ($data['pin'] ?? '');
$confirmPin = (string) ($data['confirm_pin'] ?? '');
$email = normalize_email((string) ($data['email'] ?? ''));

if ($token === '' || $pin === '' || $confirmPin === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if (!is_valid_pin($pin)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => pin_validation_message()]);
    exit;
}

if ($pin !== $confirmPin) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'PINs do not match']);
    exit;
}

$tokenHash = hash('sha256', $token);
$stmt = $mysqli->prepare(
    "SELECT t.id, t.user_id, u.email
     FROM password_reset_tokens t
     INNER JOIN users u ON u.id = t.user_id
     WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Reset link is invalid or expired']);
    exit;
}

if ($email !== '' && normalize_email((string) $row['email']) !== $email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Reset link is invalid for this email']);
    exit;
}

$userId = (int) $row['user_id'];
$tokenId = (int) $row['id'];
$passwordHash = password_hash($pin, PASSWORD_DEFAULT);

$updateUser = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
if (!$updateUser) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}
$updateUser->bind_param('si', $passwordHash, $userId);
$userUpdated = $updateUser->execute();
$updateUser->close();

if (!$userUpdated) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update PIN']);
    exit;
}

$markUsed = $mysqli->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
if ($markUsed) {
    $markUsed->bind_param('i', $tokenId);
    $markUsed->execute();
    $markUsed->close();
}

echo json_encode([
    'success' => true,
    'message' => 'PIN reset successful. You can now log in.',
]);

$mysqli->close();
