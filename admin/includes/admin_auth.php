<?php
/**
 * Admin Authentication Middleware
 * Handles admin login verification and role-based access control
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include necessary files
require_once dirname(dirname(__DIR__)) . '/includes/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';

/**
 * Check if admin is logged in
 * @return bool
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_role']);
}

/**
 * Get current admin details
 * @return array|false
 */
function getCurrentAdmin() {
    global $db;
    
    if (!isAdminLoggedIn()) {
        return false;
    }
    
    $admin = $db->fetchRow(
        "SELECT * FROM " . DB_PREFIX . "admins WHERE id = ? AND status = 'active'",
        [$_SESSION['admin_id']]
    );
    
    return $admin;
}

/**
 * Check admin role access
 * @param string $required_role
 * @return bool
 */
function hasAdminAccess($required_role = 'admin') {
    if (!isAdminLoggedIn()) {
        return false;
    }
    
    $roles = ['moderator' => 1, 'admin' => 2, 'super_admin' => 3];
    $user_level = $roles[$_SESSION['admin_role']] ?? 0;
    $required_level = $roles[$required_role] ?? 0;
    
    return $user_level >= $required_level;
}

/**
 * Require admin login
 * @param string $required_role
 */
function requireAdminLogin($required_role = 'admin') {
    if (!isAdminLoggedIn()) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
    
    if (!hasAdminAccess($required_role)) {
        http_response_code(403);
        die('Access Denied: Insufficient permissions');
    }
    
    // Update last activity
    $_SESSION['admin_last_activity'] = time();
    
    // Check for session timeout (2 hours)
    if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > 7200) {
        adminLogout();
        header('Location: ' . BASE_URL . '/admin/login.php?timeout=1');
        exit;
    }
}

/**
 * Admin logout
 */
function adminLogout() {
    // Log the logout activity
    if (isAdminLoggedIn()) {
        logAdminActivity('logout', null, null, 'Admin logged out');
    }
    
    // Clear admin session variables
    unset($_SESSION['admin_id']);
    unset($_SESSION['admin_email']);
    unset($_SESSION['admin_role']);
    unset($_SESSION['admin_name']);
    unset($_SESSION['admin_login_time']);
    unset($_SESSION['admin_last_activity']);
    
    session_destroy();
}

/**
 * Log admin activity
 * @param string $action
 * @param string $table_affected
 * @param int $record_id
 * @param string $details
 */
function logAdminActivity($action, $table_affected = null, $record_id = null, $details = null) {
    global $db;
    
    if (!isAdminLoggedIn()) {
        return;
    }
    
    $data = [
        'admin_id' => $_SESSION['admin_id'],
        'action' => $action,
        'table_affected' => $table_affected,
        'record_id' => $record_id,
        'details' => $details,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ];
    
    $db->insert('admin_logs', $data);
}

/**
 * Generate and validate CSRF token for admin
 */
function generateAdminCSRF() {
    if (!isset($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf_token'];
}

/**
 * Validate admin CSRF token
 * @param string $token
 * @return bool
 */
function validateAdminCSRF($token) {
    return isset($_SESSION['admin_csrf_token']) && hash_equals($_SESSION['admin_csrf_token'], $token);
}

/**
 * Format file size for display
 * @param int $size
 * @return string
 */
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size >= 1024 && $i < 3; $i++) {
        $size /= 1024;
    }
    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Sanitize filename
 * @param string $filename
 * @return string
 */
function sanitizeFilename($filename) {
    // Remove any path information
    $filename = basename($filename);
    
    // Replace spaces and special characters
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    
    // Remove multiple underscores
    $filename = preg_replace('/_+/', '_', $filename);
    
    return $filename;
}
?>
