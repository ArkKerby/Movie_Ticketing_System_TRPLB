<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();

if (!isset($_SESSION['staff_booking'])) {
    header("Location: booking.php");
    exit();
}

$bookingData = json_decode($_SESSION['staff_booking']['booking_data'], true);
$grandTotal  = $_SESSION['staff_booking']['grand_total'];
$seatTotal   = floatval($_SESSION['staff_booking']['seat_total'] ?? $grandTotal);
$movieTitle  = urldecode($bookingData['movie'] ?? '');
$branchName  = urldecode($bookingData['branch'] ?? '');
$showDate    = $bookingData['date'] ?? date('Y-m-d');
$showTime    = $bookingData['time'] ?? '';
$seats       = $bookingData['seats'] ?? [];

// Determine cheapest seat price for PWD/Senior discount (applies to 1 seat only)
$seatsData = $bookingData['seatsData'] ?? [];
$cheapestSeatPrice = 0;
if (!empty($seatsData)) {
    $prices = array_map(fn($s) => floatval($s['price'] ?? 0), $seatsData);
    $cheapestSeatPrice = min($prices);
} elseif (count($seats) > 0) {
    $cheapestSeatPrice = $seatTotal / count($seats); // fallback: average
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment – Staff Portal</title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="staff-main">
    <div class="page-header">
      <h1>Accept Payment</h1>
      <p>Step 4 of 4 — Collect payment from walk-in customer</p>
    </div>

    <?php if (isset($_SESSION['staff_error'])): ?>
      <div style="background: rgba(244, 67, 54, 0.1); border: 1px solid rgba(244, 67, 54, 0.4); color: #f44336; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
        <strong>Error:</strong> <?= htmlspecialchars($_SESSION['staff_error']) ?>
      </div>
      <?php unset($_SESSION['staff_error']); ?>
    <?php endif; ?>

    <!-- STEPS -->
    <div class="steps-bar">
      <div class="step-item done"><span class="step-num">✓</span> Details</div>
      <span class="step-sep">›</span>
      <div class="step-item done"><span class="step-num">✓</span> Seats & Food</div>
      <span class="step-sep">›</span>
      <div class="step-item done"><span class="step-num">✓</span> Checkout</div>
      <span class="step-sep">›</span>
      <div class="step-item active"><span class="step-num">4</span> Payment</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

      <!-- Payment Form -->
      <div class="card">
        <h2>Payment Method</h2>

        <!-- Amount Due Banner -->
        <div id="amountDueBanner" style="background:rgba(85,138,206,0.15);border:1px solid rgba(85,138,206,0.4);border-radius:10px;padding:16px;text-align:center;margin-bottom:24px;">
          <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px;">Amount Due</div>
          <div id="amountDueValue" style="font-size:36px;font-weight:800;color:var(--blue);">₱<?= number_format($grandTotal, 2) ?></div>
          <div id="amountDueOriginal" style="display:none;font-size:14px;color:var(--text-muted);text-decoration:line-through;margin-top:4px;"></div>
          <div id="pwdSavingsLabel" style="display:none;font-size:13px;color:#2ecc71;font-weight:600;margin-top:4px;"></div>
        </div>

        <form id="paymentForm" method="POST" action="process-booking.php">

          <!-- Payment Methods -->
          <div class="payment-methods">

            <!-- CASH -->
            <label class="payment-method" id="pm-cash" onclick="selectMethod('cash')">
              <input type="radio" name="payment_type" value="cash" id="radio-cash">
              <div>
                <div class="payment-method-label">Cash</div>
                <div class="payment-method-sub">Physical cash payment</div>
              </div>
            </label>

            <!-- E-WALLET -->
            <label class="payment-method" id="pm-ewallet" onclick="selectMethod('ewallet')">
              <input type="radio" name="payment_type" value="e-wallet" id="radio-ewallet">
              <div>
                <div class="payment-method-label">E-Wallet</div>
                <div class="payment-method-sub">GCash · PayMaya · GrabPay</div>
              </div>
            </label>
          </div>

          <!-- PWD / Senior ID Discount Section -->
          <div style="margin-top:20px;margin-bottom:20px;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:14px 16px;border:2px solid var(--card-border);border-radius:10px;transition:all 0.2s;" id="pwdToggleLabel">
              <input type="checkbox" id="pwdToggle" name="pwd_discount" value="1" onchange="togglePwdDiscount()" style="width:18px;height:18px;accent-color:var(--blue);cursor:pointer;">
              <div>
                <div style="font-weight:700;font-size:14px;color:var(--text-primary);">PWD / Senior ID Discount</div>
                <div style="font-size:12px;color:var(--text-muted);">20% off 1 seat only (cheapest seat in this transaction)</div>
              </div>
            </label>

            <div id="pwdFields" style="display:none;margin-top:14px;padding:16px;background:rgba(46,204,113,0.08);border:1px solid rgba(46,204,113,0.3);border-radius:10px;">
              <div class="form-group">
                <label for="pwdIdNumber" style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;display:block;">PWD ID Number</label>
                <input type="text" id="pwdIdNumber" name="pwd_id_number" class="form-control" placeholder="Enter PWD ID Number" style="font-size:14px;">
              </div>
            </div>
          </div>

          <!-- CASH Panel -->
          <div class="payment-details-panel" id="panel-cash">
            <div class="form-group">
              <label for="cashReceived">Cash Received (₱)</label>
              <input type="number" id="cashReceived" name="cash_received"
                     class="form-control" placeholder="0.00"
                     step="0.01" min="<?= $grandTotal ?>"
                     oninput="calcChange()"
                     style="font-size:20px;font-weight:700;">
            </div>
            <div id="changeDisplay" class="change-display" style="display:none;"></div>
          </div>

          <!-- E-WALLET Panel -->
          <div class="payment-details-panel" id="panel-ewallet">
            <div class="form-group">
              <label>E-Wallet Provider</label>
              <div style="display:flex;gap:10px;margin-bottom:12px;">
                <?php foreach (['GCash','PayMaya','GrabPay'] as $w): ?>
                <label style="flex:1;cursor:pointer;">
                  <input type="radio" name="ewallet_provider" value="<?= strtolower($w) ?>" style="display:none;" class="ew-radio" data-name="<?= $w ?>">
                  <div class="ewallet-chip" data-val="<?= strtolower($w) ?>"
                       style="border:2px solid var(--card-border);border-radius:8px;padding:10px;text-align:center;font-size:13px;font-weight:600;transition:all 0.2s;cursor:pointer;">
                    <?= $w ?>
                  </div>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            
            <input type="hidden" id="refNumber" name="reference_number" value="">
            
            <button type="button" id="payWithEwalletBtn" style="width:100%; padding: 12px; margin-bottom: 15px; display:none; background-color: var(--blue); color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">
              Pay with E-Wallet
            </button>
            
            <div id="ewalletRefDisplay" style="display:none; background: rgba(85,138,206,0.1); border: 1px solid var(--blue); padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 15px;">
              <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 4px;">Payment Reference Number</div>
              <div id="ewalletRefText" style="font-size: 20px; font-weight: 800; color: var(--blue);"></div>
            </div>
          </div>

          <button type="submit" id="submitBtn" class="btn btn-success btn-lg" style="width:100%;margin-top:16px;" disabled>
             Confirm & Book
          </button>
        </form>
      </div>

      <!-- Right: Booking recap -->
      <div class="card card-sm">
        <h3 style="color:var(--blue);margin-bottom:16px;"> Booking Summary</h3>
        <div class="ticket-detail-row"><span class="ticket-detail-label">Movie</span><span class="ticket-detail-value"><?= htmlspecialchars($movieTitle) ?></span></div>
        <div class="ticket-detail-row"><span class="ticket-detail-label">Branch</span><span class="ticket-detail-value"><?= htmlspecialchars($branchName) ?></span></div>
        <div class="ticket-detail-row"><span class="ticket-detail-label">Show</span><span class="ticket-detail-value"><?= date('M d, Y', strtotime($showDate)) ?><br><?= htmlspecialchars($showTime) ?></span></div>
        <div class="ticket-detail-row"><span class="ticket-detail-label">Seats</span>
          <span class="ticket-detail-value">
            <?php foreach ($seats as $s): ?><span class="ticket-seat-chip"><?= htmlspecialchars($s) ?></span> <?php endforeach; ?>
          </span>
        </div>
        <div class="ticket-detail-row" style="border:none;margin-top:8px;">
          <span class="ticket-detail-label" style="font-size:16px;">Total</span>
          <span class="ticket-detail-value summary-total" style="font-size:20px;">₱<?= number_format($grandTotal, 2) ?></span>
        </div>

        <div class="summary-divider"></div>
        <div style="font-size:12px;color:var(--text-muted);text-align:center;line-height:1.6;">
          Served by: <strong style="color:var(--text-secondary);"><?= staffName() ?></strong>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
const originalGrandTotal = <?= json_encode($grandTotal) ?>;
const seatTotal = <?= json_encode($seatTotal) ?>;
// PWD/Senior discount applies to only 1 seat (the cheapest seat price)
const cheapestSeatPrice = <?= json_encode($cheapestSeatPrice) ?>;
let currentTotal = originalGrandTotal;
let selectedMethod = null;
let pwdEnabled = false;

function getEffectiveTotal() {
  if (pwdEnabled) {
    const discount = cheapestSeatPrice * 0.20;
    return originalGrandTotal - discount;
  }
  return originalGrandTotal;
}

function updateAmountDue() {
  currentTotal = getEffectiveTotal();
  document.getElementById('amountDueValue').textContent = '₱' + currentTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

  const origEl = document.getElementById('amountDueOriginal');
  const savingsEl = document.getElementById('pwdSavingsLabel');
  const banner = document.getElementById('amountDueBanner');

  if (pwdEnabled) {
    const discount = cheapestSeatPrice * 0.20;
    origEl.textContent = '₱' + originalGrandTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    origEl.style.display = 'block';
    savingsEl.textContent = 'PWD/Senior Discount (1 seat): -₱' + discount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    savingsEl.style.display = 'block';
    banner.style.background = 'rgba(46,204,113,0.12)';
    banner.style.borderColor = 'rgba(46,204,113,0.4)';
  } else {
    origEl.style.display = 'none';
    savingsEl.style.display = 'none';
    banner.style.background = 'rgba(85,138,206,0.15)';
    banner.style.borderColor = 'rgba(85,138,206,0.4)';
  }

  // Update cash min attribute
  const cashInput = document.getElementById('cashReceived');
  if (cashInput) cashInput.min = currentTotal;
}

function togglePwdDiscount() {
  const cb = document.getElementById('pwdToggle');
  const fields = document.getElementById('pwdFields');
  const label = document.getElementById('pwdToggleLabel');
  pwdEnabled = cb.checked;

  fields.style.display = pwdEnabled ? 'block' : 'none';
  label.style.borderColor = pwdEnabled ? 'rgba(46,204,113,0.6)' : 'var(--card-border)';
  label.style.background = pwdEnabled ? 'rgba(46,204,113,0.06)' : 'transparent';

  if (!pwdEnabled) {
    document.getElementById('pwdIdNumber').value = '';
  }

  updateAmountDue();
  calcChange();
  validateForm();
}

function selectMethod(method) {
  selectedMethod = method;
  document.querySelectorAll('.payment-method').forEach(el => el.classList.remove('selected'));
  document.querySelectorAll('.payment-details-panel').forEach(el => el.classList.remove('show'));

  document.getElementById('pm-' + method)?.classList.add('selected');
  document.getElementById('panel-' + method)?.classList.add('show');

  if (method === 'cash') {
    document.getElementById('radio-cash').checked = true;
    document.getElementById('cashReceived').focus();
  } else {
    document.getElementById('radio-ewallet').checked = true;
  }
  validateForm();
}

function calcChange() {
  const received = parseFloat(document.getElementById('cashReceived').value) || 0;
  const changeEl = document.getElementById('changeDisplay');
  const diff = received - currentTotal;

  if (received > 0) {
    changeEl.style.display = 'block';
    if (diff >= 0) {
      changeEl.className = 'change-display';
      changeEl.textContent = `Change: ₱${diff.toFixed(2)}`;
    } else {
      changeEl.className = 'change-display insufficient';
      changeEl.textContent = `Insufficient — short by ₱${Math.abs(diff).toFixed(2)}`;
    }
  } else {
    changeEl.style.display = 'none';
  }
  validateForm();
}

function validateForm() {
  const btn = document.getElementById('submitBtn');
  let valid = false;

  if (selectedMethod === 'cash') {
    const rec = parseFloat(document.getElementById('cashReceived').value) || 0;
    valid = rec >= currentTotal;
    btn.style.display = 'block';
  } else if (selectedMethod === 'ewallet') {
    const ref = document.getElementById('refNumber');
    valid = ref && ref.value && ref.value.length > 3;
    if (valid) {
      btn.style.display = 'block';
    } else {
      btn.style.display = 'none';
      btn.disabled = true;
    }
  }

  // PWD ID is optional — discount can be applied without requiring an ID number
  // Staff can still enter it if the customer provides one

  btn.disabled = !valid;
}

// Handle "Pay with..." click
document.getElementById('payWithEwalletBtn')?.addEventListener('click', () => {
  const refInput = document.getElementById('refNumber');
  const checkedRadio = document.querySelector('input[name="ewallet_provider"]:checked');
  if (refInput && checkedRadio) {
    const randomNum = Math.floor(1000000000 + Math.random() * 9000000000);
    const generatedRef = checkedRadio.value.toUpperCase() + '-REF-' + randomNum;
    refInput.value = generatedRef;
    document.getElementById('ewalletRefText').textContent = generatedRef;
    document.getElementById('ewalletRefDisplay').style.display = 'block';
    document.getElementById('payWithEwalletBtn').style.display = 'none';
    validateForm();
  }
});

// E-wallet chip selection
document.querySelectorAll('.ewallet-chip').forEach(chip => {
  chip.addEventListener('click', () => {
    document.querySelectorAll('.ewallet-chip').forEach(c => {
      c.style.borderColor = 'var(--card-border)';
      c.style.background = 'transparent';
      c.style.color = 'var(--text-primary)';
    });
    chip.style.borderColor = 'var(--blue)';
    chip.style.background = 'var(--blue-glass)';
    chip.style.color = 'var(--blue)';
    const label = chip.closest('label');
    const radio = label.querySelector('input[type="radio"]');
    if (radio) {
      radio.checked = true;
      const providerName = radio.getAttribute('data-name');
      const payBtn = document.getElementById('payWithEwalletBtn');
      if (payBtn) {
        payBtn.textContent = `Pay with ${providerName}`;
        payBtn.style.display = 'block';
      }
      document.getElementById('ewalletRefDisplay').style.display = 'none';
      const refInput = document.getElementById('refNumber');
      if (refInput) refInput.value = '';
    }
    validateForm();
  });
});

// Re-validate when PWD fields change
document.getElementById('pwdIdNumber')?.addEventListener('input', validateForm);

</script>
</body>
</html>
