<?php
/**
 * Invite link for the signed-in user.
 *
 * Super Admin may choose Internal vs External (default External) for people
 * who register through the shared link. Other roles get a plain invite link.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/referral_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) ($sessionUser['role'] ?? 0);
$isSuperAdmin = $sessionRole >= 3;

$code = user_ensure_referral_code($mysqli, $sessionId);
if ($code === null || $code === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create invite code']);
    exit;
}

$underCount = 0;
$stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM users WHERE registered_by = ?');
if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $underCount = (int) ($row['c'] ?? 0);
}

// SA may pass visibility=ext|int (default ext). Others ignore it.
$visibility = null;
if ($isSuperAdmin) {
    $rawVis = strtolower(trim((string) ($_GET['visibility'] ?? $_GET['vis'] ?? 'ext')));
    if (in_array($rawVis, ['int', 'internal', '0'], true)) {
        $visibility = 'int';
    } else {
        $visibility = 'ext';
    }
}

$inviteUrl = referral_invite_url($code, null, $visibility);

echo json_encode([
    'success' => true,
    'referral_code' => $code,
    'invite_url' => $inviteUrl,
    'is_super_admin' => $isSuperAdmin,
    'default_visibility' => $isSuperAdmin ? ($visibility ?? 'ext') : null,
    'registered_under_count' => $underCount,
    'message' => $isSuperAdmin
        ? 'Share this link. New sign-ups default to external unless you switch to internal.'
        : 'Share this link. Anyone who registers through it will be under your account.',
]);
$mysqli->close();
