<?php
/**
 * Per-wallet (VTU / MoMo / Logical) transaction limit settings for Super Admin.
 * Defaults are global per product; optional rules override by account type or user.
 */

function ensure_product_transaction_limits_table(mysqli $mysqli): bool
{
    $ok = $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `product_transaction_limits` (
            `product_id` VARCHAR(32) NOT NULL,
            `max_per_transaction` DECIMAL(15, 2) NOT NULL DEFAULT 50000.00,
            `daily_limit` DECIMAL(15, 2) NOT NULL DEFAULT 1000000.00,
            `max_per_sim` DECIMAL(15, 2) NOT NULL DEFAULT 100000.00,
            `limit_days` INT UNSIGNED NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!$ok) {
        return false;
    }

    $col = $mysqli->query("SHOW COLUMNS FROM `product_transaction_limits` LIKE 'max_per_sim'");
    if ($col && $col->num_rows === 0) {
        $mysqli->query(
            "ALTER TABLE `product_transaction_limits`
             ADD COLUMN `max_per_sim` DECIMAL(15, 2) NOT NULL DEFAULT 100000.00
             AFTER `daily_limit`"
        );
    }
    if ($col) {
        $col->free();
    }

    $defaults = [
        ['vtu', 50000.0, 1000000.0, 100000.0, 1],
        ['momo', 50000.0, 1000000.0, 100000.0, 1],
        ['logical', 50000.0, 1000000.0, 100000.0, 1],
    ];
    $stmt = $mysqli->prepare(
        'INSERT IGNORE INTO product_transaction_limits
         (product_id, max_per_transaction, daily_limit, max_per_sim, limit_days)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }
    foreach ($defaults as $row) {
        $pid = $row[0];
        $maxOnce = $row[1];
        $daily = $row[2];
        $maxSim = $row[3];
        $days = $row[4];
        $stmt->bind_param('sdddi', $pid, $maxOnce, $daily, $maxSim, $days);
        $stmt->execute();
    }
    $stmt->close();
    return true;
}

function ensure_product_transaction_limit_rules_table(mysqli $mysqli): bool
{
    ensure_product_transaction_limits_table($mysqli);
    return (bool) $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `product_transaction_limit_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` VARCHAR(32) NOT NULL,
            `scope_type` VARCHAR(16) NOT NULL,
            `scope_key` VARCHAR(64) NOT NULL,
            `scope_role` TINYINT NULL,
            `scope_user_id` INT UNSIGNED NULL,
            `max_per_transaction` DECIMAL(15, 2) NOT NULL DEFAULT 50000.00,
            `daily_limit` DECIMAL(15, 2) NOT NULL DEFAULT 1000000.00,
            `max_per_sim` DECIMAL(15, 2) NOT NULL DEFAULT 100000.00,
            `limit_days` INT UNSIGNED NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_product_scope` (`product_id`, `scope_key`),
            KEY `idx_limit_user` (`scope_user_id`),
            KEY `idx_limit_role` (`scope_role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function product_limit_role_label(int $role): string
{
    switch ($role) {
        case 0:
            return 'Customer';
        case 1:
            return 'Agent';
        case 2:
            return 'Admin';
        case 3:
            return 'Super Admin';
        default:
            return 'Role ' . $role;
    }
}

/**
 * @return list<array{product_id:string,label:string,max_per_transaction:float,daily_limit:float,max_per_sim:float,limit_days:int}>
 */
function product_transaction_limits_list(mysqli $mysqli): array
{
    ensure_product_transaction_limits_table($mysqli);
    $labels = [
        'vtu' => 'VTU Airtime',
        'momo' => 'MoMo Airtime',
        'logical' => 'Logical Airtime',
    ];
    $out = [];
    $res = $mysqli->query(
        'SELECT product_id, max_per_transaction, daily_limit, max_per_sim, limit_days
         FROM product_transaction_limits
         ORDER BY FIELD(product_id, \'vtu\', \'momo\', \'logical\'), product_id'
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pid = (string) ($row['product_id'] ?? '');
            $out[] = [
                'product_id' => $pid,
                'label' => $labels[$pid] ?? strtoupper($pid),
                'max_per_transaction' => (float) ($row['max_per_transaction'] ?? 0),
                'daily_limit' => (float) ($row['daily_limit'] ?? 0),
                'max_per_sim' => (float) ($row['max_per_sim'] ?? 0),
                'limit_days' => (int) ($row['limit_days'] ?? 1),
            ];
        }
        $res->free();
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function product_transaction_limit_rules_list(mysqli $mysqli): array
{
    ensure_product_transaction_limit_rules_table($mysqli);
    $out = [];
    $sql = "SELECT r.id, r.product_id, r.scope_type, r.scope_key, r.scope_role, r.scope_user_id,
                   r.max_per_transaction, r.daily_limit, r.max_per_sim, r.limit_days,
                   u.full_name AS user_name, u.email AS user_email
            FROM product_transaction_limit_rules r
            LEFT JOIN users u ON u.id = r.scope_user_id
            ORDER BY r.product_id, r.scope_type, r.id";
    $res = $mysqli->query($sql);
    if (!$res) {
        return $out;
    }
    $labels = [
        'vtu' => 'VTU Airtime',
        'momo' => 'MoMo Airtime',
        'logical' => 'Logical Airtime',
    ];
    while ($row = $res->fetch_assoc()) {
        $pid = (string) ($row['product_id'] ?? '');
        $scopeType = (string) ($row['scope_type'] ?? '');
        $role = $row['scope_role'] !== null ? (int) $row['scope_role'] : null;
        $userId = $row['scope_user_id'] !== null ? (int) $row['scope_user_id'] : null;
        $scopeLabel = $scopeType === 'user'
            ? (trim((string) ($row['user_name'] ?? '')) !== ''
                ? trim((string) $row['user_name'])
                : ('User #' . ($userId ?? 0)))
            : product_limit_role_label((int) $role);
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'product_id' => $pid,
            'product_label' => $labels[$pid] ?? strtoupper($pid),
            'scope_type' => $scopeType,
            'scope_key' => (string) ($row['scope_key'] ?? ''),
            'scope_role' => $role,
            'scope_user_id' => $userId,
            'scope_label' => $scopeLabel,
            'user_email' => (string) ($row['user_email'] ?? ''),
            'max_per_transaction' => (float) ($row['max_per_transaction'] ?? 0),
            'daily_limit' => (float) ($row['daily_limit'] ?? 0),
            'max_per_sim' => (float) ($row['max_per_sim'] ?? 0),
            'limit_days' => (int) ($row['limit_days'] ?? 1),
        ];
    }
    $res->free();
    return $out;
}

/**
 * @return array{product_id:string,max_per_transaction:float,daily_limit:float,max_per_sim:float,limit_days:int}|null
 */
function product_transaction_limit_get(mysqli $mysqli, string $productId): ?array
{
    ensure_product_transaction_limits_table($mysqli);
    $productId = strtolower(trim($productId));
    $stmt = $mysqli->prepare(
        'SELECT product_id, max_per_transaction, daily_limit, max_per_sim, limit_days
         FROM product_transaction_limits WHERE product_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'product_id' => (string) $row['product_id'],
        'max_per_transaction' => (float) $row['max_per_transaction'],
        'daily_limit' => (float) $row['daily_limit'],
        'max_per_sim' => (float) ($row['max_per_sim'] ?? 0),
        'limit_days' => max(1, (int) $row['limit_days']),
    ];
}

/**
 * Resolve: specific user > account type > default product.
 *
 * @return array{product_id:string,max_per_transaction:float,daily_limit:float,max_per_sim:float,limit_days:int,source:string}|null
 */
function product_transaction_limit_resolve(
    mysqli $mysqli,
    string $productId,
    int $userId,
    int $userRole
): ?array {
    ensure_product_transaction_limit_rules_table($mysqli);
    $productId = strtolower(trim($productId));

    $stmt = $mysqli->prepare(
        "SELECT product_id, max_per_transaction, daily_limit, max_per_sim, limit_days
         FROM product_transaction_limit_rules
         WHERE product_id = ? AND scope_type = 'user' AND scope_user_id = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('si', $productId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'product_id' => (string) $row['product_id'],
                'max_per_transaction' => (float) $row['max_per_transaction'],
                'daily_limit' => (float) $row['daily_limit'],
                'max_per_sim' => (float) $row['max_per_sim'],
                'limit_days' => max(1, (int) $row['limit_days']),
                'source' => 'user',
            ];
        }
    }

    $stmt = $mysqli->prepare(
        "SELECT product_id, max_per_transaction, daily_limit, max_per_sim, limit_days
         FROM product_transaction_limit_rules
         WHERE product_id = ? AND scope_type = 'role' AND scope_role = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('si', $productId, $userRole);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'product_id' => (string) $row['product_id'],
                'max_per_transaction' => (float) $row['max_per_transaction'],
                'daily_limit' => (float) $row['daily_limit'],
                'max_per_sim' => (float) $row['max_per_sim'],
                'limit_days' => max(1, (int) $row['limit_days']),
                'source' => 'role',
            ];
        }
    }

    $base = product_transaction_limit_get($mysqli, $productId);
    if ($base === null) {
        return null;
    }
    $base['source'] = 'default';
    return $base;
}

function product_transaction_limit_save(
    mysqli $mysqli,
    string $productId,
    float $maxPerTransaction,
    float $dailyLimit,
    float $maxPerSim,
    int $limitDays
): bool {
    ensure_product_transaction_limits_table($mysqli);
    $productId = strtolower(trim($productId));
    if (!in_array($productId, ['vtu', 'momo', 'logical'], true)) {
        return false;
    }
    $limitDays = max(1, min(30, $limitDays));
    if ($maxPerTransaction < 0 || $dailyLimit < 0 || $maxPerSim < 0) {
        return false;
    }
    $stmt = $mysqli->prepare(
        'INSERT INTO product_transaction_limits
         (product_id, max_per_transaction, daily_limit, max_per_sim, limit_days)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           max_per_transaction = VALUES(max_per_transaction),
           daily_limit = VALUES(daily_limit),
           max_per_sim = VALUES(max_per_sim),
           limit_days = VALUES(limit_days)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sdddi', $productId, $maxPerTransaction, $dailyLimit, $maxPerSim, $limitDays);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function product_transaction_limit_rule_save(
    mysqli $mysqli,
    string $productId,
    string $scopeType,
    ?int $scopeRole,
    ?int $scopeUserId,
    float $maxPerTransaction,
    float $dailyLimit,
    float $maxPerSim,
    int $limitDays,
    int $ruleId = 0
): bool {
    ensure_product_transaction_limit_rules_table($mysqli);
    $productId = strtolower(trim($productId));
    $scopeType = strtolower(trim($scopeType));
    if (!in_array($productId, ['vtu', 'momo', 'logical'], true)) {
        return false;
    }
    if ($maxPerTransaction < 0 || $dailyLimit < 0 || $maxPerSim < 0) {
        return false;
    }
    $limitDays = max(1, min(30, $limitDays));

    if ($scopeType === 'role') {
        if ($scopeRole === null || $scopeRole < 0 || $scopeRole > 2) {
            return false;
        }
        $scopeKey = 'role-' . $scopeRole;
        $roleVal = $scopeRole;
        $userVal = null;
    } elseif ($scopeType === 'user') {
        if ($scopeUserId === null || $scopeUserId <= 0) {
            return false;
        }
        $scopeKey = 'user-' . $scopeUserId;
        $roleVal = null;
        $userVal = $scopeUserId;
    } else {
        return false;
    }

    $roleSql = $roleVal === null ? 'NULL' : (string) (int) $roleVal;
    $userSql = $userVal === null ? 'NULL' : (string) (int) $userVal;
    $productEsc = $mysqli->real_escape_string($productId);
    $typeEsc = $mysqli->real_escape_string($scopeType);
    $keyEsc = $mysqli->real_escape_string($scopeKey);
    $maxOnce = number_format($maxPerTransaction, 2, '.', '');
    $daily = number_format($dailyLimit, 2, '.', '');
    $maxSim = number_format($maxPerSim, 2, '.', '');
    $days = (int) $limitDays;

    // Editing an existing rule: update by id so changing product/scope doesn't orphan the old row.
    if ($ruleId > 0) {
        $exists = $mysqli->query('SELECT id FROM product_transaction_limit_rules WHERE id = ' . (int) $ruleId . ' LIMIT 1');
        $found = $exists && $exists->num_rows > 0;
        if ($exists) {
            $exists->free();
        }
        if ($found) {
            $sql = "UPDATE product_transaction_limit_rules SET
                      product_id = '{$productEsc}',
                      scope_type = '{$typeEsc}',
                      scope_key = '{$keyEsc}',
                      scope_role = {$roleSql},
                      scope_user_id = {$userSql},
                      max_per_transaction = {$maxOnce},
                      daily_limit = {$daily},
                      max_per_sim = {$maxSim},
                      limit_days = {$days}
                    WHERE id = " . (int) $ruleId . " LIMIT 1";
            return (bool) $mysqli->query($sql);
        }
    }

    $sql = "INSERT INTO product_transaction_limit_rules
            (product_id, scope_type, scope_key, scope_role, scope_user_id,
             max_per_transaction, daily_limit, max_per_sim, limit_days)
            VALUES ('{$productEsc}', '{$typeEsc}', '{$keyEsc}', {$roleSql}, {$userSql},
                    {$maxOnce}, {$daily}, {$maxSim}, {$days})
            ON DUPLICATE KEY UPDATE
              scope_type = VALUES(scope_type),
              scope_role = VALUES(scope_role),
              scope_user_id = VALUES(scope_user_id),
              max_per_transaction = VALUES(max_per_transaction),
              daily_limit = VALUES(daily_limit),
              max_per_sim = VALUES(max_per_sim),
              limit_days = VALUES(limit_days)";
    return (bool) $mysqli->query($sql);
}

function product_transaction_limit_rule_delete(mysqli $mysqli, int $ruleId): bool
{
    ensure_product_transaction_limit_rules_table($mysqli);
    if ($ruleId <= 0) {
        return false;
    }
    $stmt = $mysqli->prepare('DELETE FROM product_transaction_limit_rules WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $ruleId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function product_transaction_usage(
    mysqli $mysqli,
    int $userId,
    string $productId,
    int $limitDays
): ?float {
    $limitDays = max(1, $limitDays);
    $productId = strtolower(trim($productId));
    $stmt = $mysqli->prepare(
        "SELECT COALESCE(SUM(COALESCE(face_amount, amount)), 0) AS used
         FROM vtu_wallet_holds
         WHERE user_id = ?
           AND wallet_product = ?
           AND status IN ('held', 'completed')
           AND created_at >= (NOW() - INTERVAL ? DAY)"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('isi', $userId, $productId, $limitDays);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float) ($row['used'] ?? 0);
}

function product_sim_recharge_usage(
    mysqli $mysqli,
    string $phone,
    string $productId,
    int $limitDays
): ?float {
    $phone = preg_replace('/\D/', '', $phone) ?? '';
    if ($phone === '') {
        return 0.0;
    }
    $limitDays = max(1, $limitDays);
    $productId = strtolower(trim($productId));
    $stmt = $mysqli->prepare(
        "SELECT COALESCE(SUM(COALESCE(face_amount, amount)), 0) AS used
         FROM vtu_wallet_holds
         WHERE phone = ?
           AND wallet_product = ?
           AND status IN ('held', 'completed')
           AND created_at >= (NOW() - INTERVAL ? DAY)"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ssi', $phone, $productId, $limitDays);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float) ($row['used'] ?? 0);
}

function ensure_product_limit_scope_locks_table(mysqli $mysqli): bool
{
    return (bool) $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `product_limit_scope_locks` (
            `scope_key` VARCHAR(128) NOT NULL,
            PRIMARY KEY (`scope_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Lock user+wallet and (optional) phone+wallet scopes inside an open transaction.
 * Callers must begin_transaction() first. Locks release on commit/rollback.
 */
function product_limits_lock_scopes(
    mysqli $mysqli,
    int $userId,
    string $productId,
    string $phone = ''
): bool {
    if (!ensure_product_limit_scope_locks_table($mysqli)) {
        return false;
    }
    $productId = strtolower(trim($productId));
    $keys = ['u:' . $userId . ':' . $productId];
    $phoneDigits = preg_replace('/\D/', '', $phone) ?? '';
    if ($phoneDigits !== '') {
        $keys[] = 's:' . $phoneDigits . ':' . $productId;
    }
    sort($keys, SORT_STRING);
    foreach ($keys as $key) {
        $esc = $mysqli->real_escape_string($key);
        if (!$mysqli->query("INSERT IGNORE INTO product_limit_scope_locks (scope_key) VALUES ('{$esc}')")) {
            return false;
        }
        $stmt = $mysqli->prepare(
            'SELECT scope_key FROM product_limit_scope_locks WHERE scope_key = ? FOR UPDATE'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
    }
    return true;
}

/**
 * @return array{ok:bool,message?:string,max_per_transaction?:float,daily_limit?:float,max_per_sim?:float,used?:float,remaining?:float,sim_used?:float,sim_remaining?:float}
 */
function product_transaction_limits_check(
    mysqli $mysqli,
    int $userId,
    int $userRole,
    string $productId,
    float $faceAmount,
    string $phone = ''
): array {
    if ($userRole >= 3) {
        return ['ok' => true];
    }

    $limit = product_transaction_limit_resolve($mysqli, $productId, $userId, $userRole);
    if ($limit === null) {
        return ['ok' => true];
    }

    $maxOnce = (float) $limit['max_per_transaction'];
    $daily = (float) $limit['daily_limit'];
    $maxSim = (float) $limit['max_per_sim'];
    $days = (int) $limit['limit_days'];
    $period = $days === 1 ? 'daily' : ($days . '-day');

    if ($maxOnce > 0 && $faceAmount > $maxOnce + 0.001) {
        return [
            'ok' => false,
            'message' => sprintf(
                'Amount exceeds %s max per buy (₦%s)',
                strtoupper($productId),
                number_format($maxOnce, 2)
            ),
            'max_per_transaction' => $maxOnce,
            'daily_limit' => $daily,
            'max_per_sim' => $maxSim,
        ];
    }

    $used = 0.0;
    $remaining = $daily;
    if ($daily > 0) {
        $usedOrFail = product_transaction_usage($mysqli, $userId, $productId, $days);
        if ($usedOrFail === null) {
            return [
                'ok' => false,
                'message' => 'Could not verify wallet limit. Please try again.',
                'max_per_transaction' => $maxOnce,
                'daily_limit' => $daily,
                'max_per_sim' => $maxSim,
            ];
        }
        $used = $usedOrFail;
        $remaining = max(0.0, $daily - $used);
        if ($faceAmount > $remaining + 0.001) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Amount exceeds %s %s wallet limit. Used ₦%s of ₦%s',
                    strtoupper($productId),
                    $period,
                    number_format($used, 2),
                    number_format($daily, 2)
                ),
                'max_per_transaction' => $maxOnce,
                'daily_limit' => $daily,
                'max_per_sim' => $maxSim,
                'used' => $used,
                'remaining' => $remaining,
            ];
        }
    }

    $simUsed = 0.0;
    $simRemaining = $maxSim;
    $phoneDigits = preg_replace('/\D/', '', $phone) ?? '';
    if ($maxSim > 0 && $phoneDigits !== '') {
        $simUsedOrFail = product_sim_recharge_usage($mysqli, $phoneDigits, $productId, $days);
        if ($simUsedOrFail === null) {
            return [
                'ok' => false,
                'message' => 'Could not verify SIM recharge limit. Please try again.',
                'max_per_transaction' => $maxOnce,
                'daily_limit' => $daily,
                'max_per_sim' => $maxSim,
                'used' => $used,
                'remaining' => $remaining,
            ];
        }
        $simUsed = $simUsedOrFail;
        $simRemaining = max(0.0, $maxSim - $simUsed);
        if ($faceAmount > $simRemaining + 0.001) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'This number exceeds the %s max SIM recharge for %s. Used ₦%s of ₦%s',
                    $period,
                    strtoupper($productId),
                    number_format($simUsed, 2),
                    number_format($maxSim, 2)
                ),
                'max_per_transaction' => $maxOnce,
                'daily_limit' => $daily,
                'max_per_sim' => $maxSim,
                'used' => $used,
                'remaining' => $remaining,
                'sim_used' => $simUsed,
                'sim_remaining' => $simRemaining,
            ];
        }
    }

    return [
        'ok' => true,
        'max_per_transaction' => $maxOnce,
        'daily_limit' => $daily,
        'max_per_sim' => $maxSim,
        'used' => $used,
        'remaining' => $remaining,
        'sim_used' => $simUsed,
        'sim_remaining' => $simRemaining,
    ];
}
