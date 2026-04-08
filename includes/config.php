<?php
/**
 * Site Configuration File
 * Dynamic Ecommerce Website - Women's Dresses
 * 
 * This file contains all site-wide constants, configurations, and settings.
 * Environment-specific configurations are handled automatically.
 * 
 * @author Your Name
 * @version 1.0
 * @since 2025-01-01
 */

// Prevent direct access
if (!defined('CONFIG_LOADED')) {
    define('CONFIG_LOADED', true);
} else {
    exit('Direct access not allowed');
}

// Start session with secure settings if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', '7200'); // 2 hours
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');
    
    session_start();
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) { // 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

// Error reporting based on environment
$is_production = (isset($_SERVER['HTTP_HOST']) && 
    (strpos($_SERVER['HTTP_HOST'], 'localhost') === false && 
     strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false &&
     strpos($_SERVER['HTTP_HOST'], '.local') === false));

if ($is_production) {
    // Production environment
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
    define('ENVIRONMENT', 'production');
} else {
    // Development environment
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    define('ENVIRONMENT', 'development');
}

// Timezone configuration
date_default_timezone_set('Asia/Kolkata'); // Change as needed

/**
 * =============================================================================
 * SITE CONFIGURATION
 * =============================================================================
 */

// Site Information
define('SITE_NAME', 'ElegantDresses');
define('SITE_TAGLINE', 'Elegant Women\'s Fashion');
define('SITE_DESCRIPTION', 'Discover elegant women\'s dresses that blend timeless style with contemporary fashion. Quality craftsmanship meets modern design in every piece.');
define('SITE_KEYWORDS', 'women\'s dresses, elegant fashion, online shopping, casual dresses, formal wear, evening dresses');
define('SITE_AUTHOR', 'ElegantDresses Team');
define('SITE_VERSION', '1.0.0');

// Contact Information
define('COMPANY_NAME', 'ElegantDresses Ltd.');
define('COMPANY_ADDRESS', '123 Fashion Street, Style City, SC 12345');
define('COMPANY_PHONE', '+1 (555) 123-4567');
define('COMPANY_EMAIL', 'ammarchhipa9@gmail.com');
define('SUPPORT_EMAIL', 'ammarchhipa9@gmail.com');
define('ORDERS_EMAIL', 'orders@elegantdresses.com');

// Social Media Links
define('FACEBOOK_URL', 'https://facebook.com/elegantdresses');
define('INSTAGRAM_URL', 'https://instagram.com/elegantdresses');
define('TWITTER_URL', 'https://twitter.com/elegantdresses');
define('PINTEREST_URL', 'https://pinterest.com/elegantdresses');

/**
 * =============================================================================
 * RAZORPAY CONFIGURATION
 * =============================================================================
 */

// Razorpay API credentials
define('RAZORPAY_KEY_ID', 'rzp_test_RIdh6VGMVaCY7W');
define('RAZORPAY_KEY_SECRET', 'bVEcRDH4bMlqQ2blmccDYN91');

// Razorpay webhook secret
define('RAZORPAY_WEBHOOK_SECRET', 'ammar2332');

/**
 * =============================================================================
 * URL AND PATH CONFIGURATION
 * =============================================================================
 */

// Auto-detect base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_path = dirname($script_name);

// Clean up base path
if ($base_path === '/' || $base_path === '\\') {
    $base_path = '';
}

if (basename($base_path) === 'auth') {
    $base_path = dirname($base_path);
}

define('BASE_URL', '/ecommerce-project');
define('SITE_URL', BASE_URL);
define('ASSETS_URL', BASE_URL . '/assets');
define('IMAGES_URL', ASSETS_URL . '/images');
define('CSS_URL', ASSETS_URL . '/css');
define('JS_URL', ASSETS_URL . '/js');

// File system paths
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ASSETS_PATH', ROOT_PATH . '/assets');
define('IMAGES_PATH', ASSETS_PATH . '/images');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('LOGS_PATH', ROOT_PATH . '/logs');
define('CACHE_PATH', ROOT_PATH . '/cache');

// Create necessary directories if they don't exist
$required_dirs = [UPLOADS_PATH, LOGS_PATH, CACHE_PATH];
foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        // Add index.php to prevent directory listing
        file_put_contents($dir . '/index.php', '<?php exit("Access denied"); ?>');
    }
}

/**
 * =============================================================================
 * DATABASE CONFIGURATION
 * =============================================================================
 */

if (ENVIRONMENT === 'production') {
    // Production database settings (Hostinger Premium)
    define('DB_HOST', 'sql208.ezyro.com'); // Usually localhost on shared hosting
    define('DB_NAME', 'ezyro_40126868_v'); // Your database name
    define('DB_USER', 'ezyro_40126868'); // Your database username
    define('DB_PASS', '88099e713'); // Your database password
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATE', 'utf8mb4_unicode_ci');
    
    // Connection pool settings
    define('DB_PERSISTENT', true);
    define('DB_TIMEOUT', 30);
} else {
    // Development database settings
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'ecommerce_dev');
    define('DB_USER', 'root');
    define('DB_PASS', ''); // Empty for XAMPP/WAMP
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATE', 'utf8mb4_unicode_ci');
    
    define('DB_PERSISTENT', false);
    define('DB_TIMEOUT', 5);
}

// Database table prefix
define('DB_PREFIX', 'ec_');

// Database options
$db_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE " . DB_COLLATE,
    PDO::ATTR_TIMEOUT => DB_TIMEOUT,
];

if (DB_PERSISTENT) {
    $db_options[PDO::ATTR_PERSISTENT] = true;
}

define('DB_OPTIONS', $db_options);

/**
 * =============================================================================
 * SECURITY CONFIGURATION
 * =============================================================================
 */

// Password requirements
define('MIN_PASSWORD_LENGTH', 8);
define('REQUIRE_UPPERCASE', true);
define('REQUIRE_LOWERCASE', true);
define('REQUIRE_NUMBERS', true);
define('REQUIRE_SPECIAL_CHARS', true);
define('PASSWORD_COST', 12); // bcrypt cost parameter

// Session security
define('SESSION_LIFETIME', 7200); // 2 hours
define('REMEMBER_ME_LIFETIME', 2592000); // 30 days
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// CSRF Protection
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_LIFETIME', 3600); // 1 hour

// File upload security
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOCUMENT_TYPES', ['pdf', 'doc', 'docx']);
define('UPLOAD_PATH', UPLOADS_PATH);

// XSS Protection
define('ALLOWED_HTML_TAGS', '<p><br><strong><em><u><ol><ul><li><a><img>');

// Rate limiting
define('RATE_LIMIT_REQUESTS', 10); // requests per window
define('RATE_LIMIT_WINDOW', 900); // 15 minute window

/**
 * =============================================================================
 * PAYMENT AND SHIPPING CONFIGURATION
 * =============================================================================
 */

// Currency settings
define('DEFAULT_CURRENCY', 'USD');
define('CURRENCY_SYMBOL', '$');
define('CURRENCY_POSITION', 'left'); // left or right
define('DECIMAL_PLACES', 2);
define('DECIMAL_SEPARATOR', '.');
define('THOUSANDS_SEPARATOR', ',');

// Payment gateways
define('ENABLE_PAYPAL', true);
define('ENABLE_STRIPE', true);
define('ENABLE_RAZORPAY', true); // For Indian market

// PayPal settings (use environment variables in production)
define('PAYPAL_CLIENT_ID', ENVIRONMENT === 'production' ? 
    (getenv('PAYPAL_CLIENT_ID') ?: 'your-production-client-id') : 
    'your-sandbox-client-id');
define('PAYPAL_CLIENT_SECRET', ENVIRONMENT === 'production' ? 
    (getenv('PAYPAL_CLIENT_SECRET') ?: 'your-production-client-secret') : 
    'your-sandbox-client-secret');
define('PAYPAL_MODE', ENVIRONMENT === 'production' ? 'live' : 'sandbox');

// Shipping settings
define('FREE_SHIPPING_THRESHOLD', 99.00);
define('DEFAULT_SHIPPING_COST', 9.99);
define('EXPRESS_SHIPPING_COST', 19.99);
define('INTERNATIONAL_SHIPPING_COST', 29.99);

// Tax settings
define('TAX_RATE', 0.08); // 8% tax rate
define('TAX_INCLUSIVE', false); // false = tax exclusive pricing

/**
 * =============================================================================
 * ECOMMERCE CONFIGURATION
 * =============================================================================
 */

// Product settings
define('PRODUCTS_PER_PAGE', 12);
define('RELATED_PRODUCTS_COUNT', 4);
define('FEATURED_PRODUCTS_COUNT', 8);
define('NEW_ARRIVALS_DAYS', 30); // Days to consider as new
define('SALE_BADGE_DISCOUNT', 10); // Minimum discount % for sale badge

// Inventory settings
define('LOW_STOCK_THRESHOLD', 5);
define('OUT_OF_STOCK_THRESHOLD', 0);
define('ENABLE_BACKORDERS', false);

// Cart settings
define('CART_LIFETIME', 2592000); // 30 days for guest carts
define('MAX_CART_ITEMS', 50);
define('MIN_ORDER_AMOUNT', 10.00);

// Review settings
define('ENABLE_REVIEWS', true);
define('REQUIRE_LOGIN_FOR_REVIEWS', true);
define('MODERATE_REVIEWS', true);
define('MAX_REVIEW_LENGTH', 1000);

// Wishlist settings
define('ENABLE_WISHLIST', true);
define('MAX_WISHLIST_ITEMS', 100);

/**
 * =============================================================================
 * EMAIL CONFIGURATION
 * =============================================================================
 */

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'ammarchhipa9@gmail.com'); // Your Gmail address
define('SMTP_PASSWORD', 'suhd ycme esxp wtai');     // Gmail App Password
define('SMTP_ENCRYPTION', 'tls');

/**
 * =============================================================================
 * CACHE AND PERFORMANCE CONFIGURATION
 * =============================================================================
 */

// Caching
define('ENABLE_CACHE', true);
define('CACHE_LIFETIME', 3600); // 1 hour
define('ENABLE_GZIP', true);

// Image optimization
define('IMAGE_QUALITY', 85); // JPEG quality
define('ENABLE_WEBP', true);
define('LAZY_LOAD_IMAGES', true);

// CDN settings (Hostinger CDN)
define('USE_CDN', ENVIRONMENT === 'production');
define('CDN_URL', 'https://your-cdn-domain.com'); // Replace with your CDN URL

/**
 * =============================================================================
 * SOCIAL MEDIA AND ANALYTICS
 * =============================================================================
 */

// Analytics
define('GOOGLE_ANALYTICS_ID', ENVIRONMENT === 'production' ? 'GA_MEASUREMENT_ID' : '');
define('FACEBOOK_PIXEL_ID', ENVIRONMENT === 'production' ? 'FB_PIXEL_ID' : '');

// Social login (optional)
define('ENABLE_GOOGLE_LOGIN', false);
define('ENABLE_FACEBOOK_LOGIN', false);

// Newsletter
define('ENABLE_NEWSLETTER', true);
define('MAILCHIMP_API_KEY', getenv('MAILCHIMP_API_KEY') ?: '');
define('MAILCHIMP_LIST_ID', getenv('MAILCHIMP_LIST_ID') ?: '');

/**
 * =============================================================================
 * MISCELLANEOUS CONFIGURATION
 * =============================================================================
 */

// Pagination
define('DEFAULT_PAGINATION_LIMIT', 10);
define('MAX_PAGINATION_LINKS', 5);

// Date and time formats
define('DATE_FORMAT', 'Y-m-d');
define('TIME_FORMAT', 'H:i:s');
define('DATETIME_FORMAT', DATE_FORMAT . ' ' . TIME_FORMAT);
define('DISPLAY_DATE_FORMAT', 'F j, Y');
define('DISPLAY_DATETIME_FORMAT', 'F j, Y g:i A');

// File and folder permissions
define('FILE_PERMISSIONS', 0644);
define('FOLDER_PERMISSIONS', 0755);

// Debug settings
define('ENABLE_DEBUG_MODE', ENVIRONMENT === 'development');
define('DEBUG_LOG_FILE', LOGS_PATH . '/debug.log');

// API settings
define('API_RATE_LIMIT', 1000); // requests per hour
define('API_VERSION', 'v1');

// Maintenance mode
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'We are currently performing scheduled maintenance. Please check back soon.');

/**
 * =============================================================================
 * UTILITY FUNCTIONS
 * =============================================================================
 */

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_LIFETIME) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && 
           isset($_SESSION['csrf_token_time']) &&
           (time() - $_SESSION['csrf_token_time']) <= CSRF_TOKEN_LIFETIME &&
           hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input data
 */
function sanitizeInput($data, $type = 'string') {
    if (is_array($data)) {
        return array_map(function($item) use ($type) {
            return sanitizeInput($item, $type);
        }, $data);
    }
    
    $data = trim($data);
    
    switch ($type) {
        case 'string':
            return filter_var($data, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        case 'email':
            return filter_var($data, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($data, FILTER_SANITIZE_URL);
        case 'int':
            return filter_var($data, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'html':
            return strip_tags($data, ALLOWED_HTML_TAGS);
        default:
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Log messages to file
 */
function logMessage($message, $level = 'INFO', $file = null) {
    if (!$file) {
        $file = LOGS_PATH . '/app.log';
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    file_put_contents($file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Format currency
 */
function formatCurrency($amount, $currency = DEFAULT_CURRENCY) {
    $formatted = number_format($amount, DECIMAL_PLACES, DECIMAL_SEPARATOR, THOUSANDS_SEPARATOR);
    
    if (CURRENCY_POSITION === 'left') {
        return CURRENCY_SYMBOL . $formatted;
    } else {
        return $formatted . CURRENCY_SYMBOL;
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Redirect function
 */
function redirect($url, $permanent = false) {
    if (!headers_sent()) {
        $status_code = $permanent ? 301 : 302;
        http_response_code($status_code);
        header("Location: " . $url);
        exit();
    } else {
        echo "<script>window.location.href = '{$url}';</script>";
        exit();
    }
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, 
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Generate secure random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Time ago function
 */
function timeAgo($timestamp) {
    $time = time() - $timestamp;
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}

/**
 * =============================================================================
 * INITIALIZATION AND CLEANUP
 * =============================================================================
 */

// Set error handler
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    $error_message = "Error: {$message} in {$file} on line {$line}";
    logMessage($error_message, 'ERROR');
    
    if (ENVIRONMENT === 'development') {
        echo "<pre>{$error_message}</pre>";
    }
    
    return true;
});

// Set exception handler
set_exception_handler(function($exception) {
    $error_message = "Uncaught exception: " . $exception->getMessage() . 
                    " in " . $exception->getFile() . 
                    " on line " . $exception->getLine();
    
    logMessage($error_message, 'ERROR');
    
    if (ENVIRONMENT === 'development') {
        echo "<pre>{$error_message}</pre>";
        echo "<pre>" . $exception->getTraceAsString() . "</pre>";
    } else {
        // Show user-friendly error page
        http_response_code(500);
        include ROOT_PATH . '/error-pages/500.html';
    }
    
    exit();
});

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    if (ENVIRONMENT === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    
    // Content Security Policy
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://checkout.razorpay.com https://cdn.tailwindcss.com https://js.stripe.com https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com; " .
           "font-src 'self' https://fonts.gstatic.com; " .
           "img-src 'self' data: https:; " .
           "connect-src 'self' https://api.stripe.com https://lumberjack.razorpay.com; " .
           "frame-src 'self' https://api.razorpay.com;";
    
    header("Content-Security-Policy: {$csp}");
}

// Maintenance mode check
if (MAINTENANCE_MODE && !isAdmin()) {
    http_response_code(503);
    include ROOT_PATH . '/maintenance.html';
    exit();
}

// Auto-generate CSRF token
generateCSRFToken();

// Log configuration loading (development only)
if (ENVIRONMENT === 'development') {
    logMessage('Configuration loaded successfully', 'INFO');
}

?>