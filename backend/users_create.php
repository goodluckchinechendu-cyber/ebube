<?php
/**
 * Super Admin (and Admin for internal only) provisions an app user account.
 * Used by "Register Customer" so the person appears in Manage Users & Roles.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/auth_util.php';
require_once __DIR__ . '/referral_util.php';
require_once __DIR__ . '/user_visibility_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}
ensure_user_visibility_columns($mysqli);

$sessionUser = ec_require_session($mysqli);
$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) $sessionUser['role'];

if ($sessionRole < 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$fullName = trim((string) ($data['full_name'] ?? $data['name'] ?? ''));
$phone = normalize_phone_for_storage((string) ($data['phone'] ?? ''));
$location = trim((string) ($data['location'] ?? $data['address'] ?? ''));
$gender = trim((string) ($data['gender'] ?? 'Male'));
if ($gender === '') {
    $gender = 'Male';
}
$email = normalize_email((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? $data['pin'] ?? '');

$wantExternal = false;
if ($sessionRole >= 3) {
    $visRaw = $data['is_external'] ?? $data['visibility'] ?? $data['vis'] ?? true;
    if (is_bool($visRaw)) {
        $wantExternal = $visRaw;
    } else {
        $visStr = strtolower(trim((string) $visRaw));
        $wantExternal = !in_array($visStr, ['0', 'false', 'int', 'internal', 'no'], true);
    }
}

if ($fullName === '' || $phone === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Full name, phone, email and 4-digit PIN are required',
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address']);
    exit;
}

if (!is_valid_pin($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => pin_validation_message()]);
    exit;
}

// Admin may only create internal users.
if ($sessionRole < 3 && $wantExternal) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Super Admin can create external users']);
    exit;
}

$check = $mysqli->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
$check->bind_param('s', $email);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    exit;
}
$check->close();

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$role = 0;
$registeredBy = $sessionId;
$verifiedAt = date('Y-m-d H:i:s');

$insert = $mysqli->prepare(
    'INSERT INTO users (
        full_name, phone, location, gender, email,
        account_name, bank_name, account_number,
        password_hash, role, registered_by, email_verified_at
    ) VALUES (?, ?, ?, ?, ?, \'\', \'\', \'\', ?, ?, ?, ?)'
);
if (!$insert) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$insert->bind_param(
    'ssssssiis',
    $fullName,
    $phone,
    $location,
    $gender,
    $email,
    $passwordHash,
    $role,
    $registeredBy,
    $verifiedAt
);

if (!$insert->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create user: ' . $insert->error]);
    $insert->close();
    $mysqli->close();
    exit;
}

$userId = (int) $insert->insert_id;
$insert->close();

$walletIdOut = '';
if ($wantExternal) {
    $marked = false;
    for ($attempt = 0; $attempt < 5 && !$marked; $attempt++) {
        $candidate = user_generate_wallet_id($mysqli);
        $visUpd = $mysqli->prepare('UPDATE users SET is_external = 1, wallet_id = ? WHERE id = ?');
        if (!$visUpd) {
            break;
        }
        $visUpd->bind_param('si', $candidate, $userId);
        $ok = $visUpd->execute();
        $visUpd->close();
        if ($ok) {
            $walletIdOut = $candidate;
            $marked = true;
        }
    }
    if (!$marked) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'User created but could not assign external wallet ID',
            'user_id' => $userId,
        ]);
        $mysqli->close();
        exit;
    }
}

user_ensure_referral_code($mysqli, $userId);

hierarchy_ensure_customer_under(
    $mysqli,
    $registeredBy,
    $fullName,
    $phone,
    $email,
    $gender,
    $location
);

http_response_code(201);
echo json_encode([
    'success' => true,
    'message' => $wantExternal
        ? 'External customer account created. They can sign in with email and PIN.'
        : 'Internal customer account created. They can sign in with email and PIN.',
    'user' => [
        'id' => $userId,
        'full_name' => $fullName,
        'phone' => $phone,
        'location' => $location,
        'gender' => $gender,
        'email' => $email,
        'role' => $role,
        'registered_by' => $registeredBy,
        'is_external' => $wantExternal,
        'wallet_id' => $walletIdOut,
        'email_verified' => true,
    ],
]);
$mysqli->close();
