<?php
/**
 * Live pay-amount preview for airtime/data (discount + wallet charge).
 * POST: product_type (airtime|data), face_amount
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

$sessionUser = ec_require_session($mysqli);
$data = json_decode(file_get_contents('php://input'), true) ?? [];

$productType = strtolower(trim((string) ($data['product_type'] ?? 'airtime')));
if ($productType !== 'airtime' && $productType !== 'data') {
    $productType = 'airtime';
}
$faceAmount = isset($data['face_amount']) ? (float) $data['face_amount'] : 0;
if ($faceAmount <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'face_amount must be greater than zero']);
    exit;
}

$role = (int) ($sessionUser['role'] ?? 1);
$pricing = resolve_vtu_pricing($mysqli, $productType, $faceAmount, $role);

if ($pricing === null) {
    echo json_encode([
        'success' => true,
        'face_value' => round($faceAmount, 2),
        'discount' => 0,
        'commission' => 0,
        'wallet_charge' => round($faceAmount, 2),
        'tier' => null,
    ]);
    $mysqli->close();
    exit;
}

echo json_encode([
    'success' => true,
    'face_value' => round($faceAmount, 2),
    'discount' => round((float) ($pricing['discount'] ?? 0), 2),
    'commission' => round((float) ($pricing['commission'] ?? 0), 2),
    'wallet_charge' => round((float) ($pricing['wallet_charge'] ?? $faceAmount), 2),
    'tier' => $pricing['tier'] ?? null,
]);
$mysqli->close();
