<?php
/**
 * Generate PDF for booking confirmation — styled to match the Ticketix staff receipt
 *
 * @param array  $ticket     Ticket data
 * @param array  $seats      Seats array (strings)
 * @param array  $foodItems  Food items array
 * @param float  $foodTotal  Food total
 * @param string $branchName Branch display name
 * @param string $qrData     Data encoded in QR
 * @param string $ticketUrl  Direct link to ticket page
 * @return string|false      PDF file path or false on failure
 */
function generateBookingPDF($ticket, $seats, $foodItems, $foodTotal, $branchName = 'Branch not specified', $qrData = '', $ticketUrl = '') {

    // ── Locate TCPDF ──────────────────────────────────────────────────────────
    $tcpdfPath = __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (!file_exists($tcpdfPath)) {
        $tcpdfPath = __DIR__ . '/vendor/tcpdf/tcpdf/tcpdf.php';
    }
    if (!file_exists($tcpdfPath)) {
        error_log('TCPDF not found. Install via: composer require tecnickcom/tcpdf');
        return false;
    }
    require_once $tcpdfPath;

    // ── Colour palette (used in HTML cells) ───────────────────────────────────
    $bgPage    = '#f4f6fb';    // off-white page background
    $bgCard    = '#ffffff';    // receipt card background
    $bgHeader  = '#0f1a2e';    // dark navy header
    $accentBlue = '#3b5fc0';   // primary blue accent
    $accentLine = '#d0d8f0';   // subtle row divider
    $textDark  = '#111827';    // main text
    $textMuted = '#6b7280';    // label / muted text
    $bgRowAlt  = '#f9fafb';    // alternate row shade

    // ── Computed values ───────────────────────────────────────────────────────
    $showDate    = date('F d, Y',        strtotime($ticket['show_date']));
    $showTime    = date('g:i A',         strtotime($ticket['show_hour']));
    $issuedDate  = date('M d, Y g:i A', strtotime($ticket['date_issued'] ?? 'now'));

    $seatList    = !empty($seats) ? implode(', ', $seats) : ($ticket['ticket_amount'] . ' seat(s)');
    $seatCount   = !empty($seats) ? count($seats)         : intval($ticket['ticket_amount']);
    $amountPaid  = '&#8369;' . number_format($ticket['amount_paid'], 2);

    // Payment display — try to extract provider from reference prefix
    $payType     = ucfirst(str_replace('-', ' ', $ticket['payment_type'] ?? 'N/A'));
    $refNum      = $ticket['reference_number'] ?? '';
    $provMap     = ['gcash'=>'GCash','paymaya'=>'PayMaya','grabpay'=>'GrabPay','cash'=>'Cash','ew'=>'E-Wallet'];
    if ($refNum) {
        $parts  = explode('-', strtolower($refNum));
        $prefix = $parts[0] ?? '';
        if (isset($provMap[$prefix])) $payType = $provMap[$prefix];
        // Handle "EW-GCASH-..." style
        if ($prefix === 'ew' && isset($parts[1]) && isset($provMap[$parts[1]])) {
            $payType = $provMap[$parts[1]];
        }
    }

    // Booking type — walk-in vs online client
    $bookingType = (strtolower($ticket['payment_type'] ?? '') === 'cash' && empty($qrData)) ? 'Walk-in' : 'Client (Online)';
    // Safer: we trust the caller; default to Client (Online) from my-bookings flow
    $bookingType = 'Client (Online)';

    // ── TCPDF setup ──────────────────────────────────────────────────────────
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Ticketix');
    $pdf->SetAuthor('Ticketix');
    $pdf->SetTitle('E-Receipt — ' . $ticket['ticket_number']);
    $pdf->SetSubject('Ticketix Movie Ticket Receipt');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();

    // ── Page background ───────────────────────────────────────────────────────
    $pdf->SetFillColor(244, 246, 251);
    $pdf->Rect(0, 0, 210, 297, 'F');

    // ── Card ─────────────────────────────────────────────────────────────────
    $cardX = 20; $cardY = 16; $cardW = 170;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(208, 216, 240);
    $pdf->RoundedRect($cardX, $cardY, $cardW, 258, 4, '1111', 'FD');

    // ── Header band ───────────────────────────────────────────────────────────
    $pdf->SetFillColor(15, 26, 46);
    $pdf->RoundedRect($cardX, $cardY, $cardW, 22, 4, '1100', 'F');

    // Brand name
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($cardX + 5, $cardY + 4);
    $pdf->Cell(80, 8, 'TICKETIX', 0, 0, 'L');

    // "Official Receipt" label top-right
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(180, 195, 230);
    $pdf->SetXY($cardX + 85, $cardY + 4);
    $pdf->Cell(80, 4, 'OFFICIAL RECEIPT', 0, 0, 'R');

    // Subtitle beneath brand
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(150, 170, 210);
    $pdf->SetXY($cardX + 5, $cardY + 12);
    $pdf->Cell(80, 5, 'Online Booking Receipt', 0, 0, 'L');

    // Ticket number top-right
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($cardX + 85, $cardY + 12);
    $pdf->Cell(80, 5, $ticket['ticket_number'], 0, 0, 'R');

    // ── Blue accent strip ─────────────────────────────────────────────────────
    $pdf->SetFillColor(59, 95, 192);
    $pdf->Rect($cardX, $cardY + 22, $cardW, 1.5, 'F');

    // ── BOOKING CONFIRMED badge ───────────────────────────────────────────────
    $badgeY = $cardY + 27;
    $pdf->SetFillColor(34, 139, 80);
    $pdf->RoundedRect(60, $badgeY, 90, 7, 2, '1111', 'F');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(60, $badgeY + 1);
    $pdf->Cell(90, 5, 'BOOKING CONFIRMED', 0, 0, 'C');

    // Issue date under badge
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(120, 130, 150);
    $pdf->SetXY(60, $badgeY + 9);
    $pdf->Cell(90, 4, 'Issued: ' . $issuedDate, 0, 0, 'C');

    // ── Dashed divider ────────────────────────────────────────────────────────
    $divY1 = $badgeY + 15;
    $pdf->SetDrawColor(180, 195, 230);
    $pdf->SetLineStyle(['dash' => '2,2', 'width' => 0.4]);
    $pdf->Line($cardX + 6, $divY1, $cardX + $cardW - 6, $divY1);
    $pdf->SetLineStyle(['dash' => 0, 'width' => 0.3]);

    // ── Detail rows ───────────────────────────────────────────────────────────
    $rowX     = $cardX + 6;
    $rowW     = $cardW - 12;
    $labelW   = 54;
    $valW     = $rowW - $labelW;
    $rowH     = 8;
    $curY     = $divY1 + 3;
    $altRow   = false;

    $rows = [
        ['Booking Type',   $bookingType],
        ['Movie',          $ticket['title']],
        ['Branch / Cinema',$branchName],
        ['Date & Time',    $showDate . ' at ' . $showTime],
        ['Seats (' . $seatCount . ')', $seatList],
    ];

    foreach ($rows as $row) {
        if ($altRow) {
            $pdf->SetFillColor(249, 250, 251);
            $pdf->Rect($rowX, $curY, $rowW, $rowH, 'F');
        }
        // Label
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetXY($rowX + 2, $curY + 1.5);
        $pdf->Cell($labelW - 4, 5, $row[0], 0, 0, 'L');
        // Value
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetXY($rowX + $labelW, $curY + 1.5);
        $pdf->MultiCell($valW - 2, 5, $row[1], 0, 'L');
        // Bottom border
        $pdf->SetDrawColor(208, 216, 240);
        $pdf->Line($rowX, $curY + $rowH, $rowX + $rowW, $curY + $rowH);

        $curY   += $rowH;
        $altRow  = !$altRow;
    }

    // ── Food section ─────────────────────────────────────────────────────────
    if (!empty($foodItems)) {
        // Section header
        $pdf->SetFillColor(230, 236, 255);
        $pdf->Rect($rowX, $curY, $rowW, 6, 'F');
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetTextColor(59, 95, 192);
        $pdf->SetXY($rowX + 2, $curY + 1);
        $pdf->Cell($rowW, 4, 'FOOD & DRINKS', 0, 0, 'L');
        $curY += 6;
        $altRow = false;

        foreach ($foodItems as $food) {
            $lineTotal = number_format($food['food_price'] * $food['quantity'], 2);
            $label     = htmlspecialchars_decode($food['food_name']) . ' x' . $food['quantity'];
            if ($altRow) {
                $pdf->SetFillColor(249, 250, 251);
                $pdf->Rect($rowX, $curY, $rowW, $rowH, 'F');
            }
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->SetXY($rowX + 2, $curY + 1.5);
            $pdf->Cell($labelW - 4, 5, $label, 0, 0, 'L');
            $pdf->SetFont('helvetica', 'B', 8.5);
            $pdf->SetTextColor(17, 24, 39);
            $pdf->SetXY($rowX + $labelW, $curY + 1.5);
            $pdf->Cell($valW - 2, 5, chr(8369) . $lineTotal, 0, 0, 'L');
            $pdf->SetDrawColor(208, 216, 240);
            $pdf->Line($rowX, $curY + $rowH, $rowX + $rowW, $curY + $rowH);
            $curY  += $rowH;
            $altRow = !$altRow;
        }
    }

    // ── Payment rows ─────────────────────────────────────────────────────────
    $payRows = [['Payment Method', $payType]];
    if ($refNum) $payRows[] = ['Reference #', $refNum];

    foreach ($payRows as $row) {
        if ($altRow) {
            $pdf->SetFillColor(249, 250, 251);
            $pdf->Rect($rowX, $curY, $rowW, $rowH, 'F');
        }
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetXY($rowX + 2, $curY + 1.5);
        $pdf->Cell($labelW - 4, 5, $row[0], 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetXY($rowX + $labelW, $curY + 1.5);
        $pdf->Cell($valW - 2, 5, $row[1], 0, 0, 'L');
        $pdf->SetDrawColor(208, 216, 240);
        $pdf->Line($rowX, $curY + $rowH, $rowX + $rowW, $curY + $rowH);
        $curY  += $rowH;
        $altRow = !$altRow;
    }

    // ── Payment status row ────────────────────────────────────────────────────
    $pdf->SetFillColor(249, 250, 251);
    $pdf->Rect($rowX, $curY, $rowW, $rowH, 'F');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetXY($rowX + 2, $curY + 1.5);
    $pdf->Cell($labelW - 4, 5, 'Payment Status', 0, 0, 'L');
    $pdf->SetFillColor(34, 139, 80);
    $pdf->RoundedRect($rowX + $labelW, $curY + 1.5, 18, 5, 1.5, '1111', 'F');
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($rowX + $labelW, $curY + 1.5);
    $pdf->Cell(18, 5, 'PAID', 0, 0, 'C');
    $pdf->SetDrawColor(208, 216, 240);
    $pdf->Line($rowX, $curY + $rowH, $rowX + $rowW, $curY + $rowH);
    $curY += $rowH;

    // ── Total paid row ────────────────────────────────────────────────────────
    $pdf->SetFillColor(230, 236, 255);
    $pdf->Rect($rowX, $curY, $rowW, 10, 'F');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(59, 95, 192);
    $pdf->SetXY($rowX + 2, $curY + 2);
    $pdf->Cell($labelW - 4, 6, 'Total Amount Paid', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(15, 26, 46);
    $pdf->SetXY($rowX + $labelW, $curY + 2);
    $pdf->Cell($valW - 2, 6, chr(8369) . number_format($ticket['amount_paid'], 2), 0, 0, 'L');
    $curY += 10;

    // ── Dashed divider before QR ──────────────────────────────────────────────
    $curY += 5;
    $pdf->SetDrawColor(180, 195, 230);
    $pdf->SetLineStyle(['dash' => '2,2', 'width' => 0.4]);
    $pdf->Line($cardX + 6, $curY, $cardX + $cardW - 6, $curY);
    $pdf->SetLineStyle(['dash' => 0, 'width' => 0.3]);
    $curY += 6;

    // ── QR section label ─────────────────────────────────────────────────────
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColor(120, 130, 150);
    $pdf->SetXY($cardX, $curY);
    $pdf->Cell($cardW, 4, 'SCAN QR CODE AT CINEMA ENTRANCE', 0, 0, 'C');
    $curY += 6;

    // QR code image
    if (!empty($qrData)) {
        $qrUrl   = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrData);
        $qrImage = @file_get_contents($qrUrl);
        if ($qrImage !== false) {
            $qrFile = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($qrFile, $qrImage);
            $qrSize = 40;
            $qrX    = $cardX + ($cardW - $qrSize) / 2;
            $pdf->Image($qrFile, $qrX, $curY, $qrSize, $qrSize, 'PNG');
            $curY += $qrSize + 3;
            @unlink($qrFile);
        } else {
            $curY += 5;
        }
        // QR code text
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetTextColor(59, 95, 192);
        $pdf->SetXY($cardX, $curY);
        $pdf->Cell($cardW, 5, $qrData, 0, 0, 'C');
        $curY += 6;
    }

    // ── Footer note ───────────────────────────────────────────────────────────
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetTextColor(150, 160, 180);
    $pdf->SetXY($cardX + 5, $curY);
    $pdf->MultiCell($cardW - 10, 4,
        'This is the official record of your booking at Ticketix Cinema. ' .
        'Present the QR code or ticket number at the cinema entrance.', 0, 'C');

    // ── Bottom accent ─────────────────────────────────────────────────────────
    $pdf->SetFillColor(59, 95, 192);
    $pdf->Rect($cardX, $cardY + 258 - 3, $cardW, 3, 'F');

    // ── Output ────────────────────────────────────────────────────────────────
    $pdfContent = $pdf->Output('', 'S');
    $tempFile   = sys_get_temp_dir() . '/booking_' . $ticket['ticket_id'] . '_' . time() . '.pdf';
    file_put_contents($tempFile, $pdfContent);
    return $tempFile;
}