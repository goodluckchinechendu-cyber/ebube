<?php
/**
 * Peer wallet transfer between hierarchy-linked accounts.
 * Actions: recipients | transfer | history
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/wallet_pool.php';
require_once __DIR__ . '/referral_util.php';
require_once __DIR__ . '/user_visibility_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}
ensure_user_visibility_columns($mysqli);

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) $sessionUser['role'];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$action = strtolower(trim((string) ($data['action'] ?? $_GET['action'] ?? 'recipients')));

$walletLabels = [
    'momo' => 'MoMo Airtime',
    'vtu' => 'VTU Airtime',
    'logical' => 'Logical Airtime',
];

function wt_fetch_user(mysqli $mysqli, int $id): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT id, full_name, email, phone, role,
                COALESCE(registered_by, 0) AS registered_by,
                COALESCE(is_external, 0) AS is_external,
                COALESCE(momo_balance, 0) AS momo_balance,
                COALESCE(vtu_balance, 0) AS vtu_balance,
                COALESCE(logical_balance, 0) AS logical_balance
         FROM users WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function wt_row_to_api(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'reference' => (string) ($row['reference'] ?? ''),
        'from_user_id' => (int) ($row['from_user_id'] ?? 0),
        'to_user_id' => (int) ($row['to_user_id'] ?? 0),
        'from_user_name' => (string) ($row['from_user_name'] ?? ''),
        'to_user_name' => (string) ($row['to_user_name'] ?? ''),
        'wallet_product' => (string) ($row['wallet_product'] ?? 'vtu'),
        'wallet_name' => (string) ($row['wallet_name'] ?? ''),
        'amount' => (float) ($row['amount'] ?? 0),
        'status' => (string) ($row['status'] ?? 'completed'),
        'note' => (string) ($row['note'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'direction' => (string) ($row['direction'] ?? ''),
    ];
}

if ($action === 'recipients') {
    $q = trim((string) ($data['q'] ?? $_GET['q'] ?? ''));
    $recipients = [];

    if ($q !== '' && mb_strlen($q) >= 1) {
        $like = '%' . $mysqli->real_escape_string($q) . '%';
        // Scope by role to keep lists small.
                    if ($sessionRole >= 3) {
            // Super Admin may find anyone (incl. externals by wallet_id).
            $sql = "SELECT id, full_name, email, phone, role, COALESCE(registered_by, 0) AS registered_by,
                           COALESCE(is_external, 0) AS is_external,
                           COALESCE(wallet_id, '') AS wallet_id,
                           COALESCE(vtu_balance,0) AS vtu_balance,
                           COALESCE(momo_balance,0) AS momo_balance,
                           COALESCE(logical_balance,0) AS logical_balance
                    FROM users
                    WHERE id <> ? AND role < 3
                      AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR wallet_id LIKE ?)
                    ORDER BY full_name ASC LIMIT 80";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('issss', $sessionId, $like, $like, $like, $like);
        } elseif ($sessionRole === 2) {
            // Admin: never externals — they fund those via Wallet ID on Fund Wallet only.
            $sql = "SELECT id, full_name, email, phone, role, COALESCE(registered_by, 0) AS registered_by,
                           0 AS is_external, '' AS wallet_id,
                           COALESCE(vtu_balance,0) AS vtu_balance,
                           COALESCE(momo_balance,0) AS momo_balance,
                           COALESCE(logical_balance,0) AS logical_balance
                    FROM users
                    WHERE id <> ? AND role < 3
                      AND COALESCE(is_external, 0) = 0
                      AND (role = 2 OR registered_by = ?)
                      AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)
                    ORDER BY full_name ASC LIMIT 80";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('iisss', $sessionId, $sessionId, $like, $like, $like);
        } else {
            $sql = "SELECT id, full_name, email, phone, role, COALESCE(registered_by, 0) AS registered_by,
                           0 AS is_external, '' AS wallet_id,
                           COALESCE(vtu_balance,0) AS vtu_balance,
                           COALESCE(momo_balance,0) AS momo_balance,
                           COALESCE(logical_balance,0) AS logical_balance
                    FROM users
                    WHERE id <> ? AND registered_by = ?
                      AND COALESCE(is_external, 0) = 0
                      AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)
                    ORDER BY full_name ASC LIMIT 80";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('iisss', $sessionId, $sessionId, $like, $like, $like);
        }

        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $from = wt_fetch_user($mysqli, $sessionId) ?? $sessionUser;
            $from['id'] = $sessionId;
            $from['role'] = $sessionRole;
            while ($row = $res->fetch_assoc()) {
                if (!user_can_wallet_transfer_to($from, $row)) {
                    continue;
                }
                $role = (int) $row['role'];
                $roleLabel = $role === 2 ? 'Admin' : ($role === 1 ? 'Agent' : 'Customer');
                $isExt = (int) ($row['is_external'] ?? 0) === 1;
                $entry = [
                    'id' => (int) $row['id'],
                    'full_name' => (string) $row['full_name'],
                    'role' => $role,
                    'role_label' => $roleLabel,
                    'vtu' => (float) $row['vtu_balance'],
                    'momo' => (float) $row['momo_balance'],
                    'logical' => (float) $row['logical_balance'],
                ];
                // Externals (SA-only search hits): expose name + wallet id, not email/phone.
                if ($isExt && $sessionRole >= 3) {
                    $entry['email'] = '';
                    $entry['phone'] = '';
                    $entry['wallet_id'] = (string) ($row['wallet_id'] ?? '');
                    $entry['is_external'] = true;
                } else {
                    $entry['email'] = (string) $row['email'];
                    $entry['phone'] = (string) $row['phone'];
                }
                $recipients[] = $entry;
            }
            $stmt->close();
        }
    }

    echo json_encode(['success' => true, 'recipients' => $recipients]);
    $mysqli->close();
    exit;
}

if ($action === 'transfer') {
    $toUserId = (int) ($data['to_user_id'] ?? 0);
    $amount = (float) ($data['amount'] ?? 0);
    $productId = strtolower(trim((string) ($data['wallet_product'] ?? $data['product'] ?? 'vtu')));
    $note = trim((string) ($data['note'] ?? ''));
    $clientRequestId = trim((string) ($data['client_request_id'] ?? ''));

    if (!isset($walletLabels[$productId])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid wallet type']);
        exit;
    }
    if ($toUserId <= 0 || $toUserId === $sessionId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Select a valid recipient']);
        exit;
    }
    if ($amount < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Amount must be at least ₦1']);
        exit;
    }

    $from = wt_fetch_user($mysqli, $sessionId);
    $to = wt_fetch_user($mysqli, $toUserId);
    if (!$from || !$to) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    if ($sessionRole < 3 && (int) ($to['is_external'] ?? 0) === 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    if (!user_can_wallet_transfer_to($from, $to)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You cannot transfer to this account']);
        exit;
    }

    if ($clientRequestId !== '') {
        $dup = $mysqli->prepare(
            'SELECT reference FROM wallet_transfers WHERE reference = ? LIMIT 1'
        );
        $refKey = 'WTREQ-' . substr(hash('sha256', $sessionId . ':' . $clientRequestId), 0, 24);
        if ($dup) {
            $dup->bind_param('s', $refKey);
            $dup->execute();
            $existing = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($existing) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Transfer already completed',
                    'reference' => $existing['reference'],
                    'idempotent' => true,
                ]);
                $mysqli->close();
                exit;
            }
        }
        $reference = $refKey;
    } else {
        $reference = 'WT-' . $sessionId . '-' . bin2hex(random_bytes(6));
    }

    if (!$mysqli->begin_transaction()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not start transfer']);
        exit;
    }

    try {
        if (!wallet_debit($mysqli, $sessionId, $productId, $amount)) {
            throw new RuntimeException('Insufficient balance for this wallet');
        }
        if (!wallet_credit($mysqli, $toUserId, $productId, $amount)) {
            throw new RuntimeException('Could not credit recipient');
        }
        $walletName = $walletLabels[$productId];
        $status = 'completed';
        $ins = $mysqli->prepare(
            'INSERT INTO wallet_transfers
             (reference, from_user_id, to_user_id, wallet_product, amount, status, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('Could not record transfer');
        }
        $ins->bind_param(
            'siisdss',
            $reference,
            $sessionId,
            $toUserId,
            $productId,
            $amount,
            $status,
            $note
        );
        if (!$ins->execute()) {
            // Duplicate reference = concurrent retry of same client_request_id.
            if ($ins->errno === 1062) {
                $ins->close();
                $mysqli->rollback();
                echo json_encode([
                    'success' => true,
                    'message' => 'Transfer already completed',
                    'reference' => $reference,
                    'idempotent' => true,
                ]);
                $mysqli->close();
                exit;
            }
            throw new RuntimeException('Transfer record failed: ' . $ins->error);
        }
        $ins->close();
        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        $mysqli->close();
        exit;
    }

    $fromAfter = wt_fetch_user($mysqli, $sessionId);
    echo json_encode([
        'success' => true,
        'message' => 'Transfer completed',
        'reference' => $reference,
        'amount' => $amount,
        'wallet_product' => $productId,
        'to_user' => [
            'id' => $toUserId,
            'full_name' => $to['full_name'],
        ],
        'balances' => [
            'vtu' => (float) ($fromAfter['vtu_balance'] ?? 0),
            'momo' => (float) ($fromAfter['momo_balance'] ?? 0),
            'logical' => (float) ($fromAfter['logical_balance'] ?? 0),
        ],
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'history') {
    $page = max(1, (int) ($data['page'] ?? $_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int) ($data['per_page'] ?? $_GET['per_page'] ?? 30)));
    $offset = ($page - 1) * $perPage;
    $q = trim((string) ($data['q'] ?? $_GET['q'] ?? ''));
    $scope = strtolower(trim((string) ($data['scope'] ?? $_GET['scope'] ?? 'mine')));

    $where = '';
    $params = [];
    $types = '';

    if ($scope === 'all' && $sessionRole >= 2) {
        // Admin+: all transfers not involving Super Admin accounts as parties when Admin.
        // Also hide any transfer involving an external account from Admin view.
        if ($sessionRole === 2) {
            $where = ' WHERE fu.role < 3 AND tu.role < 3
                       AND COALESCE(fu.is_external, 0) = 0
                       AND COALESCE(tu.is_external, 0) = 0';
        } else {
            $where = '';
        }
    } else {
        $where = ' WHERE (t.from_user_id = ? OR t.to_user_id = ?)';
        $params[] = $sessionId;
        $params[] = $sessionId;
        $types .= 'ii';
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where .= ($where === '' ? ' WHERE' : ' AND') .
            ' (fu.full_name LIKE ? OR tu.full_name LIKE ? OR t.reference LIKE ? OR t.status LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    $fetchLimit = $perPage + 1;
    $sql = "SELECT t.*,
                   fu.full_name AS from_user_name,
                   tu.full_name AS to_user_name,
                   COALESCE(fu.is_external, 0) AS from_is_external,
                   COALESCE(tu.is_external, 0) AS to_is_external,
                   COALESCE(fu.wallet_id, '') AS from_wallet_id,
                   COALESCE(tu.wallet_id, '') AS to_wallet_id,
                   COALESCE(fu.role, 0) AS from_role,
                   COALESCE(tu.role, 0) AS to_role
            FROM wallet_transfers t
            LEFT JOIN users fu ON fu.id = t.from_user_id
            LEFT JOIN users tu ON tu.id = t.to_user_id
            {$where}
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT {$fetchLimit} OFFSET {$offset}";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not load transfers']);
        exit;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $i => $v) {
            $bind[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    $hasMore = count($rows) > $perPage;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $perPage);
    }

    $transfers = [];
    foreach ($rows as $row) {
        $fromId = (int) $row['from_user_id'];
        $direction = $fromId === $sessionId ? 'out' : 'in';
        if ($scope === 'all' && $sessionRole >= 2) {
            $direction = 'transfer';
        }
        // Admin / non-SA: show name + Wallet ID for external parties (no visibility tag).
        if ($sessionRole < 3) {
            if ((int) ($row['from_is_external'] ?? 0) === 1) {
                $row['from_user_name'] = user_external_display_label(
                    (string) ($row['from_user_name'] ?? ''),
                    (string) ($row['from_wallet_id'] ?? '')
                );
            }
            if ((int) ($row['to_is_external'] ?? 0) === 1) {
                $row['to_user_name'] = user_external_display_label(
                    (string) ($row['to_user_name'] ?? ''),
                    (string) ($row['to_wallet_id'] ?? '')
                );
            }
        }
        $product = (string) $row['wallet_product'];
        $row['wallet_name'] = $walletLabels[$product] ?? $product;
        $row['direction'] = $direction;
        $transfers[] = wt_row_to_api($row);
    }

    echo json_encode([
        'success' => true,
        'transfers' => $transfers,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ],
    ]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
