<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
require_login();
require_permission('print_receipts');

$customer_id = intval($_GET['customer_id'] ?? 0);
enforce_tenant_resource_access('customers', 'customer_id', $customer_id);
$customer = fetch_one(q("SELECT * FROM customers WHERE " . tenant_condition('tenant_id') . " AND customer_id=?", tenant_types("i"), tenant_params([$customer_id])));
if (!$customer) { http_response_code(404); echo "Customer not found"; exit; }

// Get all payments for this customer
$payments = fetch_all(q("SELECT p.*, l.reference_no, l.principal_amount, l.interest_rate, u.full_name AS cashier_name
                        FROM payments p
                        JOIN loans l ON p.loan_id=l.loan_id AND l.tenant_id=p.tenant_id
                        LEFT JOIN users u ON u.user_id=p.received_by
                        WHERE " . tenant_condition('p.tenant_id') . " AND l.customer_id=?
                        ORDER BY p.payment_date DESC", tenant_types("i"), tenant_params([$customer_id])));

$total_paid = 0;
foreach ($payments as $p) {
  $total_paid += $p['amount'];
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Summary - <?= htmlspecialchars($customer['customer_no']) ?></title>
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
      max-width: 900px;
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
      margin-bottom: 20px;
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
      padding: 8px 0;
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
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 15px;
    }
    table th, table td {
      padding: 10px;
      text-align: left;
      border-bottom: 1px solid #eee;
      font-size: 13px;
    }
    table th {
      background: #f5f5f5;
      font-weight: 700;
      color: #333;
    }
    table tr:last-child td {
      border-bottom: none;
    }
    .amount {
      text-align: right;
    }
    .total-row {
      background: #f9f9f9;
      font-weight: 700;
      font-size: 14px;
    }
    .total-row td {
      padding: 12px 10px;
    }
    .footer {
      text-align: center;
      margin-top: 30px;
      padding-top: 20px;
      border-top: 2px solid #2c3ec5;
      color: #666;
      font-size: 12px;
    }
    .timestamp {
      margin-top: 15px;
      font-size: 12px;
      color: #999;
      text-align: center;
    }
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
        padding: 20px;
        page-break-after: avoid;
      }
      .print-button {
        display: none;
      }
      .section {
        page-break-inside: avoid;
      }
      table {
        page-break-inside: avoid;
      }
    }
    .print-button {
      text-align: center;
      margin-top: 20px;
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
</head>
<body>
<div class="receipt-container">
  <div class="header">
    <h1>CredenceLend</h1>
    <div class="subtitle">Payment Summary</div>
  </div>

  <div class="receipt-title">PAYMENT SUMMARY BY CLIENT</div>

  <div class="section">
    <div class="section-title">Customer Information</div>
    <div class="row">
      <span class="label">Customer Name:</span>
      <span class="value"><?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?></span>
    </div>
    <div class="row">
      <span class="label">Customer No:</span>
      <span class="value"><?= htmlspecialchars($customer['customer_no']) ?></span>
    </div>
    <div class="row">
      <span class="label">Contact No:</span>
      <span class="value"><?= htmlspecialchars($customer['contact_no'] ?? 'N/A') ?></span>
    </div>
    <div class="row last">
      <span class="label">Email:</span>
      <span class="value"><?= htmlspecialchars($customer['email'] ?? 'N/A') ?></span>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Payment History</div>
    <?php if (!empty($payments)): ?>
      <table>
        <thead>
          <tr>
            <th>OR No</th>
            <th>Loan Ref</th>
            <th>Date</th>
            <th>Method</th>
            <th>Received By</th>
            <th class="amount">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['or_no']) ?></td>
              <td><?= htmlspecialchars($p['reference_no']) ?></td>
              <td><?= htmlspecialchars($p['payment_date']) ?></td>
              <td><?= htmlspecialchars($p['method']) ?></td>
              <td><?= htmlspecialchars($p['cashier_name'] ?? 'System') ?></td>
              <td class="amount">₱ <?= number_format($p['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="total-row">
            <td colspan="5" style="text-align:right">TOTAL PAYMENTS:</td>
            <td class="amount">₱ <?= number_format($total_paid, 2) ?></td>
          </tr>
        </tbody>
      </table>
    <?php else: ?>
      <div style="padding: 20px; text-align: center; color: #666;">
        No payments recorded for this customer.
      </div>
    <?php endif; ?>
  </div>

  <div class="footer">
    <div>This is an official payment summary document.</div>
    <div style="margin-top: 10px;">Total Number of Payments: <?= count($payments) ?></div>
  </div>

  <div class="timestamp">
    Generated: <?= date('F d, Y - g:i A') ?>
  </div>

  <div class="print-button">
    <button class="btn" onclick="window.print()">Print Summary</button>
    <button class="btn" onclick="downloadPDF()">Save as PDF</button>
    <a href="<?php echo APP_BASE; ?>/staff/reports.php" class="btn secondary">Back to Reports</a>
  </div>

  <script>
  function downloadPDF() {
    const element = document.querySelector('.receipt-container');
    const opt = {
      margin: [5, 5, 5, 5],
      filename: 'payment_summary_<?= htmlspecialchars($customer['customer_no']) ?>.pdf',
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, allowTaint: true, useCORS: true },
      jsPDF: { orientation: 'portrait', unit: 'mm', format: 'a4' },
      pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
    };
    
    // Load html2pdf library
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
    script.onload = function() {
      html2pdf().set(opt).from(element).save();
    };
    document.head.appendChild(script);
  }
  </script>
