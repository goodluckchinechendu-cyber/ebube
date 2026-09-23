<?php
require_once 'config.php';
require_once 'auth_util.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/user_visibility_util.php';

// Role constants: 0=Customer, 1=Agent, 2=Admin, 3=Super Admin
$allowedRoles = [0, 1, 2, 3];
$roleNames = [
    0 => 'Customer',
    1 => 'Agent',
    2 => 'Admin',
    3 => 'Super Admin',
];

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) $sessionUser['role'];
ensure_user_visibility_columns($mysqli);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}
$action = $data['action'] ?? 'update';

function fetch_user_role(mysqli $mysqli, int $userId): ?int
{
    $stmt = $mysqli->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return (int) $row['role'];
}

function fetch_user_role_and_external(mysqli $mysqli, int $userId): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT role, COALESCE(is_external, 0) AS is_external FROM users WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'role' => (int) $row['role'],
        'is_external' => (int) $row['is_external'] === 1,
    ];
}

/**
 * Super Admin: anyone.
 * Admin: Customers (0) and Agents (1) — can promote them to Admin only if allowed.
 */
function actor_can_edit_target(int $actorRole, int $targetRole): bool
{
    if ($actorRole >= 3) {
        return true;
    }
    if ($actorRole === 2) {
        return $targetRole <= 1;
    }
    return false;
}

function actor_can_assign_role(mysqli $mysqli, int $actorId, int $actorRole, int $newRole): bool
{
    if ($actorRole >= 3) {
        return in_array($newRole, [0, 1, 2, 3], true);
    }
    if ($actorRole === 2) {
        if ($newRole === 2) {
            return user_can_assign_admin_role($mysqli, $actorId, $actorRole);
        }
        return in_array($newRole, [0, 1], true);
    }
    return false;
}

// ── ACTION: set_visibility — Super Admin only (internal / external) ─────────
if ($action === 'set_visibility') {
    if ($sessionRole < 3) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only Super Admin can change this']);
        exit;
    }

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $isExternal = !empty($data['is_external']) && (
        $data['is_external'] === true
        || $data['is_external'] === 1
        || $data['is_external'] === '1'
        || $data['is_external'] === 'true'
    );

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User id required']);
        exit;
    }

    $chk = $mysqli->prepare('SELECT id, role, wallet_id FROM users WHERE id = ? LIMIT 1');
    if (!$chk) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
    $chk->bind_param('i', $id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $flag = $isExternal ? 1 : 0;
    $walletId = trim((string) ($row['wallet_id'] ?? ''));
    if ($isExternal) {
        // Always issue a fresh varied ID (including replacing legacy EC########).
        if ($walletId === '' || user_wallet_id_is_legacy_format($walletId)) {
            $walletId = user_generate_wallet_id($mysqli);
        }
        $upd = $mysqli->prepare('UPDATE users SET is_external = ?, wallet_id = ? WHERE id = ?');
        if (!$upd) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        $upd->bind_param('isi', $flag, $walletId, $id);
    } else {
        // Clear wallet_id when returning to internal so it cannot leak later.
        $upd = $mysqli->prepare('UPDATE users SET is_external = 0, wallet_id = NULL WHERE id = ?');
        if (!$upd) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        $upd->bind_param('i', $id);
        $walletId = '';
    }

    if (!$upd->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $upd->error]);
        $upd->close();
        exit;
    }
    $upd->close();

    if ($isExternal && $walletId === '') {
        $walletId = (string) (user_ensure_wallet_id($mysqli, $id) ?? '');
    }

    echo json_encode([
        'success' => true,
        'message' => $isExternal ? 'User marked as external' : 'User marked as internal',
        'user_id' => $id,
        'is_external' => $isExternal,
        'wallet_id' => $isExternal ? $walletId : '',
    ]);
    $mysqli->close();
    exit;
}

// ── ACTION: set_role — change only the role of a user ───────────────────────
if ($action === 'set_role') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $role = isset($data['role']) ? (int) $data['role'] : -1;
    $actorId = isset($data['actor_id']) ? (int) $data['actor_id'] : $sessionId;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User id required']);
        exit;
    }

    if ($actorId !== $sessionId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'actor_id must match authenticated session']);
        exit;
    }

    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid role. Allowed: 0=Customer, 1=Agent, 2=Admin',
        ]);
        exit;
    }

    if ($sessionRole < 2) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only an administrator can change roles']);
        exit;
    }

    $targetMeta = fetch_user_role_and_external($mysqli, $id);
    if ($targetMeta === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Target user not found']);
        exit;
    }
    $targetRole = $targetMeta['role'];

    if ($sessionRole < 3 && $targetMeta['is_external']) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Target user not found']);
        exit;
    }

    if (!actor_can_edit_target($sessionRole, $targetRole)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin can only edit Agents and Customers',
        ]);
        exit;
    }
    if (!actor_can_assign_role($mysqli, $sessionId, $sessionRole, $role)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $sessionRole >= 3
                ? 'Invalid role assignment'
                : ($role === 2
                    ? 'Only Super Admin (or Admins created by Super Admin) can assign the Admin role'
                    : 'Admin can assign Customer or Agent roles'),
        ]);
        exit;
    }

    $stmt = $mysqli->prepare('UPDATE users SET role = ? WHERE id = ?');
    $stmt->bind_param('ii', $role, $id);

    if ($stmt->execute()) {
        if ($role !== $targetRole) {
            user_set_admin_granted_by($mysqli, $id, $role, $sessionId, $sessionRole);
        }
        echo json_encode([
            'success' => true,
            'message' => 'Role updated to ' . ($roleNames[$role] ?? (string) $role),
            'user_id' => $id,
            'role' => $role,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Role update failed: ' . $stmt->error]);
    }

    $stmt->close();
    $mysqli->close();
    exit;
}

// ── ACTION: update — registration / profile fields ──────────────────────────
if ($sessionRole < 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only an administrator can edit users']);
    exit;
}

if (!isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User id required']);
    exit;
}

$id = (int) $data['id'];
$targetMeta = fetch_user_role_and_external($mysqli, $id);
if ($targetMeta === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Target user not found']);
    exit;
}
$targetRole = $targetMeta['role'];

if ($sessionRole < 3 && $targetMeta['is_external']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Target user not found']);
    exit;
}

if (!actor_can_edit_target($sessionRole, $targetRole)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin can only edit Agents and Customers',
    ]);
    exit;
}

$full_name = trim((string) ($data['full_name'] ?? ''));
$phone = normalize_phone_for_storage((string) ($data['phone'] ?? ''));
$location = trim((string) ($data['location'] ?? ''));
$gender = trim((string) ($data['gender'] ?? ''));
$email = normalize_email((string) ($data['email'] ?? ''));
$role = isset($data['role']) ? (int) $data['role'] : $targetRole;
$password = (string) ($data['password'] ?? $data['login_pin'] ?? '');
$txnPin = trim((string) ($data['transaction_pin'] ?? $data['txn_pin'] ?? ''));

if (!in_array($role, $allowedRoles, true)) {
    $role = $targetRole;
}

if ($role !== $targetRole && !actor_can_assign_role($mysqli, $sessionId, $sessionRole, $role)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => $role === 2
            ? 'Only Super Admin (or Admins created by Super Admin) can assign the Admin role'
            : 'You cannot assign that role',
    ]);
    exit;
}

if ($full_name === '' || $phone === '' || $location === '' || $gender === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if ($password !== '' && !is_valid_pin($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => pin_validation_message()]);
    exit;
}

if ($txnPin !== '') {
    if (!is_valid_pin($txnPin)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Transaction PIN must be exactly 4 digits']);
        exit;
    }
    if ($password !== '' && $txnPin === $password) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Transaction PIN must be different from login PIN',
        ]);
        exit;
    }
    // If login PIN not being changed, still block txn PIN matching existing login hash.
    if ($password === '') {
        $hashStmt = $mysqli->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        if ($hashStmt) {
            $hashStmt->bind_param('i', $id);
            $hashStmt->execute();
            $hashRow = $hashStmt->get_result()->fetch_assoc();
            $hashStmt->close();
            $existingLogin = (string) ($hashRow['password_hash'] ?? '');
            if ($existingLogin !== '' && password_verify($txnPin, $existingLogin)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Transaction PIN must be different from login PIN',
                ]);
                exit;
            }
        }
    }
}

// Unique email / phone (exclude self).
$dup = $mysqli->prepare(
    'SELECT id FROM users WHERE (email = ? OR phone = ?) AND id <> ? LIMIT 1'
);
if ($dup) {
    $dup->bind_param('ssi', $email, $phone, $id);
    $dup->execute();
    $dupRow = $dup->get_result()->fetch_assoc();
    $dup->close();
    if ($dupRow) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email or phone already used by another account']);
        exit;
    }
}

$setParts = [
    'full_name = ?',
    'phone = ?',
    'location = ?',
    'gender = ?',
    'email = ?',
    'role = ?',
];
$types = 'sssssi';
$params = [$full_name, $phone, $location, $gender, $email, $role];

// Optional: manually assign registered_by (who they are under).
if (array_key_exists('registered_by', $data)) {
    $regByRaw = $data['registered_by'];
    if ($regByRaw === null || $regByRaw === '' || (int) $regByRaw === 0) {
        $setParts[] = 'registered_by = NULL';
    } else {
        $regById = (int) $regByRaw;
        if ($regById === $id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A user cannot be registered under themselves']);
            exit;
        }
        $checkReg = $mysqli->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
        $checkReg->bind_param('i', $regById);
        $checkReg->execute();
        $regRow = $checkReg->get_result()->fetch_assoc();
        $checkReg->close();
        if (!$regRow) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Registered-by user not found']);
            exit;
        }
        if ($sessionRole === 2 && (int) $regRow['role'] >= 3) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        $setParts[] = 'registered_by = ?';
        $types .= 'i';
        $params[] = $regById;
    }
}

if ($password !== '') {
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $setParts[] = 'password_hash = ?';
    $types .= 's';
    $params[] = $password_hash;
}

if ($txnPin !== '') {
    $txnHash = password_hash($txnPin, PASSWORD_DEFAULT);
    $setParts[] = 'transaction_pin_hash = ?';
    $types .= 's';
    $params[] = $txnHash;
}

$types .= 'i';
$params[] = $id;

$sql = 'UPDATE users SET ' . implode(', ', $setParts) . ' WHERE id = ?';
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$bind = [$types];
foreach ($params as $i => $v) {
    $bind[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind);

if ($stmt->execute()) {
    if ($role !== $targetRole) {
        user_set_admin_granted_by($mysqli, $id, $role, $sessionId, $sessionRole);
    }
    echo json_encode([
        'success' => true,
        'message' => 'User updated',
        'user_id' => $id,
        'role' => $role,
        'login_pin_updated' => $password !== '',
        'transaction_pin_updated' => $txnPin !== '',
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
}

$stmt->close();
$mysqli->close();
