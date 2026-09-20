<?php
/**
 * Ensures app tables exist (safe to call on every request).
 */
function ensure_app_tables(mysqli $mysqli): ?string
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `full_name` VARCHAR(255) NOT NULL,
            `phone` VARCHAR(50) NOT NULL DEFAULT '',
            `location` VARCHAR(255) NOT NULL DEFAULT '',
            `gender` VARCHAR(50) NOT NULL DEFAULT '',
            `email` VARCHAR(255) NOT NULL,
            `account_name` VARCHAR(255) NOT NULL DEFAULT '',
            `bank_name` VARCHAR(100) NOT NULL DEFAULT '',
            `account_number` VARCHAR(20) NOT NULL DEFAULT '',
            `last_seen_announcement_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `password_hash` VARCHAR(255) NOT NULL,
            `role` INT NOT NULL DEFAULT 0,
            `momo_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `vtu_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `logical_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `data_bundle_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `commission_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `data_bundle_prices` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `amount_naira` DECIMAL(15, 2) NOT NULL,
            `data_mb` DECIMAL(15, 2) NOT NULL,
            `is_active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `product_commissions` (
            `product_id` VARCHAR(32) NOT NULL,
            `commission_per_unit` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `withdrawal_requests` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `amount` DECIMAL(15, 2) NOT NULL,
            `account_name` VARCHAR(255) NOT NULL DEFAULT '',
            `bank_name` VARCHAR(100) NOT NULL DEFAULT '',
            `account_number` VARCHAR(20) NOT NULL DEFAULT '',
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `approved_at` DATETIME NULL,
            `credit_hours` INT UNSIGNED NULL,
            `expected_credit_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `announcements` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `created_by_name` VARCHAR(255) NOT NULL DEFAULT 'Admin',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `data_bundle_lots` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `lot_key` VARCHAR(64) NOT NULL,
            `megabytes` DECIMAL(15, 2) NOT NULL,
            `remaining_mb` DECIMAL(15, 2) NOT NULL,
            `converted_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_user_lot` (`user_id`, `lot_key`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `customers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `registered_by_user_id` INT UNSIGNED NOT NULL,
            `full_name` VARCHAR(255) NOT NULL,
            `phone` VARCHAR(50) NOT NULL,
            `address` VARCHAR(500) NOT NULL DEFAULT '',
            `email` VARCHAR(255) NULL,
            `gender` VARCHAR(50) NOT NULL,
            `date_of_birth` VARCHAR(32) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_registered_by` (`registered_by_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `agent_transactions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `receipt_id` VARCHAR(64) NOT NULL,
            `wallet_user_id` INT UNSIGNED NOT NULL,
            `customer_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `customer_name` VARCHAR(255) NOT NULL DEFAULT '',
            `product` VARCHAR(255) NOT NULL,
            `qty` INT NOT NULL DEFAULT 1,
            `total` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL DEFAULT 'Completed',
            `served_by` VARCHAR(255) NOT NULL DEFAULT '',
            `transaction_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_receipt` (`receipt_id`),
            KEY `idx_wallet_user` (`wallet_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `wallet_funding_history` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `receipt_id` VARCHAR(64) NOT NULL,
            `wallet_user_id` INT UNSIGNED NOT NULL,
            `user_name` VARCHAR(255) NOT NULL DEFAULT '',
            `wallet_product` VARCHAR(32) NOT NULL DEFAULT '',
            `wallet_name` VARCHAR(255) NOT NULL DEFAULT '',
            `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `funded_by` VARCHAR(255) NOT NULL DEFAULT '',
            `funded_by_user_id` INT UNSIGNED NULL DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'Active',
            `funded_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_funding_receipt` (`receipt_id`),
            KEY `idx_funding_wallet_user` (`wallet_user_id`),
            KEY `idx_funded_at` (`funded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `login_otp_challenges` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `challenge_id` CHAR(32) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `otp_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `verify_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `send_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `used_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_challenge_id` (`challenge_id`),
            KEY `idx_user_created` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vtu_webhook_events` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_name` VARCHAR(64) NOT NULL DEFAULT '',
            `reference` VARCHAR(64) NOT NULL DEFAULT '',
            `status` VARCHAR(32) NOT NULL DEFAULT '',
            `signature_ok` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `payload_json` MEDIUMTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vtu_webhook_ref` (`reference`),
            KEY `idx_vtu_webhook_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vtu_wallet_holds` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(128) NOT NULL,
            `client_request_id` VARCHAR(64) NULL DEFAULT NULL,
            `receipt_id` VARCHAR(64) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `wallet_product` VARCHAR(32) NOT NULL,
            `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
            `face_amount` DECIMAL(15, 2) NULL DEFAULT NULL,
            `product_label` VARCHAR(255) NOT NULL DEFAULT '',
            `phone` VARCHAR(32) NOT NULL DEFAULT '',
            `served_by` VARCHAR(255) NOT NULL DEFAULT '',
            `status` VARCHAR(32) NOT NULL DEFAULT 'held',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_hold_reference` (`reference`),
            UNIQUE KEY `uniq_hold_client_request` (`client_request_id`),
            KEY `idx_hold_user` (`user_id`),
            KEY `idx_hold_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `user_sessions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `revoked_at` DATETIME NULL,
            `last_seen_at` DATETIME NULL,
            `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_token_hash` (`token_hash`),
            KEY `idx_session_user` (`user_id`),
            KEY `idx_session_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $sql) {
        if (!$mysqli->query($sql)) {
            return $mysqli->error;
        }
    }

    $dataBundleCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'data_bundle_balance'");
    if ($dataBundleCol && $dataBundleCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
            ADD COLUMN `data_bundle_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `vtu_balance`,
            ADD COLUMN `commission_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `data_bundle_balance`"
        )) {
            return $mysqli->error;
        }
    }

    $accountNameCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'account_name'");
    if ($accountNameCol && $accountNameCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
            ADD COLUMN `account_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `email`,
            ADD COLUMN `bank_name` VARCHAR(100) NOT NULL DEFAULT '' AFTER `account_name`,
            ADD COLUMN `account_number` VARCHAR(20) NOT NULL DEFAULT '' AFTER `bank_name`"
        )) {
            return $mysqli->error;
        }
    }

    $bankCol = $mysqli->query("SHOW COLUMNS FROM `withdrawal_requests` LIKE 'bank_name'");
    if ($bankCol && $bankCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `withdrawal_requests`
            ADD COLUMN `bank_name` VARCHAR(100) NOT NULL DEFAULT '' AFTER `amount`,
            ADD COLUMN `account_number` VARCHAR(20) NOT NULL DEFAULT '' AFTER `bank_name`"
        )) {
            return $mysqli->error;
        }
    }

    $accountNameWr = $mysqli->query("SHOW COLUMNS FROM `withdrawal_requests` LIKE 'account_name'");
    if ($accountNameWr && $accountNameWr->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `withdrawal_requests`
            ADD COLUMN `account_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `amount`"
        )) {
            return $mysqli->error;
        }
    }

    $approvedCol = $mysqli->query("SHOW COLUMNS FROM `withdrawal_requests` LIKE 'approved_at'");
    if ($approvedCol && $approvedCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `withdrawal_requests`
            ADD COLUMN `approved_at` DATETIME NULL AFTER `status`,
            ADD COLUMN `credit_hours` INT UNSIGNED NULL AFTER `approved_at`,
            ADD COLUMN `expected_credit_at` DATETIME NULL AFTER `credit_hours`"
        )) {
            return $mysqli->error;
        }
    }

    $lastSeenCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'last_seen_announcement_id'");
    if ($lastSeenCol && $lastSeenCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
            ADD COLUMN `last_seen_announcement_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `account_number`"
        )) {
            return $mysqli->error;
        }
    }

    $fundedByUserCol = $mysqli->query("SHOW COLUMNS FROM `wallet_funding_history` LIKE 'funded_by_user_id'");
    if ($fundedByUserCol && $fundedByUserCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `wallet_funding_history`
            ADD COLUMN `funded_by_user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `funded_by`,
            ADD KEY `idx_funded_by_user` (`funded_by_user_id`)"
        )) {
            return $mysqli->error;
        }
    }

    $fundClientCol = $mysqli->query("SHOW COLUMNS FROM `wallet_funding_history` LIKE 'client_request_id'");
    if ($fundClientCol && $fundClientCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `wallet_funding_history`
            ADD COLUMN `client_request_id` VARCHAR(64) NULL DEFAULT NULL AFTER `receipt_id`,
            ADD UNIQUE KEY `uniq_funding_client_request` (`client_request_id`)"
        )) {
            return $mysqli->error;
        }
    }

    $logicalCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'logical_balance'");
    if ($logicalCol && $logicalCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
            ADD COLUMN `logical_balance` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `vtu_balance`"
        )) {
            return $mysqli->error;
        }
    }

    $logicalCommission = $mysqli->query(
        "SELECT product_id FROM product_commissions WHERE product_id = 'logical' LIMIT 1"
    );
    if ($logicalCommission && $logicalCommission->num_rows === 0) {
        $mysqli->query(
            "INSERT INTO product_commissions (product_id, commission_per_unit) VALUES ('logical', 5)"
        );
    }

    // Receipt IDs from SMobile references can exceed 32 chars.
    $receiptLen = $mysqli->query(
        "SHOW COLUMNS FROM `agent_transactions` LIKE 'receipt_id'"
    );
    if ($receiptLen && ($col = $receiptLen->fetch_assoc())) {
        $type = strtolower((string) ($col['Type'] ?? ''));
        if (preg_match('/varchar\((\d+)\)/', $type, $m) && (int) $m[1] < 64) {
            if (!$mysqli->query(
                "ALTER TABLE `agent_transactions` MODIFY `receipt_id` VARCHAR(64) NOT NULL"
            )) {
                return $mysqli->error;
            }
        }
    }

    foreach ([
        'phone' => "VARCHAR(32) NOT NULL DEFAULT '' AFTER `customer_name`",
        'network' => "VARCHAR(32) NOT NULL DEFAULT 'MTN' AFTER `phone`",
    ] as $colName => $def) {
        $chk = $mysqli->query("SHOW COLUMNS FROM `agent_transactions` LIKE '$colName'");
        if ($chk && $chk->num_rows === 0) {
            if (!$mysqli->query("ALTER TABLE `agent_transactions` ADD COLUMN `$colName` $def")) {
                return $mysqli->error;
            }
        }
    }

    $emailVerifiedCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'email_verified_at'");
    if ($emailVerifiedCol && $emailVerifiedCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
             ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL AFTER `email`"
        )) {
            return $mysqli->error;
        }
        // Existing accounts are treated as already verified.
        $mysqli->query(
            'UPDATE users SET email_verified_at = COALESCE(created_at, NOW()) WHERE email_verified_at IS NULL'
        );
    }

    $txnPinCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'transaction_pin_hash'");
    if ($txnPinCol && $txnPinCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
             ADD COLUMN `transaction_pin_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `password_hash`"
        )) {
            return $mysqli->error;
        }
    }

    // Email verification challenges (registration / unverified login).
    if (!$mysqli->query(
        "CREATE TABLE IF NOT EXISTS `email_verification_challenges` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `challenge_id` CHAR(32) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `otp_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `verify_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `send_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `used_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_email_challenge` (`challenge_id`),
            KEY `idx_email_verify_user` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )) {
        return $mysqli->error;
    }

    // Ensure VTU commission tier tables exist.
    if (!function_exists('ensure_commission_tiers_tables')) {
        require_once __DIR__ . '/commission_tiers_util.php';
    }
    if (!ensure_commission_tiers_tables($mysqli)) {
        return $mysqli->error ?: 'Could not create commission tier tables';
    }

    if (!function_exists('ensure_product_transaction_limits_table')) {
        require_once __DIR__ . '/transaction_limits_util.php';
    }
    if (!ensure_product_transaction_limits_table($mysqli)) {
        return $mysqli->error ?: 'Could not create transaction limits table';
    }
    if (!function_exists('ensure_product_transaction_limit_rules_table')) {
        require_once __DIR__ . '/transaction_limits_util.php';
    }
    if (!ensure_product_transaction_limit_rules_table($mysqli)) {
        return $mysqli->error ?: 'Could not create transaction limit rules table';
    }
    if (!function_exists('ensure_product_limit_scope_locks_table')) {
        require_once __DIR__ . '/transaction_limits_util.php';
    }
    if (!ensure_product_limit_scope_locks_table($mysqli)) {
        return $mysqli->error ?: 'Could not create limit scope locks table';
    }

    // Invite / registration hierarchy.
    $regByCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'registered_by'");
    if ($regByCol && $regByCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
             ADD COLUMN `registered_by` INT UNSIGNED NULL DEFAULT NULL AFTER `role`,
             ADD KEY `idx_users_registered_by` (`registered_by`)"
        )) {
            return $mysqli->error;
        }
    }

    $refCodeCol = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'referral_code'");
    if ($refCodeCol && $refCodeCol->num_rows === 0) {
        if (!$mysqli->query(
            "ALTER TABLE `users`
             ADD COLUMN `referral_code` VARCHAR(32) NULL DEFAULT NULL AFTER `registered_by`,
             ADD UNIQUE KEY `uniq_users_referral_code` (`referral_code`)"
        )) {
            return $mysqli->error;
        }
    }

    require_once __DIR__ . '/user_visibility_util.php';
    $visErr = ensure_user_visibility_columns($mysqli);
    if ($visErr !== null) {
        return $visErr;
    }

    if (!$mysqli->query(
        "CREATE TABLE IF NOT EXISTS `wallet_transfers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(64) NOT NULL,
            `from_user_id` INT UNSIGNED NOT NULL,
            `to_user_id` INT UNSIGNED NOT NULL,
            `wallet_product` VARCHAR(32) NOT NULL DEFAULT 'vtu',
            `amount` DECIMAL(15, 2) NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'completed',
            `note` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_wallet_transfer_ref` (`reference`),
            KEY `idx_wt_from` (`from_user_id`, `created_at`),
            KEY `idx_wt_to` (`to_user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )) {
        return $mysqli->error;
    }

    return null;
}
