<?php
require_once __DIR__ . '/../includes/auth.php';
logout_user();
header("Location: " . APP_BASE . "/staff/login.php");
exit;
?>