<?php
require_once 'config.php';
require_once 'schema.php';
require_once 'mysqli_helpers.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/user_visibility_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}

$sessionUser = ec_require_session($mysqli);

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? 'list';

function normalize_funded_by_value(?string $value): string
{
    $text = trim((string) $value);
    if ($text === '' || $text === '0' || $text === '0.0') {
        return '';
    }
    return $text;
}

function resolve_funded_by_name(mysqli $mysqli, array $data): string
{
    $fundedBy = normalize_funded_by_value(
        (string) ($data['funded_by'] ?? $data['served_by'] ?? '')
    );
    $adminUserId = isset($data['admin_user_id']) ? (int) $data['admin_user_id'] : 0;
    if ($fundedBy !== '') {
        return $fundedBy;
    }
    if ($adminUserId > 0) {
        $adminStmt = $mysqli->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        if ($adminStmt) {
            $adminStmt->bind_param('i', $adminUserId);
            $adminStmt->execute();
            $adminRow = mysqli_stmt_fetch_assoc($adminStmt);
            $adminStmt->close();
            if ($adminRow && trim((string) $adminRow['full_name']) !== '') {
                return trim((string) $adminRow['full_name']);
            }
        }
    }
    return 'Admin';
}

function display_funded_by_from_row(array $row): string
{
    $direct = normalize_funded_by_value($row['funded_by'] ?? '');
    if ($direct !== '') {
        return $direct;
    }
    $fromUser = normalize_funded_by_value($row['funded_by_resolved'] ?? '');
    if ($fromUser !== '') {
        return $fromUser;
    }
    return '';
}

function normalize_funding_status(?string $value): string
{
    $text = trim((string) $value);
    if ($text === '' || strcasecmp($text, 'Funded') === 0) {
        return 'Active';
    }
    return $text;
}

const WALLET_FUNDING_SELECT = 'SELECT h.receipt_id, h.wallet_user_id, h.user_name, h.wallet_product, h.wallet_name,
        h.amount, h.funded_by, h.funded_by_user_id, h.status, h.funded_at,
        u.full_name AS funded_by_resolved,
        COALESCE(u.role, 0) AS funded_by_role,
        COALESCE(wu.is_external, 0) AS wallet_is_external,
        COALESCE(wu.wallet_id, \'\') AS wallet_wallet_id,
        COALESCE(wu.role, 0) AS wallet_user_role
        FROM wallet_funding_history h
        LEFT JOIN users u ON u.id = h.funded_by_user_id
        LEFT JOIN users wu ON wu.id = h.wallet_user_id';

function funding_row_to_array(array $row, int $viewerRole = 3): array
{
    $fundedBy = display_funded_by_from_row($row);
    // Admin must not learn Super Admin identity via funding history.
    if ($viewerRole === 2 && (int) ($row['funded_by_role'] ?? 0) >= 3) {
        $fundedBy = 'Administrator';
    }

    $customerName = (string) ($row['user_name'] ?? '');
    if ($viewerRole < 3 && (int) ($row['wallet_is_external'] ?? 0) === 1) {
        $wid = trim((string) ($row['wallet_wallet_id'] ?? ''));
        $customerName = user_external_display_label($customerName, $wid);
    }

    return [
        'receipt_id' => $row['receipt_id'],
        'wallet_user_id' => (int) $row['wallet_user_id'],
        'customer_name' => $customerName,
        'product' => $row['wallet_name'],
        'wallet_product' => $row['wallet_product'],
        'qty' => 1,
        'total' => (float) $row['amount'],
        'status' => normalize_funding_status($row['status'] ?? ''),
        'funded_by' => $fundedBy,
        'served_by' => $fundedBy,
        'transaction_at' => $row['funded_at'],
    ];
}

if ($action === 'list') {
    $sessionId = (int) $sessionUser['id'];
    $sessionRole = (int) $sessionUser['role'];
    $walletUserId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
    $admin = !empty($data['admin']);

    if ($admin) {
        if ($sessionRole < 2) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin access required']);
            exit;
        }
        if ($sessionRole === 2) {
            // Internals always; own external Wallet-ID fundings (redacted in row mapper).
            $sql = WALLET_FUNDING_SELECT .
                ' WHERE COALESCE(wu.role, 0) < 3
                    AND (
                      COALESCE(wu.is_external, 0) = 0
                      OR h.funded_by_user_id = ' . (int) $sessionId . '
                    )
                  ORDER BY h.funded_at DESC';
        } else {
            $sql = WALLET_FUNDING_SELECT . ' ORDER BY h.funded_at DESC';
        }
        $result = $mysqli->query($sql);
    } else {
        if ($walletUserId <= 0) {
            $walletUserId = $sessionId;
        }
        if ($walletUserId !== $sessionId && $sessionRole < 2) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only view your own funding history']);
            exit;
        }
        if ($walletUserId !== $sessionId && $sessionRole === 2) {
            $targetStmt = $mysqli->prepare(
                'SELECT role, COALESCE(is_external, 0) AS is_external FROM users WHERE id = ? LIMIT 1'
            );
            if (!$targetStmt) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not verify target user']);
                exit;
            }
            $targetStmt->bind_param('i', $walletUserId);
            $targetStmt->execute();
            $targetRow = $targetStmt->get_result()->fetch_assoc();
            $targetStmt->close();
            if (!$targetRow || (int) ($targetRow['role'] ?? 99) >= 3 || (int) ($targetRow['is_external'] ?? 0) === 1) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit;
            }
        }
        $stmt = $mysqli->prepare(
            WALLET_FUNDING_SELECT . ' WHERE h.wallet_user_id = ? ORDER BY h.funded_at DESC'
        );
        $stmt->bind_param('i', $walletUserId);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    $records = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $records[] = funding_row_to_array($row, $sessionRole);
        }
    }
    if (isset($stmt)) {
        $stmt->close();
    }

    echo json_encode(['success' => true, 'funding' => $records]);
    $mysqli->close();
    exit;
}

if ($action === 'create') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Client funding history create is disabled. Use users_wallet fund, which writes history atomically.',
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'repair_funded_by') {
    if ((int) $sessionUser['role'] < 3) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
    // Fill blank display names only — do not claim ownership as the current Super Admin.
    $placeholder = 'Unknown';
    $fix = $mysqli->prepare(
        "UPDATE wallet_funding_history
         SET funded_by = ?
         WHERE funded_by = '' OR funded_by = '0' OR funded_by = '0.0'"
    );
    if (!$fix) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Repair failed']);
        exit;
    }
    $fix->bind_param('s', $placeholder);
    $fix->execute();
    $updated = $fix->affected_rows;
    $fix->close();
    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'message' => "Repaired $updated blank funded_by name(s) to Unknown",
    ]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
