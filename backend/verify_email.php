<?php
require_once 'config.php';
require_once __DIR__ . '/email_verify_util.php';

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

$result = verify_email_challenge($mysqli, $challengeId, $otp);
$httpCode = (int) ($result['http_code'] ?? ($result['success'] ? 200 : 400));
unset($result['http_code']);
http_response_code($httpCode);
echo json_encode($result);
$mysqli->close();
