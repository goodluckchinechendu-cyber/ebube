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

$bundles = [];
// Data Bundle wallet removed — keep empty array for older clients.

$commissions = [];
$r = $mysqli->query('SELECT product_id, commission_per_unit FROM product_commissions');
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $commissions[] = [
            'product_id' => $row['product_id'],
            'commission_per_unit' => (float) $row['commission_per_unit'],
        ];
    }
}

echo json_encode([
    'success' => true,
    'data_bundle_prices' => $bundles,
    'product_commissions' => $commissions,
]);

$mysqli->close();
