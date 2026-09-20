<?php
/**
 * Commission & Discount tiers for airtime/data.
 * Ebube rules control what is debited from injected wallets (face − discount).
 * SMobile's own billed amount does NOT adjust the injected wallet.
 * Commission (if set) credits the buyer's commission_balance on success.
 */

function ensure_commission_tiers_tables(mysqli $mysqli): bool
{
    $tiers = $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `vtu_commission_tiers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_type` VARCHAR(16) NOT NULL,
            `applies_to` VARCHAR(32) NOT NULL DEFAULT 'all',
            `min_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `max_amount` DECIMAL(15, 2) NULL DEFAULT NULL,
            `commission_type` VARCHAR(16) NOT NULL DEFAULT 'fixed',
            `commission_value` DECIMAL(15, 4) NOT NULL DEFAULT 0,
            `discount_type` VARCHAR(16) NOT NULL DEFAULT 'fixed',
            `discount_value` DECIMAL(15, 4) NOT NULL DEFAULT 0,
            `is_active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vtu_comm_product` (`product_type`, `is_active`, `min_amount`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if ($tiers !== true) {
        return false;
    }

    // Migrate older installs that only had commission columns.
    $cols = [
        'applies_to' => "ALTER TABLE `vtu_commission_tiers`
            ADD COLUMN `applies_to` VARCHAR(32) NOT NULL DEFAULT 'all' AFTER `product_type`",
        'discount_type' => "ALTER TABLE `vtu_commission_tiers`
            ADD COLUMN `discount_type` VARCHAR(16) NOT NULL DEFAULT 'fixed' AFTER `commission_value`",
        'discount_value' => "ALTER TABLE `vtu_commission_tiers`
            ADD COLUMN `discount_value` DECIMAL(15, 4) NOT NULL DEFAULT 0 AFTER `discount_type`",
    ];
    foreach ($cols as $name => $sql) {
        $check = $mysqli->query("SHOW COLUMNS FROM `vtu_commission_tiers` LIKE '$name'");
        if ($check && $check->num_rows === 0) {
            if (!$mysqli->query($sql)) {
                return false;
            }
        }
    }

    $ledger = $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `vtu_commission_credits` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(128) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `product_type` VARCHAR(16) NOT NULL,
            `sale_amount` DECIMAL(15, 2) NOT NULL,
            `commission_amount` DECIMAL(15, 2) NOT NULL,
            `tier_id` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_vtu_comm_ref` (`reference`),
            KEY `idx_vtu_comm_user` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    return $ledger === true;
}

function commission_applies_to_label(string $appliesTo): string
{
    return match (strtolower(trim($appliesTo))) {
        'agent' => 'Agents',
        'admin' => 'Admins',
        'super_admin' => 'Super Admins',
        default => 'All accounts',
    };
}

function commission_applies_to_matches(string $appliesTo, int $role): bool
{
    $appliesTo = strtolower(trim($appliesTo));
    if ($appliesTo === '' || $appliesTo === 'all') {
        return true;
    }
    return match ($appliesTo) {
        'agent' => $role === 1,
        'admin' => $role === 2,
        'super_admin' => $role === 3,
        default => true,
    };
}

/**
 * @return list<array<string,mixed>>
 */
function commission_tiers_list(mysqli $mysqli, ?string $productType = null): array
{
    ensure_commission_tiers_tables($mysqli);
    if ($productType !== null && $productType !== '') {
        $stmt = $mysqli->prepare(
            'SELECT * FROM vtu_commission_tiers
             WHERE product_type = ?
             ORDER BY product_type ASC, min_amount ASC, id ASC'
        );
        $stmt->bind_param('s', $productType);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = commission_tier_row($row);
        }
        $stmt->close();
        return $rows;
    }

    $res = $mysqli->query(
        'SELECT * FROM vtu_commission_tiers
         ORDER BY product_type ASC, min_amount ASC, id ASC'
    );
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = commission_tier_row($row);
        }
    }
    return $rows;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function commission_tier_row(array $row): array
{
    $max = $row['max_amount'] ?? null;
    return [
        'id' => (int) $row['id'],
        'product_type' => (string) $row['product_type'],
        'applies_to' => (string) ($row['applies_to'] ?? 'all'),
        'applies_to_label' => commission_applies_to_label((string) ($row['applies_to'] ?? 'all')),
        'min_amount' => (float) $row['min_amount'],
        'max_amount' => $max === null || $max === '' ? null : (float) $max,
        'commission_type' => (string) ($row['commission_type'] ?? 'fixed'),
        'commission_value' => (float) ($row['commission_value'] ?? 0),
        'discount_type' => (string) ($row['discount_type'] ?? 'fixed'),
        'discount_value' => (float) ($row['discount_value'] ?? 0),
        'is_active' => (int) ($row['is_active'] ?? 1) === 1,
    ];
}

/**
 * @return array{
 *   tier:array,
 *   discount:float,
 *   commission:float,
 *   wallet_charge:float
 * }|null
 */
function resolve_vtu_pricing(
    mysqli $mysqli,
    string $productType,
    float $faceAmount,
    int $role = 1
): ?array {
    ensure_commission_tiers_tables($mysqli);
    $productType = strtolower(trim($productType));
    if ($faceAmount <= 0 || ($productType !== 'airtime' && $productType !== 'data')) {
        return null;
    }

    $stmt = $mysqli->prepare(
        'SELECT * FROM vtu_commission_tiers
         WHERE product_type = ? AND is_active = 1
           AND min_amount <= ?
           AND (max_amount IS NULL OR max_amount >= ?)
         ORDER BY min_amount DESC, id DESC'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('sdd', $productType, $faceAmount, $faceAmount);
    $stmt->execute();
    $res = $stmt->get_result();

    $best = null;
    $bestSpan = null;
    $bestSpecificity = -1;
    while ($row = $res->fetch_assoc()) {
        $applies = (string) ($row['applies_to'] ?? 'all');
        if (!commission_applies_to_matches($applies, $role)) {
            continue;
        }
        $min = (float) $row['min_amount'];
        $maxRaw = $row['max_amount'];
        $max = ($maxRaw === null || $maxRaw === '') ? 999999999.0 : (float) $maxRaw;
        $span = $max - $min;
        // Prefer role-specific tiers over "all", then narrowest amount range.
        $specificity = ($applies === 'all' || $applies === '') ? 0 : 1;
        if (
            $best === null
            || $specificity > $bestSpecificity
            || ($specificity === $bestSpecificity && $span < $bestSpan)
        ) {
            $best = $row;
            $bestSpan = $span;
            $bestSpecificity = $specificity;
        }
    }
    $stmt->close();
    if ($best === null) {
        return null;
    }

    $tier = commission_tier_row($best);
    $amounts = compute_vtu_tier_amounts($faceAmount, $best);

    return [
        'tier' => $tier,
        'discount' => $amounts['discount'],
        'commission' => $amounts['commission'],
        'wallet_charge' => $amounts['wallet_charge'],
    ];
}

/**
 * Pure pricing math (face → discount / commission / wallet charge).
 *
 * @param array<string,mixed> $tierRow
 * @return array{discount:float,commission:float,wallet_charge:float}
 */
function compute_vtu_tier_amounts(float $faceAmount, array $tierRow): array
{
    $discountType = strtolower((string) ($tierRow['discount_type'] ?? 'fixed'));
    $discountValue = (float) ($tierRow['discount_value'] ?? 0);
    $discount = $discountType === 'percent'
        ? round($faceAmount * ($discountValue / 100.0), 2)
        : round($discountValue, 2);
    if ($discount < 0) {
        $discount = 0.0;
    }
    if ($discount > $faceAmount) {
        $discount = $faceAmount;
    }

    $commissionType = strtolower((string) ($tierRow['commission_type'] ?? 'fixed'));
    $commissionValue = (float) ($tierRow['commission_value'] ?? 0);
    $commission = $commissionType === 'percent'
        ? round($faceAmount * ($commissionValue / 100.0), 2)
        : round($commissionValue, 2);
    if ($commission < 0) {
        $commission = 0.0;
    }

    return [
        'discount' => $discount,
        'commission' => $commission,
        'wallet_charge' => round(max(0.0, $faceAmount - $discount), 2),
    ];
}

/**
 * Whether two amount ranges overlap (NULL/empty max = unbounded).
 */
function commission_ranges_overlap(
    float $minA,
    ?float $maxA,
    float $minB,
    ?float $maxB
): bool {
    $hiA = $maxA === null ? PHP_FLOAT_MAX : $maxA;
    $hiB = $maxB === null ? PHP_FLOAT_MAX : $maxB;
    return $minA <= $hiB && $minB <= $hiA;
}

/**
 * Find active tiers that conflict with a proposed rule (same product + overlapping
 * audience + overlapping amount range).
 *
 * @return list<array>
 */
function commission_tier_find_overlaps(
    mysqli $mysqli,
    string $productType,
    string $appliesTo,
    float $minAmount,
    ?float $maxAmount,
    int $excludeId = 0
): array {
    ensure_commission_tiers_tables($mysqli);
    $stmt = $mysqli->prepare(
        'SELECT * FROM vtu_commission_tiers
         WHERE product_type = ? AND is_active = 1 AND id <> ?'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('si', $productType, $excludeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $overlaps = [];
    while ($row = $res->fetch_assoc()) {
        $otherApplies = strtolower((string) ($row['applies_to'] ?? 'all'));
        // Conflict when audiences can both match the same buyer.
        $audienceConflict = $appliesTo === 'all'
            || $otherApplies === 'all'
            || $appliesTo === $otherApplies;
        if (!$audienceConflict) {
            continue;
        }
        $otherMin = (float) $row['min_amount'];
        $otherMaxRaw = $row['max_amount'];
        $otherMax = ($otherMaxRaw === null || $otherMaxRaw === '')
            ? null
            : (float) $otherMaxRaw;
        if (commission_ranges_overlap($minAmount, $maxAmount, $otherMin, $otherMax)) {
            $overlaps[] = commission_tier_row($row);
        }
    }
    $stmt->close();
    return $overlaps;
}

/**
 * Backward-compatible wrapper.
 * @return array{tier:array,commission:float}|null
 */
function resolve_vtu_commission(mysqli $mysqli, string $productType, float $saleAmount, int $role = 1): ?array
{
    $pricing = resolve_vtu_pricing($mysqli, $productType, $saleAmount, $role);
    if ($pricing === null || (float) $pricing['commission'] <= 0) {
        return null;
    }
    return [
        'tier' => $pricing['tier'],
        'commission' => (float) $pricing['commission'],
    ];
}

/**
 * Idempotent credit keyed by provider reference.
 *
 * @return array{credited:bool,amount:float,message?:string}
 */
function credit_vtu_commission(
    mysqli $mysqli,
    int $userId,
    string $productType,
    float $saleAmount,
    string $reference,
    int $role = 1
): array {
    ensure_commission_tiers_tables($mysqli);
    $reference = trim($reference);
    if ($userId <= 0 || $reference === '') {
        return ['credited' => false, 'amount' => 0.0, 'message' => 'Missing user/reference'];
    }

    $check = $mysqli->prepare(
        'SELECT commission_amount FROM vtu_commission_credits WHERE reference = ? LIMIT 1'
    );
    if ($check) {
        $check->bind_param('s', $reference);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing) {
            return [
                'credited' => false,
                'amount' => (float) $existing['commission_amount'],
                'message' => 'Already credited',
            ];
        }
    }

    $resolved = resolve_vtu_commission($mysqli, $productType, $saleAmount, $role);
    if ($resolved === null) {
        return ['credited' => false, 'amount' => 0.0, 'message' => 'No matching commission'];
    }

    $amount = (float) $resolved['commission'];
    $tierId = (int) ($resolved['tier']['id'] ?? 0);

    $mysqli->begin_transaction();
    try {
        $ins = $mysqli->prepare(
            'INSERT INTO vtu_commission_credits
            (reference, user_id, product_type, sale_amount, commission_amount, tier_id)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('Could not prepare commission ledger insert');
        }
        $ins->bind_param('sisddi', $reference, $userId, $productType, $saleAmount, $amount, $tierId);
        if (!$ins->execute()) {
            if ($mysqli->errno === 1062) {
                $ins->close();
                $mysqli->rollback();
                return ['credited' => false, 'amount' => $amount, 'message' => 'Already credited'];
            }
            throw new RuntimeException($ins->error);
        }
        $ins->close();

        $upd = $mysqli->prepare(
            'UPDATE users SET commission_balance = commission_balance + ? WHERE id = ?'
        );
        if (!$upd) {
            throw new RuntimeException('Could not prepare commission balance update');
        }
        $upd->bind_param('di', $amount, $userId);
        if (!$upd->execute()) {
            throw new RuntimeException($upd->error);
        }
        $upd->close();
        $mysqli->commit();
        return ['credited' => true, 'amount' => $amount, 'tier' => $resolved['tier']];
    } catch (Throwable $e) {
        $mysqli->rollback();
        return ['credited' => false, 'amount' => 0.0, 'message' => $e->getMessage()];
    }
}

function vtu_product_type_from_label(string $label): string
{
    $l = strtolower(trim($label));
    if (str_starts_with($l, 'data')) {
        return 'data';
    }
    return 'airtime';
}
