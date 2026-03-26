<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('view_customers');

$session_tenant_id = current_tenant_id();
$has_active_tenant_context = intval($session_tenant_id ?? 0) > 0;
$is_super_admin = is_super_admin();
$err = '';
$ok = $_SESSION['customer_success_message'] ?? '';
unset($_SESSION['customer_success_message']);

// Handle Delete Customer (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
  require_permission('manage_customers');
  $customer_id = intval($_POST['delete_customer'] ?? 0);
  if ($customer_id > 0) {
    enforce_tenant_resource_access('customers', 'customer_id', $customer_id);
    $customer = fetch_one(q(
      "SELECT customer_no, first_name, last_name FROM customers WHERE customer_id=? AND " . tenant_condition('tenant_id'),
      tenant_types("i"),
      tenant_params([$customer_id])
    ));
    q(
      "UPDATE customers SET is_active=0 WHERE " . tenant_condition('tenant_id') . " AND customer_id=?",
      tenant_types("i"),
      tenant_params([$customer_id])
    );
    if ($customer) {
      log_activity('Customer Deactivated', 'Customer ' . htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) . ' deactivated', null, $customer_id, $customer['customer_no']);
    }
    $ok = "Customer account deactivated.";
  } else {
    $err = "Cannot delete this account.";
  }
}

// Handle Activate Customer (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_customer'])) {
  require_permission('manage_customers');
  $customer_id = intval($_POST['activate_customer'] ?? 0);
  if ($customer_id > 0) {
    enforce_tenant_resource_access('customers', 'customer_id', $customer_id);
    $customer = fetch_one(q(
      "SELECT customer_no, first_name, last_name FROM customers WHERE customer_id=? AND " . tenant_condition('tenant_id'),
      tenant_types("i"),
      tenant_params([$customer_id])
    ));
    q(
      "UPDATE customers SET is_active=1 WHERE " . tenant_condition('tenant_id') . " AND customer_id=?",
      tenant_types("i"),
      tenant_params([$customer_id])
    );
    if ($customer) {
      log_activity('Customer Activated', 'Customer ' . htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) . ' activated', null, $customer_id, $customer['customer_no']);
    }
    $ok = "Customer account activated.";
  } else {
    $err = "Cannot activate this account.";
  }
}

// Handle Permanently Delete Customer (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['permanent_delete_customer'])) {
  require_permission('manage_customers');
  $customer_id = intval($_POST['permanent_delete_customer'] ?? 0);
  if ($customer_id > 0) {
    enforce_tenant_resource_access('customers', 'customer_id', $customer_id);
    $customer = fetch_one(q(
      "SELECT customer_no, first_name, last_name FROM customers WHERE customer_id=? AND " . tenant_condition('tenant_id'),
      tenant_types("i"),
      tenant_params([$customer_id])
    ));
    q(
      "DELETE FROM customers WHERE " . tenant_condition('tenant_id') . " AND customer_id=?",
      tenant_types("i"),
      tenant_params([$customer_id])
    );
    if ($customer) {
      log_activity('Customer Permanently Deleted', 'Customer ' . htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) . ' permanently deleted', null, $customer_id, $customer['customer_no']);
    }
    $ok = "Customer account permanently deleted.";
  } else {
    $err = "Cannot delete this account.";
  }
}

// Handle Update Customer (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
  require_permission('manage_customers');
  $customer_id = intval($_POST['customer_id'] ?? 0);
  $first = trim($_POST['first_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $contact = trim($_POST['contact_no'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $prov = trim($_POST['province'] ?? '');
  $city = trim($_POST['city'] ?? '');
  $brgy = trim($_POST['barangay'] ?? '');
  $street = trim($_POST['street'] ?? '');

  if ($first === '' || $last === '') $err = "Please enter first and last name.";
  else if ($contact === '') $err = "Please enter contact number.";
  else if ($username === '') $err = "Please enter username.";
  else if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = "Invalid email format.";
  else {
    enforce_tenant_resource_access('customers', 'customer_id', $customer_id);
    $customer = fetch_one(q(
      "SELECT customer_no, user_id, tenant_id FROM customers WHERE customer_id=? AND " . tenant_condition('tenant_id'),
      tenant_types("i"),
      tenant_params([$customer_id])
    ));
    if (!$customer || !$customer['user_id']) {
      $err = "Customer not found.";
    } else {
      $customer_tenant_id = intval($customer['tenant_id']);
      // Check if username is already taken by another user
      $username_exists = fetch_one(q(
        "SELECT user_id FROM users WHERE tenant_id=? AND LOWER(username)=LOWER(?) AND user_id != ?",
        "isi",
        [$customer_tenant_id, $username, intval($customer['user_id'])]
      ));
      if ($username_exists) {
        $err = "Username already taken.";
      } else {
        q(
          "UPDATE customers SET first_name=?, last_name=?, contact_no=?, email=?, province=?, city=?, barangay=?, street=? WHERE " . tenant_condition('tenant_id') . " AND customer_id=?",
          tenant_types("ssssssssi"),
          tenant_params([$first, $last, $contact, $email, $prov, $city, $brgy, $street, $customer_id])
        );
        q(
          "UPDATE users SET username=?, full_name=? WHERE tenant_id=? AND user_id=?",
          "ssii",
          [$username, ($first.' '.$last), $customer_tenant_id, intval($customer['user_id'])]
        );
        if ($customer) {
          log_activity('Customer Updated', 'Customer account information updated for ' . htmlspecialchars($first . ' ' . $last), null, $customer_id, $customer['customer_no']);
        }
        // Redirect to clear the success message and reset page state
        header("Location: " . APP_BASE . "/staff/customers.php");
        exit;
      }
    }
  }
}

// Handle Register Customer (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_customer'])) {
  require_permission('manage_customers');
  
  $first = trim($_POST['first_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $contact = trim($_POST['contact_no'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $prov = trim($_POST['province'] ?? '');
  $city = trim($_POST['city'] ?? '');
  $brgy = trim($_POST['barangay'] ?? '');
  $street = trim($_POST['street'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $pw = $_POST['password'] ?? '';
  $pw2 = $_POST['confirm_password'] ?? '';

  if ($first==='' || $last==='' || $contact==='' || $username==='') $err="Please complete all required fields.";
  else if (!$has_active_tenant_context) $err = "Select a tenant first before registering a customer.";
  else if ($pw !== $pw2) $err="Passwords do not match.";
  else if (!password_is_strong($pw)) $err="Password must be 8+ chars with upper, lower, number, special.";
  else if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = "Invalid email format.";
  else {
    $conn = db();
    try {
      $conn->begin_transaction();
      // Check if username already exists
      $existing = fetch_one(q(
        "SELECT user_id FROM users WHERE tenant_id=? AND LOWER(username)=LOWER(?)",
        "is",
        [$session_tenant_id, $username]
      ));
      if ($existing) {
        $conn->rollback();
        $err = "Username already taken.";
      } else if (fetch_one(q("SELECT customer_id FROM customers WHERE tenant_id=? AND contact_no=?", "is", [$session_tenant_id, $contact]))) {
        $conn->rollback();
        $err = "Contact number already registered.";
      } else if ($email !== '' && fetch_one(q("SELECT customer_id FROM customers WHERE tenant_id=? AND email=?", "is", [$session_tenant_id, $email]))) {
        $conn->rollback();
        $err = "Email already registered.";
      } else {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        q("INSERT INTO users (tenant_id, username, password_hash, full_name, role) VALUES (?,?,?,?,?)", "issss",
          [$session_tenant_id, $username, $hash, ($first.' '.$last), 'CUSTOMER']);
        $user_id = intval($conn->insert_id);

        $customer_no = generate_customer_no();
        q("INSERT INTO customers (tenant_id, customer_no, user_id, first_name, last_name, contact_no, email, province, city, barangay, street)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          "isissssssss", [$session_tenant_id, $customer_no, $user_id, $first, $last, $contact, $email, $prov, $city, $brgy, $street]);

        $conn->commit();
        log_activity('Customer Registered', 'New customer ' . htmlspecialchars($first . ' ' . $last) . ' registered', null, $user_id, $customer_no);
        $_SESSION['customer_success_message'] = "Customer account created successfully.";
        header("Location: " . APP_BASE . "/staff/customers.php");
        exit;
      }
    } catch (mysqli_sql_exception $e) {
      try { $conn->rollback(); } catch (Exception $ex) {}
      $err = "Registration failed: " . htmlspecialchars($e->getMessage());
    }
  }
}

$rows = fetch_all(q(
  "SELECT c.customer_id, c.customer_no, c.first_name, c.last_name, c.contact_no, c.email, c.province, c.city, c.barangay, c.street, c.created_at, c.is_active, u.username
   FROM customers c
   LEFT JOIN users u ON u.user_id=c.user_id AND u.tenant_id=c.tenant_id
   WHERE " . tenant_condition('c.tenant_id') . "
   ORDER BY c.created_at DESC",
  tenant_types(),
  tenant_params()
));

$customer_metrics = fetch_one(q(
  "SELECT
      COUNT(*) AS total_customers,
      SUM(CASE WHEN c.is_active = 1 THEN 1 ELSE 0 END) AS active_customers,
      SUM(CASE WHEN c.is_active = 0 THEN 1 ELSE 0 END) AS inactive_customers,
      SUM(CASE WHEN DATE(c.created_at) = CURDATE() THEN 1 ELSE 0 END) AS customers_today
   FROM customers c
   WHERE " . tenant_condition('c.tenant_id'),
  tenant_types(),
  tenant_params()
));
$title="Customers"; $active="cust";
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

  .customers-shell {
    display: grid;
    gap: 20px;
    color: #e5eefb;
  }

  .customers-hero {
    display: grid;
    gap: 18px;
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .customers-card,
  .customer-modal-card {
    border-radius: 26px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    background:
      radial-gradient(circle at top left, rgba(56, 189, 248, 0.12), transparent 26%),
      linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 30, 0.97));
    box-shadow: 0 24px 60px rgba(2, 6, 23, 0.34);
    padding: 24px;
  }

  .customers-header {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: end;
    margin-bottom: 18px;
  }

  .customers-kicker {
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

  .customers-header h2 {
    margin: 10px 0 0;
    font-size: clamp(30px, 4vw, 44px);
    line-height: 1;
    letter-spacing: -0.04em;
    color: #f8fbff;
  }

  .customers-header p {
    margin: 8px 0 0;
    max-width: 780px;
    color: #8ea3bf;
    line-height: 1.7;
    font-size: 14px;
  }

  .customers-card h3,
  .customer-modal-card h3 {
    color: #f8fbff;
  }

  .customers-card .label,
  .customer-modal-card .label {
    color: #93a8c6;
  }

  .customers-card .input,
  .customer-modal-card .input {
    background: rgba(15, 23, 42, 0.86);
    color: #f8fbff;
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  .customers-card .input::placeholder,
  .customer-modal-card .input::placeholder {
    color: #6f86a6;
  }

  .customers-card .small,
  .customer-modal-card .small {
    color: #8ea3bf;
  }

  .customers-card .btn.btn-ghost,
  .customers-card .btn.btn-outline,
  .customer-modal-card .btn.btn-outline {
    border-color: rgba(148, 163, 184, 0.2);
    color: #d8e4f5;
    background: rgba(15, 23, 42, 0.55);
  }

  .customers-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 22px;
    font-size: 15px;
    font-weight: 700;
  }

  .customers-add-btn .bi {
    font-size: 18px;
    line-height: 1;
  }

  .customers-table-wrap {
    overflow-x: auto;
    margin-top: 14px;
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.12);
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }

  .customers-card .table {
    margin: 0;
    width: 100%;
    color: #e5eefb;
    background: transparent;
  }

  .customers-card .table th {
    background: rgba(15, 23, 42, 0.96);
    color: #93a8c6;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 11px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  }

  .customers-card .table td,
  .customers-card .table th {
    border-color: rgba(148, 163, 184, 0.1);
    padding: 14px 16px;
    vertical-align: middle;
  }

  .customers-card .table tbody tr:nth-child(odd) {
    background: rgba(15, 23, 42, 0.48);
  }

  .customers-stack {
    display: grid;
    gap: 20px;
  }

  .customers-summary-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .customers-summary-card {
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.14);
    background: rgba(15, 23, 42, 0.68);
    padding: 18px;
  }

  .customers-summary-card span {
    display: block;
    color: #8ea3bf;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 8px;
  }

  .customers-summary-card strong {
    display: block;
    color: #f8fbff;
    font-size: clamp(24px, 3vw, 34px);
    line-height: 1;
  }

  .customers-layout {
    display: grid;
    gap: 20px;
    grid-template-columns: 1fr;
    align-items: start;
  }

  .customers-table-card {
    min-width: 0;
    min-height: 680px;
    max-height: 680px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .customers-side-panel {
    display: grid;
    gap: 18px;
  }

  .customers-side-panel > .customers-card {
    min-height: 680px;
    max-height: 680px;
    overflow: auto;
  }

  .customer-row-name strong {
    display: block;
    color: #f8fbff;
  }

  .customer-row-name span {
    display: block;
    color: #8ea3bf;
    font-size: 12px;
    margin-top: 4px;
  }

  .customers-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .customers-empty {
    text-align: center;
    padding: 34px 20px;
    color: #8ea3bf;
  }

  .customers-tenant-note {
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.14);
    background: rgba(15, 23, 42, 0.72);
    padding: 18px;
  }

  .customer-modal {
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, 0.74);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  .customer-modal-card {
    max-width: 900px;
    width: 95%;
    max-height: calc(100vh - 40px);
    overflow: auto;
  }

  @media (max-width: 1080px) {
    .customers-hero,
    .customers-layout {
      grid-template-columns: 1fr;
    }

    .customers-side-panel {
      position: static;
    }
  }

  @media (max-width: 760px) {
    .customers-card,
    .customer-modal-card {
      padding: 18px;
      border-radius: 18px;
    }

    .customers-summary-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="customers-shell">
<section class="customers-hero">
  <div class="customers-card">
    <div class="customers-header">
      <div>
        <span class="customers-kicker">Customer Workspace</span>
        <h2>Customers</h2>
      </div>
      <?php if (can_access('manage_customers')): ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <button class="btn btn-primary customers-add-btn" type="button" onclick="openAddCustomerModal()" <?= !$has_active_tenant_context ? 'disabled' : '' ?>><i class="bi bi-person-plus-fill"></i><span>Add Customer</span></button>
        </div>
      <?php endif; ?>
    </div>

    <div class="customers-summary-grid">
      <div class="customers-summary-card">
        <span>Total Customers</span>
        <strong><?= intval($customer_metrics['total_customers'] ?? 0) ?></strong>
      </div>
      <div class="customers-summary-card">
        <span>Active</span>
        <strong><?= intval($customer_metrics['active_customers'] ?? 0) ?></strong>
      </div>
      <div class="customers-summary-card">
        <span>Inactive</span>
        <strong><?= intval($customer_metrics['inactive_customers'] ?? 0) ?></strong>
      </div>
      <div class="customers-summary-card">
        <span>Added Today</span>
        <strong><?= intval($customer_metrics['customers_today'] ?? 0) ?></strong>
      </div>
    </div>
  </div>

</section>

<?php if ($err): ?><div class="alert red" style="margin-top:12px"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert green" style="margin-top:12px"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<section class="customers-layout">
  <div class="customers-card customers-table-card">
    <div class="customers-header" style="margin-bottom:0">
      <div>
        <h3 style="margin:0">Customer Directory</h3>
        <p style="margin-top:8px">Primary customer records stay on the left so the list remains the main focus.</p>
      </div>
    </div>

    <div class="customers-table-wrap">
      <table class="table">
        <thead><tr><th>Customer</th><th>Contact</th><th>Email</th><th>Status</th><th>Created</th><?php if (can_access('manage_customers')): ?><th>Action</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td>
                <div class="customer-row-name">
                  <strong><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></strong>
                  <span><?= htmlspecialchars($r['customer_no']) ?><?php if (!empty($r['username'])): ?> · @<?= htmlspecialchars($r['username']) ?><?php endif; ?></span>
                </div>
              </td>
              <td><?= htmlspecialchars($r['contact_no']) ?></td>
              <td><?= htmlspecialchars($r['email'] ?? 'No email') ?></td>
              <td><span class="badge <?= ($r['is_active'] ?? 1) ? 'green' : 'red' ?>"><?= ($r['is_active'] ?? 1) ? 'Active' : 'Inactive' ?></span></td>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <?php if (can_access('manage_customers')): ?>
              <td>
                <div class="customers-actions">
                  <a class="btn btn-primary" href="#" onclick="editCustomer(<?= intval($r['customer_id']) ?>, '<?= htmlspecialchars($r['first_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['last_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['contact_no'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['email'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['username'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['province'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['city'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['barangay'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['street'] ?? '', ENT_QUOTES) ?>'); return false;">Edit</a>
                  <form style="display:inline" method="post" onsubmit="return confirm('Permanently delete this customer? This cannot be undone.')">
                    <input type="hidden" name="permanent_delete_customer" value="<?= intval($r['customer_id']) ?>">
                    <button class="btn btn-primary" type="submit">Delete</button>
                  </form>
                </div>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($rows)): ?><tr><td colspan="<?= can_access('manage_customers') ? '6' : '5' ?>" class="customers-empty">No customers found in this tenant scope.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <aside class="customers-side-panel">
    <?php if (can_access('manage_customers') && $is_super_admin && !$has_active_tenant_context): ?>
      <div class="customers-tenant-note">
        <div class="small">Tenant Required</div>
        <h3 style="margin:8px 0 10px 0">Registration Disabled</h3>
        <div class="small" style="line-height:1.7">
          Select a tenant context before creating a new customer account.
          <a href="<?php echo APP_BASE; ?>/staff/select_tenant.php">Choose tenant</a>
        </div>
      </div>
    <?php endif; ?>

<div id="addCustomerModal" class="customer-modal" style="display:<?= isset($_POST['register_customer']) && $err ? 'flex' : 'none' ?>">
  <div class="customer-modal-card">
    <h3 style="margin-top:0">Add Customer</h3>

    <?php if ($is_super_admin && !$has_active_tenant_context): ?>
      <div class="alert red" style="margin-top:12px">
        Select a tenant context first before registering a customer.
        <a href="<?php echo APP_BASE; ?>/staff/select_tenant.php">Choose tenant</a>
      </div>
    <?php endif; ?>

    <form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>" style="margin-top:14px">
      <?php if (!$has_active_tenant_context): ?>
        <fieldset style="border:0;padding:0;margin:0" disabled>
      <?php endif; ?>
      <div class="grid2">
        <div>
          <label class="label">First Name</label>
          <input class="input" name="first_name" required value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['first_name'] ?? '') : '') ?>">
        </div>
        <div>
          <label class="label">Last Name</label>
          <input class="input" name="last_name" required value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['last_name'] ?? '') : '') ?>">
        </div>
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Contact No.</label>
          <input class="input" name="contact_no" required value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['contact_no'] ?? '') : '') ?>">
        </div>
        <div>
          <label class="label">Email</label>
          <input class="input" type="email" name="email" value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['email'] ?? '') : '') ?>">
        </div>
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Province</label>
          <input class="input" name="province" value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['province'] ?? '') : '') ?>">
        </div>
        <div>
          <label class="label">City</label>
          <input class="input" name="city" value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['city'] ?? '') : '') ?>">
        </div>
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Barangay</label>
          <input class="input" name="barangay" value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['barangay'] ?? '') : '') ?>">
        </div>
        <div>
          <label class="label">Street</label>
          <input class="input" name="street" value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['street'] ?? '') : '') ?>">
        </div>
      </div>

      <div style="margin-top:10px">
        <label class="label">Username</label>
        <input class="input" name="username" required value="<?= (isset($_POST['register_customer']) ? htmlspecialchars($_POST['username'] ?? '') : '') ?>">
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Password</label>
          <input class="input" id="pw" type="password" name="password" required>
          <div class="small" style="margin-top:6px">8+ chars, with uppercase, lowercase, number, special.</div>
        </div>
        <div>
          <label class="label">Confirm Password</label>
          <input class="input" id="pw2" type="password" name="confirm_password" required>
        </div>
      </div>

      <div style="margin-top:10px">
        <label class="small" style="display:flex;gap:8px;align-items:center">
          <input type="checkbox" onclick="togglePw()">
          Show password
        </label>
      </div>

      <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit" name="register_customer" value="1">Register</button>
        <button class="btn btn-outline" type="button" onclick="closeAddCustomerModal()">Cancel</button>
      </div>
      <?php if (!$has_active_tenant_context): ?>
        </fieldset>
      <?php endif; ?>
    </form>
  </div>
</div>

<div id="editModal" class="customer-modal" style="display:none">
  <div class="customer-modal-card">
    <h3 style="margin-top:0">Edit Customer Account</h3>
    <form method="post">
      <input type="hidden" id="edit_customer_id" name="customer_id">
      
      <div class="grid2">
        <div>
          <label class="label">First Name</label>
          <input class="input" id="edit_first_name" name="first_name" required>
        </div>
        <div>
          <label class="label">Last Name</label>
          <input class="input" id="edit_last_name" name="last_name" required>
        </div>
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Contact No.</label>
          <input class="input" id="edit_contact_no" name="contact_no" required>
        </div>
        <div>
          <label class="label">Username</label>
          <input class="input" id="edit_username" name="username" required>
        </div>
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Email</label>
          <input class="input" type="email" id="edit_email" name="email">
        </div>
        <div>
          <label class="label">Barangay</label>
          <input class="input" id="edit_barangay" name="barangay">
        </div>
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Province</label>
          <input class="input" id="edit_province" name="province">
        </div>
        <div>
          <label class="label">City</label>
          <input class="input" id="edit_city" name="city">
        </div>
      </div>

      <div class="grid2" style="margin-top:10px">
        <div>
          <label class="label">Street</label>
          <input class="input" id="edit_street" name="street">
        </div>
      </div>

      <div style="margin-top:14px;display:flex;gap:10px">
        <button class="btn btn-primary" type="submit" name="update_customer" value="1">Update</button>
        <button class="btn btn-outline" type="button" onclick="closeEdit()">Cancel</button>
      </div>
    </form>
  </div>
</div>

  </aside>

  <?php if (can_access('manage_customers')): ?>
    <script>
    function openAddCustomerModal() {
      const modal = document.getElementById('addCustomerModal');
      if (modal) modal.style.display = 'flex';
    }

    function closeAddCustomerModal() {
      const modal = document.getElementById('addCustomerModal');
      if (modal) modal.style.display = 'none';
    }

    function editCustomer(customerId, firstName, lastName, contact, email, username, province, city, barangay, street) {
      document.getElementById('edit_customer_id').value = customerId;
      document.getElementById('edit_first_name').value = firstName;
      document.getElementById('edit_last_name').value = lastName;
      document.getElementById('edit_contact_no').value = contact;
      document.getElementById('edit_email').value = email;
      document.getElementById('edit_username').value = username;
      document.getElementById('edit_province').value = province;
      document.getElementById('edit_city').value = city;
      document.getElementById('edit_barangay').value = barangay;
      document.getElementById('edit_street').value = street;
      document.getElementById('editModal').style.display = 'flex';
    }

    function closeEdit() {
      document.getElementById('editModal').style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('editModal').addEventListener('click', function(e) {
      if (e.target === this) closeEdit();
    });

    const addCustomerModal = document.getElementById('addCustomerModal');
    if (addCustomerModal) {
      addCustomerModal.addEventListener('click', function(e) {
        if (e.target === this) closeAddCustomerModal();
      });
    }

    function togglePw(){
      const a=document.getElementById('pw');
      const b=document.getElementById('pw2');
      const t=a.type==='password'?'text':'password';
      a.type=t; b.type=t;
    }
    </script>
  <?php endif; ?>
</section>
</div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
