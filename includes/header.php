<?php
/**
 * Global Header Component - Fixed PHP Syntax
 * Dynamic Ecommerce Website - Women's Dresses
 * 
 * Fixed syntax error with proper PHP tag handling
 * 
 * @author Your Name
 * @version 2.3
 * @since 2025-01-31
 */

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Get cart item count for logged-in users or session-based carts
$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM " . DB_PREFIX . "cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $cart_count = $result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Cart count error: " . $e->getMessage());
    }
} elseif (isset($_SESSION['session_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM " . DB_PREFIX . "cart WHERE session_id = ? AND user_id IS NULL");
        $stmt->execute([session_id()]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $cart_count = $result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Guest cart count error: " . $e->getMessage());
    }
}

// Get current page for active navigation highlighting
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$request_uri = $_SERVER['REQUEST_URI'];

// Get user name if logged in
$user_name = '';
if (isset($_SESSION['user_id']) && isset($_SESSION['first_name'])) {
    $user_name = $_SESSION['first_name'];
} elseif (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT first_name FROM " . DB_PREFIX . "users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_name = $user['first_name'] ?? '';
        $_SESSION['first_name'] = $user_name;
    } catch (PDOException $e) {
        error_log("User name fetch error: " . $e->getMessage());
    }
}

// Fetch categories for footer/sidebar display
$display_categories = [];
if (isset($pdo)) { // Check if the database connection exists
    try {
        // MODIFICATION: Added 'LIMIT 4' to show only four categories
        $stmt = $pdo->prepare(
            "SELECT id, name, slug 
             FROM " . DB_PREFIX . "categories 
             WHERE status = 'active' 
             ORDER BY sort_order ASC
             LIMIT 4"
        );
        $stmt->execute();
        $display_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Display categories fetch error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($page_description ?? 'Elegant women\'s dresses for every occasion. Shop the latest fashion trends with premium quality and style.'); ?>">
    <meta name="keywords" content="women's dresses, fashion, elegant dresses, online shopping, clothing, formal wear, casual dresses">
    <meta name="author" content="<?php echo SITE_NAME; ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#F8BBD9">
    
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title ?? SITE_NAME); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description ?? SITE_DESCRIPTION); ?>">
    <meta property="og:image" content="<?php echo BASE_URL; ?>/assets/images/og-image.jpg">
    <meta property="og:url" content="<?php echo BASE_URL . $_SERVER['REQUEST_URI']; ?>">
    <meta property="og:type" content="website">
    
    <!-- Security Headers -->
    <!-- <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https: blob:; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; connect-src 'self' https:;"> -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo ASSETS_URL; ?>/images/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo ASSETS_URL; ?>/images/apple-touch-icon.png">
    
    <!-- Preconnect for Performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', '-apple-system', 'BlinkMacSystemFont', 'sans-serif'],
                        'poppins': ['Poppins', '-apple-system', 'BlinkMacSystemFont', 'sans-serif'],
                    },
                    colors: {
                        'primary': '#FFFFFF',
                        'secondary': '#FAFAFA',
                        'gray-light': '#F8F9FA',
                        'gray-lighter': '#E9ECEF',
                        'accent-pink': '#a53860',
                        'accent-mint': '#B2F2BB',
                        'accent-lavender': '#ffa5ab',
                        'text-primary': '#212529',
                        'text-secondary': '#6C757D',
                        'border-light': '#DEE2E6',
                    },
                    screens: {
                        'xs': '475px',
                    },
                }
            }
        }
    </script>
    
    <!-- Custom CSS -->
    <style>
        .nav-link {
            position: relative;
            color: #6C757D;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 0.5rem 0;
        }
        
        .nav-link:hover {
            color: #212529;
            transform: translateY(-1px);
        }
        
        .nav-link.active {
            color: #212529;
            font-weight: 600;
        }
        
        .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #a53860, #ffa5ab);
            border-radius: 2px;
        }
        
        .logo-gradient {
            background: linear-gradient(135deg, #a53860, #ffa5ab);
        }
        
        .btn-gradient {
            background: linear-gradient(135deg, #a53860, #ffa5ab);
            transition: all 0.3s ease;
        }
        
        .btn-gradient:hover {
            background: linear-gradient(135deg, #a53860, #ffa5ab);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(248, 187, 217, 0.4);
        }
        
        .header-glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(222, 226, 230, 0.8);
        }
        
        .dropdown-menu {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(222, 226, 230, 0.5);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .search-input {
            background: rgba(248, 249, 250, 0.8);
        }
        
        .cart-badge {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Mobile optimizations */
        @media (max-width: 768px) {
            .mobile-menu-item {
                padding: 1rem 0;
                border-bottom: 1px solid rgba(222, 226, 230, 0.3);
            }
            
            .mobile-menu-item:last-child {
                border-bottom: none;
            }
        }
    </style>
    
    <title><?php echo htmlspecialchars($page_title ?? SITE_NAME . ' - ' . SITE_TAGLINE); ?></title>
</head>

<body class="font-inter bg-secondary text-text-primary antialiased">
    <!-- Skip to main content for accessibility -->
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 bg-text-primary text-white px-4 py-2 rounded-lg z-50">
        Skip to main content
    </a>

    <!-- Announcement Bar (Optional) -->
    <?php
    if (!defined('SHOW_ANNOUNCEMENT_BAR')) {
        define('SHOW_ANNOUNCEMENT_BAR', false);
    }
    if (SHOW_ANNOUNCEMENT_BAR):
    ?>
    <div class="bg-gradient-to-r from-[#a53860] to-[#ffa5ab] to-accent-mint text-text-primary py-2 px-4 text-center text-sm font-medium" id="announcement-bar">
        <div class="container mx-auto flex items-center justify-center">
            <span class="mr-2">🎉</span>
            <span>Free shipping on orders over $99! Use code: <strong>FREESHIP99</strong></span>
            <button onclick="dismissAnnouncement()" class="ml-4 hover:opacity-70 transition-opacity" aria-label="Close announcement">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Header -->
    <header class="sticky top-0 z-40 w-full header-glass transition-all duration-300" id="main-header">
        <div class="container mx-auto px-4">
            <nav class="flex items-center justify-between py-4" role="navigation">
                
                <!-- Left Section: Logo + Navigation -->
                <div class="flex items-center space-x-8">
                    <!-- Logo -->
                    <a href="/ecommerce-project/index.php" class="flex items-center group focus:outline-none focus:ring-[#a53860] focus:ring-2 focus:ring-opacity-30 rounded-lg" aria-label="<?php echo SITE_NAME; ?> - Go to homepage">
                        <div class="w-10 h-10 bg-gradient-to-br from-[#a53860] to-[#ffa5ab] rounded-xl flex items-center justify-center group-hover:scale-110 transition-all duration-300 shadow-lg">
                            <span class="text-white font-bold text-lg">E</span>
                        </div>
                        <!-- Site Name - Visible on mobile and desktop -->
                        <div class="ml-3">
                            <span class="text-lg sm:text-xl lg:text-2xl font-poppins font-bold text-text-primary group-hover:text-transparent group-hover:bg-clip-text group-hover:bg-gradient-to-r group-hover:from-[#a53860] group-hover:to-accent-lavender transition-all duration-300">
                                <?php echo SITE_NAME; ?>
                            </span>
                            <p class="hidden sm:block text-xs text-text-secondary font-medium tracking-wide uppercase">
                                <?php echo SITE_TAGLINE; ?>
                            </p>
                        </div>
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="hidden lg:flex items-center space-x-6">
                        <ul class="flex items-center space-x-6" role="menubar">
                            <li role="none">
                                <a href="/ecommerce-project/" 
                                   class="nav-link <?php echo ($current_page === 'index') ? 'active' : ''; ?>"
                                   role="menuitem">
                                    Home
                                </a>
                            </li>
                            <li role="none" class="relative group">
                                <a href="/ecommerce-project/products/product_list.php" 
                                   class="nav-link <?php echo (strpos($request_uri, '/products/') !== false) ? 'active' : ''; ?> flex items-center"
                                   role="menuitem">
                                    Dresses
                                    <svg class="w-4 h-4 ml-1 transition-transform duration-200 group-hover:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </a>
                                <!-- Dropdown Menu -->
                                <div class="absolute top-full left-0 mt-2 w-72 dropdown-menu rounded-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 transform translate-y-2 group-hover:translate-y-0">
                                    <div class="p-6">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <h3 class="font-semibold text-text-primary mb-3 text-sm uppercase tracking-wide">Categories</h3>
                                               <div class="space-y-2">
                                                    <?php if (!empty($display_categories)): ?>
                                                        <?php foreach ($display_categories as $cat): ?>
                                                            <a href="/ecommerce-project/products/product_list.php?category=<?php echo htmlspecialchars($cat['id']); ?>" 
                                                            class="block text-text-secondary hover:text-text-primary hover:bg-gray-light/50 px-3 py-2 rounded-lg transition-all duration-200">
                                                            <?php echo htmlspecialchars($cat['name']); ?>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <p class="text-text-secondary px-3">No categories to display.</p>
                                                    <?php endif; ?></div>
                                                </div>
                                            <div>
                                                <h3 class="font-semibold text-text-primary mb-3 text-sm uppercase tracking-wide">Collections</h3>
                                                <div class="space-y-2">
                                                    <a href="/ecommerce-project/products/product_list.php?featured=1" class="block text-text-secondary hover:text-text-primary hover:bg-gray-light/50 px-3 py-2 rounded-lg transition-all duration-200">Featured</a>
                                                    <a href="/ecommerce-project/products/product_list.php?new=1" class="block text-text-secondary hover:text-text-primary hover:bg-gray-light/50 px-3 py-2 rounded-lg transition-all duration-200">New Arrivals</a>
                                                    <a href="/ecommerce-project/products/product_list.php?sale=1" class="block text-text-secondary hover:text-text-primary hover:bg-gray-light/50 px-3 py-2 rounded-lg transition-all duration-200">On Sale</a>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="border-t border-border-light mt-4 pt-4">
                                            <a href="/ecommerce-project/products/product_list.php" class="flex items-center text-text-primary font-medium hover:text-accent-pink transition-colors duration-200 group">
                                                <span>View All Dresses</span>
                                                <svg class="w-4 h-4 ml-2 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            <li role="none">
                                <a href="/ecommerce-project/about.php" 
                                   class="nav-link <?php echo ($current_page === 'about') ? 'active' : ''; ?>"
                                   role="menuitem">
                                    About
                                </a>
                            </li>
                            <li role="none">
                                <a href="/ecommerce-project/contact.php" 
                                   class="nav-link <?php echo ($current_page === 'contact') ? 'active' : ''; ?>"
                                   role="menuitem">
                                    Contact
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Center Section: Search Bar (Desktop Only) -->
                <div class="hidden lg:flex flex-1 max-w-lg mx-8">
                    <form action="/ecommerce-project/products/product_list.php" method="GET" class="w-full relative group">
                        <input type="text" 
                               name="search" 
                               placeholder="Search for dresses..." 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                               class="w-full pl-12 pr-4 py-3 search-input border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 focus:border-accent-pink transition-all duration-300 text-sm"
                               autocomplete="off">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-text-secondary group-focus-within:text-accent-pink transition-colors duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                    </form>
                </div>

                <!-- Right Section: Actions -->
                <div class="flex items-center space-x-3">
                    
                    <!-- Desktop User Actions -->
                    <div class="hidden lg:flex items-center space-x-3">
                        <!-- Wishlist (Desktop Only) -->
                        <?php if (isLoggedIn()): ?>
                        <a href="/ecommerce-project/user/wishlist.php" 
                           class="p-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200 group"
                           aria-label="View wishlist">
                            <svg class="w-5 h-5 group-hover:scale-110 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                            </svg>
                        </a>
                        <?php endif; ?>

                        <!-- User Account (Desktop) -->
                        <?php if (isLoggedIn()): ?>
                        <div class="relative group">
                            <button class="flex items-center space-x-2 p-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200"
                                    aria-label="User account menu"
                                    id="user-menu-button">
                                <div class="w-8 h-8 bg-gradient-to-br from-accent-pink to-accent-lavender rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                    <?php echo strtoupper(substr($user_name ?: 'U', 0, 1)); ?>
                                </div>
                                <span class="text-sm font-medium">
                                    <?php echo $user_name ? 'Hi, ' . htmlspecialchars($user_name) : 'Account'; ?>
                                </span>
                                <svg class="w-4 h-4 transition-transform duration-200 group-hover:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>
                            
                            <!-- User Dropdown - HOVER ENABLED -->
                            <div class="absolute top-full right-0 mt-2 w-64 dropdown-menu rounded-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 transform translate-y-2 group-hover:translate-y-0"
                                 id="user-dropdown">
                                <div class="p-4">
                                    <div class="border-b border-border-light pb-3 mb-3">
                                        <p class="font-medium text-text-primary"><?php echo htmlspecialchars($user_name ?: 'User'); ?></p>
                                        <p class="text-sm text-text-secondary"><?php echo htmlspecialchars($_SESSION['email'] ?? 'Account'); ?></p>
                                    </div>
                                    <div class="space-y-1">
                                        <a href="/ecommerce-project/user/profile.php" class="flex items-center px-3 py-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200 group">
                                            <svg class="w-4 h-4 mr-3 group-hover:text-accent-pink" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                            </svg>
                                            My Profile
                                        </a>
                                        <a href="/ecommerce-project/user/orders.php" class="flex items-center px-3 py-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200 group">
                                            <svg class="w-4 h-4 mr-3 group-hover:text-accent-pink" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            Order History
                                        </a>
                                        <a href="/ecommerce-project/user/wishlist.php" class="flex items-center px-3 py-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200 group">
                                            <svg class="w-4 h-4 mr-3 group-hover:text-accent-pink" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                            </svg>
                                            Wishlist
                                        </a>
                                        <a href="/ecommerce-project/user/settings.php" class="flex items-center px-3 py-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200 group">
                                            <svg class="w-4 h-4 mr-3 group-hover:text-accent-pink" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                            Settings
                                        </a>
                                    </div>
                                    <div class="border-t border-border-light mt-3 pt-3">
                                        <a href="/ecommerce-project/auth/logout.php" class="flex items-center px-3 py-2 text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition-all duration-200 group">
                                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                            </svg>
                                            Sign Out
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center space-x-2">
                            <a href="/ecommerce-project/auth/login.php" 
                               class="px-4 py-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200">
                                Sign In
                            </a>
                            <a href="/ecommerce-project/auth/register.php" 
                               class="btn-gradient px-4 py-2 text-text-primary font-semibold rounded-xl">
                                Get Started
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- Shopping Cart (Always Visible) -->
                    <a href="/ecommerce-project/cart/cart.php" 
                       class="relative p-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200 group"
                       aria-label="Shopping cart">
                        <svg class="w-8 h-8 group-hover:scale-110 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l-1 7H6L5 9z"></path>
                        </svg>
                        <?php if ($cart_count > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-gradient-to-r from-accent-pink to-accent-lavender text-text-primary text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center cart-badge"
                              id="cart-count-badge">
                            <?php echo min($cart_count, 99); ?>
                        </span>
                        <?php endif; ?>
                    </a>

                    <!-- Mobile Menu Button -->
                    <button class="lg:hidden p-2 text-text-secondary hover:text-text-primary hover:bg-gray-light/50 rounded-lg transition-all duration-200" 
                            onclick="toggleMobileMenu()" 
                            aria-label="Toggle mobile menu"
                            id="mobile-menu-button">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="menu-icon">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </nav>
        </div>
    </header>

    <!-- Mobile Search Bar - OUTSIDE AND BELOW HEADER -->
    <div class="lg:hidden bg-white/90 backdrop-blur-sm border-b border-border-light sticky" style="top: 80px; z-index: 35;" id="mobile-search-bar">
        <div class="container mx-auto px-4 py-3">
            <form action="/ecommerce-project/products/product_list.php" method="GET" class="relative">
                <input type="text" 
                       name="search" 
                       placeholder="Search for dresses..." 
                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                       class="w-full pl-12 pr-4 py-3 search-input border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 focus:border-accent-pink transition-all duration-300"
                       autocomplete="off">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <button type="submit" class="absolute inset-y-0 right-0 pr-4 flex items-center text-text-secondary hover:text-accent-pink transition-colors duration-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <!-- Mobile Navigation Menu -->
    <div class="lg:hidden border-t border-border-light hidden" id="mobile-menu" style="position: fixed; top: 140px; left: 0; right: 0; z-index: 30; max-height: calc(100vh - 140px); overflow-y: auto;">
        <div class="bg-white/95 backdrop-blur-lg">
            <div class="container mx-auto px-4 py-4">
                
                <!-- Navigation Links -->
                <a href="/ecommerce-project/index.php" 
                   class="mobile-menu-item flex items-center justify-between text-text-primary hover:text-accent-pink transition-colors duration-200 <?php echo ($current_page === 'index') ? 'text-accent-pink font-medium' : ''; ?>">
                    <span>Home</span>
                </a>
                
                <!-- Dresses with dropdown for mobile -->
                <div class="mobile-menu-item">
                    <div class="flex items-center justify-between w-full">
                        <a href="/ecommerce-project/products/product_list.php" 
                           class="flex-1 text-text-primary hover:text-accent-pink transition-colors duration-200 <?php echo (strpos($request_uri, '/products/') !== false) ? 'text-accent-pink font-medium' : ''; ?>">
                            Dresses
                        </a>
                        <button class="p-2 text-text-primary focus:outline-none" 
                                onclick="toggleMobileDressesDropdown(event)" 
                                aria-expanded="false"
                                aria-controls="mobile-dresses-dropdown">
                            <svg class="w-4 h-4 transition-transform duration-200" id="dresses-dropdown-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                    </div>
                    <ul id="mobile-dresses-dropdown" class="pl-4 hidden mt-2 space-y-3">
                        <li>
                            <h3 class="font-semibold text-text-primary text-sm uppercase tracking-wide">Categories</h3>
                            <div class="space-y-1 mt-1">
                                 <?php if (!empty($display_categories)): ?>
            <?php foreach ($display_categories as $cat): ?>
                <a href="/ecommerce-project/products/product_list.php?category=<?php echo htmlspecialchars($cat['id']); ?>" 
                   class="block text-text-secondary hover:text-text-primary hover:bg-gray-light/50 px-3 py-2 rounded-lg transition-all duration-200">
                   <?php echo htmlspecialchars($cat['name']); ?>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-text-secondary px-3">No categories to display.</p>
        <?php endif; ?></div>
                        </li>
                        <li>
                            <h3 class="font-semibold text-text-primary text-sm uppercase tracking-wide mt-3">Collections</h3>
                            <div class="space-y-1 mt-1">
                                <a href="/ecommerce-project/products/product_list.php?featured=1" class="block text-text-secondary hover:text-text-primary pl-2 py-1 transition-colors duration-200">Featured</a>
                                <a href="/ecommerce-project/products/product_list.php?new=1" class="block text-text-secondary hover:text-text-primary pl-2 py-1 transition-colors duration-200">New Arrivals</a>
                                <a href="/ecommerce-project/products/product_list.php?sale=1" class="block text-text-secondary hover:text-text-primary pl-2 py-1 transition-colors duration-200">On Sale</a>
                            </div>
                        </li>
                        <li class="pt-2">
                            <a href="/ecommerce-project/products/product_list.php" class="flex items-center text-text-primary font-medium hover:text-accent-pink transition-colors duration-200 group">
                                <span>View All Dresses</span>
                                <svg class="w-4 h-4 ml-2 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <a href="/ecommerce-project/about.php" 
                   class="mobile-menu-item flex items-center justify-between text-text-primary hover:text-accent-pink transition-colors duration-200 <?php echo ($current_page === 'about') ? 'text-accent-pink font-medium' : ''; ?>">
                    <span>About</span>
                    <?php if ($current_page === 'about'): ?>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    <?php endif; ?>
                </a>
                
                <a href="<?php echo BASE_URL; ?>/contact.php" 
                   class="mobile-menu-item flex items-center justify-between text-text-primary hover:text-accent-pink transition-colors duration-200 <?php echo ($current_page === 'contact') ? 'active text-accent-pink font-medium' : ''; ?>">
                    <span>Contact</span>
                    <?php if ($current_page === 'contact'): ?>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    <?php endif; ?>
                </a>
                
                <!-- User Actions in Mobile Menu -->
                <?php if (isLoggedIn()): ?>
                <div class="border-t border-border-light pt-4 mt-4">
                    <!-- User Profile Card -->
                    <div class="flex items-center mb-4 p-3 bg-gray-light/50 rounded-lg">
                        <div class="w-10 h-10 bg-gradient-to-br from-accent-pink to-accent-lavender rounded-full flex items-center justify-center text-white font-semibold mr-3">
                            <?php echo strtoupper(substr($user_name ?: 'U', 0, 1)); ?>
                        </div>
                        <div>
                            <p class="font-medium text-text-primary text-sm"><?php echo htmlspecialchars($user_name ?: 'User'); ?></p>
                            <p class="text-xs text-text-secondary"><?php echo htmlspecialchars($_SESSION['email'] ?? 'Account'); ?></p>
                        </div>
                    </div>
                    
                    <!-- Wishlist -->
                    <a href="<?php echo BASE_URL; ?>/user/wishlist.php" class="mobile-menu-item flex items-center text-text-primary hover:text-accent-pink transition-colors duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                        </svg>
                        <span>Wishlist</span>
                    </a>
                    
                    <!-- Profile -->
                    <a href="<?php echo BASE_URL; ?>/user/profile.php" class="mobile-menu-item flex items-center text-text-primary hover:text-accent-pink transition-colors duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <span>My Profile</span>
                    </a>
                    
                    <!-- Order History -->
                    <a href="<?php echo BASE_URL; ?>/user/orders.php" class="mobile-menu-item flex items-center text-text-primary hover:text-accent-pink transition-colors duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Order History</span>
                    </a>
                    
                    <!-- Settings -->
                    <a href="<?php echo BASE_URL; ?>/user/settings.php" class="mobile-menu-item flex items-center text-text-primary hover:text-accent-pink transition-colors duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        <span>Settings</span>
                    </a>
                    
                    <!-- Logout -->
                    <a href="/ecommerce-project/auth/logout.php" class="mobile-menu-item flex items-center text-red-600 hover:text-red-700 transition-colors duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                        </svg>
                        <span>Sign Out</span>
                    </a>
                </div>
                <?php else: ?>
                <div class="border-t border-border-light pt-4 mt-4 space-y-2">
                    <a href="/ecommerce-project/auth/login.php" 
                       class="mobile-menu-item flex items-center text-text-primary hover:text-accent-pink transition-colors duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                        <span>Sign In</span>
                    </a>
                    <a href="/ecommerce-project/auth/register.php" 
                       class="mobile-menu-item btn-gradient text-text-primary font-semibold text-center rounded-xl py-3 block">
                        Create Account
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Content Start -->
    <main id="main-content" role="main" class="min-h-screen">

<script>
// Global variables
let mobileMenuOpen = false;

// CSRF token for AJAX requests
window.csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';

// Mobile menu toggle
function toggleMobileMenu() {
    const mobileMenu = document.getElementById('mobile-menu');
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const menuIcon = document.getElementById('menu-icon');
    
    mobileMenuOpen = !mobileMenuOpen;
    
    if (mobileMenuOpen) {
        mobileMenu.classList.remove('hidden');
        mobileMenuButton.setAttribute('aria-expanded', 'true');
        menuIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>';
        document.body.style.overflow = 'hidden';
    } else {
        mobileMenu.classList.add('hidden');
        mobileMenuButton.setAttribute('aria-expanded', 'false');
        menuIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>';
        document.body.style.overflow = 'auto';
    }
}

// Toggle dresses dropdown in mobile menu
function toggleMobileDressesDropdown(event) {
    event.preventDefault();
    event.stopPropagation();
    const button = event.currentTarget;
    const dropdown = document.getElementById('mobile-dresses-dropdown');
    const arrow = button.querySelector('svg');
    const isHidden = dropdown.classList.toggle('hidden');
    arrow.style.transform = isHidden ? '' : 'rotate(180deg)';
    button.setAttribute('aria-expanded', !isHidden);
}

// Close mobile menu when clicking outside
document.addEventListener('click', function(event) {
    const mobileMenu = document.getElementById('mobile-menu');
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    
    if (mobileMenuOpen && !mobileMenu.contains(event.target) && !mobileMenuButton.contains(event.target)) {
        toggleMobileMenu();
    }
});

// Close menus on escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        if (mobileMenuOpen) {
            toggleMobileMenu();
        }
    }
});

// Header scroll effect
let lastScrollTop = 0;
window.addEventListener('scroll', function() {
    const header = document.getElementById('main-header');
    const mobileSearchBar = document.getElementById('mobile-search-bar');
    const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
    
    if (currentScroll > lastScrollTop && currentScroll > 100) {
        // Scrolling down - hide header and search
        header.style.transform = 'translateY(-100%)';
        if (mobileSearchBar) {
            mobileSearchBar.style.transform = 'translateY(-100%)';
        }
    } else {
        // Scrolling up - show header and search
        header.style.transform = 'translateY(0)';
        if (mobileSearchBar) {
            mobileSearchBar.style.transform = 'translateY(0)';
        }
        
        // Add shadow when scrolled
        if (currentScroll > 50) {
            header.classList.add('shadow-lg');
        } else {
            header.classList.remove('shadow-lg');
        }
    }
    
    lastScrollTop = currentScroll <= 0 ? 0 : currentScroll;
}, { passive: true });

// Dismiss announcement bar
function dismissAnnouncement() {
    const announcementBar = document.getElementById('announcement-bar');
    if (announcementBar) {
        announcementBar.style.transform = 'translateY(-100%)';
        announcementBar.style.opacity = '0';
        setTimeout(() => {
            announcementBar.remove();
        }, 300);
        localStorage.setItem('announcement-dismissed', 'true');
    }
}

// Check if announcement was previously dismissed
document.addEventListener('DOMContentLoaded', function() {
    const announcementBar = document.getElementById('announcement-bar');
    if (announcementBar && localStorage.getItem('announcement-dismissed') === 'true') {
        announcementBar.remove();
    }
});

// Update cart count function
function updateCartCount(count) {
    const cartBadge = document.getElementById('cart-count-badge');
    if (cartBadge) {
        if (count > 0) {
            cartBadge.textContent = count > 99 ? '99+' : count;
            cartBadge.style.display = 'flex';
        } else {
            cartBadge.style.display = 'none';
        }
    }
}

console.log('✨ ' + '<?php echo SITE_NAME; ?>' + ' header loaded successfully!');
</script>