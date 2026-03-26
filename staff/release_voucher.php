<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('manage_vouchers');

// FORCE MANILA TIME
date_default_timezone_set('Asia/Manila');
$today_manila = date('Y-m-d'); 

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { exit("Invalid Loan ID"); }
enforce_tenant_resource_access('loans', 'loan_id', $id);

// 1. Fetch Loan Details
$loan = fetch_one(q("SELECT l.*, c.first_name, c.last_name, c.street, c.barangay, c.city 
                      FROM loans l JOIN customers c ON c.customer_id = l.customer_id AND c.tenant_id=l.tenant_id
                      WHERE " . tenant_condition('l.tenant_id') . " AND l.loan_id=?", tenant_types("i"), tenant_params([$id])));
if (!$loan) { exit("Loan not found"); }

$real_client_name = strtoupper($loan['first_name'] . ' ' . $loan['last_name']);

// 2. Fetch existing voucher - Be strict about the ID
$existing = fetch_one(q("SELECT * FROM money_release_vouchers WHERE " . tenant_condition('tenant_id') . " AND loan_id=? AND status!='CANCELLED' LIMIT 1", tenant_types("i"), tenant_params([$id])));
$editMode = (isset($_GET['edit']) && $_GET['edit'] === '1') || !$existing;

/**
 * 3. CALCULATION LOGIC
 */
$principal = floatval($loan['principal_amount']); 
$term = strtolower($loan['payment_term']);
$rates = ['daily'=>0.0275, 'weekly'=>0.03, 'semi_monthly'=>0.035, 'monthly'=>0.04];
$active_rate = $rates[$term] ?? 0.03;

$service_fee    = $principal * $active_rate;
$notarial_alloc = ($principal > 5000) ? 150.00 : 50.00;
$risk_alloc     = $principal * 0.01;
$doc_stamps     = $principal * 0.005;

$current_allocations_default = [
    'CASH IN BANK-SB' => $principal, 
    'UNEARNED SERVICE FEE' => $service_fee,
    'UNEARNED NOTARIAL ALLOCATION' => $notarial_alloc,
    'RISK MANAGEMENT ALLOCATION' => $risk_alloc,
    'PAF COLLECTED' => 0.00,
    'DOCUMENTARY STAMPS ALLOC' => $doc_stamps
];

/**
 * 4. PERSISTENCE SYNC
 */
$current_allocations = [];
if ($existing && !empty($existing['voucher_data'])) {
    $decoded = json_decode($existing['voucher_data'], true);
    $saved_accounts = $decoded['accounts'] ?? [];
    if (!isset($saved_accounts['CASH IN BANK-SB']) || floatval($saved_accounts['CASH IN BANK-SB']) != $principal) {
        $current_allocations = $current_allocations_default;
    } else {
        $current_allocations = $saved_accounts;
    }
} else {
    $current_allocations = $current_allocations_default;
}

/**
 * 5. THE ULTIMATE DATE FIX
 * If it's a NEW application (no existing voucher) OR the voucher is just a DRAFT:
 * We IGNORE the database and force PHP's current date.
 */
if (!$existing || (isset($existing['status']) && $existing['status'] === 'DRAFT')) {
    $display_date = $today_manila; 
} else {
    // Only use the DB date if the voucher is actually completed/released
    $display_date = $existing['release_date'];
}

/**
 * 6. POST HANDLING
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_voucher'])) {
    $allocations = [];
    $final_release_amount = 0;
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'alloc_') === 0) {
            $account_name = urldecode(substr($k, 6));
            $val = floatval($v);
            $allocations[$account_name] = $val;
            if ($account_name === 'CASH IN BANK-SB') { $final_release_amount = $val; }
        }
    }
    
    $v_data = json_encode(['accounts' => $allocations]);
    $voucher_no_auto = str_replace('APP-', 'VCH-', $loan['reference_no']);
    $post_date = $_POST['release_date']; // This takes the date from the form
    $post_received = $_POST['received_by_name'];
    
    if ($existing) {
        q("UPDATE money_release_vouchers SET release_date=?, check_amount=?, received_by_name=?, voucher_data=?, status='DRAFT' WHERE tenant_id=? AND loan_id=?",
          "sdssii", [$post_date, $final_release_amount, $post_received, $v_data, $loan['tenant_id'], $id]);
    } else {
        q("INSERT INTO money_release_vouchers (tenant_id, loan_id, voucher_no, release_date, check_no, check_amount, prepared_by, received_by_name, voucher_data) VALUES (?,?,?,?,?,?,?,?,?)",
          "iisssdsss", [$loan['tenant_id'], $id, $voucher_no_auto, $post_date, 'MANUAL', $final_release_amount, $_SESSION['user_id'], $post_received, $v_data]);
    }
    header("Location: release_voucher.php?id=$id&ok=1"); exit;
}

include __DIR__ . '/_layout_top.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    .btn-brand-red { background-color: #B11226 !important; color: white !important; border: none !important; padding: 10px 24px; border-radius: 12px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; font-size: 14px; }
    .btn-brand-red:hover { opacity: 0.9; }
    .btn-outline-custom { border: 1px solid #ddd; padding: 10px 24px; border-radius: 12px; text-decoration: none; color: #333; font-weight: bold; }
    .input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }

    @media print {
        body * { visibility: hidden; }
        #voucher-print-area, #voucher-print-area * { visibility: visible; }
        #voucher-print-area { position: absolute; left: 0; top: 0; width: 100%; border: none !important; padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .btn-brand-red, .btn-outline-custom, form, h2 { display: none !important; }
    }
</style>

<div class="card">
    <div class="no-print" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h2 style="margin:0">Money Release Voucher</h2>
        <div style="display:flex;gap:10px;">
            <a class="btn-brand-red" href="release_queue.php">Back to Queue</a>
            <?php if(!$editMode): ?>
                <a class="btn-brand-red" href="?id=<?= $id ?>&edit=1">Edit Voucher</a>
                <button class="btn-brand-red" onclick="saveToPDF()">Save to PDF</button>
                <button class="btn-brand-red" onclick="window.print()">Print Voucher</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="voucher-print-area" style="background:#fff; border:1px solid #ddd; padding:40px; font-family:Arial, sans-serif; color:#000;">
        <div style="text-align:center; font-weight:bold; font-size:18px; text-transform:uppercase; margin-bottom:20px;">MONEY RELEASE VOUCHER</div>
        
        <div style="display:flex; justify-content:space-between; margin-bottom:20px; border-bottom:2px solid #000; padding-bottom:10px;">
            <div><strong>Voucher No:</strong> <?= htmlspecialchars($existing['voucher_no'] ?? str_replace('APP-', 'VCH-', $loan['reference_no'])) ?></div>
            <div><strong>Date:</strong> <span style="font-weight: bold;"><?= date('F d, Y', strtotime($display_date)) ?></span></div>
        </div>

        <div style="margin-bottom:20px;"><strong>PAY TO:</strong> <?= $real_client_name ?></div>
        
        <table style="width:100%; border-collapse:collapse; margin-top:20px;">
            <thead>
                <tr style="background:#f4f4f4;">
                    <th style="padding:10px; border:1px solid #ddd; text-align:left;">ACCOUNT TITLE / PARTICULARS</th>
                    <th style="padding:10px; border:1px solid #ddd; text-align:right;">AMOUNT</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($current_allocations as $acc => $amt): ?>
                    <tr>
                        <td style="padding:10px; border:1px solid #ddd;"><?= htmlspecialchars($acc) ?></td>
                        <td style="padding:10px; border:1px solid #ddd; text-align:right;">₱<?= number_format($amt, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#eee; font-weight:bold;">
                    <td style="padding:12px; border:1px solid #ddd; text-align:right;">TOTAL</td>
                    <td style="padding:12px; border:1px solid #ddd; text-align:right;">₱<?= number_format(array_sum($current_allocations), 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <div style="margin-top:60px; display:flex; justify-content:space-between;">
            <div style="width:40%; border-top:1px solid #000; text-align:center; padding-top:5px; font-size:12px; font-weight: bold;">
                Prepared By: <?= htmlspecialchars($existing['prepared_by_name'] ?? $_SESSION['full_name'] ?? 'Admin User') ?>
            </div>
            <div style="width:40%; border-top:1px solid #000; text-align:center; padding-top:5px; font-size:12px; font-weight: bold;">
                Received By: <?= $real_client_name ?>
            </div>
        </div>
    </div>

    <?php if($editMode): ?>
    <div class="no-print" style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;">
        <form method="post">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                <div>
                    <label>Release Date</label>
                    <input type="date" name="release_date" class="input" value="<?= $display_date ?>">
                </div>
                <div><label>Received By Name</label><input type="text" name="received_by_name" class="input" value="<?= $real_client_name ?>"></div>
            </div>
            
            <h4>Account Breakdown</h4>
            <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:15px;">
                <?php foreach($current_allocations as $name => $amt): ?>
                    <div>
                        <label style="font-size:11px; font-weight:bold;"><?= $name ?></label>
                        <input type="number" step="0.01" name="alloc_<?= urlencode($name) ?>" class="input" value="<?= $amt ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:20px; display:flex; gap:10px;">
                <button class="btn-brand-red" name="save_voucher" type="submit">Save Voucher</button>
                <a href="?id=<?= $id ?>" class="btn-outline-custom">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function saveToPDF() {
    const element = document.getElementById('voucher-print-area');
    const voucherNo = "<?= $existing['voucher_no'] ?? str_replace('APP-', 'VCH-', $loan['reference_no']) ?>";
    const opt = {
        margin: 0.5,
        filename: 'Voucher_' + voucherNo + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}
</script>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
