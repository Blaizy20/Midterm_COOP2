<?php
require_once __DIR__ . '/db.php';

function today_date() { return date('Y-m-d'); }

function generate_reference_no() {
  // APP-YYYYMMDD-#### sequence
  $prefix = 'APP-' . date('Ymd') . '-';
  $stmt = q("SELECT COUNT(*) AS c FROM loans WHERE reference_no LIKE CONCAT(?, '%')", "s", [$prefix]);
  $row = fetch_one($stmt);
  $n = ($row ? intval($row['c']) : 0) + 1;
  return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function generate_or_no() {
  $prefix = 'OR-' . date('Ymd') . '-';
  // Find the highest sequence number for today's OR numbers
  $stmt = q("SELECT MAX(CAST(SUBSTRING(or_no, LENGTH(?)+1) AS UNSIGNED)) AS max_seq FROM payments WHERE or_no LIKE CONCAT(?, '%')", "ss", [$prefix, $prefix]);
  $row = fetch_one($stmt);
  $max_seq = ($row && $row['max_seq']) ? intval($row['max_seq']) : 0;
  $n = $max_seq + 1;
  return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function generate_customer_no() {
  // CUST-YYYY-#### sequence
  $prefix = 'CUST-' . date('Y') . '-';
  // Find the highest sequence number for this year's customer numbers
  $stmt = q("SELECT MAX(CAST(SUBSTRING(customer_no, LENGTH(?)+1) AS UNSIGNED)) AS max_seq FROM customers WHERE customer_no LIKE CONCAT(?, '%')", "ss", [$prefix, $prefix]);
  $row = fetch_one($stmt);
  $max_seq = ($row && $row['max_seq']) ? intval($row['max_seq']) : 0;
  $n = $max_seq + 1;
  return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function compute_total_payable($principal, $interest_rate, $payment_term = null) {
  // Use payment term-based interest rates if payment_term is provided
  // Otherwise use the provided interest_rate
  $principal = floatval($principal);
  
  if ($payment_term) {
    // Always use payment term-based rates when payment_term is set
    $rate_map = [
      'daily' => 2.75,
      'weekly' => 3.0,
      'semi_monthly' => 3.50,
      'monthly' => 4.0
    ];
    $interest_rate = $rate_map[$payment_term] ?? 4.0;
  } else {
    // Use provided rate if no payment_term
    $interest_rate = floatval($interest_rate);
  }
  
  return round($principal + ($principal * ($interest_rate/100.0)), 2);
}

function recalc_loan($loan_id) {
    // 1. Fetch initial data and assign variables immediately
    $loan = fetch_one(q("SELECT loan_id, principal_amount, interest_rate, status, due_date, payment_term FROM loans WHERE loan_id = ?", "i", [$loan_id]));
    if (!$loan) return;

    $principal = floatval($loan['principal_amount']);
    $rate = floatval($loan['interest_rate']);
    $status = $loan['status'];
    $due = $loan['due_date'];
    $term = $loan['payment_term'];
    
    // 2. Fetch payment data to check for today's activity
    $sum = fetch_one(q("SELECT COALESCE(SUM(amount),0) AS paid, MAX(payment_date) as last_payment FROM payments WHERE loan_id = ?", "i", [$loan_id]));
    $paid = $sum ? floatval($sum['paid']) : 0.0;
    $last_payment = $sum['last_payment'] ?? null;

    // 3. Perform Calculations
    $total = compute_total_payable($principal, $rate, $term);
    $late_fee = 0.0;
    $is_past_due_date = ($due && strtotime($due) < strtotime(today_date()));
    
    // Calculate late fee based on principal to keep math fair
    if (($status === 'OVERDUE' || $is_past_due_date) && $due) {
        $late_fee = calculate_late_fee($loan_id, $term, $principal);
    }

    $total_with_late = $total + $late_fee;
    $remaining = round($total_with_late - $paid, 2);
    if ($remaining < 0) $remaining = 0.0;

    // 4. Update Totals in Database
    q("UPDATE loans SET total_payable = ?, remaining_balance = ? WHERE loan_id = ?", "ddi", [$total_with_late, $remaining, $loan_id]);

    // 5. AUTOMATIC DUE DATE ADVANCEMENT
    // Check if a payment was recorded today
    $made_payment_today = ($last_payment && date('Y-m-d', strtotime($last_payment)) === today_date());

    if ($made_payment_today && $remaining > 0) {
        // Map terms to PHP-friendly date intervals
        $intervals = [
            'daily' => '+1 day',
            'weekly' => '+7 days',
            'semi_monthly' => '+15 days',
            'monthly' => '+1 month'
        ];
        
        $interval = $intervals[$term] ?? '+1 month';
        
        // Calculate the new due date from the OLD due date to maintain the cycle
        $new_due_date = date('Y-m-d', strtotime($interval, strtotime($due)));
        
        // Push the new date and force status back to ACTIVE
        q("UPDATE loans SET due_date = ?, status = 'ACTIVE' WHERE loan_id = ?", "si", [$new_due_date, $loan_id]);
        
        // Update local $due variable so final checks are accurate
        $due = $new_due_date;
    }

    // 6. Final Status Integrity Check
    if ($remaining <= 0) {
        q("UPDATE loans SET status='CLOSED' WHERE loan_id = ?", "i", [$loan_id]);
    } else {
        // Re-verify if still overdue after potential date update
        $is_overdue = (strtotime($due) < strtotime(today_date()));
        q("UPDATE loans SET status = ? WHERE loan_id = ?", "si", [$is_overdue ? 'OVERDUE' : 'ACTIVE', $loan_id]);
    }
}

function calculate_late_fee($loan_id, $payment_term, $principal_amount = null) {
    $loan = fetch_one(q("SELECT due_date, principal_amount FROM loans WHERE loan_id = ?", "i", [$loan_id]));
    if (!$loan || !$loan['due_date']) return 0.0;
    
    $due_date = strtotime($loan['due_date']);
    $today = strtotime(today_date());
    
    // No fee if not overdue
    if ($today <= $due_date) return 0.0;
    
    $days_late = floor(($today - $due_date) / 86400); 
    if ($days_late <= 0) return 0.0;

    // Use principal for calculation to avoid "interest on interest"
    $base_amount = $principal_amount ?? floatval($loan['principal_amount']);
    
    // Define daily rates to allow for pro-rated (fair) charging
    $rates = [
        'daily'        => 0.005,        // 0.5% per day
        'weekly'       => 0.0075 / 7,   // 0.75% per week -> daily equivalent
        'semi_monthly' => 0.01 / 15,    // 1% per 15 days -> daily equivalent
        'monthly'      => 0.0125 / 30   // 1.25% per 30 days -> daily equivalent
    ];

    $daily_rate = $rates[$payment_term] ?? (0.0125 / 30);
    
    // Late fee = Principal * Daily Rate * Total Days Late
    return round($base_amount * $daily_rate * $days_late, 2);
}

function status_badge_class($status) {
  if (in_array($status, ['APPROVED','ACTIVE'], true)) return 'green';
  if (in_array($status, ['DENIED','OVERDUE'], true)) return 'red';
  return 'gray';
}

function tenant_status_options() {
  return [
    'PENDING' => 'Pending Approval',
    'ACTIVE' => 'Active',
    'INACTIVE' => 'Inactive',
    'SUSPENDED' => 'Suspended',
    'REJECTED' => 'Rejected',
  ];
}

function tenant_plan_options() {
  return [
    'BASIC' => 'Basic',
    'PROFESSIONAL' => 'Professional',
    'ENTERPRISE' => 'Enterprise',
  ];
}

function normalize_tenant_status($status, $default = 'PENDING') {
  $status = strtoupper(trim((string)$status));
  $options = tenant_status_options();
  return isset($options[$status]) ? $status : $default;
}

function normalize_tenant_plan($plan_code, $default = 'BASIC') {
  $plan_code = strtoupper(trim((string)$plan_code));
  $options = tenant_plan_options();
  return isset($options[$plan_code]) ? $plan_code : $default;
}

function tenant_status_badge_class($status) {
  $status = normalize_tenant_status($status);
  if ($status === 'ACTIVE') {
    return 'green';
  }
  if (in_array($status, ['REJECTED', 'SUSPENDED'], true)) {
    return 'red';
  }
  if ($status === 'PENDING') {
    return 'gray';
  }
  return 'gray';
}

function tenant_status_is_active($status) {
  return normalize_tenant_status($status) === 'ACTIVE' ? 1 : 0;
}

function log_activity_for_tenant($tenant_id, $action, $description, $loan_id = null, $customer_id = null, $reference_no = null) {
  $tenant_id = intval($tenant_id ?? 0);
  if ($tenant_id <= 0) {
    return;
  }

  $user_id = $_SESSION['user_id'] ?? 0;
  $user_role = $_SESSION['role'] ?? 'UNKNOWN';

  $conn = db();
  $result = $conn->query("SHOW TABLES LIKE 'activity_logs'");
  if (!$result || $result->num_rows === 0) {
    $create_result = $conn->query("CREATE TABLE IF NOT EXISTS activity_logs (
      log_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      user_id INT NULL,
      user_role VARCHAR(50) NOT NULL,
      action VARCHAR(100) NOT NULL,
      description LONGTEXT NOT NULL,
      loan_id INT NULL,
      customer_id INT NULL,
      reference_no VARCHAR(40) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_created_at (created_at),
      KEY idx_user_id (user_id),
      KEY idx_action (action),
      KEY idx_loan_id (loan_id),
      KEY idx_tenant_id (tenant_id),
      CONSTRAINT fk_activity_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    if (!$create_result && $conn->error) {
      error_log("Activity log table creation error: " . $conn->error);
    }
  }

  try {
    q(
      "INSERT INTO activity_logs (tenant_id, user_id, user_role, action, description, loan_id, customer_id, reference_no)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
      "iisssiis",
      [$tenant_id, $user_id, $user_role, $action, $description, $loan_id, $customer_id, $reference_no]
    );
  } catch (Exception $e) {
    error_log("Activity log insertion error: " . $e->getMessage());
  }
}

function log_tenant_activity($tenant_id, $action, $description, $reference_no = null) {
  log_activity_for_tenant($tenant_id, $action, $description, null, null, $reference_no);
}

function log_activity($action, $description, $loan_id = null, $customer_id = null, $reference_no = null) {
  $tenant_id = intval($_SESSION['tenant_id'] ?? 0);

  if ($tenant_id <= 0) {
    $tenant_id = intval(current_tenant_id() ?? 0);
  }

  if ($tenant_id <= 0) {
    return;
  }

  log_activity_for_tenant($tenant_id, $action, $description, $loan_id, $customer_id, $reference_no);
}

// OTP Functions for Payment Edit by Cashier

// Ensure payment OTP table exists
function ensure_payment_otp_table() {
  $conn = db();
  $result = $conn->query("SHOW TABLES LIKE 'payment_edit_otp'");
  if (!$result || $result->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS payment_edit_otp (
      otp_id INT AUTO_INCREMENT PRIMARY KEY,
      payment_id INT NOT NULL,
      user_id INT NOT NULL,
      otp_code VARCHAR(6) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      is_used TINYINT DEFAULT 0,
      verified_at DATETIME NULL,
      CONSTRAINT fk_payment_otp FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE,
      CONSTRAINT fk_user_otp FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
      KEY idx_payment_user (payment_id, user_id),
      KEY idx_expires (expires_at)
    ) ENGINE=InnoDB");
  }
}

function generate_otp_for_payment_edit($payment_id, $user_id) {
  // Ensure table exists
  ensure_payment_otp_table();
  
  // Delete any existing unused OTP for this payment
  q("DELETE FROM payment_edit_otp WHERE payment_id=? AND user_id=? AND is_used=0", "ii", [$payment_id, $user_id]);
  
  // Generate a 6-digit OTP
  $otp = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
  $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
  
  q("INSERT INTO payment_edit_otp (payment_id, user_id, otp_code, expires_at) VALUES (?,?,?,?)",
    "iiss", [$payment_id, $user_id, $otp, $expires]);
  
  return $otp;
}

function send_otp_notification($payment_id, $otp_code, $payment_or_no, $cashier_name) {
  // Send OTP to configured email address
  $otp_email = 'alliah1530@gmail.com';
  
  error_log("[OTP] send_otp_notification called - Payment ID: $payment_id, OTP: $otp_code, Sending to: $otp_email");
  
  $recipients = [
    [
      'email' => $otp_email,
      'full_name' => 'Admin'
    ]
  ];
  
  $subject = "Payment Edit OTP Request: " . $otp_code;
  
  $body = "PAYMENT EDIT OTP VERIFICATION\n";
  $body .= "================================\n\n";
  $body .= "A cashier has requested to edit a payment record.\n\n";
  $body .= "Details:\n";
  $body .= "- Cashier Name: " . htmlspecialchars($cashier_name) . "\n";
  $body .= "- Payment OR Number: " . htmlspecialchars($payment_or_no) . "\n";
  $body .= "- Payment ID: " . $payment_id . "\n\n";
  $body .= "OTP Code: " . $otp_code . "\n";
  $body .= "Valid for: 15 minutes\n\n";
  $body .= "Please provide this OTP code to the cashier after verification.\n\n";
  $body .= "If you did not initiate this request, please ignore this email.\n";
  
  error_log("[OTP] Sending OTP notification to: $otp_email");
  
  // Send via PHP mail() function
  return send_via_gmail_otp($recipients, $subject, $body, true);
}

function send_via_gmail_otp($recipients, $subject, $body, $is_plain_text = false) {
  // Use Gmail SMTP with TLS
  $gmail_user = 'alliah1530@gmail.com';
  $gmail_pass = 'mjnz fexk mofy cgxw'; // App password
  $smtp_host = 'smtp.gmail.com';
  $smtp_port = 587;
  
  $sent_count = 0;
  
  foreach ($recipients as $recipient) {
    // Validate email format
    if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
      error_log("[OTP] Skipping invalid email: {$recipient['email']}");
      continue;
    }
    
    try {
      error_log("[OTP] Attempting to send to {$recipient['email']} via Gmail SMTP");
      
      // Connect to SMTP server with TLS
      $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 10);
      
      if (!$socket) {
        $error_msg = "Failed to connect to $smtp_host:$smtp_port: $errstr ($errno)";
        error_log("[OTP] $error_msg");
        continue;
      }
      
      stream_set_timeout($socket, 10);
      
      // Read greeting
      $response = @fgets($socket, 1024);
      error_log("[OTP] SMTP greeting: " . trim($response));
      
      if (!$response || strpos($response, '220') === false) {
        @fclose($socket);
        error_log("[OTP] No SMTP greeting received");
        continue;
      }
      
      // Send EHLO
      @fputs($socket, "EHLO localhost\r\n");
      $response = @fgets($socket, 1024);
      error_log("[OTP] EHLO response: " . trim($response));
      
      // Keep reading EHLO response lines
      while ($response && strpos($response, '250-') === 0) {
        $response = @fgets($socket, 1024);
      }
      
      // Enable TLS
      @fputs($socket, "STARTTLS\r\n");
      $response = @fgets($socket, 1024);
      error_log("[OTP] STARTTLS response: " . trim($response));
      
      if (strpos($response, '220') === false) {
        error_log("[OTP] STARTTLS not available");
        @fclose($socket);
        continue;
      }
      
      // Enable crypto
      stream_context_set_option($socket, 'ssl', 'verify_peer', false);
      stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);
      stream_context_set_option($socket, 'ssl', 'allow_self_signed', true);
      
      if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        error_log("[OTP] Failed to enable TLS");
        @fclose($socket);
        continue;
      }
      
      error_log("[OTP] TLS enabled");
      
      // Send EHLO again after TLS
      @fputs($socket, "EHLO localhost\r\n");
      $response = @fgets($socket, 1024);
      
      // Keep reading EHLO response lines
      while ($response && strpos($response, '250-') === 0) {
        $response = @fgets($socket, 1024);
      }
      
      // Authenticate
      @fputs($socket, "AUTH LOGIN\r\n");
      @fgets($socket, 1024);
      
      @fputs($socket, base64_encode($gmail_user) . "\r\n");
      @fgets($socket, 1024);
      
      @fputs($socket, base64_encode($gmail_pass) . "\r\n");
      $response = @fgets($socket, 1024);
      error_log("[OTP] AUTH response: " . trim($response));
      
      if (!$response || strpos($response, '235') === false) {
        error_log("[OTP] Authentication failed: " . trim($response));
        @fclose($socket);
        continue;
      }
      
      // Send MAIL FROM
      @fputs($socket, "MAIL FROM: <" . $gmail_user . ">\r\n");
      $response = @fgets($socket, 1024);
      error_log("[OTP] MAIL FROM response: " . trim($response));
      
      // Send RCPT TO
      @fputs($socket, "RCPT TO: <" . $recipient['email'] . ">\r\n");
      $response = @fgets($socket, 1024);
      error_log("[OTP] RCPT TO response: " . trim($response));
      
      if (!$response || strpos($response, '250') === false) {
        error_log("[OTP] Recipient rejected: " . trim($response));
        @fclose($socket);
        continue;
      }
      
      // Send DATA command
      @fputs($socket, "DATA\r\n");
      $response = @fgets($socket, 1024);
      error_log("[OTP] DATA response: " . trim($response));
      
      // Build complete email message
      $message = "From: CredenceLend <" . $gmail_user . ">\r\n";
      $message .= "To: " . $recipient['email'] . "\r\n";
      $message .= "Subject: " . $subject . "\r\n";
      
      if ($is_plain_text) {
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
      } else {
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
      }
      
      $message .= "\r\n";
      $message .= $body . "\r\n";
      
      // Send message
      @fputs($socket, $message);
      @fputs($socket, ".\r\n");
      
      $response = @fgets($socket, 1024);
      error_log("[OTP] Message status: " . trim($response));
      
      // Send QUIT
      @fputs($socket, "QUIT\r\n");
      @fclose($socket);
      
      $sent_count++;
      error_log("[OTP] Successfully sent to {$recipient['email']}");
    } catch (Exception $e) {
      error_log("[OTP] Exception for {$recipient['email']}: " . $e->getMessage());
    }
  }
  
  error_log("[OTP] Final: Sent to $sent_count out of " . count($recipients) . " recipient(s)");
  return $sent_count > 0;
}

function verify_otp_for_payment($payment_id, $user_id, $otp_code) {
  // Ensure table exists
  ensure_payment_otp_table();
  
  $otp = fetch_one(q("SELECT otp_id, expires_at, is_used FROM payment_edit_otp 
                       WHERE payment_id=? AND user_id=? AND otp_code=? AND is_used=0", 
                     "iis", [$payment_id, $user_id, $otp_code]));
  
  if (!$otp) {
    return ['success' => false, 'message' => 'Invalid OTP'];
  }
  
  // Mark OTP as used and return success
  q("UPDATE payment_edit_otp SET is_used=1 WHERE otp_id=?", "i", [$otp['otp_id']]);
  return ['success' => true, 'message' => 'OTP verified'];
}

function is_payment_edit_verified($payment_id) {
  // Check if OTP has been verified for this payment
  // by checking if there's a used OTP in the current session
  if (isset($_SESSION['verified_payment_edits'][$payment_id]) && $_SESSION['verified_payment_edits'][$payment_id]) {
    return true;
  }
  return false;
}

// Loan Eligibility Check
function check_customer_loan_eligibility($customer_id) {
  // Check if customer has any unpaid loans
  // Unpaid loans = PENDING, CI_REVIEWED, APPROVED, ACTIVE, OVERDUE
  $unpaid_loans = fetch_all(q(
    "SELECT loan_id, reference_no, status, remaining_balance 
     FROM loans 
     WHERE customer_id=? AND status IN ('PENDING','CI_REVIEWED','APPROVED','ACTIVE','OVERDUE')",
    "i", [$customer_id]
  ));
  
  if (!empty($unpaid_loans)) {
    $loan_list = '';
    foreach ($unpaid_loans as $loan) {
      $loan_list .= "\n  - " . htmlspecialchars($loan['reference_no']) . 
                    " (Status: " . htmlspecialchars($loan['status']) . 
                    ", Remaining: ₱" . number_format($loan['remaining_balance'], 2) . ")";
    }
    
    return [
      'eligible' => false,
      'message' => 'You cannot apply for a new loan while you have unpaid loans. Please settle your existing loans first.' . $loan_list,
      'unpaid_loans' => $unpaid_loans
    ];
  }
  
  return [
    'eligible' => true,
    'message' => 'You are eligible to apply for a loan.'
  ];
}
?>
