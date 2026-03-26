<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$id = intval($_GET['id'] ?? 0);
enforce_tenant_resource_access('requirements', 'requirement_id', $id);
$r = fetch_one(q("SELECT * FROM requirements WHERE " . tenant_condition('tenant_id') . " AND requirement_id=?", tenant_types("i"), tenant_params([$id])));
if (!$r) { http_response_code(404); echo "Not found"; exit; }

// Basic access: customer cannot use this endpoint, staff only
require_roles(['SUPER_ADMIN','ADMIN','TENANT','MANAGER','CREDIT_INVESTIGATOR','LOAN_OFFICER','CASHIER']);

$path = __DIR__ . '/../' . $r['file_path'];
if (!file_exists($path)) { http_response_code(404); echo "File missing"; exit; }

$filename = basename($path);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
$map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','pdf'=>'application/pdf'];
if (isset($map[$ext])) $mime = $map[$ext];

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $filename . '"');
readfile($path);
exit;
?>
