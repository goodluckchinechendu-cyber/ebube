<?php
/**
 * Bearer session tokens for EbubeConnect API.
 * Issued on OTP verify; required for money / admin mutations.
 */

require_once __DIR__ . '/schema.php';

const EC_SESSION_TTL_DAYS = 30;

function ec_get_bearer_token(): string
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                $header = (string) $v;
                break;
            }
        }
    }

    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
        return trim($m[1]);
    }

    // Some hosts strip Authorization; Flutter also sends this fallback.
    if (!empty($_SERVER['HTTP_X_EBUBE_SESSION'])) {
        return trim((string) $_SERVER['HTTP_X_EBUBE_SESSION']);
    }

    // Avoid reading php://input here (endpoints need the body). GET fallback only.
    if (isset($_GET['session_token'])) {
        return trim((string) $_GET['session_token']);
    }

    return '';
}

function ec_hash_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Create a session for user after successful OTP login.
 * @return array{token:string, expires_at:string}
 */
function ec_create_session(mysqli $mysqli, int $userId, ?string $userAgent = null, ?string $ip = null): array
{
    ensure_app_tables($mysqli);

    $token = bin2hex(random_bytes(32));
    $hash = ec_hash_token($token);
    $expires = date('Y-m-d H:i:s', time() + (EC_SESSION_TTL_DAYS * 86400));
    $ua = substr((string) ($userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    $ipAddr = substr((string) ($ip ?? ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);

    $stmt = $mysqli->prepare(
        'INSERT INTO user_sessions (user_id, token_hash, expires_at, user_agent, ip_address)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not create session: ' . $mysqli->error);
    }
    $stmt->bind_param('issss', $userId, $hash, $expires, $ua, $ipAddr);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not create session: ' . $err);
    }
    $stmt->close();

    return [
        'token' => $token,
        'expires_at' => $expires,
    ];
}

function ec_revoke_session(mysqli $mysqli, string $token): void
{
    if ($token === '') {
        return;
    }
    $hash = ec_hash_token($token);
    $stmt = $mysqli->prepare(
        'UPDATE user_sessions SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL'
    );
    if ($stmt) {
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $stmt->close();
    }
}

function ec_revoke_all_sessions(mysqli $mysqli, int $userId): void
{
    $stmt = $mysqli->prepare(
        'UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL'
    );
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Validate bearer token and return user row.
 * Exits with 401 JSON on failure.
 *
 * @return array{id:int,role:int,full_name:string,email:string,...}
 */
function ec_require_session(mysqli $mysqli, ?int $mustBeUserId = null): array
{
    ensure_app_tables($mysqli);

    $token = ec_get_bearer_token();
    if ($token === '' || strlen($token) < 32) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'response_code' => 401,
            'message' => 'Authentication required. Please sign in again.',
        ]);
        exit;
    }

    $hash = ec_hash_token($token);
    $stmt = $mysqli->prepare(
        'SELECT s.id AS session_id, s.user_id, s.expires_at, s.revoked_at,
                u.id, u.role, u.full_name, u.email, u.phone,
                u.momo_balance, u.vtu_balance, u.logical_balance,
                u.data_bundle_balance, u.commission_balance
         FROM user_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ?
         LIMIT 1'
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'response_code' => 401,
            'message' => 'Invalid session. Please sign in again.',
        ]);
        exit;
    }

    if ($row['revoked_at'] !== null) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'response_code' => 401,
            'message' => 'Session ended. Please sign in again.',
        ]);
        exit;
    }

    if (strtotime((string) $row['expires_at']) < time()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'response_code' => 401,
            'message' => 'Session expired. Please sign in again.',
        ]);
        exit;
    }

    $sessionUserId = (int) $row['user_id'];
    if ($mustBeUserId !== null && $sessionUserId !== $mustBeUserId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'response_code' => 403,
            'message' => 'Session does not match the requested user',
        ]);
        exit;
    }

    $sid = (int) $row['session_id'];
    $touch = $mysqli->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?');
    if ($touch) {
        $touch->bind_param('i', $sid);
        $touch->execute();
        $touch->close();
    }

    return $row;
}

function ec_require_min_role(array $sessionUser, int $minRole): void
{
    if ((int) ($sessionUser['role'] ?? 0) < $minRole) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'response_code' => 403,
            'message' => 'Insufficient permissions',
        ]);
        exit;
    }
}
