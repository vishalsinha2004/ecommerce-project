<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
require_once '../includes/config.php';
require_once '../includes/db.php';

// Start session
session_start();

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';
$login_attempts = 0;
$lockout_time = null;

// Check for lockout
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lockout_key = 'admin_lockout_' . md5($ip_address);

if (isset($_SESSION[$lockout_key])) {
    $lockout_data = $_SESSION[$lockout_key];
    if ($lockout_data['attempts'] >= 5 && (time() - $lockout_data['time']) < 900) { // 15 minutes
        $remaining_time = 900 - (time() - $lockout_data['time']);
        $error_message = "Too many failed attempts. Please try again in " . ceil($remaining_time / 60) . " minutes.";
        $lockout_time = $remaining_time;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($lockout_time)) {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        // Validate input
        if (empty($email) || empty($password)) {
            $error_message = 'Please provide both email and password.';
        } else {
            try {
                // Find admin user
                $admin = $db->fetchRow(
                    "SELECT * FROM " . DB_PREFIX . "admins WHERE email = ? AND status = 'active'",
                    [$email]
                );
                
                if ($admin && password_verify($password, $admin['password'])) {
                    // Successful login
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role'];
                    $_SESSION['admin_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
                    $_SESSION['admin_login_time'] = time();
                    $_SESSION['admin_last_activity'] = time();
                    
                    // Update last login
                    $db->update('admins', 
                        ['last_login' => date('Y-m-d H:i:s'), 'login_attempts' => 0], 
                        'id = ?', 
                        [$admin['id']]
                    );
                    
                    // Clear lockout
                    unset($_SESSION[$lockout_key]);
                    
                    // Log successful login
                    $db->insert('admin_logs', [
                        'admin_id' => $admin['id'],
                        'action' => 'login',
                        'details' => 'Successful admin login',
                        'ip_address' => $ip_address,
                        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
                    ]);
                    
                    header('Location: dashboard.php');
                    exit;
                } else {
                    // Failed login
                    $error_message = 'Invalid email or password.';
                    
                    // Update lockout attempts
                    if (!isset($_SESSION[$lockout_key])) {
                        $_SESSION[$lockout_key] = ['attempts' => 1, 'time' => time()];
                    } else {
                        if ((time() - $_SESSION[$lockout_key]['time']) > 900) {
                            $_SESSION[$lockout_key] = ['attempts' => 1, 'time' => time()];
                        } else {
                            $_SESSION[$lockout_key]['attempts']++;
                        }
                    }
                    
                    // Update admin login attempts if user exists
                    if ($admin) {
                        $db->update('admins', 
                            ['login_attempts' => $admin['login_attempts'] + 1], 
                            'id = ?', 
                            [$admin['id']]
                        );
                    }
                }
            } catch (Exception $e) {
                $error_message = 'Login system temporarily unavailable. Please try again later.';
                error_log("Admin login error: " . $e->getMessage());
            }
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'Admin Login';
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full">
    <div class="flex min-h-full items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-md space-y-8">
            <div>
                <h1 class="text-center text-3xl font-bold text-gray-900"><?php echo SITE_NAME; ?></h1>
                <h2 class="mt-6 text-center text-2xl font-bold tracking-tight text-gray-900">Admin Panel Login</h2>
            </div>
            
            <?php if (!empty($error_message)): ?>
            <div class="rounded-md bg-red-50 p-4">
                <div class="text-sm text-red-700"><?php echo htmlspecialchars($error_message); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['timeout'])): ?>
            <div class="rounded-md bg-yellow-50 p-4">
                <div class="text-sm text-yellow-700">Your session has expired. Please log in again.</div>
            </div>
            <?php endif; ?>
            
            <form class="mt-8 space-y-6" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
                        <input id="email" name="email" type="email" required 
                               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 placeholder-gray-400 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500"
                               value="<?php echo htmlspecialchars($email ?? ''); ?>">
                    </div>
                    
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input id="password" name="password" type="password" required 
                               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 placeholder-gray-400 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500">
                    </div>
                </div>

                <div>
                    <button type="submit" <?php echo $lockout_time ? 'disabled' : ''; ?>
                            class="group relative flex w-full justify-center rounded-md bg-indigo-600 py-2 px-3 text-sm font-semibold text-white hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed">
                        <?php echo $lockout_time ? 'Locked - Try Again Later' : 'Sign in'; ?>
                    </button>
                </div>
                
                <div class="text-center">
                    <a href="<?php echo BASE_URL; ?>" class="text-sm text-indigo-600 hover:text-indigo-500">
                        ← Back to Main Site
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>