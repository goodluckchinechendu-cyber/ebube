<?php
require_once 'config.php';
require_once 'schema.php';
require_once 'auth_util.php';
require_once __DIR__ . '/session_auth.php';

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
$action = $data['action'] ?? 'request';

function withdrawal_row_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'agent_name' => (string) ($row['agent_name'] ?? ''),
        'agent_phone' => (string) ($row['agent_phone'] ?? ''),
        'amount' => (float) $row['amount'],
        'account_name' => (string) ($row['account_name'] ?? ''),
        'bank_name' => (string) ($row['bank_name'] ?? ''),
        'account_number' => (string) ($row['account_number'] ?? ''),
        'status' => (string) $row['status'],
        'created_at' => $row['created_at'],
        'approved_at' => $row['approved_at'] ?? null,
        'credit_hours' => isset($row['credit_hours']) && $row['credit_hours'] !== null
            ? (int) $row['credit_hours']
            : null,
        'expected_credit_at' => $row['expected_credit_at'] ?? null,
    ];
}

if ($action === 'request') {
    $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
    $amount = isset($data['amount']) ? (float) $data['amount'] : 0;

    if ($userId <= 0 || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id and amount required']);
        exit;
    }

    if ($userId !== $sessionId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'user_id must match authenticated session']);
        exit;
    }

    $stmt = $mysqli->prepare(
        'SELECT commission_balance, account_name, bank_name, account_number
         FROM users WHERE id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $accountName = trim((string) $user['account_name']);
    $bankName = trim((string) $user['bank_name']);
    $accountNumber = preg_replace('/\D/', '', (string) $user['account_number']);

    if ($accountName === '' && !empty($data['account_name'])) {
        $accountName = trim((string) $data['account_name']);
    }
    if ($bankName === '' && !empty($data['bank_name'])) {
        $bankName = trim((string) $data['bank_name']);
    }
    if ($accountNumber === '' && !empty($data['account_number'])) {
        $accountNumber = preg_replace('/\D/', '', (string) $data['account_number']);
    }

    $payoutError = validate_payout_details($accountName, $bankName, $accountNumber);
    if ($payoutError !== null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $payoutError,
        ]);
        exit;
    }

    if (empty($user['account_name']) || empty($user['bank_name']) || empty($user['account_number'])) {
        $upStmt = $mysqli->prepare('UPDATE users SET account_name = ?, bank_name = ?, account_number = ? WHERE id = ?');
        $upStmt->bind_param('sssi', $accountName, $bankName, $accountNumber, $userId);
        $upStmt->execute();
        $upStmt->close();
    }

    $mysqli->begin_transaction();
    try {
        // Lock the row, then debit conditionally so concurrent requests cannot overdraw.
        $lockStmt = $mysqli->prepare(
            'SELECT commission_balance FROM users WHERE id = ? FOR UPDATE'
        );
        if (!$lockStmt) {
            throw new RuntimeException('Could not lock commission balance');
        }
        $lockStmt->bind_param('i', $userId);
        $lockStmt->execute();
        $locked = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if (!$locked) {
            throw new RuntimeException('User not found');
        }

        $available = (float) $locked['commission_balance'];
        if ($amount > $available + 0.00001) {
            $mysqli->rollback();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Amount exceeds available commission']);
            $mysqli->close();
            exit;
        }

        $stmt = $mysqli->prepare(
            'UPDATE users
             SET commission_balance = commission_balance - ?
             WHERE id = ? AND commission_balance >= ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare commission debit');
        }
        $stmt->bind_param('did', $amount, $userId, $amount);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            $mysqli->rollback();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Amount exceeds available commission']);
            $mysqli->close();
            exit;
        }
        $stmt->close();

        $newBalStmt = $mysqli->prepare('SELECT commission_balance FROM users WHERE id = ?');
        $newBalStmt->bind_param('i', $userId);
        $newBalStmt->execute();
        $newBalance = (float) ($newBalStmt->get_result()->fetch_assoc()['commission_balance'] ?? 0);
        $newBalStmt->close();

        $status = 'pending';
        $stmt = $mysqli->prepare(
            'INSERT INTO withdrawal_requests
            (user_id, amount, account_name, bank_name, account_number, status)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'idssss',
            $userId,
            $amount,
            $accountName,
            $bankName,
            $accountNumber,
            $status
        );
        $stmt->execute();
        $requestId = (int) $mysqli->insert_id;
        $stmt->close();

        $mysqli->commit();
        echo json_encode([
            'success' => true,
            'request_id' => $requestId,
            'commission_balance' => $newBalance,
            'message' => 'Your request is under review for approval.',
        ]);
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Request failed']);
    }
    $mysqli->close();
    exit;
}

if ($action === 'list') {
    $userId = isset($data['user_id']) ? (int) $data['user_id'] : $sessionId;
    $admin = $sessionRole >= 2;

    $sql = "SELECT wr.id, wr.user_id, wr.amount, wr.account_name, wr.bank_name, wr.account_number,
            wr.status, wr.created_at, wr.approved_at, wr.credit_hours, wr.expected_credit_at,
            u.full_name AS agent_name, u.phone AS agent_phone
            FROM withdrawal_requests wr
            INNER JOIN users u ON u.id = wr.user_id";

    if ($admin) {
        if ($sessionRole === 2) {
            $sql .= ' WHERE COALESCE(u.is_external, 0) = 0 AND u.role < 3';
        }
        $sql .= ' ORDER BY CASE wr.status WHEN \'pending\' THEN 0 ELSE 1 END, wr.created_at DESC';
        $stmt = $mysqli->prepare($sql);
    } else {
        if ($userId !== $sessionId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Cannot list another user withdrawals']);
            exit;
        }
        $sql .= ' WHERE wr.user_id = ? ORDER BY wr.created_at DESC';
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $sessionId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = withdrawal_row_to_array($row);
    }
    $stmt->close();

    echo json_encode(['success' => true, 'requests' => $requests]);
    $mysqli->close();
    exit;
}

if ($action === 'approve') {
    if ($sessionRole < 2) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only an administrator can approve withdrawals']);
        exit;
    }

    $requestId = isset($data['request_id']) ? (int) $data['request_id'] : 0;
    $creditHours = isset($data['credit_hours']) ? (int) $data['credit_hours'] : 24;

    if ($requestId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'request_id required']);
        exit;
    }

    $allowedHours = [1, 2, 6, 12, 24, 48, 72];
    if (!in_array($creditHours, $allowedHours, true)) {
        $creditHours = 24;
    }

    $stmt = $mysqli->prepare(
        "SELECT wr.id, wr.status, wr.user_id,
                COALESCE(u.is_external, 0) AS is_external,
                COALESCE(u.role, 0) AS user_role
         FROM withdrawal_requests wr
         LEFT JOIN users u ON u.id = wr.user_id
         WHERE wr.id = ?"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }

    if ($sessionRole === 2 && (
        (int) ($request['is_external'] ?? 0) === 1 || (int) ($request['user_role'] ?? 0) >= 3
    )) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }

    if ($request['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Request is not pending']);
        exit;
    }

    $stmt = $mysqli->prepare(
        "UPDATE withdrawal_requests
         SET status = 'approved',
             approved_at = NOW(),
             credit_hours = ?,
             expected_credit_at = DATE_ADD(NOW(), INTERVAL ? HOUR)
         WHERE id = ?"
    );
    $stmt->bind_param('iii', $creditHours, $creditHours, $requestId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Approval failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Withdrawal approved',
        'credit_hours' => $creditHours,
    ]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
