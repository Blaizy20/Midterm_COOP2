<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('edit_payments');

$id = intval($_GET['id'] ?? 0);
error_log("DEBUG: payment_edit.php accessed for payment_id=$id by user=" . ($_SESSION['user_id'] ?? 'unknown'));
enforce_tenant_resource_access('payments', 'payment_id', $id);

$p = fetch_one(q("SELECT p.*, l.reference_no FROM payments p JOIN loans l ON l.loan_id=p.loan_id AND l.tenant_id=p.tenant_id WHERE " . tenant_condition('p.tenant_id') . " AND p.payment_id=?", tenant_types("i"), tenant_params([$id])));
error_log("DEBUG: Payment fetch result: " . ($p ? "Found" : "Not found"));
if (!$p) { http_response_code(404); echo "Payment not found"; exit; }

$current_user = current_user();
error_log("DEBUG: current_user={$current_user['full_name']} (role={$current_user['role']})");
$is_cashier = $current_user['role'] === 'CASHIER';
$otp_required = $is_cashier;

error_log("DEBUG: is_cashier=$is_cashier, otp_required=$otp_required");

// Ensure OTP table exists
ensure_payment_otp_table();
error_log("DEBUG: OTP table ensured");

// Check if OTP is verified
$otp_verified = false;
if (!$is_cashier) {
  $otp_verified = true;  // Admins/Managers don't need OTP
  error_log("DEBUG: Not a cashier - OTP not required");
} else {
  // For cashiers: on GET request, clear any existing verification and require fresh OTP
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Clear any previous verification - require fresh OTP on every edit click
    if (isset($_SESSION['verified_payment_edits'][$id])) {
      unset($_SESSION['verified_payment_edits'][$id]);
      error_log("DEBUG: Cleared previous OTP verification for payment $id - requiring fresh OTP");
    }
    $otp_verified = false;  // Force requirement of fresh OTP
  } else {
    // On POST request, check if OTP was already verified
    $otp_verified = is_payment_edit_verified($id);
  }
  error_log("DEBUG: Cashier check - otp_verified=$otp_verified, method={$_SERVER['REQUEST_METHOD']}");
}

$err=''; $ok='';

// Generate and send OTP for cashiers when page loads (GET request)
if ($otp_required && !$otp_verified && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  error_log("DEBUG: OTP generation condition met - generating OTP");
  try {
    // Always generate new OTP each time page loads
    $otp_code = generate_otp_for_payment_edit($id, $current_user['user_id']);
    error_log("DEBUG: OTP generated: $otp_code");
    
    // Send notification to managers and admins
    $sent = send_otp_notification($id, $otp_code, $p['or_no'], $current_user['full_name']);
    error_log("DEBUG: OTP notification sent result: $sent");
    
    if ($sent) {
      error_log("OTP sent to managers/admins for payment edit: Payment ID=$id, Cashier={$current_user['full_name']}, OR={$p['or_no']}, OTP=$otp_code");
      $ok = 'OTP has been generated and sent to all managers and admins. Please wait for their verification.';
    } else {
      error_log("Failed to send OTP notification for payment edit: Payment ID=$id");
      $ok = 'OTP generated: ' . $otp_code . ' - Note: Failed to send email notification to managers. Please contact your manager directly.';
    }
  } catch (Exception $e) {
    error_log("OTP Generation Error: " . $e->getMessage());
    $err = "OTP Generation Error: " . $e->getMessage();
  }
} else {
  error_log("DEBUG: OTP generation condition NOT met - otp_required=$otp_required, otp_verified=$otp_verified, method={$_SERVER['REQUEST_METHOD']}");
}

// Handle OTP submission
if ($otp_required && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
  $otp_code = trim($_POST['otp_code'] ?? '');
  if (empty($otp_code)) {
    $err = 'Please enter the OTP code.';
  } else {
    $result = verify_otp_for_payment($id, $current_user['user_id'], $otp_code);
    if (!$result['success']) {
      $err = $result['message'];
    } else {
      $otp_verified = true;
      $_SESSION['verified_payment_edits'][$id] = true;
      $ok = 'OTP verified! You can now edit the payment.';
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_payment']) && $otp_verified) {
  $amount = floatval($_POST['amount'] ?? 0);
  $date = $_POST['payment_date'] ?? $p['payment_date'];
  $method = trim($_POST['method'] ?? $p['method']);
  $notes = trim($_POST['notes'] ?? '');
  $cheque_number = trim($_POST['cheque_number'] ?? '');
  $cheque_date = $_POST['cheque_date'] ?? null;
  $bank_name = trim($_POST['bank_name'] ?? '');
  $account_holder = trim($_POST['account_holder'] ?? '');
  $bank_reference_no = trim($_POST['bank_reference_no'] ?? '');
  $gcash_reference_no = trim($_POST['gcash_reference_no'] ?? '');
  
  if ($amount <= 0) $err='Invalid amount.';
  else if ($method === 'CHEQUE' && $cheque_number === '') $err='Cheque number is required.';
  else if ($method === 'CHEQUE' && $cheque_date === '') $err='Cheque date is required.';
  else if ($method === 'CHEQUE' && $bank_name === '') $err='Bank name is required.';
  else if ($method === 'CHEQUE' && $account_holder === '') $err='Account holder name is required.';
  else if ($method === 'BANK' && $bank_reference_no === '') $err='Bank reference number is required.';
  else if ($method === 'GCASH' && $gcash_reference_no === '') $err='GCash reference number is required.';
  else {
    if (is_system_admin()) {
      q("UPDATE payments SET amount=?, payment_date=?, method=?, cheque_number=?, cheque_date=?, bank_name=?, account_holder=?, bank_reference_no=?, gcash_reference_no=?, notes=? WHERE payment_id=?",
        "dsssssssssi", [$amount,$date,$method,$cheque_number ?: null,$cheque_date ?: null,$bank_name ?: null,$account_holder ?: null,$bank_reference_no ?: null,$gcash_reference_no ?: null,$notes,$id]);
    } else {
      q("UPDATE payments SET amount=?, payment_date=?, method=?, cheque_number=?, cheque_date=?, bank_name=?, account_holder=?, bank_reference_no=?, gcash_reference_no=?, notes=? WHERE tenant_id=? AND payment_id=?",
        "dsssssssssii", [$amount,$date,$method,$cheque_number ?: null,$cheque_date ?: null,$bank_name ?: null,$account_holder ?: null,$bank_reference_no ?: null,$gcash_reference_no ?: null,$notes, require_current_tenant_id(), $id]);
    }
    $loan = fetch_one(q("SELECT customer_id, reference_no FROM loans WHERE " . tenant_condition('tenant_id') . " AND loan_id=?", tenant_types("i"), tenant_params([$p['loan_id']])));
    if ($loan) {
      log_activity('Payment Updated', 'Payment of ₱' . number_format($amount, 2) . ' updated via ' . $method . ' - OR#' . $p['or_no'] . ($is_cashier ? ' (OTP Verified)' : ''), $p['loan_id'], $loan['customer_id'], $loan['reference_no']);
    }
    recalc_loan($p['loan_id']);
    header("Location: " . APP_BASE . "/staff/loan_view.php?id=" . intval($p['loan_id']));
    exit;
  }
}

$title="Edit Payment"; $active="pay";
include __DIR__ . '/_layout_top.php';
?>
<div class="card" style="max-width:100%;width:100%">
  <h2 style="margin-top:0">Edit Payment</h2>
  <div class="small">Loan: <b><?= htmlspecialchars($p['reference_no']) ?></b> • OR: <?= htmlspecialchars($p['or_no']) ?></div>
  <?php if($err): ?><div class="alert err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if($ok): ?><div class="alert ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
  
  <?php if($otp_required && !$otp_verified): ?>
    <!-- OTP Verification Form -->
    <div style="margin:20px 0;padding:20px;background:#fef3cd;border:1px solid #ffc107;border-radius:8px;">
      <h3 style="margin-top:0;color:#856404;">⏳ OTP Verification Required</h3>
      <p style="color:#856404;margin:10px 0;"><strong>As a cashier, you must wait for manager/admin approval to edit this payment.</strong></p>
      <p style="color:#666;margin:10px 0;">
        An OTP (One-Time Password) code has been generated and notification sent to all managers and admins. 
        One of them will verify and provide you with the 6-digit code below.
      </p>
      
      <form method="post" style="margin-top:15px">
        <div class="row">
          <div class="col" style="flex:1;min-width:200px;">
            <label class="label">Enter OTP Code from Manager/Admin</label>
            <input class="input" type="text" name="otp_code" placeholder="Enter 6-digit code" maxlength="6" inputmode="numeric" required style="font-size:18px;letter-spacing:3px;text-align:center;">
          </div>
          <div class="col" style="flex:0;display:flex;align-items:flex-end;">
            <button class="btn btn-primary" name="verify_otp" style="width:100%;margin:0;">Verify OTP</button>
          </div>
        </div>
      </form>
      
      <p style="font-size:12px;color:#666;margin-top:15px;font-style:italic;">
        💡 <strong>Tip:</strong> OTP is valid for 15 minutes. If it expires, reload this page to generate a new code. 
        If managers/admins don't respond, contact them directly via phone or in-person.
      </p>
    </div>
  <?php endif; ?>
  
  <!-- Payment Edit Form (shown only after OTP verified for cashiers, or always for admins/managers) -->
  <?php if(!$otp_required || $otp_verified): ?>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="save_payment" value="1">
    <div class="row">
      <div class="col">
        <label class="label">Amount</label>
        <input class="input" type="number" step="0.01" name="amount" value="<?= htmlspecialchars($p['amount']) ?>" required>
      </div>
      <div class="col">
        <label class="label">Payment Date</label>
        <input class="input" type="date" name="payment_date" value="<?= htmlspecialchars($p['payment_date']) ?>" required>
      </div>
    </div>
    <div class="row">
      <div class="col">
        <label class="label">Method</label>
        <select class="input" name="method" id="method" onchange="toggleChequeFields()">
          <?php foreach(['CASH'=>'Cash','GCASH'=>'GCash','BANK'=>'Bank Transfer','CHEQUE'=>'Cheque'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $p['method']===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label class="label">Notes</label>
        <input class="input" name="notes" value="<?= htmlspecialchars($p['notes'] ?? '') ?>">
      </div>
    </div>
    
    <!-- Cheque Fields -->
    <div id="cheque-fields" style="<?= $p['method'] === 'CHEQUE' ? 'display:block' : 'display:none' ?>;margin-top:16px">
      <div style="background:#f9f9f9;padding:16px;border-radius:10px">
        <h4 style="margin-top:0">Cheque Details</h4>
        <div class="row">
          <div class="col">
            <label class="label">Cheque Number</label>
            <input class="input" type="text" name="cheque_number" id="cheque_number" value="<?= htmlspecialchars($p['cheque_number'] ?? '') ?>" placeholder="e.g., ABC123456">
          </div>
          <div class="col">
            <label class="label">Cheque Date</label>
            <input class="input" type="date" name="cheque_date" id="cheque_date" value="<?= htmlspecialchars($p['cheque_date'] ?? '') ?>">
          </div>
        </div>
        <div class="row" style="margin-top:10px">
          <div class="col">
            <label class="label">Bank Name</label>
            <input class="input" type="text" name="bank_name" id="bank_name" value="<?= htmlspecialchars($p['bank_name'] ?? '') ?>" placeholder="e.g., BDO, BPI, Metrobank">
          </div>
          <div class="col">
            <label class="label">Account Holder Name</label>
            <input class="input" type="text" name="account_holder" id="account_holder" value="<?= htmlspecialchars($p['account_holder'] ?? '') ?>" placeholder="Name on the cheque">
          </div>
        </div>
      </div>
    </div>
    
    <!-- Bank Transfer Fields -->
    <div id="bank-fields" style="<?= $p['method'] === 'BANK' ? 'display:block' : 'display:none' ?>;margin-top:16px">
      <div style="background:#f9f9f9;padding:16px;border-radius:10px">
        <h4 style="margin-top:0">Bank Transfer Details</h4>
        <div class="col">
          <label class="label">Bank Reference Number</label>
          <input class="input" type="text" name="bank_reference_no" id="bank_reference_no" value="<?= htmlspecialchars($p['bank_reference_no'] ?? '') ?>" placeholder="Transaction/Reference number">
        </div>
      </div>
    </div>
    
    <!-- GCash Fields -->
    <div id="gcash-fields" style="<?= $p['method'] === 'GCASH' ? 'display:block' : 'display:none' ?>;margin-top:16px">
      <div style="background:#f9f9f9;padding:16px;border-radius:10px">
        <h4 style="margin-top:0">GCash Details</h4>
        <div class="col">
          <label class="label">GCash Reference Number</label>
          <input class="input" type="text" name="gcash_reference_no" id="gcash_reference_no" value="<?= htmlspecialchars($p['gcash_reference_no'] ?? '') ?>" placeholder="Transaction/Reference number">
        </div>
      </div>
    </div>
    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-primary" name="save_payment">Save Changes</button>
      <a class="btn btn-outline" href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($p['loan_id']) ?>">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
function toggleChequeFields() {
  const method = document.getElementById('method').value;
  const chequeFields = document.getElementById('cheque-fields');
  const bankFields = document.getElementById('bank-fields');
  const gcashFields = document.getElementById('gcash-fields');
  const chequeInputs = ['cheque_number', 'cheque_date', 'bank_name', 'account_holder'];
  
  if (method === 'CHEQUE') {
    chequeFields.style.display = 'block';
    bankFields.style.display = 'none';
    gcashFields.style.display = 'none';
    chequeInputs.forEach(id => document.getElementById(id).required = true);
    document.getElementById('bank_reference_no').required = false;
    document.getElementById('gcash_reference_no').required = false;
  } else if (method === 'BANK') {
    chequeFields.style.display = 'none';
    bankFields.style.display = 'block';
    gcashFields.style.display = 'none';
    chequeInputs.forEach(id => document.getElementById(id).required = false);
    document.getElementById('bank_reference_no').required = true;
    document.getElementById('gcash_reference_no').required = false;
  } else if (method === 'GCASH') {
    chequeFields.style.display = 'none';
    bankFields.style.display = 'none';
    gcashFields.style.display = 'block';
    chequeInputs.forEach(id => document.getElementById(id).required = false);
    document.getElementById('bank_reference_no').required = false;
    document.getElementById('gcash_reference_no').required = true;
  }
}
</script>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
