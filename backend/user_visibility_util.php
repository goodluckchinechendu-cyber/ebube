<?php
/**
 * Internal vs external user visibility + wallet IDs.
 *
 * Rules:
 * - Super Admin: sees everyone; can set internal/external; sees wallet IDs.
 * - Super Admin invite links default to external registration (optional vis=int).
 * - Admin / others: never see is_external labels; never see external users in
 *   manage-users lists; may fund external wallets only by wallet_id.
 * - When funding/history involves an external wallet by ID, Admin may see
 *   the account name together with the Wallet ID (no Internal/External label).
 *
 * Wallet IDs intentionally vary in length and pattern so they do not look like
 * sequential product codes from one system.
 */

function ensure_user_visibility_columns(mysqli $mysqli): ?string
{
    $ext = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'is_external'");
    if ($ext && $ext->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
             ADD COLUMN `is_external` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `role`,
             ADD KEY `idx_users_is_external` (`is_external`)"
        )) {
            return $mysqli->error ?: 'Could not add is_external';
        }
    }
    if ($ext) {
        $ext->free();
    }

    $wid = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'wallet_id'");
    if ($wid && $wid->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
             ADD COLUMN `wallet_id` VARCHAR(32) NULL DEFAULT NULL AFTER `is_external`,
             ADD UNIQUE KEY `uniq_users_wallet_id` (`wallet_id`)"
        )) {
            return $mysqli->error ?: 'Could not add wallet_id';
        }
    } elseif ($wid && $wid->num_rows > 0) {
        $meta = $wid->fetch_assoc();
        $type = strtolower((string) ($meta['Type'] ?? ''));
        // Widen older VARCHAR(16) installs so varied lengths fit.
        if (preg_match('/varchar\((\d+)\)/', $type, $m) && (int) $m[1] < 32) {
            if (!$mysqli->query(
                "ALTER TABLE `users` MODIFY COLUMN `wallet_id` VARCHAR(32) NULL DEFAULT NULL"
            )) {
                return $mysqli->error ?: 'Could not widen wallet_id';
            }
        }
        // Repair missing unique index on existing installs.
        $idx = $mysqli->query("SHOW INDEX FROM `users` WHERE Key_name = 'uniq_users_wallet_id'");
        if ($idx && $idx->num_rows === 0) {
            if (!$mysqli->query(
                "ALTER TABLE `users` ADD UNIQUE KEY `uniq_users_wallet_id` (`wallet_id`)"
            )) {
                // Non-fatal if duplicates already exist.
            }
        }
        if ($idx) {
            $idx->free();
        }
    }
    if ($wid) {
        $wid->free();
    }

    return null;
}

/**
 * Ambiguity-safe alphabet (no 0/O, 1/I/L).
 */
function user_wallet_id_alphabet(bool $letters, bool $digits, bool $mixedCase = false): string
{
    $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $nums = '23456789';
    $out = '';
    if ($letters) {
        $out .= $upper;
        if ($mixedCase) {
            $out .= $lower;
        }
    }
    if ($digits) {
        $out .= $nums;
    }
    return $out !== '' ? $out : ($upper . $nums);
}

function user_wallet_id_pick(string $alphabet, int $len): string
{
    $n = strlen($alphabet);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $n - 1)];
    }
    return $out;
}

/**
 * True when an ID still uses the old fixed "EC########" product pattern
 * (including the rare hex fallback that could contain 0/1).
 */
function user_wallet_id_is_legacy_format(string $id): bool
{
    return (bool) preg_match('/^EC[A-Z0-9]{8}$/', strtoupper(trim($id)));
}

/**
 * Build one candidate wallet ID with a randomly chosen human-looking style.
 */
function user_wallet_id_candidate(): string
{
    $style = random_int(0, 7);
    switch ($style) {
        case 0:
            // Short mixed alnum, e.g. k7Qm2xP9
            return user_wallet_id_pick(user_wallet_id_alphabet(true, true, true), random_int(8, 10));
        case 1:
            // Mid length uppercase alnum, e.g. H4K9MP2X7Q
            return user_wallet_id_pick(user_wallet_id_alphabet(true, true, false), random_int(11, 13));
        case 2:
            // Longer mixed, e.g. pQ84mK2nX9aB7
            return user_wallet_id_pick(user_wallet_id_alphabet(true, true, true), random_int(14, 18));
        case 3:
            // Letter cluster + digits, e.g. WMX482917
            return user_wallet_id_pick(user_wallet_id_alphabet(true, false, false), random_int(2, 4))
                . user_wallet_id_pick(user_wallet_id_alphabet(false, true), random_int(5, 8));
        case 4:
            // Digits + letter tail, e.g. 8392047KP
            return user_wallet_id_pick(user_wallet_id_alphabet(false, true), random_int(6, 9))
                . user_wallet_id_pick(user_wallet_id_alphabet(true, false, false), random_int(2, 4));
        case 5:
            // Hyphenated mid, e.g. 7K2M-83914X
            $a = user_wallet_id_pick(user_wallet_id_alphabet(true, true, true), random_int(3, 5));
            $b = user_wallet_id_pick(user_wallet_id_alphabet(true, true, false), random_int(5, 8));
            return $a . '-' . $b;
        case 6:
            // Starts with digit (account-like), e.g. 4xK8291mP
            return user_wallet_id_pick(user_wallet_id_alphabet(false, true), 1)
                . user_wallet_id_pick(user_wallet_id_alphabet(true, true, true), random_int(7, 11));
        default:
            // Two segments with no separator of different alphabets
            return user_wallet_id_pick(user_wallet_id_alphabet(true, false, true), random_int(3, 5))
                . user_wallet_id_pick(user_wallet_id_alphabet(false, true), random_int(4, 7))
                . user_wallet_id_pick(user_wallet_id_alphabet(true, false, false), random_int(2, 3));
    }
}

function user_generate_wallet_id(mysqli $mysqli): string
{
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $id = user_wallet_id_candidate();
        // Must include both a letter and a digit so they never look purely numeric or purely alpha.
        if (!preg_match('/[A-Za-z]/', $id) || !preg_match('/[0-9]/', $id)) {
            continue;
        }
        if (strlen($id) < 8 || strlen($id) > 24) {
            continue;
        }
        // Never emit the old fixed product pattern — those are auto-rotated as "legacy".
        if (user_wallet_id_is_legacy_format($id)) {
            continue;
        }
        if (!user_wallet_id_is_available($mysqli, $id)) {
            continue;
        }
        return $id;
    }
    // Last-resort unique value — still mixed alnum, never legacy length/prefix.
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $id = user_wallet_id_pick(user_wallet_id_alphabet(true, true, true), 6)
            . substr(bin2hex(random_bytes(5)), 0, 10);
        if (user_wallet_id_is_legacy_format($id)) {
            continue;
        }
        if (user_wallet_id_is_available($mysqli, $id)) {
            return $id;
        }
    }
    return 'w'
        . user_wallet_id_pick(user_wallet_id_alphabet(true, true, true), 5)
        . substr(bin2hex(random_bytes(6)), 0, 12);
}

function user_wallet_id_is_available(mysqli $mysqli, string $id): bool
{
    $check = $mysqli->prepare(
        'SELECT id FROM users WHERE UPPER(TRIM(wallet_id)) = UPPER(?) LIMIT 1'
    );
    if (!$check) {
        return true;
    }
    $check->bind_param('s', $id);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    return !$exists;
}

function user_ensure_wallet_id(mysqli $mysqli, int $userId): ?string
{
    $stmt = $mysqli->prepare(
        'SELECT wallet_id, is_external FROM users WHERE id = ? LIMIT 1'
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
    $existing = trim((string) ($row['wallet_id'] ?? ''));
    if ($existing !== '' && !user_wallet_id_is_legacy_format($existing)) {
        return $existing;
    }
    if ((int) ($row['is_external'] ?? 0) !== 1) {
        return $existing !== '' ? $existing : null;
    }
    $fresh = user_generate_wallet_id($mysqli);
    if ($existing === '') {
        $upd = $mysqli->prepare(
            'UPDATE users SET wallet_id = ? WHERE id = ? AND (wallet_id IS NULL OR wallet_id = \'\')'
        );
        if (!$upd) {
            return null;
        }
        $upd->bind_param('si', $fresh, $userId);
    } else {
        $upd = $mysqli->prepare(
            'UPDATE users SET wallet_id = ? WHERE id = ? AND UPPER(TRIM(wallet_id)) = UPPER(?)'
        );
        if (!$upd) {
            return null;
        }
        $upd->bind_param('sis', $fresh, $userId, $existing);
    }
    if (!$upd->execute()) {
        $upd->close();
        return null;
    }
    $affected = $upd->affected_rows;
    $upd->close();
    if ($affected < 1) {
        $again = $mysqli->prepare('SELECT wallet_id FROM users WHERE id = ? LIMIT 1');
        if (!$again) {
            return null;
        }
        $again->bind_param('i', $userId);
        $again->execute();
        $row2 = $again->get_result()->fetch_assoc();
        $again->close();
        $existing2 = trim((string) ($row2['wallet_id'] ?? ''));
        return $existing2 !== '' ? $existing2 : null;
    }
    return $fresh;
}

function user_row_is_external(array $row): bool
{
    return (int) ($row['is_external'] ?? 0) === 1;
}

/**
 * Normalize wallet id input for lookup (strip spaces; preserve case for storage compare via UPPER).
 */
function user_normalize_wallet_id(string $raw): string
{
    return preg_replace('/\s+/', '', trim($raw)) ?? '';
}

/**
 * Label for an external wallet shown to Admin (name + Wallet ID, no visibility tag).
 */
function user_external_display_label(string $fullName, string $walletId): string
{
    $name = trim($fullName);
    $wid = trim($walletId);
    if ($name !== '' && $wid !== '') {
        return $name . ' · Wallet ' . $wid;
    }
    if ($wid !== '') {
        return 'Wallet ' . $wid;
    }
    return $name !== '' ? $name : 'Wallet';
}
