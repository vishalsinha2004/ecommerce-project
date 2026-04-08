<?php
/**
 * Logout Page - Dynamic Ecommerce Website
 * Women's Dresses Ecommerce Platform
 * 
 * Secure logout with session cleanup and user-friendly interface
 * Modern design matching the site's elegant aesthetic
 * 
 * @author Your Name
 * @version 1.0
 * @since 2025-01-31
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required files
require_once '../includes/config.php';
require_once '../includes/db.php';

// Initialize variables
$logout_success = false;
$user_name = '';
$redirect_url = BASE_URL;
$logout_message = '';

// Get user information before logout
if (isset($_SESSION['user_id'])) {
    $user_name = $_SESSION['first_name'] ?? 'User';
    
    try {
        // Log the logout activity
        $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "user_activities (user_id, activity_type, activity_description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $_SESSION['user_id'],
            'logout',
            'User logged out successfully',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Update last seen timestamp
        $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET last_seen = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        logMessage("User {$_SESSION['user_id']} logged out successfully", 'INFO');
        
    } catch (PDOException $e) {
        logMessage("Logout logging error: " . $e->getMessage(), 'ERROR');
    }
}

// Handle logout process
if (isset($_SESSION['user_id']) || isset($_SESSION['admin_id'])) {
    // Store user info for goodbye message
    if (empty($user_name) && isset($_SESSION['first_name'])) {
        $user_name = $_SESSION['first_name'];
    }
    
    // Clear remember me cookie if it exists
    if (isset($_COOKIE['remember_token'])) {
        try {
            // Remove remember token from database
            if (isset($_SESSION['user_id'])) {
                $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET remember_token = NULL WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            }
        } catch (PDOException $e) {
            logMessage("Remember token cleanup error: " . $e->getMessage(), 'ERROR');
        }
        
        // Clear the cookie
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
    
    // Migrate guest cart to user if needed (before logout)
    if (isset($_SESSION['user_id']) && isset($_SESSION['guest_cart_migrated']) && !$_SESSION['guest_cart_migrated']) {
        try {
            $session_id = session_id();
            $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "cart SET user_id = ?, session_id = NULL WHERE session_id = ? AND user_id IS NULL");
            $stmt->execute([$_SESSION['user_id'], $session_id]);
        } catch (PDOException $e) {
            logMessage("Cart migration error during logout: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Destroy session data
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy session
    session_destroy();
    
    // Start new session for flash messages
    session_start();
    session_regenerate_id(true);
    
    $logout_success = true;
    $logout_message = !empty($user_name) ? "Goodbye, {$user_name}! You have been logged out successfully." : "You have been logged out successfully.";
    
} else {
    // User is not logged in
    $logout_message = "You are not currently logged in.";
}

// Handle redirect parameter
if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
    $redirect_url = filter_var($_GET['redirect'], FILTER_VALIDATE_URL);
    if (!$redirect_url || !str_contains($redirect_url, $_SERVER['HTTP_HOST'])) {
        $redirect_url = BASE_URL;
    }
}

// Auto-redirect after delay (optional)
$auto_redirect = isset($_GET['auto']) && $_GET['auto'] === '1';
$redirect_delay = 3; // seconds

// Set page-specific variables for header
$page_title = 'Logout - ' . SITE_NAME;
$page_description = 'You have been logged out successfully from ' . SITE_NAME;

// Include header
include '../includes/header.php';
?>

<!-- Logout Page Content -->
<section class="min-h-screen bg-gradient-to-br from-secondary via-gray-light to-accent-lavender/20 flex items-center justify-center py-12 px-4">
    <div class="max-w-md w-full">
        
        <!-- Main Logout Card -->
        <div class="bg-white/80 backdrop-blur-sm rounded-2xl border border-border-light p-8 text-center shadow-lg">
            
            <!-- Logout Icon -->
            <div class="w-20 h-20 mx-auto mb-6 bg-gradient-to-br from-accent-pink/20 to-accent-lavender/20 rounded-full flex items-center justify-center">
                <?php if ($logout_success): ?>
                <!-- Success Icon -->
                <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <?php else: ?>
                <!-- Info Icon -->
                <svg class="w-10 h-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <?php endif; ?>
            </div>
            
            <!-- Logout Message -->
            <h1 class="text-2xl font-poppins font-bold text-text-primary mb-4">
                <?php if ($logout_success): ?>
                    Logged Out Successfully
                <?php else: ?>
                    Logout Status
                <?php endif; ?>
            </h1>
            
            <p class="text-text-secondary mb-8 leading-relaxed">
                <?php echo htmlspecialchars($logout_message); ?>
            </p>
            
            <!-- Auto-redirect countdown (if enabled) -->
            <?php if ($auto_redirect && $logout_success): ?>
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-blue-800 text-sm">
                    <span class="font-medium">Auto-redirecting in</span>
                    <span id="countdown" class="font-bold text-blue-600"><?php echo $redirect_delay; ?></span>
                    <span class="font-medium">seconds...</span>
                </p>
                <div class="mt-2 w-full bg-blue-200 rounded-full h-2">
                    <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-1000" style="width: 100%"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="space-y-4">
                
                <!-- Primary Actions -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <a href="/ecommerce-project/auth/login.php"
                       class="inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-accent-pink to-accent-lavender text-text-primary font-semibold rounded-xl hover:shadow-lg hover:scale-105 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-accent-pink/50">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                        Login Again
                    </a>
                    
                    <a href="/ecommerce-project/index.php" 
                       class="inline-flex items-center justify-center px-6 py-3 bg-white border-2 border-border-light text-text-primary font-medium rounded-xl hover:bg-gray-light hover:border-gray-300 hover:shadow-md transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                        </svg>
                        Go Home
                    </a>
                </div>
                
                <!-- Secondary Actions -->
                <div class="pt-4 border-t border-border-light">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <a href="/ecommerce-project/products/product_list.php" 
                           class="inline-flex items-center justify-center px-4 py-2 text-text-secondary hover:text-text-primary transition-colors duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l-1 12H6L5 9z"></path>
                            </svg>
                            Continue Shopping
                        </a>
                        
                        <a href="/ecommerce-project/auth/register.php" 
                           class="inline-flex items-center justify-center px-4 py-2 text-text-secondary hover:text-text-primary transition-colors duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                            Create Account
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Security Information -->
        <div class="mt-6 bg-white/60 backdrop-blur-sm rounded-xl border border-border-light p-4">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-text-primary mb-1">Secure Logout</h3>
                    <p class="text-xs text-text-secondary leading-relaxed">
                        Your session has been securely terminated and all login tokens have been cleared. 
                        <?php if (isset($_COOKIE['remember_token'])): ?>
                        Your "Remember Me" preference has also been reset for security.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity (if available) -->
        <?php if ($logout_success && !empty($_SESSION['last_login_time'])): ?>
        <div class="mt-4 bg-white/40 backdrop-blur-sm rounded-xl border border-border-light p-4">
            <div class="flex items-center space-x-3">
                <svg class="w-4 h-4 text-text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-xs text-text-secondary">
                    Last login: <?php echo date('M j, Y \a\t g:i A', strtotime($_SESSION['last_login_time'])); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</section>

<!-- Floating Elements (matching index.php design) -->
<div class="fixed top-20 left-10 w-32 h-32 bg-accent-mint/20 rounded-full blur-3xl animate-pulse opacity-60"></div>
<div class="fixed bottom-20 right-10 w-40 h-40 bg-accent-pink/15 rounded-full blur-3xl animate-pulse delay-1000 opacity-60"></div>
<div class="fixed top-1/2 right-20 w-24 h-24 bg-accent-lavender/25 rounded-full blur-2xl animate-bounce opacity-60"></div>

<!-- Enhanced Styling -->
<style>
/* Logout page specific styles */
.logout-card {
    animation: fadeInUp 0.6s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Countdown animation */
.countdown-pulse {
    animation: pulse 1s infinite;
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

/* Progress bar animation */
.progress-animate {
    transition: width 1s linear;
}

/* Button hover effects */
.logout-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
}

/* Responsive adjustments */
@media (max-width: 640px) {
    .logout-grid {
        grid-template-columns: 1fr;
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* High contrast mode */
@media (prefers-contrast: high) {
    .bg-white\/80 {
        background-color: #ffffff;
        border-width: 2px;
        border-color: #000000;
    }
}
</style>

<!-- JavaScript for enhanced functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Auto-redirect countdown functionality
    <?php if ($auto_redirect && $logout_success): ?>
    let countdown = <?php echo $redirect_delay; ?>;
    const countdownElement = document.getElementById('countdown');
    const progressBar = document.getElementById('progress-bar');
    const redirectUrl = '<?php echo addslashes($redirect_url); ?>';
    
    if (countdownElement && progressBar) {
        const timer = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            // Update progress bar
            const progressPercent = (countdown / <?php echo $redirect_delay; ?>) * 100;
            progressBar.style.width = progressPercent + '%';
            
            // Add pulse effect to countdown
            countdownElement.classList.add('countdown-pulse');
            setTimeout(() => {
                countdownElement.classList.remove('countdown-pulse');
            }, 500);
            
            if (countdown <= 0) {
                clearInterval(timer);
                
                // Show redirecting message
                countdownElement.parentElement.innerHTML = `
                    <div class="flex items-center justify-center space-x-2">
                        <div class="w-4 h-4 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                        <span class="font-medium text-blue-800">Redirecting...</span>
                    </div>
                `;
                
                // Redirect after a short delay
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 500);
            }
        }, 1000);
        
        // Allow user to cancel auto-redirect
        document.addEventListener('click', () => {
            clearInterval(timer);
            const autoRedirectElement = countdownElement.closest('.mb-6');
            if (autoRedirectElement) {
                autoRedirectElement.style.display = 'none';
            }
        });
    }
    <?php endif; ?>
    
    // Smooth entrance animation
    const logoutCard = document.querySelector('.bg-white\\/80');
    if (logoutCard) {
        logoutCard.classList.add('logout-card');
    }
    
    // Button click analytics (if analytics is implemented)
    document.querySelectorAll('a[href]').forEach(link => {
        link.addEventListener('click', function() {
            const destination = this.href;
            const text = this.textContent.trim();
            
            // Log button click for analytics
            if (typeof gtag !== 'undefined') {
                gtag('event', 'logout_page_interaction', {
                    'event_category': 'navigation',
                    'event_label': text,
                    'value': destination
                });
            }
            
            console.log(`Logout page: User clicked "${text}" -> ${destination}`);
        });
    });
    
    // Security message auto-hide (optional)
    setTimeout(() => {
        const securityMessage = document.querySelector('.bg-white\\/60');
        if (securityMessage) {
            securityMessage.style.opacity = '0.8';
        }
    }, 5000);
    
    // Keyboard navigation support
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            if (e.target.tagName === 'A') {
                e.target.click();
            }
        }
        
        // ESC key to go home
        if (e.key === 'Escape') {
            window.location.href = '<?php echo BASE_URL; ?>';
        }
    });
    
    console.log('🚪 Logout page loaded successfully');
});

// Prevent back button issues after logout
if (window.history && window.history.pushState) {
    window.history.pushState('', null, './');
    window.addEventListener('popstate', function() {
        window.history.pushState('', null, './');
    });
}
</script>

<?php
// Clear any remaining session data
if (isset($_SESSION['last_login_time'])) {
    unset($_SESSION['last_login_time']);
}

// Include footer
include '../includes/footer.php';
?>