<?php
/**
 * CLI smoke tests for VTU pricing math + overlap helpers + hold/commission idempotency.
 * Run: php backend/tests/smoke_vtu_pricing_hold.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/commission_tiers_util.php';

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $label): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  $label\n";
        $passed++;
    } else {
        echo "FAIL  $label\n";
        $failed++;
    }
}

function assert_eq($expected, $actual, string $label): void
{
    assert_true($expected === $actual, $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

// --- Pure pricing math ---
$fixed = compute_vtu_tier_amounts(100.0, [
    'discount_type' => 'fixed',
    'discount_value' => 1,
    'commission_type' => 'fixed',
    'commission_value' => 1,
]);
assert_eq(1.0, $fixed['discount'], 'fixed discount ₦1 on ₦100');
assert_eq(1.0, $fixed['commission'], 'fixed commission ₦1 on ₦100');
assert_eq(99.0, $fixed['wallet_charge'], 'wallet charge face−discount');

$pct = compute_vtu_tier_amounts(200.0, [
    'discount_type' => 'percent',
    'discount_value' => 10,
    'commission_type' => 'percent',
    'commission_value' => 5,
]);
assert_eq(20.0, $pct['discount'], '10% discount on ₦200');
assert_eq(10.0, $pct['commission'], '5% commission on face ₦200 (not on discounted)');
assert_eq(180.0, $pct['wallet_charge'], 'wallet charge after 10%');

$capped = compute_vtu_tier_amounts(50.0, [
    'discount_type' => 'fixed',
    'discount_value' => 80,
    'commission_type' => 'fixed',
    'commission_value' => 0,
]);
assert_eq(50.0, $capped['discount'], 'discount capped at face');
assert_eq(0.0, $capped['wallet_charge'], 'zero wallet charge when fully discounted');

// --- Range overlap ---
assert_true(commission_ranges_overlap(50, 100, 100, 200), 'touching edges overlap');
assert_true(commission_ranges_overlap(50, 150, 100, 200), 'partial overlap');
assert_true(!commission_ranges_overlap(50, 99, 100, 200), 'adjacent non-overlap');
assert_true(commission_ranges_overlap(50, null, 1000, 2000), 'unbounded high overlaps');

// --- Optional live DB: face-based credit idempotency ---
$envLoader = $root . '/env_loader.php';
$config = $root . '/config.php';
if (is_file($envLoader)) {
    // Avoid emitting JSON headers from config when run CLI — connect manually.
    require_once $envLoader;
    $host = ec_env('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
    $user = ec_env('DB_USER', '') ?? '';
    $pass = ec_env('DB_PASS', '') ?? '';
    $db = ec_env('DB_NAME', '') ?? '';
    if ($user !== '' && $db !== '') {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli($host, $user, $pass, $db);
        if (!$mysqli->connect_error) {
            $mysqli->set_charset('utf8mb4');
            ensure_commission_tiers_tables($mysqli);

            $mysqli->query(
                "INSERT INTO vtu_commission_tiers
                (product_type, applies_to, min_amount, max_amount, commission_type, commission_value,
                 discount_type, discount_value, is_active)
                VALUES ('airtime', 'all', 100, 100, 'percent', 2, 'fixed', 1, 1)"
            );
            $tierId = (int) $mysqli->insert_id;

            $pricing = resolve_vtu_pricing($mysqli, 'airtime', 100.0, 0);
            assert_true(is_array($pricing), 'resolve_vtu_pricing finds smoke tier');
            if (is_array($pricing)) {
                assert_eq(1.0, (float) $pricing['discount'], 'live discount ₦1');
                assert_eq(2.0, (float) $pricing['commission'], 'live 2% of face ₦100 = ₦2');
                assert_eq(99.0, (float) $pricing['wallet_charge'], 'live wallet charge ₦99');
            }

            // Idempotency: ledger unique on reference — insert twice, second must fail/no-op.
            $ref = 'SMOKE-TEST-' . bin2hex(random_bytes(6));
            $ins = $mysqli->prepare(
                'INSERT INTO vtu_commission_credits
                (reference, user_id, product_type, sale_amount, commission_amount, tier_id)
                VALUES (?, 1, \'airtime\', 100, 2, ?)'
            );
            if ($ins) {
                $ins->bind_param('si', $ref, $tierId);
                $ok1 = $ins->execute();
                $ins->close();
                $ins2 = $mysqli->prepare(
                    'INSERT INTO vtu_commission_credits
                    (reference, user_id, product_type, sale_amount, commission_amount, tier_id)
                    VALUES (?, 1, \'airtime\', 100, 2, ?)'
                );
                $ins2->bind_param('si', $ref, $tierId);
                $ok2 = @$ins2->execute();
                $ins2->close();
                assert_true($ok1 === true, 'first commission ledger insert');
                assert_true($ok2 === false, 'duplicate commission ledger rejected');
                $mysqli->query(
                    "DELETE FROM vtu_commission_credits WHERE reference = '" . $mysqli->real_escape_string($ref) . "'"
                );
            }

            if ($tierId > 0) {
                $mysqli->query('DELETE FROM vtu_commission_tiers WHERE id = ' . $tierId);
            }
            $mysqli->close();
        } else {
            echo "SKIP  DB connect failed: {$mysqli->connect_error}\n";
        }
    } else {
        echo "SKIP  private/.env DB_* not set\n";
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
