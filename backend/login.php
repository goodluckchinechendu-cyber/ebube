<?php
/**
 * Direct login (no OTP). Requires verified email. Issues session token.
 */
require_once 'config.php';
require_once 'auth_util.php';
require_once 'mysqli_helpers.php';
require_once 'schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/email_verify_util.php';

try {
    $schemaError = ensure_app_tables($mysqli);
    if ($schemaError !== null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
        exit;
    }

    $rawLogin = trim($data['login'] ?? $data['email'] ?? $data['phone'] ?? '');
    $password = $data['password'] ?? '';

    if ($rawLogin === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email or phone and PIN required']);
        exit;
    }

    if (!is_valid_pin($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => pin_validation_message()]);
        exit;
    }

    $isEmail = is_email_login($rawLogin);
    $login = $isEmail ? normalize_email($rawLogin) : normalize_phone_digits($rawLogin);

    if (!$isEmail && strlen($login) < 7) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a valid phone number']);
        exit;
    }

    $userLite = mysqli_fetch_user_for_login($mysqli, $login, $isEmail);
    if ($userLite === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email, phone number, or PIN']);
        exit;
    }

    if (!password_verify($password, (string) $userLite['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email, phone number, or PIN']);
        exit;
    }

    $user = mysqli_fetch_user_by_id($mysqli, (int) $userLite['id']);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email, phone number, or PIN']);
        exit;
    }

    if (!user_email_is_verified($user)) {
        $email = normalize_email((string) ($user['email'] ?? ''));
        $challenge = create_email_verification_challenge($mysqli, (int) $user['id'], $email);
        http_response_code(403);
        if (empty($challenge['success'])) {
            // Rate-limited / send failure: still surface latest challenge so Resend/UI works.
            $latestId = null;
            $latestStmt = $mysqli->prepare(
                'SELECT challenge_id FROM email_verification_challenges
                 WHERE user_id = ? AND used_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            if ($latestStmt) {
                $uid = (int) $user['id'];
                $latestStmt->bind_param('i', $uid);
                $latestStmt->execute();
                $latestRow = $latestStmt->get_result()->fetch_assoc();
                $latestStmt->close();
                $latestId = $latestRow['challenge_id'] ?? null;
            }
            echo json_encode([
                'success' => false,
                'needs_email_verification' => true,
                'message' => $challenge['message']
                    ?? 'Please verify your email before signing in.',
                'challenge_id' => $latestId,
                'masked_email' => mask_email_for_display($email),
                'email' => $email,
                'email_sent' => false,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'needs_email_verification' => true,
                'message' => 'Please verify your email before signing in.',
                'challenge_id' => $challenge['challenge_id'] ?? null,
                'masked_email' => $challenge['masked_email'] ?? mask_email_for_display($email),
                'email' => $email,
                'email_sent' => $challenge['email_sent'] ?? false,
            ]);
        }
        $mysqli->close();
        exit;
    }

    $session = ec_create_session($mysqli, (int) $user['id']);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => login_user_payload($user),
        'session_token' => $session['token'],
        'session_expires_at' => $session['expires_at'],
        'requires_transaction_pin_setup' => !user_has_transaction_pin($user),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Login error: ' . $e->getMessage(),
    ]);
}

$mysqli->close();
