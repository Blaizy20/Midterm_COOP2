<?php
/**
 * PDO-Based Database Connection
 * 
 * This class provides a modern PDO-based database connection with prepared statements.
 * It replaces the mysqli approach for better security and flexibility for future
 * mobile app integration.
 * 
 * Usage:
 *   $db = Database::getInstance();
 *   $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
 *   $stmt->execute([$userId]);
 *   $user = $stmt->fetch(PDO::FETCH_ASSOC);
 */

class Database {
    private static $instance = null;
    private $pdo = null;
    
    private $db_host = 'localhost';
    private $db_user = 'root';
    private $db_pass = '';
    private $db_name = 'loan_management';
    private $db_port = 3306;
    
    private function __construct() {
        $this->connect();
    }
    
    /**
     * Get singleton instance of Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish PDO connection
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->db_host};port={$this->db_port};dbname={$this->db_name};charset=utf8mb4";
            
            $this->pdo = new PDO(
                $dsn,
                $this->db_user,
                $this->db_pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                ]
            );
            
            $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Unable to connect to database. Please contact support.");
        }
    }
    
    /**
     * Get raw PDO instance (use with caution, prefer prepare() method)
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    /**
     * Prepare a SQL statement
     * 
     * @param string $sql SQL query string
     * @return \PDOStatement|false Prepared statement or false on failure
     */
    public function prepare($sql) {
        try {
            return $this->pdo->prepare($sql);
        } catch (PDOException $e) {
            error_log("Prepare Error: " . $e->getMessage());
            throw new Exception("Database preparation error");
        }
    }
    
    /**
     * Execute a prepared statement
     */
    public function execute($stmt, $params = []) {
        try {
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Execution Error: " . $e->getMessage());
            throw new Exception("Database execution error");
        }
    }
    
    /**
     * Fetch single row as associative array
     */
    public function fetchOne($stmt) {
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Fetch all rows as associative array
     */
    public function fetchAll($stmt) {
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get last inserted ID
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    /**
     * Check if in transaction
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
    
    /**
     * Convenience method: prepare, execute, and fetch one
     */
    public function queryOne($sql, $params = []) {
        $stmt = $this->prepare($sql);
        $this->execute($stmt, $params);
        return $this->fetchOne($stmt);
    }
    
    /**
     * Convenience method: prepare, execute, and fetch all
     */
    public function queryAll($sql, $params = []) {
        $stmt = $this->prepare($sql);
        $this->execute($stmt, $params);
        return $this->fetchAll($stmt);
    }
    
    /**
     * Perform an insert/update/delete
     */
    public function exec($sql, $params = []) {
        $stmt = $this->prepare($sql);
        return $this->execute($stmt, $params);
    }
}

?>
