<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_permission('view_dashboard');

$role = $_SESSION['role'] ?? '';

$counts_sql = "SELECT
  SUM(status='PENDING') AS pending,
  SUM(status='DENIED') AS denied,
  SUM(status='PENDING') AS ci_queue,
  SUM(status='CI_REVIEWED') AS manager_queue,
  SUM(status='ACTIVE') AS approved,
  SUM(status='ACTIVE') AS active,
  SUM(status='OVERDUE') AS overdue,
  SUM(status='CLOSED') AS closed
FROM loans";
if (!is_system_admin()) {
  $counts_sql .= " WHERE tenant_id=?";
}
$counts = fetch_one(q($counts_sql, is_system_admin() ? "" : "i", is_system_admin() ? [] : [require_current_tenant_id()]));

$total_tx = fetch_one(q(
  "SELECT IFNULL(SUM(amount),0) AS total FROM payments" . (is_system_admin() ? "" : " WHERE tenant_id=?"),
  is_system_admin() ? "" : "i",
  is_system_admin() ? [] : [require_current_tenant_id()]
));

$total_customers = fetch_one(q(
  "SELECT COUNT(*) AS count FROM customers WHERE user_id IS NOT NULL" . (is_system_admin() ? "" : " AND tenant_id=?"),
  is_system_admin() ? "" : "i",
  is_system_admin() ? [] : [require_current_tenant_id()]
));

$total_staff = fetch_one(q(
  "SELECT COUNT(*) AS count FROM users WHERE role <> 'CUSTOMER'" . (is_system_admin() ? "" : " AND tenant_id=?"),
  is_system_admin() ? "" : "i",
  is_system_admin() ? [] : [require_current_tenant_id()]
));

$applicants = fetch_all(q(
  "SELECT l.reference_no, l.submitted_at, l.status, c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name
   FROM loans l
   JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
   WHERE " . tenant_condition('l.tenant_id') . "
   ORDER BY l.submitted_at DESC
   LIMIT 10",
  tenant_types(),
  tenant_params()
));

$staff = fetch_all(q(
  "SELECT full_name, role, created_at
   FROM users
   WHERE " . (is_system_admin() ? "role <> 'CUSTOMER'" : "role IN ('TENANT','MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER') AND tenant_id=?") . "
   ORDER BY
     CASE role
       WHEN 'SUPER_ADMIN' THEN 0
       WHEN 'ADMIN' THEN 1
       WHEN 'TENANT' THEN 1
       WHEN 'MANAGER' THEN 2
       WHEN 'CREDIT_INVESTIGATOR' THEN 3
       WHEN 'LOAN_OFFICER' THEN 4
       WHEN 'CASHIER' THEN 5
       ELSE 99
     END,
     created_at DESC",
  is_system_admin() ? "" : "i",
  is_system_admin() ? [] : [current_tenant_id()]
));

$title = "Dashboard";
$active = "dash";
include __DIR__ . '/_layout_top.php';
$display_name = $_SESSION['full_name'] ?? 'User';
$role_label = str_replace('_', ' ', $_SESSION['role'] ?? '');
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

  .layout {
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

  .main {
    background: transparent;
  }

  .dashboard-shell { position: relative; display: grid; gap: 24px; color: #e5eefb; }
  .dashboard-shell::before, .dashboard-shell::after { content: ""; position: fixed; width: 420px; height: 420px; border-radius: 999px; filter: blur(90px); opacity: 0.18; pointer-events: none; z-index: 0; }
  .dashboard-shell::before { top: 92px; right: 4%; background: rgba(59, 130, 246, 0.55); }
  .dashboard-shell::after { bottom: 24px; left: 10%; background: rgba(14, 165, 233, 0.34); }
  .dashboard-shell > * { position: relative; z-index: 1; }
  .dashboard-hero { display: grid; gap: 18px; grid-template-columns: minmax(0, 1.8fr) minmax(280px, 1fr); padding: 30px; border: 1px solid rgba(148, 163, 184, 0.18); border-radius: 28px; background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.18), transparent 34%), radial-gradient(circle at bottom right, rgba(59, 130, 246, 0.16), transparent 32%), linear-gradient(145deg, rgba(8, 15, 33, 0.96), rgba(15, 23, 42, 0.92)); box-shadow: 0 24px 60px rgba(2, 6, 23, 0.42); overflow: hidden; }
  .dashboard-hero h1 { margin: 0; font-size: clamp(32px, 4vw, 52px); line-height: 1; letter-spacing: -0.04em; color: #f8fbff; }
  .dashboard-hero p { margin: 0; max-width: 760px; color: #9fb0c9; font-size: 15px; line-height: 1.7; }
  .dashboard-kicker, .dashboard-pill { display: inline-flex; align-items: center; gap: 8px; width: fit-content; padding: 8px 12px; border-radius: 999px; border: 1px solid rgba(125, 211, 252, 0.24); background: rgba(14, 165, 233, 0.12); color: #d8f4ff; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; }
  .dashboard-hero-meta { display: flex; flex-wrap: wrap; gap: 10px; }
  .dashboard-pill { padding: 10px 14px; font-size: 12px; letter-spacing: 0.04em; }
  .dashboard-highlight { display: grid; gap: 16px; align-content: space-between; padding: 22px; border-radius: 24px; border: 1px solid rgba(148, 163, 184, 0.14); background: rgba(15, 23, 42, 0.72); backdrop-filter: blur(12px); }
  .dashboard-highlight-label { color: #8ea3bf; font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase; }
  .dashboard-highlight-value { font-size: clamp(28px, 4vw, 44px); line-height: 1; font-weight: 800; color: #ffffff; }
  .dashboard-highlight-note { color: #9fb0c9; font-size: 13px; line-height: 1.6; }
  .dashboard-grid { display: grid; gap: 16px; grid-template-columns: repeat(12, minmax(0, 1fr)); }
  .dashboard-grid > * { grid-column: span 4; }
  .dashboard-grid.dashboard-grid-wide > * { grid-column: span 3; }
  .dashboard-grid.dashboard-grid-wide > *:nth-child(-n+2) { grid-column: span 6; }
  .dashboard-card, .dashboard-panel, .dashboard-chart, .dashboard-table-card { border-radius: 22px; border: 1px solid rgba(148, 163, 184, 0.16); background: linear-gradient(180deg, rgba(15, 23, 42, 0.94), rgba(8, 15, 30, 0.95)); box-shadow: 0 18px 44px rgba(2, 6, 23, 0.3); }
  .dashboard-card { padding: 20px; }
  .dashboard-card-label, .dashboard-section-label { margin: 0 0 8px; color: #93a8c6; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; }
  .dashboard-card-value { margin: 0; color: #f8fbff; font-size: clamp(28px, 3vw, 36px); line-height: 1; font-weight: 800; letter-spacing: -0.04em; }
  .dashboard-card-note { display: none; }
  .dashboard-section { display: grid; gap: 16px; }
  .dashboard-section-header { display: flex; justify-content: space-between; align-items: end; gap: 12px; }
  .dashboard-section-header h2, .dashboard-panel h3, .dashboard-chart h3, .dashboard-table-card h3 { margin: 0; color: #f8fbff; letter-spacing: -0.03em; }
  .dashboard-section-header p, .dashboard-panel-subtitle, .dashboard-table-card p { display: none; }
  .dashboard-split, .dashboard-table-grid { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .dashboard-panel { padding: 22px; }
  .dashboard-panel-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-top: 16px; }
  .dashboard-panel-stat { padding: 14px 16px; border-radius: 18px; border: 1px solid rgba(148, 163, 184, 0.12); background: rgba(15, 23, 42, 0.72); }
  .dashboard-panel-stat span { display: block; color: #8ea3bf; font-size: 12px; margin-bottom: 8px; }
  .dashboard-panel-stat strong { display: block; color: #ffffff; font-size: 24px; line-height: 1.1; }
  .dashboard-chart-grid { display: grid; gap: 16px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .dashboard-chart-grid.dashboard-chart-grid-two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .dashboard-chart { padding: 22px; }
  .dashboard-chart.tall { min-height: 366px; }
  .dashboard-chart-canvas { position: relative; width: 100%; height: 300px; margin-top: 16px; }
  .dashboard-chart-canvas.short { height: 260px; }
  .dashboard-table-card { padding: 22px; overflow: hidden; }
  .dashboard-table-wrap { overflow-x: auto; margin-top: 16px; border-radius: 18px; border: 1px solid rgba(148, 163, 184, 0.12); }
  .dashboard-table-card .table { margin: 0; width: 100%; color: #e5eefb; background: transparent; }
  .dashboard-table-card .table thead th { background: rgba(15, 23, 42, 0.96); color: #93a8c6; text-transform: uppercase; letter-spacing: 0.08em; font-size: 11px; border-bottom: 1px solid rgba(148, 163, 184, 0.16); }
  .dashboard-table-card .table td, .dashboard-table-card .table th { border-color: rgba(148, 163, 184, 0.1); padding: 14px 16px; }
  .dashboard-table-card .table tbody tr:nth-child(odd) { background: rgba(15, 23, 42, 0.48); }
  .dashboard-table-card .small { color: #8ea3bf; }
  @media (max-width: 1180px) {
    .dashboard-hero, .dashboard-split, .dashboard-table-grid, .dashboard-chart-grid, .dashboard-chart-grid.dashboard-chart-grid-two { grid-template-columns: 1fr; }
    .dashboard-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    .dashboard-grid > *, .dashboard-grid.dashboard-grid-wide > *, .dashboard-grid.dashboard-grid-wide > *:nth-child(-n+2) { grid-column: span 3; }
  }
  @media (max-width: 760px) {
    .dashboard-shell { gap: 18px; }
    .dashboard-hero, .dashboard-card, .dashboard-panel, .dashboard-chart, .dashboard-table-card { padding: 18px; border-radius: 18px; }
    .dashboard-grid, .dashboard-panel-grid { grid-template-columns: 1fr; }
    .dashboard-grid > *, .dashboard-grid.dashboard-grid-wide > *, .dashboard-grid.dashboard-grid-wide > *:nth-child(-n+2) { grid-column: auto; }
    .dashboard-chart-canvas, .dashboard-chart-canvas.short { height: 240px; }
  }
</style>

<div class="dashboard-shell">
  <section class="dashboard-hero">
    <div>
      <h1>Dashboard</h1>
      <div class="dashboard-hero-meta">
        <span class="dashboard-pill"><?= htmlspecialchars($display_name) ?></span>
        <span class="dashboard-pill"><?= htmlspecialchars($role_label) ?></span>
        <?php if (!is_system_admin() && current_tenant_id()): ?>
          <span class="dashboard-pill">Tenant <?= intval(current_tenant_id()) ?></span>
        <?php else: ?>
          <span class="dashboard-pill">All Tenants</span>
        <?php endif; ?>
      </div>
    </div>
  </section>

<?php if (is_system_admin()): ?>
<?php
$admin_metrics = fetch_one(q("SELECT
  (SELECT COUNT(*) FROM tenants) AS total_tenants,
  (SELECT COUNT(*) FROM users) AS total_users,
  (SELECT COUNT(*) FROM users WHERE is_active=1) AS active_users,
  (SELECT COUNT(*) FROM users WHERE is_active=0) AS inactive_users,
  (SELECT COUNT(*) FROM customers WHERE is_active=1) AS total_customers,
  (SELECT COUNT(*) FROM loans) AS total_loans,
  (SELECT COUNT(*) FROM loans WHERE status='ACTIVE') AS active_loans,
  (SELECT COUNT(*) FROM loans WHERE status='OVERDUE') AS overdue_loans,
  (SELECT IFNULL(SUM(principal_amount), 0) FROM loans WHERE status IN ('ACTIVE','OVERDUE')) AS portfolio_value,
  (SELECT COUNT(*) FROM loans WHERE status IN ('PENDING','CI_REVIEWED')) AS pending_approvals
"));

$activity_snapshot = fetch_one(q("SELECT
  (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) AS users_today,
  (SELECT COUNT(*) FROM loans WHERE DATE(submitted_at) = CURDATE()) AS loans_today,
  (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE payment_date = CURDATE()) AS revenue_today,
  (SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()) AS events_today,
  (SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) AS users_month,
  (SELECT COUNT(*) FROM loans WHERE DATE_FORMAT(submitted_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) AS loans_month,
  (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) AS revenue_month,
  (SELECT COUNT(*) FROM activity_logs WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) AS events_month
"));
$admin_metric_cards = [
  ['label' => 'Total Tenants', 'value' => intval($admin_metrics['total_tenants'] ?? 0), 'note' => 'Organizations on the platform'],
  ['label' => 'Total Users', 'value' => intval($admin_metrics['total_users'] ?? 0), 'note' => 'All staff and customer accounts'],
  ['label' => 'Active Users', 'value' => intval($admin_metrics['active_users'] ?? 0), 'note' => 'Currently enabled accounts'],
  ['label' => 'Inactive Users', 'value' => intval($admin_metrics['inactive_users'] ?? 0), 'note' => 'Accounts that need attention'],
  ['label' => 'Total Customers', 'value' => intval($admin_metrics['total_customers'] ?? 0), 'note' => 'Borrowers with active records'],
  ['label' => 'Total Loans', 'value' => intval($admin_metrics['total_loans'] ?? 0), 'note' => 'Applications and issued loans'],
  ['label' => 'Active Loans', 'value' => intval($admin_metrics['active_loans'] ?? 0), 'note' => 'Running loan accounts'],
  ['label' => 'Overdue Loans', 'value' => intval($admin_metrics['overdue_loans'] ?? 0), 'note' => 'Require collection follow-up'],
  ['label' => 'Portfolio Value', 'value' => 'PHP ' . number_format((float)($admin_metrics['portfolio_value'] ?? 0), 2), 'note' => 'Principal across active exposure'],
  ['label' => 'Pending Approvals', 'value' => intval($admin_metrics['pending_approvals'] ?? 0), 'note' => 'Awaiting CI or manager review'],
];
?>
  <section class="dashboard-section">
    <div class="dashboard-grid dashboard-grid-wide">
      <?php foreach ($admin_metric_cards as $metric): ?>
        <article class="dashboard-card">
          <div class="dashboard-card-label"><?= htmlspecialchars($metric['label']) ?></div>
          <p class="dashboard-card-value"><?= htmlspecialchars((string)$metric['value']) ?></p>
          <div class="dashboard-card-note"><?= htmlspecialchars($metric['note']) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="dashboard-split">
    <article class="dashboard-panel">
      <h3>Today</h3>
      <div class="dashboard-panel-grid">
        <div class="dashboard-panel-stat"><span>New Users</span><strong><?= intval($activity_snapshot['users_today'] ?? 0) ?></strong></div>
        <div class="dashboard-panel-stat"><span>Loan Applications</span><strong><?= intval($activity_snapshot['loans_today'] ?? 0) ?></strong></div>
        <div class="dashboard-panel-stat"><span>Revenue Today</span><strong>PHP <?= number_format((float)($activity_snapshot['revenue_today'] ?? 0), 2) ?></strong></div>
        <div class="dashboard-panel-stat"><span>Logged Events</span><strong><?= intval($activity_snapshot['events_today'] ?? 0) ?></strong></div>
      </div>
    </article>
    <article class="dashboard-panel">
      <h3>This Month</h3>
      <div class="dashboard-panel-grid">
        <div class="dashboard-panel-stat"><span>New Users</span><strong><?= intval($activity_snapshot['users_month'] ?? 0) ?></strong></div>
        <div class="dashboard-panel-stat"><span>Loan Applications</span><strong><?= intval($activity_snapshot['loans_month'] ?? 0) ?></strong></div>
        <div class="dashboard-panel-stat"><span>Revenue This Month</span><strong>PHP <?= number_format((float)($activity_snapshot['revenue_month'] ?? 0), 2) ?></strong></div>
        <div class="dashboard-panel-stat"><span>Logged Events</span><strong><?= intval($activity_snapshot['events_month'] ?? 0) ?></strong></div>
      </div>
    </article>
  </section>

  <section class="dashboard-section">
    <div class="dashboard-chart-grid">
      <article class="dashboard-chart">
        <h3>Daily Revenue</h3>
        <div class="dashboard-chart-canvas short"><canvas id="chart-sales-daily" width="400" height="260"></canvas></div>
      </article>
      <article class="dashboard-chart">
        <h3>Weekly Revenue</h3>
        <div class="dashboard-chart-canvas short"><canvas id="chart-sales-weekly" width="400" height="260"></canvas></div>
      </article>
      <article class="dashboard-chart">
        <h3>Monthly Revenue</h3>
        <div class="dashboard-chart-canvas short"><canvas id="chart-sales-monthly" width="400" height="260"></canvas></div>
      </article>
    </div>
  </section>

  <section class="dashboard-chart-grid dashboard-chart-grid-two">
    <article class="dashboard-chart">
      <h3>User Growth</h3>
      <div class="dashboard-chart-canvas"><canvas id="chart-user-growth" width="400" height="300"></canvas></div>
    </article>
    <article class="dashboard-chart">
      <h3>Loan Status Distribution</h3>
      <div class="dashboard-chart-canvas"><canvas id="chart-loan-status" width="400" height="300"></canvas></div>
    </article>
  </section>

  <section class="dashboard-section">
    <article class="dashboard-chart tall">
      <h3>Tenant Activity</h3>
      <div class="dashboard-chart-canvas"><canvas id="chart-tenant-activity" width="400" height="320"></canvas></div>
    </article>
  </section>

  <section class="dashboard-chart-grid">
    <article class="dashboard-chart">
      <h3>Staff by Role</h3>
      <div class="dashboard-chart-canvas"><canvas id="chart-staff-role" width="400" height="300"></canvas></div>
    </article>
    <article class="dashboard-chart">
      <h3>Daily Activity</h3>
      <div class="dashboard-chart-canvas"><canvas id="chart-daily-activity" width="400" height="300"></canvas></div>
    </article>
    <article class="dashboard-chart">
      <h3>Loan Applications</h3>
      <div class="dashboard-chart-canvas"><canvas id="chart-applications" width="400" height="300"></canvas></div>
    </article>
  </section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
const analyticsUrl = <?= json_encode(url_for('/api/v1/analytics.php')) ?>;
const chartInstances = {};
const palette = ['#1d4ed8', '#0f766e', '#b45309', '#7c3aed', '#dc2626', '#0891b2', '#4f46e5'];

const currencyTick = (value) => 'PHP ' + Number(value).toLocaleString();

const chartConfigs = [
  {
    endpoint: 'sales_trends_daily',
    chartId: 'chart-sales-daily',
    type: 'line',
    label: 'Daily Revenue',
    borderColor: palette[0],
    backgroundColor: 'rgba(29, 78, 216, 0.12)',
    fill: true,
    tension: 0.24,
    options: {
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: currencyTick
          }
        }
      }
    }
  },
  {
    endpoint: 'sales_trends_weekly',
    chartId: 'chart-sales-weekly',
    type: 'bar',
    label: 'Weekly Revenue',
    backgroundColor: 'rgba(15, 118, 110, 0.7)',
    borderColor: palette[1],
    borderWidth: 1,
    options: {
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: currencyTick
          }
        }
      }
    }
  },
  {
    endpoint: 'sales_trends_monthly',
    chartId: 'chart-sales-monthly',
    type: 'line',
    label: 'Monthly Revenue',
    borderColor: palette[4],
    backgroundColor: 'rgba(220, 38, 38, 0.12)',
    fill: true,
    tension: 0.28,
    options: {
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: currencyTick
          }
        }
      }
    }
  },
  {
    endpoint: 'user_growth',
    chartId: 'chart-user-growth',
    type: 'line',
    label: 'New Users',
    borderColor: palette[0],
    backgroundColor: 'rgba(29, 78, 216, 0.16)',
    fill: true,
    tension: 0.32
  },
  {
    endpoint: 'loan_status_distribution',
    chartId: 'chart-loan-status',
    type: 'doughnut'
  },
  {
    endpoint: 'staff_by_role',
    chartId: 'chart-staff-role',
    type: 'bar',
    label: 'Staff Members',
    backgroundColor: 'rgba(124, 58, 237, 0.72)',
    borderColor: palette[3],
    borderWidth: 1,
    options: {
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        }
      }
    }
  },
  {
    endpoint: 'tenant_activity',
    chartId: 'chart-tenant-activity',
    type: 'bar',
    useApiDatasets: true,
    options: {
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        }
      }
    }
  },
  {
    endpoint: 'daily_activity',
    chartId: 'chart-daily-activity',
    type: 'bar',
    label: 'Logged Activities',
    backgroundColor: 'rgba(180, 83, 9, 0.72)',
    borderColor: palette[2],
    borderWidth: 1,
    options: {
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        }
      }
    }
  },
  {
    endpoint: 'loan_applications_monthly',
    chartId: 'chart-applications',
    type: 'line',
    label: 'Applications',
    borderColor: palette[5],
    backgroundColor: 'rgba(8, 145, 178, 0.16)',
    fill: true,
    tension: 0.28
  }
];

function buildDatasets(config, payload) {
  if (config.useApiDatasets && Array.isArray(payload.datasets) && payload.datasets.length > 0) {
    return payload.datasets;
  }

  if (config.type === 'doughnut') {
    return [{
      data: Array.isArray(payload.data) ? payload.data : [],
      backgroundColor: palette,
      borderWidth: 1
    }];
  }

  return [{
    label: config.label,
    data: Array.isArray(payload.data) ? payload.data : [],
    borderColor: config.borderColor || palette[0],
    backgroundColor: config.backgroundColor || 'rgba(29, 78, 216, 0.2)',
    borderWidth: config.borderWidth || 2,
    fill: !!config.fill,
    tension: config.tension ?? 0.25
  }];
}

function buildOptions(config) {
  const options = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
      mode: 'index',
      intersect: false
    },
    plugins: {
      legend: {
        display: true
      }
    }
  };

  if (config.type !== 'doughnut') {
    options.scales = {
      y: {
        beginAtZero: true
      }
    };
  }

  if (!config.options) {
    return options;
  }

  return {
    ...options,
    ...config.options,
    plugins: {
      ...options.plugins,
      ...(config.options.plugins || {})
    },
    scales: {
      ...(options.scales || {}),
      ...(config.options.scales || {})
    }
  };
}

async function loadChart(config) {
  const canvas = document.getElementById(config.chartId);
  if (!canvas) {
    return;
  }

  try {
    const response = await fetch(`${analyticsUrl}?endpoint=${encodeURIComponent(config.endpoint)}`, {
      credentials: 'same-origin'
    });
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.error || 'Request failed');
    }

    if (chartInstances[config.chartId]) {
      chartInstances[config.chartId].destroy();
    }

    chartInstances[config.chartId] = new Chart(canvas.getContext('2d'), {
      type: config.type,
      data: {
        labels: Array.isArray(payload.labels) ? payload.labels : [],
        datasets: buildDatasets(config, payload)
      },
      options: buildOptions(config)
    });
  } catch (error) {
    console.error(`Failed to load ${config.endpoint}`, error);
  }
}

chartConfigs.forEach(loadChart);
</script>

<?php else: ?>
<?php
$role_metric_cards = [];
if (in_array($role, ['CASHIER', 'CREDIT_INVESTIGATOR', 'MANAGER', 'TENANT'], true)) {
  $role_metric_cards[] = [
    'label' => 'Total Transactions',
    'value' => 'PHP ' . number_format((float)($total_tx['total'] ?? 0), 2),
    'note' => 'Collections processed in your scope',
  ];
}
if (in_array($role, ['MANAGER', 'LOAN_OFFICER', 'CASHIER', 'CREDIT_INVESTIGATOR', 'TENANT'], true)) {
  $role_metric_cards[] = [
    'label' => 'Total Customers',
    'value' => intval($total_customers['count'] ?? 0),
    'note' => 'Customers linked to active accounts',
  ];
}
$role_metric_cards[] = [
  'label' => 'Total Staff',
  'value' => intval($total_staff['count'] ?? 0),
  'note' => 'Non-customer users in this tenant scope',
];

$pipeline_cards = [
  ['label' => 'Pending', 'value' => intval($counts['pending'] ?? 0), 'note' => 'Applications awaiting review'],
  ['label' => 'Denied', 'value' => intval($counts['denied'] ?? 0), 'note' => 'Applications not approved'],
  ['label' => 'CI Review Queue', 'value' => intval($counts['ci_queue'] ?? 0), 'note' => 'Queued for investigation'],
  ['label' => 'Manager Approval', 'value' => intval($counts['manager_queue'] ?? 0), 'note' => 'Waiting for final approval'],
  ['label' => 'Approved', 'value' => intval($counts['approved'] ?? 0), 'note' => 'Approved and active loans'],
  ['label' => 'Overdue', 'value' => intval($counts['overdue'] ?? 0), 'note' => 'Require collection follow-up'],
  ['label' => 'Closed', 'value' => intval($counts['closed'] ?? 0), 'note' => 'Loans fully settled'],
];
?>
  <section class="dashboard-section">
    <div class="dashboard-grid">
      <?php foreach ($role_metric_cards as $metric): ?>
        <article class="dashboard-card">
          <div class="dashboard-card-label"><?= htmlspecialchars($metric['label']) ?></div>
          <p class="dashboard-card-value"><?= htmlspecialchars((string)$metric['value']) ?></p>
          <div class="dashboard-card-note"><?= htmlspecialchars($metric['note']) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

<?php endif; ?>

<?php if (is_system_admin()): ?>
<?php else: ?>
  <section class="dashboard-section">
    <div class="dashboard-grid dashboard-grid-wide">
      <?php foreach ($pipeline_cards as $metric): ?>
        <article class="dashboard-card">
          <div class="dashboard-card-label"><?= htmlspecialchars($metric['label']) ?></div>
          <p class="dashboard-card-value"><?= htmlspecialchars((string)$metric['value']) ?></p>
          <div class="dashboard-card-note"><?= htmlspecialchars($metric['note']) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

<?php endif; ?>

  <section class="dashboard-table-grid">
    <article class="dashboard-table-card">
      <h3>Recent client applications</h3>
      <div class="dashboard-table-wrap">
        <table class="table">
          <thead><tr><th>Reference</th><th>Customer</th><th>Status</th><th>Submitted</th></tr></thead>
          <tbody>
          <?php foreach ($applicants as $a): ?>
            <tr>
              <td><?= htmlspecialchars($a['reference_no']) ?></td>
              <td><?= htmlspecialchars($a['customer_name']) ?> <span class="small">(<?= htmlspecialchars($a['customer_no']) ?>)</span></td>
              <td><span class="badge <?= status_badge_class($a['status']) ?>"><?= htmlspecialchars($a['status']) ?></span></td>
              <td><?= htmlspecialchars($a['submitted_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($applicants)): ?><tr><td colspan="4" class="small">No applications yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>

    <article class="dashboard-table-card">
      <h3><?= is_system_admin() ? 'Staff & Admin ranking' : 'Staff directory' ?></h3>
      <div class="dashboard-table-wrap">
        <table class="table">
          <thead><tr><th>Name</th><th>Role</th></tr></thead>
          <tbody>
          <?php foreach ($staff as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['full_name']) ?></td>
              <td><?= htmlspecialchars(str_replace('_', ' ', $s['role'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($staff)): ?><tr><td colspan="2" class="small">No staff.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
