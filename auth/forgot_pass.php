<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/email_handler.php';

// Start session securely
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// Security: Regenerate session ID to prevent session fixation
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = true;
}

$success_message = '';
$error_message = '';
$rate_limit_message = '';

// Rate limiting check
$ip = $_SERVER['REMOTE_ADDR'];
$rate_limit_key = "forgot_pass_" . $ip;

// Check rate limit (max 3 attempts per 15 minutes per IP)
if (isset($_SESSION[$rate_limit_key])) {
    $attempts = $_SESSION[$rate_limit_key];
    if ($attempts['count'] >= 3 && (time() - $attempts['time']) < 900) {
        $remaining_time = 900 - (time() - $attempts['time']);
        $rate_limit_message = "Too many password reset attempts. Please try again in " . ceil($remaining_time / 60) . " minutes.";
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($rate_limit_message)) {
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Invalid security token. Please refresh the page and try again.');
        }

        // Rate limiting
        if (!isset($_SESSION[$rate_limit_key])) {
            $_SESSION[$rate_limit_key] = ['count' => 0, 'time' => time()];
        }
        $attempts = $_SESSION[$rate_limit_key];
        if ($attempts['count'] >= 3 && (time() - $attempts['time']) < 900) {
            throw new Exception('Too many attempts. Please wait before trying again.');
        }
        // Increment attempt counter
        if ((time() - $attempts['time']) > 900) {
            $_SESSION[$rate_limit_key] = ['count' => 1, 'time' => time()];
        } else {
            $_SESSION[$rate_limit_key]['count']++;
        }

        // Get and validate email
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        if (empty($email)) throw new Exception('Email address is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Please enter a valid email address.');
        if (strlen($email) > 320) throw new Exception('Email address is too long.');

        // Check if user exists
        $user = $db->fetchRow(
            "SELECT id, first_name, email, status FROM " . DB_PREFIX . "users WHERE email = ? AND status = 'active'",
            [$email]
        );

        // Always show success message for security
        $success_message = "If an account with this email exists, you will receive password reset instructions shortly.";

        if ($user) {
            // Check if there's an existing valid reset token
            $existing_reset = $db->fetchRow(
                "SELECT reset_token, reset_expires FROM " . DB_PREFIX . "users 
                 WHERE id = ? AND reset_token IS NOT NULL AND reset_expires > NOW()",
                [$user['id']]
            );

            if ($existing_reset) {
                $token_age = time() - strtotime($existing_reset['reset_expires']) + 3600; // 3600 is token lifetime
                if ($token_age < 600) { // 10 minutes
                    $reset_token = $existing_reset['reset_token'];
                } else {
                    $reset_token = bin2hex(random_bytes(32));
                }
            } else {
                $reset_token = bin2hex(random_bytes(32));
            }

            // Set expiration time (1 hour)
            $reset_expires = date('Y-m-d H:i:s', time() + 3600);

            // Store reset token with transaction safety
            $db->beginTransaction();
            try {
                $db->execute(
                    "UPDATE " . DB_PREFIX . "users 
                     SET reset_token = NULL, reset_expires = NULL 
                     WHERE id = ?",
                    [$user['id']]
                );
                $db->execute(
                    "UPDATE " . DB_PREFIX . "users 
                     SET reset_token = ?, reset_expires = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$reset_token, $reset_expires, $user['id']]
                );
                $db->commit();
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }

            // Send email using PHPMailer
            $mail_sent = sendPasswordResetEmail($user, $reset_token);

            if ($mail_sent) {
                logMessage("Password reset email sent successfully to: " . $email . " (User ID: " . $user['id'] . ")", 'INFO');
                if (ENVIRONMENT === 'development') {
                    $success_message .= " (Check your email inbox and spam folder)";
                }
            } else {
                logMessage("Failed to send password reset email to: " . $email . " (User ID: " . $user['id'] . ")", 'ERROR');
            }
        } else {
            logMessage("Password reset attempted for non-existent email: " . $email . " from IP: " . $_SERVER['REMOTE_ADDR'], 'WARNING');
        }

        unset($_SESSION[$rate_limit_key]);

    } catch (Exception $e) {
        $error_message = $e->getMessage();
        logMessage("Password reset error: " . $e->getMessage() . " for email: " . ($email ?? 'unknown') . " from IP: " . $_SERVER['REMOTE_ADDR'], 'ERROR');
    }
}

// Generate secure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Clean up expired reset tokens (maintenance)
if (rand(1, 100) <= 5) {
    $db->execute(
        "UPDATE " . DB_PREFIX . "users 
         SET reset_token = NULL, reset_expires = NULL 
         WHERE reset_expires <= NOW()"
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <meta name="description" content="Reset your <?php echo htmlspecialchars(SITE_NAME); ?> account password securely">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo htmlspecialchars(SITE_URL); ?>/auth/forgot_pass.php">

    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Custom styles for pastel, glassy, soft ElegantDresses theme -->
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
        }
        .glass-effect {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(14px);
            border: 1.5px solid #f3e6ff33;
        }
        .btn-primary {
            background: linear-gradient(90deg, #f7b9e3 0%, #c5b6f7 100%);
            color: #252536;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(.4,0,.2,1);
            box-shadow: 0 2px 16px 0 #c5b6f722;
        }
        .btn-primary:hover:not(:disabled) {
            transform: scale(1.025);
            box-shadow: 0 12px 32px #eac1f4b0;
            filter: brightness(1.05);
        }
        .form-input {
            background: rgba(255,255,255,.92);
            border: 1px solid #ece4fa;
            color: #181830;
        }
        .form-input:focus {
            border-color: #e8b6f7;
            box-shadow: 0 0 0 3px #f7b9e356;
        }
        h1, h3 {
            color: #191929 !important;
        }
        label, .text-gray-200 {
            color: #7a749e !important;
        }
        .shadow-2xl {
            box-shadow: 0 14px 48px 0 rgba(200,175,220,0.10);
        }
        .rounded-2xl {
            border-radius: 1.25rem;
        }
        .alert-success {
            background-color: #efeaff;
            border-color: #f7b9e386;
            color: #252536;
        }
        .alert-error {
            background-color: #fbe7f9;
            border-color: #f8d7eccc;
            color: #812a63;
        }
        .alert-warning {
            background-color: #fff6f2;
            border-color: #ffe3e2;
            color: #b77d2b;
        }
        .alert-info {
            background-color: #f7f1fd;
            border-color: #e4e0fb;
            color: #9066cb; 
        }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="glass-effect rounded-2xl p-8 shadow-2xl">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold mb-2">Forgot Password</h1>
                <p class="text-gray-200">Enter your email to reset your password securely</p>
            </div>

            <!-- Rate Limit Warning -->
            <?php if (!empty($rate_limit_message)): ?>
                <div class="alert-warning border rounded-lg p-4 mb-6 flex items-start">
                    <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <div><?php echo htmlspecialchars($rate_limit_message); ?></div>
                </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if (!empty($success_message)): ?>
                <div class="alert-success border rounded-lg p-4 mb-6 flex items-start">
                    <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <div><?php echo htmlspecialchars($success_message); ?></div>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="alert-error border rounded-lg p-4 mb-6 flex items-start">
                    <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <p class="font-medium">Please correct the following:</p>
                        <p><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="forgot_pass.php" class="space-y-6" id="forgotForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div>
                    <label for="email" class="block text-sm font-medium mb-2">Email Address</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                        required 
                        maxlength="320"
                        class="form-input w-full px-4 py-3 rounded-lg focus:outline-none"
                        placeholder="Enter your email address"
                        <?php echo !empty($rate_limit_message) ? 'disabled' : ''; ?>
                    >
                    <p class="text-gray-200 text-sm mt-2">We'll send secure password reset instructions to this email address.</p>
                </div>

                <button 
                    type="submit" 
                    class="btn-primary w-full py-3 px-4 rounded-lg font-semibold focus:outline-none focus:ring-4 focus:ring-purple-200"
                    id="submitBtn"
                    <?php echo !empty($rate_limit_message) ? 'disabled' : ''; ?>
                >
                    <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <span id="submitText">Send Reset Instructions</span>
                </button>
            </form>

            <!-- Security Information -->
            <div class="mt-6 p-4 bg-white bg-opacity-10 rounded-lg">
                <h3 class="font-medium mb-2">🔒 Security Features</h3>
                <ul class="text-gray-200 text-sm space-y-1">
                    <li>• Reset links expire in 1 hour</li>
                    <li>• Rate limiting prevents abuse</li>
                    <li>• Secure token generation</li>
                    <li>• Account status verification</li>
                    <li>• Professional email delivery</li>
                </ul>
            </div>

            <!-- Footer Links -->
            <div class="mt-8 pt-6 border-t border-gray-300">
                <p class="text-center text-gray-200 mb-4">Remember your password?</p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <a 
                        href="login.php" 
                        class="flex-1 text-center py-2 px-4 border border-white border-opacity-30 rounded-lg text-black hover:bg-white hover:bg-opacity-10 transition-colors duration-200"
                    >
                        <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                        Sign In
                    </a>
                    <a 
                        href="register.php" 
                        class="flex-1 text-center py-2 px-4 border border-white border-opacity-30 rounded-lg text-black hover:bg-white hover:bg-opacity-10 transition-colors duration-200"
                    >
                        <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                        </svg>
                        Register
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading overlay -->
    <div id="loading" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white rounded-lg p-6 flex items-center space-x-3">
            <svg class="animate-spin h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="text-gray-700">Sending secure reset instructions...</span>
        </div>
    </div>

    <script>
        // Enhanced form security and UX
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('forgotForm');
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const emailInput = document.getElementById('email');
            const loading = document.getElementById('loading');

            // Form submission handling
            form.addEventListener('submit', function(e) {
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return false;
                }
                // Show loading
                loading.classList.remove('hidden');
                submitBtn.disabled = true;
                submitText.textContent = 'Sending...';

                // Re-enable form after 10 seconds (fallback)
                setTimeout(() => {
                    loading.classList.add('hidden');
                    submitBtn.disabled = false;
                    submitText.textContent = 'Send Reset Instructions';
                }, 10000);
            });

            // Enhanced email validation
            emailInput.addEventListener('input', function() {
                const email = this.value.toLowerCase().trim();
                this.value = email;
                // Real-time validation
                if (email.length > 0) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(email)) {
                        this.style.borderColor = '#ef4444';
                        this.setCustomValidity('Please enter a valid email address');
                    } else if (email.length > 320) {
                        this.style.borderColor = '#ef4444';
                        this.setCustomValidity('Email address is too long');
                    } else {
                        this.style.borderColor = '#10b981';
                        this.setCustomValidity('');
                    }
                } else {
                    this.style.borderColor = '#e2e8f0';
                    this.setCustomValidity('');
                }
            });

            // Auto-hide messages after 15 seconds
            const alerts = document.querySelectorAll('.alert-success, .alert-error, .alert-warning');
            alerts.forEach(alert => {
                if (!alert.textContent.includes('Too many')) {
                    setTimeout(() => {
                        alert.style.transition = 'opacity 0.5s ease-out';
                        alert.style.opacity = '0';
                        setTimeout(() => {
                            alert.style.display = 'none';
                        }, 500);
                    }, 15000);
                }
            });

            // Prevent double submission
            let submitted = false;
            form.addEventListener('submit', function(e) {
                if (submitted) {
                    e.preventDefault();
                    return false;
                }
                submitted = true;
            });

            // Security: Clear form data on page unload
            window.addEventListener('beforeunload', function() {
                emailInput.value = '';
            });
        });
    </script>
</body>
</html>