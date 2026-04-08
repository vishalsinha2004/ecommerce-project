<?php

/**
 * Product List Page - Complete Working Version
 * Dynamic Ecommerce Website - Women's Dresses
 * 
 * Features: Advanced filtering, sorting, pagination, mobile-first design
 * Compatible with existing config.php and db.php architecture
 * 
 * @author Your Name
 * @version 3.0
 * @since 2025-01-31
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required files
require_once '../includes/config.php';
require_once '../includes/db.php';


// Initialize wishlist status array
$wishlist_status = [];

// Check if user is logged in and fetch their wishlist items
if (isset($_SESSION['user_id'])) {
    try {
        $wishlist_result = $db->fetchAll(
            "SELECT product_id FROM " . DB_PREFIX . "wishlist WHERE user_id = ?",
            [$_SESSION['user_id']]
        );

        // Convert to a simple array of product IDs
        foreach ($wishlist_result as $item) {
            $wishlist_status[] = $item['product_id'];
        }
    } catch (Exception $e) {
        logMessage("Error fetching wishlist: " . $e->getMessage(), 'ERROR');
    }
}


// =============================================================================
// ADD TO CART HANDLING - FIXED
// =============================================================================
// =============================================================================
// ADD TO CART HANDLING - AJAX VERSION
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $productId = filter_var($_POST['product_id'], FILTER_VALIDATE_INT);
    $quantity = 1; // Default quantity

    if ($productId) {
        try {
            // Check if user is logged in
            if (!isset($_SESSION['user_id'])) {
                if ($is_ajax) {
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

            // Check if the product is already in cart
            $existingItem = $db->fetchRow(
                "SELECT id, quantity FROM " . DB_PREFIX . "cart WHERE user_id = ? AND product_id = ?",
                [$userId, $productId]
            );

            if ($existingItem) {
                // Update existing item
                $newQuantity = min($existingItem['quantity'] + $quantity, 10);
$updateData = [
    'quantity' => $newQuantity,
    'updated_at' => date('Y-m-d H:i:s')
];

// Also update color if provided
if (isset($_POST['color'])) {
    $updateData['color'] = $_POST['color'];
}

$db->update(
    'cart',
    $updateData,
    'id = :id',
    ['id' => $existingItem['id']]
);
            } else {
                // Add new item to cart
                $data = [
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'color' => $_POST['color'] ?? '',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $db->insert('cart', $data);
            }

            // Get updated cart count
            $cart_count_result = $db->fetchRow(
                "SELECT SUM(quantity) as total FROM " . DB_PREFIX . "cart WHERE user_id = ?",
                [$userId]
            );
            $cart_count = $cart_count_result['total'] ?? 0;

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Product added to cart!',
                    'cart_count' => $cart_count
                ]);
                exit;
            } else {
                $_SESSION['cart_success'] = 'Product added to cart!';
            }
        } catch (Exception $e) {
            error_log("Cart add error: " . $e->getMessage());

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to add product to cart'
                ]);
                exit;
            } else {
                $_SESSION['cart_error'] = 'Failed to add product to cart';
            }
        }
    }

    if (!$is_ajax) {
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}
// =============================================================================
// WISHLIST HANDLING - AJAX VERSION
// =============================================================================
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
$page_title = 'Shop Dresses - ' . SITE_NAME;
$page_description = 'Discover our complete collection of elegant women\'s dresses. Filter by style, size, color, and price to find your perfect dress.';

// Initialize filter parameters with proper sanitization
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$min_price = max(0, (float)($_GET['min_price'] ?? 0));
$max_price = max(0, (float)($_GET['max_price'] ?? 0));
$color = trim($_GET['color'] ?? '');
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12; // Products per page

// Special filters
$sale_only = isset($_GET['sale']);
$new_only = isset($_GET['new']);

// Validate sort parameters
$allowed_sorts = ['created_at', 'price', 'name', 'featured', 'rating'];
$allowed_orders = ['ASC', 'DESC'];
if (!in_array($sort_by, $allowed_sorts)) $sort_by = 'created_at';
if (!in_array($sort_order, $allowed_orders)) $sort_order = 'DESC';

// Build filter array for getProducts function
$filters = [];
if (!empty($search)) $filters['search'] = $search;
if (!empty($category)) $filters['category'] = $category;
if ($min_price > 0) $filters['min_price'] = $min_price;
if ($max_price > 0) $filters['max_price'] = $max_price;
if (!empty($color)) $filters['color'] = $color; // CHANGED FROM $filters['colors'] = [$color]
if ($sale_only) $filters['sale'] = true;
if ($new_only) $filters['new'] = true;
// Build sort string
$order_string = $sort_by;
// if ($sort_by === 'price') {
//     $order_string = 'final_price';
// } else if ($sort_by === 'name') {
//     $order_string = 'p.name';
// }
$order_string .= ' ' . $sort_order;
try {
    // Get products using the utility function from db.php
    $products = getProducts($filters, $order_string, $page, $per_page);

    // Build proper where clause for count query
    $total_products = getProductsCount($filters);
} catch (Exception $e) {
    logMessage("Error fetching products: " . $e->getMessage(), 'ERROR');
    $products = [];
    $total_products = 0;
}

$total_pages = ceil($total_products / $per_page);
// Fetch categories for filter dropdown
$categories = [];
try {
    $categories = $db->fetchAll(
        "SELECT c.id, c.name, c.slug 
         FROM " . DB_PREFIX . "categories c
         WHERE c.status = 'active' 
         ORDER BY c.name"
    );
} catch (Exception $e) {
    logMessage("Error fetching categories: " . $e->getMessage(), 'ERROR');
    $categories = [];
}

// Get price range for filters
$price_range = ['min' => 0, 'max' => 1000];
try {
    $price_data = $db->fetchRow(
        "SELECT MIN(sale_price) as min_price, 
                MAX(sale_price) as max_price 
         FROM " . DB_PREFIX . "products 
         WHERE status = 'active' AND sale_price IS NOT NULL AND sale_price > 0"
    );
    if ($price_data) {
        $price_range['min'] = floor($price_data['min_price'] ?? 0);
        $price_range['max'] = ceil($price_data['max_price'] ?? 1000);
    }
} catch (Exception $e) {
    logMessage("Error fetching price range: " . $e->getMessage(), 'ERROR');
}

// Available sizes and colors (you can make these dynamic from database)
// --- ADD THIS NEW BLOCK ---
// Dynamically get all unique colors from active products
$available_colors = [];
try {
    $color_results = $db->fetchAll(
        "SELECT DISTINCT available_colors FROM " . DB_PREFIX . "products WHERE status = 'active' AND available_colors IS NOT NULL AND available_colors != ''"
    );
    
    $all_colors = [];
    foreach ($color_results as $row) {
        // Split the comma-separated string of colors and merge into one array
        $all_colors = array_merge($all_colors, array_map('trim', explode(',', $row['available_colors'])));
    }
    
    // Get only the unique colors and sort them alphabetically
    $available_colors = array_unique($all_colors);
    sort($available_colors);

} catch (Exception $e) {
    logMessage("Error fetching available colors: " . $e->getMessage(), 'ERROR');
    // Fallback to a default list if the query fails
    $available_colors = ['Black', 'White', 'Red', 'Blue'];
}

// Helper function for color hex codes
function getColorHex($colorName)
{
    $colors = [
        'Black' => '#000000',
        'White' => '#FFFFFF',
        'Red' => '#DC2626',
        'Blue' => '#2563EB',
        'Green' => '#16A34A',
        'Pink' => '#EC4899',
        'Purple' => '#9333EA',
        'Yellow' => '#EAB308',
        'Navy' => '#1E3A8A',
        'Gray' => '#6B7280'
    ];
    return $colors[$colorName] ?? '#6B7280';
}

// Helper function to build URLs
function buildFilterUrl($params = [])
{
    $current = $_GET;
    unset($current['page']); // Remove page parameter when filtering
    $merged = array_merge($current, $params);

    // Remove empty values
    $merged = array_filter($merged, function ($value) {
        return $value !== '' && $value !== null && $value !== 0;
    });

    $query = http_build_query($merged);
    return $_SERVER['PHP_SELF'] . ($query ? '?' . $query : '');
}

// Helper function to remove filter parameters
function removeFilterUrl($remove_params)
{
    $current = $_GET;
    if (is_array($remove_params)) {
        foreach ($remove_params as $param) {
            unset($current[$param]);
        }
    } else {
        unset($current[$remove_params]);
    }

    $query = http_build_query($current);
    return $_SERVER['PHP_SELF'] . ($query ? '?' . $query : '');
}

// Include header
include '../includes/header.php';
?>

<!-- Page Header -->
<section class="bg-gradient-to-br from-secondary via-gray-light to-accent-lavender/20 py-12 lg:py-16">
    <div class="container mx-auto px-4">
        <!-- Breadcrumb -->
        <nav class="flex items-center space-x-2 text-sm text-text-secondary mb-6" aria-label="Breadcrumb">
            <a href="<?php echo BASE_URL; ?>" class="hover:text-text-primary transition-colors duration-200">Home</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
            <span class="text-text-primary font-medium">Shop Dresses</span>
        </nav>

        <!-- Page Title -->
        <div class="text-center lg:text-left">
            <h1 class="text-3xl lg:text-4xl font-poppins font-bold text-text-primary mb-4">
                <?php if (!empty($search)): ?>
                    Search Results for "<?php echo htmlspecialchars($search); ?>"
                <?php elseif (!empty($category)):
                    $category_name = '';
                    foreach ($categories as $cat) {
                        if ($cat['id'] == $category) {
                            $category_name = $cat['name'];
                            break;
                        }
                    }
                ?>
                    <?php echo htmlspecialchars($category_name); ?> Dresses
                <?php elseif ($sale_only): ?>
                    Sale Dresses
                <?php elseif ($new_only): ?>
                    New Arrivals
                <?php else: ?>
                    All Dresses
                <?php endif; ?>
            </h1>
            <p class="text-text-secondary text-lg mb-6">
                <?php if ($total_products > 0): ?>
                    Showing <?php echo number_format($total_products); ?> <?php echo $total_products === 1 ? 'dress' : 'dresses'; ?>
                <?php else: ?>
                    No dresses found matching your criteria
                <?php endif; ?>
            </p>

            <!-- Quick Filter Tags -->
           <div class="flex flex-wrap gap-3 justify-center lg:justify-start">
    
    <a href="<?php echo $_SERVER['PHP_SELF']; ?>"
       class="px-4 py-2 bg-white/80 backdrop-blur-sm border border-border-light rounded-lg hover:bg-white hover:shadow-md transition-all duration-200 text-sm 
       <?php echo (empty($_GET) || (count($_GET) === 1 && isset($_GET['page']))) ? 'bg-accent-pink/20 border-accent-pink text-accent-pink font-medium' : ''; ?>">
        All Dresses
    </a>

    <a href="<?php echo $new_only ? removeFilterUrl('new') : buildFilterUrl(['new' => '1', 'sale' => null]); ?>"
       class="px-4 py-2 bg-white/80 backdrop-blur-sm border border-border-light rounded-lg hover:bg-white hover:shadow-md transition-all duration-200 text-sm 
       <?php echo $new_only ? 'bg-accent-pink/20 border-accent-pink text-accent-pink font-medium' : ''; ?>">
        New Arrivals
        <?php if($new_only): ?> <span class="ml-1 text-xs">✕</span> <?php endif; ?>
    </a>

    <a href="<?php echo $sale_only ? removeFilterUrl('sale') : buildFilterUrl(['sale' => '1', 'new' => null]); ?>"
       class="px-4 py-2 bg-white/80 backdrop-blur-sm border border-border-light rounded-lg hover:bg-white hover:shadow-md transition-all duration-200 text-sm 
       <?php echo $sale_only ? 'bg-accent-pink/20 border-accent-pink text-accent-pink font-medium' : ''; ?>">
        Sale Items
        <?php if($sale_only): ?> <span class="ml-1 text-xs">✕</span> <?php endif; ?>
    </a>
    
</div>
        </div>
    </div>
</section>

<!-- Main Content -->
<main class="py-8 lg:py-12">
    <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row gap-8">

            <!-- Desktop Filters Sidebar -->
            <aside class="hidden lg:block w-80 flex-shrink-0">
                <div class="bg-accent-lavender/5 backdrop-blur-sm rounded-2xl border border-border-light p-6 sticky top-24">
                    <h3 class="text-lg font-semibold text-text-primary mb-6">Filter & Sort</h3>

                    <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="space-y-6">
                        <!-- Search -->
                        <div>
                            <label for="search" class="block text-sm font-medium text-text-primary mb-2">Search</label>
                            <div class="relative">
                                <input type="text"
                                    id="search"
                                    name="search"
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    placeholder="Search dresses..."
                                    class="w-full pl-10 pr-4 py-3 bg-white border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 focus:border-accent-pink transition-all duration-200 text-sm">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Category -->
                        <div>
                            <label for="category" class="block text-sm font-medium text-text-primary mb-2">Category</label>
                            <div class="relative">
                                <select id="category"
                                    name="category"
                                    class="w-full px-4 py-3 bg-white border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 focus:border-accent-pink transition-all duration-200 text-sm appearance-none cursor-pointer">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['id']); ?>"
                                            <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Price Range -->
                        <div>
                            <label class="block text-sm font-medium text-text-primary mb-2">Price Range</label>
                            <div class="grid grid-cols-2 gap-3">
                                <input type="number"
                                    name="min_price"
                                    value="<?php echo $min_price > 0 ? $min_price : ''; ?>"
                                    placeholder="Min ($)"
                                    min="0"
                                    max="<?php echo $price_range['max']; ?>"
                                    class="px-3 py-2.5 bg-white border border-border-light rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm">
                                <input type="number"
                                    name="max_price"
                                    value="<?php echo $max_price > 0 ? $max_price : ''; ?>"
                                    placeholder="Max ($)"
                                    min="0"
                                    max="<?php echo $price_range['max']; ?>"
                                    class="px-3 py-2.5 bg-white border border-border-light rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm">
                            </div>
                            <div class="text-xs text-text-secondary mt-2 text-center">
                                Range: $<?php echo $price_range['min']; ?> - $<?php echo $price_range['max']; ?>
                            </div>
                        </div>



                        <!-- Color -->
                        <div>
                            <label class="block text-sm font-medium text-text-primary mb-2">Color</label>
                            <div class="grid grid-cols-2 gap-2 bg-accent-lavender/10 p-3 rounded-lg max-h-48 overflow-y-auto">
                                <?php foreach ($available_colors as $available_color): ?>
                                    <label class="relative cursor-pointer">
                                        <input type="radio"
                                            name="color"
                                            value="<?php echo $available_color; ?>"
                                            <?php echo $color === $available_color ? 'checked' : ''; ?>
                                            class="sr-only color-radio">
                                        <div class="flex items-center p-2.5 bg-white border-2 border-border-light rounded-lg text-sm hover:border-accent-pink/50 hover:bg-accent-pink/5 transition-all duration-200">
                                            <span><?php echo $available_color; ?></span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Sort -->
                        <div>
                            <label for="sort" class="block text-sm font-medium text-text-primary mb-2">Sort By</label>
                            <div class="relative">
                                <select id="sort"
                                    name="sort"
                                    class="w-full px-4 py-3 bg-white border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm appearance-none cursor-pointer">
                                    <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="price" <?php echo $sort_by === 'price' ? 'selected' : ''; ?>>Price</option>
                                    <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name A-Z</option>
                                    <option value="featured" <?php echo $sort_by === 'featured' ? 'selected' : ''; ?>>Featured</option>
                                    <option value="rating" <?php echo $sort_by === 'rating' ? 'selected' : ''; ?>>Rating</option>

                                </select>
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Sort Order -->
                        <div>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="relative cursor-pointer">
                                    <input type="radio"
                                        name="order"
                                        value="DESC"
                                        <?php echo $sort_order === 'DESC' ? 'checked' : ''; ?>
                                        class="sr-only order-radio">
                                    <div class="flex items-center justify-center p-3 bg-white border-2 border-border-light rounded-lg text-sm font-medium transition-all duration-200 hover:border-accent-pink/50 hover:bg-accent-pink/5">
                                        High to Low
                                    </div>
                                </label>
                                <label class="relative cursor-pointer">
                                    <input type="radio"
                                        name="order"
                                        value="ASC"
                                        <?php echo $sort_order === 'ASC' ? 'checked' : ''; ?>
                                        class="sr-only order-radio">
                                    <div class="flex items-center justify-center p-3 bg-white border-2 border-border-light rounded-lg text-sm font-medium transition-all duration-200 hover:border-accent-pink/50 hover:bg-accent-pink/5">
                                        Low to High
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Filter Actions -->
                        <div class="space-y-3">
                            <button type="submit"
                                class="w-full py-3 bg-gradient-to-r from-accent-pink to-accent-lavender text-text-primary font-semibold rounded-xl hover:shadow-lg hover:scale-105 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-accent-pink/50">
                                Apply Filters
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>"
                                class="block w-full py-3 bg-white border border-border-light text-text-secondary font-medium rounded-xl hover:bg-gray-light transition-all duration-200 text-center">
                                Clear All Filters
                            </a>
                        </div>
                    </form>
                </div>
            </aside>

            <!-- Main Product Area -->
            <div class="flex-1">

                <!-- Mobile Filter Toggle -->
                <div class="lg:hidden mb-6">
                    <button onclick="toggleMobileFilters()"
                        class="w-full flex items-center justify-between px-4 py-3 bg-white/80 backdrop-blur-sm border border-border-light rounded-lg hover:bg-white hover:shadow-md transition-all duration-200">
                        <span class="font-medium text-text-primary">Filters & Sort</span>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-text-secondary">
                                <?php
                                $active_filters = 0;
                                if (!empty($search)) $active_filters++;
                                if (!empty($category)) $active_filters++;
                                if ($min_price > 0 || $max_price > 0) $active_filters++;
                                if (!empty($color)) $active_filters++;
                                echo $active_filters > 0 ? $active_filters . ' active' : '';
                                ?>
                            </span>
                            <svg class="w-5 h-5 text-text-secondary transition-transform duration-200" id="filter-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </button>
                </div>

                <!-- Mobile Filters -->
                <div class="lg:hidden hidden mb-6" id="mobile-filters">
                    <div class="bg-white/95 backdrop-blur-sm rounded-2xl border border-border-light p-6">
                        <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                            <div class="space-y-4">
                                <!-- Mobile Search -->
                                <div>
                                    <label class="block text-sm font-medium text-text-primary mb-2">Search</label>
                                    <input type="text"
                                        name="search"
                                        value="<?php echo htmlspecialchars($search); ?>"
                                        placeholder="Search dresses..."
                                        class="w-full px-4 py-3 bg-white border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm">
                                </div>

                                <!-- Mobile Category -->
                                <div>
                                    <label class="block text-sm font-medium text-text-primary mb-2">Category</label>
                                    <select name="category" class="w-full px-4 py-3 bg-white border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm appearance-none">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat['id']); ?>"
                                                <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Mobile Price -->
                                <div>
                                    <label class="block text-sm font-medium text-text-primary mb-2">Price Range</label>
                                    <div class="grid grid-cols-2 gap-3">
                                        <input type="number" name="min_price" value="<?php echo $min_price > 0 ? $min_price : ''; ?>" placeholder="Min ($)" class="px-3 py-2.5 bg-white border border-border-light rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm">
                                        <input type="number" name="max_price" value="<?php echo $max_price > 0 ? $max_price : ''; ?>" placeholder="Max ($)" class="px-3 py-2.5 bg-white border border-border-light rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm">
                                    </div>
                                </div>


                                <!-- Color Filter (Mobile/Drawer Only) -->
                                <div>
                                    <label for="color-mobile" class="block text-sm font-medium text-text-primary mb-2">Color</label>
                                    <div class="relative">
                                        <select id="color-mobile" name="color" class="w-full px-4 py-3 bg-white border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm appearance-none cursor-pointer">
                                            <option value="">All Colors</option>
                                            <?php foreach ($available_colors as $available_color): ?>
                                                <option value="<?php echo $available_color; ?>" <?php echo $color === $available_color ? 'selected' : ''; ?>>
                                                    <?php echo $available_color; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                            <svg class="h-5 w-5 text-text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>


                                <!-- Mobile Sort -->
                                <div>
                                    <label class="block text-sm font-medium text-text-primary mb-2">Sort By</label>
                                    <select name="sort" class="w-full px-4 py-3 bg-white border border-border-light rounded-xl focus:outline-none focus:ring-2 focus:ring-accent-pink/40 text-sm appearance-none">
                                        <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="price" <?php echo $sort_by === 'price' ? 'selected' : ''; ?>>Price</option>
                                        <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name A-Z</option>
                                        <option value="featured" <?php echo $sort_by === 'featured' ? 'selected' : ''; ?>>Featured</option>
                                        <option value="rating" <?php echo $sort_by === 'rating' ? 'selected' : ''; ?>>Rating</option>

                                    </select>
                                </div>
                            </div>
                            <!-- Sort Order -->
                            <!-- Sort Order -->
                            <div class="mt-4 md:mt-0"> <!-- Added mt-4 for mobile, no margin on desktop -->
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="relative cursor-pointer">
                                        <input type="radio"
                                            name="order"
                                            value="DESC"
                                            <?php echo $sort_order === 'DESC' ? 'checked' : ''; ?>
                                            class="sr-only order-radio">
                                        <div class="flex items-center justify-center p-3 bg-white border-2 border-border-light rounded-lg text-sm font-medium transition-all duration-200 hover:border-accent-pink/50 hover:bg-accent-pink/5">
                                            High to Low
                                        </div>
                                    </label>
                                    <label class="relative cursor-pointer">
                                        <input type="radio"
                                            name="order"
                                            value="ASC"
                                            <?php echo $sort_order === 'ASC' ? 'checked' : ''; ?>
                                            class="sr-only order-radio">
                                        <div class="flex items-center justify-center p-3 bg-white border-2 border-border-light rounded-lg text-sm font-medium transition-all duration-200 hover:border-accent-pink/50 hover:bg-accent-pink/5">
                                            Low to High
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Mobile Actions -->
                            <div class="mt-6 space-y-3">
                                <button type="submit" class="w-full py-3 bg-gradient-to-r from-accent-pink to-accent-lavender text-text-primary font-semibold rounded-xl hover:shadow-lg transition-all duration-200">
                                    Apply Filters
                                </button>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="block w-full py-3 bg-white border border-border-light text-text-secondary font-medium rounded-xl text-center hover:bg-gray-light transition-all duration-200">
                                    Clear All
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Active Filters Display -->
                <?php
                $active_filter_count = 0;
                if (!empty($search)) $active_filter_count++;
                if (!empty($category)) $active_filter_count++;
                if ($min_price > 0 || $max_price > 0) $active_filter_count++;
                if (!empty($color)) $active_filter_count++;

                if ($active_filter_count > 0): ?>
                    <div class="mb-6">
                        <h4 class="text-sm font-medium text-text-primary mb-3">Active Filters:</h4>
                        <div class="flex flex-wrap gap-2">
                            <?php if (!empty($search)): ?>
                                <span class="inline-flex items-center px-3 py-1 bg-accent-pink/20 border border-accent-pink/30 text-accent-pink rounded-lg text-sm">
                                    Search: <?php echo htmlspecialchars($search); ?>
                                    <a href="<?php echo removeFilterUrl('search'); ?>" class="ml-2 hover:text-red-600">×</a>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($category)):
                                $category_name = '';
                                foreach ($categories as $cat) {
                                    if ($cat['id'] == $category) {
                                        $category_name = $cat['name'];
                                        break;
                                    }
                                }
                            ?>
                                <span class="inline-flex items-center px-3 py-1 bg-accent-pink/20 border border-accent-pink/30 text-accent-pink rounded-lg text-sm">
                                    Category: <?php echo htmlspecialchars($category_name); ?>
                                    <a href="<?php echo removeFilterUrl('category'); ?>" class="ml-2 hover:text-red-600">×</a>
                                </span>
                            <?php endif; ?>

                            <?php if ($min_price > 0 || $max_price > 0): ?>
                                <span class="inline-flex items-center px-3 py-1 bg-accent-pink/20 border border-accent-pink/30 text-accent-pink rounded-lg text-sm">
                                    Price: $<?php echo $min_price > 0 ? $min_price : '0'; ?> - $<?php echo $max_price > 0 ? $max_price : '∞'; ?>
                                    <a href="<?php echo removeFilterUrl(['min_price', 'max_price']); ?>" class="ml-2 hover:text-red-600">×</a>
                                </span>
                            <?php endif; ?>


                            <?php if (!empty($color)): ?>
                                <span class="inline-flex items-center px-3 py-1 bg-accent-pink/20 border border-accent-pink/30 text-accent-pink rounded-lg text-sm">
                                    Color: <?php echo htmlspecialchars($color); ?>
                                    <a href="<?php echo removeFilterUrl('color'); ?>" class="ml-2 hover:text-red-600">×</a>
                                </span>
                            <?php endif; ?>


                            <?php if ($sale_only): ?>
                                <span class="inline-flex items-center px-3 py-1 bg-accent-pink/20 border border-accent-pink/30 text-accent-pink rounded-lg text-sm">
                                    Sale Only
                                    <a href="<?php echo removeFilterUrl('sale'); ?>" class="ml-2 hover:text-red-600">×</a>
                                </span>
                            <?php endif; ?>

                            <?php if ($new_only): ?>
                                <span class="inline-flex items-center px-3 py-1 bg-accent-pink/20 border border-accent-pink/30 text-accent-pink rounded-lg text-sm">
                                    New Only
                                    <a href="<?php echo removeFilterUrl('new'); ?>" class="ml-2 hover:text-red-600">×</a>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Products Grid -->
                <?php if (!empty($products)): ?>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-4 lg:gap-6 mb-8">
                        <?php foreach ($products as $product): $in_wishlist = in_array($product['id'], $wishlist_status); ?>
                            <div class="group relative bg-white rounded-2xl overflow-hidden border border-border-light hovershadow-xl transition-all duration-300 hover:scale-102 flex flex-col min-h-[320px] max-w-[280px] mx-auto w-full">

                                <!-- Product Image Container -->
                                <div class="relative w-full aspect-[3/4] overflow-hidden flex-shrink-0">
                                    <a href="/ecommerce-project/products/product_detail.php?id=<?php echo $product['id']; ?>">
                                        <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                            alt="<?php echo htmlspecialchars($product['name']); ?>"
                                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                            loading="lazy"
                                            onerror="this.src='<?php echo IMAGES_URL; ?>/placeholder.jpg'">
                                    </a>

                                    <!-- Sale Badge -->
                                    <?php if (isset($product['discount_percentage']) && $product['discount_percentage'] > 0): ?>
                                        <div class="absolute top-3 left-3 bg-red-500 text-white text-xs font-medium px-2 py-1 rounded-lg">
                                            -<?php echo $product['discount_percentage']; ?>%
                                        </div>
                                    <?php endif; ?>

                                    <!-- Slimmer Rating Badge (from index.php) -->
                                    <?php if ($product['average_rating'] >= 0): ?>
                                        <div class="absolute bottom-3 right-3 bg-green-500/90 text-white rounded-full px-2 py-1 flex items-center justify-center text-xs font-bold backdrop-blur-sm">
                                            <svg class="w-3 h-3 text-yellow-300 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                            </svg>
                                            <?php echo number_format($product['average_rating'], 1); ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Wishlist Button -->
                                    <div class="absolute top-2 right-3 transition-opacity duration-300 space-y-2">
                                        <?php if (isset($_SESSION['user_id'])): ?>
                                            <button class="wishlist-btn w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center transition-all duration-200 transform hover:scale-105 <?php echo $in_wishlist ? 'text-red-500 border-red-300 bg-red-50/90' : 'text-text-secondary border-border-light hover:text-accent-pink hover:bg-white'; ?>" data-product-id="<?php echo $product['id']; ?>" data-in-wishlist="<?php echo $in_wishlist ? 'true' : 'false'; ?>" title="<?php echo $in_wishlist ? 'Remove from Wishlist' : 'Add to Wishlist'; ?>" onclick="toggleWishlist(this, <?php echo $product['id']; ?>)">
                                                <svg class="w-4 h-4 transition-all duration-200" fill="<?php echo $in_wishlist ? 'currentColor' : 'none'; ?>" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                                </svg>
                                                <svg class="w-4 h-4 animate-spin absolute inset-0 m-auto hidden wishlist-loading" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            </button>
                                        <?php else: ?>
                                            <a href="<?php echo BASE_URL; ?>/auth/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center text-text-secondary hover:text-accent-pink hover:bg-white transition-all duration-200" title="Login to add to wishlist">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Stock Status -->
                                    <?php if (isset($product['stock_quantity'])): ?>
                                        <?php if ($product['stock_quantity'] <= LOW_STOCK_THRESHOLD && $product['stock_quantity'] > 0): ?>
                                            <div class="absolute bottom-3 left-3 bg-orange-500 text-white text-xs font-medium px-2 py-1 rounded-lg backdrop-blur-sm bg-opacity-90">
                                                Only <?php echo $product['stock_quantity']; ?> left
                                            </div>
                                        <?php elseif ($product['stock_quantity'] == 0): ?>
                                            <div class="absolute inset-0 bg-black/50 flex items-center justify-center">
                                                <span class="bg-white text-text-primary px-4 py-2 rounded-lg font-medium">Out of Stock</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Product Info -->
                                <div class="p-3 md:p-4 flex flex-col flex-grow">
                                    <h3 class="font-medium text-gray-900 text-sm md:text-base truncate h-8 group-hover:text-accent-pink transition-colors duration-200">
                                        <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </a>
                                    </h3>

                                    <?php if (!empty($product['category_name'])): ?>
                                        <p class="text-xs text-text-secondary mb-2 uppercase tracking-wide">
                                            <?php echo ucwords(str_replace('-', ' ', htmlspecialchars($product['category_name']))); ?>
                                        </p>
                                    <?php endif; ?>

                                    <!-- Price Section (Old rating code removed from here) -->
                                    <div class="flex items-center justify-between mb-3">
                                        <div>
                                            <?php if ($product['sale_price'] && $product['sale_price'] < $product['price']): ?>
                                                <div class="flex items-center space-x-2">
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
                                </div>


                                <!-- Add to Cart button always at the bottom -->
                              <!-- Add to Cart button always at the bottom -->
<div class="p-4 pt-0">
    <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="add-to-cart-form">
        <input type="hidden" name="product_id" value="<?= $product['id']; ?>">
        <input type="hidden" name="color" value="<?= htmlspecialchars(trim($product['available_colors'])) ?>">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
        <input type="hidden" name="add_to_cart" value="1">
        <button
            type="submit"
            class="w-full py-2.5 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-medium rounded-lg hover:shadow-md hover:scale-[1.02] transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-[#F2B9C7]/50 <?= (isset($product['stock_quantity']) && $product['stock_quantity'] <= 0) ? 'opacity-50 cursor-not-allowed' : ''; ?>"
            <?= (isset($product['stock_quantity']) && $product['stock_quantity'] <= 0) ? 'disabled' : '' ?>>
            <?= (isset($product['stock_quantity']) && $product['stock_quantity'] <= 0) ? 'Out of Stock' : 'Add to Cart' ?>
        </button>
    </form>
</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav class="flex items-center justify-center" aria-label="Pagination Navigation">
                            <div class="flex items-center space-x-2">

                                <!-- Previous Page -->
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo buildFilterUrl(['page' => $page - 1]); ?>"
                                        class="flex items-center px-4 py-2 bg-white border border-border-light rounded-lg hover:bg-gray-light transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-accent-pink/50">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                        </svg>
                                        Previous
                                    </a>
                                <?php endif; ?>

                                <!-- Page Numbers -->
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                if ($start_page > 1): ?>
                                    <a href="<?php echo buildFilterUrl(['page' => 1]); ?>"
                                        class="px-4 py-2 bg-white border border-border-light rounded-lg hover:bg-gray-light transition-all duration-200">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="px-2 py-2 text-text-secondary">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="<?php echo buildFilterUrl(['page' => $i]); ?>"
                                        class="px-4 py-2 border rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-accent-pink/50 <?php echo $i === $page ? 'bg-gradient-to-r from-accent-pink to-accent-lavender text-text-primary border-accent-pink' : 'bg-white border-border-light hover:bg-gray-light'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="px-2 py-2 text-text-secondary">...</span>
                                    <?php endif; ?>
                                    <a href="<?php echo buildFilterUrl(['page' => $total_pages]); ?>"
                                        class="px-4 py-2 bg-white border border-border-light rounded-lg hover:bg-gray-light transition-all duration-200"><?php echo $total_pages; ?></a>
                                <?php endif; ?>

                                <!-- Next Page -->
                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo buildFilterUrl(['page' => $page + 1]); ?>"
                                        class="flex items-center px-4 py-2 bg-white border border-border-light rounded-lg hover:bg-gray-light transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-accent-pink/50">
                                        Next
                                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </nav>

                        <!-- Pagination Info -->
                        <div class="text-center mt-6 text-sm text-text-secondary">
                            Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_products); ?> of <?php echo number_format($total_products); ?> dresses
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- No Products Found -->
                    <div class="text-center py-16">
                        <div class="w-32 h-32 mx-auto mb-6 bg-gray-light rounded-full flex items-center justify-center">
                            <svg class="w-16 h-16 text-text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-semibold text-text-primary mb-4">No dresses found</h3>
                        <p class="text-text-secondary mb-8 max-w-md mx-auto">
                            We couldn't find any dresses matching your criteria. Try adjusting your filters or search terms.
                        </p>
                        <div class="space-y-3">
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>"
                                class="inline-block px-6 py-3 bg-gradient-to-r from-accent-pink to-accent-lavender text-text-primary font-semibold rounded-lg hover:shadow-lg transition-all duration-200">
                                View All Dresses
                            </a>
                            <div>
                                <a href="<?php echo BASE_URL; ?>" class="text-text-secondary hover:text-text-primary transition-colors duration-200">
                                    ← Back to Homepage
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>



<div id="quick-view-content">
    <!-- Content loaded via AJAX -->
</div>
</div>
</div>
</div>

<!-- Loading Overlay -->
<div id="loading-overlay" class="fixed inset-0 z-50 hidden bg-black/20 backdrop-blur-sm">
    <div class="flex items-center justify-center min-h-screen">
        <div class="bg-white rounded-2xl p-8 text-center">
            <div class="w-8 h-8 border-4 border-accent-pink border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
            <p class="text-text-secondary">Loading...</p>
        </div>
    </div>
</div>

<!-- Styling and Scripts -->
<style>
    /* Enhanced Filter Styling */
    .color-radio:checked+div,
    .order-radio:checked+div {
        background: linear-gradient(135deg, #F8BBD9, #E7F5FF);
        border-color: #F8BBD9;
        color: #212529;
        font-weight: 600;
        transform: scale(1.05);
    }

    /* Custom Select Styling */
    select {
        background-image: none;
    }

    select:focus {
        box-shadow: 0 0 0 3px rgba(248, 187, 217, 0.1);
    }

    /* Remove number input arrows */
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    input[type="number"] {
        appearance: textfield;
        -moz-appearance: textfield;
    }

    /* Mobile optimizations */
    @media (max-width: 1023px) {
        #mobile-filters {
            max-height: 70vh;
            overflow-y: auto;
        }
    }

    /* Line clamp utility */
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>

<script>
    // Mobile filters toggle
    function toggleMobileFilters() {
        const mobileFilters = document.getElementById('mobile-filters');
        const filterArrow = document.getElementById('filter-arrow');

        if (mobileFilters) {
            mobileFilters.classList.toggle('hidden');

            if (filterArrow) {
                if (mobileFilters.classList.contains('hidden')) {
                    filterArrow.style.transform = 'rotate(0deg)';
                } else {
                    filterArrow.style.transform = 'rotate(180deg)';
                }
            }
        }
    }

    // Add to Cart functionality
    // Update the existing addToCart function or replace it with this:
    function handleAddToCart(event, form) {
        event.preventDefault();

        const formData = new FormData(form);
        const button = form.querySelector('button[type="submit"]');
        const originalText = button.textContent;

        // Show loading state
        button.disabled = true;
        button.textContent = 'Adding...';

        fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');

                    // Update cart count in header
                    const cartCountElements = document.querySelectorAll('.cart-count');
                    cartCountElements.forEach(element => {
                        element.textContent = data.cart_count;
                        element.classList.remove('hidden');
                    });

                    // Update button text temporarily
                    button.textContent = 'Added!';
                    setTimeout(() => {
                        button.textContent = originalText;
                        button.disabled = false;
                    }, 2000);
                } else {
                    if (data.redirect) {
                        // Redirect to login page
                        window.location.href = data.redirect;
                    } else {
                        showToast(data.message, 'error');
                        button.textContent = originalText;
                        button.disabled = false;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
                button.textContent = originalText;
                button.disabled = false;
            });
    }

    // Add event listeners to all add-to-cart forms
    document.addEventListener('DOMContentLoaded', function() {
        const addToCartForms = document.querySelectorAll('.add-to-cart-form');
        addToCartForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                handleAddToCart(e, this);
            });
        });
    }); // Toggle Wishlist functionality - UPDATED
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

    // Keep your existing showToast function for compatibility
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg text-white z-50 transform translate-x-full transition-transform duration-300 ${
        type === 'success' ? 'bg-green-500' : 
        type === 'error' ? 'bg-red-500' : 
        'bg-blue-500'
    }`;
        toast.textContent = message;
        document.body.appendChild(toast);

        // Slide in
        setTimeout(() => {
            toast.classList.remove('translate-x-full');
        }, 100);

        // Slide out and remove
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 300);
        }, 3000);
    }

    // Loading and toast utilities
    function showLoading() {
        document.getElementById('loading-overlay').classList.remove('hidden');
    }

    function hideLoading() {
        document.getElementById('loading-overlay').classList.add('hidden');
    }

    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg text-white z-50 transform translate-x-full transition-transform duration-300 ${
        type === 'success' ? 'bg-green-500' : 
        type === 'error' ? 'bg-red-500' : 
        'bg-blue-500'
    }`;
        toast.textContent = message;
        document.body.appendChild(toast);

        // Slide in
        setTimeout(() => {
            toast.classList.remove('translate-x-full');
        }, 100);

        // Slide out and remove
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 300);
        }, 3000);
    }

    // Initialize radio button states
    document.addEventListener('DOMContentLoaded', function() {
        // Handle radio button visual updates
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const name = this.name;
                document.querySelectorAll(input[name = "${name}"]).forEach(r => {
                    const div = r.nextElementSibling;
                    if (div) {
                        if (r.checked) {
                            div.classList.add('selected');
                        } else {
                            div.classList.remove('selected');
                        }
                    }
                });
            });
        });

        // Initialize selected states
        document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
            const div = radio.nextElementSibling;
            if (div) {
                div.classList.add('selected');
            }
        });
    });

    // Keyboard shortcuts
    function clearSearch() {
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.value = '';
            // Submit form to refresh results
            searchInput.closest('form').submit();
        }
    }

    // Auto-submit search with debouncing
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('search');
        const searchForm = searchInput ? searchInput.closest('form') : null;

        if (searchInput && searchForm) {
            let timeoutId;

            searchInput.addEventListener('input', function() {
                clearTimeout(timeoutId);
                const query = this.value.trim();

                // Auto-submit after user stops typing for 1 second
                if (query.length >= 2 || query.length === 0) {
                    timeoutId = setTimeout(() => {
                        searchForm.submit();
                    }, 1000);
                }
            });

            // Submit on Enter key
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(timeoutId);
                    searchForm.submit();
                }
            });
        }
    });

    // Focus search with Ctrl/Cmd + K
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });

    console.log('🛍 Product list page loaded successfully!');
</script>

<?php
// Include footer
include '../includes/footer.php';
?>