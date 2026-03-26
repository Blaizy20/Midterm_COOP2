<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('view_payments');

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$method = trim($_GET['method'] ?? '');

// Query to get payments with loan and customer information
$sql = "SELECT p.payment_id, p.amount, p.payment_date, p.method, p.or_no, p.notes, 
        l.loan_id, l.reference_no, l.status, l.remaining_balance,
        c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name,
        u.full_name AS received_by_name
        FROM payments p
        JOIN loans l ON l.loan_id=p.loan_id AND l.tenant_id=p.tenant_id
        JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
        LEFT JOIN users u ON u.user_id=p.received_by
        WHERE " . tenant_condition('p.tenant_id');

$types = tenant_types();
$params = tenant_params();

// Add status filter if provided
if ($status !== '' && in_array($status, ['ACTIVE','OVERDUE'], true)) {
  $sql .= " AND l.status = ?";
  $types .= "s"; $params[] = $status;
}

// Add payment method filter if provided
if ($method !== '' && in_array($method, ['CASH','GCASH','BANK','CHEQUE'], true)) {
  $sql .= " AND p.method = ?";
  $types .= "s"; $params[] = $method;
}

// Add search filter if provided
if ($search !== '') {
  $sql .= " AND (l.reference_no=? OR c.customer_no=? OR CONCAT(c.first_name,' ',c.last_name) LIKE CONCAT('%',?,'%'))";
  $types .= "sss"; $params[] = $search; $params[] = $search; $params[] = $search;
}

$sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC";
$rows = fetch_all(q($sql, $types, $params));

$title="Payments"; $active="pay";
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

  .payments-shell {
    display: grid;
    gap: 20px;
    color: #e5eefb;
  }

  .payments-card {
    border-radius: 26px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    background:
      radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%),
      linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97));
    box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34);
    padding: 24px;
  }

  .payments-header {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: end;
    margin-bottom: 18px;
  }

  .payments-kicker {
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

  .payments-header h2 {
    margin: 10px 0 0;
    font-size: clamp(30px, 4vw, 44px);
    line-height: 1;
    letter-spacing: -0.04em;
    color: #f8fbff;
  }

  .payments-header p {
    margin: 8px 0 0;
    max-width: 780px;
    color: #8ea3bf;
    line-height: 1.7;
    font-size: 14px;
  }

  .payments-filters {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(150px, 180px) minmax(150px, 180px) auto auto;
    gap: 12px;
    align-items: end;
    margin-bottom: 20px;
  }

  .payments-field {
    display: grid;
    gap: 6px;
  }

  .payments-field .label {
    color: #93a8c6;
    font-size: 11px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
  }

  .payments-card .input {
    background: rgba(15, 23, 42, 0.86);
    color: #f8fbff;
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  .payments-card .input::placeholder {
    color: #6f86a6;
  }

  .payments-card .btn.btn-ghost,
  .payments-card .btn.btn-outline {
    border-color: rgba(148, 163, 184, 0.2);
    color: #d8e4f5;
    background: rgba(15, 23, 42, 0.55);
  }

  .payments-table-wrap {
    overflow-x: auto;
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.12);
  }

  .payments-card .table {
    margin: 0;
    width: 100%;
    color: #e5eefb;
    background: transparent;
  }

  .payments-card .table th {
    background: rgba(15, 23, 42, 0.96);
    color: #93a8c6;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 11px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  }

  .payments-card .table td,
  .payments-card .table th {
    border-color: rgba(148, 163, 184, 0.1);
    padding: 14px 16px;
    vertical-align: middle;
  }

  .payments-card .table tbody tr:nth-child(odd) {
    background: rgba(15, 23, 42, 0.48);
  }

  .payments-card .table a {
    color: #7dd3fc;
  }

  .payments-card .small {
    color: #8ea3bf;
  }

  @media (max-width: 980px) {
    .payments-filters {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 760px) {
    .payments-card {
      padding: 18px;
      border-radius: 18px;
    }
  }
</style>

<div class="payments-shell">
<div class="payments-card">
  <div class="payments-header">
    <div>
      <span class="payments-kicker">Collections Workspace</span>
      <h2>Payments</h2>
      <p>Review payment records, narrow by loan status or method, and jump into loan or payment details from a darker high-contrast workspace.</p>
    </div>
  </div>
  <form method="get" class="payments-filters">
    <div class="payments-field">
      <label class="label">Search</label>
      <input class="input" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search reference/customer no/name">
    </div>
    <div class="payments-field">
      <label class="label">Loan Status</label>
      <select class="input" name="status">
        <option value="">All</option>
        <option value="ACTIVE" <?= ($status === 'ACTIVE') ? 'selected' : '' ?>>ACTIVE</option>
        <option value="OVERDUE" <?= ($status === 'OVERDUE') ? 'selected' : '' ?>>OVERDUE</option>
      </select>
    </div>
    <div class="payments-field">
      <label class="label">Method</label>
      <select class="input" name="method">
        <option value="">All</option>
        <option value="CASH" <?= ($method === 'CASH') ? 'selected' : '' ?>>Cash</option>
        <option value="GCASH" <?= ($method === 'GCASH') ? 'selected' : '' ?>>GCash</option>
        <option value="BANK" <?= ($method === 'BANK') ? 'selected' : '' ?>>Bank Transfer</option>
        <option value="CHEQUE" <?= ($method === 'CHEQUE') ? 'selected' : '' ?>>Cheque</option>
      </select>
    </div>
    <button class="btn btn-primary">Search</button>
    <a class="btn btn-ghost" href="<?php echo APP_BASE; ?>/staff/payments.php">Reset</a>
  </form>
  <div class="payments-table-wrap">
  <table class="table">
    <thead><tr><th>OR No</th><th>Reference</th><th>Customer</th><th>Payment Date</th><th>Amount</th><th>Method</th><th>Received By</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['or_no']) ?></td>
          <td><a href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($r['loan_id']) ?>"><?= htmlspecialchars($r['reference_no']) ?></a></td>
          <td><?= htmlspecialchars($r['customer_name']) ?> <span class="small">(<?= htmlspecialchars($r['customer_no']) ?>)</span></td>
          <td><?= htmlspecialchars($r['payment_date']) ?></td>
          <td>₱<?= number_format($r['amount'], 2) ?></td>
          <td><?= htmlspecialchars($r['method'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['received_by_name'] ?? '') ?></td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/payment_edit.php?id=<?= intval($r['payment_id']) ?>" style="font-size:12px">Edit</a>
            <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($r['loan_id']) ?>" style="font-size:12px">View Loan</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(empty($rows)): ?><tr><td colspan="8" class="small">No payments found.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
</div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
