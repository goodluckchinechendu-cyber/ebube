<?php
require_once 'config.php';
require_once 'login_otp_util.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$challengeId = trim((string) ($data['challenge_id'] ?? ''));
if ($challengeId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Verification session required']);
    exit;
}

if (!ensure_login_otp_table($mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not prepare verification storage']);
    exit;
}

$result = resend_login_otp_challenge($mysqli, $challengeId);
$httpCode = (int) ($result['http_code'] ?? ($result['success'] ? 200 : 400));
unset($result['http_code']);
http_response_code($httpCode);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'A new verification code was sent to your email',
        'masked_email' => $result['masked_email'] ?? '',
        'expires_in_seconds' => $result['expires_in_seconds'] ?? LOGIN_OTP_EXPIRY_MINUTES * 60,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['message'] ?? 'Could not resend code',
    ]);
}

$mysqli->close();
