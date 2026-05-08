<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
/**
 * Product Detail Page
 * Displays individual product information with add to cart functionality
 * Updated to handle multiple product variants with same product_id
 */

// Start session and include required files
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

// Get product ID from URL
$product_id = $_GET['id'] ?? null;

if (!$product_id) {
    header("Location: product_list.php");
    exit();
}

// Initialize variables
$product_variants = [];
$main_product = null;
$related_products = [];
$error_message = '';
$success_message = '';
$reviews = [];
$average_rating = 0;
$total_reviews = 0;
$can_review = false;
$has_reviewed = false;

// Unified AJAX/non-AJAX handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_to_cart', 'add_to_wishlist', 'add_review'])) {
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $response = ['success' => false, 'message' => 'Invalid request'];

    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $response = [
            'success'  => false,
            'message'  => 'Security token mismatch. Please refresh the page and try again.',
            'code'     => 'csrf'
        ];

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        } else {
            $error_message = $response['message'];
        }
    }

    // Authentication check (except for add_review which handles its own auth)
    if (!isset($_SESSION['user_id']) && $_POST['action'] !== 'add_review') {
        $response = [
            'success'  => false,
            'message'  => 'Please login to continue.',
            'redirect' => '/ecommerce-project/auth/login.php',
            'code'     => 'auth'
        ];

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        } else {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            $_SESSION['login_message'] = $response['message'];
            header("Location: " . $response['redirect']);
            exit();
        }
    }

    $action = $_POST['action'];
    $variant_id = (int)($_POST['variant_id'] ?? 0);
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));

    try {
        if ($action === 'add_to_cart' || $action === 'add_to_wishlist') {
            // Validate product variant
            $current_variant = $db->fetchRow(
                "SELECT * FROM " . DB_PREFIX . "products WHERE id = ? AND status = 'active'",
                [$variant_id]
            );

            if (!$current_variant) {
                throw new Exception('Product not found or unavailable.');
            }

            // Get the selected color from variant
            $selected_color = $current_variant['available_colors'] ?? '';

            // Process actions
            if ($action === 'add_to_cart') {
                if ($current_variant['stock_quantity'] < $quantity) {
                    throw new Exception('Insufficient stock available.');
                }

                $existing_cart = $db->fetchRow(
                    "SELECT id, quantity FROM " . DB_PREFIX . "cart WHERE user_id = ? AND product_id = ?",
                    [$_SESSION['user_id'], $variant_id]
                );

                if ($existing_cart) {
                    $new_quantity = $existing_cart['quantity'] + $quantity;
                    if ($new_quantity > $current_variant['stock_quantity']) {
                        throw new Exception('Cannot add more items. Insufficient stock available.');
                    }
                    $db->update(
                        'cart',
                        [
                            'quantity' => $new_quantity,
                            'color' => $selected_color, // Update color too
                            'updated_at' => date('Y-m-d H:i:s')
                        ],
                        'id = :id',
                        ['id' => $existing_cart['id']]
                    );
                } else {
                    $cart_insert_data = [
                        'user_id'    => $_SESSION['user_id'],
                        'product_id' => $variant_id,
                        'quantity'   => $quantity,
                        'color'      => $selected_color, // Store selected color
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    $db->insert('cart', $cart_insert_data);
                }

                // Get updated cart count
                $cart_count_result = $db->fetchRow(
                    "SELECT SUM(quantity) as total FROM " . DB_PREFIX . "cart WHERE user_id = ?",
                    [$_SESSION['user_id']]
                );
                $cart_count = $cart_count_result['total'] ?? 0;

                $response = [
                    'success'    => true,
                    'message'    => 'Added to cart successfully!',
                    'cart_count' => $cart_count
                ];
            } elseif ($action === 'add_to_wishlist') {
                $existing_wishlist = $db->fetchRow(
                    "SELECT id FROM " . DB_PREFIX . "wishlist WHERE user_id = ? AND product_id = ?",
                    [$_SESSION['user_id'], $variant_id]
                );

                if ($existing_wishlist) {
                    $removed = $db->delete('wishlist', 'user_id = ? AND product_id = ?', [$_SESSION['user_id'], $variant_id]);
                    $response = [
                        'success'     => $removed,
                        'message'     => $removed ? 'Removed from wishlist.' : 'Failed to remove from wishlist.',
                        'action'      => 'removed',
                        'in_wishlist' => false
                    ];
                } else {
                    $wishlist_insert_data = [
                        'user_id'    => $_SESSION['user_id'],
                        'product_id' => $variant_id,
                        'color'      => $selected_color, // Store selected color
                        'added_at'   => date('Y-m-d H:i:s')
                    ];
                    $added = $db->insert('wishlist', $wishlist_insert_data);
                    $response = [
                        'success'     => $added,
                        'message'     => $added ? 'Added to wishlist!' : 'Failed to add to wishlist.',
                        'action'      => 'added',
                        'in_wishlist' => true
                    ];
                }
            }
        } elseif ($action === 'add_review') {
            // Review submission handling
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('Please login to submit a review.');
            }

            $rating = (int)($_POST['rating'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $review_text = trim($_POST['review'] ?? '');
            $variant_id = (int)($_POST['variant_id'] ?? 0);

            // Validate review data
            if ($rating < 1 || $rating > 5) {
                throw new Exception('Please select a valid rating (1-5 stars).');
            }

            if (empty($title)) {
                throw new Exception('Review title is required.');
            }

            if (empty($review_text)) {
                throw new Exception('Review text is required.');
            }

            if (strlen($title) > 255) {
                throw new Exception('Review title must be less than 255 characters.');
            }

            if (strlen($review_text) > 1000) {
                throw new Exception('Review text must be less than 1000 characters.');
            }

            // Get the product info for this variant
            $variant_info = $db->fetchRow(
                "SELECT id, product_id FROM " . DB_PREFIX . "products WHERE id = ?",
                [$variant_id]
            );

            if (!$variant_info) {
                throw new Exception('Invalid product variant.');
            }

            // Check if user has already reviewed this variant
            $existing_review = $db->fetchRow(
                "SELECT id FROM " . DB_PREFIX . "testimonials WHERE user_id = ? AND product_id = ?",
                [$_SESSION['user_id'], $variant_info['id']]
            );

            if ($existing_review) {
                throw new Exception('You have already reviewed this product variant.');
            }

            // Insert the review
            $user_info = $db->fetchRow(
                "SELECT first_name, last_name FROM " . DB_PREFIX . "users WHERE id = ?",
                [$_SESSION['user_id']]
            );

            $customer_name = trim(($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? ''));
            if (empty($customer_name)) {
                $customer_name = 'Anonymous User'; // Fallback if name is not available
            }

            // Insert the review - CORRECTED: product_id gets the variant's id, variant_id gets the product's product_id
            $review_data = [
                'product_id' => $variant_info['id'],  // Use the variant's id as product_id
                'variant_id' => $variant_info['product_id'],  // Use the product's product_id as variant_id
                'user_id' => $_SESSION['user_id'],
                'rating' => $rating,
                'title' => $title,
                'customer_name' => $customer_name,
                'review' => $review_text,
                'status' => MODERATE_REVIEWS ? 'pending' : 'approved',
                'created_at' => date('Y-m-d H:i:s'),
                'verified_purchase' => 0 // For simplicity, not verifying purchases
            ];

            $success = $db->insert('testimonials', $review_data);

            if ($success) {
                $message = MODERATE_REVIEWS
                    ? 'Thank you for your review! It will be visible after approval.'
                    : 'Thank you for your review!';

                $response = [
                    'success' => true,
                    'message' => $message,
                    'reload' => true
                ];
            } else {
                throw new Exception('Failed to submit review. Please try again.');
            }
        }
    } catch (Exception $ex) {
        logMessage("ADD TO CART/WISHLIST/REVIEW ERROR: " . $ex->getMessage(), 'ERROR');
        $response = [
            'success' => false,
            'message' => $ex->getMessage(),
            'code'    => 'server'
        ];
    }

    // Handle response
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        if ($response['success']) {
            $success_message = $response['message'];
        } else {
            $error_message = $response['message'];
        }
    }
}

try {
    // Get all product variants
    $requested_id = (int)($_GET['id'] ?? 0);
    $current_product = $db->fetchRow(
        "SELECT * FROM " . DB_PREFIX . "products WHERE id = ? AND status = 'active'",
        [$requested_id]
    );

    if (!$current_product) {
        header("Location: product_list.php?error=product_not_found");
        exit();
    }

    $product_id = $current_product['product_id'];
    $product_variants = $db->fetchAll(
        "SELECT * FROM " . DB_PREFIX . "products 
         WHERE product_id = ? AND status = 'active'
         ORDER BY sort_order ASC, id ASC",
        [$product_id]
    );

    $main_product = $current_product;

    // Check if product is out of stock
    $is_out_of_stock = ($main_product['stock_quantity'] <= 0);

    // Get gallery images
    $gallery_images = [];
    if (!empty($main_product['gallery'])) {
        $gallery_images = explode(',', $main_product['gallery']);
    }
    // Prepend the main image to the gallery
    array_unshift($gallery_images, $main_product['image']);

    // Get related products
    $related_products = $db->fetchAll(
        "SELECT DISTINCT p.* FROM " . DB_PREFIX . "products p 
        WHERE p.category_id = ? AND p.product_id != ? AND p.status = 'active'
        GROUP BY p.product_id
        ORDER BY RAND() LIMIT 4",
        [$main_product['category_id'], $main_product['product_id']]
    );

    // Get reviews for this specific variant
    $review_stats = $db->fetchRow(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as average_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
         FROM " . DB_PREFIX . "testimonials 
         WHERE product_id = ? AND status = 'approved'",
        [$main_product['id']]  // Use current variant ID
    );

    $reviews = $db->fetchAll(
        "SELECT r.* FROM " . DB_PREFIX . "testimonials r 
         WHERE r.product_id = ? AND r.status = 'approved' 
         ORDER BY r.created_at DESC LIMIT 10",
        [$main_product['id']]  // Use current variant ID
    );

    $average_rating = round($review_stats['average_rating'] ?? 0, 1);
    $total_reviews = $review_stats['total_reviews'] ?? 0;

    // Check if user can review
    $can_review = false;
    $has_reviewed = false;
    if (isset($_SESSION['user_id'])) {
        $existing_review = $db->fetchRow(
            "SELECT id FROM " . DB_PREFIX . "testimonials WHERE user_id = ? AND product_id = ?",
            [$_SESSION['user_id'], $main_product['id']]  // Use current variant ID
        );
        $has_reviewed = (bool)$existing_review;
        $can_review = !$has_reviewed;
    }
} catch (Exception $e) {
    logMessage("Error fetching product details: " . $e->getMessage(), 'ERROR');
    $error_message = "Unable to load product details. Please try again later.";
    // Ensure variables are still defined even if there's an error
    $can_review = false;
    $has_reviewed = false;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Set page meta data
$page_title = $main_product['name'] . ' - ' . SITE_NAME;
$page_description = substr(strip_tags($main_product['description']), 0, 160);

// Helper function for star rating display
function renderStars($rating, $total = 5)
{
    $output = '';
    for ($i = 1; $i <= $total; $i++) {
        if ($i <= $rating) {
            $output .= '<svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                                </svg>';
        } elseif ($i - 0.5 <= $rating) {
            $output .= '<svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                                </svg>';
        } else {
            $output .= '<svg class="w-4 h-4 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                                </svg>';
        }
    }
    return $output;
}

// Get category name for breadcrumb
$category_name = "Dresses"; // Default fallback
if (!empty($main_product['category_id'])) {
    $category = $db->fetchRow(
        "SELECT name FROM " . DB_PREFIX . "categories WHERE id = ?",
        [$main_product['category_id']]
    );
    if ($category) {
        $category_name = $category['name'];
    }
}

include '../includes/header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Breadcrumb -->
    <nav class="bg-white border-b border-gray-200 py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center space-x-2 text-sm text-gray-500">
                <a href="../index.php" class="hover:text-gray-900 transition-colors">Home</a>
                <span>/</span>
                <a href="product_list.php?category=<?= $main_product['category_id'] ?? '' ?>"
                    class="hover:text-gray-900 transition-colors">
                    <?= htmlspecialchars($category_name) ?>
                </a>
                <span>/</span>
                <span class="text-gray-900" id="breadcrumb-name"><?= htmlspecialchars($main_product['name']) ?></span>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Product Details -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="lg:flex">
                <!-- Product Images -->
                <div class="lg:w-1/2">
                    <div class="aspect-square bg-gray-100 relative overflow-hidden">
                        <!-- Main Image Display -->
                        <div id="main-image-container" class="w-full h-full relative cursor-pointer" onclick="openImageZoom()">
                            <img id="main-image"
                                src="<?= IMAGES_URL . '/' . htmlspecialchars($main_product['image']) ?>"
                                alt="<?= htmlspecialchars($main_product['name']) ?>"
                                class="main-product-image w-full h-full object-cover hover:scale-105 transition-transform duration-300"
                                loading="lazy">

                            <!-- Sale Badge -->
                            <div id="sale-badge" class="absolute top-4 left-4 bg-red-500 text-white px-3 py-1 rounded-full text-sm font-medium <?= $main_product['sale_price'] && $main_product['sale_price'] < $main_product['price'] ? '' : 'hidden' ?>">
                                <span id="discount-percentage"><?= $main_product['sale_price'] ? round((($main_product['price'] - $main_product['sale_price']) / $main_product['price']) * 100) : 0 ?>% OFF</span>
                            </div>

                            <!-- Out of Stock Badge -->
                            <div id="stock-badge" class="absolute top-4 right-4 bg-gray-800 text-white px-3 py-1 rounded-full text-sm font-medium <?= $is_out_of_stock ? '' : 'hidden' ?>">
                                Out of Stock
                            </div>

                            <!-- Zoom Indicator -->
                            <div class="absolute inset-0 bg-black/0 hover:bg-black/10 transition-all duration-300 flex items-center justify-center opacity-0 hover:opacity-100">
                                <div class="bg-white/90 rounded-full p-3 shadow-lg">
                                    <svg class="w-6 h-6 text-gray-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Gallery Thumbnails -->
                    <?php if (!empty($gallery_images)): ?>
                        <div class="flex space-x-2 p-4 overflow-x-auto" id="gallery-thumbnails">
                            <?php foreach ($gallery_images as $index => $image): ?>
                                <?php $trimmed_image = trim($image); ?>
                                <button class="flex-shrink-0 w-20 h-20 border-2 rounded-lg overflow-hidden hover:border-gray-400 transition-colors gallery-thumbnail <?= $index === 0 ? 'border-black' : 'border-gray-200' ?>"
                                    data-index="<?= $index ?>"
                                    onclick="changeGalleryImage(<?= $index ?>)">
                                    <img src="<?= IMAGES_URL . '/' . htmlspecialchars($trimmed_image) ?>"
                                        alt="Gallery image <?= $index + 1 ?>"
                                        class="w-full h-full object-cover pointer-events-none">
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Product Information -->
                <div class="lg:w-1/2 p-8">
                    <div class="space-y-6">
                        <!-- Product Title & Rating -->
                        <div>
                            <h1 id="product-name" class="text-3xl font-bold text-gray-900 mb-2">
                                <?= htmlspecialchars($main_product['name']) ?>
                            </h1>

                            <?php if ($total_reviews > 0): ?>
                                <div class="flex items-center space-x-2 mb-4">
                                    <span class="text-xl text-grey-600 flex items-center">
                                        <?php
                                        $rating = round($average_rating); // Round to nearest whole number

                                        // Display stars
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= $rating) {
                                                echo '<span class="text-xl text-yellow-400">★</span>'; // Filled star
                                            } else {
                                                echo '<span class="text-xl text-gray-300">★</span>'; // Empty star
                                            }
                                        }
                                        ?>

                                        <span class="ml-2">
                                            <?= $average_rating ?>
                                        </span>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Price -->
                        <div class="flex items-center space-x-3">
                            <span id="current-price" class="text-3xl font-bold text-gray-900">
                                ₹<?= number_format($main_product['sale_price'] ?: $main_product['price'], 2) ?>
                            </span>
                            <span id="original-price" class="text-xl text-gray-500 line-through <?= $main_product['sale_price'] && $main_product['sale_price'] < $main_product['price'] ? '' : 'hidden' ?>">
                                ₹<?= number_format($main_product['price'], 2) ?>
                            </span>
                        </div>

                        <!-- Description -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-3">Description</h3>
                            <p id="product-description" class="text-gray-600 leading-relaxed">
                                <?= nl2br(htmlspecialchars($main_product['description'])) ?>
                            </p>
                        </div>

                        <!-- Color Selection -->
                        <?php if (count($product_variants) > 1): ?>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-3">
                                    Color <span class="text-red-500">*</span>
                                </h3>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($product_variants as $index => $variant): ?>
                                        <?php
                                        $is_variant_out_of_stock = $variant['stock_quantity'] <= 0;
                                        $color_name = htmlspecialchars(trim($variant['available_colors']));
                                        $is_selected = ($variant['id'] == $main_product['id']);
                                        ?>
                                        <a href="product_detail.php?id=<?= $variant['id'] ?>" class="relative color-option block">
                                            <div class="px-4 py-2 border-2 rounded-lg text-sm font-medium
                                                 <?= $is_selected
                                                        ? 'border-black bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white'
                                                        : 'border-gray-300 hover:border-gray-400' ?>
                                                 <?= $is_variant_out_of_stock ? 'opacity-50 cursor-not-allowed' : '' ?>">
                                                <?= $color_name ?>
                                                <?php if ($is_variant_out_of_stock): ?>
                                                    <span class="text-xs opacity-70">(Out of Stock)</span>
                                                <?php endif; ?>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="space-y-4">
                            <!-- Add to Cart Button -->
                            <form id="addToCartForm" class="space-y-6">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" id="selected-variant-id" name="variant_id" value="<?= $main_product['id'] ?>">
                                <input type="hidden" id="selected-quantity" name="quantity" value="1">
                                <input type="hidden" name="action" value="add_to_cart">

                                <!-- Quantity Selector -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-900 mb-2">Quantity</label>
                                    <div class="flex items-center space-x-3">
                                        <button type="button" id="decreaseQty"
                                            class="flex items-center justify-center w-10 h-10 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors <?= $is_out_of_stock ? 'cursor-not-allowed' : '' ?>"
                                            <?= $is_out_of_stock ? 'disabled' : '' ?>>
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                            </svg>
                                        </button>
                                        <input type="number" id="quantity" value="1" min="1" max="<?= $main_product['stock_quantity'] ?>"
                                            class="w-20 text-center border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-black <?= $is_out_of_stock ? 'cursor-not-allowed bg-gray-100' : '' ?>"
                                            <?= $is_out_of_stock ? 'disabled' : '' ?>>
                                        <button type="button" id="increaseQty"
                                            class="flex items-center justify-center w-10 h-10 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors <?= $is_out_of_stock ? 'cursor-not-allowed' : '' ?>"
                                            <?= $is_out_of_stock ? 'disabled' : '' ?>>
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex space-x-4">
                                    <button type="submit" id="add-to-cart-btn"
                                        class="flex-1 py-3 px-6 rounded-lg <?= $is_out_of_stock
                                                                                ? 'bg-gray-400 text-white cursor-not-allowed'
                                                                                : 'bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white hover:shadow-md hover:scale-105 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2' ?>"
                                        <?= $is_out_of_stock ? 'disabled' : '' ?>>
                                        <span id="cart-btn-text">
                                            <?= $is_out_of_stock ? 'Out of Stock' : 'Add to Cart' ?>
                                        </span>
                                    </button>

                                    <button type="button" id="add-to-wishlist-btn"
                                        class="flex items-center justify-center w-12 h-12 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors focus:outline-none focus:ring-offset-2">
                                        <svg id="wishlist-icon" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.682l-1.318-1.364a4.5 4.5 0 00-6.364 0z" />
                                        </svg>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Product Features -->
                        <div class="border-t pt-6">
                            <div class="grid grid-cols-1 gap-4">
                                <div class="flex items-center space-x-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512" fill="#16a34a" width="24" height="24">
                                        <path d="M48 0C21.5 0 0 21.5 0 48V368c0 26.5 21.5 48 48 48H64c0 53 43 96 96 96s96-43 96-96H384c0 53 43 96 96 96s96-43 96-96h32c17.7 0 32-14.3 32-32s-14.3-32-32-32V288 256 237.3c0-17-6.7-33.3-18.7-45.3L512 114.7c-12-12-28.3-18.7-45.3-18.7H416V48c0-26.5-21.5-48-48-48H48zM416 160h50.7L544 237.3V256H416V160zM112 416a48 48 0 1 1 96 0 48 48 0 1 1 -96 0zm368-48a48 48 0 1 1 0 96 48 48 0 1 1 0-96z" />
                                    </svg>
                                    <span class="text-sm text-gray-600">Free shipping on orders over ₹999</span>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="#2563eb" width="24" height="24">
                                        <path d="M125.7 160H176c17.7 0 32 14.3 32 32s-14.3 32-32 32H48c-17.7 0-32-14.3-32-32V64c0-17.7 14.3-32 32-32s32 14.3 32 32v51.2L97.6 97.6c87.5-87.5 229.3-87.5 316.8 0s87.5 229.3 0 316.8s-229.3 87.5-316.8 0c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0c62.5 62.5 163.8 62.5 226.3 0s62.5-163.8 0-226.3s-163.8-62.5-226.3 0L125.7 160z" />
                                    </svg>
                                    <span class="text-sm text-gray-600">7-day return policy</span>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="#9333ea" width="24" height="24">
                                        <path d="M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 38.4 31 38.3 57.2c-.5 99.2-41.3 280.7-213.6 363.2c-16.7 8-36.1 8-52.8 0C57.3 420.7 16.5 239.2 16 140c-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.8 1 251.4 0 256 0z" />
                                    </svg>
                                    <span class="text-sm text-gray-600">Secure payment guaranteed</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Details Tabs -->
        <div class="mt-12 bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="border-b border-gray-200">
                <nav class="flex space-x-8 px-8">
                    <button class="tab-button active py-4 text-sm font-medium border-b-2 border-black text-black"
                        data-tab="details">
                        Product Details
                    </button>
                    <button class="tab-button py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 
                                   hover:text-gray-700 hover:border-gray-300"
                        data-tab="reviews">
                        Reviews (<?= $total_reviews ?>)
                    </button>
                    <button class="tab-button py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 
                                   hover:text-gray-700 hover:border-gray-300"
                        data-tab="shipping">
                        Shipping & Returns
                    </button>
                </nav>
            </div>

            <div class="p-8">
                <!-- Product Details Tab -->
                <div id="details-tab" class="tab-content">
                    <div class="prose max-w-none">
                        <h3>Product Information</h3>
                        <p id="tab-description"><?= nl2br(htmlspecialchars($main_product['description'])) ?></p>

                        <div id="tab-short-description" class="<?= $main_product['short_description'] ? '' : 'hidden' ?>">
                            <h4>Features</h4>
                            <p><?= nl2br(htmlspecialchars($main_product['short_description'])) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Reviews Tab -->
                <div id="reviews-tab" class="tab-content hidden">
                    <div class="space-y-8">
                        <!-- Review Statistics -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                            <div class="text-center p-6 bg-gray-50 rounded-lg">
                                <div class="text-4xl font-bold text-gray-900 mb-2"><?= $average_rating ?></div>
                                <div class="flex justify-center mb-2">
                                    <?= renderStars($average_rating) ?>
                                </div>
                                <p class="text-gray-600"><?= $total_reviews ?> review<?= $total_reviews !== 1 ? 's' : '' ?></p>
                            </div>

                            <div class="md:col-span-2">
                                <?php if ($total_reviews > 0): ?>
                                    <?php
                                    $starKeys = [
                                        5 => 'five_star',
                                        4 => 'four_star',
                                        3 => 'three_star',
                                        2 => 'two_star',
                                        1 => 'one_star'
                                    ];

                                    for ($i = 5; $i >= 1; $i--):
                                        $key = $starKeys[$i];
                                        $count = $review_stats[$key] ?? 0;
                                        $percentage = $total_reviews > 0 ? ($count / $total_reviews) * 100 : 0;
                                    ?>
                                        <div class="flex items-center mb-3">
                                            <span class="text-sm text-gray-600 w-16"><?= $i ?> star</span>
                                            <div class="flex-1 mx-4 bg-gray-200 rounded-full h-2.5">
                                                <div class="bg-yellow-400 h-2.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                                            </div>
                                            <span class="text-sm text-gray-600 w-16"><?= $count ?></span>
                                        </div>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <p class="text-gray-600 py-4">No reviews yet. Be the first to review this product!</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Write Review Form -->
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <?php if ($can_review): ?>
                                <div class="border-t border-gray-200 pt-8">
                                    <h3 class="text-xl font-bold text-gray-900 mb-6">Write a Review</h3>
                                    <form id="review-form" class="space-y-6">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="add_review">
                                        <input type="hidden" name="variant_id" value="<?= $main_product['id'] ?>">

                                        <!-- Rating -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-3">Rating *</label>
                                            <div class="flex items-center space-x-1">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <button type="button" class="rating-star text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="<?= $i ?>">
                                                        <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24">
                                                            <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
                                                        </svg>
                                                    </button>
                                                <?php endfor; ?>
                                            </div>
                                            <input type="hidden" name="rating" id="rating-input" required>
                                        </div>

                                        <!-- Title -->
                                        <div>
                                            <label for="review-title" class="block text-sm font-medium text-gray-700 mb-2">Review Title *</label>
                                            <input type="text" id="review-title" name="title" required maxlength="255"
                                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent"
                                                placeholder="Summarize your review">
                                        </div>

                                        <!-- Review Text -->
                                        <div>
                                            <label for="review-text" class="block text-sm font-medium text-gray-700 mb-2">Your Review *</label>
                                            <textarea id="review-text" name="review" rows="4" required maxlength="1000"
                                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent resize-none"
                                                placeholder="Share your experience with this product (10-1000 characters)"></textarea>
                                            <div class="text-right text-sm text-gray-500 mt-1">
                                                <span id="char-count">0</span>/1000 characters
                                            </div>
                                        </div>

                                        <button type="submit" class="bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white px-6 py-3 rounded-lg hover:bg-gray-800 transition-colors font-medium">
                                            Submit Review
                                        </button>
                                    </form>
                                </div>
                            <?php elseif ($has_reviewed): ?>
                                <div class="border-t border-gray-200 pt-8">
                                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                        <div class="flex">
                                            <div class="flex-shrink-0">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="#60a5fa" width="24" height="24">
                                                    <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z" />
                                                </svg>
                                            </div>
                                            <div class="ml-3">
                                                <p class="text-sm text-blue-700">You have already reviewed this product. Thank you for your feedback!</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="border-t border-gray-200 pt-8">
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-6">
                                    <div class="text-center">
                                        <p class="text-gray-600 mb-4">Please log in to write a review</p>
                                        <a href="/ecommerce-project/auth/login.php" class="bg-black text-white px-6 py-3 rounded-lg hover:bg-gray-800 transition-colors font-medium">
                                            Log In
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Reviews List -->
                        <?php if (!empty($reviews)): ?>
                            <div class="border-t border-gray-200 pt-8">
                                <h3 class="text-xl font-bold text-gray-900 mb-6">Customer Reviews</h3>
                                <div class="space-y-8" id="reviews-container">
                                    <?php foreach ($reviews as $review): ?>
                                        <div class="border-b border-gray-200 pb-8 last:border-b-0">
                                            <div class="flex items-start justify-between mb-4">
                                                <div class="flex items-center space-x-4">
                                                    <div class="w-12 h-12 bg-gray-200 rounded-full flex items-center justify-center">
                                                        <span class="text-base font-medium text-gray-700">
                                                            <?= strtoupper(substr($review['first_name'] ?? $review['customer_name'], 0, 1)) ?>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <p class="font-medium text-gray-900">
                                                            <span class="mr-4">
                                                            <?= htmlspecialchars($review['customer_name']) ?>
                                                            </span>
                                                            <?php if ($review['verified_purchase']): ?>
                                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                                        class="h-5 w-5 text-green-600 mr-1"
                                                                        fill="currentColor"
                                                                        viewBox="0 0 512 512">
                                                                        <path d="M256 8C119.043 8 8 119.043 8 256s111.043 
                                                                        248 248 248 248-111.043 248-248S392.957 8 
                                                                        256 8zm0 48c110.532 0 200 89.468 
                                                                        200 200s-89.468 200-200 200S56 366.532 56 
                                                                        256 145.468 56 256 56zm97.941 
                                                                        113.941l-123.514 123.515-51.429-51.43c-9.373-9.372-24.568-9.372-33.941 
                                                                        0-9.372 9.373-9.372 24.569 0 
                                                                        33.941l68.4 68.4c9.373 9.373 24.568 
                                                                        9.373 33.941 0l140.485-140.486c9.372-9.372 
                                                                        9.372-24.568 0-33.941-9.373-9.372-24.569-9.372-33.942 0z" />
                                                                    </svg>

                                                                    Verified Purchase
                                                                </span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <div class="flex items-center mt-1">
                                                            <?= renderStars($review['rating']) ?>
                                                            <span class="ml-2 text-sm text-gray-600">
                                                                <?= date('M j, Y', strtotime($review['created_at'])) ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <?php if (!empty($review['title'])): ?>
                                                <h4 class="font-medium text-gray-900 mb-3"><?= htmlspecialchars($review['title']) ?></h4>
                                            <?php endif; ?>

                                            <p class="text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($review['review'])) ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Shipping Tab -->
                <div id="shipping-tab" class="tab-content hidden">
                    <div class="prose max-w-none">
                        <h3>Shipping Information</h3>
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <ul class="space-y-2 text-gray-700">
                                <li><strong>Free Shipping:</strong> Orders above ₹999</li>
                                <li><strong>Express Shipping:</strong> ₹199 (1-2 business days)</li>
                                <li><strong>Standard Delivery:</strong> 3-7 business days</li>
                                <li><strong>Coverage:</strong> Pan-India delivery available</li>
                            </ul>
                        </div>

                        <h3>Returns & Exchanges</h3>
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <ul class="space-y-2 text-gray-700">
                                <li><strong>Return Window:</strong> 7 days from delivery date</li>
                                <li><strong>Condition:</strong> Items must be unused with original tags</li>
                                <li><strong>Refund Process:</strong> 5-7 business days after return received</li>
                                <li><strong>Return Pickup:</strong> Available at your doorstep</li>
                            </ul>
                        </div>

                        <div class="bg-gray-100 rounded-lg p-4 text-center">
                            <p class="text-sm text-gray-600 mb-2">
                                <strong>Questions?</strong> Contact our support team
                            </p>
                            <p class="text-sm text-gray-600">
                                📧 support@yourstore.com
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Related Products -->
        <?php if (!empty($related_products)): ?>
            <div class="mt-12">
                <h2 class="text-2xl font-bold text-gray-900 mb-8">You might also like</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <?php foreach ($related_products as $related): ?>
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow duration-200">
                            <div class="aspect-square bg-gray-100 relative overflow-hidden group">
                                <a href="product_detail.php?id=<?= $related['id'] ?>">
                                    <img src="<?= IMAGES_URL . '/' . htmlspecialchars($related['image']) ?>"
                                        alt="<?= htmlspecialchars($related['name']) ?>"
                                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                        loading="lazy">
                                </a>
                                <?php if ($related['sale_price'] && $related['sale_price'] < $related['price']): ?>
                                    <div class="absolute top-2 left-2 bg-red-500 text-white px-2 py-1 rounded text-xs font-medium">
                                        <?= round((($related['price'] - $related['sale_price']) / $related['price']) * 100) ?>% OFF
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="p-4">
                                <h3 class="font-medium text-gray-900 mb-2">
                                    <a href="product_detail.php?id=<?= $related['id'] ?>" class="hover:text-gray-700">
                                        <?= htmlspecialchars($related['name']) ?>
                                    </a>
                                </h3>
                                <div class="flex items-center space-x-2">
                                    <span class="font-bold text-gray-900">
                                        ₹<?= number_format($related['sale_price'] ?: $related['price'], 2) ?>
                                    </span>
                                    <?php if ($related['sale_price'] && $related['sale_price'] < $related['price']): ?>
                                        <span class="text-sm text-gray-500 line-through">
                                            ₹<?= number_format($related['price'], 2) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Image Zoom Modal -->
<div id="imageZoomModal" class="fixed inset-0 bg-black/90 z-50 hidden items-center justify-center p-4">
    <!-- Modal Container -->
    <div class="relative bg-white rounded-lg shadow-2xl max-w-5xl max-h-[95vh] w-full h-full flex items-center justify-center overflow-hidden">

        <!-- Close Button - Fixed positioning and z-index -->
        <button id="closeZoomModal"
            class="absolute top-2 right-2 z-[60] bg-red-500 hover:bg-red-600 text-white rounded-full p-3 shadow-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-red-300"
            onclick="event.stopPropagation();">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <!-- Zoom Controls -->
        <div class="absolute top-2 left-2 z-[60] flex flex-col space-y-2">
            <button id="zoomIn"
                class="bg-blue-500 hover:bg-blue-600 text-white rounded-full p-3 shadow-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-300"
                onclick="event.stopPropagation();">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
            </button>
            <button id="zoomOut"
                class="bg-blue-500 hover:bg-blue-600 text-white rounded-full p-3 shadow-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-300"
                onclick="event.stopPropagation();">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 12H6" />
                </svg>
            </button>
            <button id="resetZoom"
                class="bg-blue-500 hover:bg-blue-600 text-white rounded-full p-3 shadow-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-300"
                onclick="event.stopPropagation();">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </button>
        </div>

        <!-- Zoom Level Indicator -->
        <div class="absolute bottom-2 left-2 z-[60]">
            <div class="bg-gray-800/80 text-white px-3 py-1 rounded-full text-sm shadow-lg">
                <span id="zoomLevel">100%</span>
            </div>
        </div>

        <!-- Image Container -->
        <div id="imageContainer" class="relative w-full h-full p-4 overflow-hidden select-none">
            <img id="zoomImage"
                src=""
                alt="Product Image"
                class="w-full h-full object-contain transition-transform duration-200 ease-out"
                draggable="false">
        </div>

        <!-- Loading Spinner -->
        <div id="imageLoader" class="absolute inset-0 flex items-center justify-center bg-white rounded-lg">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
        </div>
    </div>
</div>

<!-- JavaScript for Interactive Features -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Image Zoom Modal Class - Updated and Fixed
        class ImageZoomModal {
            constructor() {
                this.modal = document.getElementById('imageZoomModal');
                this.zoomImage = document.getElementById('zoomImage');
                this.imageContainer = document.getElementById('imageContainer');
                this.imageLoader = document.getElementById('imageLoader');
                this.zoomLevel = document.getElementById('zoomLevel');

                this.currentZoom = 1;
                this.minZoom = 0.5;
                this.maxZoom = 3;
                this.zoomStep = 0.25;

                this.isDragging = false;
                this.startX = 0;
                this.startY = 0;
                this.translateX = 0;
                this.translateY = 0;

                this.init();
            }

            init() {
                // Button event listeners with proper event stopping
                document.getElementById('closeZoomModal').addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.closeModal();
                });

                document.getElementById('zoomIn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.zoomIn();
                });

                document.getElementById('zoomOut').addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.zoomOut();
                });

                document.getElementById('resetZoom').addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.resetZoom();
                });

                // Modal background click to close
                this.modal.addEventListener('click', (e) => {
                    if (e.target === this.modal) {
                        this.closeModal();
                    }
                });

                // Prevent modal content clicks from closing modal
                this.modal.querySelector('.relative').addEventListener('click', (e) => {
                    e.stopPropagation();
                });

                // Keyboard events
                document.addEventListener('keydown', (e) => this.handleKeyboard(e));

                // Mouse wheel zoom
                this.imageContainer.addEventListener('wheel', (e) => this.handleWheel(e), {
                    passive: false
                });

                // Mouse and touch events
                this.setupInteractionEvents();

                // Prevent context menu
                this.zoomImage.addEventListener('contextmenu', (e) => e.preventDefault());
            }

            setupInteractionEvents() {
                let touchStartTime = 0;
                let touchCount = 0;

                // Mouse events
                this.imageContainer.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    this.startDrag(e.clientX, e.clientY);
                });

                document.addEventListener('mousemove', (e) => {
                    this.drag(e.clientX, e.clientY);
                });

                document.addEventListener('mouseup', () => {
                    this.endDrag();
                });

                // Touch events for mobile
                this.imageContainer.addEventListener('touchstart', (e) => {
                    e.preventDefault();

                    const now = Date.now();
                    const touch = e.touches[0];

                    // Double tap detection
                    if (now - touchStartTime < 300) {
                        touchCount++;
                        if (touchCount === 2) {
                            this.handleDoubleTap(touch.clientX, touch.clientY);
                            touchCount = 0;
                            return;
                        }
                    } else {
                        touchCount = 1;
                    }
                    touchStartTime = now;

                    // Single touch drag
                    if (e.touches.length === 1) {
                        this.startDrag(touch.clientX, touch.clientY);
                    }
                }, {
                    passive: false
                });

                this.imageContainer.addEventListener('touchmove', (e) => {
                    e.preventDefault();
                    if (e.touches.length === 1) {
                        const touch = e.touches[0];
                        this.drag(touch.clientX, touch.clientY);
                    }
                }, {
                    passive: false
                });

                this.imageContainer.addEventListener('touchend', (e) => {
                    e.preventDefault();
                    this.endDrag();
                }, {
                    passive: false
                });

                // Double click for desktop
                this.zoomImage.addEventListener('dblclick', (e) => {
                    this.handleDoubleTap(e.clientX, e.clientY);
                });
            }

            openModal(imageSrc) {
                this.showLoader();
                this.modal.classList.remove('hidden');
                this.modal.classList.add('flex');
                document.body.classList.add('overflow-hidden');

                const img = new Image();
                img.onload = () => {
                    this.zoomImage.src = imageSrc;
                    this.resetZoom();
                    this.hideLoader();
                };
                img.onerror = () => {
                    this.hideLoader();
                    this.closeModal();
                    alert('Failed to load image');
                };
                img.src = imageSrc;
            }

            closeModal() {
                this.modal.classList.add('hidden');
                this.modal.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
                this.resetZoom();
            }

            showLoader() {
                this.imageLoader.classList.remove('hidden');
            }

            hideLoader() {
                this.imageLoader.classList.add('hidden');
            }

            zoomIn() {
                if (this.currentZoom < this.maxZoom) {
                    this.currentZoom = Math.min(this.currentZoom + this.zoomStep, this.maxZoom);
                    this.updateZoom();
                }
            }

            zoomOut() {
                if (this.currentZoom > this.minZoom) {
                    this.currentZoom = Math.max(this.currentZoom - this.zoomStep, this.minZoom);
                    this.updateZoom();
                }
            }

            resetZoom() {
                this.currentZoom = 1;
                this.translateX = 0;
                this.translateY = 0;
                this.updateZoom();
            }

            updateZoom() {
                const transform = `translate(${this.translateX}px, ${this.translateY}px) scale(${this.currentZoom})`;
                this.zoomImage.style.transform = transform;
                this.zoomImage.style.transformOrigin = 'center center';
                this.zoomLevel.textContent = Math.round(this.currentZoom * 100) + '%';

                // Update cursor
                if (this.currentZoom > 1) {
                    this.imageContainer.style.cursor = this.isDragging ? 'grabbing' : 'grab';
                } else {
                    this.imageContainer.style.cursor = 'default';
                }
            }

            handleWheel(e) {
                e.preventDefault();
                const delta = e.deltaY > 0 ? -this.zoomStep : this.zoomStep;
                const newZoom = Math.max(this.minZoom, Math.min(this.maxZoom, this.currentZoom + delta));

                if (newZoom !== this.currentZoom) {
                    this.currentZoom = newZoom;
                    this.updateZoom();
                }
            }

            startDrag(clientX, clientY) {
                if (this.currentZoom <= 1) return;

                this.isDragging = true;
                this.startX = clientX - this.translateX;
                this.startY = clientY - this.translateY;
                this.updateZoom();
            }

            drag(clientX, clientY) {
                if (!this.isDragging || this.currentZoom <= 1) return;

                this.translateX = clientX - this.startX;
                this.translateY = clientY - this.startY;
                this.updateZoom();
            }

            endDrag() {
                if (this.isDragging) {
                    this.isDragging = false;
                    this.updateZoom();
                }
            }

            handleDoubleTap(clientX, clientY) {
                if (this.currentZoom === 1) {
                    this.currentZoom = 2;
                } else {
                    this.resetZoom();
                }
                this.updateZoom();
            }

            handleKeyboard(e) {
                if (this.modal.classList.contains('hidden')) return;

                switch (e.key) {
                    case 'Escape':
                        this.closeModal();
                        break;
                    case '+':
                    case '=':
                        e.preventDefault();
                        this.zoomIn();
                        break;
                    case '-':
                        e.preventDefault();
                        this.zoomOut();
                        break;
                    case '0':
                        e.preventDefault();
                        this.resetZoom();
                        break;
                }
            }
        }

        // Initialize zoom modal
        const imageZoomModal = new ImageZoomModal();

        // Global function to open zoom (called from main image)
        window.openImageZoom = function() {
            const mainImage = document.getElementById('main-image');
            if (mainImage) {
                imageZoomModal.openModal(mainImage.src);
            }
        };

        // Tab functionality
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');

        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const targetTab = this.dataset.tab;

                // Remove active states
                tabButtons.forEach(btn => {
                    btn.classList.remove('active', 'border-black', 'text-black');
                    btn.classList.add('border-transparent', 'text-gray-500');
                });

                tabContents.forEach(content => {
                    content.classList.add('hidden');
                });

                // Add active states
                this.classList.add('active', 'border-black', 'text-black');
                this.classList.remove('border-transparent', 'text-gray-500');
                document.getElementById(targetTab + '-tab').classList.remove('hidden');
            });
        });

        // Gallery swipe functionality
        const mainImageContainer = document.getElementById('main-image-container');
        if (mainImageContainer) {
            let touchStartX = 0;
            let touchEndX = 0;
            let currentImageIndex = 0;

            // Get all gallery images from thumbnails
            const galleryImages = Array.from(document.querySelectorAll('.gallery-thumbnail img')).map(img => img.src);

            // Only enable swipe if there are multiple images
            if (galleryImages.length > 1) {
                mainImageContainer.addEventListener('touchstart', e => {
                    touchStartX = e.changedTouches[0].screenX;
                }, {
                    passive: true
                });

                mainImageContainer.addEventListener('touchend', e => {
                    touchEndX = e.changedTouches[0].screenX;
                    handleSwipe();
                }, {
                    passive: true
                });

                function handleSwipe() {
                    const threshold = 50; // Minimum swipe distance to trigger

                    if (touchStartX - touchEndX > threshold) {
                        // Swipe left - next image
                        changeGalleryImage(currentImageIndex + 1);
                    } else if (touchEndX - touchStartX > threshold) {
                        // Swipe right - previous image
                        changeGalleryImage(currentImageIndex - 1);
                    }
                }
            }

            window.changeGalleryImage = function(index) {
                if (galleryImages.length === 0) return;

                if (index < 0) index = galleryImages.length - 1;
                if (index >= galleryImages.length) index = 0;

                currentImageIndex = index;
                document.getElementById('main-image').src = galleryImages[index];

                // Update active thumbnail
                document.querySelectorAll('.gallery-thumbnail').forEach((thumb, i) => {
                    thumb.classList.toggle('border-black', i === index);
                    thumb.classList.toggle('border-gray-200', i !== index);
                });
            }
        }

        // Quantity controls
        const decreaseBtn = document.getElementById('decreaseQty');
        const increaseBtn = document.getElementById('increaseQty');
        const quantityInput = document.getElementById('quantity');
        const selectedQuantity = document.getElementById('selected-quantity');

        decreaseBtn.addEventListener('click', function() {
            const currentValue = parseInt(quantityInput.value);
            if (currentValue > 1) {
                quantityInput.value = currentValue - 1;
                selectedQuantity.value = quantityInput.value;
            }
        });

        increaseBtn.addEventListener('click', function() {
            const currentValue = parseInt(quantityInput.value);
            const maxValue = parseInt(quantityInput.max);
            if (currentValue < maxValue) {
                quantityInput.value = currentValue + 1;
                selectedQuantity.value = quantityInput.value;
            }
        });

        quantityInput.addEventListener('input', function() {
            selectedQuantity.value = this.value;
        });

        // Form submission handlers
        document.getElementById('addToCartForm').addEventListener('submit', function(e) {
            const selectedVariantId = document.getElementById('selected-variant-id');
            if (!selectedVariantId || !selectedVariantId.value) {
                e.preventDefault();
                alert('Please select a valid product variant');
                return;
            }
        });

        // Review form submission
        const reviewForm = document.getElementById('review-form');
        if (reviewForm) {
            const ratingStars = reviewForm.querySelectorAll('.rating-star');
            const ratingInput = document.getElementById('rating-input');
            const reviewText = document.getElementById('review-text');
            const charCount = document.getElementById('char-count');

            // Star rating functionality
            let selectedRating = 0;

            ratingStars.forEach((star, index) => {
                star.addEventListener('click', function() {
                    selectedRating = parseInt(this.dataset.rating);
                    ratingInput.value = selectedRating;
                    updateStars();
                });

                star.addEventListener('mouseenter', function() {
                    const hoverRating = parseInt(this.dataset.rating);
                    highlightStars(hoverRating);
                });
            });

            if (ratingStars.length > 0) {
                ratingStars[0].parentElement.addEventListener('mouseleave', function() {
                    updateStars();
                });
            }

            function updateStars() {
                ratingStars.forEach((star, index) => {
                    if (index < selectedRating) {
                        star.classList.add('text-yellow-400');
                        star.classList.remove('text-gray-300');
                    } else {
                        star.classList.remove('text-yellow-400');
                        star.classList.add('text-gray-300');
                    }
                });
            }

            function highlightStars(rating) {
                ratingStars.forEach((star, index) => {
                    if (index < rating) {
                        star.classList.add('text-yellow-400');
                        star.classList.remove('text-gray-300');
                    } else {
                        star.classList.remove('text-yellow-400');
                        star.classList.add('text-gray-300');
                    }
                });
            }

            // Character count
            if (reviewText && charCount) {
                reviewText.addEventListener('input', function() {
                    charCount.textContent = this.value.length;
                });
            }

            // Review form submission
            reviewForm.addEventListener('submit', function(e) {
                e.preventDefault();

                if (selectedRating === 0) {
                    showNotification('Please select a rating.', 'error');
                    return;
                }

                const formData = new FormData(this);
                const submitButton = this.querySelector('button[type="submit"]');
                const originalText = submitButton.textContent;

                submitButton.disabled = true;
                submitButton.textContent = 'Submitting...';

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            // Reset form
                            reviewForm.reset();
                            selectedRating = 0;
                            updateStars();
                            if (charCount) charCount.textContent = '0';

                            // If the response indicates to reload, then reload the page after a delay
                            if (data.reload) {
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                            }
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    })
                    .finally(() => {
                        submitButton.disabled = false;
                        submitButton.textContent = originalText;
                    });
            });
        }

        // Add to Cart Form Submission
        document.getElementById('addToCartForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const addToCartBtn = document.getElementById('add-to-cart-btn');
            const cartBtnText = document.getElementById('cart-btn-text');
            const originalText = cartBtnText.textContent;

            // Disable button and show loading
            addToCartBtn.disabled = true;
            cartBtnText.textContent = 'Adding...';

            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update cart count in header
                        const cartCountElements = document.querySelectorAll('.cart-count');
                        cartCountElements.forEach(element => {
                            element.textContent = data.cart_count;
                            element.classList.remove('hidden');
                        });

                        // Show success message
                        showNotification(data.message, 'success');
                        cartBtnText.textContent = 'Added!';

                        // Reset button after 2 seconds
                        setTimeout(() => {
                            cartBtnText.textContent = originalText;
                            addToCartBtn.disabled = false;
                        }, 2000);
                    } else {
                        if (data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        showNotification(data.message, 'error');
                        cartBtnText.textContent = originalText;
                        addToCartBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred. Please try again.', 'error');
                    cartBtnText.textContent = originalText;
                    addToCartBtn.disabled = false;
                });
        });

        // Wishlist functionality
        document.getElementById('add-to-wishlist-btn').addEventListener('click', function() {
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            formData.append('variant_id', document.getElementById('selected-variant-id').value);
            formData.append('action', 'add_to_wishlist');

            const wishlistIcon = document.getElementById('wishlist-icon');

            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update wishlist icon
                        if (data.in_wishlist) {
                            wishlistIcon.setAttribute('fill', 'currentColor');
                            wishlistIcon.classList.add('text-red-500');
                        } else {
                            wishlistIcon.setAttribute('fill', 'none');
                            wishlistIcon.classList.remove('text-red-500');
                        }

                        showNotification(data.message, 'success');
                    } else {
                        if (data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred. Please try again.', 'error');
                });
        });
    });

    // Notification function
    function showNotification(message, type = 'info') {
        // Remove any existing notifications
        const existingNotifications = document.querySelectorAll('.notification-toast');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification-toast fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;

        // Set colors based on type
        const colors = {
            success: 'bg-green-500 text-white',
            error: 'bg-red-500 text-white',
            info: 'bg-blue-500 text-white'
        };

        notification.className += ` ${colors[type] || colors.info}`;
        notification.textContent = message;

        // Add to DOM
        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.classList.remove('translate-x-full');
        }, 100);

        // Auto remove after 3 seconds
        setTimeout(() => {
            notification.classList.add('translate-x-full');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }
</script>

<?php include '../includes/footer.php'; ?>