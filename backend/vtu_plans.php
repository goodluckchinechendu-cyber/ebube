<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smobile_vtu_client.php';

$query = [];
$network = trim((string) ($_GET['network'] ?? ''));
$networkId = trim((string) ($_GET['network_id'] ?? ''));

if ($network !== '') {
    $normalized = smobile_vtu_normalize_network($network);
    if ($normalized !== null && $normalized !== '') {
        $query['network'] = $normalized;
    }
}
if ($networkId !== '') {
    $query['network_id'] = $networkId;
}

// Also accept JSON body for POST-style clients.
$input = json_decode(file_get_contents('php://input') ?: 'null', true);
if (is_array($input)) {
    if ($network === '' && !empty($input['network'])) {
        $normalized = smobile_vtu_normalize_network($input['network']);
        if ($normalized !== null && $normalized !== '') {
            $query['network'] = $normalized;
            $network = is_string($normalized) ? $normalized : $network;
        }
    }
    if ($networkId === '' && isset($input['network_id']) && $input['network_id'] !== '') {
        $query['network_id'] = trim((string) $input['network_id']);
        $networkId = $query['network_id'];
    }
}

$result = smobile_vtu_request('GET', '/v1/plans', null, $query);

// Normalize plan list for the Flutter app.
// SMobile returns `plans` as a network-keyed object: {"1":[...], "2":[...]}
// and usually also a flat `plan_list`. Ensure both shapes are usable.
$body = is_array($result['body'] ?? null) ? $result['body'] : [];
$httpCode = (int) ($result['http_code'] ?? 0);
$flat = smobile_vtu_flatten_plans($body, $network !== '' ? $network : null, $networkId !== '' ? $networkId : null);
$body['plan_list'] = $flat;
$body['plans'] = $flat;
if (!array_key_exists('success', $body)) {
    $body['success'] = $httpCode >= 200 && $httpCode < 400;
}
if (!isset($body['response_code'])) {
    $body['response_code'] = $httpCode > 0 ? $httpCode : 200;
}
$result['body'] = $body;

smobile_vtu_respond($result);
