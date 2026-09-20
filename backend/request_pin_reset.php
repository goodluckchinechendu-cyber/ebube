<?php
require_once 'config.php';
require_once 'auth_util.php';
require_once 'mail_util.php';

function ensure_reset_table(mysqli $mysqli): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL UNIQUE,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $mysqli->query($sql) === true;
}

function base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api'), '/\\');
    return $scheme . '://' . $host . $path;
}

function send_reset_email(string $toEmail, string $resetUrl): bool
{
    apply_mail_brand_from_request();
    $brand = app_brand_display_name();
    $safeBrand = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
    $subject = $brand . ' PIN reset';
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $partnerLine = htmlspecialchars(
        strtoupper($brand) . ' - YOUR TRUSTED PARTNER',
        ENT_QUOTES,
        'UTF-8'
    );
    $html = '<p>Hello,</p>'
        . '<p>You requested to reset your <strong>' . $safeBrand . '</strong> PIN.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:10px 16px;'
        . 'background:#004A8F;color:#ffffff;text-decoration:none;border-radius:6px;">'
        . 'Reset PIN</a></p>'
        . '<p>If you did not request this, you can ignore this email.</p>'
        . '<p>This link expires in 30 minutes.</p>'
        . '<p style="margin-top:20px;font-size:12px;color:#4b5563;text-align:center;">'
        . $partnerLine . '</p>';

    return send_app_html_email($toEmail, $subject, $html);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$rawLogin = trim($data['login'] ?? $data['email'] ?? $data['phone'] ?? '');
if ($rawLogin === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email or phone is required']);
    exit;
}

if (!ensure_reset_table($mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not prepare reset storage']);
    exit;
}

$isEmail = is_email_login($rawLogin);
$emailForLookup = normalize_email($rawLogin);
$phoneDigits = normalize_phone_digits($rawLogin);

$user = null;
if ($isEmail) {
    $stmt = $mysqli->prepare("SELECT id, email FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $emailForLookup);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
} else if (strlen($phoneDigits) >= 7) {
    $stmt = $mysqli->prepare(
        "SELECT id, email FROM users
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '') = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('s', $phoneDigits);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($user && !empty($user['email'])) {
    $userId = (int) $user['id'];
    $email = normalize_email((string) $user['email']);
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $cleanup = $mysqli->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? OR expires_at < NOW() OR used_at IS NOT NULL");
    if ($cleanup) {
        $cleanup->bind_param('i', $userId);
        $cleanup->execute();
        $cleanup->close();
    }

    $insert = $mysqli->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    if ($insert) {
        $insert->bind_param('iss', $userId, $tokenHash, $expiresAt);
        $ok = $insert->execute();
        $insert->close();

        if ($ok) {
            $resetUrl = base_url() . '/reset_pin_page.php?token=' . urlencode($token) . '&email=' . urlencode($email);
            send_reset_email($email, $resetUrl);
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => 'If the account exists, a PIN reset email has been sent.',
]);

$mysqli->close();
