<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('print_receipts');

$payment_id = intval($_GET['id'] ?? 0);
enforce_tenant_resource_access('payments', 'payment_id', $payment_id);
$payment = fetch_one(q("SELECT p.*, l.reference_no, l.principal_amount, l.interest_rate, l.total_payable, l.remaining_balance,
                               c.customer_no, CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.contact_no,
                               u.full_name AS cashier_name
                        FROM payments p
                        JOIN loans l ON l.loan_id=p.loan_id AND l.tenant_id=p.tenant_id
                        JOIN customers c ON c.customer_id=l.customer_id AND c.tenant_id=l.tenant_id
                        LEFT JOIN users u ON u.user_id=p.received_by
                        WHERE " . tenant_condition('p.tenant_id') . " AND p.payment_id=?", tenant_types("i"), tenant_params([$payment_id])));
if (!$payment) { http_response_code(404); echo "Payment not found"; exit; }

// Recalculate to get current balance
recalc_loan($payment['loan_id']);
$loan = fetch_one(q("SELECT remaining_balance FROM loans WHERE " . tenant_condition('tenant_id') . " AND loan_id=?", tenant_types("i"), tenant_params([$payment['loan_id']])));
$payment['remaining_balance'] = $loan['remaining_balance'];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Receipt - <?= htmlspecialchars($payment['or_no']) ?></title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #f0f0f0;
      padding: 20px;
    }
    .receipt-container {
      max-width: 600px;
      margin: 0 auto;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .header {
      text-align: center;
      border-bottom: 2px solid #2c3ec5;
      padding-bottom: 20px;
      margin-bottom: 20px;
    }
    .header h1 {
      color: #2c3ec5;
      margin-bottom: 5px;
      font-size: 24px;
    }
    .header .subtitle {
      color: #666;
      font-size: 14px;
    }
    .receipt-title {
      text-align: center;
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 20px;
    }
    .section {
      margin-bottom: 15px; 
    }
    .section-title {
      font-weight: 700;
      color: #333;
      margin-bottom: 10px;
      border-bottom: 1px solid #ddd;
      padding-bottom: 5px;
    }
    .row {
      display: flex;
      justify-content: space-between;
      padding: 6px 0;
      font-size: 14px;
      border-bottom: 1px solid #eee;
    }
    .row.last {
      border-bottom: none;
    }
    .row .label {
      font-weight: 500;
      color: #666;
    }
    .row .value {
      text-align: right;
      font-weight: 600;
      color: #333;
    }
    .row.highlight {
      background: #f9f9f9;
      padding: 10px 8px;
      margin: 0 -8px;
      border-top: 2px solid #2c3ec5;
      border-bottom: 2px solid #2c3ec5;
    }
    .row.highlight .value {
      color: #2c3ec5;
      font-size: 16px;
    }
    .footer {
      text-align: center;
      margin-top: 20px;
      padding-top: 20px;
      border-top: 2px solid #2c3ec5;
      color: #666;
      font-size: 12px;
    }
    .timestamp {
      margin-top: 15px;
      font-size: 11px;
      color: #999;
      text-align: center;
    }
    /* Print specific styles to ensure browser print works too */
    @media print {
      body {
        background: white;
        padding: 0;
        margin: 0;
      }
      .receipt-container {
        box-shadow: none;
        border-radius: 0;
        max-width: 100%;
        margin: 0;
        padding: 10px;
      }
      .print-button {
        display: none;
      }
    }
    .print-button {
      max-width: 600px;
      margin: 20px auto;
      text-align: center;
    }
    .btn {
      display: inline-block;
      padding: 10px 20px;
      background: #2c3ec5;
      color: white;
      border: none;
      border-radius: 5px;
      cursor: pointer;
      font-weight: 600;
      text-decoration: none;
    }
    .btn:hover {
      background: #1e2ba8;
    }
    .btn.secondary {
      background: #6B7280;
      margin-left: 10px;
    }
    .btn.secondary:hover {
      background: #5a6470;
    }
  </style>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>

<div class="receipt-container" id="receipt-content">
  <div class="header">
    <h1>CredenceLend</h1>
    <div class="subtitle">Official Payment Receipt</div>
  </div>

  <div class="receipt-title">PAYMENT RECEIPT</div>

  <div class="section">
    <div class="section-title">Receipt Information</div>
    <div class="row">
      <span class="label">Receipt No (OR):</span>
      <span class="value"><?= htmlspecialchars($payment['or_no']) ?></span>
    </div>
    <div class="row">
      <span class="label">Receipt Date:</span>
      <span class="value"><?= htmlspecialchars($payment['payment_date']) ?></span>
    </div>
    <div class="row last">
      <span class="label">Received By:</span>
      <span class="value"><?= htmlspecialchars($payment['cashier_name'] ?? 'System') ?></span>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Customer Information</div>
    <div class="row">
      <span class="label">Customer Name:</span>
      <span class="value"><?= htmlspecialchars($payment['customer_name']) ?></span>
    </div>
    <div class="row">
      <span class="label">Customer No:</span>
      <span class="value"><?= htmlspecialchars($payment['customer_no']) ?></span>
    </div>
    <div class="row last">
      <span class="label">Contact No:</span>
      <span class="value"><?= htmlspecialchars($payment['contact_no'] ?? 'N/A') ?></span>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Loan Information</div>
    <div class="row">
      <span class="label">Reference No:</span>
      <span class="value"><?= htmlspecialchars($payment['reference_no']) ?></span>
    </div>
    <div class="row">
      <span class="label">Principal Amount:</span>
      <span class="value">₱ <?= number_format($payment['principal_amount'], 2) ?></span>
    </div>
    <div class="row last">
      <span class="label">Interest Rate:</span>
      <span class="value"><?= htmlspecialchars($payment['interest_rate']) ?>%</span>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Payment Details</div>
    <div class="row">
      <span class="label">Payment Method:</span>
      <span class="value"><?= htmlspecialchars($payment['method']) ?></span>
    </div>
    <?php if ($payment['method'] === 'CHEQUE'): ?>
      <div class="row">
        <span class="label">Cheque Number:</span>
        <span class="value"><?= htmlspecialchars($payment['cheque_number'] ?? '—') ?></span>
      </div>
      <div class="row">
        <span class="label">Cheque Date:</span>
        <span class="value"><?= htmlspecialchars($payment['cheque_date'] ?? '—') ?></span>
      </div>
      <div class="row">
        <span class="label">Bank Name:</span>
        <span class="value"><?= htmlspecialchars($payment['bank_name'] ?? '—') ?></span>
      </div>
      <div class="row">
        <span class="label">Account Holder:</span>
        <span class="value"><?= htmlspecialchars($payment['account_holder'] ?? '—') ?></span>
      </div>
    <?php elseif ($payment['method'] === 'BANK'): ?>
      <div class="row">
        <span class="label">Bank Reference Number:</span>
        <span class="value"><?= htmlspecialchars($payment['bank_reference_no'] ?? '—') ?></span>
      </div>
    <?php elseif ($payment['method'] === 'GCASH'): ?>
      <div class="row">
        <span class="label">GCash Reference Number:</span>
        <span class="value"><?= htmlspecialchars($payment['gcash_reference_no'] ?? '—') ?></span>
      </div>
    <?php endif; ?>
    <div class="row highlight">
      <span class="label">Amount Paid:</span>
      <span class="value">₱ <?= number_format($payment['amount'], 2) ?></span>
    </div>
    <div class="row">
      <span class="label">Total Payable:</span>
      <span class="value">₱ <?= number_format($payment['total_payable'] ?? 0, 2) ?></span>
    </div>
    <div class="row last">
      <span class="label">Remaining Balance:</span>
      <span class="value">₱ <?= number_format($payment['remaining_balance'] ?? 0, 2) ?></span>
    </div>
  </div>

  <?php if ($payment['notes']): ?>
  <div class="section">
    <div class="section-title">Remarks</div>
    <div class="row last">
      <span style="color: #666; font-size: 14px;"><?= htmlspecialchars($payment['notes']) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="footer">
    <div>This is an officially issued payment receipt.</div>
    <div style="margin-top: 10px;">Thank you for your payment.</div>
  </div>

  <div class="timestamp">
    Generated: <?= date('F d, Y - g:i A') ?>
  </div>

</div>

<div class="print-button">
  <button class="btn" onclick="window.print()">Print Receipt</button>
  <button class="btn" onclick="downloadPDF()">Save as PDF</button>
  <a href="<?php echo APP_BASE; ?>/staff/loan_view.php?id=<?= intval($payment['loan_id']) ?>" class="btn secondary">Back to Loan</a>
</div>

<script>
  function downloadPDF() {
    const element = document.getElementById('receipt-content');
    
    const opt = {
      // 1. Set consistent margins
      margin:       [5, 5, 5, 5], 
      filename:     'receipt_<?= htmlspecialchars($payment['or_no']) ?>.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { 
        scale: 2, 
        useCORS: true, 
        scrollY: 0,
        // 2. CRITICAL FIX: The onclone function modifies the receipt just before capture
        //    to align it to the left (removing centering margins) so nothing is cut off.
        onclone: function(clonedDoc) {
            const el = clonedDoc.getElementById('receipt-content');
            el.style.margin = '0';
            el.style.maxWidth = '100%';
            el.style.boxShadow = 'none';
        }
      },
      jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak:    { mode: 'avoid-all' }
    };

    html2pdf().set(opt).from(element).save();
  }
</script>

</body>
</html>
