<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';

$selected_tenant_id = requested_tenant_id();
$tenants = fetch_all(q(
  "SELECT tenant_id, tenant_name, COALESCE(display_name, tenant_name) AS display_name
   FROM tenants
   WHERE is_active=1
   ORDER BY tenant_name ASC"
));
$settings = get_system_settings($selected_tenant_id);
$error = '';

if (isset($_GET['error']) && $_GET['error'] === 'invalid_tenant') {
  $error = 'Your tenant access is invalid or inactive. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $selected_tenant_id = intval($_POST['tenant_id'] ?? 0);
  $settings = get_system_settings($selected_tenant_id);
  $user = null;
  $tenant_scoped_roles = ['TENANT', 'MANAGER', 'CREDIT_INVESTIGATOR', 'LOAN_OFFICER', 'CASHIER'];

  if ($username === '' || $password === '') {
    $error = "Enter your username and password.";
  } else {
    $auth_service = new AuthService();

    if ($selected_tenant_id > 0) {
      $user = $auth_service->authenticateStaffUser($username, $password, $selected_tenant_id);
    }

    if (!$user) {
      $user = $auth_service->authenticateStaffUser($username, $password);
    }

    if ($user && in_array($user['role'], $tenant_scoped_roles, true) && $selected_tenant_id <= 0) {
      $error = "Select your tenant to continue.";
      $user = null;
    }

    if ($user && in_array($user['role'], $tenant_scoped_roles, true) && intval($user['tenant_id'] ?? 0) !== $selected_tenant_id) {
      $error = "Invalid credentials (staff only).";
      $user = null;
    }
  }

  if (!$error && (!$user || !in_array($user['role'], ['SUPER_ADMIN', 'ADMIN', 'TENANT', 'MANAGER', 'CREDIT_INVESTIGATOR', 'LOAN_OFFICER', 'CASHIER'], true))) {
    $error = "Invalid credentials (staff only).";
  } elseif (!$error) {
    login_user($user);
    $_SESSION['pending_login_audit'] = 1;

    if ($user['role'] === 'ADMIN') {
      $owned_tenants = user_owned_tenants($user['user_id'], true);
      if (empty($owned_tenants)) {
        logout_user();
        $error = "This admin account has no active owned tenants assigned.";
      } elseif (count($owned_tenants) === 1) {
        set_current_active_tenant_id($owned_tenants[0]['tenant_id']);
      } else {
        clear_current_active_tenant_id();
        header("Location: " . APP_BASE . "/staff/select_tenant.php");
        exit;
      }
    }

    if (!$error && !is_super_admin() && !is_admin_owner()) {
      set_current_active_tenant_id($user['tenant_id']);
    }

    if (!$error) {
      $current_tenant_id = intval(current_tenant_id() ?? 0);
      if ($current_tenant_id > 0 && !empty($_SESSION['pending_login_audit'])) {
        log_activity_for_tenant(
          $current_tenant_id,
          'USER_LOGIN',
          'Staff user logged in: ' . ($_SESSION['full_name'] ?? $user['full_name']) . ' (' . ($_SESSION['role'] ?? $user['role']) . ').'
        );
        unset($_SESSION['pending_login_audit']);
      }

      $return_url = $_SESSION['return_url'] ?? (APP_BASE . "/staff/dashboard.php");
      unset($_SESSION['return_url']);
      header("Location: " . $return_url);
      exit;
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Staff Login</title>
  <link rel="stylesheet" href="<?php echo APP_BASE; ?>/assets/css/theme.css">
  <style>
    :root{
      --login-primary: <?= json_encode($settings['primary_color'] ?? app_default_primary_color()) ?>;
      --login-primary-dark: #0b1433;
      --login-ink: #e5eefc;
      --login-muted: #93a4c3;
      --login-line: rgba(148, 163, 184, 0.18);
      --login-panel: rgba(9, 16, 34, 0.82);
      --login-surface: rgba(15, 23, 42, 0.9);
      --login-shadow: 0 28px 80px rgba(2, 6, 23, 0.46);
    }
    body.login-body{
      min-height:100vh;
      background:
        radial-gradient(circle at top left, rgba(37, 99, 235, .24), transparent 26%),
        radial-gradient(circle at bottom right, rgba(14, 165, 233, .14), transparent 28%),
        linear-gradient(135deg, #030712 0%, #081224 40%, #0b1733 100%);
      color:var(--login-ink);
    }
    .login-shell{
      min-height:100vh;
      padding:24px;
      display:flex;
      flex-direction:column;
    }
    .login-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      margin-bottom:24px;
    }
    .login-brand{
      display:flex;
      align-items:center;
      gap:14px;
    }
    .login-brand-mark{
      width:54px;
      height:54px;
      border-radius:18px;
      background:rgba(255,255,255,.08);
      border:1px solid rgba(255,255,255,.08);
      box-shadow:0 14px 36px rgba(2,6,23,.28);
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:hidden;
    }
    .login-brand-mark img{
      width:40px;
      height:40px;
      object-fit:contain;
    }
    .login-brand-text strong{
      display:block;
      font-size:17px;
      line-height:1.1;
      letter-spacing:-.03em;
    }
    .login-brand-text span{
      display:block;
      margin-top:4px;
      color:var(--login-muted);
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:.12em;
    }
    .login-status{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:10px 14px;
      border-radius:999px;
      background:rgba(15,23,42,.58);
      border:1px solid var(--login-line);
      color:var(--login-muted);
      font-size:12px;
    }
    .login-status::before{
      content:"";
      width:8px;
      height:8px;
      border-radius:999px;
      background:var(--login-primary);
      box-shadow:0 0 0 4px rgba(44,62,197,.12);
    }
    .login-main{
      flex:1;
      display:grid;
      grid-template-columns:minmax(0, 1.05fr) minmax(380px, 460px);
      gap:26px;
      align-items:stretch;
    }
    .login-hero{
      position:relative;
      overflow:hidden;
      border-radius:32px;
      padding:38px;
      color:#fff;
      background:
        radial-gradient(circle at top left, rgba(96,165,250,.24), transparent 28%),
        radial-gradient(circle at bottom right, rgba(14,165,233,.18), transparent 24%),
        linear-gradient(135deg, #091121 0%, var(--login-primary-dark) 40%, var(--login-primary) 100%);
      box-shadow:var(--login-shadow);
      display:flex;
      flex-direction:column;
      justify-content:space-between;
    }
    .login-hero::after{
      content:"";
      position:absolute;
      right:-70px;
      top:40px;
      width:220px;
      height:220px;
      border-radius:999px;
      background:rgba(255,255,255,.12);
      filter:blur(4px);
    }
    .login-kicker{
      position:relative;
      z-index:1;
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:10px 14px;
      border-radius:999px;
      background:rgba(255,255,255,.12);
      border:1px solid rgba(255,255,255,.16);
      font-size:11px;
      text-transform:uppercase;
      letter-spacing:.14em;
      color:rgba(255,255,255,.8);
    }
    .login-kicker::before{
      content:"";
      width:8px;
      height:8px;
      border-radius:999px;
      background:#7dd3fc;
    }
    .login-hero h1{
      position:relative;
      z-index:1;
      margin:22px 0 14px;
      font-size:clamp(40px, 6vw, 72px);
      line-height:.96;
      letter-spacing:-.06em;
      max-width:10ch;
    }
    .login-hero p{
      position:relative;
      z-index:1;
      max-width:58ch;
      margin:0;
      color:rgba(255,255,255,.78);
      line-height:1.7;
      font-size:15px;
    }
    .login-role-grid{
      position:relative;
      z-index:1;
      margin-top:28px;
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:14px;
    }
    .login-role-card{
      padding:16px 18px;
      border-radius:20px;
      background:rgba(255,255,255,.12);
      border:1px solid rgba(255,255,255,.14);
      backdrop-filter:blur(10px);
    }
    .login-role-card strong{
      display:block;
      font-size:14px;
      letter-spacing:.02em;
    }
    .login-role-card span{
      display:block;
      margin-top:6px;
      color:rgba(255,255,255,.68);
      font-size:12px;
      line-height:1.5;
    }
    .login-hero-footer{
      position:relative;
      z-index:1;
      margin-top:24px;
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:12px;
    }
    .login-stat{
      padding:16px 18px;
      border-radius:20px;
      background:rgba(15,23,42,.18);
      border:1px solid rgba(255,255,255,.08);
    }
    .login-stat strong{
      display:block;
      font-size:24px;
      letter-spacing:-.04em;
    }
    .login-stat span{
      display:block;
      margin-top:4px;
      font-size:12px;
      color:rgba(255,255,255,.72);
    }
    .login-card{
      border-radius:32px;
      padding:28px;
      background:var(--login-panel);
      border:1px solid rgba(148,163,184,.12);
      box-shadow:var(--login-shadow);
      backdrop-filter:blur(18px);
      display:flex;
      flex-direction:column;
      justify-content:center;
    }
    .login-card-top{
      margin-bottom:18px;
    }
    .login-card-top h2{
      margin:0;
      font-size:34px;
      line-height:1;
      letter-spacing:-.05em;
    }
    .login-card-top p{
      margin:10px 0 0;
      color:var(--login-muted);
      line-height:1.65;
      font-size:14px;
    }
    .login-card .input{
      min-height:48px;
      border-radius:16px;
      border:1px solid rgba(148,163,184,.16);
      background:var(--login-surface);
      box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
      color:var(--login-ink);
    }
    .login-card .input::placeholder{
      color:#6b7b97;
    }
    .login-card .input:focus{
      outline:none;
      border-color:rgba(96,165,250,.45);
      box-shadow:0 0 0 3px rgba(59,130,246,.16);
    }
    .login-card select.input option{
      color:#e5eefc;
      background:#0f172a;
    }
    .login-card .label{
      margin:0 0 8px;
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:.12em;
      color:var(--login-muted);
    }
    .login-field{
      margin-top:16px;
    }
    .login-hint{
      margin-top:8px;
      color:var(--login-muted);
      font-size:12px;
      line-height:1.55;
    }
    .login-password-row{
      display:flex;
      align-items:center;
      gap:10px;
    }
    .login-toggle{
      display:flex;
      align-items:center;
      gap:8px;
      margin-top:12px;
      color:var(--login-muted);
      font-size:12px;
    }
    .login-submit{
      width:100%;
      min-height:50px;
      margin-top:20px;
      border-radius:16px;
      font-size:15px;
      box-shadow:0 16px 34px rgba(44,62,197,.22);
    }
    .login-card-footer{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:14px;
      margin-top:16px;
      flex-wrap:wrap;
    }
    .login-link{
      color:var(--login-primary);
      font-weight:600;
    }
    .login-note{
      color:var(--login-muted);
      font-size:12px;
    }
    .login-card .alert.err{
      background:rgba(127, 29, 29, 0.28);
      color:#fecaca;
      border:1px solid rgba(248,113,113,.22);
    }
    @media (max-width: 1080px){
      .login-main{
        grid-template-columns:1fr;
      }
      .login-hero,
      .login-card{
        border-radius:28px;
      }
    }
    @media (max-width: 720px){
      .login-shell{
        padding:16px;
      }
      .login-header{
        flex-direction:column;
        align-items:flex-start;
      }
      .login-hero,
      .login-card{
        padding:22px;
      }
      .login-role-grid,
      .login-hero-footer{
        grid-template-columns:1fr;
      }
      .login-card-top h2{
        font-size:30px;
      }
      .login-card-footer{
        flex-direction:column;
        align-items:flex-start;
      }
    }
  </style>
</head>
<body class="login-body">
  <div class="login-shell">
    <header class="login-header">
      <div class="login-brand">
        <div class="login-brand-mark">
          <img src="<?php echo htmlspecialchars($settings['logo_path'] ?? APP_BASE . '/assets/img/logo.png'); ?>" alt="Logo"/>
        </div>
        <div class="login-brand-text">
          <strong><?= htmlspecialchars($settings['system_name'] ?? 'CredenceLend') ?></strong>
          <span>Staff Portal</span>
        </div>
      </div>
      <div class="login-status">Secure staff access</div>
    </header>

    <main class="login-main">
      <section class="login-hero">
        <div>
          <div class="login-kicker">Operational Access</div>
          <h1>Access the lending workspace with the right tenant context.</h1>
          <p>Sign in to review applications, manage collections, monitor customer activity, and move daily loan operations forward. Tenant-scoped staff should select their tenant before continuing.</p>

          <div class="login-role-grid">
            <div class="login-role-card">
              <strong>Admin owners</strong>
              <span>Can sign in without choosing a tenant on this screen.</span>
            </div>
            <div class="login-role-card">
              <strong>Managers and officers</strong>
              <span>Use tenant-aware access for assigned branch activity and review queues.</span>
            </div>
            <div class="login-role-card">
              <strong>Credit investigation</strong>
              <span>Enter the correct tenant to keep borrower review and approval data aligned.</span>
            </div>
            <div class="login-role-card">
              <strong>Cashiers</strong>
              <span>Open the right tenant context before recording payment and receipt activity.</span>
            </div>
          </div>
        </div>

        <div class="login-hero-footer">
          <div class="login-stat">
            <strong><?= count($tenants) ?></strong>
            <span>Active tenants</span>
          </div>
          <div class="login-stat">
            <strong>24/7</strong>
            <span>Portal access</span>
          </div>
          <div class="login-stat">
            <strong>1</strong>
            <span>Secure staff session</span>
          </div>
        </div>
      </section>

      <section class="login-card">
        <div class="login-card-top">
          <h2>Staff Login</h2>
          <p>Enter your credentials to continue to the dashboard. The login flow and tenant rules are unchanged.</p>
        </div>

        <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post">
          <div class="login-field">
            <label class="label">Tenant</label>
            <select class="input" name="tenant_id">
              <option value="">Select tenant if you are tenant-scoped staff</option>
              <?php foreach ($tenants as $tenant): ?>
                <option value="<?= intval($tenant['tenant_id']) ?>" <?= ($selected_tenant_id === intval($tenant['tenant_id'])) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($tenant['display_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="login-hint">Owner `ADMIN` and `SUPER_ADMIN` accounts can sign in without choosing a tenant here.</div>
          </div>

          <div class="login-field">
            <label class="label">Username</label>
            <input class="input" name="username" required>
          </div>

          <div class="login-field">
            <label class="label">Password</label>
            <div class="login-password-row">
              <input class="input" type="password" id="pw" name="password" required>
            </div>
            <label class="login-toggle">
              <input type="checkbox" onclick="document.getElementById('pw').type=this.checked?'text':'password'">
              <span>Show password</span>
            </label>
          </div>

          <button class="btn btn-primary login-submit" type="submit">Login</button>

          <div class="login-card-footer">
            <a class="login-link" href="<?php echo APP_BASE; ?>/staff/forgot_password.php<?= $selected_tenant_id ? '?tenant_id=' . intval($selected_tenant_id) : '' ?>">Forgot password?</a>
            <div class="login-note">Admin / Manager / Credit Investigator / Loan Officer / Cashier</div>
          </div>
        </form>
      </section>
    </main>
  </div>
</body>
</html>
