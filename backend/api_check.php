<?php
/**
 * Open once in browser to verify API files and database:
 * https://yourdomain.com/api/api_check.php
 */
require_once 'config.php';
require_once 'mail_util.php';

$files = [
    'config.php',
    'api_bootstrap.php',
    'env_loader.php',
    'mysqli_helpers.php',
    'login.php',
    'register.php',
    'verify_email.php',
    'resend_verify_email.php',
    'email_verify_util.php',
    'set_transaction_pin.php',
    'verify_transaction_pin.php',
    'txn_pin_guard.php',
    'session_auth.php',
    'mail_util.php',
    'announcements.php',
    'schema.php',
    'wallet_holds.php',
    'commission_tiers.php',
];

$missing = [];
foreach ($files as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $missing[] = $file;
    }
}

$tables = ['users', 'announcements', 'email_verification_challenges', 'user_sessions'];
$tableStatus = [];
foreach ($tables as $table) {
    $result = $mysqli->query("SHOW TABLES LIKE '$table'");
    $tableStatus[$table] = $result && $result->num_rows > 0;
}

$checks = [
    'mysqli_get_result' => method_exists('mysqli_stmt', 'get_result'),
    'random_int' => function_exists('random_int'),
    'random_bytes' => function_exists('random_bytes'),
    'php_mail' => function_exists('mail'),
] + app_mail_delivery_status();

echo json_encode([
    'success' => $missing === [] && !in_array(false, $tableStatus, true),
    'message' => $missing === []
        ? 'API files present. Run install_database.php if any table is missing.'
        : 'Upload missing files: ' . implode(', ', $missing),
    'missing_files' => $missing,
    'tables' => $tableStatus,
    'php_version' => PHP_VERSION,
    'checks' => $checks,
]);

$mysqli->close();
