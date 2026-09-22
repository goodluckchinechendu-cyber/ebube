<?php
require_once 'config.php';
require_once 'auth_util.php';
require_once 'schema.php';
require_once __DIR__ . '/email_verify_util.php';
require_once __DIR__ . '/referral_util.php';
require_once __DIR__ . '/user_visibility_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['full_name']) || !isset($data['phone']) || !isset($data['location']) ||
    !isset($data['gender']) || !isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$full_name = trim((string) $data['full_name']);
$phone = normalize_phone_for_storage((string) $data['phone']);
$location = trim((string) $data['location']);
$gender = trim((string) $data['gender']);
$email = normalize_email((string) $data['email']);
$password = (string) $data['password'];
$account_name = trim((string) ($data['account_name'] ?? ''));
$bank_name = trim((string) ($data['bank_name'] ?? ''));
$account_number = preg_replace('/\D/', '', (string) ($data['account_number'] ?? ''));

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

if ($account_name !== '' || $bank_name !== '' || $account_number !== '') {
    $payoutError = validate_payout_details($account_name, $bank_name, $account_number);
    if ($payoutError !== null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $payoutError]);
        exit;
    }
}

$check_sql = 'SELECT id FROM users WHERE LOWER(TRIM(email)) = ?';
$check_stmt = $mysqli->prepare($check_sql);
$check_stmt->bind_param('s', $email);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    $check_stmt->close();
    exit;
}
$check_stmt->close();

$password_hash = password_hash($password, PASSWORD_DEFAULT);
$role = 0;

$registeredBy = null;
$refCode = strtoupper(trim((string) ($data['referral_code'] ?? $data['ref'] ?? '')));
if ($refCode !== '') {
    $inviterId = user_id_from_referral_code($mysqli, $refCode);
    if ($inviterId !== null && $inviterId > 0) {
        $registeredBy = $inviterId;
    }
}
// Same ownership as Customer Directory: if already registered as a customer, inherit that parent.
if ($registeredBy === null) {
    $fromCustomer = hierarchy_registrar_from_customer($mysqli, $phone, $email);
    if ($fromCustomer !== null && $fromCustomer > 0) {
        $registeredBy = $fromCustomer;
    }
}

$insert_sql = 'INSERT INTO users (
    full_name, phone, location, gender, email,
    account_name, bank_name, account_number,
    password_hash, role, registered_by, email_verified_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), NULL)';
$insert_stmt = $mysqli->prepare($insert_sql);

if (!$insert_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    exit;
}

$registeredByBind = $registeredBy === null ? 0 : (int) $registeredBy;
$insert_stmt->bind_param(
    'sssssssssii',
    $full_name,
    $phone,
    $location,
    $gender,
    $email,
    $account_name,
    $bank_name,
    $account_number,
    $password_hash,
    $role,
    $registeredByBind
);

if (!$insert_stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $insert_stmt->error]);
    $insert_stmt->close();
    $mysqli->close();
    exit;
}

$userId = (int) $insert_stmt->insert_id;
$insert_stmt->close();

// Super Admin invites: register as external by default (vis=int opts out).
// Only applies when registration used an invite referral code (not CRM phone match alone).
$isExternalOut = false;
$walletIdOut = '';
if ($registeredBy !== null && $registeredBy > 0 && $refCode !== '') {
    $inv = $mysqli->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    if ($inv) {
        $inv->bind_param('i', $registeredBy);
        $inv->execute();
        $invRow = $inv->get_result()->fetch_assoc();
        $inv->close();
        if ($invRow && (int) ($invRow['role'] ?? 0) >= 3) {
            $visRaw = strtolower(trim((string) ($data['visibility'] ?? $data['vis'] ?? 'ext')));
            $wantInternal = in_array($visRaw, ['int', 'internal', '0', 'false'], true);
            if (!$wantInternal) {
                ensure_user_visibility_columns($mysqli);
                $marked = false;
                for ($attempt = 0; $attempt < 5 && !$marked; $attempt++) {
                    $candidate = user_generate_wallet_id($mysqli);
                    $visUpd = $mysqli->prepare(
                        'UPDATE users SET is_external = 1, wallet_id = ? WHERE id = ?'
                    );
                    if (!$visUpd) {
                        break;
                    }
                    $visUpd->bind_param('si', $candidate, $userId);
                    $ok = $visUpd->execute();
                    $visUpd->close();
                    if (!$ok) {
                        continue;
                    }
                    $check = $mysqli->prepare(
                        'SELECT COALESCE(is_external, 0) AS is_external, COALESCE(wallet_id, \'\') AS wallet_id
                         FROM users WHERE id = ? LIMIT 1'
                    );
                    if (!$check) {
                        break;
                    }
                    $check->bind_param('i', $userId);
                    $check->execute();
                    $crow = $check->get_result()->fetch_assoc();
                    $check->close();
                    if ($crow && (int) ($crow['is_external'] ?? 0) === 1) {
                        $isExternalOut = true;
                        $walletIdOut = trim((string) ($crow['wallet_id'] ?? $candidate));
                        $marked = true;
                    }
                }
                if (!$marked) {
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Account created but could not assign external wallet ID. Contact support.',
                        'user_id' => $userId,
                    ]);
                    $mysqli->close();
                    exit;
                }
            }
        }
    }
}

// Ensure new account has its own invite code.
user_ensure_referral_code($mysqli, $userId);

// Keep Customer Directory + app-user hierarchy in sync under the same parent.
if ($registeredBy !== null && $registeredBy > 0) {
    hierarchy_ensure_customer_under(
        $mysqli,
        (int) $registeredBy,
        $full_name,
        $phone,
        $email,
        $gender,
        $location
    );
}

$challenge = create_email_verification_challenge($mysqli, $userId, $email);
if (empty($challenge['success'])) {
    http_response_code((int) ($challenge['http_code'] ?? 500));
    echo json_encode([
        'success' => false,
        'message' => $challenge['message'] ?? 'Account created but verification email failed. Try signing in to resend.',
        'user_id' => $userId,
        'needs_email_verification' => true,
    ]);
    $mysqli->close();
    exit;
}

http_response_code(201);
echo json_encode([
    'success' => true,
    'message' => $challenge['message'] ?? 'Registration successful. Verify your email to sign in.',
    'needs_email_verification' => true,
    'challenge_id' => $challenge['challenge_id'] ?? null,
    'masked_email' => $challenge['masked_email'] ?? mask_email_for_display($email),
    'email_sent' => $challenge['email_sent'] ?? false,
    'user' => [
        'id' => $userId,
        'full_name' => $full_name,
        'phone' => $phone,
        'location' => $location,
        'gender' => $gender,
        'email' => $email,
        'role' => $role,
        'email_verified' => false,
        'has_transaction_pin' => false,
        'is_external' => $isExternalOut,
        'wallet_id' => $isExternalOut ? $walletIdOut : '',
    ],
]);

$mysqli->close();
