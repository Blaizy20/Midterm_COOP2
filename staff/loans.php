<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('view_loans');

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');

// Handle interest rate update
$update_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_interest'])) {
  require_permission('update_loan_terms');
  $loan_id = isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0;
  $interest_rate = isset($_POST['interest_rate']) ? (float)$_POST['interest_rate'] : 0;
  
  if ($loan_id > 0 && $interest_rate > 0) {
    try {
      enforce_tenant_resource_access('loans', 'loan_id', $loan_id);
      $current_loan = fetch_one(q(
        "SELECT interest_rate, reference_no, customer_id FROM loans WHERE loan_id=? AND " . tenant_condition('tenant_id'),
        tenant_types("i"),
        tenant_params([$loan_id])
      ));
      if ($current_loan) {
        q(
          "UPDATE loans SET interest_rate=? WHERE " . tenant_condition('tenant_id') . " AND loan_id=?",
          tenant_types("di"),
          tenant_params([$interest_rate, $loan_id])
        );
        log_activity('Interest Rate Updated', 'Interest rate changed to ' . number_format($interest_rate, 2) . '%', $loan_id, $current_loan['customer_id'], $current_loan['reference_no']);
        recalc_loan($loan_id);
        // Redirect to refresh the page with updated data
        header("Location: " . APP_BASE . "/staff/loans.php?q=" . urlencode($search) . "&status=" . urlencode($status));
        exit;
      }
    } catch (Exception $e) {
      $update_msg = '<div class="alert red">Update failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
  }
}

$params = tenant_params();
$types = tenant_types();
$sql = "SELECT l.loan_id, l.reference_no, l.status, l.principal_amount, l.interest_rate, l.payment_term, l.remaining_balance, l.submitted_at,
        c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, u.full_name AS officer_name
        FROM loans l 
        JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
        LEFT JOIN users u ON u.user_id=l.loan_officer_id AND u.tenant_id=l.tenant_id";

$where = [tenant_condition('l.tenant_id')];
if ($search !== '') {
  $where[] = "(l.reference_no=? OR l.reference_no LIKE CONCAT('%',?) OR c.customer_no=? OR CONCAT(c.first_name,' ',c.last_name) LIKE CONCAT('%',?,'%'))";
  $types .= "ssss"; $params[] = $search; $params[] = $search; $params[] = $search; $params[] = $search;
}
if ($status !== '') {
  $where[] = "l.status = ?";
  $types .= "s"; $params[] = $status;
}

if (!empty($where)) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY l.submitted_at DESC";

$rows = fetch_all(q($sql, $types, $params));

$title="Loans"; $active="loans";
include __DIR__ . '/_layout_top.php';
?>
<style>
  body {
    background:
      radial-gradient(circle at top, rgba(14, 165, 233, 0.12), transparent 30%),
      linear-gradient(180deg, #020617 0%, #081121 42%, #0f172a 100%);
    color: #e5eefb;
  }

  .topbar {
    background: linear-gradient(135deg, #081121, #0f1b35) !important;
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
    box-shadow: 0 18px 40px rgba(2, 6, 23, 0.35);
  }

  .topbar .small,
  .topbar a.btn.btn-outline {
    color: #d8e4f5 !important;
    border-color: rgba(148, 163, 184, 0.24) !important;
  }

  .layout,
  .main {
    background: transparent;
  }

  .sidebar {
    background: rgba(4, 10, 24, 0.84);
    border-right: 1px solid rgba(148, 163, 184, 0.12);
    backdrop-filter: blur(16px);
  }

  .sidebar h3 {
    color: #7f93b0;
  }

  .sidebar a {
    color: #d7e3f4;
  }

  .sidebar a.active,
  .sidebar a:hover {
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(59, 130, 246, 0.2));
    color: #f8fbff;
  }

  .loans-shell {
    display: grid;
    gap: 20px;
    color: #e5eefb;
  }

  .loans-card {
    border-radius: 26px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    background:
      radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%),
      linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97));
    box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34);
    padding: 24px;
  }

  .loans-header {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: end;
    margin-bottom: 18px;
  }

  .loans-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid rgba(125, 211, 252, 0.24);
    background: rgba(14, 165, 233, 0.12);
    color: #d8f4ff;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .loans-header h2 {
    margin: 10px 0 0;
    font-size: clamp(30px, 4vw, 44px);
    line-height: 1;
    letter-spacing: -0.04em;
    color: #f8fbff;
  }

  .loans-header p {
    margin: 8px 0 0;
    max-width: 780px;
    color: #8ea3bf;
    line-height: 1.7;
    font-size: 14px;
  }

  .loans-filters {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(180px, 220px) auto auto;
    gap: 12px;
    align-items: end;
    margin-bottom: 20px;
  }

  .loans-field {
    display: grid;
    gap: 6px;
  }

  .loans-field .label {
    color: #93a8c6;
    font-size: 11px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
  }

  .loans-card .input {
    background: rgba(15, 23, 42, 0.86);
    color: #f8fbff;
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  .loans-card .input::placeholder {
    color: #6f86a6;
  }

  .loans-card .btn {
    min-height: 42px;
  }

  .loans-card .btn.btn-ghost,
  .loans-card .btn.btn-outline {
    border-color: rgba(148, 163, 184, 0.2);
    color: #d8e4f5;
    background: rgba(15, 23, 42, 0.55);
  }

  .loans-table-wrap {
    overflow-x: auto;
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.12);
  }

  .loans-card .table {
    margin: 0;
    width: 100%;
    color: #e5eefb;
    background: transparent;
  }

  .loans-card .table th {
    background: rgba(15, 23, 42, 0.96);
    color: #93a8c6;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 11px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  }

  .loans-card .table td,
  .loans-card .table th {
    border-color: rgba(148, 163, 184, 0.1);
    padding: 14px 16px;
    vertical-align: middle;
  }

  .loans-card .table tbody tr:nth-child(odd) {
    background: rgba(15, 23, 42, 0.48);
  }

  .loans-card .small {
    color: #8ea3bf;
  }

  .loan-modal {
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, 0.74);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  .loan-modal-card {
    max-width: 420px;
    width: 100%;
    border-radius: 24px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.98), rgba(8, 15, 30, 0.98));
    box-shadow: 0 28px 70px rgba(2, 6, 23, 0.5);
    padding: 24px;
  }

  .loan-modal-card h3 {
    margin-top: 0;
    color: #f8fbff;
  }

  .loan-modal-card .label {
    color: #93a8c6;
  }

  .loan-modal-card .input {
    background: rgba(15, 23, 42, 0.88);
    color: #f8fbff;
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  @media (max-width: 900px) {
    .loans-filters {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 760px) {
    .loans-card,
    .loan-modal-card {
      padding: 18px;
      border-radius: 18px;
    }
  }
</style>

<div class="loans-shell">
<div class="loans-card">
  <div class="loans-header">
    <div>
      <span class="loans-kicker">Loan Workspace</span>
      <h2>Loans</h2>
      <p>Browse active records, filter the pipeline, and open individual loan details from a darker high-contrast workspace.</p>
    </div>
  </div>
  <?php if ($update_msg): echo $update_msg; endif; ?>
  <form method="get" class="loans-filters">
    <div class="loans-field">
      <label class="label">Search</label>
      <input class="input" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search reference/customer no/name">
    </div>
    <div class="loans-field">
      <label class="label">Status</label>
      <select class="input" name="status">
        <option value="">All</option>
        <?php
          $opts = ['PENDING','CI_REVIEWED','DENIED','ACTIVE','OVERDUE','CLOSED'];
          foreach ($opts as $o) {
            $sel = ($status === $o) ? 'selected' : '';
            echo '<option '.$sel.' value="'.htmlspecialchars($o).'">'.htmlspecialchars($o).'</option>';
          }
        ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit">Search</button>
    <a class="btn btn-ghost" href="<?php echo APP_BASE; ?>/staff/loans.php">Reset</a>
  </form>
  <div class="loans-table-wrap">
  <table class="table">
    <thead><tr><th>Reference</th><th>Customer</th><th>Status</th><th>Officer</th><th>Requested</th><th>Payment Term</th><th>Interest</th><th>Remaining</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($rows as $r): ?>
      <?php if (in_array($r['status'], ['ACTIVE','OVERDUE'], true)) recalc_loan($r['loan_id']); ?>
      <?php
        // Calculate interest rate: prioritize custom rate if payment_term is null
        $interest_rate = null;
        // Only use payment_term rate if payment_term is explicitly set AND is not null
        if (!empty($r['payment_term'])) {
          $rates = ['daily' => 2.75, 'weekly' => 3.0, 'semi_monthly' => 3.50, 'monthly' => 4.0];
          $interest_rate = $rates[$r['payment_term']] ?? null;
        }
        // If no interest rate from payment_term, use database interest_rate
        if ($interest_rate === null) {
          $interest_rate = (!empty($r['interest_rate']) && floatval($r['interest_rate']) > 0) ? floatval($r['interest_rate']) : null;
        }
        // Default to 5% for PENDING applications with 0 or no interest set
        if ($interest_rate === null && $r['status'] === 'PENDING') {
          $interest_rate = 5.0;
        }
      ?>
      <tr>
        <td><?= htmlspecialchars($r['reference_no']) ?></td>
        <td><?= htmlspecialchars($r['customer_name']) ?> <span class="small">(<?= htmlspecialchars($r['customer_no']) ?>)</span></td>
        <td><span class="badge <?= status_badge_class($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
        <td><?= $r['officer_name'] ? htmlspecialchars($r['officer_name']) : '—' ?></td>
        <td>₱<?= number_format($r['principal_amount'], 2) ?></td>
        <td><?= $r['payment_term'] ? htmlspecialchars(ucfirst(str_replace('_', ' ', $r['payment_term']))) : '—' ?></td>
        <td>
          <?= $interest_rate !== null ? number_format((float)$interest_rate, 2) : '—' ?>%
        </td>
        <td><?= $r['remaining_balance']===null ? '—' : '₱' . number_format($r['remaining_balance'], 2) ?></td>
        <td><a class="btn btn-outline" href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($r['loan_id']) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if(empty($rows)): ?><tr><td colspan="9" class="small">No loans found.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
</div>

<div id="editModal" class="loan-modal" style="display:none">
  <div class="loan-modal-card">
    <h3 style="margin-top:0">Update Interest Rate</h3>
    <form method="post">
      <input type="hidden" id="modalLoanId" name="loan_id">
      <div style="margin-bottom:12px">
        <label class="label">Loan: <span id="modalRefNo"></span></label>
      </div>
      <div style="margin-bottom:12px">
        <label class="label">New Interest Rate (%)</label>
        <input class="input" type="number" step="0.01" id="modalInterestRate" name="interest_rate" required>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit" name="update_interest" value="1">Update</button>
        <button class="btn btn-outline" type="button" onclick="closeEditModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(loanId, currentRate, refNo) {
  document.getElementById('modalLoanId').value = loanId;
  document.getElementById('modalInterestRate').value = currentRate;
  document.getElementById('modalRefNo').textContent = refNo;
  document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
  document.getElementById('editModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('editModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});
</script>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
