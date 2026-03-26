<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('view_loan_details');

$role = $_SESSION['role'] ?? '';
$id = intval($_GET['id'] ?? 0);
enforce_tenant_resource_access('loans', 'loan_id', $id);

// 1. Initial Fetch to check existence
$loan = fetch_one(q("SELECT l.*, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.contact_no, u.full_name AS officer_name
                    FROM loans l 
                    JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
                    LEFT JOIN users u ON u.user_id=l.loan_officer_id AND u.tenant_id=l.tenant_id
                    WHERE " . tenant_condition('l.tenant_id') . " AND l.loan_id=?", tenant_types("i"), tenant_params([$id])));

if (!$loan) { http_response_code(404); echo "Loan not found"; exit; }

// 2. Trigger Recalculation (Uses the fixed logic in loan_helpers.php)
if (in_array($loan['status'], ['APPROVED','ACTIVE','OVERDUE'], true)) {
  recalc_loan($loan['loan_id']);
  
  // 3. Re-fetch fresh data after calculation
  $loan = fetch_one(q("SELECT l.*, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.contact_no, u.full_name AS officer_name
                    FROM loans l 
                    JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
                    LEFT JOIN users u ON u.user_id=l.loan_officer_id AND u.tenant_id=l.tenant_id
                    WHERE " . tenant_condition('l.tenant_id') . " AND l.loan_id=?", tenant_types("i"), tenant_params([$id])));
}

$reqs = fetch_all(q("SELECT * FROM requirements WHERE " . tenant_condition('tenant_id') . " AND loan_id=? ORDER BY uploaded_at DESC", tenant_types("i"), tenant_params([$id])));

// Handle payment date filtering
$payment_filter_from = trim($_GET['from'] ?? '');
$payment_filter_to = trim($_GET['to'] ?? '');
$payment_filter_range = trim($_GET['range'] ?? '');

$payment_params = tenant_params([$id]);
$payment_types = tenant_types('i');
$payment_where = '';

if ($payment_filter_range === 'week') {
  $payment_where = " AND DATE(p.payment_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($payment_filter_range === 'month') {
  $payment_where = " AND DATE(p.payment_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
} elseif ($payment_filter_from && $payment_filter_to) {
  $payment_where = " AND DATE(p.payment_date) BETWEEN ? AND ?";
  $payment_types .= 'ss';
  $payment_params[] = $payment_filter_from;
  $payment_params[] = $payment_filter_to;
}

$payments = fetch_all(q("SELECT p.*, u.full_name AS cashier_name FROM payments p LEFT JOIN users u ON u.user_id=p.received_by WHERE " . tenant_condition('p.tenant_id') . " AND p.loan_id=?$payment_where ORDER BY p.payment_date DESC, p.payment_id DESC", $payment_types, $payment_params));

$err=''; $ok='';

// CI action: mark reviewed
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ci_review'])) {
  require_permission('review_applications');
  if (is_system_admin()) {
    q("UPDATE loans SET status='CI_REVIEWED', ci_by=?, ci_at=NOW(), notes=? WHERE loan_id=? AND status='PENDING'",
      "isi", [$_SESSION['user_id'], trim($_POST['ci_notes'] ?? ''), $id]);
  } else {
    q("UPDATE loans SET status='CI_REVIEWED', ci_by=?, ci_at=NOW(), notes=? WHERE tenant_id=? AND loan_id=? AND status='PENDING'",
      "isii", [$_SESSION['user_id'], trim($_POST['ci_notes'] ?? ''), require_current_tenant_id(), $id]);
  }
  log_activity('CI Review', 'Loan marked as CI reviewed - ' . trim($_POST['ci_notes'] ?? ''), $id, $loan['customer_id'], $loan['reference_no']);
  $ok='CI review submitted.';
  header("Location: " . APP_BASE . "/staff/loan_view.php?id=$id"); exit;
}

// Manager action: approve/deny
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['manager_decision'])) {
  require_permission('approve_applications');
  $decision = $_POST['decision'] ?? '';
  if ($decision==='APPROVE') {
    // Set approved + activate as ACTIVE
    $term = intval($loan['term_months']);
    $due = date('Y-m-d', strtotime("+$term months"));
    if (is_system_admin()) {
      q(
        "UPDATE loans SET status='ACTIVE', manager_by=?, manager_at=NOW(), activated_at=NOW(), due_date=?, notes=? WHERE loan_id=? AND status IN ('CI_REVIEWED','PENDING')",
        "issi",
        [$_SESSION['user_id'], $due, trim($_POST['manager_notes'] ?? ''), $id]
      );
    } else {
      q(
        "UPDATE loans SET status='ACTIVE', manager_by=?, manager_at=NOW(), activated_at=NOW(), due_date=?, notes=? WHERE tenant_id=? AND loan_id=? AND status IN ('CI_REVIEWED','PENDING')",
        "issii",
        [$_SESSION['user_id'], $due, trim($_POST['manager_notes'] ?? ''), require_current_tenant_id(), $id]
      );
    }
    log_activity('Loan Approved', 'Loan approved and activated - ' . trim($_POST['manager_notes'] ?? ''), $id, $loan['customer_id'], $loan['reference_no']);
    recalc_loan($id);
    $ok='Loan approved and activated.';
    header("Location: " . APP_BASE . "/staff/loan_view.php?id=$id"); exit;
  } else if ($decision==='DENY') {
    if (is_system_admin()) {
      q("UPDATE loans SET status='DENIED', manager_by=?, manager_at=NOW(), notes=? WHERE loan_id=? AND status IN ('CI_REVIEWED','PENDING')",
        "isi", [$_SESSION['user_id'], trim($_POST['manager_notes'] ?? ''), $id]);
    } else {
      q("UPDATE loans SET status='DENIED', manager_by=?, manager_at=NOW(), notes=? WHERE tenant_id=? AND loan_id=? AND status IN ('CI_REVIEWED','PENDING')",
        "isii", [$_SESSION['user_id'], trim($_POST['manager_notes'] ?? ''), require_current_tenant_id(), $id]);
    }
    log_activity('Loan Denied', 'Loan denied - ' . trim($_POST['manager_notes'] ?? ''), $id, $loan['customer_id'], $loan['reference_no']);
    $ok='Loan denied.';
    header("Location: " . APP_BASE . "/staff/loan_view.php?id=$id"); exit;
  } else {
    $err='Invalid decision.';
  }
}

// Manager update interest (for active loans)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_terms'])) {
  require_permission('update_loan_terms');
  $update_type = $_POST['update_type'] ?? 'both';
  $rate = floatval($_POST['interest_rate_update'] ?? 0);
  $payment_term = trim($_POST['payment_term_update'] ?? '');
  
  // Update only interest rate
  if ($update_type === 'interest_only') {
    if ($rate <= 0) {
      $err='Invalid interest rate.';
    } else {
      if (is_system_admin()) {
        q("UPDATE loans SET interest_rate=? WHERE loan_id=?", "di", [$rate, $id]);
      } else {
        q("UPDATE loans SET interest_rate=? WHERE tenant_id=? AND loan_id=?", "dii", [$rate, require_current_tenant_id(), $id]);
      }
      log_activity('Interest Rate Updated', 'Interest rate changed to ' . number_format($rate, 2) . '%', $id, $loan['customer_id'], $loan['reference_no']);
      recalc_loan($id);
      $ok='Interest rate updated.';
      // Refetch
      $loan = fetch_one(q("SELECT l.*, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.contact_no, u.full_name AS officer_name FROM loans l JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id LEFT JOIN users u ON u.user_id=l.loan_officer_id AND u.tenant_id=l.tenant_id WHERE " . tenant_condition('l.tenant_id') . " AND l.loan_id=?", tenant_types("i"), tenant_params([$id])));
    }
  }
  // Update only payment term
  else if ($update_type === 'term_only') {
    if ($payment_term && in_array($payment_term, ['daily','weekly','semi_monthly','monthly'], true)) {
      if (is_system_admin()) {
        q("UPDATE loans SET payment_term=? WHERE loan_id=?", "si", [$payment_term, $id]);
      } else {
        q("UPDATE loans SET payment_term=? WHERE tenant_id=? AND loan_id=?", "sii", [$payment_term, require_current_tenant_id(), $id]);
      }
      $term_names = ['daily' => 'Daily', 'weekly' => 'Weekly', 'semi_monthly' => 'Semi-Monthly', 'monthly' => 'Monthly'];
      log_activity('Payment Term Updated', 'Payment term changed to ' . $term_names[$payment_term], $id, $loan['customer_id'], $loan['reference_no']);
      recalc_loan($id);
      $ok='Payment term updated.';
      // Refetch
      $loan = fetch_one(q("SELECT l.*, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.contact_no, u.full_name AS officer_name FROM loans l JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id LEFT JOIN users u ON u.user_id=l.loan_officer_id AND u.tenant_id=l.tenant_id WHERE " . tenant_condition('l.tenant_id') . " AND l.loan_id=?", tenant_types("i"), tenant_params([$id])));
    } else {
      $err='Please select a valid payment term.';
    }
  }
}

// Assign loan officer
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['assign_officer'])) {
  require_permission('assign_loan_officer');
  $officer_id = intval($_POST['loan_officer_id'] ?? 0);
  if ($officer_id <= 0) $err='Please select a loan officer.';
  else {
    $officer = fetch_one(q("SELECT user_id, full_name FROM users WHERE role='LOAN_OFFICER' AND is_active=1 AND " . tenant_condition('tenant_id') . " AND user_id=?", tenant_types("i"), tenant_params([$officer_id])));
    if (!$officer) $err='Loan officer not found.';
    else {
      if (is_system_admin()) {
        q("UPDATE loans SET loan_officer_id=? WHERE loan_id=?", "ii", [$officer_id, $id]);
      } else {
        q("UPDATE loans SET loan_officer_id=? WHERE tenant_id=? AND loan_id=?", "iii", [$officer_id, require_current_tenant_id(), $id]);
      }
      log_activity('Loan Officer Assigned', 'Loan officer assigned to ' . htmlspecialchars($officer['full_name']), $id, $loan['customer_id'], $loan['reference_no']);
      $ok='Loan officer assigned.';
      header("Location: " . APP_BASE . "/staff/loan_view.php?id=$id"); exit;
    }
  }
}

$title="Loan Details"; $active="loans";
include __DIR__ . '/_layout_top.php';
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div>
      <h2 style="margin-top:0">Loan: <?= htmlspecialchars($loan['reference_no']) ?></h2>
      <div class="small">Customer: <b><?= htmlspecialchars($loan['customer_name']) ?></b> (<?= htmlspecialchars($loan['customer_no']) ?>) • <?= htmlspecialchars($loan['contact_no']) ?></div>
      <div style="margin-top:10px">
        <span class="badge <?= status_badge_class($loan['status']) ?>"><?= htmlspecialchars($loan['status']) ?></span>
      </div>
    </div>
    <div style="text-align:right">
      <div class="small">Submitted</div>
      <div><?= htmlspecialchars($loan['submitted_at']) ?></div>
      <?php if($loan['due_date']): ?><div class="small" style="margin-top:8px">Due Date</div><div><?= htmlspecialchars($loan['due_date']) ?></div><?php endif; ?>
    </div>
  </div>

  <?php if($err): ?><div class="alert err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if($ok): ?><div class="alert ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

  <div class="row" style="margin-top:10px">
    <div class="col">
      <div class="card" style="background:#fff;min-height:95px;display:flex;flex-direction:column;justify-content:space-between">
        <div>
          <div class="small">Principal</div>
          <div style="font-size:20px;font-weight:800">₱<?= number_format($loan['principal_amount'], 2) ?></div>
        </div>
      </div>
    </div>
    <div class="col">
      <div class="card" style="background:#fff;min-height:95px;display:flex;flex-direction:column;justify-content:space-between">
        <div>
          <div class="small">Payment Term</div>
          <div style="font-size:18px;font-weight:800;white-space:nowrap"><?php 
            $pt = trim($loan['payment_term'] ?? '');
            if (!empty($pt)) {
              $terms = ['daily' => 'Daily', 'weekly' => 'Weekly', 'semi_monthly' => 'Semi Monthly', 'monthly' => 'Monthly'];
              echo isset($terms[$pt]) ? htmlspecialchars($terms[$pt]) : htmlspecialchars(ucfirst(str_replace('_', ' ', $pt)));
            } else {
              echo 'Not Set';
            }
          ?></div>
        </div>
      </div>
    </div>
    <div class="col">
      <div class="card" style="background:#fff;min-height:95px;display:flex;flex-direction:column;justify-content:space-between">
        <div>
          <div class="small">Interest Rate</div>
          <div style="font-size:20px;font-weight:800"><?php 
            // Display exactly what is in the DB
            $ir = floatval($loan['interest_rate']);
            echo ($ir > 0) ? htmlspecialchars($ir) . '%' : '—';
          ?></div>
        </div>
      </div>
    </div>
    <div class="col">
      <div class="card" style="background:#fff;min-height:95px;display:flex;flex-direction:column;justify-content:space-between">
        <div>
          <div class="small">Total Payable</div>
          <div style="font-size:20px;font-weight:800"><?= $loan['total_payable']===null?'—':'₱' . number_format($loan['total_payable'], 2) ?></div>
        </div>
      </div>
    </div>
    <div class="col">
      <div class="card" style="background:#fff;min-height:95px;display:flex;flex-direction:column;justify-content:space-between">
        <div>
          <div class="small">Remaining</div>
          <div style="font-size:20px;font-weight:800"><?= $loan['remaining_balance']===null?'—':'₱' . number_format($loan['remaining_balance'], 2) ?></div>
        </div>
      </div>
    </div>
    <div class="col">
      <div class="card" style="background:#fff;min-height:95px;display:flex;flex-direction:column;justify-content:space-between">
        <div>
          <div class="small">Late Fee (Est)</div>
          <div style="font-size:20px;font-weight:800"><?php 
            // Calculate strictly for display purposes using the helper
            $display_late_fee = 0.0;
            if ($loan['status'] === 'OVERDUE') {
              // We recalculate just to show the user how much of the Total is Late Fee
              // Note: Total Payable in DB already includes this fee due to recalc_loan()
              $display_late_fee = calculate_late_fee($loan['loan_id'], $loan['payment_term'], null); 
            }
            echo $display_late_fee > 0 ? '₱' . number_format($display_late_fee, 2) : '—';
          ?></div>
        </div>
      </div>
    </div>
    <div class="col">
      <div class="card" style="background:#fff;min-height:95px;display:flex;flex-direction:column;justify-content:space-between">
        <div>
          <div class="small" style="font-size:12px">Loan Officer</div>
          <div style="font-size:18px;font-weight:800"><?= $loan['officer_name'] ? htmlspecialchars($loan['officer_name']) : '—' ?></div>
        </div>
      </div>
    </div>
  </div>

  <div style="height:14px"></div>

  <div class="row">
    <div class="col">
      <div class="card" style="background:#fff">
        <h3 style="margin-top:0">Submitted Requirements</h3>
        <table class="table">
          <thead><tr><th>Requirement</th><th>Uploaded</th><th>Notes</th><th>File</th></tr></thead>
          <tbody>
            <?php foreach($reqs as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['requirement_name']) ?></td>
                <td><?= htmlspecialchars($r['uploaded_at']) ?></td>
                <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
                <td><a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/download_requirement.php?id=<?= intval($r['requirement_id']) ?>">View/Download</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($reqs)): ?><tr><td colspan="4" class="small">No requirements uploaded.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div style="height:14px"></div>

  <?php 
    // Parse co-maker information from notes
    $comaker_info = '';
    $comaker_full_name = '';
    $comaker_id_type = '';
    $comaker_contact = '';
    $comaker_email = '';
    $comaker_address = '';
    
    if (!empty($loan['notes'])) {
      $notes = $loan['notes'];
      // Check if it contains co-maker info
      if (strpos($notes, 'Co-maker:') !== false) {
        preg_match('/Co-maker:\s*([^|]+)/', $notes, $name_match);
        if ($name_match) $comaker_full_name = trim($name_match[1]);
        
        preg_match('/ID Type:\s*([^|]+)/', $notes, $id_type_match);
        if ($id_type_match) $comaker_id_type = trim($id_type_match[1]);
        
        preg_match('/Contact:\s*([^|]+)/', $notes, $contact_match);
        if ($contact_match) $comaker_contact = trim($contact_match[1]);
        
        preg_match('/Email:\s*([^|]+)/', $notes, $email_match);
        if ($email_match) $comaker_email = trim($email_match[1]);
        
        preg_match('/Address:\s*(.+)$/', $notes, $address_match);
        if ($address_match) $comaker_address = trim($address_match[1]);
      }
    }
    
    // Filter co-maker related requirements
    $comaker_reqs = array_filter($reqs, function($r) {
      return strpos($r['requirement_code'], 'COMAKER') === 0;
    });
  ?>

  <?php if (!empty($comaker_full_name) || !empty($comaker_reqs)): ?>
  <div class="row">
    <div class="col">
      <div class="card" style="background:#fff">
        <h3 style="margin-top:0">Co-Maker Information</h3>
        
        <?php if (!empty($comaker_full_name)): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #ddd">
          <div>
            <div class="small" style="color:#666">Full Name</div>
            <div style="font-weight:600"><?= htmlspecialchars($comaker_full_name) ?></div>
          </div>
          <div>
            <div class="small" style="color:#666">Valid ID Type</div>
            <div style="font-weight:600"><?= htmlspecialchars($comaker_id_type) ?></div>
          </div>
          <div>
            <div class="small" style="color:#666">Contact Number</div>
            <div style="font-weight:600"><?= htmlspecialchars($comaker_contact) ?></div>
          </div>
          <div>
            <div class="small" style="color:#666">Email Address</div>
            <div style="font-weight:600"><?= htmlspecialchars($comaker_email) ?></div>
          </div>
          <div style="grid-column:1/-1">
            <div class="small" style="color:#666">Address</div>
            <div style="font-weight:600"><?= htmlspecialchars($comaker_address) ?></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($comaker_reqs)): ?>
        <div>
          <div class="small" style="color:#666;margin-bottom:10px">Attached Documents</div>
          <table class="table">
            <thead><tr><th>Document</th><th>Uploaded</th><th>File</th></tr></thead>
            <tbody>
              <?php foreach($comaker_reqs as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['requirement_name']) ?></td>
                  <td><?= htmlspecialchars($r['uploaded_at']) ?></td>
                  <td><a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/download_requirement.php?id=<?= intval($r['requirement_id']) ?>">View/Download</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div style="height:14px"></div>

  <div class="row">
    <div class="col">
      <div class="card" style="background:#fff">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;gap:20px">
          <h3 style="margin:0">Payments</h3>
          <form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;justify-content:flex-end">
            <input type="hidden" name="id" value="<?= intval($id) ?>">
            <div>
              <label class="label" style="margin-bottom:4px">Filter</label>
              <select class="input" name="range" onchange="this.form.submit()">
                <option value="">All</option>
                <option value="week" <?= ($payment_filter_range === 'week') ? 'selected' : '' ?>>Last Week</option>
                <option value="month" <?= ($payment_filter_range === 'month') ? 'selected' : '' ?>>Last Month</option>
                <option value="custom" <?= ($payment_filter_from && $payment_filter_to) ? 'selected' : '' ?>>Custom Range</option>
              </select>
            </div>
            
            <?php if ($payment_filter_range === 'custom' || ($payment_filter_from && $payment_filter_to)): ?>
              <div>
                <input class="input" type="date" name="from" value="<?= htmlspecialchars($payment_filter_from) ?>" style="min-width:130px">
              </div>
              <div>
                <input class="input" type="date" name="to" value="<?= htmlspecialchars($payment_filter_to) ?>" style="min-width:130px">
              </div>
              <button class="btn btn-primary" type="submit" style="padding:8px 12px">Filter</button>
              <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($id) ?>" style="padding:8px 12px">Reset</a>
            <?php endif; ?>
          </form>
        </div>
        <div style="height:10px"></div>
        <table class="table">
          <thead><tr><th>OR No</th><th>Date</th><th>Amount</th><th>Method</th><th>Details</th><th>Notes</th><th>Received By</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach($payments as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['or_no']) ?></td>
                <td><?= htmlspecialchars($p['payment_date']) ?></td>
                <td>₱<?= number_format($p['amount'], 2) ?></td>
                <td><?= htmlspecialchars($p['method'] ?? '') ?></td>
                <td>
                  <?php if ($p['method'] === 'CHEQUE'): ?>
                    <div class="small" style="background:#f0f0f0;padding:6px;border-radius:4px;margin:0">
                      <strong>Cheque #:</strong> <?= htmlspecialchars($p['cheque_number'] ?? '—') ?><br>
                      <strong>Date:</strong> <?= htmlspecialchars($p['cheque_date'] ?? '—') ?><br>
                      <strong>Bank:</strong> <?= htmlspecialchars($p['bank_name'] ?? '—') ?><br>
                      <strong>Holder:</strong> <?= htmlspecialchars($p['account_holder'] ?? '—') ?>
                    </div>
                  <?php elseif ($p['method'] === 'BANK'): ?>
                    <div class="small" style="background:#f0f0f0;padding:6px;border-radius:4px;margin:0">
                      <strong>Reference #:</strong> <?= htmlspecialchars($p['bank_reference_no'] ?? '—') ?>
                    </div>
                  <?php elseif ($p['method'] === 'GCASH'): ?>
                    <div class="small" style="background:#f0f0f0;padding:6px;border-radius:4px;margin:0">
                      <strong>Reference #:</strong> <?= htmlspecialchars($p['gcash_reference_no'] ?? '—') ?>
                    </div>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($p['notes'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['cashier_name'] ?? '') ?></td>
                <td style="display:flex;gap:6px;flex-wrap:wrap">
                  <?php if (can_access('print_receipts')): ?>
                    <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/payment_receipt.php?id=<?= intval($p['payment_id']) ?>" style="font-size:12px">Receipt</a>
                  <?php endif; ?>
                  <?php if (can_access('edit_payments')): ?>
                    <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/payment_edit.php?id=<?= intval($p['payment_id']) ?>" style="font-size:12px">Edit</a>
                  <?php endif; ?>
                  <?php if (!can_access('print_receipts') && !can_access('edit_payments')): ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($payments)): ?><tr><td colspan="7" class="small">No payments yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
        <div style="margin-top:12px;display:flex;justify-content:flex-end">
          <?php if (can_access('record_payments') && in_array($loan['status'], ['ACTIVE','OVERDUE'], true)): ?>
            <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/payment_add.php?loan_id=<?= intval($loan['loan_id']) ?>">Record Payment</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div style="height:14px"></div>

  <?php if (in_array($loan['status'], ['PENDING'], true) && can_access('review_applications')): ?>
    <div class="card" style="background:#fff">
      <h3 style="margin-top:0">CI Review</h3>
      <form method="post">
        <label class="label">Remarks</label>
        <input class="input" name="ci_notes" placeholder="Verification notes (optional)">
        <div style="margin-top:10px">
          <button class="btn btn-primary" name="ci_review" value="1">Mark as CI Reviewed</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <?php if (in_array($loan['status'], ['PENDING','CI_REVIEWED'], true) && can_access('approve_applications')): ?>
    <div style="height:14px"></div>
    <div class="card" style="background:#fff">
      <h3 style="margin-top:0">Manager Decision</h3>
      <form method="post">
        <label class="label">Remarks</label>
        <input class="input" name="manager_notes" placeholder="Approval/denial notes (optional)">
        <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn btn-primary" name="manager_decision" value="1" type="submit" onclick="document.getElementById('decision').value='APPROVE'">Approve</button>
          <button class="btn btn-primary" name="manager_decision" value="1" type="submit" onclick="document.getElementById('decision').value='DENY'">Deny</button>
        </div>
        <input type="hidden" id="decision" name="decision" value="APPROVE">
      </form>
    </div>
  <?php endif; ?>

  <?php if (in_array($loan['status'], ['ACTIVE','OVERDUE','APPROVED'], true) && can_access('update_loan_terms')): ?>
    <div style="height:14px"></div>
    <div class="card" style="background:#fff">
      <h3 style="margin-top:0">Update Loan Terms</h3>
      <form method="post">
        <div class="grid2">
          <div>
            <label class="label">Interest Rate (%)</label>
            <input class="input" type="number" step="0.01" name="interest_rate_update" placeholder="Enter new interest rate" value="<?= htmlspecialchars($loan['interest_rate']) ?>">
            <button class="btn btn-primary" name="update_terms" value="1" type="submit" onclick="document.getElementById('update_type').value='interest_only'" style="margin-top:8px">Update Interest Rate Only</button>
          </div>
          <div>
            <label class="label">Payment Term</label>
            <select class="input" name="payment_term_update">
              <option value="">-- Select Payment Term --</option>
              <option value="daily" <?= ($loan['payment_term'] === 'daily') ? 'selected' : '' ?>>Daily</option>
              <option value="weekly" <?= ($loan['payment_term'] === 'weekly') ? 'selected' : '' ?>>Weekly</option>
              <option value="semi_monthly" <?= ($loan['payment_term'] === 'semi_monthly') ? 'selected' : '' ?>>Semi-Monthly</option>
              <option value="monthly" <?= ($loan['payment_term'] === 'monthly') ? 'selected' : '' ?>>Monthly</option>
            </select>
            <button class="btn btn-primary" name="update_terms" value="1" type="submit" onclick="document.getElementById('update_type').value='term_only'" style="margin-top:8px">Update Payment Term Only</button>
          </div>
        </div>
        <input type="hidden" id="update_type" name="update_type" value="">
      </form>
    </div>
  <?php endif; ?>

  <?php if (can_access('assign_loan_officer')): ?>
    <div style="height:14px"></div>
    <div class="card" style="background:#fff">
      <h3 style="margin-top:0">Loan Officer Assignment</h3>
      
      <form method="post">
        <label class="label">Loan Officer</label>
        <select class="input" name="loan_officer_id">
          <option value="">-- Select Officer --</option>
          <?php
            $staff = fetch_all(q("SELECT user_id, full_name FROM users WHERE role='LOAN_OFFICER' AND is_active=1 AND " . tenant_condition('tenant_id') . " ORDER BY full_name", tenant_types(), tenant_params()));
            foreach ($staff as $s) {
              $selected = ($loan['loan_officer_id'] == $s['user_id']) ? 'selected' : '';
              echo '<option '.$selected.' value="'.intval($s['user_id']).'">'.htmlspecialchars($s['full_name']).'</option>';
            }
          ?>
        </select>
        <div style="margin-top:10px">
          <button class="btn btn-primary" name="assign_officer" value="1">Assign Officer</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

</div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
