<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('record_payments');

$loan_id = intval($_GET['loan_id'] ?? 0);
enforce_tenant_resource_access('loans', 'loan_id', $loan_id);
$loan = fetch_one(q(
  "SELECT l.*, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, u.full_name AS officer_name
   FROM loans l
   JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
   LEFT JOIN users u ON u.user_id=l.loan_officer_id AND u.tenant_id=l.tenant_id
   WHERE " . tenant_condition('l.tenant_id') . " AND l.loan_id=?",
  tenant_types("i"),
  tenant_params([$loan_id])
));
if (!$loan) { http_response_code(404); echo "Loan not found"; exit; }
$can_manage_officer = can_access('assign_loan_officer');

// Ensure calculations are fresh before showing the form
recalc_loan($loan_id);
$loan = fetch_one(q(
  "SELECT l.*, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, u.full_name AS officer_name
   FROM loans l
   JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
   LEFT JOIN users u ON u.user_id=l.loan_officer_id AND u.tenant_id=l.tenant_id
   WHERE " . tenant_condition('l.tenant_id') . " AND l.loan_id=?",
  tenant_types("i"),
  tenant_params([$loan_id])
));

$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $amount = floatval($_POST['amount'] ?? 0);
  $date = $_POST['payment_date'] ?? date('Y-m-d');
  $method = trim($_POST['method'] ?? 'CASH');
  $notes = trim($_POST['notes'] ?? '');
  $officer_id = intval($_POST['loan_officer_id'] ?? 0);
  $cheque_number = trim($_POST['cheque_number'] ?? '');
  $cheque_date = $_POST['cheque_date'] ?? null;
  $bank_name = trim($_POST['bank_name'] ?? '');
  $account_holder = trim($_POST['account_holder'] ?? '');
  $bank_reference_no = trim($_POST['bank_reference_no'] ?? '');
  $gcash_reference_no = trim($_POST['gcash_reference_no'] ?? '');

  if ($amount <= 0) $err='Invalid amount.';
  else if ($loan['remaining_balance'] !== null && $amount > floatval($loan['remaining_balance'])) $err='Amount exceeds remaining balance.';
  else if ($method === 'CHEQUE' && $cheque_number === '') $err='Cheque number is required.';
  else if ($method === 'CHEQUE' && $cheque_date === '') $err='Cheque date is required.';
  else if ($method === 'CHEQUE' && $bank_name === '') $err='Bank name is required.';
  else if ($method === 'CHEQUE' && $account_holder === '') $err='Account holder name is required.';
  else if ($method === 'BANK' && $bank_reference_no === '') $err='Bank reference number is required.';
  else if ($method === 'GCASH' && $gcash_reference_no === '') $err='GCash reference number is required.';
  else if (!$can_manage_officer && empty($loan['loan_officer_id'])) $err='A manager or admin must assign a loan officer before this payment can be recorded.';
  else {
    if ($officer_id > 0 && $can_manage_officer) {
      $officer = fetch_one(q(
        "SELECT user_id FROM users WHERE tenant_id=? AND role='LOAN_OFFICER' AND is_active=1 AND user_id=?",
        "ii",
        [$loan['tenant_id'], $officer_id]
      ));
      if (!$officer) {
        $err = 'Loan officer not found for this tenant.';
      } else {
        q("UPDATE loans SET loan_officer_id=? WHERE tenant_id=? AND loan_id=?", "iii", [$officer_id, $loan['tenant_id'], $loan_id]);
      }
    }

    if (!$err) {
      $or = generate_or_no();

      try {
        q(
          "INSERT INTO payments (tenant_id, loan_id, amount, payment_date, method, cheque_number, cheque_date, bank_name, account_holder, bank_reference_no, gcash_reference_no, or_no, loan_officer_id, received_by, notes)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          "iidssssssssssis",
          [$loan['tenant_id'], $loan_id, $amount, $date, $method, $cheque_number ?: null, $cheque_date ?: null, $bank_name ?: null, $account_holder ?: null, $bank_reference_no ?: null, $gcash_reference_no ?: null, $or, $officer_id ?: null, $_SESSION['user_id'], $notes]
        );
        log_activity('Payment Recorded', 'Payment of â‚±' . number_format($amount, 2) . ' recorded via ' . $method . ' - OR#' . $or, $loan_id, $loan['customer_id'], $loan['reference_no']);

        // Keep due-date behavior unchanged; recalc handles the status math.
        recalc_loan($loan_id);

        header("Location: " . APP_BASE . "/staff/loan_view.php?id=" . intval($loan_id));
        exit;
      } catch (Exception $e) {
        $err = 'Error saving payment: ' . $e->getMessage();
      }
    }
  }
}

$loan_officers = fetch_all(q("SELECT user_id, full_name FROM users WHERE tenant_id=? AND role='LOAN_OFFICER' AND is_active=1 ORDER BY full_name", "i", [$loan['tenant_id']]));

$title="Record Payment"; $active="pay";
include __DIR__ . '/_layout_top.php';
?>
<div class="card">
  <h2 style="margin-top:0;margin-bottom:8px">Record Payment</h2>
  <div class="small">Loan: <b><?= htmlspecialchars($loan['reference_no']) ?></b> â€¢ Customer: <?= htmlspecialchars($loan['customer_name']) ?> (<?= htmlspecialchars($loan['customer_no']) ?>)</div>
  
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-top:20px;margin-bottom:20px">
    <div class="card">
      <div class="small" style="color:var(--muted);font-weight:600;margin-bottom:8px">PRINCIPAL</div>
      <div style="font-size:26px;font-weight:800;color:#000000"><?= $loan['principal_amount']===null?'â€”':'â‚±' . number_format($loan['principal_amount'], 2) ?></div>
    </div>
    
    <div class="card">
      <div class="small" style="color:var(--muted);font-weight:600;margin-bottom:8px">TOTAL PAYABLE</div>
      <div style="font-size:26px;font-weight:800;color:#000000"><?= $loan['total_payable']===null?'â€”':'â‚±' . number_format($loan['total_payable'], 2) ?></div>
    </div>
    
    <div class="card">
      <div class="small" style="color:var(--muted);font-weight:600;margin-bottom:8px">REMAINING BALANCE</div>
      <div style="font-size:26px;font-weight:800;color:#000000" id="remaining"><?= $loan['remaining_balance']===null?'â€”':'â‚±' . number_format($loan['remaining_balance'], 2) ?></div>
    </div>
    
    <div class="card">
      <div class="small" style="color:var(--muted);font-weight:600;margin-bottom:8px">PAYMENT TERM</div>
      <div style="font-size:26px;font-weight:800;color:#000000"><?php 
        $terms = array('daily'=>'Daily','weekly'=>'Weekly','semi_monthly'=>'Semi Monthly','monthly'=>'Monthly');
        echo isset($terms[$loan['payment_term']]) ? htmlspecialchars($terms[$loan['payment_term']]) : (htmlspecialchars($loan['payment_term']) ?: 'â€”');
      ?></div>
    </div>
    
    <div class="card">
      <div class="small" style="color:var(--muted);font-weight:600;margin-bottom:8px">INTEREST RATE</div>
      <div style="font-size:26px;font-weight:800;color:#000000"><?php 
        $rate = null;
        if ($loan['payment_term']) {
          $term_rates = array('daily'=>2.75, 'weekly'=>3.0, 'semi_monthly'=>3.50, 'monthly'=>4.0);
          if (isset($term_rates[$loan['payment_term']])) {
            $rate = $term_rates[$loan['payment_term']];
          }
        }
        if (!$rate && $loan['interest_rate']) $rate = $loan['interest_rate'];
        if (!$rate && $loan['status']=='PENDING') $rate = 5.0;
        echo $rate ? htmlspecialchars($rate) . '%' : 'â€”';
        ?></div>
    </div>
  </div>
  
  <?php if($err): ?><div class="alert err" style="margin-top:12px;margin-bottom:12px"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if($ok): ?><div class="alert" style="margin-top:12px;margin-bottom:12px;background-color:#dcfce7;border:1px solid #86efac;color:#166534"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
  
  <form method="post" style="margin-top:20px">
    <div class="grid2">
      <div>
        <label class="label">Payment Amount</label>
        <input class="input" type="number" step="0.01" name="amount" id="amount" required>
        <div class="small" id="after" style="margin-top:6px;color:#000000;font-weight:500"></div>
      </div>
      <div>
        <label class="label">Payment Date</label>
        <input class="input" type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
      </div>
    </div>
    
    <div class="grid2" style="margin-top:16px">
      <div>
        <label class="label">Payment Method</label>
        <select class="input" name="method" id="method" onchange="toggleChequeFields()">
          <option value="CASH">Cash</option>
          <option value="GCASH">GCash</option>
          <option value="BANK">Bank Transfer</option>
          <option value="CHEQUE">Cheque</option>
        </select>
      </div>
      <div>
        <label class="label">Notes (optional)</label>
        <input class="input" name="notes" placeholder="e.g., partial payment">
      </div>
    </div>
    
    <div id="cheque-fields" style="display:none;margin-top:16px">
      <div style="background:#f9f9f9;padding:16px;border-radius:10px">
        <h4 style="margin-top:0">Cheque Details</h4>
        <div class="grid2">
          <div>
            <label class="label">Cheque Number</label>
            <input class="input" type="text" name="cheque_number" id="cheque_number" placeholder="e.g., ABC123456">
          </div>
          <div>
            <label class="label">Cheque Date</label>
            <input class="input" type="date" name="cheque_date" id="cheque_date">
          </div>
        </div>
        <div class="grid2" style="margin-top:10px">
          <div>
            <label class="label">Bank Name</label>
            <input class="input" type="text" name="bank_name" id="bank_name" placeholder="e.g., BDO, BPI, Metrobank">
          </div>
          <div>
            <label class="label">Account Holder Name</label>
            <input class="input" type="text" name="account_holder" id="account_holder" placeholder="Name on the cheque">
          </div>
        </div>
      </div>
    </div>
    
    <div id="bank-fields" style="display:none;margin-top:16px">
      <div style="background:#f9f9f9;padding:16px;border-radius:10px">
        <h4 style="margin-top:0">Bank Transfer Details</h4>
        <div>
          <label class="label">Bank Reference Number / Transaction ID</label>
          <input class="input" type="text" name="bank_reference_no" id="bank_reference_no" placeholder="e.g., REF123456789">
        </div>
      </div>
    </div>
    
    <div id="gcash-fields" style="display:none;margin-top:16px">
      <div style="background:#f9f9f9;padding:16px;border-radius:10px">
        <h4 style="margin-top:0">GCash Details</h4>
        <div>
          <label class="label">GCash Reference Number</label>
          <input class="input" type="text" name="gcash_reference_no" id="gcash_reference_no" placeholder="e.g., REF123456789">
        </div>
      </div>
    </div>
    
    <div style="margin-top:16px">
      <label class="label">Loan Officer</label>
      <?php if ($can_manage_officer): ?>
        <select class="input" name="loan_officer_id" required>
          <option value="">Select Loan Officer</option>
          <?php foreach($loan_officers as $officer): ?>
            <option value="<?= $officer['user_id'] ?>" <?= $loan['loan_officer_id'] == $officer['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($officer['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input class="input" value="<?= htmlspecialchars($loan['officer_name'] ?: 'Not assigned') ?>" readonly>
        <input type="hidden" name="loan_officer_id" value="<?= intval($loan['loan_officer_id'] ?? 0) ?>">
        <div class="small" style="margin-top:6px">Loan officer assignment can only be changed by Manager or Admin.</div>
      <?php endif; ?>
    </div>
    
    <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-primary">Save Payment</button>
      <a class="btn btn-outline" href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($loan_id) ?>">Cancel</a>
    </div>
  </form>
</div>

<style>
  @media(max-width:768px){
    [style*="grid-template-columns:repeat(5,1fr)"] {
      grid-template-columns: repeat(2, 1fr) !important;
    }
  }
  @media(max-width:480px){
    [style*="grid-template-columns:repeat(5,1fr)"] {
      grid-template-columns: 1fr !important;
    }
  }
</style>

<script>
const remaining = <?= $loan['remaining_balance']===null? "null" : floatval($loan['remaining_balance']) ?>;
document.getElementById('amount').addEventListener('input', (e)=>{
  if(remaining===null) return;
  const amt = parseFloat(e.target.value||'0');
  const after = Math.max(0, (remaining-amt)).toFixed(2);
  document.getElementById('after').textContent = "Remaining after: â‚±" + parseFloat(after).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
});

function toggleChequeFields() {
  const method = document.getElementById('method').value;
  const chequeFields = document.getElementById('cheque-fields');
  const bankFields = document.getElementById('bank-fields');
  const gcashFields = document.getElementById('gcash-fields');
  const chequeInputs = ['cheque_number', 'cheque_date', 'bank_name', 'account_holder'];
  const bankInputs = ['bank_reference_no'];
  const gcashInputs = ['gcash_reference_no'];
  
  // Hide all fields first
  chequeFields.style.display = 'none';
  bankFields.style.display = 'none';
  gcashFields.style.display = 'none';
  
  // Clear required flags
  chequeInputs.forEach(id => document.getElementById(id).required = false);
  bankInputs.forEach(id => document.getElementById(id).required = false);
  gcashInputs.forEach(id => document.getElementById(id).required = false);
  
  // Show appropriate fields based on method
  if (method === 'CHEQUE') {
    chequeFields.style.display = 'block';
    chequeInputs.forEach(id => document.getElementById(id).required = true);
  } else if (method === 'BANK') {
    bankFields.style.display = 'block';
    bankInputs.forEach(id => document.getElementById(id).required = true);
  } else if (method === 'GCASH') {
    gcashFields.style.display = 'block';
    gcashInputs.forEach(id => document.getElementById(id).required = true);
  }
}
</script>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
