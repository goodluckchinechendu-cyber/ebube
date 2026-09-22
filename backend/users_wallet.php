<?php
require_once 'config.php';
require_once 'schema.php';
require_once 'mysqli_helpers.php';
require_once __DIR__ . '/smobile_vtu_client.php';
require_once __DIR__ . '/wallet_pool.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/user_visibility_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}
ensure_user_visibility_columns($mysqli);

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? 'sync';

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) $sessionUser['role'];

if ($action === 'resolve_wallet') {
    // Admin / SA: look up an external wallet ID and return name + id for confirmation UI.
    if ($sessionRole < 2) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
    $wid = user_normalize_wallet_id((string) ($data['wallet_id'] ?? ''));
    if ($wid === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Wallet ID required']);
        exit;
    }
    $find = $mysqli->prepare(
        'SELECT id, full_name, role, COALESCE(is_external, 0) AS is_external,
                COALESCE(wallet_id, \'\') AS wallet_id
         FROM users WHERE UPPER(TRIM(wallet_id)) = UPPER(?) LIMIT 1'
    );
    if (!$find) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
    $find->bind_param('s', $wid);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if (
        !$row
        || (int) ($row['is_external'] ?? 0) !== 1
        || ($sessionRole === 2 && (int) ($row['role'] ?? 0) >= 3)
    ) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Wallet ID not found']);
        exit;
    }
    $fullName = (string) ($row['full_name'] ?? '');
    $walletId = trim((string) ($row['wallet_id'] ?? ''));
    echo json_encode([
        'success' => true,
        'full_name' => $fullName,
        'wallet_id' => $walletId,
        'display_name' => user_external_display_label($fullName, $walletId),
    ]);
    $mysqli->close();
    exit;
}

// Resolve target for fund: Admin must use wallet_id alone for externals.
$walletIdIn = user_normalize_wallet_id((string) ($data['wallet_id'] ?? ''));
$id = isset($data['id']) ? (int) $data['id'] : 0;
$fundedViaWalletId = false;

if ($action === 'fund' && $walletIdIn !== '') {
    // When a Wallet ID is supplied, always resolve by Wallet ID (ignore conflicting id).
    $find = $mysqli->prepare(
        'SELECT id, COALESCE(is_external, 0) AS is_external
         FROM users WHERE UPPER(TRIM(wallet_id)) = UPPER(?) LIMIT 1'
    );
    if ($find) {
        $find->bind_param('s', $walletIdIn);
        $find->execute();
        $found = $find->get_result()->fetch_assoc();
        $find->close();
        if ($found) {
            $id = (int) $found['id'];
            $fundedViaWalletId = true;
            // Wallet IDs only belong to external accounts.
            if ((int) ($found['is_external'] ?? 0) !== 1) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Wallet ID not found']);
                exit;
            }
        }
    }
    if (!$fundedViaWalletId || $id <= 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Wallet ID not found']);
        exit;
    }
}

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User id required']);
    exit;
}

function fetch_user_wallet(mysqli $mysqli, int $id): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT id, full_name, momo_balance, vtu_balance, logical_balance, data_bundle_balance, commission_balance,
                transaction_pin_hash, email_verified_at,
                COALESCE(is_external, 0) AS is_external,
                COALESCE(wallet_id, \'\') AS wallet_id
         FROM users WHERE id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function wallet_response(array $user): void
{
    echo json_encode([
        'success' => true,
        'message' => 'Wallet updated',
        'momo_balance' => (float) $user['momo_balance'],
        'vtu_balance' => (float) $user['vtu_balance'],
        'logical_balance' => (float) ($user['logical_balance'] ?? 0),
        'data_bundle_balance' => (float) $user['data_bundle_balance'],
        'commission_balance' => (float) $user['commission_balance'],
    ]);
}

if ($action === 'fund') {
    // Super Admin (3): inject new float against SMobile capacity (SMobile API unchanged).
    // Admin (2): transfer from their funded personal wallet to the recipient (SMobile unchanged).
    // Purchases (anywhere) are what reduce the SMobile wallet.
    if ($sessionRole < 2) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required to fund wallets']);
        exit;
    }

    $actorId = isset($data['actor_id']) ? (int) $data['actor_id'] : 0;
    if ($actorId !== $sessionId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'actor_id must match authenticated admin']);
        exit;
    }

    $actor = [
        'id' => $sessionId,
        'role' => $sessionRole,
        'full_name' => (string) ($sessionUser['full_name'] ?? 'Admin'),
    ];
    $isSuperAdmin = $sessionRole >= 3;

    $productId = (string) ($data['product_id'] ?? '');
    $amount = isset($data['amount']) ? (float) $data['amount'] : 0;

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'amount must be greater than zero']);
        exit;
    }

    if (!in_array($productId, ['momo', 'vtu', 'logical'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'product_id must be momo, vtu, or logical']);
        exit;
    }

    $targetStmt = $mysqli->prepare(
        'SELECT id, role, full_name, COALESCE(is_external, 0) AS is_external,
                COALESCE(wallet_id, \'\') AS wallet_id
         FROM users WHERE id = ? LIMIT 1'
    );
    if (!$targetStmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not verify target user']);
        exit;
    }
    $targetStmt->bind_param('i', $id);
    $targetStmt->execute();
    $targetRow = $targetStmt->get_result()->fetch_assoc();
    $targetStmt->close();
    if (!$targetRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    $targetRole = (int) ($targetRow['role'] ?? 0);
    $targetIsExternal = (int) ($targetRow['is_external'] ?? 0) === 1;
    $targetWalletId = trim((string) ($targetRow['wallet_id'] ?? ''));

    if ($sessionRole === 2) {
        // Admin may fund self, other Admins, Agents, and Customers — never Super Admins.
        if ($targetRole >= 3) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You cannot fund this account']);
            exit;
        }
        // External wallets: Admin may ONLY fund via a matching Wallet ID (never by numeric id).
        if ($targetIsExternal) {
            if (
                !$fundedViaWalletId
                || $walletIdIn === ''
                || strtoupper($walletIdIn) !== strtoupper($targetWalletId)
            ) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit;
            }
        } elseif ($fundedViaWalletId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Wallet ID not found']);
            exit;
        }
    }

    // Display label: Admin sees name + Wallet ID for externals (no Internal/External tag).
    $targetDisplayName = (string) ($targetRow['full_name'] ?? 'User');
    if ($sessionRole < 3 && $targetIsExternal) {
        $targetDisplayName = user_external_display_label($targetDisplayName, $targetWalletId);
    }

    // Admin funding anyone (self, other Admins, Agents, Customers) = transfer from
    // their Super-Admin-funded balance. Self-fund moves ₦0 net on the same product
    // only when debit+credit apply; for self we credit without debit only if…
    // Safer rule: Admin→self also transfers (net-zero same product). Prefer:
    // Admin→self = inject from master capacity so they can top up their trading wallet.
    $isAdminSelfInject = ($sessionRole === 2 && $id === $sessionId);
    $isTransfer = ($sessionRole === 2 && !$isAdminSelfInject);
    $needsPoolCapacity = $isSuperAdmin || $isAdminSelfInject;

    $pool = [
        'smobile_balance' => 0.0,
        'injected_total' => 0.0,
        'held_total' => 0.0,
        'available_to_inject' => 0.0,
    ];
    $receiptId = '';
    $fundedBy = '';
    $user = null;
    $funderAfter = null;

    // Idempotency: retries with the same client_request_id must not double-move money.
    $clientRequestId = trim((string) ($data['client_request_id'] ?? ''));
    if ($clientRequestId === '' || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $clientRequestId)) {
        $clientRequestId = 'srv-' . bin2hex(random_bytes(16));
    }
    // Ensure optional column exists (also in schema.php migration).
    $colCr = $mysqli->query("SHOW COLUMNS FROM `wallet_funding_history` LIKE 'client_request_id'");
    if ($colCr && $colCr->num_rows === 0) {
        $mysqli->query(
            "ALTER TABLE `wallet_funding_history`
             ADD COLUMN `client_request_id` VARCHAR(64) NULL DEFAULT NULL AFTER `receipt_id`,
             ADD UNIQUE KEY `uniq_funding_client_request` (`client_request_id`)"
        );
    }
    if ($colCr) {
        $colCr->free();
    }

    $prior = $mysqli->prepare(
        'SELECT receipt_id, wallet_user_id, wallet_product, amount, funded_by, status
         FROM wallet_funding_history
         WHERE client_request_id = ? AND amount > 0
         LIMIT 1'
    );
    if ($prior) {
        $prior->bind_param('s', $clientRequestId);
        $prior->execute();
        $priorRow = $prior->get_result()->fetch_assoc();
        $prior->close();
        if ($priorRow) {
            $targetUser = fetch_user_wallet($mysqli, (int) $priorRow['wallet_user_id']);
            $funderUser = fetch_user_wallet($mysqli, $sessionId);
            $snap = $isSuperAdmin ? wallet_pool_snapshot($mysqli) : null;
            $response = [
                'success' => true,
                'message' => 'Funding already completed',
                'idempotent_replay' => true,
                'mode' => $isTransfer ? 'transfer' : 'inject',
                'receipt_id' => (string) $priorRow['receipt_id'],
                'product_id' => (string) $priorRow['wallet_product'],
                'amount' => (float) $priorRow['amount'],
                'funded_by' => (string) $priorRow['funded_by'],
                'momo_balance' => (float) ($targetUser['momo_balance'] ?? 0),
                'vtu_balance' => (float) ($targetUser['vtu_balance'] ?? 0),
                'logical_balance' => (float) ($targetUser['logical_balance'] ?? 0),
                'data_bundle_balance' => (float) ($targetUser['data_bundle_balance'] ?? 0),
                'commission_balance' => (float) ($targetUser['commission_balance'] ?? 0),
            ];
            if ($isTransfer && $funderUser) {
                $col = wallet_column_for_product($productId);
                $response['funder_momo_balance'] = (float) $funderUser['momo_balance'];
                $response['funder_vtu_balance'] = (float) $funderUser['vtu_balance'];
                $response['funder_logical_balance'] = (float) ($funderUser['logical_balance'] ?? 0);
                $response['available_to_inject'] = $col ? (float) ($funderUser[$col] ?? 0) : 0.0;
                $response['show_smobile_pool'] = false;
            } else {
                $response['smobile_balance'] = (float) ($snap['smobile_balance'] ?? 0);
                $response['injected_total'] = (float) ($snap['injected_total'] ?? 0);
                $response['held_total'] = (float) ($snap['held_total'] ?? 0);
                $response['available_to_inject'] = (float) ($snap['available_to_inject'] ?? 0);
                $response['show_smobile_pool'] = true;
            }
            echo json_encode($response);
            $mysqli->close();
            exit;
        }
    }

    $lockName = $isTransfer ? 'ebube_wallet_transfer' : 'ebube_wallet_inject';
    $lock = $mysqli->query("SELECT GET_LOCK('" . $mysqli->real_escape_string($lockName) . "', 15) AS got");
    $lockRow = $lock ? $lock->fetch_assoc() : null;
    if (!$lockRow || (int) $lockRow['got'] !== 1) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Could not lock wallet funding; try again']);
        exit;
    }

    try {
        if ($needsPoolCapacity) {
            // Capacity = SMobile − (balances + open holds). Funding does not charge SMobile.
            $pool = wallet_pool_snapshot($mysqli);
            if (empty($pool['success'])) {
                throw new RuntimeException(
                    'Could not verify SMobile wallet capacity|' .
                    json_encode([
                        'smobile_balance' => $pool['smobile_balance'] ?? 0,
                        'injected_total' => $pool['injected_total'] ?? 0,
                        'held_total' => $pool['held_total'] ?? 0,
                        'available_to_inject' => $pool['available_to_inject'] ?? 0,
                        'message' => $pool['message'] ?? 'Could not verify SMobile wallet capacity',
                    ])
                );
            }
            $available = (float) $pool['available_to_inject'];
            if ($amount > $available + 0.00001) {
                throw new RuntimeException(
                    'Amount exceeds available inject capacity (SMobile balance minus already injected wallets)|' .
                    json_encode([
                        'smobile_balance' => (float) $pool['smobile_balance'],
                        'injected_total' => (float) $pool['injected_total'],
                        'held_total' => (float) ($pool['held_total'] ?? 0),
                        'available_to_inject' => $available,
                        'message' => $isAdminSelfInject
                            ? 'Not enough wallet capacity to fund your account. Ask your administrator to top up.'
                            : null,
                    ])
                );
            }
        }

        if (!$mysqli->begin_transaction()) {
            throw new RuntimeException('Could not begin funding transaction');
        }

        if ($isTransfer) {
            if (!wallet_debit($mysqli, $sessionId, $productId, $amount)) {
                throw new RuntimeException(
                    'Insufficient funded balance|' .
                    json_encode(['message' => 'Insufficient funded balance for this wallet type'])
                );
            }
        }

        if (!wallet_credit($mysqli, $id, $productId, $amount)) {
            throw new RuntimeException('Could not credit recipient wallet');
        }

        $user = fetch_user_wallet($mysqli, $id);
        if (!$user) {
            throw new RuntimeException('User not found after fund');
        }
        if ($isTransfer) {
            $funderAfter = fetch_user_wallet($mysqli, $sessionId);
            if (!$funderAfter) {
                throw new RuntimeException('Funder wallet missing after transfer');
            }
        }

        $receiptId = 'WF-' . $id . '-' . bin2hex(random_bytes(8));
        $walletLabels = [
            'momo' => 'MoMo Airtime Wallet',
            'vtu' => 'VTU Airtime Wallet',
            'logical' => 'Logical Airtime Wallet',
        ];
        $walletName = $walletLabels[$productId] ?? ($productId . ' Wallet');
        // Store the real full name; mask for Admin viewers only when listing history.
        $userName = trim((string) ($user['full_name'] ?? ''));
        if ($userName === '') {
            $userName = 'User';
        }
        $fundedBy = trim((string) ($actor['full_name'] ?? 'Admin'));
        $fundedByUserId = $sessionId;
        $status = 'Active';
        $fundedAt = date('Y-m-d H:i:s');

        $hist = $mysqli->prepare(
            'INSERT INTO wallet_funding_history
            (receipt_id, client_request_id, wallet_user_id, user_name, wallet_product, wallet_name, amount, funded_by, funded_by_user_id, status, funded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$hist) {
            // Older schema without client_request_id — fall back.
            $hist = $mysqli->prepare(
                'INSERT INTO wallet_funding_history
                (receipt_id, wallet_user_id, user_name, wallet_product, wallet_name, amount, funded_by, funded_by_user_id, status, funded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$hist) {
                throw new RuntimeException('Could not prepare funding history: ' . $mysqli->error);
            }
            $hist->bind_param(
                'sisssdsiss',
                $receiptId,
                $id,
                $userName,
                $productId,
                $walletName,
                $amount,
                $fundedBy,
                $fundedByUserId,
                $status,
                $fundedAt
            );
        } else {
            $hist->bind_param(
                'ssisssdsiss',
                $receiptId,
                $clientRequestId,
                $id,
                $userName,
                $productId,
                $walletName,
                $amount,
                $fundedBy,
                $fundedByUserId,
                $status,
                $fundedAt
            );
        }
        if (!$hist->execute()) {
            $err = $hist->error;
            $errno = $hist->errno;
            $hist->close();
            // Duplicate client_request_id: another worker won the race — treat as success replay.
            if ($errno === 1062) {
                throw new RuntimeException('IDEMPOTENT_RACE');
            }
            throw new RuntimeException('Funding history failed: ' . $err);
        }
        $hist->close();

        // Admin transfer: record outflow on the funder's statement.
        if ($isTransfer && $funderAfter) {
            $outReceipt = $receiptId . '-OUT';
            $outStatus = 'Transferred';
            $outAmount = -1 * abs($amount);
            $funderName = trim((string) ($funderAfter['full_name'] ?? $fundedBy));
            $outHist = $mysqli->prepare(
                'INSERT INTO wallet_funding_history
                (receipt_id, wallet_user_id, user_name, wallet_product, wallet_name, amount, funded_by, funded_by_user_id, status, funded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($outHist) {
                $outHist->bind_param(
                    'sisssdsiss',
                    $outReceipt,
                    $sessionId,
                    $funderName,
                    $productId,
                    $walletName,
                    $outAmount,
                    $userName,
                    $id,
                    $outStatus,
                    $fundedAt
                );
                if (!$outHist->execute()) {
                    $err = $outHist->error;
                    $outHist->close();
                    throw new RuntimeException('Funding debit history failed: ' . $err);
                }
                $outHist->close();
            }
        }

        if (!$mysqli->commit()) {
            throw new RuntimeException('Could not commit funding transaction');
        }
    } catch (Throwable $e) {
        @$mysqli->rollback();
        $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($lockName) . "')");
        $msg = $e->getMessage();
        if ($msg === 'IDEMPOTENT_RACE') {
            // Re-enter via prior-row lookup by releasing and letting client retry,
            // or load the winning row now.
            $prior2 = $mysqli->prepare(
                'SELECT receipt_id, wallet_user_id, wallet_product, amount, funded_by
                 FROM wallet_funding_history WHERE client_request_id = ? AND amount > 0 LIMIT 1'
            );
            if ($prior2) {
                $prior2->bind_param('s', $clientRequestId);
                $prior2->execute();
                $pr = $prior2->get_result()->fetch_assoc();
                $prior2->close();
                if ($pr) {
                    $targetUser = fetch_user_wallet($mysqli, (int) $pr['wallet_user_id']);
                    echo json_encode([
                        'success' => true,
                        'message' => 'Funding already completed',
                        'idempotent_replay' => true,
                        'mode' => $isTransfer ? 'transfer' : 'inject',
                        'receipt_id' => (string) $pr['receipt_id'],
                        'product_id' => (string) $pr['wallet_product'],
                        'amount' => (float) $pr['amount'],
                        'funded_by' => (string) $pr['funded_by'],
                        'momo_balance' => (float) ($targetUser['momo_balance'] ?? 0),
                        'vtu_balance' => (float) ($targetUser['vtu_balance'] ?? 0),
                        'logical_balance' => (float) ($targetUser['logical_balance'] ?? 0),
                    ]);
                    $mysqli->close();
                    exit;
                }
            }
        }
        if (str_starts_with($msg, 'Amount exceeds available inject capacity') ||
            str_starts_with($msg, 'Could not verify SMobile wallet capacity') ||
            str_starts_with($msg, 'Insufficient funded balance')) {
            $parts = explode('|', $msg, 2);
            $extra = isset($parts[1]) ? (json_decode($parts[1], true) ?: []) : [];
            $code = str_starts_with($msg, 'Could not verify') ? 502 : 400;
            http_response_code($code);
            echo json_encode(array_merge([
                'success' => false,
                'message' => $extra['message'] ?? $parts[0],
            ], $extra));
            exit;
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fund failed: ' . $msg]);
        exit;
    }

    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($lockName) . "')");

    $response = [
        'success' => true,
        'message' => $isTransfer ? 'Wallet funded from your balance' : 'Wallet funded',
        'mode' => $isTransfer ? 'transfer' : 'inject',
        'receipt_id' => $receiptId,
        'client_request_id' => $clientRequestId,
        'momo_balance' => (float) $user['momo_balance'],
        'vtu_balance' => (float) $user['vtu_balance'],
        'logical_balance' => (float) ($user['logical_balance'] ?? 0),
        'data_bundle_balance' => (float) $user['data_bundle_balance'],
        'commission_balance' => (float) $user['commission_balance'],
        'funded_by' => $fundedBy,
        'product_id' => $productId,
        'amount' => $amount,
    ];
    if ($isTransfer && $funderAfter) {
        $response['funder_momo_balance'] = (float) $funderAfter['momo_balance'];
        $response['funder_vtu_balance'] = (float) $funderAfter['vtu_balance'];
        $response['funder_logical_balance'] = (float) ($funderAfter['logical_balance'] ?? 0);
        $col = wallet_column_for_product($productId);
        $response['available_to_inject'] = $col
            ? (float) ($funderAfter[$col] ?? 0)
            : 0.0;
        $response['show_smobile_pool'] = false;
    } elseif ($isAdminSelfInject) {
        // Admin topped up self — never expose SMobile pool figures.
        $response['mode'] = 'self_inject';
        $response['message'] = 'Your wallet was funded';
        $response['funder_momo_balance'] = (float) $user['momo_balance'];
        $response['funder_vtu_balance'] = (float) $user['vtu_balance'];
        $response['funder_logical_balance'] = (float) ($user['logical_balance'] ?? 0);
        $col = wallet_column_for_product($productId);
        $response['available_to_inject'] = $col ? (float) ($user[$col] ?? 0) : 0.0;
        $response['show_smobile_pool'] = false;
    } else {
        $freshPool = wallet_pool_snapshot($mysqli);
        $response['smobile_balance'] = (float) ($freshPool['smobile_balance'] ?? $pool['smobile_balance'] ?? 0);
        $response['injected_total'] = (float) ($freshPool['injected_total'] ?? 0);
        $response['held_total'] = (float) ($freshPool['held_total'] ?? 0);
        $response['available_to_inject'] = (float) ($freshPool['available_to_inject'] ?? 0);
        $response['show_smobile_pool'] = true;
    }
    echo json_encode($response);
    $mysqli->close();
    exit;
}

// Read-only sync (Flutter / agents refresh balances without writing).
if ($action === 'sync' || $action === 'get' || $action === 'read') {
    // Users sync own wallet; Admin+ may sync others.
    if ($id !== $sessionId && $sessionRole < 2) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cannot sync another user wallet']);
        exit;
    }
    if ($id !== $sessionId && $sessionRole === 2) {
        $targetStmt = $mysqli->prepare(
            'SELECT id, role, COALESCE(is_external, 0) AS is_external FROM users WHERE id = ? LIMIT 1'
        );
        if (!$targetStmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not verify target user']);
            exit;
        }
        $targetStmt->bind_param('i', $id);
        $targetStmt->execute();
        $targetRow = $targetStmt->get_result()->fetch_assoc();
        $targetStmt->close();
        if (!$targetRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        if ((int) ($targetRow['role'] ?? 99) >= 3 || (int) ($targetRow['is_external'] ?? 0) === 1) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
    }
    $user = fetch_user_wallet($mysqli, $id);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        $mysqli->close();
        exit;
    }

    // Own external account: rotate legacy EC######## IDs on sync so dashboard stays current.
    if ($id === $sessionId && (int) ($user['is_external'] ?? 0) === 1) {
        $freshWid = user_ensure_wallet_id($mysqli, $id);
        if ($freshWid !== null && $freshWid !== '') {
            $user['wallet_id'] = $freshWid;
        }
    }

    $payload = [
        'success' => true,
        'message' => 'Wallet synced',
        'momo_balance' => (float) $user['momo_balance'],
        'vtu_balance' => (float) $user['vtu_balance'],
        'logical_balance' => (float) ($user['logical_balance'] ?? 0),
        'data_bundle_balance' => (float) $user['data_bundle_balance'],
        'commission_balance' => (float) $user['commission_balance'],
        'has_transaction_pin' => trim((string) ($user['transaction_pin_hash'] ?? '')) !== '',
        'email_verified' => !empty($user['email_verified_at']),
    ];
    // Visibility: Super Admin always. Other roles never get is_external;
    // own account may receive wallet_id only when assigned.
    if ($sessionRole >= 3) {
        $payload['is_external'] = (int) ($user['is_external'] ?? 0) === 1;
        $payload['wallet_id'] = trim((string) ($user['wallet_id'] ?? ''));
    } elseif ($id === $sessionId) {
        $wid = trim((string) ($user['wallet_id'] ?? ''));
        if ($wid !== '') {
            $payload['wallet_id'] = $wid;
        }
    }
    echo json_encode($payload);
    $mysqli->close();
    exit;
}

// Absolute balance writes are disabled. Wallets grow via Super Admin inject,
// Admin→user transfer (`fund`), or purchase debit/refund helpers.
http_response_code(403);
echo json_encode([
    'success' => false,
    'message' => 'Direct wallet balance overwrite is not allowed. Use Fund Wallet or the purchase flow.',
]);
$mysqli->close();
