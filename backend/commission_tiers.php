<?php
/**
 * Admin / Super Admin: Commission & Discount tiers (airtime / data).
 * POST body:
 *   action=list|save|delete
 *   save fields: product_type, applies_to, min_amount, max_amount,
 *                commission_type, commission_value, discount_type, discount_value, is_active, id?
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/commission_tiers_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

if (!ensure_commission_tiers_tables($mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not prepare commission tables']);
    exit;
}

$sessionUser = ec_require_session($mysqli);
ec_require_min_role($sessionUser, 2); // Admin or Super Admin

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$action = strtolower(trim((string) ($data['action'] ?? $_GET['action'] ?? 'list')));
$sessionRole = (int) ($sessionUser['role'] ?? 0);

if ($action === 'list' || $action === '') {
    $product = isset($data['product_type']) ? trim((string) $data['product_type']) : null;
    if ($product === '') {
        $product = null;
    }
    $tiers = commission_tiers_list($mysqli, $product);
    // Non–Super Admin must not see super_admin-targeted rules (label would leak the role).
    if ($sessionRole < 3) {
        $tiers = array_values(array_filter(
            $tiers,
            static fn(array $t): bool => strtolower((string) ($t['applies_to'] ?? '')) !== 'super_admin'
        ));
    }
    echo json_encode([
        'success' => true,
        'tiers' => $tiers,
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'delete') {
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id required']);
        exit;
    }
    if ($sessionRole < 3) {
        $check = $mysqli->prepare('SELECT applies_to FROM vtu_commission_tiers WHERE id = ? LIMIT 1');
        $check->bind_param('i', $id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();
        if ($row && strtolower((string) ($row['applies_to'] ?? '')) === 'super_admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            $mysqli->close();
            exit;
        }
    }
    $stmt = $mysqli->prepare('DELETE FROM vtu_commission_tiers WHERE id = ?');
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Tier deleted' : 'Delete failed',
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'save') {
    $id = (int) ($data['id'] ?? 0);
    $productType = strtolower(trim((string) ($data['product_type'] ?? '')));
    $appliesTo = strtolower(trim((string) ($data['applies_to'] ?? 'all')));
    $minAmount = (float) ($data['min_amount'] ?? 0);
    $maxRaw = $data['max_amount'] ?? null;
    $maxAmount = ($maxRaw === null || $maxRaw === '' || $maxRaw === false)
        ? null
        : (float) $maxRaw;
    $commissionType = strtolower(trim((string) ($data['commission_type'] ?? 'fixed')));
    $commissionValue = (float) ($data['commission_value'] ?? 0);
    $discountType = strtolower(trim((string) ($data['discount_type'] ?? 'fixed')));
    $discountValue = (float) ($data['discount_value'] ?? 0);
    $isActive = !isset($data['is_active']) || $data['is_active'] === true || $data['is_active'] === 1 || $data['is_active'] === '1';

    if ($productType !== 'airtime' && $productType !== 'data') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'product_type must be airtime or data']);
        exit;
    }
    $allowedApplies = $sessionRole >= 3
        ? ['all', 'agent', 'admin', 'super_admin']
        : ['all', 'agent', 'admin'];
    if (!in_array($appliesTo, $allowedApplies, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid account type for this rule']);
        exit;
    }
    if ($sessionRole < 3 && $id > 0) {
        $check = $mysqli->prepare('SELECT applies_to FROM vtu_commission_tiers WHERE id = ? LIMIT 1');
        $check->bind_param('i', $id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing && strtolower((string) ($existing['applies_to'] ?? '')) === 'super_admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            $mysqli->close();
            exit;
        }
    }
    if ($minAmount < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'min_amount cannot be negative']);
        exit;
    }
    if ($maxAmount !== null && $maxAmount < $minAmount) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'max_amount must be >= min_amount']);
        exit;
    }
    foreach ([['commission', $commissionType, $commissionValue], ['discount', $discountType, $discountValue]] as $pair) {
        [$label, $ctype, $cval] = $pair;
        if ($ctype !== 'fixed' && $ctype !== 'percent') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $label . '_type must be fixed or percent']);
            exit;
        }
        if ($cval < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $label . '_value cannot be negative']);
            exit;
        }
        if ($ctype === 'percent' && $cval > 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $label . ' percent cannot exceed 100']);
            exit;
        }
    }
    if ($commissionValue <= 0 && $discountValue <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Set a commission and/or a discount greater than zero']);
        exit;
    }

    $forceOverlap = !empty($data['confirm_overlap']) || !empty($data['force']);
    if ($isActive) {
        $overlaps = commission_tier_find_overlaps(
            $mysqli,
            $productType,
            $appliesTo,
            $minAmount,
            $maxAmount,
            $id
        );
        if ($overlaps !== [] && !$forceOverlap) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'code' => 'tier_overlap',
                'message' => 'This rule overlaps another active rule for the same product/audience. Confirm to save anyway, or narrow the amount range.',
                'overlaps' => $overlaps,
            ]);
            exit;
        }
    }

    $activeInt = $isActive ? 1 : 0;

    if ($id > 0) {
        if ($maxAmount === null) {
            $stmt = $mysqli->prepare(
                'UPDATE vtu_commission_tiers
                 SET product_type = ?, applies_to = ?, min_amount = ?, max_amount = NULL,
                     commission_type = ?, commission_value = ?,
                     discount_type = ?, discount_value = ?, is_active = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'ssdsdsdii',
                $productType,
                $appliesTo,
                $minAmount,
                $commissionType,
                $commissionValue,
                $discountType,
                $discountValue,
                $activeInt,
                $id
            );
        } else {
            $stmt = $mysqli->prepare(
                'UPDATE vtu_commission_tiers
                 SET product_type = ?, applies_to = ?, min_amount = ?, max_amount = ?,
                     commission_type = ?, commission_value = ?,
                     discount_type = ?, discount_value = ?, is_active = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'ssddsdsdii',
                $productType,
                $appliesTo,
                $minAmount,
                $maxAmount,
                $commissionType,
                $commissionValue,
                $discountType,
                $discountValue,
                $activeInt,
                $id
            );
        }
    } else {
        if ($maxAmount === null) {
            $stmt = $mysqli->prepare(
                'INSERT INTO vtu_commission_tiers
                (product_type, applies_to, min_amount, max_amount, commission_type, commission_value,
                 discount_type, discount_value, is_active)
                VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssdsdsdi',
                $productType,
                $appliesTo,
                $minAmount,
                $commissionType,
                $commissionValue,
                $discountType,
                $discountValue,
                $activeInt
            );
        } else {
            $stmt = $mysqli->prepare(
                'INSERT INTO vtu_commission_tiers
                (product_type, applies_to, min_amount, max_amount, commission_type, commission_value,
                 discount_type, discount_value, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssddsdsdi',
                $productType,
                $appliesTo,
                $minAmount,
                $maxAmount,
                $commissionType,
                $commissionValue,
                $discountType,
                $discountValue,
                $activeInt
            );
        }
    }

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Save failed: ' . $stmt->error]);
        $stmt->close();
        exit;
    }
    $newId = $id > 0 ? $id : (int) $stmt->insert_id;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Commission & discount tier saved',
        'id' => $newId,
        'tiers' => commission_tiers_list($mysqli),
    ]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
