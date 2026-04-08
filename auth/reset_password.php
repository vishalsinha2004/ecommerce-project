<?php
require_once '../includes/config.php';
require_once '../includes/db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$success_message = '';
$error_message = '';
$token_valid = false;
$user_data = null;

// Check reset token
$token = $_GET['token'] ?? '';
if (empty($token)) {
    $error_message = 'Invalid or missing reset token.';
} else {
    $user_data = $db->fetchRow(
        "SELECT id, first_name, email FROM " . DB_PREFIX . "users
         WHERE reset_token = ? AND reset_expires > NOW() AND status = 'active'",
        [$token]
    );
    if (!$user_data) {
        $error_message = 'Invalid or expired reset token. Please request a new password reset.';
    } else {
        $token_valid = true;
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Invalid security token. Please refresh the page and try again.');
        }

        $new_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        if (empty($new_password) || empty($confirm_password)) {
            throw new Exception('Please fill in all password fields.');
        }
        if ($new_password !== $confirm_password) {
            throw new Exception('Passwords do not match.');
        }
        if (strlen($new_password) < MIN_PASSWORD_LENGTH) {
            throw new Exception('Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.');
        }

        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT, ['cost' => PASSWORD_COST]);
        $db->beginTransaction();
        try {
            $db->execute(
                "UPDATE " . DB_PREFIX . "users SET password = ?, reset_token = NULL, reset_expires = NULL, updated_at = NOW() WHERE id = ?",
                [$hashed_password, $user_data['id']]
            );
            $db->commit();
            $success_message = 'Your password has been successfully updated. You can now log in with your new password.';
            $token_valid = false;
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?= htmlspecialchars(SITE_NAME) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
            min-height: 100vh;
        }
        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(16px);
            border-radius: 1.25rem;
            box-shadow: 0 8px 32px 0 rgba(200,175,220,0.13);
            border: 1.5px solid #f3e6ff33;
        }
        .btn-primary {
            background: linear-gradient(90deg, #e883a7 0%, #b0679d 100%);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: 9999px;
            box-shadow: 0 3px 16px #e883a754;
            transition: box-shadow 0.18s, transform 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-primary:hover:not(:disabled) {
            filter: brightness(1.07);
            transform: scale(1.027);
            box-shadow: 0 10px 24px #e883a778;
        }
        .form-input {
            background: rgba(255,255,255,.96);
            border: 1.5px solid #ece4fa;
            color: #181830;
            font-size: 1rem;
            transition: border 0.17s, box-shadow 0.19s;
        }
        .form-input:focus {
            border-color: #e883a7;
            box-shadow: 0 0 0 3px #e883a751;
        }
        .alert-success {
            background: #efeaff;
            border-left: 4px solid #c5b6f7;
            color: #191929;
        }
        .alert-error {
            background: #fbe7f9;
            border-left: 4px solid #ea699b;
            color: #812a63;
        }
        h1 { color: #181830; font-size: 2.1rem; font-weight: 700; }
        label { color: #7a749e; }
        .rounded-2xl { border-radius: 1.25rem; }
        .fade-in { animation: fadeIn 0.5s ease-out;}
        @keyframes fadeIn {
            from{ opacity:0; transform:translateY(16px);}
            to{ opacity:1; transform:translateY(0);}
        }
    </style>
</head>
<body class="font-sans">
<div class="flex min-h-screen items-center justify-center px-4 py-6 bg-transparent">
    <div class="w-full max-w-md">
        <div class="glass-card p-8 rounded-2xl shadow-2xl fade-in">

            <!-- Header -->
            <div class="text-center mb-8">
                <h1>Reset Password</h1>
                <p class="text-gray-400 text-base">Enter your new password below.</p>
            </div>

            <!-- Success Message -->
            <?php if (!empty($success_message)): ?>
                <div class="alert-success rounded-lg p-4 mb-6 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-purple-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <span>
                        <?= htmlspecialchars($success_message) ?>
                        <div class="mt-4">
                            <a href="login.php" class="inline-block text-pink-600 hover:underline font-medium">Go to Login Page</a>
                        </div>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="alert-error rounded-lg p-4 mb-6 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-pink-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span>
                        <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
                        <div class="mt-4">
                            <a href="forgot_pass.php" class="inline-block text-pink-600 hover:underline font-medium">Request New Reset Link</a>
                        </div>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Password Reset Form -->
            <?php if ($token_valid): ?>
                <form method="POST" class="space-y-6" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div>
                        <label for="password" class="block text-sm font-medium mb-2">New Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            minlength="<?= MIN_PASSWORD_LENGTH ?>"
                            class="form-input w-full px-4 py-3 rounded-lg focus:outline-none"
                            placeholder="Enter your new password"
                        >
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium mb-2">Confirm New Password</label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            required
                            minlength="<?= MIN_PASSWORD_LENGTH ?>"
                            class="form-input w-full px-4 py-3 rounded-lg focus:outline-none"
                            placeholder="Confirm your new password"
                        >
                    </div>
                    <button
                        type="submit"
                        class="btn-primary w-full px-6 py-3 font-semibold shadow-sm hover:shadow-md transition rounded-full"
                        id="submitBtn"
                    >
                        Update Password
                    </button>
                </form>
            <?php endif; ?>

            <!-- Footer links -->
            <div class="mt-8 pt-6 border-t border-gray-200 text-center">
                <a href="login.php" class="text-gray-400 hover:text-pink-600 transition-colors duration-200">Back to Login</a>
            </div>
        </div>
    </div>
</div>
<script>
    // Live match validation for passwords
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    function validatePasswords() {
        if (password && confirmPassword && password.value !== confirmPassword.value) {
            confirmPassword.setCustomValidity('Passwords do not match');
        } else if (confirmPassword) {
            confirmPassword.setCustomValidity('');
        }
    }
    if (password && confirmPassword) {
        password.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);
    }
    // Loading indicator
    document.getElementById('resetForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        if (!btn.disabled) {
            btn.disabled = true;
            btn.textContent = "Updating...";
        }
    });
    // Auto-hide messages
    setTimeout(() => {
        document.querySelectorAll('.alert-success, .alert-error').forEach(el => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        });
    }, 7000);
</script>
</body>
</html>