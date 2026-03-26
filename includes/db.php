<?php
// Database connection (MariaDB/MySQL)
// Adjust if your XAMPP/WAMP uses a different host/user/pass.
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'loan_management';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Return a singleton mysqli connection (with DB selected).
 * NOTE: setup/setup_db.php will reset tables; this function only ensures the DB exists.
 */
function db() {
  global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
  static $conn = null;

  if ($conn === null) {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    $conn->set_charset('utf8mb4');

    // Ensure DB exists, then select it
    $conn->query("CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db($DB_NAME);
  }
  return $conn;
}

function _bind_params($stmt, $types, $params) {
  if ($types === '' || $params === null || count($params) === 0) return;
  // bind_param requires references
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v) {
    $bind[] = &$params[$k];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);
}

/**
 * Prepared statement helper.
 * Example: q("SELECT * FROM users WHERE user_id = ?", "i", [$id]);
 */
function q($sql, $types = '', $params = []) {
  $conn = db();
  $stmt = $conn->prepare($sql);
  if ($stmt === false) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  _bind_params($stmt, $types, $params);
  $stmt->execute();
  return $stmt;
}

function fetch_all($stmt) {
  $res = $stmt->get_result();
  return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function fetch_one($stmt) {
  $res = $stmt->get_result();
  return $res ? $res->fetch_assoc() : null;
}
?>