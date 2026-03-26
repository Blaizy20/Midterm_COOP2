<?php
// Use Railway env vars in production, fallback to local XAMPP values
$DB_HOST = getenv('MYSQLHOST')     ?: 'localhost';
$DB_USER = getenv('MYSQLUSER')     ?: 'root';
$DB_PASS = getenv('MYSQLPASSWORD') ?: '';
$DB_NAME = getenv('MYSQLDATABASE') ?: 'loan_management';
$DB_PORT = (int)(getenv('MYSQLPORT') ?: 3306);

// DEBUG: show what values are being used (remove after fixing)
error_log("DB DEBUG => Host: $DB_HOST | Port: $DB_PORT | User: $DB_USER | DB: $DB_NAME");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db() {
  global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT;
  static $conn = null;

  if ($conn === null) {
    try {
      $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
      $conn->set_charset('utf8mb4');
    } catch (Exception $e) {
      $msg  = "DB Connection failed: " . $e->getMessage();
      $msg .= " | Host: $DB_HOST | Port: $DB_PORT | User: $DB_USER | DB: $DB_NAME";
      error_log($msg);
      die("<pre>$msg</pre>");
    }
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
