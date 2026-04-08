<?php

/**
 * Homepage - Dynamic Ecommerce Website with Hero Carousel
 * Women's Dresses Ecommerce Platform
 * 
 * Updated with dynamic hero carousel linked to products
 * 
 * @author Your Name
 * @version 2.1
 * @since 2025-01-31
 */

// Initialize the application
require_once 'includes/config.php';
require_once 'includes/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $productId = filter_var($_POST['product_id'], FILTER_VALIDATE_INT);
    $color = trim($_POST['color'] ?? '');
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $quantity = 1;

    if ($productId) {
        try {
            if (!isset($_SESSION['user_id'])) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'Please login to add items to cart',
                        'redirect' => '/ecommerce-project/auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])
                    ]);
                    exit;
                } else {
                    header("Location: /ecommerce-project/auth/login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
                    exit;
                }
            }

            $userId = $_SESSION['user_id'];

            // Check if the same product with same color already exists in cart
            $existingItem = $db->fetchRow(
                "SELECT id, quantity FROM " . DB_PREFIX . "cart WHERE user_id = ? AND product_id = ? AND color = ?",
                [$userId, $productId, $color]
            );

            if ($existingItem) {
                $newQuantity = min($existingItem['quantity'] + $quantity, 10);
                $db->update(
                    'cart',
                    ['quantity' => $newQuantity, 'updated_at' => date('Y-m-d H:i:s')],
                    'id = :id',
                    ['id' => $existingItem['id']]
                );
            } else {
                $data = [
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'color' => $color,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $db->insert('cart', $data);
            }

            // Get updated cart count
            $cartCountResult = $db->fetchRow(
                "SELECT SUM(quantity) as total FROM " . DB_PREFIX . "cart WHERE user_id = ?",
                [$userId]
            );
            $cartCount = $cartCountResult['total'] ?? 0;

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Product added to cart!',
                    'cartcount' => $cartCount
                ]);
                exit;
            } else {
                $_SESSION['cart_success'] = 'Product added to cart!';
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            }
        } catch (Exception $e) {
            error_log("Cart add error: " . $e->getMessage());
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to add product to cart'
                ]);
                exit;
            } else {
                $_SESSION['cart_error'] = 'Failed to add product to cart';
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            }
        }
    }
}


// Handle wishlist actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_wishlist') {
    // Check CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $response = ['success' => false, 'message' => 'Security token mismatch'];
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        $_SESSION['wishlist_error'] = 'Security token mismatch';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    // User login check
    if (!isset($_SESSION['user_id'])) {
        $response = ['success' => false, 'message' => 'Please login to add items to wishlist', 'redirect' => true];
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        $redirect_url = urlencode($_SERVER['REQUEST_URI']);
        header("Location: " . BASE_URL . "/auth/login.php?redirect=" . $redirect_url);
        exit;
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    $userId = $_SESSION['user_id'];
    $response = ['success' => false, 'message' => 'Invalid request'];

    if ($productId > 0) {
        try {
            // Check if product exists and is active
            $product = $db->fetchRow(
                "SELECT id, name FROM " . DB_PREFIX . "products WHERE id = ? AND status = 'active'",
                [$productId]
            );

            if ($product) {
                // Check if already in wishlist
                $exists = $db->exists('wishlist', 'user_id = :user_id AND product_id = :product_id', [
                    'user_id' => $userId,
                    'product_id' => $productId
                ]);

                if ($exists) {
                    // Remove from wishlist
                    $result = $db->delete('wishlist', 'user_id = :user_id AND product_id = :product_id', [
                        'user_id' => $userId,
                        'product_id' => $productId
                    ]);
                    if ($result) {
                        $response = [
                            'success' => true,
                            'message' => 'Removed from wishlist!',
                            'action' => 'removed',
                            'in_wishlist' => false
                        ];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to remove from wishlist'];
                    }
                } else {
                    // Add to wishlist
                    $result = $db->insert('wishlist', [
                        'user_id' => $userId,
                        'product_id' => $productId,
                        'added_at' => date('Y-m-d H:i:s')
                    ]);
                    if ($result) {
                        $response = [
                            'success' => true,
                            'message' => 'Added to wishlist!',
                            'action' => 'added',
                            'in_wishlist' => true
                        ];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to add to wishlist'];
                    }
                }
            } else {
                $response = ['success' => false, 'message' => 'Product not found'];
            }
        } catch (Exception $e) {
            error_log("Wishlist toggle error: " . $e->getMessage());
            $response = ['success' => false, 'message' => 'Database error occurred'];
        }
    } else {
        $response = ['success' => false, 'message' => 'Invalid product'];
    }

    // Handle AJAX request
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Handle regular form submission
    if ($response['success']) {
        $_SESSION['wishlist_success'] = $response['message'];
    } else {
        $_SESSION['wishlist_error'] = $response['message'];
    }

    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}


// Set page-specific variables for header
$page_title = SITE_NAME . ' - ' . SITE_TAGLINE;
$page_description = SITE_DESCRIPTION;

// Fetch hero carousel slides (admin-configurable)
$hero_slides = [];
try {
    $hero_slides = $db->fetchAll(
        "SELECT id, name, slug, price, sale_price, image
         FROM ec_products
         WHERE status = 'active' AND image IS NOT NULL AND image != ''
         ORDER BY created_at DESC
         LIMIT 4"
    );

    foreach ($hero_slides as &$slide) {
        // Add product_id for consistency with your link code
        $slide['product_id'] = $slide['id'];

        // Add image URL
        if (!empty($slide['image'])) {
            $slide['image_url'] = BASE_URL . '/assets/images/' . $slide['image'];
        }

        // Add empty link_url for fallback
        $slide['link_url'] = '';
    }
    unset($slide);
} catch (Exception $e) {
    logMessage("Error fetching hero slides: " . $e->getMessage(), 'ERROR');
    $hero_slides = []; // Empty array as fallback
}


$wishlist_status = [];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $wishlist_status = $db->fetchAll(
        "SELECT product_id FROM " . DB_PREFIX . "wishlist WHERE user_id = ?",
        [$user_id]
    );
    // Convert to simple array of product IDs
    $wishlist_status = array_column($wishlist_status, 'product_id');
}

// Fetch featured products
$featured_products = [];
try {
    $featured_products = $db->fetchAll(
        "SELECT p.*, 
            CASE 
                WHEN p.sale_price IS NOT NULL AND p.sale_price < p.price 
                THEN p.sale_price 
                ELSE p.price 
            END as final_price,
            CASE 
                WHEN p.sale_price IS NOT NULL AND p.sale_price < p.price 
                THEN ROUND(((p.price - p.sale_price) / p.price) * 100) 
                ELSE 0 
            END as discount_percentage,
            COALESCE(AVG(t.rating), 0) AS average_rating,
            p.available_colors as color
         FROM " . DB_PREFIX . "products p
         LEFT JOIN " . DB_PREFIX . "testimonials t ON p.id = t.product_id AND t.status = 'approved'
         WHERE p.status = 'active' AND p.featured = 1 
         GROUP BY p.id
         ORDER BY p.sort_order ASC, p.created_at DESC 
         LIMIT :limit",
        ['limit' => FEATURED_PRODUCTS_COUNT]
    );
} catch (Exception $e) {
    logMessage("Error fetching featured products: " . $e->getMessage(), 'ERROR');
}

// Fetch new arrivals
$new_arrivals = [];
try {
    $new_arrivals = $db->fetchAll(
        "SELECT p.*, 
            CASE 
                WHEN p.sale_price IS NOT NULL AND p.sale_price < p.price 
                THEN p.sale_price 
                ELSE p.price 
            END as final_price,
            COALESCE(AVG(t.rating), 0) AS average_rating,
            p.available_colors as color
         FROM " . DB_PREFIX . "products p
         LEFT JOIN " . DB_PREFIX . "testimonials t ON p.id = t.product_id AND t.status = 'approved'
         WHERE p.status = 'active' 
         AND p.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
         GROUP BY p.id
         ORDER BY p.created_at DESC 
         LIMIT 8",
        ['days' => NEW_ARRIVALS_DAYS]
    );
} catch (Exception $e) {
    logMessage("Error fetching new arrivals: " . $e->getMessage(), 'ERROR');
}
// Fetch categories for quick navigation
$categories = [];
try {
    // We select c.id explicitly to ensure we have it for the link
    $categories = $db->fetchAll(
        "SELECT c.*, COUNT(p.id) as product_count
         FROM " . DB_PREFIX . "categories c
         LEFT JOIN " . DB_PREFIX . "products p ON c.id = p.category_id AND p.status = 'active'
         WHERE c.status = 'active' 
         GROUP BY c.id
         ORDER BY c.name ASC
         LIMIT 6"
    );
} catch (Exception $e) {
    // If table columns like 'show_on_homepage' don't exist, this simpler query works safely
    logMessage("Error fetching categories: " . $e->getMessage(), 'ERROR');
}

// Fetch testimonials
$testimonials = [];
try {
    $testimonials = $db->fetchAll(
        "SELECT * FROM " . DB_PREFIX . "testimonials 
         WHERE status = 'approved' 
         ORDER BY sort_order ASC, created_at DESC 
         LIMIT 6"
    );
} catch (Exception $e) {
    logMessage("Error fetching testimonials: " . $e->getMessage(), 'ERROR');
}

// Fetch active marquee promotion
$active_marquee = null;
try {
    $active_marquee = $db->fetchRow(
        "SELECT * FROM " . DB_PREFIX . "promotions 
         WHERE type = 'marquee' AND is_active = 1 
         ORDER BY created_at DESC LIMIT 1"
    );
} catch (Exception $e) {
    logMessage("Error fetching marquee promotion: " . $e->getMessage(), 'ERROR');
}

// Fetch active banner promotion
$active_banner = null;
try {
    $active_banner = $db->fetchRow(
        "SELECT * FROM " . DB_PREFIX . "promotions 
         WHERE type = 'banner' AND is_active = 1 
         ORDER BY created_at DESC LIMIT 1"
    );
} catch (Exception $e) {
    logMessage("Error fetching banner promotion: " . $e->getMessage(), 'ERROR');
}

// Include header
include 'includes/header.php';
?>

<!-- Dynamic Marquee Section -->
<?php if ($active_marquee): ?>
    <marquee
        behavior="scroll"
        direction="left"
        scrollamount="10"
        class="mt-4 h-12 bg-[#8A1935] text-lg text-white flex items-center font-serif">
        <p><?php echo htmlspecialchars($active_marquee['content']); ?></p>
    </marquee>
<?php endif; ?>

<!-- Hero Section with Carousel -->
<section class="relative bg-gradient-to-br from-secondary via-gray-light to-accent-lavender/20 overflow-hidden">
    <!-- Background Pattern -->
    <div class="absolute inset-0 opacity-5">
        <div class="absolute inset-0" style="background-image: url('data:image/svg+xml,%3Csvg width=" 60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg" %3E%3Cg fill="none" fill-rule="evenodd" %3E%3Cg fill="%23000000" fill-opacity="0.1" %3E%3Ccircle cx="7" cy="7" r="1" /%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"></div>
    </div>

    <!-- Center the container horizontally with max-width and mx-auto -->
    <div class="relative max-w-7xl mx-auto lg:ml-auto lg:mr-8 px-4 py-16 lg:py-24">

        <!-- Mobile Layout: Carousel First, Then Text -->
        <div class="lg:hidden">
            <!-- Mobile Carousel -->
            <div class="mb-12">
                <div class="relative w-full max-w-md mx-auto">
                    <!-- Carousel Container -->
                    <div class="relative overflow-hidden rounded-2xl shadow-2xl w-full max-w-sm aspect-[3/4]" id="mobile-hero-carousel">
                        <div class="flex transition-transform duration-500 ease-in-out" id="mobile-carousel-track">
                            <?php foreach ($hero_slides as $index => $slide): ?>
                                <div class="w-full flex-shrink-0 relative">
                                    <a href="<?php echo !empty($slide['product_id']) ? BASE_URL . '/products/product_detail.php?id=' . $slide['product_id'] : (!empty($slide['link_url']) ? $slide['link_url'] : '#'); ?>" class="block">
                                        <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($slide['image']); ?>"
                                            alt="<?php echo htmlspecialchars($slide['title'] ?? 'Hero slide'); ?>"
                                            class="w-full max-w-sm aspect-[3/4] object-cover"
                                            loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>">

                                        <!-- Mobile Slide Overlay -->
                                        <?php if (!empty($slide['title']) || !empty($slide['button_text'])): ?>
                                            <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent flex items-end">
                                                <div class="p-6 text-white">
                                                    <?php if (!empty($slide['title'])): ?>
                                                        <h3 class="text-lg font-semibold mb-2"><?php echo htmlspecialchars($slide['title']); ?></h3>
                                                    <?php endif; ?>
                                                    <?php if (!empty($slide['subtitle'])): ?>
                                                        <p class="text-sm mb-3 opacity-90"><?php echo htmlspecialchars($slide['subtitle']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($slide['button_text'])): ?>
                                                        <span class="inline-block px-4 py-2 bg-white/20 backdrop-blur-sm text-white text-sm font-medium rounded-lg">
                                                            <?php echo htmlspecialchars($slide['button_text']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Mobile Carousel Indicators -->
                        <?php if (count($hero_slides) > 1): ?>
                            <div class="absolute bottom-4 left-1/2 transform -translate-x-1/2 flex space-x-2">
                                <?php foreach ($hero_slides as $index => $slide): ?>
                                    <button class="w-2 h-2 rounded-full bg-white/50 hover:bg-white/80 transition-colors duration-200 mobile-carousel-indicator <?php echo $index === 0 ? 'bg-white' : ''; ?>"
                                        data-slide="<?php echo $index; ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Mobile Hero Content -->
            <div class="text-center">
                <h1 class="text-4xl font-poppins font-bold text-gray-900 mb-6 leading-tight">
                    Elegant Fashion
                    <span class="block text-transparent bg-clip-text bg-gradient-to-r from-[#a53860] to-[#ffa5ab]">
                        Made Simple
                    </span>
                </h1>

                <p class="text-lg text-gray-600 mb-8 leading-relaxed">
                    Discover our curated collection of women's dresses that blend timeless elegance with contemporary style. Every piece tells a story of craftsmanship and beauty.
                </p>

                <div class="flex flex-col gap-4">
                    <a href="<?php echo BASE_URL; ?>/products/product_list.php"
                        class="px-8 py-4 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-semibold rounded-xl hover:shadow-xl hover:scale-105 transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-[#F2B9C7]/30">
                        Shop Collection
                    </a>
                    <a href="<?php echo BASE_URL; ?>/about.php"
                        class="px-8 py-4 bg-white/80 backdrop-blur-sm border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-white hover:shadow-lg transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-gray-200">
                        Our Story
                    </a>
                </div>

                <!-- Hero Stats -->
                <div class="flex justify-center gap-8 mt-12">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900">5K+</div>
                        <div class="text-sm text-gray-600">Happy Customers</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900">500+</div>
                        <div class="text-sm text-gray-600">Unique Designs</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900">98%</div>
                        <div class="text-sm text-gray-600">Satisfaction Rate</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop Layout: Text and Carousel Side by Side -->
        <div class="hidden lg:grid lg:grid-cols-2 gap-12 items-center">
            <!-- Desktop Hero Content (Left Side) -->
            <div class="text-center lg:text-left">
                <h1 class="text-4xl lg:text-6xl font-poppins font-bold text-gray-900 mb-6 leading-tight">
                    Elegant Fashion
                    <span class="block text-transparent bg-clip-text bg-gradient-to-r from-[#a53860] to-[#ffa5ab]">
                        Made Simple
                    </span>
                </h1>

                <p class="text-lg lg:text-xl text-gray-600 mb-8 max-w-lg mx-auto lg:mx-0 leading-relaxed">
                    Discover our curated collection of women's dresses that blend timeless elegance with contemporary style. Every piece tells a story of craftsmanship and beauty.
                </p>

                <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                    <a href="<?php echo BASE_URL; ?>/products/product_list.php"
                        class="px-8 py-4 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-semibold rounded-xl hover:shadow-xl hover:scale-105 transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-accent-pink/30">
                        Shop Collection
                    </a>
                    <a href="<?php echo BASE_URL; ?>/about.php"
                        class="px-8 py-4 bg-white/80 backdrop-blur-sm border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-white hover:shadow-lg transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-gray-200">
                        Our Story
                    </a>
                </div>

                <!-- Hero Stats -->
                <div class="flex justify-center lg:justify-start gap-8 mt-12">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900">5K+</div>
                        <div class="text-sm text-gray-600">Happy Customers</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900">500+</div>
                        <div class="text-sm text-gray-600">Unique Designs</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900">98%</div>
                        <div class="text-sm text-gray-600">Satisfaction Rate</div>
                    </div>
                </div>
            </div>

            <!-- Desktop Hero Carousel (Right Side) -->
            <div class="relative">
                <div class="relative z-10">
                    <!-- Carousel Container -->
                    <div class="relative overflow-hidden rounded-2xl shadow-2xl w-96 h-full" id="desktop-hero-carousel">
                        <div class="flex transition-transform duration-500 ease-in-out" id="desktop-carousel-track">
                            <?php foreach ($hero_slides as $index => $slide): ?>
                                <div class="w-full flex-shrink-0 relative">
                                    <a href="<?php echo !empty($slide['product_id']) ? BASE_URL . '/products/product_detail.php?id=' . $slide['product_id'] : (!empty($slide['link_url']) ? $slide['link_url'] : '#'); ?>" class="block">

                                        <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($slide['image']); ?>"
                                            alt="<?php echo htmlspecialchars($slide['title'] ?? 'Hero slide'); ?>"
                                            class="w-full max-w-md mx-auto lg:max-w-lg xl:max-w-xl h-96 lg:h-[500px] object-cover rounded-2xl"
                                            loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>">

                                        <!-- Desktop Slide Overlay -->
                                        <?php if (!empty($slide['title']) || !empty($slide['button_text'])): ?>
                                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent flex items-end rounded-2xl">
                                                <div class="p-8 text-white">
                                                    <?php if (!empty($slide['title'])): ?>
                                                        <h3 class="text-2xl font-bold mb-3"><?php echo htmlspecialchars($slide['title']); ?></h3>
                                                    <?php endif; ?>
                                                    <?php if (!empty($slide['subtitle'])): ?>
                                                        <p class="text-base mb-4 opacity-90"><?php echo htmlspecialchars($slide['subtitle']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($slide['button_text'])): ?>
                                                        <span class="inline-block px-6 py-3 bg-white/20 backdrop-blur-sm text-white font-medium rounded-xl hover:bg-white/30 transition-all duration-200">
                                                            <?php echo htmlspecialchars($slide['button_text']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Desktop Navigation Arrows -->
                        <?php if (count($hero_slides) > 1): ?>
                            <button class="absolute left-4 top-1/2 transform -translate-y-1/2 w-10 h-10 bg-white/20 backdrop-blur-sm rounded-full flex items-center justify-center text-white hover:bg-white/30 transition-all duration-200 desktop-carousel-prev" aria-label="Previous slide">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </button>
                            <button class="absolute right-4 top-1/2 transform -translate-y-1/2 w-10 h-10 bg-white/20 backdrop-blur-sm rounded-full flex items-center justify-center text-white hover:bg-white/30 transition-all duration-200 desktop-carousel-next" aria-label="Next slide">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>

                            <!-- Desktop Carousel Indicators -->
                            <div class="absolute bottom-4 left-1/2 transform -translate-x-1/2 flex space-x-2">
                                <?php foreach ($hero_slides as $index => $slide): ?>
                                    <button class="w-3 h-3 rounded-full bg-white/50 hover:bg-white/80 transition-colors duration-200 desktop-carousel-indicator <?php echo $index === 0 ? 'bg-white' : ''; ?>"
                                        data-slide="<?php echo $index; ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Floating Elements -->
                <div class="absolute top-10 -left-4 w-20 h-20 bg-accent-mint/30 rounded-full blur-xl animate-pulse"></div>
                <div class="absolute bottom-10 -right-4 w-32 h-32 bg-accent-pink/20 rounded-full blur-2xl animate-pulse delay-1000"></div>
                <div class="absolute top-1/2 -right-8 w-16 h-16 bg-accent-lavender/40 rounded-full blur-lg animate-bounce"></div>
            </div>
        </div>
    </div>

    <!-- Scroll Indicator -->
    <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
        </svg>
    </div>
</section>


<!-- Trust Indicators -->
<section class="py-12 bg-white">
    <div class="container mx-auto px-4">
        <div class="flex justify-center items-center space-x-8 lg:space-x-16 opacity-60">
            <div class="flex items-center space-x-2">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-sm font-medium">Free Shipping Over $99</span>
            </div>
            <div class="flex items-center space-x-2">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
                <span class="text-sm font-medium">7-Day Return</span>
            </div>
            <div class="flex items-center space-x-2">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
                <span class="text-sm font-medium">Secure Checkout</span>
            </div>
        </div>
    </div>
</section>

<!-- Dynamic Promotional Banner Section -->
<?php if ($active_banner): ?>
    <section class="w-full px-2 sm:px-4 lg:px-6 py-8">
        <div class="group relative border border-gray-200 bg-white rounded-3xl overflow-hidden shadow-lg hover:shadow-2xl transition-all duration-500 hover:-translate-y-3 hover:border-blue-400 w-full">

            <!-- Background pattern -->
            <div class="absolute inset-0 bg-gradient-to-br from-blue-50 via-transparent to-purple-50 opacity-50"></div>

            <!-- Content -->
            <div class="relative">
                <a href="<?php echo !empty($active_banner['link_url']) ? htmlspecialchars($active_banner['link_url']) : BASE_URL . '/products/product_list.php?sale=2'; ?>"
                    class="block relative overflow-hidden rounded-3xl">

                    <!-- Image with overlay gradient -->
                    <div class="relative">
                        <img src="<?= IMAGES_URL . '/' . htmlspecialchars($active_banner['image_url']) ?>"
                            alt="<?= htmlspecialchars($active_banner['title'] ?? 'Promotional Banner') ?>"
                            class="w-full h-auto object-cover max-h-96 sm:max-h-[28rem] lg:max-h-[32rem] transition-all duration-700 group-hover:scale-110">

                        <!-- Optional overlay for better text readability -->
                        <div class="absolute inset-0 bg-gradient-to-t from-black/20 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    </div>
                </a>
            </div>

            <!-- SALE Ribbon -->
            <div class="absolute top-4 right-4 bg-red-500 text-white px-3 py-1 text-xs font-bold rounded-full shadow-lg transform rotate-12 z-20">
                SALE
            </div>

            <!-- Decorative elements -->
            <div class="absolute top-0 left-0 w-16 h-16 sm:w-20 sm:h-20 lg:w-24 lg:h-24 bg-gradient-to-br from-blue-400 to-transparent rounded-br-full opacity-20"></div>
            <div class="absolute bottom-0 right-0 w-12 h-12 sm:w-16 sm:h-16 lg:w-20 lg:h-20 bg-gradient-to-tl from-purple-400 to-transparent rounded-tl-full opacity-20"></div>

        </div>
    </section>
<?php endif; ?>


<?php if (!empty($categories)): ?>
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl lg:text-4xl font-poppins font-bold text-gray-900 mb-4">
                    Shop by Category
                </h2>
                <p class="text-gray-600 max-w-2xl mx-auto">
                    Explore our carefully curated collections designed for every occasion and style preference.
                </p>
            </div>

            <div id="categoryGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-6">
                <?php foreach ($categories as $index => $category): ?>
                    <a href="<?php echo BASE_URL; ?>/products/product_list.php?category=<?php echo $category['id']; ?>"
                       class="group relative bg-white rounded-2xl p-6 text-center hover:shadow-xl transition-all duration-300 hover:scale-105 focus:outline-none focus:ring-4 focus:ring-accent-pink/30
                       <?php echo ($index >= 4) ? 'hidden md:block mobile-hidden-cat' : ''; ?> category-card">
                        
                        <div class="w-20 h-20 mx-auto mb-4 bg-gradient-to-br from-accent-pink/20 to-accent-lavender/20 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                            <?php if (!empty($category['icon'])): ?>
                                <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($category['icon']); ?>"
                                     alt="<?php echo htmlspecialchars($category['name']); ?>"
                                     class="w-20 h-20 object-contain" loading="lazy">
                            <?php else: ?>
                                <svg class="w-10 h-10 text-accent-pink" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                            <?php endif; ?>
                        </div>
                        
                        <h3 class="font-medium text-gray-900 mb-2"><?php echo htmlspecialchars($category['name']); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo $category['product_count']; ?> items</p>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (count($categories) > 4): ?>
                <div class="text-center mt-6 md:hidden">
                    <button id="showMoreBtn" onclick="toggleCategories()" class="px-4 py-2 bg-[#a53860] text-white rounded-full text-sm font-medium hover:bg-[#F2B9C7] transition">
                        Show More
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
        const showMoreBtn = document.getElementById('showMoreBtn');
        const hiddenCards = document.querySelectorAll('.category-card.hidden');

        showMoreBtn?.addEventListener('click', () => {
            hiddenCards.forEach(card => card.classList.remove('hidden'));
            showMoreBtn.style.display = 'none';
        });
    </script>
<?php endif; ?>

<!-- Featured Products Section -->
<?php if (!empty($featured_products)): ?>
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center mb-12">
                <div>
                    <h2 class="text-3xl lg:text-4xl font-poppins font-bold text-gray-900 mb-4">
                        Featured Collection
                    </h2>
                    <p class="text-gray-600">
                        Handpicked designs that define elegance and style.
                    </p>
                </div>
                <a href="<?php echo BASE_URL; ?>/products/product_list.php"
                    class="hidden lg:block px-6 py-3 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-medium rounded-xl hover:shadow-lg transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-[#F2B9C7]/30">
                    View All
                </a>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6">
                <?php foreach ($featured_products as $product):
                    $in_wishlist = in_array($product['id'], $wishlist_status);
                ?>
                    <div class="group relative bg-white rounded-2xl overflow-hidden border border-gray-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] flex flex-col min-h-[320px] max-w-[280px] mx-auto w-full">
                        <!-- Image Container -->
                        <div class="relative w-full aspect-[3/4] overflow-hidden flex-shrink-0">
                            <a href="/ecommerce-project/products/product_detail.php?id=<?php echo $product['id']; ?>">
                                <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                    alt="<?php echo htmlspecialchars($product['name']); ?>"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                    loading="lazy"
                                    onerror="this.src='<?php echo IMAGES_URL; ?>/placeholder.jpg'">
                            </a>

                            <!-- Sale Badge -->
                            <?php if ($product['discount_percentage'] > 0): ?>
                                <div class="absolute top-3 left-3 bg-red-500 text-white text-xs font-medium px-2 py-1 rounded-lg">
                                    -<?php echo $product['discount_percentage']; ?>%
                                </div>
                            <?php endif; ?>

                            <!-- Stock Status Badge -->
                            <?php if ($product['stock_quantity'] <= LOW_STOCK_THRESHOLD && $product['stock_quantity'] > 0): ?>
                                <div class="absolute bottom-3 left-3 bg-orange-500 text-white text-xs font-medium px-2 py-1 rounded-lg backdrop-blur-sm bg-opacity-90">
                                    Only <?php echo $product['stock_quantity']; ?> left
                                </div>
                            <?php elseif ($product['stock_quantity'] <= 0): ?>
                                <div class="absolute bottom-3 left-3 bg-red-500 text-white text-xs font-medium px-2 py-1 rounded-lg backdrop-blur-sm bg-opacity-90">
                                    Out of Stock
                                </div>
                            <?php endif; ?>

                            <!-- Product Rating -->
                            <?php if ($product['average_rating'] >= 0): ?>
                                <div class="absolute bottom-3 right-3 bg-green-500 text-white rounded-full px-2 py-1 flex items-center justify-center text-xs font-bold backdrop-blur-sm bg-opacity-90">
                                    <svg class="w-3 h-3 text-yellow-300 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                    <?php echo number_format($product['average_rating'], 1); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Quick Actions -->
                            <div class="absolute top-2 right-3 transition-opacity duration-300 space-y-2 <?php echo (isset($product['featured']) && $product['featured']) ? 'top-12' : ''; ?>">
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <button class="wishlist-btn w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center transition-all duration-200 transform hover:scale-105 <?php echo $in_wishlist ? 'text-red-500 border-red-300 bg-red-50/90' : 'text-text-secondary border-border-light hover:text-accent-pink hover:bg-white'; ?>"
                                        data-product-id="<?php echo $product['id']; ?>"
                                        data-in-wishlist="<?php echo $in_wishlist ? 'true' : 'false'; ?>"
                                        title="<?php echo $in_wishlist ? 'Remove from Wishlist' : 'Add to Wishlist'; ?>"
                                        onclick="toggleWishlist(this, <?php echo $product['id']; ?>)">
                                        <svg class="w-4 h-4 transition-all duration-200"
                                            fill="<?php echo $in_wishlist ? 'currentColor' : 'none'; ?>"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                        </svg>
                                        <svg class="w-4 h-4 animate-spin absolute inset-0 m-auto hidden wishlist-loading" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </button>
                                <?php else: ?>
                                    <a href="<?php echo BASE_URL; ?>/auth/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                                        class="w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center text-text-secondary hover:text-accent-pink hover:bg-white transition-all duration-200"
                                        title="Login to add to wishlist">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Product Info -->
                        <div class="p-4 flex flex-col flex-grow">
                            <h3 class="font-medium text-gray-900 truncate h-8 group-hover:text-accent-pink transition-colors duration-200">
                                <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </a>
                            </h3>

                            <div class="flex items-center justify-between mb-3">
                                <div class="mb-3">
                                    <?php if ($product['sale_price'] && $product['sale_price'] < $product['price']): ?>
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm md:text-base font-semibold text-gray-900">
                                                ₹<?php echo $product['sale_price']; ?>
                                            </span>
                                            <span class="text-xs text-gray-500 line-through">
                                                ₹<?php echo $product['price']; ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-sm md:text-base font-semibold text-gray-900">
                                            ₹<?php echo $product['price']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Add to Cart Button -->
                            <div class="mt-auto pt-2">
                                <form method="POST" action="" class="add-to-cart-form">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <input type="hidden" name="color" value="<?php echo htmlspecialchars($product['available_colors']); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="add_to_cart" value="1">
                                    <button type="submit"
                                        class="w-full py-2.5 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-medium rounded-lg hover:shadow-md hover:scale-[1.02] transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-[#F2B9C7]/50 <?php echo ($product['stock_quantity'] <= 0) ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                                        <?php echo ($product['stock_quantity'] <= 0) ? 'disabled' : ''; ?>>

                                        <span class="button-text">
                                            <?php echo ($product['stock_quantity'] <= 0) ? 'Out of Stock' : 'Add to Cart'; ?>
                                        </span>
                                        <svg class="w-4 h-4 animate-spin mx-auto hidden loading-spinner" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </button>
                                </form>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="text-center mt-12 lg:hidden">
                <a href="<?php echo BASE_URL; ?>/products/product_list.php"
                    class="inline-block px-8 py-3 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-medium rounded-xl hover:shadow-lg transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-[#F2B9C7]/30">
                    View All Products
                </a>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- New Arrivals Section -->
<?php if (!empty($new_arrivals)): ?>
    <section class="py-16 bg-gradient-to-br from-accent-lavender/10 to-accent-pink/10">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl lg:text-4xl font-poppins font-bold text-gray-900 mb-4">
                    New Arrivals
                </h2>
                <p class="text-gray-600 max-w-2xl mx-auto">
                    Be the first to discover our latest designs and trending styles.
                </p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6">
                <?php foreach (array_slice($new_arrivals, 0, 8) as $product): ?>
                    <div class="group relative bg-white rounded-2xl overflow-hidden border border-gray-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] flex flex-col min-h-[320px] max-w-[280px] mx-auto w-full">

                        <!-- Image container -->
                        <div class="relative w-full aspect-[3/4] overflow-hidden flex-shrink-0">
                            <a href="/ecommerce-project/products/product_detail.php?id=<?php echo $product['id']; ?>">
                                <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                    alt="<?php echo htmlspecialchars($product['name']); ?>"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                    loading="lazy"
                                    onerror="this.src='<?php echo IMAGES_URL; ?>/placeholder.jpg'">
                            </a>

                            <!-- New badge -->
                            <div class="absolute top-2 left-2 bg-accent-mint text-gray-900 text-xs font-medium px-2 py-1 rounded-lg">
                                New
                            </div>

                            <!-- Product Rating -->
                            <?php if ($product['average_rating'] >= 0): ?>
                                <div class="absolute bottom-3 right-3 bg-green-500 text-white rounded-full px-2 py-1 flex items-center justify-center text-xs font-bold backdrop-blur-sm bg-opacity-90">
                                    <svg class="w-3 h-3 text-yellow-300 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                    <?php echo number_format($product['average_rating'], 1); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Action buttons -->
                            <div class="absolute top-2 right-2 flex flex-col space-y-2">
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <?php
                                    $in_wishlist = in_array($product['id'], $wishlist_status);
                                    ?>
                                    <button class="wishlist-btn w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center transition-all duration-200 transform hover:scale-105 <?php echo $in_wishlist ? 'text-red-500 border-red-300 bg-red-50/90' : 'text-text-secondary border-border-light hover:text-accent-pink hover:bg-white'; ?>"
                                        data-product-id="<?php echo $product['id']; ?>"
                                        data-in-wishlist="<?php echo $in_wishlist ? 'true' : 'false'; ?>"
                                        title="<?php echo $in_wishlist ? 'Remove from Wishlist' : 'Add to Wishlist'; ?>"
                                        onclick="toggleWishlist(this, <?php echo $product['id']; ?>)">
                                        <svg class="w-4 h-4 transition-all duration-200"
                                            fill="<?php echo $in_wishlist ? 'currentColor' : 'none'; ?>"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                        </svg>
                                        <svg class="w-4 h-4 animate-spin absolute inset-0 m-auto hidden wishlist-loading" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </button>
                                <?php else: ?>
                                    <a href="<?php echo BASE_URL; ?>/auth/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                                        class="p-2 rounded-full bg-white/90 backdrop-blur-sm border border-gray-200 text-gray-400 hover:bg-white hover:text-red-400 transition-all duration-200 transform hover:scale-105"
                                        title="Login to add to wishlist">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card content -->
                        <div class="p-3 md:p-4 flex flex-col flex-grow">
                            <!-- Product name -->
                            <h3 class="font-medium text-gray-900 text-sm md:text-base truncate h-8">
                                <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $product['id']; ?>"
                                    class="hover:text-accent-pink transition-colors duration-200"
                                    title="<?php echo htmlspecialchars($product['name']); ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </a>
                            </h3>

                            <!-- Price section -->
                            <div class="mb-3">
                                <?php if ($product['sale_price'] && $product['sale_price'] < $product['price']): ?>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm md:text-base font-semibold text-gray-900">
                                            ₹<?php echo $product['sale_price']; ?>
                                        </span>
                                        <span class="text-xs text-gray-500 line-through">
                                            ₹<?php echo $product['price']; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-sm md:text-base font-semibold text-gray-900">
                                        ₹<?php echo $product['price']; ?>
                                    </span>
                                <?php endif; ?>
                                          
                            </div>

                            <!-- Add to Cart Button -->
                            <div class="mt-auto pt-2">
                                <form method="POST" action="" class="add-to-cart-form">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <input type="hidden" name="color" value="<?php echo htmlspecialchars($product['available_colors']); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="add_to_cart" value="1">
                                    <button type="submit"
                                        class="w-full py-2.5 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-medium rounded-lg hover:shadow-md hover:scale-[1.02] transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-[#F2B9C7]/50 <?php echo ($product['stock_quantity'] <= 0) ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                                        <?php echo ($product['stock_quantity'] <= 0) ? 'disabled' : ''; ?>>

                                        <span class="button-text">
                                            <?php echo ($product['stock_quantity'] <= 0) ? 'Out of Stock' : 'Add to Cart'; ?>
                                        </span>
                                        <svg class="w-4 h-4 animate-spin mx-auto hidden loading-spinner" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </button>
                                </form>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- Testimonials Section -->
<?php if (!empty($testimonials)): ?>
    <section class="py-16 bg-gradient-to-br from-accent-lavender/10 to-accent-pink/10">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl lg:text-4xl font-poppins font-bold text-gray-900 mb-4">
                    What Our Customers Say
                </h2>
                <p class="text-gray-600">
                    Real feedback from real customers who love our dresses.
                </p>
            </div>

            <!-- Scrollable Container with Center Snapping -->
            <div class="flex overflow-x-auto snap-x snap-mandatory gap-4 md:grid md:grid-cols-2 lg:grid-cols-3 md:gap-8 px-4 -mx-4">
                <?php foreach (array_slice($testimonials, 0, 6) as $testimonial): ?>
                    <div class="flex-shrink-0 snap-center bg-gray-50 rounded-2xl p-6 hover:shadow-lg transition-all duration-300 w-[90%] max-w-sm md:w-auto">
                        <!-- Star Ratings -->
                        <div class="flex items-center mb-4">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <svg class="w-5 h-5 <?php echo ($i <= $testimonial['rating']) ? 'text-yellow-400' : 'text-gray-300'; ?>" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                            <?php endfor; ?>
                        </div>

                        <!-- Review Text -->
                        <p class="text-gray-600 mb-4 italic">
                            "<?php echo htmlspecialchars($testimonial['review']); ?>"
                        </p>

                        <!-- Customer Info -->
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-gradient-to-br from-[#a53860] to-[#ffa5ab] rounded-full flex items-center justify-center text-white font-semibold mr-3">
                                <?php echo strtoupper(substr($testimonial['customer_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($testimonial['customer_name']); ?></div>
                                <div class="text-sm text-gray-600">Verified Purchase</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<script>
    // Handle all "Add to Cart" forms
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.add-to-cart-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const button = this.querySelector('button[type="submit"]');
                const buttonText = button.querySelector('.button-text');
                const loadingSpinner = button.querySelector('.loading-spinner');
                const originalText = buttonText.textContent;

                // Skip if disabled (out of stock)
                if (button.disabled) return;

                // Show loading state
                button.disabled = true;
                buttonText.classList.add('hidden');
                loadingSpinner.classList.remove('hidden');

                // Prepare form data
                const formData = new FormData(this);

                // Send AJAX request
                fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.json();
                    })
                    .then(data => {
                        // Hide loading state
                        loadingSpinner.classList.add('hidden');
                        buttonText.classList.remove('hidden');
                        button.disabled = false;

                        if (data.success) {
                            // Show success message
                            showNotification(data.message || 'Product added to cart!', 'success');
                            // Update cart count in header if it exists
                            const cartCounts = document.querySelectorAll('.cart-count');
                            if (cartCounts.length && data.cartcount) {
                                cartCounts.forEach(elm => {
                                    elm.textContent = data.cartcount;
                                    elm.classList.remove('hidden');
                                });
                            }
                            // Change button text temporarily
                            buttonText.textContent = 'Added!';
                            setTimeout(() => {
                                buttonText.textContent = originalText;
                            }, 2000);
                        } else if (data.redirect) {
                            // Redirect to login if needed
                            window.location.href = data.redirect;
                        } else {
                            // Show error message
                            showNotification(data.message || 'Failed to add product to cart', 'error');
                        }
                    })
                    .catch(error => {
                        loadingSpinner.classList.add('hidden');
                        buttonText.classList.remove('hidden');
                        buttonText.textContent = originalText;
                        button.disabled = false;
                        showNotification('An error occurred. Please try again.', 'error');
                        console.error('Error:', error);
                    });
            });
        });
    });
    // Toast notification system
    function showNotification(message, type = 'success') {
        const existing = document.querySelectorAll('.notification-toast');
        existing.forEach(toast => toast.remove());

        const toast = document.createElement('div');
        toast.className = `notification-toast fixed top-4 right-4 max-w-sm p-4 rounded-lg shadow-lg z-50 transform transition-all duration-300 ease-in-out translate-x-full ${
        type === 'success' ? 'bg-green-500 text-white border border-green-600' : 'bg-red-500 text-white border border-red-600'
    }`;
        toast.innerHTML = `
        <div class="flex items-center">
            <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
            </svg>
            <span class="text-sm font-medium">${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-current opacity-75 hover:opacity-100">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    `;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.remove('translate-x-full'), 10);
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }

    // Hero Carousel Functionality
    class HeroCarousel {
        constructor(containerId, trackId, indicatorClass, prevClass = null, nextClass = null) {
            this.container = document.getElementById(containerId);
            this.track = document.getElementById(trackId);
            this.indicators = document.querySelectorAll(`.${indicatorClass}`);
            this.prevButton = prevClass ? document.querySelector(`.${prevClass}`) : null;
            this.nextButton = nextClass ? document.querySelector(`.${nextClass}`) : null;

            this.currentSlide = 0;
            this.totalSlides = this.indicators.length;
            this.autoPlayInterval = null;

            this.init();
        }

        init() {
            if (this.totalSlides <= 1) return;

            // Add event listeners
            this.indicators.forEach((indicator, index) => {
                indicator.addEventListener('click', () => this.goToSlide(index));
            });

            if (this.prevButton) {
                this.prevButton.addEventListener('click', () => this.previousSlide());
            }

            if (this.nextButton) {
                this.nextButton.addEventListener('click', () => this.nextSlide());
            }

            // Start autoplay
            this.startAutoPlay();

            // Pause on hover
            this.container.addEventListener('mouseenter', () => this.stopAutoPlay());
            this.container.addEventListener('mouseleave', () => this.startAutoPlay());

            // Touch/swipe support
            this.addTouchSupport();
        }

        goToSlide(slideIndex) {
            this.currentSlide = slideIndex;
            this.updateCarousel();
        }

        nextSlide() {
            this.currentSlide = (this.currentSlide + 1) % this.totalSlides;
            this.updateCarousel();
        }

        previousSlide() {
            this.currentSlide = (this.currentSlide - 1 + this.totalSlides) % this.totalSlides;
            this.updateCarousel();
        }

        updateCarousel() {
            // Update track position
            const translateX = -this.currentSlide * 100;
            this.track.style.transform = `translateX(${translateX}%)`;

            // Update indicators
            this.indicators.forEach((indicator, index) => {
                if (index === this.currentSlide) {
                    indicator.classList.add('bg-white');
                    indicator.classList.remove('bg-white/50');
                } else {
                    indicator.classList.remove('bg-white');
                    indicator.classList.add('bg-white/50');
                }
            });
        }

        startAutoPlay() {
            this.stopAutoPlay();
            if (this.totalSlides > 1) {
                this.autoPlayInterval = setInterval(() => {
                    this.nextSlide();
                }, 5000); // Change slide every 5 seconds
            }
        }

        stopAutoPlay() {
            if (this.autoPlayInterval) {
                clearInterval(this.autoPlayInterval);
                this.autoPlayInterval = null;
            }
        }

        addTouchSupport() {
            let startX = 0;
            let endX = 0;

            this.container.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
            }, {
                passive: true
            });

            this.container.addEventListener('touchend', (e) => {
                endX = e.changedTouches[0].clientX;
                const diff = startX - endX;

                if (Math.abs(diff) > 50) { // Minimum swipe distance
                    if (diff > 0) {
                        this.nextSlide();
                    } else {
                        this.previousSlide();
                    }
                }
            }, {
                passive: true
            });
        }
    }

    // Initialize carousels when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Desktop carousel
        const desktopCarousel = new HeroCarousel(
            'desktop-hero-carousel',
            'desktop-carousel-track',
            'desktop-carousel-indicator',
            'desktop-carousel-prev',
            'desktop-carousel-next'
        );

        // Mobile carousel
        const mobileCarousel = new HeroCarousel(
            'mobile-hero-carousel',
            'mobile-carousel-track',
            'mobile-carousel-indicator'
        );
    });

    // In index.php, add this utility function
    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 px-4 py-2 rounded-md text-white z-50 ${
        type === 'success' ? 'bg-green-500' : 'bg-red-500'
    }`;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Add to Wishlist functionality
    // Toggle Wishlist functionality - UPDATED
    function toggleWishlist(button, productId) {
        // Prevent multiple clicks
        if (button.disabled) return;

        // Get current state
        const isInWishlist = button.getAttribute('data-in-wishlist') === 'true';
        const heartIcon = button.querySelector('svg:not(.wishlist-loading)');
        const loadingIcon = button.querySelector('.wishlist-loading');

        // Show loading state
        button.disabled = true;
        heartIcon.classList.add('hidden');
        loadingIcon.classList.remove('hidden');

        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'toggle_wishlist');
        formData.append('product_id', productId);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        // Send AJAX request
        fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                // Hide loading state
                loadingIcon.classList.add('hidden');
                heartIcon.classList.remove('hidden');
                button.disabled = false;

                if (data.success) {
                    // Update button state instantly
                    const newInWishlist = data.in_wishlist;

                    // Update button attributes
                    button.setAttribute('data-in-wishlist', newInWishlist ? 'true' : 'false');
                    button.title = newInWishlist ? 'Remove from Wishlist' : 'Add to Wishlist';

                    // Update button styling
                    if (newInWishlist) {
                        // Added to wishlist - make it red
                        button.classList.remove('text-text-secondary', 'border-border-light', 'hover:text-accent-pink', 'hover:bg-white');
                        button.classList.add('text-red-500', 'border-red-300', 'bg-red-50/90');
                        heartIcon.setAttribute('fill', 'currentColor');
                    } else {
                        // Removed from wishlist - make it gray
                        button.classList.remove('text-red-500', 'border-red-300', 'bg-red-50/90');
                        button.classList.add('text-text-secondary', 'border-border-light', 'hover:text-accent-pink', 'hover:bg-white');
                        heartIcon.setAttribute('fill', 'none');
                    }

                    // Show success notification
                    showToast(data.message, 'success');

                    // Add heart animation
                    heartIcon.classList.add('animate-pulse');
                    setTimeout(() => {
                        heartIcon.classList.remove('animate-pulse');
                    }, 600);

                } else {
                    // Handle error
                    showToast(data.message || 'An error occurred', 'error');

                    if (data.redirect) {
                        // Redirect to login if needed
                        setTimeout(() => {
                            window.location.href = '<?php echo BASE_URL; ?>/auth/login.php?redirect=' + encodeURIComponent(window.location.href);
                        }, 1500);
                    }
                }
            })
            .catch(error => {
                console.error('Wishlist error:', error);

                // Hide loading state
                loadingIcon.classList.add('hidden');
                heartIcon.classList.remove('hidden');
                button.disabled = false;

                // Show error notification
                showToast('Network error. Please try again.', 'error');
            });
    }


    // Enhanced notification system
    function showNotification(message, type = 'success') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.toast-notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `toast-notification fixed top-4 right-4 max-w-sm p-4 rounded-lg shadow-lg z-50 transform transition-all duration-300 ease-in-out translate-x-full ${
        type === 'success' 
            ? 'bg-green-500 text-white border border-green-600' 
            : 'bg-red-500 text-white border border-red-600'
    }`;

        // Add icon and message
        const icon = type === 'success' ?
            '<svg class="w-5 h-5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>' :
            '<svg class="w-5 h-5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>';

        notification.innerHTML = `
        <div class="flex items-center">
            ${icon}
            <span class="text-sm font-medium">${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-current opacity-75 hover:opacity-100">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    `;

        // Add to document
        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.classList.remove('translate-x-full');
        }, 100);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 5000);
    }

    // Handle existing session notifications
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_SESSION['wishlist_success'])): ?>
            showNotification('<?php echo addslashes($_SESSION['wishlist_success']); ?>', 'success');
            <?php unset($_SESSION['wishlist_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['wishlist_error'])): ?>
            showNotification('<?php echo addslashes($_SESSION['wishlist_error']); ?>', 'error');
            <?php unset($_SESSION['wishlist_error']); ?>
        <?php endif; ?>
    });

    // Quick View functionality
    async function quickView(productId) {
        try {
            showLoading();

            const response = await fetch(`<?php echo BASE_URL; ?>/api/product-quick-view.php?id=${productId}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.success) {
                document.getElementById('quick-view-content').innerHTML = data.html;
                document.getElementById('quick-view-modal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else {
                showToast('Failed to load product details', 'error');
            }
        } catch (error) {
            console.error('Quick view error:', error);
            showToast('Network error. Please try again.', 'error');
        } finally {
            hideLoading();
        }
    }

    // Close quick view modal
    document.addEventListener('click', function(e) {
        if (e.target.id === 'quick-view-modal') {
            document.getElementById('quick-view-modal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    });

    // Utility functions for loading and toast (add these if they don't exist)
    function showLoading() {
        // Add loading indicator
        console.log('Loading...');
    }

    function hideLoading() {
        // Remove loading indicator
        console.log('Loading complete');
    }

    function showToast(message, type) {
        // Simple toast notification
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg text-white z-50 ${type === 'success' ? 'bg-green-500' : 'bg-red-500'}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 3000);
    }

    console.log('🎉 Homepage with carousel loaded successfully!');
</script>

<?php
// Include footer
include 'includes/footer.php';
?>