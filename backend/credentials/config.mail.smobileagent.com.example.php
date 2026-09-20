<?php
/**
 * Copy to: api/credentials/config.mail.smobileagent.com.php
 * Use when the same api/ folder is deployed on multiple domains.
 * Password must be for no-reply@smobileagent.com (cPanel → Email Accounts).
 */

$mailSmtpHost = 'mail.smobileagent.com';
$mailSmtpPort = 587;
$mailSmtpUser = 'no-reply@smobileagent.com';
$mailSmtpPass = 'YOUR_CPANEL_MAILBOX_PASSWORD';
$mailSmtpSecure = 'tls';

$mailFromEmail = 'no-reply@smobileagent.com';
$mailFromName = 'SMobile Agent';
