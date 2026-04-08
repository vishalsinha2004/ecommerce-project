<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/email_handler.php'; // Added for OTP

// Set CSRF token if not present
if (empty($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}

$success = null;
$errors = [];
$input = [];
$showOtpModal = false; // Track if we should show OTP modal
$unverifiedUserId = null; // Store unverified user ID

// Handle registration POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX resend OTP request
    if (isset($_POST['ajax_resend_otp'])) {
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => ''];
        
        try {
            if (empty($_SESSION['unverified_user_id'])) {
                $response['message'] = "Session expired. Please register again.";
            } else {
                $userId = $_SESSION['unverified_user_id'];
                
                // Generate new OTP
                $newOtp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $otpHash = password_hash($newOtp, PASSWORD_DEFAULT);
                $expiry = date('Y-m-d H:i:s', time() + 300); // 5 minutes
                
                // Update OTP in database
                $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $stmt->execute([$otpHash, $expiry, $userId]);
                
                // Retrieve user email
                $stmt = $pdo->prepare("SELECT email, first_name FROM " . DB_PREFIX . "users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Send new OTP email
                    sendRegistrationOtp($user['email'], $user['first_name'], $newOtp);
                    $_SESSION['otp_resend_time'] = time(); // Track resend time
                    $response['success'] = true;
                    $response['message'] = "A new code has been sent to your email.";
                }
            }
        } catch (PDOException $e) {
            error_log("OTP resend error: " . $e->getMessage());
            $response['message'] = "Failed to resend OTP. Please try again.";
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Check for OTP verification request
    if (isset($_POST['verify_otp'])) {
        // OTP verification logic
        if (empty($_SESSION['unverified_user_id'])) {
            $errors[] = "OTP session expired. Please register again.";
        } else {
            $otp = trim($_POST['otp'] ?? '');
            $userId = $_SESSION['unverified_user_id'];
            
            if (empty($otp) || strlen($otp) !== 6 || !ctype_digit($otp)) {
                $errors[] = "Please enter a valid 6-digit OTP code.";
            } else {
                try {
                    // Retrieve OTP data from database
                    $stmt = $pdo->prepare("SELECT reset_token, reset_expires FROM " . DB_PREFIX . "users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $userData = $stmt->fetch();
                    
                    if (!$userData) {
                        $errors[] = "User not found. Please register again.";
                    } elseif (strtotime($userData['reset_expires']) < time()) {
                        // OTP expired - delete unverified user
                        $pdo->prepare("DELETE FROM " . DB_PREFIX . "users WHERE id = ?")->execute([$userId]);
                        unset($_SESSION['unverified_user_id']);
                        $errors[] = "OTP expired. Please register again.";
                    } elseif (!password_verify($otp, $userData['reset_token'])) {
                        $errors[] = "Invalid OTP code. Please try again.";
                    } else {
                        // OTP verified - activate user
                        $pdo->prepare("UPDATE " . DB_PREFIX . "users SET email_verified = 1, reset_token = NULL, reset_expires = NULL WHERE id = ?")->execute([$userId]);
                        
                        // Log user in
                        $stmt = $pdo->prepare("SELECT first_name, email FROM " . DB_PREFIX . "users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $user = $stmt->fetch();
                        
                        if ($user) {
                            $_SESSION['user_id'] = $userId;
                            $_SESSION['first_name'] = $user['first_name'];
                            $_SESSION['success_message'] = "Registration successful! Welcome, " . htmlspecialchars($user['first_name']);
                            
                            // Merge guest cart
                            if (isset($_SESSION['session_id'])) {
                                $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "cart SET user_id = ?, session_id = NULL WHERE session_id = ? AND user_id IS NULL");
                                $stmt->execute([$userId, session_id()]);
                            }
                            
                            session_regenerate_id(true);
                            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
                            
                            // Clear unverified session
                            unset($_SESSION['unverified_user_id']);
                            
                            header("Location: /ecommerce-project/index.php");
                            exit;
                        }
                    }
                } catch (PDOException $e) {
                    error_log("OTP verification error: " . $e->getMessage());
                    $errors[] = "A database error occurred. Please try again.";
                }
            }
        }
    } 
    // Handle initial registration form
    else {
        // CSRF check
        if (empty($_POST[CSRF_TOKEN_NAME]) || !hash_equals($_SESSION[CSRF_TOKEN_NAME], $_POST[CSRF_TOKEN_NAME])) {
            $errors[] = "Invalid request. Please try again.";
        } else {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            $address_1 = trim($_POST['address_1'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $country = trim($_POST['country'] ?? '');
            $postal_code = trim($_POST['postal_code'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $input = compact('first_name', 'last_name', 'email', 'address_1', 'city', 'state', 'country', 'postal_code', 'phone');

            // Validation (existing code remains)
            if (empty($first_name)) $errors[] = "First name is required.";
            if (empty($last_name)) $errors[] = "Last name is required.";
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $errors[] = "Please provide a valid email address.";
            if (empty($password)) $errors[] = "Password is required.";
            if ($password !== $password_confirm) $errors[] = "Passwords do not match.";
            if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters long.";

            // Validate new fields length
            if (strlen($address_1) > 255) $errors[] = "Address must be less than 255 characters.";
            if (strlen($city) > 100) $errors[] = "City must be less than 100 characters.";
            if (strlen($state) > 100) $errors[] = "State must be less than 100 characters.";
            if (strlen($country) > 100) $errors[] = "Country must be less than 100 characters.";
            if (strlen($postal_code) > 20) $errors[] = "Postal code must be less than 20 characters.";
            if (strlen($phone) > 20) $errors[] = "Phone must be less than 20 characters.";

            if (empty($errors)) {
                try {
                    // Check for existing email (including unverified)
                    $stmt = $pdo->prepare("SELECT id, email_verified FROM " . DB_PREFIX . "users WHERE email = ?");
                    $stmt->execute([$email]);
                    $existingUser = $stmt->fetch();
                    
                    if ($existingUser) {
                        // Delete if unverified account exists
                        if (!$existingUser['email_verified']) {
                            $pdo->prepare("DELETE FROM " . DB_PREFIX . "users WHERE id = ?")->execute([$existingUser['id']]);
                        } else {
                            $errors[] = "This email is already registered.";
                        }
                    }
                    
                    if (empty($errors)) {
                        // Generate OTP
                        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                        $otpExpiry = date('Y-m-d H:i:s', time() + 300); // 5 minutes
                        
                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
                        
                        // Insert user as unverified
                        $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "users (first_name, last_name, email, password, address_1, city, state, country, postal_code, phone, reset_token, reset_expires, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $first_name, 
                            $last_name, 
                            $email, 
                            $hashed_password,
                            $address_1,
                            $city,
                            $state,
                            $country,
                            $postal_code,
                            $phone,
                            $otpHash,
                            $otpExpiry,
                            0 // email_verified = false
                        ]);
                        
                        $user_id = $pdo->lastInsertId();
                        $_SESSION['unverified_user_id'] = $user_id;
                        $unverifiedUserId = $user_id;
                        $showOtpModal = true;
                        
                        // Send OTP email
                        sendRegistrationOtp($email, $first_name, $otp);
                        $_SESSION['otp_resend_time'] = time(); // Track initial send time
                    }
                } catch (PDOException $e) {
                    error_log("Registration error: " . $e->getMessage());
                    $errors[] = "A database error occurred. Please try again later.";
                    
                    // Cleanup if insertion failed
                    if (isset($user_id)) {
                        $pdo->prepare("DELETE FROM " . DB_PREFIX . "users WHERE id = ?")->execute([$user_id]);
                    }
                }
            }
        }
    }
}

// Function to send OTP email
function sendRegistrationOtp($email, $name, $otp) {
    $subject = "Verify Your Account - " . SITE_NAME;
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Email Verification</title>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #f8f9fa; padding: 20px; text-align: center; }
            .otp { font-size: 24px; font-weight: bold; letter-spacing: 3px; margin: 20px 0; }
            .note { background: #fff3cd; padding: 15px; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Email Verification</h2>
            </div>
            <p>Hello " . htmlspecialchars($name) . ",</p>
            <p>Thank you for registering with " . htmlspecialchars(SITE_NAME) . "!</p>
            <p>Your verification code is:</p>
            <div class='otp'>" . $otp . "</div>
            <p>This code will expire in 5 minutes.</p>
            <div class='note'>
                <p><strong>Security Note:</strong> Never share this code with anyone. Our support team will never ask you for this code.</p>
            </div>
            <p>If you didn't request this, please ignore this email.</p>
            <p>Best regards,<br>" . htmlspecialchars(SITE_NAME) . " Team</p>
        </div>
    </body>
    </html>";
    
    sendSecureEmail($email, $subject, $message);
}

// Cleanup if user leaves without verifying
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['unverified_user_id'])) {
    try {
        $pdo->prepare("DELETE FROM " . DB_PREFIX . "users WHERE id = ? AND email_verified = 0")->execute([$_SESSION['unverified_user_id']]);
        unset($_SESSION['unverified_user_id']);
    } catch (PDOException $e) {
        error_log("Cleanup error: " . $e->getMessage());
    }
}

// Meta
$page_title = "Register – " . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en" class="font-sans">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
      body {
        background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
        min-height: 100vh;
      }
      .glass-card {
        background: rgba(255, 255, 255, 0.92);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-radius: 1.25rem;
        box-shadow: 0 8px 40px 0 rgba(200,175,220,0.12);
        border: 1px solid #f3e6ff33;
      }
      .btn-primary {
        background: linear-gradient(90deg, #f7b9e3 0%, #c5b6f7 100%);
        color: #26263d;
        font-weight: 600;
        transition: all 0.3s cubic-bezier(.4,0,.2,1);
        box-shadow: 0 2px 16px 0 #c5b6f722;
      }
      .btn-primary:hover:not(:disabled) {
        filter: brightness(1.07);
        transform: scale(1.025);
        box-shadow: 0 12px 32px #eac1f4b0;
      }
      .input-style {
        background: rgba(255,255,255,0.92);
        border: 1.5px solid #ece4fa;
        color: #181830;
        transition: border 0.2s, box-shadow 0.2s;
      }
      .input-style:focus {
        border-color: #f7b9e3;
        box-shadow: 0 0 0 3px #f7b9e356;
      }
      h1, h2, h3, h4, h5 {
        color: #27273b;
      }
      .alert-error {
        background-color: #fbe7f9;
        border-left: 4px solid #ea699b;
        color: #812a63;
      }
      .rounded-2xl { border-radius: 1.25rem; }
      .shadow-2xl { box-shadow: 0 14px 48px 0 rgba(200,175,220,0.10);}
      .text-accent { color: #eb55aa;}
      .link-accent { color: #eb55aa; text-decoration: underline;}
      .link-accent:hover { color: #a259e1;}
      .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000;
      }
      .modal-content {
        background: white;
        border-radius: 1rem;
        padding: 2rem;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      }
      .toast {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background-color: #333;
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        z-index: 2000;
        opacity: 0;
        transition: opacity 0.3s;
      }
    </style>
</head>
<body class="font-sans">
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container mx-auto px-4 py-8 md:py-12">
    <div class="max-w-xl mx-auto">
        <section class="glass-card p-6 md:p-8 shadow-2xl">
            <h1 class="text-3xl md:text-4xl font-bold mb-2">Create Your Account</h1>
            <p class="text-gray-500 mb-6">Join us for a seamless and elegant shopping experience.</p>
            <?php if (!empty($errors)): ?>
                <div class="alert-error rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <svg class="w-6 h-6 mr-2 text-pink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <strong class="font-medium">Please fix the following:</strong>
                    </div>
                    <ul class="mt-2 ml-2 text-sm list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="registration-form" action="/ecommerce-project/auth/register.php" method="POST" class="space-y-4">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $_SESSION[CSRF_TOKEN_NAME] ?>">

                <div class="flex gap-4">
                    <div class="flex-1">
                        <label for="first_name" class="block text-sm font-medium mb-1">First Name *</label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?= htmlspecialchars($input['first_name'] ?? '') ?>"
                               required maxlength="32"
                               class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="given-name">
                    </div>
                    <div class="flex-1">
                        <label for="last_name" class="block text-sm font-medium mb-1">Last Name *</label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?= htmlspecialchars($input['last_name'] ?? '') ?>"
                               required maxlength="32"
                               class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="family-name">
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium mb-1">Email *</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($input['email'] ?? '') ?>"
                           required maxlength="100"
                           class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="email">
                </div>

                <!-- Address Fields -->
                <div>
                    <label for="address_1" class="block text-sm font-medium mb-1">Address Line 1</label>
                    <input type="text" id="address_1" name="address_1"
                           value="<?= htmlspecialchars($input['address_1'] ?? '') ?>"
                           maxlength="255"
                           class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="address-line1">
                </div>

                <div class="flex gap-4">
                    <div class="flex-1">
                        <label for="city" class="block text-sm font-medium mb-1">City</label>
                        <input type="text" id="city" name="city"
                               value="<?= htmlspecialchars($input['city'] ?? '') ?>"
                               maxlength="100"
                               class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="address-level2">
                    </div>
                    <div class="flex-1">
                        <label for="state" class="block text-sm font-medium mb-1">State / Province</label>
                        <input type="text" id="state" name="state"
                               value="<?= htmlspecialchars($input['state'] ?? '') ?>"
                               maxlength="100"
                               class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="address-level1">
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex-1">
                        <label for="country" class="block text-sm font-medium mb-1">Country</label>
                        <input type="text" id="country" name="country"
                               value="<?= htmlspecialchars($input['country'] ?? '') ?>"
                               maxlength="100"
                               class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="country-name">
                    </div>
                    <div class="flex-1">
                        <label for="postal_code" class="block text-sm font-medium mb-1">Postal Code</label>
                        <input type="text" id="postal_code" name="postal_code"
                               value="<?= htmlspecialchars($input['postal_code'] ?? '') ?>"
                               maxlength="20"
                               class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="postal-code">
                    </div>
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium mb-1">Phone Number</label>
                    <input type="tel" id="phone" name="phone"
                           value="<?= htmlspecialchars($input['phone'] ?? '') ?>"
                           maxlength="20"
                           class="input-style w-full px-4 py-3 rounded-lg focus:outline-none" autocomplete="tel">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium mb-1">Password * (min. 8 characters)</label>
                    <input type="password" id="password" name="password" required minlength="8"
                           class="input-style w-full px-4 py-3 rounded-lg focus:outline-none"
                           autocomplete="new-password">
                    <small class="mt-1 text-xs text-gray-400 block">Hint: Mix letters, numbers, and symbols for a stronger password.</small>
                </div>

                <div>
                    <label for="password_confirm" class="block text-sm font-medium mb-1">Confirm Password *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8"
                           class="input-style w-full px-4 py-3 rounded-lg focus:outline-none"
                           autocomplete="new-password">
                </div>

                <div class="flex items-center mt-3">
                    <input type="checkbox" id="agree" name="agree" required
                           class="h-4 w-4 text-pink-400 focus:ring-pink-400 border-gray-300 rounded">
                    <label for="agree" class="ml-2 block text-sm text-gray-700">
                        I accept the <a href="#" class="link-accent">Terms of Service</a> and <a href="#" class="link-accent">Privacy Policy</a>
                    </label>
                </div>

                <div class="pt-2">
                    <button type="submit" class="btn-primary w-full px-6 py-3 rounded-full text-black text-base font-semibold transition-colors duration-200 ease-in-out shadow-sm hover:shadow-md">Register Now</button>
                </div>
            </form>

            <div class="mt-6 text-center text-gray-500">
                <p>Already have an account? <a href="/ecommerce-project/auth/login.php" class="link-accent">Log In</a></p>
            </div>
        </section>
    </div>
</main>

<!-- OTP Verification Modal -->
<?php if ($showOtpModal): ?>
<div id="otp-modal" class="modal-overlay">
    <div class="modal-content glass-card">
        <form id="otp-form" action="/ecommerce-project/auth/register.php" method="POST">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $_SESSION[CSRF_TOKEN_NAME] ?>">
            <input type="hidden" name="verify_otp" value="1">
            
            <h2 class="text-2xl font-bold mb-4 text-center">Verify Your Email</h2>
            <p class="text-gray-600 mb-6 text-center">
                We sent a 6-digit code to <span class="font-medium"><?= htmlspecialchars($input['email'] ?? '') ?></span>
            </p>
            
            <div class="mb-6">
                <label for="otp" class="block text-sm font-medium mb-2">Verification Code</label>
                <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="\d{6}" 
                       class="input-style w-full px-4 py-3 rounded-lg focus:outline-none text-center text-xl tracking-widest"
                       required autocomplete="off">
                <p id="otp-error" class="text-red-500 text-sm mt-1"></p>
            </div>
            
            <div class="flex flex-col gap-3">
                <button type="submit" class="btn-primary px-6 py-3 rounded-full text-black font-semibold">
                    Verify Account
                </button>
                
                <div class="text-center mt-4">
                    <p class="text-gray-500">Didn't receive the code?</p>
                    <button type="button" id="resend-otp" class="text-pink-500 font-medium disabled:opacity-50" disabled>
                        Resend Code (<span id="resend-countdown">30</span>s)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="toast" class="toast"></div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const otpModal = document.getElementById('otp-modal');
    const resendBtn = document.getElementById('resend-otp');
    const resendCountdown = document.getElementById('resend-countdown');
    const otpForm = document.getElementById('otp-form');
    const registrationForm = document.getElementById('registration-form');
    const toast = document.getElementById('toast');
    
    // Global variable to track countdown interval
    let countdownInterval = null;
    
    // Show toast message
    function showToast(message, isSuccess = true) {
        toast.textContent = message;
        toast.style.backgroundColor = isSuccess ? '#4CAF50' : '#F44336';
        toast.style.opacity = '1';
        
        setTimeout(() => {
            toast.style.opacity = '0';
        }, 3000);
    }
    
    // Function to start countdown timer
    function startCountdown() {
        let countdown = 30;
        resendCountdown.textContent = countdown;
        resendBtn.disabled = true;
        resendBtn.innerHTML = `Resend Code (<span id="resend-countdown">${countdown}</span>s)`;
        
        countdownInterval = setInterval(() => {
            countdown--;
            const countdownSpan = document.getElementById('resend-countdown');
            if (countdownSpan) {
                countdownSpan.textContent = countdown;
            }
            
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                resendBtn.disabled = false;
                resendBtn.innerHTML = 'Resend Code';
            }
        }, 1000);
    }
    
    // Show modal if needed
    <?php if ($showOtpModal): ?>
        if (otpModal) {
            otpModal.style.display = 'flex';
            // Start initial countdown timer
            startCountdown();
        }
    <?php endif; ?>
    
    // Handle OTP form submission
    if (otpForm) {
        otpForm.addEventListener('submit', function(e) {
            const otpInput = document.getElementById('otp');
            const errorElement = document.getElementById('otp-error');
            
            if (!/^\d{6}$/.test(otpInput.value)) {
                e.preventDefault();
                errorElement.textContent = 'Please enter a valid 6-digit code';
                otpInput.focus();
            }
        });
    }
    
    // Handle OTP resend with AJAX
    if (resendBtn) {
        resendBtn.addEventListener('click', function() {
            if (!this.disabled) {
                // Disable button during request
                this.disabled = true;
                this.textContent = 'Sending...';
                
                // Create AJAX request
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '/ecommerce-project/auth/register.php');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    console.log('Response status:', xhr.status);
                    console.log('Response text:', xhr.responseText);
                    
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            console.log('Parsed response:', response);
                            
                            if (response.success) {
                                showToast(response.message, true);
                                
                                // Clear existing countdown if any
                                if (countdownInterval) {
                                    clearInterval(countdownInterval);
                                }
                                
                                // Start new countdown
                                startCountdown();
                                
                            } else {
                                showToast(response.message || 'Failed to resend code', false);
                                resendBtn.disabled = false;
                                resendBtn.innerHTML = 'Resend Code';
                            }
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                            console.error('Raw response:', xhr.responseText);
                            showToast('Error processing response', false);
                            resendBtn.disabled = false;
                            resendBtn.innerHTML = 'Resend Code';
                        }
                    } else {
                        showToast('Request failed. Please try again.', false);
                        resendBtn.disabled = false;
                        resendBtn.innerHTML = 'Resend Code';
                    }
                };
                
                xhr.onerror = function() {
                    showToast('Network error. Please try again.', false);
                    resendBtn.disabled = false;
                    resendBtn.innerHTML = 'Resend Code';
                };
                
                // Prepare data
                const data = new URLSearchParams();
                data.append('ajax_resend_otp', '1');
                data.append('<?= CSRF_TOKEN_NAME ?>', '<?= $_SESSION[CSRF_TOKEN_NAME] ?>');
                
                xhr.send(data);
            }
        });
    }
    
    // Cleanup unverified user if page is exited
    <?php if ($showOtpModal): ?>
        window.addEventListener('beforeunload', function(e) {
            // Notify user they might lose registration progress
            e.preventDefault();
            e.returnValue = '';
        });
        
        // Cleanup on page exit
        window.addEventListener('unload', function() {
            fetch('/ecommerce-project/auth/register.php?cleanup=1', {
                method: 'GET',
                keepalive: true // Ensure request completes
            });
        });
    <?php endif; ?>
    
    // OTP input formatting
    const otpInput = document.getElementById('otp');
    if (otpInput) {
        otpInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length > 6) {
                this.value = this.value.slice(0, 6);
            }
        });
    }
});
</script>
</body>
</html>