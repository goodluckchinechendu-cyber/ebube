<?php
require_once 'auth_util.php';
require_once 'mail_util.php';
require_once 'mysqli_helpers.php';

const LOGIN_OTP_LENGTH = 4;
const LOGIN_OTP_EXPIRY_MINUTES = 5;
const LOGIN_OTP_RATE_WINDOW_MINUTES = 5;
const LOGIN_OTP_MAX_SENDS_PER_WINDOW = 5;
const LOGIN_OTP_MAX_VERIFY_ATTEMPTS = 5;

function ensure_login_otp_table(mysqli $mysqli): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS `login_otp_challenges` (
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
        UNIQUE KEY `uniq_challenge_id` (`challenge_id`),
        KEY `idx_user_created` (`user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $mysqli->query($sql) === true;
}

function login_otp_pepper(): string
{
    return 'smobile-login-otp-v1';
}

function hash_login_otp(string $challengeId, string $otp): string
{
    return hash('sha256', login_otp_pepper() . ':' . $challengeId . ':' . $otp);
}

function generate_login_otp(): string
{
    return str_pad((string) random_int(0, 9999), LOGIN_OTP_LENGTH, '0', STR_PAD_LEFT);
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

function send_login_otp_email(string $toEmail, string $otp, ?string $appName = null): bool
{
    apply_mail_brand_from_request();
    $brand = $appName ?? app_brand_display_name();
    $subject = $brand . ' login verification code';
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $safeBrand = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
    $partnerLine = htmlspecialchars(
        strtoupper($brand) . ' - YOUR TRUSTED PARTNER',
        ENT_QUOTES,
        'UTF-8'
    );
    $html = '<p>Hello,</p>'
        . '<p>Your <strong>' . $safeBrand . '</strong> login verification code is:</p>'
        . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;">' . $safeOtp . '</p>'
        . '<p>This code expires in ' . LOGIN_OTP_EXPIRY_MINUTES . ' minutes.</p>'
        . '<p>If you did not try to sign in, you can ignore this email.</p>'
        . '<p style="margin-top:20px;font-size:12px;color:#4b5563;text-align:center;">'
        . $partnerLine . '</p>';

    return send_app_html_email($toEmail, $subject, $html);
}

function count_recent_otp_sends(mysqli $mysqli, int $userId): int
{
    $userId = (int) $userId;
    $minutes = (int) LOGIN_OTP_RATE_WINDOW_MINUTES;
    $sql = "SELECT COALESCE(SUM(send_count), 0) AS total
            FROM login_otp_challenges
            WHERE user_id = $userId
              AND created_at >= (NOW() - INTERVAL $minutes MINUTE)";
    $result = $mysqli->query($sql);
    if (!$result instanceof mysqli_result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    $result->free();
    return (int) ($row['total'] ?? 0);
}

function invalidate_user_otp_challenges(mysqli $mysqli, int $userId): void
{
    $stmt = $mysqli->prepare(
        "UPDATE login_otp_challenges
         SET used_at = NOW()
         WHERE user_id = ? AND used_at IS NULL"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function create_login_otp_challenge(mysqli $mysqli, int $userId, string $email): array
{
    if (count_recent_otp_sends($mysqli, $userId) >= LOGIN_OTP_MAX_SENDS_PER_WINDOW) {
        return [
            'success' => false,
            'message' => 'Too many verification codes requested. Try again in '
                . LOGIN_OTP_RATE_WINDOW_MINUTES . ' minutes.',
            'http_code' => 429,
        ];
    }

    invalidate_user_otp_challenges($mysqli, $userId);

    $challengeId = bin2hex(random_bytes(16));
    $otp = generate_login_otp();
    $otpHash = hash_login_otp($challengeId, $otp);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . LOGIN_OTP_EXPIRY_MINUTES . ' minutes'));

    $stmt = $mysqli->prepare(
        "INSERT INTO login_otp_challenges
         (challenge_id, user_id, email, otp_hash, expires_at, verify_attempts, send_count)
         VALUES (?, ?, ?, ?, ?, 0, 1)"
    );
    if (!$stmt) {
        return [
            'success' => false,
            'message' => 'Could not start verification: ' . $mysqli->error,
            'http_code' => 500,
        ];
    }

    $stmt->bind_param('sisss', $challengeId, $userId, $email, $otpHash, $expiresAt);
    $ok = $stmt->execute();
    $dbError = $stmt->error;
    $stmt->close();

    if (!$ok) {
        return [
            'success' => false,
            'message' => 'Could not save verification code'
                . ($dbError !== '' ? ": $dbError" : '')
                . '. Check DB permissions; tables are created automatically via schema.php.',
            'http_code' => 500,
        ];
    }

    $emailSent = send_login_otp_email($email, $otp);

    return [
        'success' => true,
        'challenge_id' => $challengeId,
        'masked_email' => mask_email_for_display($email),
        'expires_in_seconds' => LOGIN_OTP_EXPIRY_MINUTES * 60,
        'email_sent' => $emailSent,
        'message' => $emailSent
            ? 'Verification code sent to your email'
            : 'Verification started but email could not be sent. Tap Resend code.',
    ];
}

function resend_login_otp_challenge(mysqli $mysqli, string $challengeId): array
{
    $stmt = $mysqli->prepare(
        "SELECT id, user_id, email, verify_attempts, send_count, used_at, expires_at
         FROM login_otp_challenges
         WHERE challenge_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error', 'http_code' => 500];
    }
    $stmt->bind_param('s', $challengeId);
    $stmt->execute();
    $row = mysqli_stmt_fetch_assoc($stmt);
    $stmt->close();

    if (!$row || $row['used_at'] !== null) {
        return ['success' => false, 'message' => 'Verification expired. Sign in again.', 'http_code' => 400];
    }

    $userId = (int) $row['user_id'];
    if (count_recent_otp_sends($mysqli, $userId) >= LOGIN_OTP_MAX_SENDS_PER_WINDOW) {
        return [
            'success' => false,
            'message' => 'Too many verification codes requested. Try again in '
                . LOGIN_OTP_RATE_WINDOW_MINUTES . ' minutes.',
            'http_code' => 429,
        ];
    }

    $otp = generate_login_otp();
    $otpHash = hash_login_otp($challengeId, $otp);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . LOGIN_OTP_EXPIRY_MINUTES . ' minutes'));
    $sendCount = ((int) $row['send_count']) + 1;

    $update = $mysqli->prepare(
        "UPDATE login_otp_challenges
         SET otp_hash = ?, expires_at = ?, verify_attempts = 0, send_count = ?
         WHERE challenge_id = ? AND used_at IS NULL"
    );
    if (!$update) {
        return ['success' => false, 'message' => 'Could not resend code', 'http_code' => 500];
    }
    $update->bind_param('ssis', $otpHash, $expiresAt, $sendCount, $challengeId);
    $ok = $update->execute();
    $update->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Could not resend code', 'http_code' => 500];
    }

    $email = normalize_email((string) $row['email']);
    $emailSent = send_login_otp_email($email, $otp);

    return [
        'success' => true,
        'masked_email' => mask_email_for_display($email),
        'expires_in_seconds' => LOGIN_OTP_EXPIRY_MINUTES * 60,
        'email_sent' => $emailSent,
        'message' => $emailSent
            ? 'A new verification code was sent to your email'
            : 'Code updated but email could not be sent. Check server mail settings.',
    ];
}

function verify_login_otp_challenge(mysqli $mysqli, string $challengeId, string $otp): array
{
    if (!preg_match('/^\d{4}$/', $otp)) {
        return ['success' => false, 'message' => 'Enter the 4-digit code from your email', 'http_code' => 400];
    }

    $stmt = $mysqli->prepare(
        "SELECT id, user_id, otp_hash, verify_attempts, used_at, expires_at
         FROM login_otp_challenges
         WHERE challenge_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error', 'http_code' => 500];
    }
    $stmt->bind_param('s', $challengeId);
    $stmt->execute();
    $row = mysqli_stmt_fetch_assoc($stmt);
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'message' => 'Verification expired. Sign in again.', 'http_code' => 400];
    }

    if ($row['used_at'] !== null) {
        return ['success' => false, 'message' => 'Code already used. Sign in again.', 'http_code' => 400];
    }

    if (strtotime((string) $row['expires_at']) < time()) {
        return ['success' => false, 'message' => 'Code expired. Sign in again.', 'http_code' => 400];
    }

    $attempts = (int) $row['verify_attempts'];
    if ($attempts >= LOGIN_OTP_MAX_VERIFY_ATTEMPTS) {
        return [
            'success' => false,
            'message' => 'Too many incorrect attempts. Sign in again.',
            'http_code' => 429,
        ];
    }

    $expectedHash = (string) $row['otp_hash'];
    $providedHash = hash_login_otp($challengeId, $otp);
    if (!hash_equals($expectedHash, $providedHash)) {
        $attempts++;
        $update = $mysqli->prepare(
            "UPDATE login_otp_challenges SET verify_attempts = ? WHERE id = ?"
        );
        if ($update) {
            $challengeDbId = (int) $row['id'];
            $update->bind_param('ii', $attempts, $challengeDbId);
            $update->execute();
            $update->close();
        }
        $remaining = LOGIN_OTP_MAX_VERIFY_ATTEMPTS - $attempts;
        $message = $remaining > 0
            ? "Incorrect code. $remaining attempt(s) left."
            : 'Too many incorrect attempts. Sign in again.';
        return ['success' => false, 'message' => $message, 'http_code' => 401];
    }

    $mark = $mysqli->prepare(
        "UPDATE login_otp_challenges SET used_at = NOW() WHERE id = ? AND used_at IS NULL"
    );
    if ($mark) {
        $challengeDbId = (int) $row['id'];
        $mark->bind_param('i', $challengeDbId);
        $mark->execute();
        $mark->close();
    }

    $userId = (int) $row['user_id'];
    $user = mysqli_fetch_user_by_id($mysqli, $userId);

    if (!$user) {
        return ['success' => false, 'message' => 'Account not found', 'http_code' => 404];
    }

    return [
        'success' => true,
        'user' => login_user_payload($user),
    ];
}

function login_user_payload(array $user): array
{
    $hasTxnPin = trim((string) ($user['transaction_pin_hash'] ?? '')) !== '';
    $verified = !empty($user['email_verified_at'])
        && trim((string) $user['email_verified_at']) !== ''
        && trim((string) $user['email_verified_at']) !== '0000-00-00 00:00:00';

    return [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'phone' => $user['phone'] ?? '',
        'location' => $user['location'] ?? '',
        'gender' => $user['gender'] ?? '',
        'email' => $user['email'],
        'role' => (int) $user['role'],
        'email_verified' => $verified,
        'has_transaction_pin' => $hasTxnPin,
        'momo_balance' => (float) ($user['momo_balance'] ?? 0),
        'vtu_balance' => (float) ($user['vtu_balance'] ?? 0),
        'logical_balance' => (float) ($user['logical_balance'] ?? 0),
        'data_bundle_balance' => (float) ($user['data_bundle_balance'] ?? 0),
        'commission_balance' => (float) ($user['commission_balance'] ?? 0),
        'last_seen_announcement_id' => (int) ($user['last_seen_announcement_id'] ?? 0),
    ] + user_payout_from_row($user);
}
