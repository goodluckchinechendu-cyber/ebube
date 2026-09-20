<?php
require_once 'config.php';
require_once 'login_otp_util.php';
require_once __DIR__ . '/session_auth.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$challengeId = trim((string) ($data['challenge_id'] ?? ''));
$otp = trim((string) ($data['otp'] ?? ''));

if ($challengeId === '' || $otp === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Verification code required']);
    exit;
}

if (!ensure_login_otp_table($mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not prepare verification storage']);
    exit;
}

$result = verify_login_otp_challenge($mysqli, $challengeId, $otp);
$httpCode = (int) ($result['http_code'] ?? ($result['success'] ? 200 : 400));
unset($result['http_code']);
http_response_code($httpCode);

if ($result['success']) {
    $userId = (int) ($result['user']['id'] ?? 0);
    try {
        $session = ec_create_session($mysqli, $userId);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Login ok but session failed: ' . $e->getMessage()]);
        $mysqli->close();
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => $result['user'],
        'session_token' => $session['token'],
        'session_expires_at' => $session['expires_at'],
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['message'] ?? 'Verification failed',
    ]);
}

$mysqli->close();
