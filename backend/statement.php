<?php
/**
 * Statement of account — wallet activity for agents/admins.
 * POST:
 *   action=generate — return JSON ledger
 *   action=email    — generate + email HTML statement (requires email)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/mail_util.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    exit;
}

$sessionUser = ec_require_session($mysqli);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$action = strtolower(trim((string) ($data['action'] ?? 'generate')));
if (!in_array($action, ['generate', 'email'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

$from = trim((string) ($data['from'] ?? ''));
$to = trim((string) ($data['to'] ?? ''));
if ($from === '' || $to === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'from and to dates required (Y-m-d)']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid date format; use Y-m-d']);
    exit;
}

$fromTs = strtotime($from . ' 00:00:00');
$toTs = strtotime($to . ' 23:59:59');
if ($fromTs === false || $toTs === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid date']);
    exit;
}
if ($fromTs > $toTs) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'from date must be on or before to date']);
    exit;
}

$fromDt = date('Y-m-d 00:00:00', $fromTs);
$toDt = date('Y-m-d 23:59:59', $toTs);

$sessionId = (int) $sessionUser['id'];
$sessionRole = (int) $sessionUser['role'];
$targetUserId = isset($data['user_id']) ? (int) $data['user_id'] : $sessionId;

if ($targetUserId !== $sessionId) {
    if ($sessionRole < 2) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only view your own statement']);
        exit;
    }
    // Admin must not pull Super Admin or external-user statements.
    $chk = $mysqli->prepare(
        'SELECT id, role, full_name, email, COALESCE(is_external, 0) AS is_external
         FROM users WHERE id = ? LIMIT 1'
    );
    $chk->bind_param('i', $targetUserId);
    $chk->execute();
    $target = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    if ($sessionRole === 2 && (
        (int) $target['role'] >= 3 || (int) ($target['is_external'] ?? 0) === 1
    )) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
} else {
    $target = [
        'id' => $sessionId,
        'role' => $sessionRole,
        'full_name' => (string) ($sessionUser['full_name'] ?? ''),
        'email' => (string) ($sessionUser['email'] ?? ''),
    ];
}

function statement_counts_as_debit(string $status): bool
{
    $normalized = strtolower(trim($status));
    return !in_array($normalized, ['failed', 'refunded', 'cancelled', 'void'], true);
}

function statement_money(float $amount): string
{
    return number_format($amount, 2);
}

/**
 * @return array{statement: array, entries: list<array>}
 */
function build_statement_payload(
    mysqli $mysqli,
    int $targetUserId,
    array $target,
    string $fromDt,
    string $toDt
): array {
    if (!function_exists('ensure_commission_tiers_tables')) {
        require_once __DIR__ . '/commission_tiers_util.php';
    }
    ensure_commission_tiers_tables($mysqli);

    $entries = [];
    $totalDebits = 0.0;
    $totalCredits = 0.0;
    $salesAirtime = 0.0;
    $salesData = 0.0;
    $salesOther = 0.0;
    $profitEarned = 0.0;
    $fundingIn = 0.0;
    $fundingOut = 0.0;

    // Sales (airtime / data purchases) + join commission profit when available.
    $txnSql = 'SELECT t.receipt_id, t.product, t.total, t.status, t.served_by, t.transaction_at,
                      COALESCE(t.phone, \'\') AS phone, COALESCE(t.network, \'MTN\') AS network,
                      COALESCE(
                        NULLIF(h.reference, \'\'),
                        CASE
                          WHEN t.receipt_id LIKE \'VTU-%\' THEN SUBSTRING(t.receipt_id, 5)
                          ELSE t.receipt_id
                        END
                      ) AS provider_ref,
                      COALESCE(c.commission_amount, 0) AS commission_amount
               FROM agent_transactions t
               LEFT JOIN vtu_wallet_holds h ON h.receipt_id = t.receipt_id
               LEFT JOIN vtu_commission_credits c
                      ON c.user_id = t.wallet_user_id
                     AND (
                       c.reference = h.reference
                       OR c.reference = CASE
                            WHEN t.receipt_id LIKE \'VTU-%\' THEN SUBSTRING(t.receipt_id, 5)
                            ELSE t.receipt_id
                          END
                     )
               WHERE t.wallet_user_id = ? AND t.transaction_at BETWEEN ? AND ?
               ORDER BY t.transaction_at ASC';
    $stmt = $mysqli->prepare($txnSql);
    if ($stmt) {
        $stmt->bind_param('iss', $targetUserId, $fromDt, $toDt);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $amt = (float) $row['total'];
            $status = (string) ($row['status'] ?? '');
            $counts = statement_counts_as_debit($status);
            $product = (string) ($row['product'] ?? '');
            $productLower = strtolower($product);
            $category = 'other';
            if (str_contains($productLower, 'data')) {
                $category = 'data';
            } elseif (str_contains($productLower, 'airtime') || str_contains($productLower, 'vtu') || str_contains($productLower, 'momo') || str_contains($productLower, 'logical')) {
                $category = 'airtime';
            }

            $commission = (float) ($row['commission_amount'] ?? 0);
            if ($counts) {
                $totalDebits += $amt;
                if ($category === 'data') {
                    $salesData += $amt;
                } elseif ($category === 'airtime') {
                    $salesAirtime += $amt;
                } else {
                    $salesOther += $amt;
                }
                if ($commission > 0) {
                    $profitEarned += $commission;
                }
            }

            $desc = $product;
            if ($category === 'airtime') {
                $desc = 'Airtime sale — ' . $product;
            } elseif ($category === 'data') {
                $desc = 'Data sale — ' . $product;
            }
            if ($commission > 0 && $counts) {
                $desc .= ' · Profit ₦' . number_format($commission, 2);
            }

            $entries[] = [
                'date' => $row['transaction_at'],
                'type' => $counts ? 'debit' : 'void',
                'category' => $category,
                'reference' => $row['receipt_id'],
                'description' => $desc,
                'phone' => $row['phone'],
                'network' => $row['network'],
                'amount' => $amt,
                'profit' => $counts ? round($commission, 2) : 0.0,
                'status' => $status,
                'served_by' => $row['served_by'],
            ];
        }
        $stmt->close();
    }

    // Funding in/out — always show who funded (Super Admin / Admin name).
    $fundSql = 'SELECT receipt_id, wallet_name, wallet_product, amount, funded_by,
                        funded_by_user_id, status, funded_at
                FROM wallet_funding_history
                WHERE wallet_user_id = ? AND funded_at BETWEEN ? AND ?
                ORDER BY funded_at ASC';
    $fstmt = $mysqli->prepare($fundSql);
    if ($fstmt) {
        $fstmt->bind_param('iss', $targetUserId, $fromDt, $toDt);
        $fstmt->execute();
        $fres = $fstmt->get_result();
        while ($row = $fres->fetch_assoc()) {
            $amt = (float) $row['amount'];
            $status = (string) ($row['status'] ?? '');
            $isOutflow = $amt < 0 || strcasecmp($status, 'Transferred') === 0;
            $abs = abs($amt);
            $fundedBy = trim((string) ($row['funded_by'] ?? ''));
            if ($fundedBy === '') {
                $fundedBy = 'System';
            }
            $walletName = (string) ($row['wallet_name'] ?? 'Wallet');

            if ($isOutflow) {
                $totalDebits += $abs;
                $fundingOut += $abs;
                $desc = 'Funds transferred out — ' . $walletName . ' · To/via ' . $fundedBy;
            } else {
                $totalCredits += $abs;
                $fundingIn += $abs;
                $desc = 'Funds received — ' . $walletName . ' · Funded by ' . $fundedBy;
            }

            $entries[] = [
                'date' => $row['funded_at'],
                'type' => $isOutflow ? 'debit' : 'credit',
                'category' => $isOutflow ? 'funding_out' : 'funding_in',
                'reference' => $row['receipt_id'],
                'description' => $desc,
                'phone' => '',
                'network' => '',
                'amount' => $abs,
                'profit' => 0.0,
                'status' => $status,
                'served_by' => $fundedBy,
                'funded_by' => $fundedBy,
            ];
        }
        $fstmt->close();
    }

    // Commission/profit credits in period (in case hold join missed some).
    $cstmt = $mysqli->prepare(
        'SELECT reference, product_type, sale_amount, commission_amount, created_at
         FROM vtu_commission_credits
         WHERE user_id = ? AND created_at BETWEEN ? AND ?
         ORDER BY created_at ASC'
    );
    if ($cstmt) {
        $cstmt->bind_param('iss', $targetUserId, $fromDt, $toDt);
        $cstmt->execute();
        $cres = $cstmt->get_result();
        $seenProfitRefs = [];
        foreach ($entries as $e) {
            if (($e['profit'] ?? 0) > 0 && !empty($e['reference'])) {
                $seenProfitRefs[(string) $e['reference']] = true;
                // Also mark provider refs without VTU- prefix.
                $seenProfitRefs[preg_replace('/^VTU-/', '', (string) $e['reference'])] = true;
            }
        }
        while ($row = $cres->fetch_assoc()) {
            $ref = (string) ($row['reference'] ?? '');
            $comm = (float) ($row['commission_amount'] ?? 0);
            if ($comm <= 0) {
                continue;
            }
            if (isset($seenProfitRefs[$ref]) || isset($seenProfitRefs['VTU-' . $ref])) {
                continue;
            }
            $profitEarned += $comm;
            $totalCredits += $comm;
            $ptype = strtoupper((string) ($row['product_type'] ?? 'VTU'));
            $entries[] = [
                'date' => $row['created_at'],
                'type' => 'credit',
                'category' => 'profit',
                'reference' => $ref,
                'description' => 'Commission / profit — ' . $ptype
                    . ' sale ₦' . number_format((float) ($row['sale_amount'] ?? 0), 2),
                'phone' => '',
                'network' => '',
                'amount' => $comm,
                'profit' => round($comm, 2),
                'status' => 'Credited',
                'served_by' => 'EbubeConnect',
            ];
        }
        $cstmt->close();
    }

    usort($entries, static function (array $a, array $b): int {
        return strcmp($a['date'], $b['date']);
    });

    $balStmt = $mysqli->prepare(
        'SELECT momo_balance, vtu_balance, logical_balance, commission_balance
         FROM users WHERE id = ? LIMIT 1'
    );
    $balances = [
        'momo_balance' => 0.0,
        'vtu_balance' => 0.0,
        'logical_balance' => 0.0,
        'commission_balance' => 0.0,
    ];
    if ($balStmt) {
        $balStmt->bind_param('i', $targetUserId);
        $balStmt->execute();
        $brow = $balStmt->get_result()->fetch_assoc();
        $balStmt->close();
        if ($brow) {
            foreach ($balances as $k => $_) {
                $balances[$k] = (float) ($brow[$k] ?? 0);
            }
        }
    }

    $availableBalance = $balances['momo_balance']
        + $balances['vtu_balance']
        + $balances['logical_balance'];

    $statement = [
        'user_id' => $targetUserId,
        'full_name' => $target['full_name'] ?? '',
        'email' => $target['email'] ?? '',
        'period_from' => substr($fromDt, 0, 10),
        'period_to' => substr($toDt, 0, 10),
        'generated_at' => date('Y-m-d H:i:s'),
        'total_debits' => round($totalDebits, 2),
        'total_credits' => round($totalCredits, 2),
        'net_movement' => round($totalCredits - $totalDebits, 2),
        'available_balance' => round($availableBalance, 2),
        'sales_airtime' => round($salesAirtime, 2),
        'sales_data' => round($salesData, 2),
        'sales_other' => round($salesOther, 2),
        'sales_total' => round($salesAirtime + $salesData + $salesOther, 2),
        'profit_earned' => round($profitEarned, 2),
        'funding_received' => round($fundingIn, 2),
        'funding_sent' => round($fundingOut, 2),
        'entry_count' => count($entries),
        'entries' => $entries,
        'balances' => $balances,
    ];

    return ['statement' => $statement, 'entries' => $entries];
}

function build_statement_html(array $statement): string
{
    $name = htmlspecialchars((string) ($statement['full_name'] ?? 'Account'), ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars((string) ($statement['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $periodFrom = htmlspecialchars((string) ($statement['period_from'] ?? ''), ENT_QUOTES, 'UTF-8');
    $periodTo = htmlspecialchars((string) ($statement['period_to'] ?? ''), ENT_QUOTES, 'UTF-8');
    $generatedAt = date('d M Y, h:i A', strtotime((string) ($statement['generated_at'] ?? 'now')) ?: time());
    $periodStr = $periodFrom . ' — ' . $periodTo;

    $totalCredits = statement_money((float) ($statement['total_credits'] ?? 0));
    $totalDebits = statement_money((float) ($statement['total_debits'] ?? 0));
    $net = statement_money((float) ($statement['net_movement'] ?? 0));
    $available = statement_money((float) ($statement['available_balance'] ?? 0));
    $salesAirtime = statement_money((float) ($statement['sales_airtime'] ?? 0));
    $salesData = statement_money((float) ($statement['sales_data'] ?? 0));
    $salesTotal = statement_money((float) ($statement['sales_total'] ?? 0));
    $profitEarned = statement_money((float) ($statement['profit_earned'] ?? 0));
    $fundingReceived = statement_money((float) ($statement['funding_received'] ?? 0));
    $fundingSent = statement_money((float) ($statement['funding_sent'] ?? 0));

    $balances = is_array($statement['balances'] ?? null) ? $statement['balances'] : [];
    $momo = statement_money((float) ($balances['momo_balance'] ?? 0));
    $vtu = statement_money((float) ($balances['vtu_balance'] ?? 0));
    $logical = statement_money((float) ($balances['logical_balance'] ?? 0));
    $commission = statement_money((float) ($balances['commission_balance'] ?? 0));

    $logoSrc = 'https://ebubeconnect.com/agent/assets/assets/images/ebube.png';

    $rowsHtml = '';
    $entries = is_array($statement['entries'] ?? null) ? $statement['entries'] : [];
    foreach ($entries as $it) {
        if (!is_array($it)) {
            continue;
        }
        $type = strtolower((string) ($it['type'] ?? ''));
        $amt = (float) ($it['amount'] ?? 0);
        $debitFmt = $type === 'debit' ? statement_money($amt) : '';
        $creditFmt = $type === 'credit' ? statement_money($amt) : '';
        $dateFmt = !empty($it['date']) ? date('d/m/Y', strtotime((string) $it['date']) ?: time()) : '-';
        $desc = htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ref = htmlspecialchars((string) ($it['reference'] ?? ''), ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars((string) ($it['status'] ?? ''), ENT_QUOTES, 'UTF-8');
        $servedBy = htmlspecialchars((string) ($it['served_by'] ?? $it['funded_by'] ?? ''), ENT_QUOTES, 'UTF-8');
        $refLine = $ref !== '' ? "REF: {$ref}" : '';
        if ($status !== '') {
            $refLine .= ($refLine !== '' ? ' · ' : '') . $status;
        }
        if ($servedBy !== '') {
            $refLine .= ($refLine !== '' ? ' · ' : '') . $servedBy;
        }

        $rowsHtml .= "
        <tr style='border-bottom: 1px solid #e5e7eb;'>
            <td style='padding: 10px 12px; font-size: 12px; color: #374151; border-right: 1px solid #e5e7eb; vertical-align: top;'>{$dateFmt}</td>
            <td style='padding: 10px 12px; font-size: 12px; color: #111827; border-right: 1px solid #e5e7eb; vertical-align: top;'>
                <div style='font-weight: 600;'>{$desc}</div>
                <div style='font-size: 11px; color: #6b7280; margin-top: 3px;'>{$refLine}</div>
            </td>
            <td style='padding: 10px 12px; font-size: 12px; color: #111827; border-right: 1px solid #e5e7eb; text-align: right; vertical-align: top;'>{$debitFmt}</td>
            <td style='padding: 10px 12px; font-size: 12px; color: #111827; text-align: right; vertical-align: top;'>{$creditFmt}</td>
        </tr>";
    }

    if ($rowsHtml === '') {
        $rowsHtml = "<tr><td colspan='4' style='padding: 30px; text-align: center; color: #9ca3af; font-size: 13px;'>No transactions found for this period.</td></tr>";
    }

    return "<!DOCTYPE html>
<html>
<head>
<meta charset='utf-8'>
<title>Account Statement - {$name}</title>
</head>
<body style='margin: 0; padding: 24px 10px; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif;'>
<div style='max-width: 850px; margin: 0 auto; background: #ffffff; padding: 36px 32px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); color: #000000;'>
    <div style='margin-bottom: 24px;'>
        <img src='{$logoSrc}' alt='EbubeConnect' style='height: 72px; width: auto; max-width: 280px; object-fit: contain;' />
    </div>
    <div style='margin-bottom: 24px;'>
        <h1 style='margin: 0 0 4px 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; color: #111827;'>ACCOUNT STATEMENT</h1>
        <div style='font-size: 12px; color: #6b7280; font-weight: 500;'>Generated on {$generatedAt}</div>
    </div>

    <table style='width: 100%; border-collapse: collapse; margin-bottom: 24px;'>
        <tr>
            <td style='width: 48%; vertical-align: top; padding: 0 8px 0 0;'>
                <table style='width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb;'>
                    <thead>
                        <tr>
                            <th colspan='2' style='background-color: #FFCC00; color: #000000; font-size: 12px; font-weight: 800; letter-spacing: 0.5px; text-align: left; padding: 8px 12px; text-transform: uppercase;'>ACCOUNT DETAILS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827; width: 40%;'>Account Name:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #374151; font-weight: 500;'>{$name}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Email:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #374151; font-weight: 500;'>{$email}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Account Source:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #374151; font-weight: 500;'>EbubeConnect</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Currency:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #374151; font-weight: 500;'>NGN</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Period:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #374151; font-weight: 500;'>{$periodStr}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
            <td style='width: 48%; vertical-align: top; padding: 0 0 0 8px;'>
                <table style='width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb;'>
                    <thead>
                        <tr>
                            <th colspan='2' style='background-color: #FFCC00; color: #000000; font-size: 12px; font-weight: 800; letter-spacing: 0.5px; text-align: left; padding: 8px 12px; text-transform: uppercase;'>FINANCIAL SUMMARY</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827; width: 45%;'>Total Credits:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$totalCredits}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Total Debits:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$totalDebits}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Funds received:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$fundingReceived}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Funds sent:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$fundingSent}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Airtime sales:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$salesAirtime}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Data sales:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$salesData}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Total sales:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$salesTotal}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Profit earned:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$profitEarned}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Net Movement:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$net}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>MoMo / VTU / Logical:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$momo} / {$vtu} / {$logical}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 6px 12px; font-size: 12px; font-weight: 700; color: #111827;'>Commission wallet:</td>
                            <td style='padding: 6px 12px; font-size: 12px; color: #111827; text-align: right; font-weight: 600;'>{$commission}</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 12px; font-size: 13px; font-weight: 800; color: #000000;'>Available Balance:</td>
                            <td style='padding: 6px 12px; font-size: 13px; font-weight: 800; color: #000000; text-align: right;'>{$available}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <table style='width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; margin-bottom: 24px;'>
        <thead>
            <tr style='background-color: #FFCC00; border-bottom: 2px solid #e5e7eb;'>
                <th style='padding: 9px 12px; font-size: 11px; font-weight: 800; text-align: left; color: #000000; text-transform: uppercase; width: 14%; border-right: 1px solid #e5e7eb;'>DATE</th>
                <th style='padding: 9px 12px; font-size: 11px; font-weight: 800; text-align: left; color: #000000; text-transform: uppercase; width: 46%; border-right: 1px solid #e5e7eb;'>DESCRIPTION</th>
                <th style='padding: 9px 12px; font-size: 11px; font-weight: 800; text-align: right; color: #000000; text-transform: uppercase; width: 20%; border-right: 1px solid #e5e7eb;'>DEBIT (NGN)</th>
                <th style='padding: 9px 12px; font-size: 11px; font-weight: 800; text-align: right; color: #000000; text-transform: uppercase; width: 20%;'>CREDIT (NGN)</th>
            </tr>
        </thead>
        <tbody>
            {$rowsHtml}
        </tbody>
    </table>

    <div style='text-align: right; font-size: 11px; color: #6b7280; margin-top: 16px; font-weight: 500;'>
        Generated by EbubeConnect on {$generatedAt}
    </div>
</div>
</body>
</html>";
}

$built = build_statement_payload($mysqli, $targetUserId, $target, $fromDt, $toDt);
$statement = $built['statement'];

if ($action === 'generate') {
    echo json_encode([
        'success' => true,
        'statement' => $statement,
    ]);
    $mysqli->close();
    exit;
}

// action=email — request recipient and dispatch PDF statement.
$recipient = trim((string) ($data['email'] ?? ''));
if ($recipient === '') {
    $recipient = trim((string) ($target['email'] ?? ''));
}
if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'A valid recipient email is required',
        'email_sent' => false,
    ]);
    $mysqli->close();
    exit;
}

require_once __DIR__ . '/lib/statement_pdf.php';

try {
    $pdfBytes = build_statement_pdf($statement);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not generate PDF statement: ' . $e->getMessage(),
        'email_sent' => false,
    ]);
    $mysqli->close();
    exit;
}

$subject = 'EbubeConnect Account Statement ('
    . ($statement['period_from'] ?? '')
    . ' to '
    . ($statement['period_to'] ?? '')
    . ')';

$html = '<p>Dear ' . htmlspecialchars((string) ($statement['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') . ',</p>'
    . '<p>Please find attached your <strong>EbubeConnect Account Statement</strong> '
    . 'for <strong>' . htmlspecialchars((string) ($statement['period_from'] ?? ''), ENT_QUOTES, 'UTF-8')
    . '</strong> to <strong>' . htmlspecialchars((string) ($statement['period_to'] ?? ''), ENT_QUOTES, 'UTF-8')
    . '</strong>.</p>'
    . '<p>Total credits: ₦' . number_format((float) ($statement['total_credits'] ?? 0), 2)
    . '<br>Total debits: ₦' . number_format((float) ($statement['total_debits'] ?? 0), 2)
    . '<br>Entries: ' . (int) ($statement['entry_count'] ?? 0) . '</p>'
    . '<p>Open the attached PDF for the full itemized statement.</p>'
    . '<p style="color:#6b7280;font-size:12px;">If you do not see the attachment, check Spam/Junk.</p>';

$text = "EbubeConnect — Account Statement\n"
    . 'Account: ' . ($statement['full_name'] ?? '') . "\n"
    . 'Period: ' . ($statement['period_from'] ?? '') . ' to ' . ($statement['period_to'] ?? '') . "\n"
    . 'Total credits: NGN ' . number_format((float) ($statement['total_credits'] ?? 0), 2) . "\n"
    . 'Total debits: NGN ' . number_format((float) ($statement['total_debits'] ?? 0), 2) . "\n"
    . 'Entries: ' . (int) ($statement['entry_count'] ?? 0) . "\n\n"
    . "Open the attached PDF for the full itemized statement.\n"
    . "If you do not see the attachment, check Spam/Junk.\n";

$filename = 'EbubeConnect-Statement-'
    . preg_replace('/[^0-9\-]/', '', (string) ($statement['period_from'] ?? ''))
    . '-to-'
    . preg_replace('/[^0-9\-]/', '', (string) ($statement['period_to'] ?? ''))
    . '.pdf';

$sent = send_app_html_email(
    $recipient,
    $subject,
    $html,
    $text,
    [
        [
            'filename' => $filename,
            'content' => $pdfBytes,
            'content_type' => 'application/pdf',
        ],
    ]
);

if (!$sent) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Could not send statement email. Check mail configuration and try again.',
        'email_sent' => false,
        'recipient_email' => $recipient,
        'statement' => $statement,
    ]);
    $mysqli->close();
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Statement emailed to ' . $recipient . '. If it is not in Inbox, check Spam/Junk.',
    'email_sent' => true,
    'recipient_email' => $recipient,
    'statement' => $statement,
]);
$mysqli->close();
