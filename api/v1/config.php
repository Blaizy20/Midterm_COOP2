<?php
/**
 * API Gateway Configuration File
 * 
 * This file serves as a starting point for building the REST API layer
 * that connects the mobile application to the database services.
 * 
 * Usage:
 * 1. Create /api/v1/ directory
 * 2. Copy this file content to /api/v1/config.php
 * 3. Create individual endpoint files for each service
 * 4. Test each endpoint with mobile client
 */

// ============================================================================
// CORS Configuration
// ============================================================================

function setup_cors() {
    // Configure allowed origins (restrict in production)
    $allowed_origins = [
        'http://localhost:8080',
        'http://localhost:3000',
        // Add your mobile app package name here when deployed
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    
    if ($origin && in_array($origin, $allowed_origins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 3600');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// ============================================================================
// Response Handler
// ============================================================================

/**
 * Standard API Response Format
 */
function api_response($success, $data = null, $message = null, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => $success,
        'timestamp' => date('Y-m-d\TH:i:s\Z'),
        'version' => 'v1'
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($message !== null) {
        $response['message'] = $message;
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * API Error Response
 */
function api_error($message, $error_code = null, $http_code = 400) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => false,
        'message' => $message,
        'timestamp' => date('Y-m-d\TH:i:s\Z'),
        'version' => 'v1'
    ];
    
    if ($error_code) {
        $response['error_code'] = $error_code;
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// Authentication Token Handler
// ============================================================================

/**
 * Get authentication token from header
 */
function get_auth_token() {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? '';
    
    if (preg_match('/^Bearer\s+(.+)$/', $authorization, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Verify JWT token and return user data
 * 
 * NOTE: This is a simplified example. Use proper JWT library in production.
 * Recommended: firebase/php-jwt from Composer
 */
function verify_auth_token($token) {
    // TODO: Implement proper JWT verification
    // For now, return dummy data - replace with actual JWT verification
    
    // Example with proper JWT (after composer require firebase/php-jwt):
    /*
    use Firebase\JWT\JWT;
    use Firebase\JWT\Key;
    
    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        return [
            'user_id' => $decoded->user_id,
            'customer_id' => $decoded->customer_id,
            'username' => $decoded->username
        ];
    } catch (Exception $e) {
        return null;
    }
    */
    
    // Simplified: Validate token format (implement proper JWT)
    if (strlen($token) < 20) {
        return null;
    }
    
    // In production, decode and validate JWT token here
    // Return user data if valid, null if invalid
    return null;
}

/**
 * Require authentication
 */
function require_auth() {
    $token = get_auth_token();
    
    if (!$token) {
        api_error('Missing authentication token', 'TOKEN_MISSING', 401);
    }
    
    $user = verify_auth_token($token);
    
    if (!$user) {
        api_error('Invalid or expired token', 'TOKEN_INVALID', 401);
    }
    
    return $user;
}

// ============================================================================
// Input Validation
// ============================================================================

/**
 * Get and validate JSON request body
 */
function get_json_input() {
    $input = file_get_contents('php://input');
    
    try {
        return json_decode($input, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        api_error('Invalid JSON input', 'INVALID_JSON', 400);
    }
}

/**
 * Validate required fields
 */
function validate_required_fields($data, $fields) {
    $missing = [];
    
    foreach ($fields as $field) {
        if (empty($data[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        api_error(
            'Missing required fields: ' . implode(', ', $missing),
            'VALIDATION_ERROR',
            422
        );
    }
}

/**
 * Validate email format
 */
function validate_email($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Invalid email format', 'INVALID_EMAIL', 422);
    }
}

/**
 * Validate phone number (Philippine format)
 */
function validate_phone($phone) {
    if (!preg_match('/^09\d{9}$/', $phone)) {
        api_error('Invalid phone number format', 'INVALID_PHONE', 422);
    }
}

// ============================================================================
// Rate Limiting
// ============================================================================

/**
 * Implement rate limiting (per user)
 * 
 * NOTE: Use Redis for distributed rate limiting in production
 */
function check_rate_limit($user_id, $limit = 100, $window = 60) {
    // Use session or cache to track requests
    // In production, use Redis:
    
    // Check if APCu functions are available
    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        // Use APCu if available
        $cache_key = "api_rate_limit:{$user_id}";
        $requests = @apcu_fetch($cache_key) ?? 0;  // @ suppresses warnings
        
        if ($requests >= $limit) {
            http_response_code(429);
            header('Retry-After: ' . $window);
            api_error('Rate limit exceeded', 'RATE_LIMIT_EXCEEDED', 429);
        }
        
        @apcu_store($cache_key, $requests + 1, $window);
        
        // Return remaining requests
        return $limit - ($requests + 1);
    }
    
    // Fallback to session-based rate limiting if APCu not available
    $cache_key = "rate_limit_{$user_id}";
    
    if (!isset($_SESSION[$cache_key])) {
        $_SESSION[$cache_key] = ['count' => 0, 'time' => time()];
    }
    
    $entry = $_SESSION[$cache_key];
    
    // Reset counter if window has passed
    if ((time() - $entry['time']) > $window) {
        $_SESSION[$cache_key] = ['count' => 1, 'time' => time()];
        return $limit - 1;
    }
    
    // Check if limit exceeded
    if ($entry['count'] >= $limit) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        api_error('Rate limit exceeded', 'RATE_LIMIT_EXCEEDED', 429);
    }
    
    $_SESSION[$cache_key]['count']++;
    return $limit - $_SESSION[$cache_key]['count'];
}

// ============================================================================
// Logging
// ============================================================================

/**
 * Log API requests for audit trail
 */
function log_api_request($user_id, $method, $endpoint, $status_code, $response_time) {
    $log_file = __DIR__ . '/../../logs/api_access.log';
    
    $log_entry = sprintf(
        "[%s] %s %s - User:%d Status:%d Time:%.3fms\n",
        date('Y-m-d H:i:s'),
        $method,
        $endpoint,
        $user_id ?? 0,
        $status_code,
        $response_time
    );
    
    error_log($log_entry, 3, $log_file);
}

/**
 * Log errors for debugging
 */
function log_error($message, $context = []) {
    $log_file = __DIR__ . '/../../logs/api_errors.log';
    
    $log_entry = sprintf(
        "[%s] ERROR: %s - Context: %s\n",
        date('Y-m-d H:i:s'),
        $message,
        json_encode($context)
    );
    
    error_log($log_entry, 3, $log_file);
}

// ============================================================================
// Database Connection
// ============================================================================

/**
 * Get database instance
 */
function get_db() {
    require_once __DIR__ . '/../../includes/pdo_db.php';
    return Database::getInstance();
}

// ============================================================================
// Initialize API
// ============================================================================

// Set up CORS headers
setup_cors();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to client

// Ensure UTF-8
mb_internal_encoding('UTF-8');

?>
