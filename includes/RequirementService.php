<?php
/**
 * Requirement Service
 *
 * Handles document/requirement uploads and management.
 * Tenant context is required for tenant-owned operations.
 */

require_once __DIR__ . '/pdo_db.php';

class RequirementService {
    private $db;
    private $tenant_id;
    private $uploadDir = __DIR__ . '/../uploads/requirements';

    public function __construct($tenant_id = null) {
        $this->db = Database::getInstance();
        $this->tenant_id = $tenant_id ?? ($_SESSION['tenant_id'] ?? null);

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function setTenantId($tenant_id) {
        $this->tenant_id = $tenant_id;
    }

    private function requireTenantId() {
        if (!$this->tenant_id) {
            throw new Exception("Tenant context is required");
        }
        return $this->tenant_id;
    }

    public function getRequirementById($requirementId) {
        try {
            $sql = "SELECT r.*, u.full_name as uploaded_by_name
                    FROM requirements r
                    LEFT JOIN users u ON u.user_id = r.uploaded_by_user
                    WHERE r.requirement_id = ? AND r.tenant_id = ?";

            return $this->db->queryOne($sql, [$requirementId, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get requirement error: " . $e->getMessage());
            return null;
        }
    }

    public function getLoanRequirements($loanId) {
        try {
            $sql = "SELECT r.*, u.full_name as uploaded_by_name
                    FROM requirements r
                    LEFT JOIN users u ON u.user_id = r.uploaded_by_user
                    WHERE r.loan_id = ? AND r.tenant_id = ?
                    ORDER BY r.uploaded_at DESC";

            return $this->db->queryAll($sql, [$loanId, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get loan requirements error: " . $e->getMessage());
            return [];
        }
    }

    public function getRequirementsByCode($loanId, $requirementCode) {
        try {
            $sql = "SELECT r.*, u.full_name as uploaded_by_name
                    FROM requirements r
                    LEFT JOIN users u ON u.user_id = r.uploaded_by_user
                    WHERE r.loan_id = ? AND r.requirement_code = ? AND r.tenant_id = ?
                    ORDER BY r.uploaded_at DESC";

            return $this->db->queryAll($sql, [$loanId, $requirementCode, $this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get requirements by code error: " . $e->getMessage());
            return [];
        }
    }

    public function addRequirement($loanId, $requirementCode, $requirementName, $filePath, $uploadedByRole = 'STAFF', $uploadedByUser = null, $notes = null) {
        try {
            $tenant_id = $this->requireTenantId();
            $sql = "SELECT loan_id FROM loans WHERE loan_id = ? AND tenant_id = ?";
            $loan = $this->db->queryOne($sql, [$loanId, $tenant_id]);
            if (!$loan) {
                throw new Exception("Loan not found");
            }

            $validRoles = ['CUSTOMER', 'STAFF'];
            if (!in_array($uploadedByRole, $validRoles, true)) {
                throw new Exception("Invalid upload role");
            }

            $sql = "INSERT INTO requirements
                    (tenant_id, loan_id, requirement_code, requirement_name, file_path,
                     uploaded_by_role, uploaded_by_user, uploaded_at, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)";

            $this->db->exec($sql, [
                $tenant_id,
                $loanId,
                $requirementCode,
                $requirementName,
                $filePath,
                $uploadedByRole,
                $uploadedByUser,
                $notes
            ]);

            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("Add requirement error: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateRequirement($requirementId, $data) {
        try {
            $updates = [];
            $params = [];
            $allowed = ['requirement_name', 'notes'];

            foreach ($allowed as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($updates)) {
                return true;
            }

            $params[] = $requirementId;
            $params[] = $this->requireTenantId();
            $sql = "UPDATE requirements SET " . implode(", ", $updates) . " WHERE requirement_id = ? AND tenant_id = ?";
            $this->db->exec($sql, $params);

            return true;
        } catch (Exception $e) {
            error_log("Update requirement error: " . $e->getMessage());
            throw $e;
        }
    }

    public function deleteRequirement($requirementId) {
        try {
            $requirement = $this->getRequirementById($requirementId);
            if (!$requirement) {
                throw new Exception("Requirement not found");
            }

            $filePath = $this->uploadDir . '/' . basename($requirement['file_path']);
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $sql = "DELETE FROM requirements WHERE requirement_id = ? AND tenant_id = ?";
            $this->db->exec($sql, [$requirementId, $this->requireTenantId()]);

            return true;
        } catch (Exception $e) {
            error_log("Delete requirement error: " . $e->getMessage());
            throw $e;
        }
    }

    public function countLoanRequirements($loanId) {
        try {
            $sql = "SELECT COUNT(*) as count FROM requirements WHERE loan_id = ? AND tenant_id = ?";
            $result = $this->db->queryOne($sql, [$loanId, $this->requireTenantId()]);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            error_log("Count requirements error: " . $e->getMessage());
            return 0;
        }
    }

    public function getUploadStats() {
        try {
            $sql = "SELECT uploaded_by_role, COUNT(*) as count
                    FROM requirements
                    WHERE tenant_id = ?
                    GROUP BY uploaded_by_role";

            return $this->db->queryAll($sql, [$this->requireTenantId()]);
        } catch (Exception $e) {
            error_log("Get upload stats error: " . $e->getMessage());
            return [];
        }
    }

    public function getUploadPath($filename) {
        $safeName = basename($filename);
        return $this->uploadDir . '/' . $safeName;
    }

    public function getUploadUrl($filename) {
        $safeName = basename($filename);
        return '/uploads/requirements/' . urlencode($safeName);
    }
}

?>
