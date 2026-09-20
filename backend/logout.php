<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session_auth.php';

$token = ec_get_bearer_token();
ec_revoke_session($mysqli, $token);

echo json_encode([
    'success' => true,
    'message' => 'Logged out',
]);
$mysqli->close();
