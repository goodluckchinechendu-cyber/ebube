<?php
/**
 * Build an EbubeConnect Statement of Account as a PDF (FPDF).
 */
require_once __DIR__ . '/fpdf.php';

function statement_pdf_money(float $amount): string
{
    return number_format($amount, 2);
}

/**
 * @param array $statement from build_statement_payload()
 * @return string raw PDF bytes
 */
function build_statement_pdf(array $statement): string
{
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->SetMargins(12, 12, 12);

    $name = (string) ($statement['full_name'] ?? 'Account');
    $email = (string) ($statement['email'] ?? '');
    $periodFrom = (string) ($statement['period_from'] ?? '');
    $periodTo = (string) ($statement['period_to'] ?? '');
    $generatedAt = date('d M Y, h:i A', strtotime((string) ($statement['generated_at'] ?? 'now')) ?: time());

    $totalCredits = statement_pdf_money((float) ($statement['total_credits'] ?? 0));
    $totalDebits = statement_pdf_money((float) ($statement['total_debits'] ?? 0));
    $net = statement_pdf_money((float) ($statement['net_movement'] ?? 0));
    $available = statement_pdf_money((float) ($statement['available_balance'] ?? 0));
    $balances = is_array($statement['balances'] ?? null) ? $statement['balances'] : [];
    $momo = statement_pdf_money((float) ($balances['momo_balance'] ?? 0));
    $vtu = statement_pdf_money((float) ($balances['vtu_balance'] ?? 0));
    $logical = statement_pdf_money((float) ($balances['logical_balance'] ?? 0));
    $commission = statement_pdf_money((float) ($balances['commission_balance'] ?? 0));

    // Header — logo (brand mark) + title (no duplicate brand text)
    $logoPath = __DIR__ . '/../assets/images/ebube.png';
    $headerY = 12;
    if (is_file($logoPath)) {
        // Square brand asset; keep height modest so the mark stays crisp
        $pdf->Image($logoPath, 12, $headerY, 28, 28);
        $pdf->SetXY(44, $headerY + 6);
    } else {
        $pdf->SetXY(12, $headerY + 6);
    }
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 8, 'ACCOUNT STATEMENT', 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(is_file($logoPath) ? 44 : 12, $headerY + 16);
    $pdf->Cell(0, 5, 'Generated on ' . $generatedAt, 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($headerY + 34);
    $pdf->Ln(2);

    // Account details
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetFillColor(255, 204, 0);
    $pdf->Cell(90, 7, ' ACCOUNT DETAILS', 1, 0, 'L', true);
    $pdf->Cell(6, 7, '', 0, 0);
    $pdf->Cell(90, 7, ' FINANCIAL SUMMARY', 1, 1, 'L', true);

    $pdf->SetFont('Helvetica', '', 9);
    $leftX = 12;
    $rightX = 108;
    $y = $pdf->GetY();

    $leftRows = [
        ['Account Name', $name],
        ['Email', $email],
        ['Account Source', 'EbubeConnect'],
        ['Currency', 'NGN'],
        ['Period', $periodFrom . ' to ' . $periodTo],
    ];
    $rightRows = [
        ['Total Credits', $totalCredits],
        ['Total Debits', $totalDebits],
        ['Net Movement', $net],
        ['MoMo / VTU / Logical', $momo . ' / ' . $vtu . ' / ' . $logical],
        ['Commission', $commission],
        ['Available Balance', $available],
    ];

    $rowH = 6;
    $maxRows = max(count($leftRows), count($rightRows));
    for ($i = 0; $i < $maxRows; $i++) {
        $yy = $y + ($i * $rowH);
        if (isset($leftRows[$i])) {
            $pdf->SetXY($leftX, $yy);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Cell(32, $rowH, $leftRows[$i][0] . ':', 0, 0);
            $pdf->SetFont('Helvetica', '', 8);
            $label = $leftRows[$i][1];
            $label = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $label) ?: $label;
            if (strlen($label) > 42) {
                $label = substr($label, 0, 39) . '...';
            }
            $pdf->Cell(58, $rowH, $label, 0, 0);
        }
        if (isset($rightRows[$i])) {
            $pdf->SetXY($rightX, $yy);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Cell(38, $rowH, $rightRows[$i][0] . ':', 'LR', 0);
            $pdf->SetFont('Helvetica', $i === count($rightRows) - 1 ? 'B' : '', 8);
            $pdf->Cell(52, $rowH, $rightRows[$i][1], 'R', 0, 'R');
        }
    }

    // Close boxes
    $boxH = $maxRows * $rowH;
    $pdf->Rect($leftX, $y, 90, $boxH);
    $pdf->Rect($rightX, $y, 90, $boxH);
    $pdf->SetY($y + $boxH + 8);

    // Ledger header
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(255, 204, 0);
    $pdf->Cell(22, 7, 'DATE', 1, 0, 'L', true);
    $pdf->Cell(88, 7, 'DESCRIPTION', 1, 0, 'L', true);
    $pdf->Cell(38, 7, 'DEBIT (NGN)', 1, 0, 'R', true);
    $pdf->Cell(38, 7, 'CREDIT (NGN)', 1, 1, 'R', true);

    $pdf->SetFont('Helvetica', '', 8);
    $entries = is_array($statement['entries'] ?? null) ? $statement['entries'] : [];
    if ($entries === []) {
        $pdf->Cell(186, 8, 'No transactions found for this period.', 1, 1, 'C');
    } else {
        foreach ($entries as $it) {
            if (!is_array($it)) {
                continue;
            }
            if ($pdf->GetY() > 270) {
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', 'B', 8);
                $pdf->SetFillColor(255, 204, 0);
                $pdf->Cell(22, 7, 'DATE', 1, 0, 'L', true);
                $pdf->Cell(88, 7, 'DESCRIPTION', 1, 0, 'L', true);
                $pdf->Cell(38, 7, 'DEBIT (NGN)', 1, 0, 'R', true);
                $pdf->Cell(38, 7, 'CREDIT (NGN)', 1, 1, 'R', true);
                $pdf->SetFont('Helvetica', '', 8);
            }

            $type = strtolower((string) ($it['type'] ?? ''));
            $amt = (float) ($it['amount'] ?? 0);
            $debit = $type === 'debit' ? statement_pdf_money($amt) : '';
            $credit = $type === 'credit' ? statement_pdf_money($amt) : '';
            $dateFmt = !empty($it['date']) ? date('d/m/Y', strtotime((string) $it['date']) ?: time()) : '-';
            $desc = (string) ($it['description'] ?? '');
            $ref = trim((string) ($it['reference'] ?? ''));
            if ($ref !== '') {
                $desc .= '  [REF: ' . $ref . ']';
            }
            // Strip non-latin1 for core FPDF fonts
            $desc = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $desc) ?: $desc;
            if (strlen($desc) > 70) {
                $desc = substr($desc, 0, 67) . '...';
            }

            $pdf->Cell(22, 6, $dateFmt, 1, 0, 'L');
            $pdf->Cell(88, 6, $desc, 1, 0, 'L');
            $pdf->Cell(38, 6, $debit, 1, 0, 'R');
            $pdf->Cell(38, 6, $credit, 1, 1, 'R');
        }
    }

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 5, 'Generated by EbubeConnect on ' . $generatedAt, 0, 1, 'R');

    return $pdf->Output('S');
}
