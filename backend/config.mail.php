<?php
/**
 * Legacy stub — secrets live in ../private/.env (MAIL_*).
 * Kept so older deploys that require this file do not fatally error.
 */
require_once __DIR__ . '/env_loader.php';

$mailSmtpHost = ec_env('MAIL_SMTP_HOST', '') ?? '';
$mailSmtpPort = (int) (ec_env('MAIL_SMTP_PORT', '587') ?? '587');
$mailSmtpUser = ec_env('MAIL_SMTP_USER', '') ?? '';
$mailSmtpPass = ec_env('MAIL_SMTP_PASS', '') ?? '';
$mailSmtpSecure = ec_env('MAIL_SMTP_SECURE', 'tls') ?? 'tls';
$mailFromEmail = ec_env('MAIL_FROM_EMAIL', '') ?? '';
$mailFromName = ec_env('MAIL_FROM_NAME', 'EbubeConnect') ?? 'EbubeConnect';
