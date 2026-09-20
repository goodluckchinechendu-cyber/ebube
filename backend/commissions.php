<?php
require_once 'config.php';
require_once 'schema.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

if (!isset($data['product_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'product_id required']);
    exit;
}

$productId = (string) $data['product_id'];
$commission = isset($data['commission_percent'])
    ? (float) $data['commission_percent']
    : (float) ($data['commission_per_unit'] ?? 0);

if ($commission < 0 || $commission > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Commission percentage must be between 0 and 100']);
    exit;
}

$stmt = $mysqli->prepare(
    'INSERT INTO product_commissions (product_id, commission_per_unit) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE commission_per_unit = VALUES(commission_per_unit)'
);
$stmt->bind_param('sd', $productId, $commission);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}

$stmt->close();
$mysqli->close();
