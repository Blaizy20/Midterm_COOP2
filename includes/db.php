<?php
// Use Railway env vars in production, fallback to local XAMPP values
$DB_HOST = getenv('MYSQLHOST')     ?: 'localhost';
$DB_USER = getenv('MYSQLUSER')     ?: 'root';
$DB_PASS = getenv('MYSQLPASSWORD') ?: '';
$DB_NAME = getenv('MYSQLDATABASE') ?: 'loan_management';
$DB_PORT = (int)(getenv('MYSQLPORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Return a singleton mysqli connection (with DB selected).
 */
function db() {
  global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT;
  static $conn = null;

  if ($conn === null) {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $conn->set_charset('utf8mb4');
  }
  return $conn;
}

function _bind_params($stmt, $types, $params) {
  if ($types === '' || $params === null || count($params) === 0) return;
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
