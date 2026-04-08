<?php
/**
 * Payment Failed Page
 */

session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

// Get error details
$error_type = $_GET['error'] ?? 'unknown';
$error_message = $_SESSION['payment_error'] ?? 'Your payment could not be processed.';

// Clear the error from session after displaying
unset($_SESSION['payment_error']);

// Map error types to user-friendly messages
$error_messages = [
    'signature_verification_failed' => 'Payment verification failed. Please contact support.',
    'processing_error' => 'There was an error processing your payment.',
    'missing_data' => 'Required payment information was missing.',
    'unknown' => 'An unknown error occurred during payment processing.'
];

$user_message = $error_messages[$error_type] ?? $error_messages['unknown'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed - <?php echo SITE_NAME; ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .shake {
            animation: shake 0.5s ease-in-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <a href="<?php echo BASE_URL; ?>" class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-pink-500 rounded-full"></div>
                    <span class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?></span>
                </a>
                <div class="flex items-center space-x-4">
                    <a href="<?php echo BASE_URL; ?>" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-home"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow py-8">
        <div class="container mx-auto px-4 max-w-2xl">
            <!-- Payment Failed Card -->
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden border border-red-100">
                <!-- Header -->
                <div class="gradient-bg px-6 py-8 text-center">
                    <div class="w-20 h-20 mx-auto mb-4 bg-white rounded-full flex items-center justify-center shake">
                        <i class="fas fa-times-circle text-red-500 text-4xl"></i>
                    </div>
                    <h1 class="text-3xl font-bold text-white mb-2">Payment Failed</h1>
                    <p class="text-red-100 text-lg">We couldn't process your payment</p>
                </div>

                <!-- Content -->
                <div class="p-6 md:p-8">
                    <!-- Error Details -->
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-exclamation-triangle text-red-500 mt-1"></i>
                            <div>
                                <h3 class="font-semibold text-red-800 mb-1">What happened?</h3>
                                <p class="text-red-700 text-sm"><?php echo htmlspecialchars($user_message); ?></p>
                                <?php if (ENVIRONMENT === 'development' && !empty($error_message)): ?>
                                    <p class="text-red-600 text-xs mt-2 font-mono bg-red-100 p-2 rounded">
                                        <?php echo htmlspecialchars($error_message); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Next Steps -->
                    <div class="space-y-4 mb-8">
                        <h3 class="font-semibold text-gray-800 text-lg">What to do next?</h3>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <!-- Try Again -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-center space-x-3 mb-2">
                                    <i class="fas fa-redo-alt text-blue-500"></i>
                                    <h4 class="font-semibold text-blue-800">Try Again</h4>
                                </div>
                                <p class="text-blue-700 text-sm">Check your payment details and try the payment again.</p>
                            </div>

                            <!-- Contact Support -->
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <div class="flex items-center space-x-3 mb-2">
                                    <i class="fas fa-headset text-green-500"></i>
                                    <h4 class="font-semibold text-green-800">Get Help</h4>
                                </div>
                                <p class="text-green-700 text-sm">Contact our support team for immediate assistance.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="<?php echo BASE_URL; ?>/cart/checkout.php" 
                           class="flex-1 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200 transform hover:scale-105 text-center">
                            <i class="fas fa-credit-card mr-2"></i>
                            Try Payment Again
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/contact.php" 
                           class="flex-1 bg-gray-800 hover:bg-gray-900 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200 text-center">
                            <i class="fas fa-envelope mr-2"></i>
                            Contact Support
                        </a>
                    </div>

                    <!-- Quick Links -->
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <div class="flex flex-wrap justify-center gap-6 text-sm">
                            <a href="<?php echo BASE_URL; ?>" class="text-gray-600 hover:text-gray-900 transition-colors">
                                <i class="fas fa-home mr-1"></i>
                                Back to Home
                            </a>
                            <a href="<?php echo BASE_URL; ?>/shop" class="text-gray-600 hover:text-gray-900 transition-colors">
                                <i class="fas fa-shopping-bag mr-1"></i>
                                Continue Shopping
                            </a>
                            <a href="<?php echo BASE_URL; ?>/cart" class="text-gray-600 hover:text-gray-900 transition-colors">
                                <i class="fas fa-shopping-cart mr-1"></i>
                                View Cart
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Help Section -->
            <div class="mt-8 grid md:grid-cols-3 gap-6 text-center">
                <!-- Security -->
                <div class="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                    <div class="w-12 h-12 mx-auto mb-3 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-shield-alt text-green-500 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-gray-800 mb-2">Secure Payment</h4>
                    <p class="text-gray-600 text-sm">All payments are encrypted and secure</p>
                </div>

                <!-- Support -->
                <div class="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                    <div class="w-12 h-12 mx-auto mb-3 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-clock text-blue-500 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-gray-800 mb-2">24/7 Support</h4>
                    <p class="text-gray-600 text-sm">We're here to help you anytime</p>
                </div>

                <!-- Money Back -->
                <div class="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                    <div class="w-12 h-12 mx-auto mb-3 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-undo-alt text-purple-500 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-gray-800 mb-2">Easy Returns</h4>
                    <p class="text-gray-600 text-sm">30-day return policy on all items</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white mt-12">
        <div class="container mx-auto px-4 py-8">
            <div class="text-center">
                <p class="text-gray-400">&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
                <div class="mt-4 flex justify-center space-x-6">
                    <a href="<?php echo FACEBOOK_URL; ?>" class="text-gray-400 hover:text-white transition-colors">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="<?php echo INSTAGRAM_URL; ?>" class="text-gray-400 hover:text-white transition-colors">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <a href="<?php echo TWITTER_URL; ?>" class="text-gray-400 hover:text-white transition-colors">
                        <i class="fab fa-twitter"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <!-- JavaScript for enhanced interactivity -->
    <script>
        // Add some interactive elements
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to cards
            const cards = document.querySelectorAll('.bg-white.rounded-lg');
            cards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.classList.add('shadow-md', 'transform', 'scale-105');
                });
                card.addEventListener('mouseleave', () => {
                    card.classList.remove('shadow-md', 'transform', 'scale-105');
                });
            });

            // Auto-hide development error after 10 seconds
            const devError = document.querySelector('.text-red-600.text-xs');
            if (devError) {
                setTimeout(() => {
                    devError.style.opacity = '0';
                    devError.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => devError.remove(), 500);
                }, 10000);
            }
        });
    </script>
</body>
</html>