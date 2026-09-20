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
        }
    }
    if ($networkId === '' && isset($input['network_id']) && $input['network_id'] !== '') {
        $query['network_id'] = trim((string) $input['network_id']);
    }
}

$result = smobile_vtu_request('GET', '/v1/plans', null, $query);

// Normalize plan list key for the Flutter app.
$body = $result['body'];
if (is_array($body)) {
    if (!isset($body['plans']) && isset($body['plan_list']) && is_array($body['plan_list'])) {
        $body['plans'] = $body['plan_list'];
    } elseif (!isset($body['plan_list']) && isset($body['plans']) && is_array($body['plans'])) {
        $body['plan_list'] = $body['plans'];
    }
    $result['body'] = $body;
}

smobile_vtu_respond($result);
