<?php
/**
 * Proxy GET /v1/transaction/{reference} and auto-settle local wallet holds
 * when the provider reaches a final status (so processing resolves without
 * waiting only on webhook / manual checks).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/smobile_vtu_client.php';
require_once __DIR__ . '/wallet_pool.php';
require_once __DIR__ . '/session_auth.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode([
        'response_code' => 500,
        'success' => false,
        'message' => 'Database setup failed: ' . $schemaError,
    ]);
    exit;
}

$sessionUser = ec_require_session($mysqli);

$reference = trim((string) ($_GET['reference'] ?? ''));
if ($reference === '') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (is_array($data)) {
        $reference = trim((string) ($data['reference'] ?? ''));
    }
}

if ($reference === '') {
    http_response_code(422);
    echo json_encode([
        'response_code' => 422,
        'success' => false,
        'message' => 'reference is required',
    ]);
    exit;
}

$path = '/v1/transaction/' . rawurlencode($reference);
$result = smobile_vtu_request('GET', $path);
$body = is_array($result['body'] ?? null) ? $result['body'] : [];

$info = smobile_vtu_status_info($body, (int) ($result['http_code'] ?? 0), true);
$status = $info['class'];
// Surface normalized status so the app mirrors SMobile classification.
$body['status'] = $status;
$body['success'] = $info['success'];

$holdNote = null;
if ($info['success']) {
    $holdNote = vtu_hold_complete($mysqli, $reference) ? 'hold_completed' : 'hold_complete_skipped';
} elseif ($info['failed']) {
    $holdNote = vtu_hold_refund($mysqli, $reference) ? 'hold_refunded' : 'hold_refund_skipped';
} elseif (str_starts_with($reference, 'PEND-') && $info['raw'] === '') {
    // Provisional refs never exist at the provider — settle via TTL / local state.
    $holdStmt = $mysqli->prepare(
        'SELECT id, reference, receipt_id, user_id, wallet_product, amount, face_amount,
                product_label, phone, served_by, status, client_request_id, created_at
         FROM vtu_wallet_holds WHERE reference = ? LIMIT 1'
    );
    if ($holdStmt) {
        $holdStmt->bind_param('s', $reference);
        $holdStmt->execute();
        $pendHold = $holdStmt->get_result()->fetch_assoc();
        $holdStmt->close();
        if ($pendHold) {
            $settle = vtu_hold_try_settle($mysqli, $pendHold);
            $holdNote = 'pend_' . ($settle['outcome'] ?? 'still_held');
            if (($settle['outcome'] ?? '') === 'refunded') {
                $status = 'failed';
                $body['status'] = 'failed';
                $body['success'] = false;
                $body['message'] = (string) ($settle['message'] ?? 'Purchase timed out; wallet refunded');
                $body['local_wallet_refunded'] = true;
            }
        }
    }
}

$body['hold'] = $holdNote;
$body['session_user_id'] = (int) $sessionUser['id'];

// After settle (or if already settled), surface Ebube charge + commission for the UI.
if ($status === 'success') {
    $holdStmt = $mysqli->prepare(
        'SELECT amount, face_amount FROM vtu_wallet_holds WHERE reference = ? LIMIT 1'
    );
    if ($holdStmt) {
        $holdStmt->bind_param('s', $reference);
        $holdStmt->execute();
        $holdRow = $holdStmt->get_result()->fetch_assoc();
        $holdStmt->close();
        if ($holdRow) {
            $walletCharged = (float) ($holdRow['amount'] ?? 0);
            $face = isset($holdRow['face_amount']) && (float) $holdRow['face_amount'] > 0
                ? (float) $holdRow['face_amount']
                : $walletCharged;
            $body['amount_charged'] = $walletCharged;
            $body['face_value'] = $face;
            if ($face > $walletCharged) {
                $body['discount'] = round($face - $walletCharged, 2);
            }
        }
    }
    $creditStmt = $mysqli->prepare(
        'SELECT commission_amount FROM vtu_commission_credits WHERE reference = ? LIMIT 1'
    );
    if ($creditStmt) {
        $creditStmt->bind_param('s', $reference);
        $creditStmt->execute();
        $creditRow = $creditStmt->get_result()->fetch_assoc();
        $creditStmt->close();
        if ($creditRow) {
            $earned = (float) $creditRow['commission_amount'];
            $body['commission_earned'] = $earned;
            $body['commission_credited'] = $earned > 0;
        }
    }
}

$updated = fetch_user_role_row($mysqli, (int) $sessionUser['id']);
if ($updated) {
    $body['momo_balance'] = (float) ($updated['momo_balance'] ?? 0);
    $body['vtu_balance'] = (float) ($updated['vtu_balance'] ?? 0);
    $body['logical_balance'] = (float) ($updated['logical_balance'] ?? 0);
    $body['commission_balance'] = (float) ($updated['commission_balance'] ?? 0);
}

$result['body'] = $body;
smobile_vtu_respond($result);
$mysqli->close();
