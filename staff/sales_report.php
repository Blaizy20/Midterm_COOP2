<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('view_sales');
require_roles(['SUPER_ADMIN', 'ADMIN']);

$title = "Sales Report";
$active = "sales";
$is_system_admin = is_system_admin();
$is_admin_owner = is_admin_owner();

// Get filter parameters
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$tenant_id_filter = intval($_GET['tenant_id'] ?? 0);
$method_filter = strtoupper(trim($_GET['method'] ?? ''));
$transaction_page = max(1, intval($_GET['page'] ?? 1));
$transactions_per_page = 25;
$payment_methods = ['CASH', 'CHEQUE', 'GCASH', 'DIGITAL', 'OTHER', 'BANK'];

if (!in_array($method_filter, $payment_methods, true)) {
  $method_filter = '';
}

$available_tenants = [];
if ($is_system_admin) {
  $available_tenants = fetch_all(q(
    "SELECT tenant_id, COALESCE(display_name, tenant_name) AS tenant_name
     FROM tenants
     WHERE is_active = 1
     ORDER BY COALESCE(display_name, tenant_name)"
  )) ?: [];
} elseif ($is_admin_owner) {
  foreach (user_owned_tenants($_SESSION['user_id'] ?? 0, true) as $tenant) {
    $available_tenants[] = [
      'tenant_id' => intval($tenant['tenant_id']),
      'tenant_name' => $tenant['display_name'] ?: $tenant['tenant_name'],
    ];
  }
}

$available_tenant_ids = array_map(static function ($tenant) {
  return intval($tenant['tenant_id']);
}, $available_tenants);

if ($tenant_id_filter > 0 && !in_array($tenant_id_filter, $available_tenant_ids, true)) {
  $tenant_id_filter = 0;
}

$report_tenant_id = 0;
if ($tenant_id_filter > 0) {
  $report_tenant_id = $tenant_id_filter;
} elseif (!$is_system_admin) {
  $report_tenant_id = intval(current_tenant_id() ?? 0);
  if ($report_tenant_id <= 0 && count($available_tenant_ids) === 1) {
    $report_tenant_id = $available_tenant_ids[0];
  }
}

if (!$is_system_admin && $tenant_id_filter <= 0 && $report_tenant_id > 0) {
  $tenant_id_filter = $report_tenant_id;
}

$show_tenant_filter = $is_system_admin || count($available_tenants) > 1;
$selected_tenant_name = 'All Accessible Tenants';
if ($tenant_id_filter > 0) {
  foreach ($available_tenants as $tenant) {
    if (intval($tenant['tenant_id']) === $tenant_id_filter) {
      $selected_tenant_name = $tenant['tenant_name'];
      break;
    }
  }
} elseif (!$is_system_admin && count($available_tenants) === 1) {
  $selected_tenant_name = $available_tenants[0]['tenant_name'];
}

$build_scope = static function ($tenant_column) use ($is_system_admin, $report_tenant_id) {
  if ($is_system_admin && $report_tenant_id <= 0) {
    return ['1=1', '', []];
  }

  if ($report_tenant_id > 0) {
    return ["{$tenant_column} = ?", 'i', [$report_tenant_id]];
  }

  return ['1=0', '', []];
};

$build_payment_filters = static function ($tenant_column, $date_column, $method_column = null) use ($build_scope, $from, $to, $method_filter) {
  [$scope_sql, $scope_types, $scope_params] = $build_scope($tenant_column);
  $where_conditions = [$scope_sql];
  $types = $scope_types;
  $params = $scope_params;

  if ($from !== '') {
    $where_conditions[] = "{$date_column} >= ?";
    $types .= "s";
    $params[] = $from;
  }
  if ($to !== '') {
    $where_conditions[] = "{$date_column} <= ?";
    $types .= "s";
    $params[] = $to;
  }
  if ($method_column !== null && $method_filter !== '') {
    $where_conditions[] = "{$method_column} = ?";
    $types .= "s";
    $params[] = $method_filter;
  }

  return [
    'sql' => "WHERE " . implode(" AND ", $where_conditions),
    'types' => $types,
    'params' => $params,
  ];
};

$filter_summary = [];
if ($tenant_id_filter > 0 || !$is_system_admin) {
  $filter_summary[] = 'Tenant: ' . $selected_tenant_name;
}
if ($from !== '' || $to !== '') {
  $filter_summary[] = 'Date Range: ' . ($from !== '' ? $from : 'Any') . ' to ' . ($to !== '' ? $to : 'Any');
}
if ($method_filter !== '') {
  $filter_summary[] = 'Method: ' . $method_filter;
}

// ========== SALES OVERVIEW METRICS ==========
$err = '';
$metrics = [
  'total_sales' => 0,
  'total_transactions' => 0,
  'top_tenant' => 'N/A',
  'avg_transaction' => 0
];

try {
  $payment_filters = $build_payment_filters('tenant_id', 'payment_date', 'method');

  // Total Sales / Revenue
  $sql_total = "SELECT IFNULL(SUM(amount), 0) as total FROM payments " . $payment_filters['sql'];
  $result = fetch_one(q($sql_total, $payment_filters['types'], $payment_filters['params']));
  $metrics['total_sales'] = $result['total'] ?? 0;

  // Total Transactions
  $sql_count = "SELECT COUNT(payment_id) as count FROM payments " . $payment_filters['sql'];
  $result = fetch_one(q($sql_count, $payment_filters['types'], $payment_filters['params']));
  $metrics['total_transactions'] = $result['count'] ?? 0;

  // Average Transaction Value
  if ($metrics['total_transactions'] > 0) {
    $metrics['avg_transaction'] = $metrics['total_sales'] / $metrics['total_transactions'];
  }

  // Top Performing Tenant
  $joined_filters = $build_payment_filters('p.tenant_id', 'p.payment_date', 'p.method');
  $sql_top = "SELECT t.tenant_name, SUM(p.amount) as total_revenue 
              FROM payments p 
              JOIN tenants t ON p.tenant_id = t.tenant_id 
              " . $joined_filters['sql'] . "
              GROUP BY p.tenant_id, t.tenant_name 
              ORDER BY total_revenue DESC 
              LIMIT 1";
  $result = fetch_one(q($sql_top, $joined_filters['types'], $joined_filters['params']));
  if ($result && $result['tenant_name']) {
    $metrics['top_tenant'] = htmlspecialchars($result['tenant_name']);
  }
} catch (Exception $e) {
  $err = "Error loading metrics: " . htmlspecialchars($e->getMessage());
}

// ========== SALES PER TENANT ==========
$sales_per_tenant = [];
try {
  $joined_filters = $build_payment_filters('p.tenant_id', 'p.payment_date', 'p.method');
  $sql_per_tenant = "SELECT 
                      t.tenant_id,
                      t.tenant_name,
                      COUNT(p.payment_id) as transaction_count,
                      SUM(p.amount) as total_revenue,
                      AVG(p.amount) as avg_amount
                    FROM payments p 
                    JOIN tenants t ON p.tenant_id = t.tenant_id 
                    " . $joined_filters['sql'] . "
                    GROUP BY p.tenant_id, t.tenant_name 
                    ORDER BY total_revenue DESC";
  $sales_per_tenant = fetch_all(q($sql_per_tenant, $joined_filters['types'], $joined_filters['params']));
} catch (Exception $e) {
  $err = "Error loading sales per tenant: " . htmlspecialchars($e->getMessage());
}

// ========== TIME-BASED SALES ==========
$daily_sales = [];
$weekly_sales = [];
$monthly_sales = [];

try {
  $payment_filters = $build_payment_filters('tenant_id', 'payment_date', 'method');

  // Daily Sales
  $sql_daily = "SELECT 
                  DATE(payment_date) as sales_date,
                  COUNT(payment_id) as count,
                  SUM(amount) as total
                FROM payments 
                " . $payment_filters['sql'] . "
                GROUP BY DATE(payment_date)
                ORDER BY sales_date DESC
                LIMIT 30";
  $daily_sales = fetch_all(q($sql_daily, $payment_filters['types'], $payment_filters['params']));

  // Weekly Sales
  $sql_weekly = "SELECT 
                  WEEK(payment_date) as sales_week,
                  YEAR(payment_date) as sales_year,
                  COUNT(payment_id) as count,
                  SUM(amount) as total
                FROM payments 
                " . $payment_filters['sql'] . "
                GROUP BY YEAR(payment_date), WEEK(payment_date)
                ORDER BY sales_year DESC, sales_week DESC
                LIMIT 12";
  $weekly_sales = fetch_all(q($sql_weekly, $payment_filters['types'], $payment_filters['params']));

  // Monthly Sales
  $sql_monthly = "SELECT 
                   DATE_FORMAT(payment_date, '%Y-%m') as sales_month,
                   COUNT(payment_id) as count,
                   SUM(amount) as total
                 FROM payments 
                 " . $payment_filters['sql'] . "
                 GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                 ORDER BY sales_month DESC
                 LIMIT 12";
  $monthly_sales = fetch_all(q($sql_monthly, $payment_filters['types'], $payment_filters['params']));
} catch (Exception $e) {
  $err = "Error loading time-based sales: " . htmlspecialchars($e->getMessage());
}

// ========== TOP 5 PERFORMING TENANTS ==========
$top_tenants = [];
try {
  $joined_filters = $build_payment_filters('p.tenant_id', 'p.payment_date', 'p.method');
  $sql_top5 = "SELECT 
                t.tenant_id,
                t.tenant_name,
                COUNT(p.payment_id) as transaction_count,
                SUM(p.amount) as total_revenue
              FROM payments p 
              JOIN tenants t ON p.tenant_id = t.tenant_id 
              " . $joined_filters['sql'] . "
              GROUP BY p.tenant_id, t.tenant_name 
              ORDER BY total_revenue DESC 
              LIMIT 5";
  $top_tenants = fetch_all(q($sql_top5, $joined_filters['types'], $joined_filters['params']));
} catch (Exception $e) {
  $err = "Error loading top tenants: " . htmlspecialchars($e->getMessage());
}

// ========== TRANSACTION HISTORY ==========
$transactions = [];
$transaction_total = 0;
$transaction_pages = 1;
try {
  $joined_filters = $build_payment_filters('p.tenant_id', 'p.payment_date', 'p.method');
  $count_row = fetch_one(q(
    "SELECT COUNT(p.payment_id) AS total
     FROM payments p
     JOIN tenants t ON p.tenant_id = t.tenant_id
     " . $joined_filters['sql'],
    $joined_filters['types'],
    $joined_filters['params']
  ));
  $transaction_total = intval($count_row['total'] ?? 0);
  $transaction_pages = max(1, (int)ceil($transaction_total / $transactions_per_page));
  if ($transaction_page > $transaction_pages) {
    $transaction_page = $transaction_pages;
  }
  $transaction_offset = ($transaction_page - 1) * $transactions_per_page;

  $transaction_types = $joined_filters['types'] . 'ii';
  $transaction_params = $joined_filters['params'];
  $transaction_params[] = $transactions_per_page;
  $transaction_params[] = $transaction_offset;

  $sql_transactions = "SELECT 
                        p.payment_id,
                        p.payment_date,
                        p.or_no,
                        t.tenant_name,
                        p.amount,
                        p.method
                      FROM payments p 
                      JOIN tenants t ON p.tenant_id = t.tenant_id 
                      " . $joined_filters['sql'] . "
                      ORDER BY p.payment_date DESC
                      LIMIT ? OFFSET ?";
  $transactions = fetch_all(q($sql_transactions, $transaction_types, $transaction_params));
} catch (Exception $e) {
  $err = "Error loading transactions: " . htmlspecialchars($e->getMessage());
}

$daily_chart_labels = [];
$daily_chart_values = [];
foreach (array_reverse($daily_sales) as $row) {
  $daily_chart_labels[] = $row['sales_date'];
  $daily_chart_values[] = (float)$row['total'];
}

$weekly_chart_labels = [];
$weekly_chart_values = [];
foreach (array_reverse($weekly_sales) as $row) {
  $weekly_chart_labels[] = sprintf('%s-W%02d', $row['sales_year'], $row['sales_week']);
  $weekly_chart_values[] = (float)$row['total'];
}

$monthly_chart_labels = [];
$monthly_chart_values = [];
foreach (array_reverse($monthly_sales) as $row) {
  $monthly_chart_labels[] = $row['sales_month'];
  $monthly_chart_values[] = (float)$row['total'];
}

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

  .sidebar h3 { color: #7f93b0; }
  .sidebar a { color: #d7e3f4; }
  .sidebar a.active,
  .sidebar a:hover {
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(59, 130, 246, 0.2));
    color: #f8fbff;
  }

  .sales-card {
    border-radius: 26px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    background:
      radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%),
      linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97));
    box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34);
    padding: 24px;
  }

  .sales-section-title {
    text-align: left;
    width: 100%;
    font-weight: bold;
    font-size: 1.25rem;
    margin: 24px 0 15px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
    color: #f8fbff;
  }

  .metric-card {
    background: linear-gradient(160deg, rgba(15, 23, 42, 0.98), rgba(19, 34, 61, 0.94));
    border: 1px solid rgba(148, 163, 184, 0.14);
    padding: 15px;
    border-radius: 18px;
    text-align: center;
  }

  .metric-label {
    font-size: 12px;
    color: #8ea3bf;
    margin-bottom: 8px;
  }

  .metric-value {
    font-size: 24px;
    font-weight: bold;
    color: #f8fbff;
  }

  .metric-value.large {
    font-size: 24px;
  }

  .metric-value.medium {
    font-size: 18px;
  }

  .sales-table-wrapper {
    margin-top: 20px;
    margin-bottom: 30px;
    width: 100%;
  }

  .sales-table-wrapper table {
    width: 100%;
    margin-top: 0;
    color: #e5eefb;
  }

  .sales-table-wrapper th,
  .sales-table-wrapper td {
    padding: 12px;
    text-align: left;
    border-color: rgba(148, 163, 184, 0.1);
  }

  .sales-table-wrapper th {
    font-weight: bold;
    background-color: rgba(15, 23, 42, 0.96);
    color: #93a8c6;
  }

  .sales-table-wrapper {
    overflow: auto;
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.12);
  }

  .sales-table-wrapper tbody tr:nth-child(odd) {
    background: rgba(15, 23, 42, 0.48);
  }

  .sales-card .input {
    background: rgba(15, 23, 42, 0.86);
    color: #f8fbff;
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  .sales-card .input::placeholder {
    color: #6f86a6;
  }

  .sales-card .small {
    color: #8ea3bf;
  }

  .sales-card .btn.btn-ghost,
  .sales-card .btn.btn-outline {
    border-color: rgba(148, 163, 184, 0.2);
    color: #d8e4f5;
    background: rgba(15, 23, 42, 0.55);
  }

  .sales-filter-form {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    align-items: end;
  }

  .sales-filter-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }

  .sales-filter-summary {
    margin-top: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .sales-filter-pill {
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(14, 165, 233, 0.12);
    border: 1px solid rgba(125, 211, 252, 0.16);
    color: #d9efff;
    font-size: 12px;
  }

  .sales-chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 16px;
    margin-top: 15px;
  }

  .sales-chart-panel {
    border: 1px solid rgba(148, 163, 184, 0.12);
    border-radius: 20px;
    padding: 16px;
    background: rgba(15, 23, 42, 0.58);
  }

  .sales-chart-panel h4 {
    margin: 0 0 14px 0;
    color: #f8fbff;
  }

  .sales-chart-panel canvas {
    width: 100%;
    max-height: 260px;
  }

  .sales-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 14px;
    flex-wrap: wrap;
  }

  @media (max-width: 760px) {
    .sales-card {
      padding: 18px;
      border-radius: 18px;
    }

    .sales-filter-form {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="sales-card">
  <h2 style="margin:0 0 10px 0">Sales Report</h2>
  <div class="small">Comprehensive sales analytics and payment tracking.</div>

  <?php if ($err): ?><div class="alert red" style="margin-top:12px"><?= $err ?></div><?php endif; ?>

  <!-- Filters -->
  <h3 class="sales-section-title">Filters</h3>

  <form method="get" class="sales-filter-form">
    <?php if ($show_tenant_filter): ?>
      <div>
        <label class="label">Tenant</label>
        <select class="input" name="tenant_id">
          <option value="">All Accessible Tenants</option>
          <?php foreach ($available_tenants as $tenant): ?>
            <option value="<?= intval($tenant['tenant_id']) ?>" <?= ($tenant_id_filter === intval($tenant['tenant_id'])) ? 'selected' : '' ?>>
              <?= htmlspecialchars($tenant['tenant_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div>
      <label class="label">From Date</label>
      <input class="input" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div>
      <label class="label">To Date</label>
      <input class="input" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <div>
      <label class="label">Payment Method</label>
      <select class="input" name="method">
        <option value="">All Methods</option>
        <?php foreach ($payment_methods as $payment_method): ?>
          <option value="<?= htmlspecialchars($payment_method) ?>" <?= ($method_filter === $payment_method) ? 'selected' : '' ?>>
            <?= htmlspecialchars($payment_method) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sales-filter-actions">
      <button class="btn btn-primary" type="submit">Apply Filters</button>
      <a class="btn btn-ghost" href="<?php echo APP_BASE; ?>/staff/sales_report.php">Reset</a>
    </div>
  </form>

  <?php if (!empty($filter_summary)): ?>
    <div class="sales-filter-summary">
      <?php foreach ($filter_summary as $item): ?>
        <span class="sales-filter-pill"><?= htmlspecialchars($item) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Sales Overview Metrics -->
  <h3 class="sales-section-title">Sales Overview</h3>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;margin-top:15px">
    <div class="metric-card">
      <div class="metric-label">Total Sales / Revenue</div>
      <div class="metric-value">₱<?= number_format($metrics['total_sales'], 2) ?></div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Total Transactions</div>
      <div class="metric-value"><?= intval($metrics['total_transactions']) ?></div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Top Performing Tenant</div>
      <div class="metric-value medium"><?= $metrics['top_tenant'] ?></div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Avg Transaction Value</div>
      <div class="metric-value">₱<?= number_format($metrics['avg_transaction'], 2) ?></div>
    </div>
  </div>

  <!-- Sales Trend Charts -->
  <h3 class="sales-section-title">Sales Trends</h3>

  <div class="sales-chart-grid">
    <div class="sales-chart-panel">
      <h4>Daily Revenue</h4>
      <canvas id="sales-daily-chart" height="220"></canvas>
    </div>
    <div class="sales-chart-panel">
      <h4>Weekly Revenue</h4>
      <canvas id="sales-weekly-chart" height="220"></canvas>
    </div>
    <div class="sales-chart-panel">
      <h4>Monthly Revenue</h4>
      <canvas id="sales-monthly-chart" height="220"></canvas>
    </div>
  </div>

  <!-- Sales Per Tenant -->
  <h3 class="sales-section-title">Sales Per Tenant</h3>

  <div class="sales-table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Tenant Name</th>
          <th>Transaction Count</th>
          <th>Total Revenue</th>
          <th>Average Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sales_per_tenant as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['tenant_name']) ?></td>
            <td><?= intval($row['transaction_count']) ?></td>
            <td>₱<?= number_format((float)$row['total_revenue'], 2) ?></td>
            <td>₱<?= number_format((float)$row['avg_amount'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($sales_per_tenant)): ?>
          <tr><td colspan="4" class="small" style="text-align:center;padding:20px">No sales data found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Daily Sales -->
  <h3 class="sales-section-title">Daily Sales (Last 30 Days)</h3>

  <div class="sales-table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Transaction Count</th>
          <th>Daily Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($daily_sales as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['sales_date']) ?></td>
            <td><?= intval($row['count']) ?></td>
            <td>₱<?= number_format((float)$row['total'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($daily_sales)): ?>
          <tr><td colspan="3" class="small" style="text-align:center;padding:20px">No daily sales data found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Weekly Sales -->
  <h3 class="sales-section-title">Weekly Sales (Last 12 Weeks)</h3>

  <div class="sales-table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Week</th>
          <th>Transaction Count</th>
          <th>Weekly Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($weekly_sales as $row): ?>
          <tr>
            <td><?= htmlspecialchars(sprintf('%s-W%02d', $row['sales_year'], $row['sales_week'])) ?></td>
            <td><?= intval($row['count']) ?></td>
            <td>â‚±<?= number_format((float)$row['total'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($weekly_sales)): ?>
          <tr><td colspan="3" class="small" style="text-align:center;padding:20px">No weekly sales data found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Monthly Sales -->
  <h3 class="sales-section-title">Monthly Sales (Last 12 Months)</h3>

  <div class="sales-table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Month</th>
          <th>Transaction Count</th>
          <th>Monthly Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($monthly_sales as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['sales_month']) ?></td>
            <td><?= intval($row['count']) ?></td>
            <td>₱<?= number_format((float)$row['total'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($monthly_sales)): ?>
          <tr><td colspan="3" class="small" style="text-align:center;padding:20px">No monthly sales data found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Top 5 Tenants -->
  <h3 class="sales-section-title">Top 5 Performing Tenants</h3>

  <div class="sales-table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Rank</th>
          <th>Tenant Name</th>
          <th>Transaction Count</th>
          <th>Total Revenue</th>
        </tr>
      </thead>
      <tbody>
        <?php $rank = 1; foreach ($top_tenants as $row): ?>
          <tr>
            <td><?= intval($rank) ?></td>
            <td><?= htmlspecialchars($row['tenant_name']) ?></td>
            <td><?= intval($row['transaction_count']) ?></td>
            <td>₱<?= number_format((float)$row['total_revenue'], 2) ?></td>
          </tr>
          <?php $rank++; endforeach; ?>
        <?php if (empty($top_tenants)): ?>
          <tr><td colspan="4" class="small" style="text-align:center;padding:20px">No tenant data found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Transaction History -->
  <h3 class="sales-section-title">Transaction History</h3>

  <div class="sales-table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>OR No</th>
          <th>Tenant</th>
          <th>Amount</th>
          <th>Method</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['payment_date']) ?></td>
            <td><?= htmlspecialchars($row['or_no']) ?></td>
            <td><?= htmlspecialchars($row['tenant_name']) ?></td>
            <td>₱<?= number_format((float)$row['amount'], 2) ?></td>
            <td><?= htmlspecialchars($row['method'] ?? 'N/A') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="5" class="small" style="text-align:center;padding:20px">No transactions found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="sales-pagination">
    <div class="small">
      Showing <?= count($transactions) ?> of <?= intval($transaction_total) ?> transaction<?= ($transaction_total === 1) ? '' : 's' ?>.
    </div>
    <?php if ($transaction_pages > 1): ?>
      <div style="display:flex;gap:10px">
        <?php
          $query = $_GET;
          if ($transaction_page > 1) {
            $query['page'] = $transaction_page - 1;
            $prev_link = APP_BASE . '/staff/sales_report.php?' . http_build_query($query);
            echo '<a class="btn btn-outline" href="' . htmlspecialchars($prev_link) . '">Previous</a>';
          }
          if ($transaction_page < $transaction_pages) {
            $query['page'] = $transaction_page + 1;
            $next_link = APP_BASE . '/staff/sales_report.php?' . http_build_query($query);
            echo '<a class="btn btn-outline" href="' . htmlspecialchars($next_link) . '">Next</a>';
          }
        ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
  const salesCharts = [
    {
      id: 'sales-daily-chart',
      type: 'line',
      label: 'Daily Revenue',
      labels: <?= json_encode($daily_chart_labels) ?>,
      data: <?= json_encode($daily_chart_values) ?>,
      borderColor: '#38bdf8',
      backgroundColor: 'rgba(56, 189, 248, 0.16)',
      fill: true
    },
    {
      id: 'sales-weekly-chart',
      type: 'bar',
      label: 'Weekly Revenue',
      labels: <?= json_encode($weekly_chart_labels) ?>,
      data: <?= json_encode($weekly_chart_values) ?>,
      borderColor: '#0f766e',
      backgroundColor: 'rgba(45, 212, 191, 0.55)',
      fill: false
    },
    {
      id: 'sales-monthly-chart',
      type: 'line',
      label: 'Monthly Revenue',
      labels: <?= json_encode($monthly_chart_labels) ?>,
      data: <?= json_encode($monthly_chart_values) ?>,
      borderColor: '#f97316',
      backgroundColor: 'rgba(249, 115, 22, 0.16)',
      fill: true
    }
  ];

  if (window.Chart) {
    const currencyTick = (value) => 'PHP ' + Number(value).toLocaleString();
    salesCharts.forEach((config) => {
      const canvas = document.getElementById(config.id);
      if (!canvas) {
        return;
      }

      new Chart(canvas.getContext('2d'), {
        type: config.type,
        data: {
          labels: config.labels,
          datasets: [{
            label: config.label,
            data: config.data,
            borderColor: config.borderColor,
            backgroundColor: config.backgroundColor,
            borderWidth: 2,
            fill: config.fill,
            tension: 0.28
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback: currencyTick
              }
            }
          }
        }
      });
    });
  }
</script>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
