<?php
/**
 * Reconcile stuck VTU wallet holds (Processing forever).
 *
 * Safe to run via CLI or HTTP cron every 1–5 minutes:
 *   php cron_vtu_hold_reconcile.php
 *   curl -s 'https://…/backend/cron_vtu_hold_reconcile.php?key=…'
 *
 * Optional: set EC_CRON_KEY in private/.env; when set, HTTP calls must pass ?key=.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/wallet_pool.php';
require_once __DIR__ . '/smobile_vtu_client.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/env_loader.php';
    $cronKey = trim((string) (ec_env('EC_CRON_KEY', '') ?? ''));
    $given = trim((string) ($_GET['key'] ?? ''));
    if ($cronKey !== '' && !hash_equals($cronKey, $given)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    header('Content-Type: application/json');
}

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    $payload = ['success' => false, 'message' => 'Database setup failed: ' . $schemaError];
    if ($isCli) {
        fwrite(STDERR, $payload['message'] . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode($payload);
    exit;
}

$limit = 40;
if ($isCli && isset($argv[1]) && ctype_digit((string) $argv[1])) {
    $limit = max(1, min(100, (int) $argv[1]));
} elseif (!$isCli && isset($_GET['limit']) && ctype_digit((string) $_GET['limit'])) {
    $limit = max(1, min(100, (int) $_GET['limit']));
}

$rows = [];
$sql = "SELECT id, reference, receipt_id, user_id, wallet_product, amount, face_amount,
               product_label, phone, served_by, status, client_request_id, created_at
        FROM vtu_wallet_holds
        WHERE status = 'held'
        ORDER BY created_at ASC
        LIMIT {$limit}";
$res = $mysqli->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
}

$stats = [
    'checked' => 0,
    'completed' => 0,
    'refunded' => 0,
    'still_held' => 0,
    'skipped' => 0,
    'synced_processing' => 0,
];

foreach ($rows as $hold) {
    $stats['checked']++;
    $result = vtu_hold_try_settle($mysqli, $hold);
    $outcome = (string) ($result['outcome'] ?? 'still_held');
    if (isset($stats[$outcome])) {
        $stats[$outcome]++;
    } else {
        $stats['still_held']++;
    }
    if ($outcome === 'completed' || $outcome === 'refunded') {
        $fresh = $hold;
        $fresh['status'] = $result['status'] ?? $outcome;
        vtu_sync_agent_transaction_from_hold($mysqli, $fresh);
    }
}

// Fix statement rows still showing Processing after hold already settled.
$syncSql = "UPDATE agent_transactions t
            INNER JOIN vtu_wallet_holds h ON h.receipt_id = t.receipt_id
            SET t.status = CASE
                WHEN h.status = 'completed' THEN 'Completed'
                WHEN h.status = 'refunded' THEN 'Failed'
                ELSE t.status
            END
            WHERE t.status IN ('Processing', 'processing', 'Pending', 'pending')
              AND h.status IN ('completed', 'refunded')";
if ($mysqli->query($syncSql)) {
    $stats['synced_processing'] = (int) $mysqli->affected_rows;
}

$stats['purged_orphans'] = vtu_purge_provisional_processing_orphans($mysqli);

$payload = [
    'success' => true,
    'message' => 'VTU hold reconcile finished',
    'stats' => $stats,
];

if ($isCli) {
    echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($payload);
}
$mysqli->close();
