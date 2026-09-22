<?php
/**
 * SMobile Agent webhook receiver.
 *
 * Enter this public URL in SMobile → Developer Settings → Webhook URL:
 *   https://ebubeconnect.com/backend/vtu_webhook.php
 *
 * Headers:
 *   X-SMobile-Signature: sha256=<hmac_hex>
 * Body: JSON (event transaction.updated on final status)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smobile_vtu_client.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/wallet_pool.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'POST only',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$cfg = smobile_vtu_load_config();
if (empty($cfg['webhook_configured'])) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Webhook secret not configured',
    ]);
    exit;
}

$signatureHeader = trim((string) ($_SERVER['HTTP_X_SMOBILE_SIGNATURE'] ?? ''));
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, (string) $cfg['webhook_secret']);

if ($signatureHeader === '' || !hash_equals($expected, $signatureHeader)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid webhook signature',
    ]);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON body',
    ]);
    exit;
}

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database setup failed: ' . $schemaError,
    ]);
    exit;
}

$event = trim((string) ($payload['event'] ?? $payload['type'] ?? 'transaction.updated'));

$data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];
$txn = (isset($payload['transaction']) && is_array($payload['transaction'])) ? $payload['transaction'] : [];

$reference = trim((string) (
    $payload['reference']
    ?? $data['reference']
    ?? $txn['reference']
    ?? ''
));
$status = trim((string) (
    $payload['status']
    ?? $data['status']
    ?? $txn['status']
    ?? ($payload['data']['transaction']['status'] ?? '')
    ?? ''
));

// Some providers nest final status only under data / transaction blobs.
if ($status === '' && isset($data['transaction']) && is_array($data['transaction'])) {
    $status = trim((string) ($data['transaction']['status'] ?? ''));
}
if ($status === '' && isset($payload['transaction']) && is_array($payload['transaction'])) {
    $status = trim((string) ($payload['transaction']['status'] ?? ''));
}

$stmt = $mysqli->prepare(
    'INSERT INTO vtu_webhook_events (event_name, reference, status, signature_ok, payload_json)
     VALUES (?, ?, ?, 1, ?)'
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $mysqli->error,
    ]);
    exit;
}

$payloadJson = $rawBody;
$stmt->bind_param('ssss', $event, $reference, $status, $payloadJson);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not store webhook: ' . $stmt->error,
    ]);
    $stmt->close();
    exit;
}
$stmt->close();

// Settle local injected-wallet holds when provider reaches a final status.
$info = smobile_vtu_status_info($payload, null, $reference !== '');
$class = $info['class'];
$holdNote = null;
if ($reference !== '') {
    if ($info['success']) {
        $holdNote = vtu_hold_complete($mysqli, $reference) ? 'hold_completed' : 'hold_complete_skipped';
    } elseif ($info['failed']) {
        $holdNote = vtu_hold_refund($mysqli, $reference) ? 'hold_refunded' : 'hold_refund_skipped';
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Webhook received',
    'event' => $event,
    'reference' => $reference,
    'status' => $status !== '' ? $status : $class,
    'hold' => $holdNote,
]);

$mysqli->close();
