<?php
/**
 * Email verification for new account registration (not login).
 */
require_once __DIR__ . '/auth_util.php';
require_once __DIR__ . '/mail_util.php';
require_once __DIR__ . '/schema.php';

const EMAIL_VERIFY_LENGTH = 6;
const EMAIL_VERIFY_EXPIRY_MINUTES = 30;
const EMAIL_VERIFY_MAX_SENDS = 5;
const EMAIL_VERIFY_RATE_WINDOW_MINUTES = 30;

function ensure_email_verify_table(mysqli $mysqli): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS `email_verification_challenges` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `challenge_id` CHAR(32) NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `email` VARCHAR(255) NOT NULL,
        `otp_hash` CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `verify_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
        `send_count` INT UNSIGNED NOT NULL DEFAULT 1,
        `used_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_email_challenge` (`challenge_id`),
        KEY `idx_email_verify_user` (`user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return $mysqli->query($sql) === true;
}

function email_verify_pepper(): string
{
    return 'ebube-email-verify-v1';
}

function hash_email_verify_otp(string $challengeId, string $otp): string
{
    return hash('sha256', email_verify_pepper() . ':' . $challengeId . ':' . $otp);
}

function generate_email_verify_otp(): string
{
    return str_pad((string) random_int(0, 999999), EMAIL_VERIFY_LENGTH, '0', STR_PAD_LEFT);
}

if (!function_exists('mask_email_for_display')) {
    function mask_email_for_display(string $email): string
    {
        $email = normalize_email($email);
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return 'your email';
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $visible = substr($local, 0, 1);
        return $visible . str_repeat('*', max(2, strlen($local) - 1)) . $domain;
    }
}

function send_email_verification_mail(string $toEmail, string $otp): bool
{
    apply_mail_brand_from_request();
    $brand = app_brand_display_name();
    $subject = $brand . ' verify your email';
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $safeBrand = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
    $html = '<p>Hello,</p>'
        . '<p>Thanks for creating a <strong>' . $safeBrand . '</strong> account.</p>'
        . '<p>Your email verification code is:</p>'
        . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . $safeOtp . '</p>'
        . '<p>This code expires in ' . EMAIL_VERIFY_EXPIRY_MINUTES . ' minutes.</p>'
        . '<p>If you did not create an account, you can ignore this email.</p>';
    return send_app_html_email($toEmail, $subject, $html);
}

/**
 * @return array{success:bool,message?:string,challenge_id?:string,masked_email?:string,expires_in_seconds?:int,email_sent?:bool,http_code?:int}
 */
function create_email_verification_challenge(mysqli $mysqli, int $userId, string $email): array
{
    ensure_app_tables($mysqli);
    if (!ensure_email_verify_table($mysqli)) {
        return ['success' => false, 'message' => 'Could not prepare email verification storage', 'http_code' => 500];
    }

    $email = normalize_email($email);
    $windowStart = date('Y-m-d H:i:s', time() - (EMAIL_VERIFY_RATE_WINDOW_MINUTES * 60));
    $countStmt = $mysqli->prepare(
        'SELECT COUNT(*) AS c FROM email_verification_challenges WHERE user_id = ? AND created_at >= ?'
    );
    if ($countStmt) {
        $countStmt->bind_param('is', $userId, $windowStart);
        $countStmt->execute();
        $cRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();
        if ((int) ($cRow['c'] ?? 0) >= EMAIL_VERIFY_MAX_SENDS) {
            return [
                'success' => false,
                'message' => 'Too many verification emails. Try again later.',
                'http_code' => 429,
            ];
        }
    }

    $challengeId = bin2hex(random_bytes(16));
    $otp = generate_email_verify_otp();
    $otpHash = hash_email_verify_otp($challengeId, $otp);
    $expires = date('Y-m-d H:i:s', time() + (EMAIL_VERIFY_EXPIRY_MINUTES * 60));

    $stmt = $mysqli->prepare(
        'INSERT INTO email_verification_challenges
        (challenge_id, user_id, email, otp_hash, expires_at)
        VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error', 'http_code' => 500];
    }
    $stmt->bind_param('sisss', $challengeId, $userId, $email, $otpHash, $expires);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Could not create verification challenge', 'http_code' => 500];
    }
    $stmt->close();

    $sent = send_email_verification_mail($email, $otp);
    return [
        'success' => true,
        'challenge_id' => $challengeId,
        'masked_email' => mask_email_for_display($email),
        'expires_in_seconds' => EMAIL_VERIFY_EXPIRY_MINUTES * 60,
        'email_sent' => $sent,
        'message' => $sent
            ? 'Verification code sent to your email'
            : 'Account created, but the verification email could not be sent. Use Resend.',
        'http_code' => 200,
    ];
}

/**
 * @return array{success:bool,message?:string,http_code?:int}
 */
function verify_email_challenge(mysqli $mysqli, string $challengeId, string $otp): array
{
    if (!ensure_email_verify_table($mysqli)) {
        return ['success' => false, 'message' => 'Could not prepare email verification storage', 'http_code' => 500];
    }

    if (!preg_match('/^\d{' . EMAIL_VERIFY_LENGTH . '}$/', $otp)) {
        return ['success' => false, 'message' => 'Enter the ' . EMAIL_VERIFY_LENGTH . '-digit code from your email', 'http_code' => 400];
    }

    $stmt = $mysqli->prepare(
        'SELECT id, user_id, otp_hash, verify_attempts, used_at, expires_at
         FROM email_verification_challenges WHERE challenge_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error', 'http_code' => 500];
    }
    $stmt->bind_param('s', $challengeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'message' => 'Verification expired. Register again or resend code.', 'http_code' => 400];
    }
    if ($row['used_at'] !== null) {
        return ['success' => false, 'message' => 'Code already used. You can sign in.', 'http_code' => 400];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['success' => false, 'message' => 'Code expired. Tap Resend for a new code.', 'http_code' => 400];
    }
    if ((int) $row['verify_attempts'] >= 8) {
        return ['success' => false, 'message' => 'Too many incorrect attempts. Resend a new code.', 'http_code' => 429];
    }

    $id = (int) $row['id'];
    $inc = $mysqli->prepare('UPDATE email_verification_challenges SET verify_attempts = verify_attempts + 1 WHERE id = ?');
    if ($inc) {
        $inc->bind_param('i', $id);
        $inc->execute();
        $inc->close();
    }

    $expected = (string) $row['otp_hash'];
    $got = hash_email_verify_otp($challengeId, $otp);
    if (!hash_equals($expected, $got)) {
        return ['success' => false, 'message' => 'Incorrect verification code', 'http_code' => 400];
    }

    $userId = (int) $row['user_id'];
    $mark = $mysqli->prepare('UPDATE email_verification_challenges SET used_at = NOW() WHERE id = ?');
    if ($mark) {
        $mark->bind_param('i', $id);
        $mark->execute();
        $mark->close();
    }

    $verifyUser = $mysqli->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?');
    if ($verifyUser) {
        $verifyUser->bind_param('i', $userId);
        $verifyUser->execute();
        $verifyUser->close();
    }

    return [
        'success' => true,
        'message' => 'Email verified. You can sign in now.',
        'http_code' => 200,
        'user_id' => $userId,
    ];
}

function user_email_is_verified(array $user): bool
{
    $v = $user['email_verified_at'] ?? null;
    return $v !== null && trim((string) $v) !== '' && trim((string) $v) !== '0000-00-00 00:00:00';
}

function user_has_transaction_pin(array $user): bool
{
    $hash = trim((string) ($user['transaction_pin_hash'] ?? ''));
    return $hash !== '';
}
