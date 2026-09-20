<?php
require_once 'config.php';
require_once 'schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/wallet_pool.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) $sessionUser['role'];

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? 'list';

function transaction_row_to_array(array $row): array
{
    $phone = trim((string) ($row['phone'] ?? ''));
    $network = trim((string) ($row['network'] ?? ''));
    if ($network === '') {
        $network = 'MTN';
    }
    return [
        'receipt_id' => $row['receipt_id'],
        'wallet_user_id' => (int) $row['wallet_user_id'],
        'customer_id' => (int) $row['customer_id'],
        'customer_name' => $row['customer_name'],
        'phone' => $phone,
        'network' => $network,
        'product' => $row['product'],
        'qty' => (int) $row['qty'],
        'total' => (float) $row['total'],
        'status' => $row['status'],
        'served_by' => $row['served_by'],
        'transaction_at' => $row['transaction_at'],
    ];
}

function agent_transactions_select_sql(): string
{
    // Prefer settled hold status so "Processing" does not stick after complete/refund.
    return 'SELECT t.receipt_id, t.wallet_user_id, t.customer_id, t.customer_name,
                   COALESCE(NULLIF(t.phone, \'\'), h.phone, \'\') AS phone,
                   COALESCE(NULLIF(t.network, \'\'), \'MTN\') AS network,
                   t.product, t.qty, t.total,
                   CASE
                     WHEN LOWER(t.status) IN (\'processing\', \'pending\') AND h.status = \'completed\' THEN \'Completed\'
                     WHEN LOWER(t.status) IN (\'processing\', \'pending\') AND h.status = \'refunded\' THEN \'Failed\'
                     ELSE t.status
                   END AS status,
                   t.served_by, t.transaction_at
            FROM agent_transactions t
            LEFT JOIN vtu_wallet_holds h ON h.receipt_id = t.receipt_id';
}

/**
 * Persist hold→statement status sync for Processing rows (best-effort).
 */
function agent_transactions_sync_processing_from_holds(mysqli $mysqli): void
{
    $mysqli->query(
        "UPDATE agent_transactions t
         INNER JOIN vtu_wallet_holds h ON h.receipt_id = t.receipt_id
         SET t.status = CASE
             WHEN h.status = 'completed' THEN 'Completed'
             WHEN h.status = 'refunded' THEN 'Failed'
             ELSE t.status
         END
         WHERE t.status IN ('Processing', 'processing', 'Pending', 'pending')
           AND h.status IN ('completed', 'refunded')"
    );
}

/**
 * Soft-settle a few held rows for this user so Processing clears when they open the list.
 */
function agent_transactions_soft_settle_holds(mysqli $mysqli, int $userId, int $limit = 5): void
{
    if ($userId <= 0 || $limit <= 0) {
        return;
    }
    $limit = min(10, $limit);
    $stmt = $mysqli->prepare(
        "SELECT id, reference, receipt_id, user_id, wallet_product, amount, face_amount,
                product_label, phone, served_by, status, client_request_id, created_at
         FROM vtu_wallet_holds
         WHERE user_id = ? AND status = 'held'
         ORDER BY created_at ASC
         LIMIT {$limit}"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    foreach ($rows as $hold) {
        $result = vtu_hold_try_settle($mysqli, $hold);
        $outcome = (string) ($result['outcome'] ?? '');
        if ($outcome === 'completed' || $outcome === 'refunded') {
            $fresh = $hold;
            $fresh['status'] = $result['status'] ?? $outcome;
            vtu_sync_agent_transaction_from_hold($mysqli, $fresh);
        }
    }
}

if ($action === 'list') {
    $actorId = isset($data['actor_id']) ? (int) $data['actor_id'] : $sessionId;

    if ($actorId !== $sessionId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'actor_id must match authenticated session']);
        exit;
    }

    agent_transactions_soft_settle_holds($mysqli, $sessionId, 5);
    agent_transactions_sync_processing_from_holds($mysqli);
    vtu_purge_provisional_processing_orphans($mysqli);

    // Personal transactions only (own wallet).
    $ownId = $sessionId;
    $stmt = $mysqli->prepare(
        agent_transactions_select_sql() . ' WHERE t.wallet_user_id = ? ORDER BY t.transaction_at DESC'
    );
    $stmt->bind_param('i', $ownId);
    $stmt->execute();
    $result = $stmt->get_result();

    $transactions = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = transaction_row_to_array($row);
        }
    }
    $stmt->close();

    echo json_encode(['success' => true, 'transactions' => $transactions]);
    $mysqli->close();
    exit;
}

if ($action === 'list_all') {
    if ($sessionRole < 2) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    agent_transactions_sync_processing_from_holds($mysqli);
    vtu_purge_provisional_processing_orphans($mysqli);

    $filterUserId = isset($data['user_id']) ? (int) $data['user_id'] : 0;

    if ($sessionRole >= 3) {
        // Elevated admin: admin / agent / customer (hide own elevated peers if desired — show all non-self optional)
        if ($filterUserId > 0) {
            $stmt = $mysqli->prepare(
                agent_transactions_select_sql() .
                ' INNER JOIN users u ON u.id = t.wallet_user_id
                  WHERE u.role < 3 AND t.wallet_user_id = ?
                  ORDER BY t.transaction_at DESC'
            );
            $stmt->bind_param('i', $filterUserId);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $sql = agent_transactions_select_sql() .
                ' INNER JOIN users u ON u.id = t.wallet_user_id
                  WHERE u.role < 3
                  ORDER BY t.transaction_at DESC';
            $result = $mysqli->query($sql);
        }
    } else {
        // Admin: other admins, agents, customers — never elevated or external accounts.
        if ($filterUserId > 0) {
            $stmt = $mysqli->prepare(
                agent_transactions_select_sql() .
                ' INNER JOIN users u ON u.id = t.wallet_user_id
                  WHERE u.role < 3 AND COALESCE(u.is_external, 0) = 0 AND t.wallet_user_id = ?
                  ORDER BY t.transaction_at DESC'
            );
            $stmt->bind_param('i', $filterUserId);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $sql = agent_transactions_select_sql() .
                ' INNER JOIN users u ON u.id = t.wallet_user_id
                  WHERE u.role < 3 AND COALESCE(u.is_external, 0) = 0
                  ORDER BY t.transaction_at DESC';
            $result = $mysqli->query($sql);
        }
    }

    $transactions = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = transaction_row_to_array($row);
        }
    }
    if (isset($stmt)) {
        $stmt->close();
    }

    if (!isset($result) || $result === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not load transactions']);
        $mysqli->close();
        exit;
    }

    echo json_encode(['success' => true, 'transactions' => $transactions]);
    $mysqli->close();
    exit;
}

if ($action === 'create') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Client create disabled; purchases write transactions server-side']);
    $mysqli->close();
    exit;
}

if ($action === 'delete') {
    if ($sessionRole < 3) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only an administrator can delete transactions']);
        exit;
    }
    $receiptId = trim((string) ($data['receipt_id'] ?? ''));
    if ($receiptId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'receipt_id required']);
        exit;
    }

    $stmt = $mysqli->prepare('DELETE FROM agent_transactions WHERE receipt_id = ?');
    $stmt->bind_param('s', $receiptId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Transaction deleted']);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
