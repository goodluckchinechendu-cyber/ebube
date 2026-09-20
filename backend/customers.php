<?php
require_once 'config.php';
require_once 'schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/auth_util.php';
require_once __DIR__ . '/referral_util.php';

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

function customer_row_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'registered_by_user_id' => (int) $row['registered_by_user_id'],
        'full_name' => $row['full_name'],
        'phone' => $row['phone'],
        'address' => $row['address'],
        'email' => $row['email'],
        'gender' => $row['gender'],
        'date_of_birth' => $row['date_of_birth'],
        'created_at' => $row['created_at'] ?? null,
    ];
}

if ($action === 'list') {
    $agentUserId = isset($data['user_id']) ? (int) $data['user_id'] : $sessionId;
    $admin = !empty($data['admin']) && $sessionRole >= 2;

    // Non-admins can only list their own customers.
    if ($sessionRole < 2) {
        $admin = false;
        $agentUserId = $sessionId;
    } elseif (!$admin && $agentUserId <= 0) {
        $agentUserId = $sessionId;
    }

    if ($admin) {
        $sql = 'SELECT id, registered_by_user_id, full_name, phone, address, email, gender, date_of_birth, created_at
                FROM customers ORDER BY full_name ASC';
        $result = $mysqli->query($sql);
    } else {
        if ($agentUserId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'user_id required']);
            exit;
        }
        $stmt = $mysqli->prepare(
            'SELECT id, registered_by_user_id, full_name, phone, address, email, gender, date_of_birth, created_at
             FROM customers WHERE registered_by_user_id = ? ORDER BY full_name ASC'
        );
        $stmt->bind_param('i', $agentUserId);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    $customers = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $customers[] = customer_row_to_array($row);
        }
    }
    if (isset($stmt)) {
        $stmt->close();
    }

    echo json_encode(['success' => true, 'customers' => $customers]);
    $mysqli->close();
    exit;
}

if ($action === 'create') {
    // Always profile under the signed-in user (same parent as Invite Link).
    $registeredBy = $sessionId;
    $name = trim((string) ($data['name'] ?? $data['full_name'] ?? ''));
    $phone = normalize_phone_for_storage((string) ($data['phone'] ?? ''));
    $address = trim((string) ($data['address'] ?? ''));
    $email = normalize_email((string) ($data['email'] ?? ''));
    $gender = trim((string) ($data['gender'] ?? ''));
    if ($gender === '') {
        $gender = 'Male';
    }
    $dob = trim((string) ($data['date_of_birth'] ?? ''));

    if ($registeredBy <= 0 || $name === '' || $phone === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Name and phone required']);
        exit;
    }

    hierarchy_ensure_customer_under(
        $mysqli,
        $registeredBy,
        $name,
        $phone,
        $email,
        $gender,
        $address
    );
    // If they already have an app account, put them under this registrar too.
    hierarchy_link_users_under($mysqli, $registeredBy, $phone, $email, true);

    // Prefer the row just upserted.
    $phoneDigits = $phone;
    $fetch = $mysqli->prepare(
        'SELECT id, registered_by_user_id, full_name, phone, address, email, gender, date_of_birth, created_at
         FROM customers
         WHERE registered_by_user_id = ?
           AND (phone = ? OR REPLACE(REPLACE(REPLACE(phone, \'+\', \'\'), \' \', \'\'), \'-\', \'\') = ?)
         ORDER BY id DESC LIMIT 1'
    );
    $fetch->bind_param('iss', $registeredBy, $phone, $phoneDigits);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if (!$row && $dob !== '') {
        // Fallback insert path if helper somehow skipped (should be rare).
        $emailVal = $email === '' ? null : $email;
        $stmt = $mysqli->prepare(
            'INSERT INTO customers (registered_by_user_id, full_name, phone, address, email, gender, date_of_birth)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issssss', $registeredBy, $name, $phone, $address, $emailVal, $gender, $dob);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        $fetch = $mysqli->prepare(
            'SELECT id, registered_by_user_id, full_name, phone, address, email, gender, date_of_birth, created_at
             FROM customers WHERE id = ?'
        );
        $fetch->bind_param('i', $newId);
        $fetch->execute();
        $row = $fetch->get_result()->fetch_assoc();
        $fetch->close();
    }

    if (!$row) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not create customer']);
        $mysqli->close();
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'customer' => customer_row_to_array($row),
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'update') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $admin = !empty($data['admin']) && $sessionRole >= 2;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id required']);
        exit;
    }

    $check = $mysqli->prepare('SELECT registered_by_user_id FROM customers WHERE id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }

    if (!$admin && (int) $existing['registered_by_user_id'] !== $sessionId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not allowed to edit this customer']);
        exit;
    }

    $name = trim((string) ($data['name'] ?? $data['full_name'] ?? ''));
    $phone = normalize_phone_for_storage((string) ($data['phone'] ?? ''));
    $address = trim((string) ($data['address'] ?? ''));
    $email = normalize_email((string) ($data['email'] ?? ''));
    $gender = trim((string) ($data['gender'] ?? ''));
    $dob = trim((string) ($data['date_of_birth'] ?? ''));
    $emailVal = $email === '' ? null : $email;

    $stmt = $mysqli->prepare(
        'UPDATE customers SET full_name = ?, phone = ?, address = ?, email = ?, gender = ?, date_of_birth = ? WHERE id = ?'
    );
    $stmt->bind_param('ssssssi', $name, $phone, $address, $emailVal, $gender, $dob, $id);
    $stmt->execute();
    $stmt->close();

    // Keep app-user hierarchy aligned with this customer owner.
    $ownerId = (int) $existing['registered_by_user_id'];
    if ($ownerId > 0) {
        hierarchy_link_users_under($mysqli, $ownerId, $phone, $email, true);
    }

    $fetch = $mysqli->prepare(
        'SELECT id, registered_by_user_id, full_name, phone, address, email, gender, date_of_birth, created_at
         FROM customers WHERE id = ?'
    );
    $fetch->bind_param('i', $id);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    echo json_encode(['success' => true, 'customer' => customer_row_to_array($row)]);
    $mysqli->close();
    exit;
}

if ($action === 'delete') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $admin = !empty($data['admin']) && $sessionRole >= 2;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id required']);
        exit;
    }

    $check = $mysqli->prepare('SELECT registered_by_user_id FROM customers WHERE id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }

    if (!$admin && (int) $existing['registered_by_user_id'] !== $sessionId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not allowed to delete this customer']);
        exit;
    }

    $stmt = $mysqli->prepare('DELETE FROM customers WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
