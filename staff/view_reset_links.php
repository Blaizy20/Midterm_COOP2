<?php
require_once __DIR__ . '/../includes/auth.php';

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'STAFF') {
  header('Location: ' . APP_BASE . '/staff/login.php');
  exit;
}

$resetLinksFile = __DIR__ . '/../logs/reset_links.txt';
$content = '';

if (file_exists($resetLinksFile)) {
  $content = file_get_contents($resetLinksFile);
}
?>
<!doctype html>
<html><head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Password Reset Links</title>
  <link rel="stylesheet" href="<?php echo APP_BASE; ?>/assets/css/theme.css">
  <style>
    .reset-entry { 
      border: 1px solid #ddd; 
      padding: 12px; 
      margin: 10px 0; 
      background: #f9f9f9;
      border-radius: 4px;
      font-family: monospace;
      font-size: 12px;
      white-space: pre-wrap;
      word-break: break-all;
      max-height: 300px;
      overflow-y: auto;
    }
  </style>
</head>
<body>
<div class="center-wrap" style="max-width: 800px">
  <div class="card">
    <h2>Password Reset Links Log</h2>
    <p class="small">This page shows all generated password reset links. Share the full reset link with the user.</p>
    
    <?php if (!$content): ?>
      <div class="alert ok">No password reset links generated yet.</div>
    <?php else: ?>
      <div class="reset-entry"><?= htmlspecialchars($content) ?></div>
      <div style="margin-top: 10px; text-align: right;">
        <small>Last updated: <?php echo date('Y-m-d H:i:s', filemtime($resetLinksFile)); ?></small>
      </div>
    <?php endif; ?>
    
    <div style="margin-top: 20px">
      <a class="btn btn-primary" href="<?php echo APP_BASE; ?>/staff/dashboard.php" style="text-decoration: none">Back to Dashboard</a>
    </div>
  </div>
</div>
</body></html>
