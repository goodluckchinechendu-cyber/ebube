<?php
/**
 * Super Admin: transaction limit settings (defaults + account-type/user rules).
 * Actions: list | save | save_rule | delete_rule
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/transaction_limits_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

if (!ensure_product_transaction_limits_table($mysqli) || !ensure_product_transaction_limit_rules_table($mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not prepare limits tables']);
    exit;
}

$sessionUser = ec_require_session($mysqli);
$sessionRole = (int) ($sessionUser['role'] ?? 0);

if ($sessionRole < 3) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$action = strtolower(trim((string) ($data['action'] ?? $_GET['action'] ?? 'list')));

if ($action === 'list' || $action === '') {
    echo json_encode([
        'success' => true,
        'limits' => product_transaction_limits_list($mysqli),
        'rules' => product_transaction_limit_rules_list($mysqli),
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'save') {
    $productId = strtolower(trim((string) ($data['product_id'] ?? '')));
    $maxOnce = isset($data['max_per_transaction']) ? (float) $data['max_per_transaction'] : -1;
    $daily = isset($data['daily_limit']) ? (float) $data['daily_limit'] : -1;
    $maxSim = isset($data['max_per_sim']) ? (float) $data['max_per_sim'] : -1;
    $days = isset($data['limit_days']) ? (int) $data['limit_days'] : 1;

    if (!in_array($productId, ['vtu', 'momo', 'logical'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'product_id must be vtu, momo, or logical']);
        exit;
    }
    if ($maxOnce < 0 || $daily < 0 || $maxSim < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Limits must be zero or greater']);
        exit;
    }
    if ($days < 1 || $days > 30) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'limit_days must be between 1 and 30']);
        exit;
    }

    if (!product_transaction_limit_save($mysqli, $productId, $maxOnce, $daily, $maxSim, $days)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not save limit']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Default limit saved',
        'limits' => product_transaction_limits_list($mysqli),
        'rules' => product_transaction_limit_rules_list($mysqli),
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'save_rule') {
    $ruleId = isset($data['id']) ? (int) $data['id'] : 0;
    $productId = strtolower(trim((string) ($data['product_id'] ?? '')));
    $scopeType = strtolower(trim((string) ($data['scope_type'] ?? '')));
    $scopeRole = array_key_exists('scope_role', $data) && $data['scope_role'] !== null && $data['scope_role'] !== ''
        ? (int) $data['scope_role']
        : null;
    $scopeUserId = array_key_exists('scope_user_id', $data) && $data['scope_user_id'] !== null && $data['scope_user_id'] !== ''
        ? (int) $data['scope_user_id']
        : null;
    $maxOnce = isset($data['max_per_transaction']) ? (float) $data['max_per_transaction'] : -1;
    $daily = isset($data['daily_limit']) ? (float) $data['daily_limit'] : -1;
    $maxSim = isset($data['max_per_sim']) ? (float) $data['max_per_sim'] : -1;
    $days = isset($data['limit_days']) ? (int) $data['limit_days'] : 1;

    if (!product_transaction_limit_rule_save(
        $mysqli,
        $productId,
        $scopeType,
        $scopeRole,
        $scopeUserId,
        $maxOnce,
        $daily,
        $maxSim,
        $days,
        $ruleId
    )) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Could not save rule. Pick VTU/MoMo/Logical and either an account type (Customer/Agent/Admin) or a user.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Rule saved',
        'limits' => product_transaction_limits_list($mysqli),
        'rules' => product_transaction_limit_rules_list($mysqli),
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'delete_rule') {
    $ruleId = isset($data['id']) ? (int) $data['id'] : 0;
    if (!product_transaction_limit_rule_delete($mysqli, $ruleId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not delete rule']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => 'Rule deleted',
        'limits' => product_transaction_limits_list($mysqli),
        'rules' => product_transaction_limit_rules_list($mysqli),
    ]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
