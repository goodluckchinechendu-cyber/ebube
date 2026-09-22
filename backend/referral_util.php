<?php
/**
 * Invite / registered_by helpers for wallet transfer hierarchy.
 * Customer Directory and Invite Link share the same parent ownership.
 */

function referral_code_alphabet(): string
{
    return 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
}

function referral_generate_code(mysqli $mysqli, int $userId): string
{
    $alphabet = referral_code_alphabet();
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
        if (!$stmt) {
            break;
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $code;
        }
    }
    return strtoupper(substr(bin2hex(pack('N', $userId)) . bin2hex(random_bytes(3)), 0, 10));
}

/**
 * Ensure the user has a referral_code; returns it.
 */
function user_ensure_referral_code(mysqli $mysqli, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = $mysqli->prepare('SELECT referral_code FROM users WHERE id = ? LIMIT 1');
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
    $existing = trim((string) ($row['referral_code'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }
    $code = referral_generate_code($mysqli, $userId);
    $upd = $mysqli->prepare(
        "UPDATE users SET referral_code = ? WHERE id = ? AND (referral_code IS NULL OR referral_code = '')"
    );
    if (!$upd) {
        return null;
    }
    $upd->bind_param('si', $code, $userId);
    $upd->execute();
    $upd->close();
    $check = $mysqli->prepare('SELECT referral_code FROM users WHERE id = ? LIMIT 1');
    $check->bind_param('i', $userId);
    $check->execute();
    $fresh = $check->get_result()->fetch_assoc();
    $check->close();
    return $fresh ? trim((string) ($fresh['referral_code'] ?? '')) : $code;
}

/**
 * Resolve invite code → user id (or null).
 */
function user_id_from_referral_code(mysqli $mysqli, string $code): ?int
{
    $code = strtoupper(trim($code));
    if ($code === '' || strlen($code) < 4) {
        return null;
    }
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE UPPER(referral_code) = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return (int) $row['id'];
}

/**
 * Whether $from may peer-transfer wallet balance to $to.
 */
function user_can_wallet_transfer_to(array $from, array $to): bool
{
    $fromId = (int) ($from['id'] ?? 0);
    $toId = (int) ($to['id'] ?? 0);
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        return false;
    }
    $fromRole = (int) ($from['role'] ?? 0);
    $toRole = (int) ($to['role'] ?? 0);
    if ($toRole >= 3) {
        return false;
    }

    if ($fromRole >= 3) {
        return $toRole < 3;
    }

    $registeredBy = (int) ($to['registered_by'] ?? 0);

    if ($fromRole === 2) {
        // Admin may peer-transfer to any external customer (fund/transfer by name or Wallet ID).
        if ((int) ($to['is_external'] ?? 0) === 1 && $toRole < 3) {
            return true;
        }
        if ($toRole === 2) {
            return true;
        }
        return $registeredBy === $fromId && ($toRole === 0 || $toRole === 1);
    }

    if ($fromRole === 1) {
        return $registeredBy === $fromId && ($toRole === 0 || $toRole === 1);
    }

    return $registeredBy === $fromId && $toRole === 0;
}

/**
 * Public invite registration URL for a referral code.
 * $visibility: null (omit), 'ext', or 'int' — Super Admin invite links use this.
 */
function referral_invite_url(string $code, ?string $baseOrigin = null, ?string $visibility = null): string
{
    $code = strtoupper(trim($code));
    $origin = $baseOrigin;
    if ($origin === null || $origin === '') {
        $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = ($fwd === 'https')
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $host = $_SERVER['HTTP_HOST'] ?? 'ebubeconnect.com';
        if (stripos($host, 'ebubeconnect.com') !== false) {
            $https = true;
        }
        $origin = ($https ? 'https' : 'http') . '://' . $host;
    }
    $origin = rtrim($origin, '/');
    $url = $origin . '/agent/?ref=' . rawurlencode($code);
    $vis = strtolower(trim((string) $visibility));
    if ($vis === 'ext' || $vis === 'external') {
        $url .= '&vis=ext';
    } elseif ($vis === 'int' || $vis === 'internal') {
        $url .= '&vis=int';
    }
    return $url;
}

function hierarchy_phone_digits(string $phone): string
{
    return preg_replace('/\D/', '', $phone) ?? '';
}

/**
 * Find who already owns this person via Customer Directory.
 */
function hierarchy_registrar_from_customer(mysqli $mysqli, string $phone, string $email = ''): ?int
{
    $phoneDigits = hierarchy_phone_digits($phone);
    if ($phoneDigits !== '') {
        $stmt = $mysqli->prepare(
            'SELECT registered_by_user_id FROM customers
             WHERE phone = ?
                OR REPLACE(REPLACE(REPLACE(phone, "+", ""), " ", ""), "-", "") = ?
             ORDER BY id ASC LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('ss', $phone, $phoneDigits);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $id = (int) ($row['registered_by_user_id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
    }
    $email = strtolower(trim($email));
    if ($email !== '') {
        $stmt = $mysqli->prepare(
            'SELECT registered_by_user_id FROM customers
             WHERE email IS NOT NULL AND LOWER(TRIM(email)) = ?
             ORDER BY id ASC LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $id = (int) ($row['registered_by_user_id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
    }
    return null;
}

/**
 * Ensure a Customer Directory row exists under $registrarId (matched by phone).
 */
function hierarchy_ensure_customer_under(
    mysqli $mysqli,
    int $registrarId,
    string $fullName,
    string $phone,
    string $email = '',
    string $gender = 'Male',
    string $address = ''
): void {
    if ($registrarId <= 0) {
        return;
    }
    $phone = trim($phone);
    $fullName = trim($fullName);
    if ($phone === '' || $fullName === '') {
        return;
    }
    if ($gender === '') {
        $gender = 'Male';
    }
    $emailVal = trim($email);
    $phoneDigits = hierarchy_phone_digits($phone);

    $find = $mysqli->prepare(
        'SELECT id FROM customers
         WHERE phone = ?
            OR REPLACE(REPLACE(REPLACE(phone, "+", ""), " ", ""), "-", "") = ?
         ORDER BY id ASC LIMIT 1'
    );
    if (!$find) {
        return;
    }
    $find->bind_param('ss', $phone, $phoneDigits);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();

    if ($existing) {
        $cid = (int) $existing['id'];
        if ($emailVal === '') {
            $upd = $mysqli->prepare(
                'UPDATE customers
                 SET registered_by_user_id = ?, full_name = ?, gender = ?,
                     address = IF(? = "", address, ?)
                 WHERE id = ?'
            );
            if ($upd) {
                $upd->bind_param('issssi', $registrarId, $fullName, $gender, $address, $address, $cid);
                $upd->execute();
                $upd->close();
            }
        } else {
            $upd = $mysqli->prepare(
                'UPDATE customers
                 SET registered_by_user_id = ?, full_name = ?, gender = ?,
                     address = IF(? = "", address, ?), email = ?
                 WHERE id = ?'
            );
            if ($upd) {
                $upd->bind_param(
                    'isssssi',
                    $registrarId,
                    $fullName,
                    $gender,
                    $address,
                    $address,
                    $emailVal,
                    $cid
                );
                $upd->execute();
                $upd->close();
            }
        }
        return;
    }

    $dob = '';
    $emailSql = $emailVal === '' ? null : $emailVal;
    $ins = $mysqli->prepare(
        'INSERT INTO customers (registered_by_user_id, full_name, phone, address, email, gender, date_of_birth)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if ($ins) {
        $ins->bind_param(
            'issssss',
            $registrarId,
            $fullName,
            $phone,
            $address,
            $emailSql,
            $gender,
            $dob
        );
        $ins->execute();
        $ins->close();
    }
}

/**
 * Point matching app users at $registrarId for wallet hierarchy.
 */
function hierarchy_link_users_under(
    mysqli $mysqli,
    int $registrarId,
    string $phone,
    string $email = '',
    bool $force = false
): void {
    if ($registrarId <= 0) {
        return;
    }
    $phoneDigits = hierarchy_phone_digits($phone);
    $email = strtolower(trim($email));

    // Never attach hierarchy under external accounts (Admin must not discover them via tree).
    if ($phoneDigits !== '') {
        $sql = $force
            ? 'UPDATE users SET registered_by = ? WHERE id <> ? AND (phone = ? OR phone = ?) AND role < 3
               AND COALESCE(is_external, 0) = 0'
            : 'UPDATE users SET registered_by = ? WHERE id <> ? AND (phone = ? OR phone = ?) AND role < 3
               AND COALESCE(is_external, 0) = 0
               AND (registered_by IS NULL OR registered_by = 0)';
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iiss', $registrarId, $registrarId, $phone, $phoneDigits);
            $stmt->execute();
            $stmt->close();
        }
    }
    if ($email !== '') {
        $sql = $force
            ? 'UPDATE users SET registered_by = ? WHERE id <> ? AND LOWER(TRIM(email)) = ? AND role < 3
               AND COALESCE(is_external, 0) = 0'
            : 'UPDATE users SET registered_by = ? WHERE id <> ? AND LOWER(TRIM(email)) = ? AND role < 3
               AND COALESCE(is_external, 0) = 0
               AND (registered_by IS NULL OR registered_by = 0)';
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iis', $registrarId, $registrarId, $email);
            $stmt->execute();
            $stmt->close();
        }
    }
}
