<?php
require_once __DIR__ . '/api_bootstrap.php';
require_once __DIR__ . '/env_loader.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Ebube-Session, X-SMobile-Brand-Slug, X-SMobile-App-Name, Accept');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Prefer DB_*; fall back to Railway MySQL plugin names.
$host = ec_env('DB_HOST', ec_env('MYSQLHOST', ec_env('MYSQL_HOST', '127.0.0.1'))) ?? '127.0.0.1';
$user = ec_env('DB_USER', ec_env('MYSQLUSER', ec_env('MYSQL_USER', ''))) ?? '';
$password = ec_env('DB_PASS', ec_env('MYSQLPASSWORD', ec_env('MYSQL_PASSWORD', ''))) ?? '';
$dbName = ec_env('DB_NAME', ec_env('MYSQLDATABASE', ec_env('MYSQL_DATABASE', ''))) ?? '';
$dbPort = (int) (ec_env('DB_PORT', ec_env('MYSQLPORT', ec_env('MYSQL_PORT', '3306'))) ?? '3306');
if ($dbPort < 1) {
    $dbPort = 3306;
}

if ($user === '' || $dbName === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database credentials missing. Create private/.env (see private/.env.example).',
    ]);
    exit;
}

$mysqli = @new mysqli($host, $user, $password, $dbName, $dbPort);

if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $mysqli->connect_error]);
    exit;
}

$mysqli->set_charset('utf8mb4');

// Optional SMTP — prefer private/.env; legacy config.mail.php still allowed as fallback.
$mailSmtpHost = ec_env('MAIL_SMTP_HOST', '') ?? '';
$mailSmtpPort = (int) (ec_env('MAIL_SMTP_PORT', '587') ?? '587');
$mailSmtpUser = ec_env('MAIL_SMTP_USER', '') ?? '';
$mailSmtpPass = ec_env('MAIL_SMTP_PASS', '') ?? '';
$mailSmtpSecure = ec_env('MAIL_SMTP_SECURE', 'tls') ?? 'tls';
$mailFromEmail = ec_env('MAIL_FROM_EMAIL', '') ?? '';
$mailFromName = ec_env('MAIL_FROM_NAME', 'EbubeConnect') ?? 'EbubeConnect';

if ($mailSmtpHost === '' && file_exists(__DIR__ . '/config.mail.php')) {
    require __DIR__ . '/config.mail.php';
}

require_once __DIR__ . '/mail_brand.php';
apply_mail_brand_from_request();
apply_mail_credentials_for_request();
mail_align_from_address_with_smtp();
