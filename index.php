<?php
/**
 * Loan Management System - Unified Database
 *
 * Web Portal: Staff access at /staff/
 * Mobile API: Client access at /api/v1/
 * Database: One shared 'loan_management' database
 *
 * Both platforms query the same database with tenant isolation.
 */

// Build the redirect from the current script path so it works whether the
// project is served from localhost root or from a subfolder in htdocs.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$loginPath = ($basePath === '' || $basePath === '.') ? '/staff/login.php' : $basePath . '/staff/login.php';

header('Location: ' . $loginPath);
exit;
