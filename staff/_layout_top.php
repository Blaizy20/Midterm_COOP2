<?php
require_once __DIR__ . '/../includes/auth.php';
$user = current_user();
$settings = get_system_settings();
$active_tenant_name = '';
$can_switch_tenant = is_admin_owner() || is_super_admin();
$can_view_settings = can_access('view_settings') && (!is_super_admin() || current_tenant_id());
$profile_name = $user['full_name'] ?? ($_SESSION['full_name'] ?? '');
$profile_role = get_role_display_name($_SESSION['role'] ?? '');
$profile_initial = strtoupper(substr(trim($profile_name), 0, 1));
$primary_color = $settings['primary_color'] ?? app_default_primary_color();

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primary_color)) {
  $primary_color = app_default_primary_color();
}

function theme_hex_to_rgb($hex) {
  $hex = ltrim($hex, '#');
  return [
    hexdec(substr($hex, 0, 2)),
    hexdec(substr($hex, 2, 2)),
    hexdec(substr($hex, 4, 2)),
  ];
}

function theme_adjust_hex($hex, $factor) {
  [$red, $green, $blue] = theme_hex_to_rgb($hex);
  $channels = [$red, $green, $blue];

  foreach ($channels as &$channel) {
    if ($factor >= 0) {
      $channel = (int) round($channel + ((255 - $channel) * $factor));
    } else {
      $channel = (int) round($channel * (1 + $factor));
    }
    $channel = max(0, min(255, $channel));
  }
  unset($channel);

  return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
}

[$primary_red, $primary_green, $primary_blue] = theme_hex_to_rgb($primary_color);
$primary_hover = theme_adjust_hex($primary_color, -0.18);
$primary_deep = theme_adjust_hex($primary_color, -0.5);
$primary_mid = theme_adjust_hex($primary_color, -0.28);
$primary_soft = "rgba({$primary_red}, {$primary_green}, {$primary_blue}, 0.08)";
$primary_soft_strong = "rgba({$primary_red}, {$primary_green}, {$primary_blue}, 0.14)";

if (!is_super_admin() && current_tenant_id()) {
  $active_tenant = fetch_one(q(
    "SELECT COALESCE(display_name, tenant_name) AS tenant_name FROM tenants WHERE tenant_id=? LIMIT 1",
    "i",
    [current_tenant_id()]
  ));
  $active_tenant_name = $active_tenant['tenant_name'] ?? '';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= htmlspecialchars($title ?? 'Loan Management') ?></title>
  <link rel="stylesheet" href="<?php echo APP_BASE; ?>/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
  <style>
    :root {
      --brand-primary: <?= htmlspecialchars($primary_color, ENT_QUOTES) ?>;
      --brand-primary-hover: <?= htmlspecialchars($primary_hover, ENT_QUOTES) ?>;
      --brand-primary-deep: <?= htmlspecialchars($primary_deep, ENT_QUOTES) ?>;
      --brand-primary-mid: <?= htmlspecialchars($primary_mid, ENT_QUOTES) ?>;
      --brand-topbar-start: <?= htmlspecialchars($primary_deep, ENT_QUOTES) ?>;
      --brand-topbar-end: <?= htmlspecialchars($primary_mid, ENT_QUOTES) ?>;
      --brand-primary-rgb: <?= intval($primary_red) ?>, <?= intval($primary_green) ?>, <?= intval($primary_blue) ?>;
      --brand-primary-soft: <?= htmlspecialchars($primary_soft, ENT_QUOTES) ?>;
      --brand-primary-soft-strong: <?= htmlspecialchars($primary_soft_strong, ENT_QUOTES) ?>;
      --brand-red: var(--brand-primary);
      --brand-red-hover: var(--brand-primary-hover);
    }

    .sidebar a {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sidebar a .bi {
      width: 18px;
      text-align: center;
      font-size: 15px;
      flex: 0 0 18px;
    }
  </style>
</head>
<body>
<div class="topbar">
  <div class="brand">
    <img src="<?php echo htmlspecialchars($settings['logo_path'] ?? APP_BASE . '/assets/img/logo.png'); ?>" alt="Logo"/>
    <div>
      <div style="font-weight:800;line-height:1"><?= htmlspecialchars($settings['system_name'] ?? 'CredenceLend') ?></div>
      <div class="small" style="color:#fde8ec"><?php 
        $role = $_SESSION['role'] ?? '';
        $roleNames = [
          'SUPER_ADMIN' => 'Super Admin Portal',
          'ADMIN' => 'Admin Portal',
          'MANAGER' => 'Manager Portal',
          'CREDIT_INVESTIGATOR' => 'Credit Investigator Portal',
          'LOAN_OFFICER' => 'Loan Officer Portal',
          'CASHIER' => 'Cashier Portal'
        ];
        echo htmlspecialchars($roleNames[$role] ?? 'Staff Portal');
      ?></div>
    </div>
  </div>
  <div class="topbar-actions">
    <?php if ($active_tenant_name): ?>
      <span class="small topbar-tenant-label" style="color:#fde8ec"><?= htmlspecialchars($active_tenant_name) ?></span>
    <?php endif; ?>
    <button
      type="button"
      class="profile-trigger"
      id="profileMenuTrigger"
      aria-haspopup="dialog"
      aria-expanded="false"
      aria-controls="profileMenuModal"
    >
      <i class="bi bi-person-circle"></i>
      <span class="profile-trigger-label">Profile</span>
    </button>
  </div>
</div>
<div class="profile-modal-backdrop" id="profileMenuBackdrop" hidden></div>
<div
  class="profile-modal"
  id="profileMenuModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="profileMenuTitle"
  hidden
>
  <div class="profile-modal-header">
    <div class="profile-modal-identity">
      <div class="profile-modal-avatar"><?= htmlspecialchars($profile_initial ?: 'U') ?></div>
      <div class="profile-modal-copy">
        <div class="profile-modal-kicker">Signed In As</div>
        <h3 id="profileMenuTitle"><?= htmlspecialchars($profile_name) ?></h3>
        <div class="profile-modal-role"><?= htmlspecialchars($profile_role) ?></div>
      </div>
    </div>
    <button type="button" class="profile-modal-close" id="profileMenuClose" aria-label="Close profile menu">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <div class="profile-modal-panel">
    <?php if ($active_tenant_name): ?>
      <div class="profile-modal-tenant-block">
        <div class="profile-modal-label">Active Tenant</div>
        <div class="profile-modal-tenant"><?= htmlspecialchars($active_tenant_name) ?></div>
      </div>
    <?php endif; ?>
    <div class="profile-modal-status">
      <span class="profile-status-dot"></span>
      <span>Session active</span>
    </div>
  </div>
  <div class="profile-modal-section-title">Quick Actions</div>
  <div class="profile-modal-actions">
    <?php if ($can_switch_tenant): ?>
      <a
        class="profile-modal-link"
        href="<?php echo APP_BASE; ?>/staff/select_tenant.php"
        data-profile-nav="<?php echo APP_BASE; ?>/staff/select_tenant.php"
      >
        <span class="profile-modal-link-icon"><i class="bi bi-arrow-repeat"></i></span>
        <span class="profile-modal-link-copy">
          <span class="profile-modal-link-title">Switch Tenant</span>
          <span class="profile-modal-link-subtitle">Change your active workspace</span>
        </span>
      </a>
    <?php endif; ?>
    <?php if ($can_view_settings): ?>
      <a class="profile-modal-link" href="<?php echo APP_BASE; ?>/staff/manager_settings.php">
        <span class="profile-modal-link-icon"><i class="bi bi-gear"></i></span>
        <span class="profile-modal-link-copy">
          <span class="profile-modal-link-title">Settings</span>
          <span class="profile-modal-link-subtitle">Manage portal configuration</span>
        </span>
      </a>
    <?php endif; ?>
    <a class="profile-modal-link logout" href="<?php echo APP_BASE; ?>/staff/logout.php">
      <span class="profile-modal-link-icon"><i class="bi bi-box-arrow-right"></i></span>
      <span class="profile-modal-link-copy">
        <span class="profile-modal-link-title">Logout</span>
        <span class="profile-modal-link-subtitle">End this session securely</span>
      </span>
    </a>
  </div>
</div>
<div class="layout">
  <div class="sidebar">
    <h3>Menu</h3>
    <a href="<?php echo APP_BASE; ?>/staff/dashboard.php" class="<?= ($active??'')==='dash'?'active':''?>"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
    
    <?php if (can_access('view_loans')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/loans.php" class="<?= ($active??'')==='loans'?'active':''?>"><i class="bi bi-cash-stack"></i><span>Loans</span></a>
    <?php endif; ?>
    <?php if (can_access('view_customers')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/customers.php" class="<?= ($active??'')==='cust'?'active':''?>"><i class="bi bi-people-fill"></i><span>Customers</span></a>
    <?php endif; ?>
    
    <?php if (can_access('view_payments')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/payments.php" class="<?= ($active??'')==='pay'?'active':''?>"><i class="bi bi-credit-card-2-front-fill"></i><span>Payments</span></a>
    <?php endif; ?>
    <?php if (can_access('manage_vouchers')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/release_queue.php" class="<?= ($active??'')==='release_queue'?'active':''?>"><i class="bi bi-wallet2"></i><span>Money Release</span></a>
    <?php endif; ?>
    <?php if (can_access('review_applications')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/ci_queue.php" class="<?= ($active??'')==='ci'?'active':''?>"><i class="bi bi-search"></i><span>CI Review</span></a>
    <?php endif; ?>
    <?php if (can_access('approve_applications')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/manager_queue.php" class="<?= ($active??'')==='mgr'?'active':''?>"><i class="bi bi-check2-square"></i><span>Manager Approval</span></a>
    <?php endif; ?>
    <?php if (can_access('view_reports')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/reports.php" class="<?= ($active??'')==='rep'?'active':''?>"><i class="bi bi-bar-chart-line-fill"></i><span>Reports</span></a>
    <?php endif; ?>
    <?php if (can_access('view_staff')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/staff.php" class="<?= ($active??'')==='staff'?'active':''?>"><i class="bi bi-person-badge-fill"></i><span>Staff</span></a>
    <?php endif; ?>
    <?php if (can_access('manage_staff')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/registration.php" class="<?= ($active??'')==='reg'?'active':''?>"><i class="bi bi-person-plus-fill"></i><span>Register Staff</span></a>
    <?php endif; ?>
    <?php if (can_access('manage_tenants')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/tenant_management.php" class="<?= ($active??'')==='tenants'?'active':''?>"><i class="bi bi-buildings-fill"></i><span>Tenant Management</span></a>
    <?php endif; ?>
    <?php if (can_access('view_sales')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/sales_report.php" class="<?= ($active??'')==='sales'?'active':''?>"><i class="bi bi-graph-up-arrow"></i><span>Sales Report</span></a>
    <?php endif; ?>
    <?php if (can_access('view_history')): ?>
      <a href="<?php echo APP_BASE; ?>/staff/history.php" class="<?= ($active??'')==='history'?'active':''?>"><i class="bi bi-clock-history"></i><span>History</span></a>
    <?php endif; ?>
    <?php if (can_access('view_settings') && (!is_super_admin() || current_tenant_id())): ?>
      <a href="<?php echo APP_BASE; ?>/staff/manager_settings.php" class="<?= ($active??'')==='settings'?'active':''?>"><i class="bi bi-gear-fill"></i><span>Settings</span></a>
    <?php endif; ?>
  </div>
  <div class="main">
