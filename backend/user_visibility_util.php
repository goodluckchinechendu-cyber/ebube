<?php
/**
 * Internal vs external user visibility + wallet IDs.
 *
 * Rules:
 * - Super Admin: sees everyone; can set internal/external; sees wallet IDs.
 * - Admin / others: never see is_external labels; never see external users in
 *   manage-users lists; may fund external wallets only by wallet_id (no PII).
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
             ADD COLUMN `wallet_id` VARCHAR(16) NULL DEFAULT NULL AFTER `is_external`,
             ADD UNIQUE KEY `uniq_users_wallet_id` (`wallet_id`)"
        )) {
            return $mysqli->error ?: 'Could not add wallet_id';
        }
    }
    if ($wid) {
        $wid->free();
    }

    return null;
}

function user_generate_wallet_id(mysqli $mysqli): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 24; $attempt++) {
        $id = 'EC';
        for ($i = 0; $i < 8; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $check = $mysqli->prepare('SELECT id FROM users WHERE wallet_id = ? LIMIT 1');
        if (!$check) {
            return $id;
        }
        $check->bind_param('s', $id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) {
            return $id;
        }
    }
    return 'EC' . strtoupper(bin2hex(random_bytes(4)));
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
    if ($existing !== '') {
        return strtoupper($existing);
    }
    if ((int) ($row['is_external'] ?? 0) !== 1) {
        return null;
    }
    $fresh = user_generate_wallet_id($mysqli);
    $upd = $mysqli->prepare('UPDATE users SET wallet_id = ? WHERE id = ? AND (wallet_id IS NULL OR wallet_id = \'\')');
    if (!$upd) {
        return null;
    }
    $upd->bind_param('si', $fresh, $userId);
    if (!$upd->execute()) {
        $upd->close();
        return null;
    }
    $affected = $upd->affected_rows;
    $upd->close();
    if ($affected < 1) {
        // Another request may have set it — re-read.
        $again = $mysqli->prepare('SELECT wallet_id FROM users WHERE id = ? LIMIT 1');
        if (!$again) {
            return null;
        }
        $again->bind_param('i', $userId);
        $again->execute();
        $row2 = $again->get_result()->fetch_assoc();
        $again->close();
        $existing2 = strtoupper(trim((string) ($row2['wallet_id'] ?? '')));
        return $existing2 !== '' ? $existing2 : null;
    }
    return $fresh;
}

function user_row_is_external(array $row): bool
{
    return (int) ($row['is_external'] ?? 0) === 1;
}

/**
 * Normalize wallet id input (uppercase, strip spaces).
 */
function user_normalize_wallet_id(string $raw): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($raw)) ?? '');
}
