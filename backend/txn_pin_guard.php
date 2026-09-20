<?php
/**
 * Soft lockout for wrong transaction PINs.
 * After TXN_PIN_MAX_FAILURES failures → locked for TXN_PIN_LOCK_MINUTES.
 */

const TXN_PIN_MAX_FAILURES = 5;
const TXN_PIN_LOCK_MINUTES = 15;

function ensure_txn_pin_guard_table(mysqli $mysqli): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS `txn_pin_attempts` (
        `user_id` INT UNSIGNED NOT NULL,
        `fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `locked_until` DATETIME NULL DEFAULT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    return (bool) $mysqli->query($sql);
}

/**
 * @return array{ok:bool,message?:string,locked_until?:string,remaining_attempts?:int}
 */
function txn_pin_guard_status(mysqli $mysqli, int $userId): array
{
    ensure_txn_pin_guard_table($mysqli);
    $stmt = $mysqli->prepare(
        'SELECT fail_count, locked_until FROM txn_pin_attempts WHERE user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => true];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return ['ok' => true, 'remaining_attempts' => TXN_PIN_MAX_FAILURES];
    }

    $lockedUntil = $row['locked_until'] ?? null;
    if ($lockedUntil !== null && $lockedUntil !== '' && strtotime((string) $lockedUntil) > time()) {
        return [
            'ok' => false,
            'message' => 'Transaction PIN temporarily locked after too many wrong attempts. Try again after '
                . date('g:i A', strtotime((string) $lockedUntil)) . '.',
            'locked_until' => (string) $lockedUntil,
            'remaining_attempts' => 0,
        ];
    }

    $fails = (int) ($row['fail_count'] ?? 0);
    return [
        'ok' => true,
        'remaining_attempts' => max(0, TXN_PIN_MAX_FAILURES - $fails),
    ];
}

function txn_pin_guard_clear(mysqli $mysqli, int $userId): void
{
    ensure_txn_pin_guard_table($mysqli);
    $stmt = $mysqli->prepare(
        'INSERT INTO txn_pin_attempts (user_id, fail_count, locked_until)
         VALUES (?, 0, NULL)
         ON DUPLICATE KEY UPDATE fail_count = 0, locked_until = NULL'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array{ok:bool,message:string,locked:bool,remaining_attempts:int}
 */
function txn_pin_guard_fail(mysqli $mysqli, int $userId): array
{
    ensure_txn_pin_guard_table($mysqli);
    $status = txn_pin_guard_status($mysqli, $userId);
    if (!$status['ok']) {
        return [
            'ok' => false,
            'message' => (string) ($status['message'] ?? 'Transaction PIN locked'),
            'locked' => true,
            'remaining_attempts' => 0,
        ];
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO txn_pin_attempts (user_id, fail_count, locked_until)
         VALUES (?, 1, NULL)
         ON DUPLICATE KEY UPDATE
           fail_count = IF(locked_until IS NOT NULL AND locked_until > NOW(), fail_count, fail_count + 1),
           locked_until = IF(
             (IF(locked_until IS NOT NULL AND locked_until > NOW(), fail_count, fail_count + 1)) >= ?,
             DATE_ADD(NOW(), INTERVAL ? MINUTE),
             NULL
           )'
    );
    if (!$stmt) {
        return [
            'ok' => false,
            'message' => 'Incorrect transaction PIN',
            'locked' => false,
            'remaining_attempts' => 0,
        ];
    }
    $max = TXN_PIN_MAX_FAILURES;
    $mins = TXN_PIN_LOCK_MINUTES;
    $stmt->bind_param('iii', $userId, $max, $mins);
    $stmt->execute();
    $stmt->close();

    $after = txn_pin_guard_status($mysqli, $userId);
    if (!$after['ok']) {
        return [
            'ok' => false,
            'message' => (string) $after['message'],
            'locked' => true,
            'remaining_attempts' => 0,
        ];
    }
    $remaining = (int) ($after['remaining_attempts'] ?? 0);
    $msg = 'Incorrect transaction PIN';
    if ($remaining > 0) {
        $msg .= " ($remaining attempt" . ($remaining === 1 ? '' : 's') . ' left before lockout)';
    }
    return [
        'ok' => false,
        'message' => $msg,
        'locked' => false,
        'remaining_attempts' => $remaining,
    ];
}
