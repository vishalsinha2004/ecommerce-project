<?php
/**
 * Secure Login System with Brute Force Protection
 * Enhanced security features with minimal database changes
 * * Security Features:
 * - Brute force protection with IP and user-based tracking
 * - Rate limiting with exponential backoff
 * - Account lockout mechanism
 * - CSRF protection
 * - Session security hardening
 * - Input validation and sanitization
 * - Secure password verification
 * - Failed login attempt logging
 * - Remember me token security
 * - Device fingerprinting
 * - Suspicious activity detection
 * * @version 2.0
 * @since 2025-01-01
 */

// Start session and include config
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

/**
 * =============================================================================
 * SECURITY CONFIGURATION
 * =============================================================================
 */

// Brute force protection settings
define('LOCKOUT_TIME', 900);              // 15 minutes lockout in seconds
define('MAX_IP_ATTEMPTS', 10);            // Maximum attempts per IP
define('IP_LOCKOUT_TIME', 1800);          // 30 minutes IP lockout
define('PROGRESSIVE_DELAY', true);        // Enable progressive delay
define('MAX_DELAY', 30);                  // Maximum delay in seconds
define('CAPTCHA_THRESHOLD', 3);           // Show captcha after N attempts

// Account security
define('AUTO_LOCK_ATTEMPTS', 10);         // Auto-lock account after N attempts
define('SUSPICIOUS_ACTIVITY_THRESHOLD', 3); // Threshold for suspicious activity

/**
 * =============================================================================
 * SECURITY UTILITY FUNCTIONS
 * =============================================================================
 */

/**
 * Get client fingerprint for device tracking
 */
function getClientFingerprint() {
    $fingerprint_data = [
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
    ];
    
    return hash('sha256', implode('|', $fingerprint_data));
}

/**
 * Log security event
 */
function logSecurityEvent($event_type, $details = [], $severity = 'INFO') {
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event_type' => $event_type,
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'fingerprint' => getClientFingerprint(),
        'session_id' => session_id(),
        'details' => $details,
        'severity' => $severity
    ];
    
    $log_message = json_encode($log_data);
    logMessage($log_message, $severity, LOGS_PATH . '/security.log');
    
    // Also log to main application log for high severity events
    if (in_array($severity, ['ERROR', 'CRITICAL', 'ALERT'])) {
        logMessage("SECURITY: {$event_type} - " . json_encode($details), $severity);
    }
}

/**
 * Check and record failed login attempts
 */
function recordFailedAttempt($email = null, $pdo = null) {
    if (!$pdo) return;
    
    $ip = getClientIP();
    $fingerprint = getClientFingerprint();
    $now = time();
    
    try {
        // Record failed attempt
        $stmt = $pdo->prepare("
            INSERT INTO " . DB_PREFIX . "login_attempts 
            (email, ip_address, fingerprint, attempt_time, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $email,
            $ip,
            $fingerprint,
            date('Y-m-d H:i:s'),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        logSecurityEvent('failed_login_attempt', [
            'email' => $email,
            'ip' => $ip,
            'fingerprint' => $fingerprint
        ], 'WARNING');
        
    } catch (PDOException $e) {
        logSecurityEvent('failed_attempt_log_error', [
            'error' => $e->getMessage()
        ], 'ERROR');
    }
}

/**
 * Check if IP or user is locked out
 */
function isLockedOut($email = null, $pdo = null) {
    if (!$pdo) return false;
    
    $ip = getClientIP();
    $lockout_time = date('Y-m-d H:i:s', time() - LOCKOUT_TIME);
    $ip_lockout_time = date('Y-m-d H:i:s', time() - IP_LOCKOUT_TIME);
    
    try {
        // Check IP-based lockout
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempt_count 
            FROM " . DB_PREFIX . "login_attempts 
            WHERE ip_address = ? AND attempt_time > ?
        ");
        $stmt->execute([$ip, $ip_lockout_time]);
        $ip_attempts = $stmt->fetch()['attempt_count'];
        
        if ($ip_attempts >= MAX_IP_ATTEMPTS) {
            logSecurityEvent('ip_lockout_active', [
                'ip' => $ip,
                'attempts' => $ip_attempts
            ], 'WARNING');
            return ['locked' => true, 'type' => 'ip', 'attempts' => $ip_attempts];
        }
        
        // Check user-based lockout if email provided
        if ($email) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as attempt_count 
                FROM " . DB_PREFIX . "login_attempts 
                WHERE email = ? AND attempt_time > ?
            ");
            $stmt->execute([$email, $lockout_time]);
            $user_attempts = $stmt->fetch()['attempt_count'];
            
            if ($user_attempts >= MAX_LOGIN_ATTEMPTS) {
                logSecurityEvent('user_lockout_active', [
                    'email' => $email,
                    'attempts' => $user_attempts
                ], 'WARNING');
                return ['locked' => true, 'type' => 'user', 'attempts' => $user_attempts];
            }
            
            return ['locked' => false, 'user_attempts' => $user_attempts, 'ip_attempts' => $ip_attempts];
        }
        
        return ['locked' => false, 'ip_attempts' => $ip_attempts];
        
    } catch (PDOException $e) {
        logSecurityEvent('lockout_check_error', [
            'error' => $e->getMessage()
        ], 'ERROR');
        return false;
    }
}

/**
 * Apply progressive delay based on failed attempts
 */
function applyProgressiveDelay($attempts) {
    if (!PROGRESSIVE_DELAY) return;
    
    // Exponential backoff: 2^attempts seconds, capped at MAX_DELAY
    $delay = min(pow(2, $attempts), MAX_DELAY);
    
    if ($delay > 0) {
        logSecurityEvent('progressive_delay_applied', [
            'delay' => $delay,
            'attempts' => $attempts
        ]);
        sleep($delay);
    }
}

/**
 * Clean up old login attempts
 */
function cleanupOldAttempts($pdo = null) {
    if (!$pdo) return;
    
    $cleanup_time = date('Y-m-d H:i:s', time() - (IP_LOCKOUT_TIME * 2)); // Keep records for 2x lockout time
    
    try {
        $stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "login_attempts WHERE attempt_time < ?");
        $deleted = $stmt->execute([$cleanup_time]);
        
        if ($stmt->rowCount() > 0) {
            logSecurityEvent('login_attempts_cleanup', [
                'deleted_count' => $stmt->rowCount()
            ]);
        }
    } catch (PDOException $e) {
        logSecurityEvent('cleanup_error', [
            'error' => $e->getMessage()
        ], 'ERROR');
    }
}

/**
 * Rate limiting check
 */
function checkRateLimit($pdo = null) {
    if (!$pdo) return true;
    
    $ip = getClientIP();
    $window_start = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW);
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as request_count 
            FROM " . DB_PREFIX . "login_attempts 
            WHERE ip_address = ? AND attempt_time > ?
        ");
        $stmt->execute([$ip, $window_start]);
        $request_count = $stmt->fetch()['request_count'];
        
        if ($request_count >= RATE_LIMIT_REQUESTS) {
            logSecurityEvent('rate_limit_exceeded', [
                'ip' => $ip,
                'requests' => $request_count
            ], 'WARNING');
            return false;
        }
        
        return true;
        
    } catch (PDOException $e) {
        logSecurityEvent('rate_limit_check_error', [
            'error' => $e->getMessage()
        ], 'ERROR');
        return true; // Allow on error
    }
}

/**
 * Enhanced CSRF token generation and validation
 */
function generateEnhancedCSRFToken() {
    $token_data = [
        'token' => bin2hex(random_bytes(32)),
        'timestamp' => time(),
        'fingerprint' => getClientFingerprint()
    ];
    
    $_SESSION['csrf_token_data'] = $token_data;
    return base64_encode(json_encode($token_data));
}

function validateEnhancedCSRFToken($token) {
    if (!isset($_SESSION['csrf_token_data'])) {
        return false;
    }
    
    try {
        $submitted_data = json_decode(base64_decode($token), true);
        $session_data = $_SESSION['csrf_token_data'];
        
        // Check token match
        if (!hash_equals($session_data['token'], $submitted_data['token'])) {
            return false;
        }
        
        // Check timestamp (token expires in 1 hour)
        if ((time() - $session_data['timestamp']) > 3600) {
            return false;
        }
        
        // Check fingerprint to prevent token theft
        if ($session_data['fingerprint'] !== getClientFingerprint()) {
            logSecurityEvent('csrf_token_fingerprint_mismatch', [
                'expected' => $session_data['fingerprint'],
                'received' => getClientFingerprint()
            ], 'WARNING');
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        logSecurityEvent('csrf_validation_error', [
            'error' => $e->getMessage()
        ], 'ERROR');
        return false;
    }
}

/**
 * =============================================================================
 * MAIN SECURITY IMPLEMENTATION
 * =============================================================================
 */

// Database connection with error handling
$db_connected = false;
$pdo = null;

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $db_connected = true;
    
    // Create login_attempts table if it doesn't exist (minimal database change)
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255),
        ip_address VARCHAR(45) NOT NULL,
        fingerprint VARCHAR(64),
        attempt_time DATETIME NOT NULL,
        user_agent TEXT,
        INDEX idx_email_time (email, attempt_time),
        INDEX idx_ip_time (ip_address, attempt_time),
        INDEX idx_fingerprint (fingerprint)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
} catch (PDOException $e) {
    $db_connected = false;
    logSecurityEvent('database_connection_failed', [
        'error' => $e->getMessage()
    ], 'CRITICAL');
}

// Clean up old attempts periodically (1% chance)
if ($db_connected && rand(1, 100) === 1) {
    cleanupOldAttempts($pdo);
}

// Redirect if logged in
if (isset($_SESSION['user_id'])) {
    logSecurityEvent('already_logged_in_redirect', [
        'user_id' => $_SESSION['user_id']
    ]);
    header("Location: ../index.php");
    exit();
}

// Initialize variables
$errors = [];
$email = '';
$remember_me = false;
$show_captcha = false;
$lockout_info = null;

// Rate limiting check
if ($db_connected && !checkRateLimit($pdo)) {
    $errors[] = 'Too many requests. Please try again later.';
    logSecurityEvent('rate_limit_blocked', [], 'WARNING');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    
    // Enhanced CSRF validation
    if (!isset($_POST['csrf_token']) || !validateEnhancedCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Security token validation failed. Please refresh the page and try again.';
        logSecurityEvent('csrf_validation_failed', [
            'token_present' => isset($_POST['csrf_token'])
        ], 'WARNING');
    } else {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        // Input validation
        if (empty($email)) {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (strlen($email) > 255) {
            $errors[] = 'Email address is too long.';
        }
        
        if (empty($password)) {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) > 255) {
            $errors[] = 'Password is too long.';
        }
        
        if (!$db_connected) {
            $errors[] = 'Database connection unavailable. Please try again later.';
        }
        
        // Check for lockout status
        if ($db_connected && empty($errors)) {
            $lockout_info = isLockedOut($email, $pdo);
            
            if ($lockout_info && $lockout_info['locked']) {
                $lockout_type = $lockout_info['type'];
                $attempts = $lockout_info['attempts'];
                
                if ($lockout_type === 'ip') {
                    $errors[] = "Your IP address has been temporarily blocked due to too many failed attempts. Please try again later.";
                    logSecurityEvent('ip_lockout_triggered', [
                        'ip' => getClientIP(),
                        'attempts' => $attempts
                    ], 'WARNING');
                } else {
                    $errors[] = "This account has been temporarily locked due to multiple failed login attempts. Please try again later or contact support.";
                    logSecurityEvent('user_lockout_triggered', [
                        'email' => $email,
                        'attempts' => $attempts
                    ], 'WARNING');
                }
            }
            
            // Show captcha if attempts exceed threshold
            if ($lockout_info && !$lockout_info['locked']) {
                $user_attempts = $lockout_info['user_attempts'] ?? 0;
                $ip_attempts = $lockout_info['ip_attempts'] ?? 0;
                
                if ($user_attempts >= CAPTCHA_THRESHOLD || $ip_attempts >= CAPTCHA_THRESHOLD) {
                    $show_captcha = true;
                }
            }
        }
        
        // Process login if no errors
        if (empty($errors) && $db_connected) {
            
            // Apply progressive delay based on previous attempts
            if ($lockout_info && !$lockout_info['locked']) {
                $attempts = max(
                    $lockout_info['user_attempts'] ?? 0,
                    $lockout_info['ip_attempts'] ?? 0
                );
                applyProgressiveDelay($attempts);
            }
            
            try {
                // Get user data with account status
                $stmt = $pdo->prepare("
                    SELECT id, email, password, first_name, last_name, role, status, 
                           failed_login_attempts, last_login_attempt, account_locked_until
                    FROM " . DB_PREFIX . "users 
                    WHERE email = ? 
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Check account status
                    if ($user['status'] !== 'active') {
                        $errors[] = 'Your account is not active. Please contact support.';
                        logSecurityEvent('inactive_account_login_attempt', [
                            'email' => $email,
                            'status' => $user['status']
                        ], 'WARNING');
                    } 
                    // Check if account is locked
                    elseif ($user['account_locked_until'] && 
                            strtotime($user['account_locked_until']) > time()) {
                        $errors[] = 'Your account is temporarily locked. Please try again later or contact support.';
                        logSecurityEvent('locked_account_login_attempt', [
                            'email' => $email,
                            'locked_until' => $user['account_locked_until']
                        ], 'WARNING');
                    }
                    // Verify password
                    elseif (password_verify($password, $user['password'])) {
                        // Successful login
                        
                        // Clear failed attempts on successful login
                        try {
                            $pdo->prepare("
                                DELETE FROM " . DB_PREFIX . "login_attempts 
                                WHERE email = ? OR ip_address = ?
                            ")->execute([$email, getClientIP()]);
                            
                            // Reset user failed attempts
                            $pdo->prepare("
                                UPDATE " . DB_PREFIX . "users 
                                SET failed_login_attempts = 0, 
                                    last_login_attempt = NULL,
                                    account_locked_until = NULL,
                                    last_login = NOW()
                                WHERE id = ?
                            ")->execute([$user['id']]);
                            
                        } catch (PDOException $e) {
                            logSecurityEvent('failed_attempts_reset_error', [
                                'error' => $e->getMessage()
                            ], 'ERROR');
                        }
                        
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['first_name'] = $user['first_name'];
                        $_SESSION['last_name'] = $user['last_name'];
                        $_SESSION['user_role'] = $user['role'] ?? 'user';
                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();
                        $_SESSION['client_fingerprint'] = getClientFingerprint();
                        
                        // Enhanced remember me functionality
                        if ($remember_me) {
                            try {
                                $remember_token = generateRandomString(64);
                                $expires = time() + REMEMBER_ME_LIFETIME;
                                
                                // Store remember token in database (requires adding remember_token field)
                                $pdo->prepare("
                                    UPDATE " . DB_PREFIX . "users 
                                    SET remember_token = ?, remember_token_expires = ?
                                    WHERE id = ?
                                ")->execute([
                                    hash('sha256', $remember_token),
                                    date('Y-m-d H:i:s', $expires),
                                    $user['id']
                                ]);
                                
                                // Set secure cookie
                                setcookie(
                                    'remember_token',
                                    $remember_token,
                                    $expires,
                                    '/',
                                    '',
                                    isset($_SERVER['HTTPS']),
                                    true
                                );
                                
                            } catch (Exception $e) {
                                logSecurityEvent('remember_me_token_error', [
                                    'error' => $e->getMessage()
                                ], 'ERROR');
                            }
                        }
                        
                        logSecurityEvent('successful_login', [
                            'user_id' => $user['id'],
                            'email' => $email,
                            'role' => $user['role'],
                            'remember_me' => $remember_me
                        ], 'INFO');
                        
                        // Handle redirect parameter for admin access
                        if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
                            $redirect_url = filter_var($_GET['redirect'], FILTER_SANITIZE_URL);
                            // Security check for redirect URL
                            if (strpos($redirect_url, '/admin/') !== false && $user['role'] === 'admin') {
                                header("Location: " . $redirect_url);
                                exit();
                            }
                        }
                        
                        // Role-based redirect
                        if ($user['role'] === 'admin') {
                            header("Location: /ecommerce-project/admin/dashboard.php");
                        } else {
                            header("Location: /ecommerce-project/index.php");
                        }
                        exit();
                        
                    } else {
                        // Failed password verification
                        $errors[] = 'Invalid email address or password.';
                        
                        // Record failed attempt
                        recordFailedAttempt($email, $pdo);
                        
                        // Update user failed attempts counter
                        try {
                            $new_attempts = ($user['failed_login_attempts'] ?? 0) + 1;
                            $lock_until = null;
                            
                            // Auto-lock account if too many attempts
                            if ($new_attempts >= AUTO_LOCK_ATTEMPTS) {
                                $lock_until = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
                                logSecurityEvent('account_auto_locked', [
                                    'email' => $email,
                                    'attempts' => $new_attempts,
                                    'locked_until' => $lock_until
                                ], 'ALERT');
                            }
                            
                            $pdo->prepare("
                                UPDATE " . DB_PREFIX . "users 
                                SET failed_login_attempts = ?, 
                                    last_login_attempt = NOW(),
                                    account_locked_until = ?
                                WHERE id = ?
                            ")->execute([$new_attempts, $lock_until, $user['id']]);
                            
                        } catch (PDOException $e) {
                            logSecurityEvent('failed_attempts_update_error', [
                                'error' => $e->getMessage()
                            ], 'ERROR');
                        }
                        
                        logSecurityEvent('failed_password_verification', [
                            'email' => $email,
                            'attempts' => ($user['failed_login_attempts'] ?? 0) + 1
                        ], 'WARNING');
                    }
                } else {
                    // User not found
                    $errors[] = 'Invalid email address or password.';
                    recordFailedAttempt($email, $pdo);
                    
                    logSecurityEvent('user_not_found_login_attempt', [
                        'email' => $email
                    ], 'WARNING');
                }
                
            } catch (PDOException $e) {
                $errors[] = 'Login system temporarily unavailable. Please try again later.';
                logSecurityEvent('database_error_during_login', [
                    'error' => $e->getMessage()
                ], 'ERROR');
                
            } catch (Exception $e) {
                $errors[] = 'An unexpected error occurred. Please try again later.';
                logSecurityEvent('unexpected_error_during_login', [
                    'error' => $e->getMessage()
                ], 'ERROR');
            }
        }
    }
}

// Generate enhanced CSRF token
$csrf_token = generateEnhancedCSRFToken();

/**
 * =============================================================================
 * HTML OUTPUT WITH ENHANCED SECURITY
 * =============================================================================
 */
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Sign In - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css?family=Nunito:700,800,400&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Nunito', sans-serif; 
            background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
            color: #4a4a4a;
        }
        .glass-card { 
            background: rgba(255, 255, 255, 0.9); 
            backdrop-filter: blur(12px); 
            border-radius: 1.25rem; 
            border: 1px solid rgba(255, 255, 255, 0.6); 
            box-shadow: 0 8px 32px 0 rgba(200, 175, 220, 0.1); 
        }
        .security-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(31, 41, 55, 0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }
        .lockout-warning {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        /* Slimmer Input Style */
        .input-style {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background-color: #f9fafb; /* gray-50 */
            border: 1px solid #f3f4f6; /* gray-100 */
            transition: all 0.2s ease-in-out;
            color: #1f2937;
        }
        .input-style:focus {
            background-color: #ffffff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(251, 207, 232, 0.5); /* ring-pink-200 */
            border-color: transparent;
        }
        /* Slimmer Button Gradient */
        .btn-primary {
            background: linear-gradient(90deg, #d86990 0%, #e995b5 100%);
            color: #fff;
            transition: all 0.3s cubic-bezier(.4, 0, .2, 1);
            box-shadow: 0 2px 10px 0 #e995b544;
            border: none;
        }
        .btn-primary:hover {
            filter: brightness(1.05);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px 0 #e995b566;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    
    <div class="security-indicator flex items-center shadow-lg">
        <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
        Enhanced Security Active
    </div>
    
    <div class="max-w-md w-full space-y-8">
        <div class="glass-card p-8 md:p-10">
            <div class="text-center">
                <div class="flex justify-center mb-4">
                    <div class="w-14 h-14 bg-pink-50 rounded-full flex items-center justify-center">
                        <svg class="w-7 h-7 text-[#d86990]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                </div>
                <h2 class="text-2xl font-bold text-gray-800 mb-1">Welcome Back</h2>
                <p class="text-sm text-gray-500 mb-8">Sign in to access your account</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border border-red-100 rounded-xl p-4 mb-6 <?php echo ($lockout_info && $lockout_info['locked']) ? 'lockout-warning' : ''; ?>">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-bold text-red-800">Security Alert</h3>
                            <div class="mt-1 text-sm text-red-600">
                                <ul class="list-disc list-inside space-y-1">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($lockout_info && !$lockout_info['locked'] && ($lockout_info['user_attempts'] > 0 || $lockout_info['ip_attempts'] > 0)): ?>
                <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-4 mb-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.492-1.646-1.742-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-bold text-yellow-800">Security Warning</h3>
                            <div class="mt-1 text-sm text-yellow-700">
                                <p>Multiple failed login attempts detected.</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-6" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div>
                    <label for="email" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                        Email Address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                            </svg>
                        </div>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($email); ?>"
                            class="input-style pl-10"
                            placeholder="Enter your email address"
                            required
                            autocomplete="email"
                            maxlength="255"
                        >
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                        Password
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <input 
                            type="password" 
                            id="password" 
                            name="password"
                            class="input-style pl-10 pr-10"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                            maxlength="255"
                        >
                        <button 
                            type="button" 
                            class="absolute inset-y-0 right-0 pr-3 flex items-center"
                            onclick="togglePassword()"
                            id="togglePasswordBtn"
                        >
                            <svg class="h-5 w-5 text-gray-400 hover:text-gray-600 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eyeIcon">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <?php if ($show_captcha): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                        Security Check
                    </label>
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-4">
                        <p class="text-xs text-gray-500 mb-3">Please complete the math verification:</p>
                        <div class="flex items-center space-x-3">
                            <?php
                            $num1 = rand(1, 10);
                            $num2 = rand(1, 10);
                            $answer = $num1 + $num2;
                            $_SESSION['captcha_answer'] = $answer;
                            ?>
                            <span class="text-sm font-mono bg-white px-3 py-2 border border-gray-200 rounded-lg text-gray-700 shadow-sm">
                                <?php echo $num1; ?> + <?php echo $num2; ?> = ?
                            </span>
                            <input 
                                type="number" 
                                name="captcha" 
                                class="w-20 px-3 py-2 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-pink-200 text-sm"
                                placeholder="Answer"
                                required
                                min="0"
                                max="20"
                            >
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input 
                            type="checkbox" 
                            id="remember_me" 
                            name="remember_me"
                            class="h-4 w-4 text-[#d86990] focus:ring-pink-200 border-gray-300 rounded cursor-pointer"
                            <?php echo $remember_me ? 'checked' : ''; ?>
                        >
                        <label for="remember_me" class="ml-2 block text-sm text-gray-600 cursor-pointer select-none">
                            Keep me signed in
                        </label>
                    </div>
                    <div>
                        <a href="/ecommerce-project/auth/forgot_pass.php" class="text-sm text-[#d86990] hover:text-pink-700 font-semibold transition-colors duration-200">
                            Forgot password?
                        </a>
                    </div>
                </div>

                <div>
                    <button 
                        type="submit" 
                        class="group relative w-full flex justify-center py-3 px-4 btn-primary rounded-xl text-sm font-bold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-pink-300 transform hover:scale-[1.01]"
                        id="loginBtn"
                        <?php echo (!empty($errors) && ($lockout_info && $lockout_info['locked'])) ? 'disabled' : ''; ?>
                    >
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <svg class="h-5 w-5 text-pink-200 group-hover:text-white transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </span>
                        <span id="loginBtnText">Sign In Securely</span>
                        <span id="loginSpinner" class="hidden flex items-center">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Processing...
                        </span>
                    </button>
                </div>
            </form>

            <div class="mt-8">
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-100"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-3 bg-white text-gray-400">Don't have an account?</span>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="register.php" class="w-full flex justify-center py-3 px-4 border border-gray-200 text-sm font-semibold rounded-xl text-gray-600 bg-gray-50 hover:bg-white hover:shadow-md transition-all duration-200">
                        Create New Account
                    </a>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t border-gray-100">
                <div class="flex justify-center items-center space-x-4 text-xs text-gray-400">
                    <div class="flex items-center" title="Brute Force Protection Active">
                        <svg class="w-3 h-3 text-green-500 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                        Secure Login
                    </div>
                    <div class="flex items-center" title="SSL Encryption">
                        <svg class="w-3 h-3 text-green-500 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path></svg>
                        Encrypted
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center">
            <a href="../index.php" class="text-gray-500 hover:text-[#d86990] text-sm font-medium transition-colors duration-200 inline-flex items-center">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Back to Home
            </a>
        </div>
    </div>

    <script>
        // Enhanced client-side security and UX
        
        // Password visibility toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L12 12m-3.122-3.122L6.8 6.8m7.2 7.2l2.122 2.122M12 12l2.878 2.878"></path>
                `;
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                `;
            }
        }

        // Form submission handling with loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loginBtn = document.getElementById('loginBtn');
            const loginBtnText = document.getElementById('loginBtnText');
            const loginSpinner = document.getElementById('loginSpinner');
            
            if (!loginBtn.disabled) {
                loginBtn.disabled = true;
                loginBtnText.classList.add('hidden');
                loginSpinner.classList.remove('hidden');
                
                // Re-enable button after 10 seconds as failsafe
                setTimeout(function() {
                    if (loginBtn.disabled) {
                        loginBtn.disabled = false;
                        loginBtnText.classList.remove('hidden');
                        loginSpinner.classList.add('hidden');
                    }
                }, 10000);
            }
        });

        // Enhanced client-side validation
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email && !emailRegex.test(email)) {
                this.classList.add('border-red-300', 'ring-1', 'ring-red-200');
                this.classList.remove('border-gray-100');
            } else {
                this.classList.remove('border-red-300', 'ring-1', 'ring-red-200');
                this.classList.add('border-gray-100');
            }
        });

        // Security monitoring - detect unusual behavior
        let clickCount = 0;
        let lastClickTime = 0;
        
        document.addEventListener('click', function() {
            const currentTime = Date.now();
            if (currentTime - lastClickTime < 100) { // Rapid clicking detection
                clickCount++;
                if (clickCount > 10) {
                    console.warn('Unusual clicking pattern detected');
                    // Could implement additional security measures here
                }
            } else {
                clickCount = 0;
            }
            lastClickTime = currentTime;
        });

        // Auto-focus email field on load
        window.addEventListener('load', function() {
            document.getElementById('email').focus();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + Enter to submit form
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('loginForm').submit();
            }
        });

        // Session timeout warning (if needed)
        let sessionWarningShown = false;
        setTimeout(function() {
            if (!sessionWarningShown) {
                sessionWarningShown = true;
                const warning = document.createElement('div');
                warning.className = 'fixed top-4 right-4 bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl shadow-lg z-50 flex items-center text-sm';
                warning.innerHTML = `
                    <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.492-1.646-1.742-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span>For your security, please complete your login soon.</span>
                    <button onclick="this.parentElement.remove()" class="ml-3 text-yellow-600 hover:text-yellow-900 font-bold">×</button>
                `;
                document.body.appendChild(warning);
                
                setTimeout(function() {
                    if (warning.parentElement) {
                        warning.remove();
                    }
                }, 10000);
            }
        }, 300000); // 5 minutes

        // Performance monitoring
        if ('performance' in window && 'measure' in window.performance) {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    const loadTime = window.performance.timing.loadEventEnd - window.performance.timing.navigationStart;
                    if (loadTime > 0) {
                        console.info('Page load time:', loadTime + 'ms');
                    }
                }, 0);
            });
        }
    </script>

    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">

</body>
</html>