<?php
require_once 'config.php';
require_once 'auth_util.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/user_visibility_util.php';

$sessionUser = ec_require_session($mysqli);
ensure_user_visibility_columns($mysqli);
$sessionId = (int) ($sessionUser['id'] ?? 0);
if ($sessionId <= 0) {
    $sessionId = (int) ($sessionUser['user_id'] ?? 0);
}
$sessionRole = (int) ($sessionUser['role'] ?? 0);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

if ($sessionRole < 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only an administrator can delete users']);
    exit;
}

$id = isset($data['id']) ? (int) $data['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User id required']);
    exit;
}

// Never allow Admin / Super Admin to delete the account they are signed in as.
if ($sessionId > 0 && $id === $sessionId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id, full_name, role, COALESCE(is_external, 0) AS is_external FROM users WHERE id = ? LIMIT 1'
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Target user not found']);
    exit;
}

$targetRole = (int) $target['role'];

// Super Admin: anyone. Admin: Customers (0) and Agents (1) only — never externals.
if ($sessionRole < 3 && (int) ($target['is_external'] ?? 0) === 1) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Target user not found']);
    exit;
}

if ($sessionRole < 3 && $targetRole > 1) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin can only delete Agents and Customers',
    ]);
    exit;
}

/**
 * Run optional cleanup SQL. Missing tables / soft failures must not abort delete
 * (PHP 8 mysqli throws on prepare when a table does not exist).
 */
function ec_delete_cleanup(mysqli $mysqli, string $sql, int $userId): void
{
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        @$stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // ignore — optional auth/aux tables may not exist on every environment
    }
}

$mysqli->begin_transaction();
try {
    // Soft-revoke then hard-delete sessions.
    ec_delete_cleanup(
        $mysqli,
        'UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
        $id
    );

    // Clear referral / tree pointers to this user.
    ec_delete_cleanup(
        $mysqli,
        'UPDATE users SET registered_by = NULL WHERE registered_by = ?',
        $id
    );

    // Optional auth-related rows (tables may be created lazily elsewhere).
    foreach (
        [
            'DELETE FROM password_reset_tokens WHERE user_id = ?',
            'DELETE FROM email_verification_challenges WHERE user_id = ?',
            'DELETE FROM txn_pin_attempts WHERE user_id = ?',
            'DELETE FROM user_sessions WHERE user_id = ?',
            'DELETE FROM user_preferences WHERE user_id = ?',
        ] as $sql
    ) {
        ec_delete_cleanup($mysqli, $sql, $id);
    }

    $del = $mysqli->prepare('DELETE FROM users WHERE id = ? LIMIT 1');
    if (!$del) {
        throw new RuntimeException('Database error: ' . $mysqli->error);
    }
    $del->bind_param('i', $id);
    if (!$del->execute()) {
        $err = $del->error !== '' ? $del->error : 'User could not be deleted';
        $del->close();
        throw new RuntimeException($err);
    }
    if ($del->affected_rows < 1) {
        $del->close();
        throw new RuntimeException('User could not be deleted');
    }
    $del->close();

    $mysqli->commit();
    echo json_encode([
        'success' => true,
        'message' => 'User deleted successfully',
        'user_id' => $id,
        'full_name' => (string) ($target['full_name'] ?? ''),
    ]);
} catch (Throwable $e) {
    $mysqli->rollback();
    http_response_code(500);
    // Never leak raw DB credential names; keep message short for the app.
    $msg = $e->getMessage();
    if (stripos($msg, 'password_reset') !== false || stripos($msg, "doesn't exist") !== false) {
        $msg = 'Could not delete user (database cleanup). Please try again.';
    }
    echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $msg]);
}

$mysqli->close();
