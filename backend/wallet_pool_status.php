<?php
/**
 * Super Admin: SMobile master wallet vs injected MoMo/VTU/Logical totals.
 * Admin: personal funded balances only (no SMobile pool).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smobile_vtu_client.php';
require_once __DIR__ . '/wallet_pool.php';
require_once __DIR__ . '/session_auth.php';

$sessionUser = ec_require_session($mysqli);
$sessionRole = (int) $sessionUser['role'];
if ($sessionRole < 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required to view wallet funding status']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$actorId = isset($data['actor_id']) ? (int) $data['actor_id'] : (isset($_GET['actor_id']) ? (int) $_GET['actor_id'] : 0);
if ($actorId > 0 && $actorId !== (int) $sessionUser['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'actor_id must match authenticated admin']);
    exit;
}

// Admin: only what Super Admin funded them — no SMobile pool.
if ($sessionRole === 2) {
    $uid = (int) $sessionUser['id'];
    $row = fetch_user_role_row($mysqli, $uid);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        $mysqli->close();
        exit;
    }
    $momo = (float) ($row['momo_balance'] ?? 0);
    $vtu = (float) ($row['vtu_balance'] ?? 0);
    $logical = (float) ($row['logical_balance'] ?? 0);
    echo json_encode([
        'success' => true,
        'mode' => 'personal',
        'show_smobile_pool' => false,
        'momo_balance' => $momo,
        'vtu_balance' => $vtu,
        'logical_balance' => $logical,
        // Convenience: max across products (UI picks the selected product).
        'available_to_inject' => max($momo, $vtu, $logical),
        'smobile_balance' => null,
        'injected_total' => null,
        'currency' => 'NGN',
        'response_code' => 200,
    ]);
    $mysqli->close();
    exit;
}

$snap = wallet_pool_snapshot($mysqli);
$snap['mode'] = 'pool';
$snap['show_smobile_pool'] = true;
http_response_code((int) ($snap['response_code'] ?? ($snap['success'] ? 200 : 502)));
echo json_encode($snap);
$mysqli->close();
