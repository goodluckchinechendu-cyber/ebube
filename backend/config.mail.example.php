<?php
/**
 * Copy the matching domain file to config.mail.php on each server, e.g.:
 *   config.mail.smobileunich.example.php  → config.mail.php (on smobileunich.com)
 *   config.mail.smobilestarlot.example.php → config.mail.php (on smobilestarlot.com)
 *
 * Sender name and address are also set from HTTP_HOST via config.mail.brands.php
 * (so OTP emails show the right brand even if config.mail.php was copied from another site).
 * $mailSmtpPass must still match that domain's mailbox on each server.
 * cPanel: Email Accounts → create no-reply@yourdomain.com → use those SMTP settings.
 */

$mailSmtpHost = 'mail.yourdomain.com';
$mailSmtpPort = 587;
$mailSmtpUser = 'no-reply@yourdomain.com';
$mailSmtpPass = 'your-email-password';
$mailSmtpSecure = 'tls'; // tls (port 587) or ssl (port 465)

$mailFromEmail = 'no-reply@yourdomain.com';
$mailFromName = 'SMobile Agent';
