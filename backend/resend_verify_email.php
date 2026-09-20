<?php
require_once 'config.php';
require_once 'auth_util.php';
require_once 'mysqli_helpers.php';
require_once __DIR__ . '/email_verify_util.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$challengeId = trim((string) ($data['challenge_id'] ?? ''));
$email = normalize_email((string) ($data['email'] ?? ''));

ensure_email_verify_table($mysqli);

$userId = 0;
if ($challengeId !== '') {
    $stmt = $mysqli->prepare(
        'SELECT user_id, email FROM email_verification_challenges WHERE challenge_id = ? ORDER BY id DESC LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('s', $challengeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $userId = (int) $row['user_id'];
            if ($email === '') {
                $email = normalize_email((string) $row['email']);
            }
        }
    }
}

if ($userId <= 0 && $email !== '') {
    $stmt = $mysqli->prepare('SELECT id, email, email_verified_at FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            if (!empty($row['email_verified_at'])) {
                echo json_encode(['success' => true, 'message' => 'Email already verified. You can sign in.', 'already_verified' => true]);
                $mysqli->close();
                exit;
            }
            $userId = (int) $row['id'];
            $email = normalize_email((string) $row['email']);
        }
    }
}

if ($userId <= 0 || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'challenge_id or email required']);
    exit;
}

$result = create_email_verification_challenge($mysqli, $userId, $email);
$httpCode = (int) ($result['http_code'] ?? ($result['success'] ? 200 : 500));
unset($result['http_code']);
http_response_code($httpCode);
echo json_encode($result);
$mysqli->close();
