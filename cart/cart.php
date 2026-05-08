<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
/**
 * Shopping Cart Page
 */
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security token mismatch']);
        exit;
    }

    // Rate limiting
    $rate_limit_key = 'cart_actions_' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : session_id());
    if (!isset($_SESSION[$rate_limit_key])) {
        $_SESSION[$rate_limit_key] = ['count' => 0, 'reset_time' => time() + 60];
    }

    if (time() > $_SESSION[$rate_limit_key]['reset_time']) {
        $_SESSION[$rate_limit_key] = ['count' => 0, 'reset_time' => time() + 60];
    }

    $_SESSION[$rate_limit_key]['count']++;

    if ($_SESSION[$rate_limit_key]['count'] > 30) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
        exit;
    }

    $response = ['success' => false, 'message' => ''];

    try {
        $db->beginTransaction();

        switch ($_POST['action']) {
            case 'update_quantity':
                $cart_id = filter_var($_POST['cart_id'], FILTER_VALIDATE_INT);
                $quantity = filter_var($_POST['quantity'], FILTER_VALIDATE_INT);

                if (!$cart_id || $quantity < 1) {
                    throw new Exception('Invalid input parameters');
                }

                // Verify ownership and get product stock
                $where_clause = isLoggedIn()
                    ? 'c.id = :cart_id AND c.user_id = :user_id'
                    : 'c.id = :cart_id AND c.session_id = :session_id AND c.user_id IS NULL';

                $params = ['cart_id' => $cart_id];
                if (isLoggedIn()) {
                    $params['user_id'] = $_SESSION['user_id'];
                } else {
                    $params['session_id'] = session_id();
                }

                $cart_item = $db->fetchRow("
                    SELECT c.*, p.stock_quantity, p.name, p.status 
                    FROM " . DB_PREFIX . "cart c 
                    JOIN " . DB_PREFIX . "products p ON c.product_id = p.id 
                    WHERE {$where_clause}", $params);

                if (!$cart_item) {
                    throw new Exception('Cart item not found');
                }

                if ($cart_item['stock_quantity'] <= 0 || $cart_item['status'] !== 'active') {
                    throw new Exception('Product is out of stock');
                }

                if ($quantity > $cart_item['stock_quantity']) {
                    throw new Exception('Insufficient stock available');
                }

                $db->update(
                    'cart',
                    ['quantity' => $quantity, 'updated_at' => date('Y-m-d H:i:s')],
                    'id = :id',
                    ['id' => $cart_id]
                );

                $response = ['success' => true, 'message' => 'Cart updated successfully'];
                break;

            case 'remove_item':
                $cart_id = filter_var($_POST['cart_id'], FILTER_VALIDATE_INT);

                if (!$cart_id) {
                    throw new Exception('Invalid cart item ID');
                }

                $where_clause = isLoggedIn()
                    ? 'id = :cart_id AND user_id = :user_id'
                    : 'id = :cart_id AND session_id = :session_id AND user_id IS NULL';

                $params = ['cart_id' => $cart_id];
                if (isLoggedIn()) {
                    $params['user_id'] = $_SESSION['user_id'];
                } else {
                    $params['session_id'] = session_id();
                }

                // Get item info before deleting to check if it's out of stock
                // FIXED: Added table alias to 'id' column to avoid ambiguous column error
                $item_info = $db->fetchRow("
                    SELECT c.*, p.stock_quantity 
                    FROM " . DB_PREFIX . "cart c 
                    JOIN " . DB_PREFIX . "products p ON c.product_id = p.id 
                    WHERE c.id = :cart_id" . (isLoggedIn() ? " AND c.user_id = :user_id" : " AND c.session_id = :session_id AND c.user_id IS NULL"),
                    $params
                );

                $deleted = $db->delete('cart', $where_clause, $params);

                if ($deleted) {
                    $response = [
                        'success' => true, 
                        'message' => 'Item removed from cart',
                        'was_out_of_stock' => $item_info['stock_quantity'] <= 0
                    ];
                } else {
                    throw new Exception('Failed to remove item');
                }
                break;

            case 'apply_coupon':
                $coupon_code = sanitizeInput($_POST['coupon_code'] ?? '');
                if (!empty($coupon_code)) {
                    $coupon = $db->fetchRow(
                        "SELECT * FROM " . DB_PREFIX . "coupons 
                         WHERE code = :code AND status = 'active' 
                         AND start_date <= NOW() 
                         AND (end_date IS NULL OR end_date >= NOW()) 
                         AND (usage_limit IS NULL OR usage_count < usage_limit)",
                        ['code' => $coupon_code]
                    );

                    if ($coupon) {
                        $_SESSION['applied_coupon'] = $coupon;
                        $response = ['success' => true, 'message' => 'Coupon applied successfully', 'discount' => $coupon['discount_amount']];
                    } else {
                        $response['message'] = 'Invalid or expired coupon code';
                    }
                } else {
                    $response['message'] = 'Please enter a coupon code';
                }
                break;

            case 'remove_coupon':
                unset($_SESSION['applied_coupon']);
                $response = ['success' => true, 'message' => 'Coupon removed'];
                break;
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        logMessage("Cart AJAX error: " . $e->getMessage(), 'ERROR');
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// Get cart items
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$has_out_of_stock = false;

try {
    if (isLoggedIn()) {
        $condition = "c.user_id = ?";
        $param = $_SESSION['user_id'];
    } else {
        $condition = "c.session_id = ? AND c.user_id IS NULL";
        $param = session_id();
    }

    $query = "
        SELECT 
            c.*,
            p.id as product_id,
            p.name,
            p.image,
            p.price,
            p.sale_price,
            p.stock_quantity,
            p.status as product_status,
            c.color as selected_color,
            COALESCE(AVG(t.rating), 0) AS average_rating
        FROM " . DB_PREFIX . "cart c
        INNER JOIN " . DB_PREFIX . "products p ON c.product_id = p.id
        LEFT JOIN " . DB_PREFIX . "testimonials t ON p.id = t.product_id AND t.status = 'approved'
        WHERE {$condition}
        AND p.status = 'active'
        GROUP BY c.id, p.id
        ORDER BY c.created_at DESC
    ";

    $cart_items = $db->fetchAll($query, [$param]);

    // Calculate totals and check for out-of-stock items
    foreach ($cart_items as $key => $item) {
        $unit_price = $item['sale_price'] && $item['sale_price'] < $item['price'] ? $item['sale_price'] : $item['price'];
        $item_total = $unit_price * $item['quantity'];
        $cart_total += $item_total;
        $cart_count += $item['quantity'];

        // Store item total for order summary display
        $cart_items[$key]['unit_price'] = $unit_price;
        $cart_items[$key]['item_total'] = $item_total;

        // Check if item is out of stock
        if ($item['stock_quantity'] <= 0) {
            $has_out_of_stock = true;
        }
    }
} catch (Exception $e) {
    logMessage("Error fetching cart items: " . $e->getMessage(), 'ERROR');
    $cart_items = [];
}

// Calculate discount if coupon applied
$discount_amount = 0;
$final_total = $cart_total;

if (isset($_SESSION['applied_coupon'])) {
    $coupon = $_SESSION['applied_coupon'];
    if ($coupon['discount_type'] === 'percentage') {
        $discount_amount = ($cart_total * $coupon['discount_amount']) / 100;
        if ($coupon['max_discount'] && $discount_amount > $coupon['max_discount']) {
            $discount_amount = $coupon['max_discount'];
        }
    } else {
        $discount_amount = min($coupon['discount_amount'], $cart_total);
    }
    $final_total = $cart_total - $discount_amount;
}

// Suggested products (related to cart items)
$suggested_products = [];
if (!empty($cart_items)) {
    try {
        $product_ids = array_column($cart_items, 'product_id');
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));

        $suggested_products = $db->fetchAll(
            "SELECT *, CASE 
                WHEN sale_price IS NOT NULL AND sale_price < price THEN sale_price 
                ELSE price 
             END as final_price 
             FROM " . DB_PREFIX . "products 
             WHERE status = 'active' AND id NOT IN ({$placeholders}) 
             ORDER BY RAND() LIMIT 4",
            $product_ids
        );
    } catch (Exception $e) {
        logMessage("Error fetching suggested products: " . $e->getMessage(), 'ERROR');
    }
}

// Page meta data
$page_title = 'Shopping Cart - ' . SITE_NAME;
$page_description = 'Review your selected items and proceed to checkout for a seamless shopping experience.';

// Include header
include '../includes/header.php';
?>

<style>
    /* Toast & Base Styles */
    .toast-message {
        display: none;
    }

    .toast-message.show {
        display: block;
    }

    .toast-success {
        background-color: #10b981 !important;
    }

    .toast-error {
        background-color: #ef4444 !important;
    }

    .toast-info {
        background-color: #3b82f6 !important;
    }

    /* Out of Stock Styles */
    .out-of-stock-item {
        background-color: #f9fafb !important;
    }

    .out-of-stock-image {
        filter: grayscale(100%);
        opacity: 0.8;
    }

    .out-of-stock-controls {
        opacity: 0.5;
        pointer-events: none;
    }

    /* Out of Stock Badge - Improved Positioning */
    .out-of-stock-badge-container {
        position: absolute;
        top: 0;
        left: 0%;
        width: 100%;
        height: 100%;
        z-index: 10;
        pointer-events: none;
        overflow: hidden;
        border-radius: 8px;
    }

    .out-of-stock-label {
        position: absolute;
        top: 50%;
        left: 0.5rem;
        background: #dc2626;
        color: white;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 3px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    /* DESKTOP LAYOUT (Default) - Keeps your "perfect" layout */
    .cart-item {
        display: flex;
        align-items: center;
        padding: 1.5rem;
        background: white;
        border-radius: 0.5rem;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        margin-bottom: 1rem;
        position: relative;
    }

    .ci-image {
        width: 6rem;
        height: 6rem;
        flex-shrink: 0;
        margin-right: 1.5rem;
        position: relative;
    }

    .ci-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 0.5rem;
    }

    .ci-info {
        flex: 1;
        min-width: 0;
        margin-right: 1.5rem;
    }

    .ci-price {
        text-align: right;
        margin-right: 2rem;
        min-width: 80px;
    }

    .ci-qty {
        margin-right: 2rem;
    }

    .ci-remove {
        flex-shrink: 0;
    }

    /* Order Summary Item Styles */
    .order-summary-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 0.5rem 0;
        border-bottom: 1px solid #f3f4f6;
    }

    .order-summary-item:last-child {
        border-bottom: none;
    }

    .order-summary-item-name {
        flex: 1;
        font-size: 0.875rem;
        color: #4b5563;
        line-height: 1.4;
    }

    .order-summary-item-total {
        font-size: 0.875rem;
        font-weight: 600;
        color: #111827;
        white-space: nowrap;
        margin-left: 0.75rem;
    }

    /* MOBILE LAYOUT (Short Screens) */
    @media (max-width: 768px) {
        .cart-item {
            display: grid;
            /* Column 1: Image width + extra for controls (approx 100px) | Column 2: Rest */
            grid-template-columns: 110px 1fr;
            grid-template-rows: auto auto auto;
            gap: 12px;
            padding: 16px !important;
            margin-bottom: 16px !important;
            align-items: start;
            /* Restore card look on mobile */
            background-color: white !important;
            border: 1px solid #f3f4f6;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
            border-radius: 0.75rem !important;
        }

        /* 1. Image Area: Top Left */
        .ci-image {
            grid-column: 1;
            grid-row: 1;
            width: 100%;
            /* Full width of col 1 */
            height: auto;
            aspect-ratio: 3/4;
            /* Keep portrait ratio */
            margin: 0;
        }

        /* 2. Info Area: Top Right */
        .ci-info {
            grid-column: 2;
            grid-row: 1;
            margin: 0;
            align-items: start;
        }

        .ci-info h3 {
            font-size: 1rem;
            line-height: 1.3;
            margin-bottom: 0.9rem;
        }


        /* 3. Quantity: Left Below Image (Row 2, Col 1) */
        .ci-qty {
            grid-column: 1;
            grid-row: 2;
            /* Directly under image */
            margin: 0;
            margin-top: 4px;
            width: 100%;
            display: flex;
            justify-content: center;
            /* Center buttons in the column */
        }

        /* Make qty buttons slightly more compact on mobile */
        .quantity-controls button {
            width: 28px;
            height: 28px;
        }

        .quantity-controls input {
            width: 30px;
            padding: 0;
            height: 28px;
            font-size: 0.9rem;
        }

        /* 4. Price: Middle Right */
        .ci-price {
            grid-column: 2;
            grid-row: 2;
            /* Aligned with Qty vertically? Or below Info */
            text-align: left;
            /* Align left with info */
            margin: 0;
            display: flex;
            align-items: center;
        }

        /* 5. Remove: Bottom Right (taking the place roughly where Qty was on right) */
        .ci-remove {
            grid-column: 1;
            grid-row: 3;
            justify-self:center;
            /* Align left */
            margin-top: 4px;
        }

        /* Adjustments for spacing */
        .ci-price {
            margin-top: 4px;
        }

        /* Order Summary Mobile Styles */
        .order-summary-item {
            padding: 0.4rem 0;
        }

        .order-summary-item-name {
            font-size: 0.8rem;
        }

        .order-summary-item-total {
            font-size: 0.8rem;
        }
    }
</style>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Breadcrumb -->
    <nav class="mb-8" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm">
            <li><a href="<?php echo BASE_URL; ?>" class="text-gray-500 hover:text-gray-700">Home</a></li>
            <li><span class="text-gray-400">/</span></li>
            <li><span class="text-gray-900 font-medium">Shopping Cart</span></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Shopping Cart</h1>
        <p class="text-gray-600">Review your selected items and proceed to checkout when you're ready.</p>
    </div>

    <?php if (empty($cart_items)): ?>
        <!-- Empty Cart State -->
        <div class="text-center py-16">
            <div class="mb-8">
                <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M16 11V7a4 4 0 00-8 0v4M5 9h14l-1 12H6L5 9z"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-semibold text-gray-900 mb-4">Your cart is empty</h2>
            <p class="text-gray-600 mb-8">Discover our beautiful collection of elegant dresses and add your favorites to get started.</p>
            <a href="/ecommerce-project/products/product_list.php"
                class="inline-block bg-pink-600 text-white px-8 py-3 rounded-md hover:bg-pink-700 transition-colors font-semibold">
                Start Shopping
            </a>
        </div>
    <?php else: ?>
        <!-- Out of Stock Warning -->
        <?php if ($has_out_of_stock): ?>
            <div class="out-of-stock-warning bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 px-4 py-3 mb-6" role="alert">
                <svg class="w-10 h-10" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <p>Some items in your cart are out of stock. Please remove them to proceed with checkout.</p>
            </div>
        <?php endif; ?>

        <!-- Cart Content -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Cart Items -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-xl font-semibold mb-6">Cart Items (<?php echo $cart_count; ?>)</h2>

                    <?php foreach ($cart_items as $item):
                        $is_out_of_stock = $item['stock_quantity'] <= 0;
                        $average_rating = $item['average_rating'] ?? 0;
                    ?>
                        <div class="cart-item <?php echo $is_out_of_stock ? 'out-of-stock-item' : ''; ?>" data-cart-id="<?php echo $item['id']; ?>">

                            <div class="ci-image">
                                <a href="/ecommerce-project/products/product_detail.php?id=<?php echo $item['product_id']; ?>" class="block w-full h-full">
                                    <img src="<?php echo IMAGES_URL . '/' . htmlspecialchars($item['image']); ?>"
                                        alt="<?php echo htmlspecialchars($item['name']); ?>"
                                        class="<?php echo $is_out_of_stock ? 'out-of-stock-image' : ''; ?>">
                                </a>

                                <?php if ($is_out_of_stock): ?>
                                    <div class="out-of-stock-badge-container">
                                        <span class="out-of-stock-label">OUT OF STOCK</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="ci-info">
                                <h3 class="font-semibold text-gray-900">
                                    <a href="/ecommerce-project/products/product_detail.php?id=<?php echo $item['product_id']; ?>" class="hover:text-pink-600 transition-colors">
                                        <?php echo htmlspecialchars($item['name']); ?>
                                    </a>
                                </h3>
                                <div class="flex flex-col items-start gap-2 text-sm text-gray-600 mt-1">
                                    <?php if (!empty($item['selected_color'])): ?>
                                        <span class="bg-gray-100 px-2 py-0.5 rounded text-xs">
                                            Color: <?php echo htmlspecialchars($item['selected_color']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($average_rating > 0): ?>
                                        <div class="rating bg-green-500 text-white rounded-full px-2 py-1 flex items-center justify-center text-xs font-bold backdrop-blur-sm bg-opacity-90">
                                            <svg class="w-3 h-3 text-yellow-300 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                            </svg>
                                            <?php echo number_format($average_rating, 1); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="ci-price">
                                <?php if ($item['sale_price'] && $item['sale_price'] < $item['price']): ?>
                                    <div class="flex flex-col md:items-end">
                                        <span class="item-price text-lg font-bold text-gray-900" data-price="<?php echo $item['sale_price']; ?>">
                                            ₹<?php echo number_format($item['sale_price'], 2); ?>
                                        </span>
                                        <span class="text-xs text-gray-500 line-through">
                                            ₹<?php echo number_format($item['price'], 2); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="item-price text-lg font-bold text-gray-900" data-price="<?php echo $item['price']; ?>">
                                        ₹<?php echo number_format($item['price'], 2); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="ci-qty <?php echo $is_out_of_stock ? 'out-of-stock-controls' : ''; ?>">
                                <div class="quantity-controls flex items-center space-x-1" data-cart-id="<?php echo $item['id']; ?>">
                                    <button type="button" class="decrease-btn bg-gray-100 hover:bg-gray-200 text-gray-600 rounded flex items-center justify-center transition-colors"
                                        <?php echo ($item['quantity'] <= 1 || $is_out_of_stock) ? 'disabled' : ''; ?>>-</button>

                                    <input type="number" class="quantity-input bg-white border border-gray-300 rounded text-center focus:ring-pink-500 focus:border-pink-500"
                                        value="<?php echo $item['quantity']; ?>" min="1" max="10"
                                        data-original-value="<?php echo $item['quantity']; ?>"
                                        <?php echo $is_out_of_stock ? 'disabled' : ''; ?>></input>

                                    <button type="button" class="increase-btn bg-gray-100 hover:bg-gray-200 text-gray-600 rounded flex items-center justify-center transition-colors"
                                        <?php echo $is_out_of_stock ? 'disabled' : ''; ?>>+</button>
                                </div>
                            </div>

                            <div class="ci-remove">
                                <button type="button" class="remove-item-btn text-red-500 hover:text-red-700 text-sm font-medium flex items-center gap-1 transition-colors"
                                    data-cart-id="<?php echo $item['id']; ?>"
                                    data-product-name="<?php echo htmlspecialchars($item['name']); ?>"
                                    data-is-out-of-stock="<?php echo $is_out_of_stock ? '1' : '0'; ?>">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                    <span>Remove</span>
                                </button>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Cart Summary -->
            <div class="lg:w-full">
                <div class="bg-white rounded-lg shadow-sm p-6 sticky top-4">
                    <h3 class="text-xl font-semibold mb-4">Order Summary</h3>

                    <!-- Individual Cart Items with Totals -->
                    <div class="mb-4 max-h-64 overflow-y-auto pr-2">
                        <?php foreach ($cart_items as $item): ?>
                            <div class="order-summary-item">
                                <div class="order-summary-item-name">
                                    <?php echo htmlspecialchars($item['name']); ?> (Qty: <?php echo $item['quantity']; ?>)
                                </div>
                                <div class="order-summary-item-total">
                                    ₹<?php echo number_format($item['item_total'], 2); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Subtotal -->
                    <div class="flex justify-between text-gray-600 mb-2">
                        <span>Subtotal</span>
                        <span class="cart-subtotal">₹<?php echo number_format($cart_total, 2); ?></span>
                    </div>

                    <!-- Discount Display (if any) -->
                    <?php if ($discount_amount > 0): ?>
                        <div class="flex justify-between text-green-600 mb-2 cart-discount"
                            data-discount="<?php echo $discount_amount; ?>">
                            <span>Discount (<?php echo htmlspecialchars($_SESSION['applied_coupon']['code']); ?>)</span>
                            <span>-₹<?php echo number_format($discount_amount, 2); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Shipping -->
                    <div class="flex justify-between text-gray-600 mb-4">
                        <span>Shipping</span>
                        <span class="text-green-600">FREE</span>
                    </div>

                    <hr class="my-4">

                    <!-- Total -->
                    <div class="flex justify-between text-xl font-semibold mb-6">
                        <span>Total</span>
                        <span class="cart-total">₹<?php echo number_format($final_total, 2); ?></span>
                    </div>

                    <!-- Coupon Section - Commented Out for Future Use -->
                    <!--
                    <div class="mb-6">
                        <div class="flex space-x-2">
                            <input type="text"
                                id="coupon-code"
                                placeholder="Enter coupon code"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500">
                            <button type="button"
                                id="apply-coupon-btn"
                                class="px-4 py-2 bg-gray-800 text-white rounded-md hover:bg-gray-700 transition-colors">
                                Apply
                            </button>
                        </div>
                        <?php if (isset($_SESSION['applied_coupon'])): ?>
                            <div class="mt-2 flex items-center justify-between bg-green-50 p-2 rounded">
                                <span class="text-green-800 text-sm">
                                    Coupon "<?php echo htmlspecialchars($_SESSION['applied_coupon']['code']); ?>" applied
                                </span>
                                <button type="button" id="remove-coupon-btn" class="text-red-600 hover:text-red-800 text-sm">
                                    Remove
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    -->

                    <!-- Checkout Button -->
                    <form method="POST" action="checkout.php" id="checkout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="from_cart" value="1">
                        <button type="submit"
                            class="w-full h-12 bg-gradient-to-r from-[#a53860] to-[#ffa5ab] text-white font-semibold rounded-xl hover:shadow-xl hover:scale-105 transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-[#F2B9C7]/30 <?php echo $has_out_of_stock ? 'checkout-disabled' : ''; ?>"
                            <?php echo $has_out_of_stock ? 'disabled' : ''; ?>>
                            <?php if ($has_out_of_stock): ?>
                                <div class="flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                    Remove Out of Stock Items
                                </div>
                            <?php else: ?>
                                Proceed to Checkout
                            <?php endif; ?>
                        </button>
                    </form>

                    <!-- Security Notice -->
                    <div class="mt-4 text-center">
                        <div class="flex items-center justify-center text-sm text-gray-500">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-9a2 2 0 00-2-2H6a2 2 0 00-2 2v9a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            Secure Checkout
                        </div>
                        <p class="text-xs text-gray-400 mt-1">Your payment information is encrypted and secure. We never store your card details.</p>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <?php if (!empty($suggested_products)): ?>
        <!-- Suggested Products -->
        <div class="mt-16">
            <h2 class="text-2xl font-bold text-gray-900 mb-8">Complete your look with these elegant pieces</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($suggested_products as $product): ?>
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden hover:shadow-md transition-shadow">
                        <a href="/ecommerce-project/products/product_detail.php?id=<?php echo $product['id']; ?>">
                            <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($product['image']); ?>"
                                alt="<?php echo htmlspecialchars($product['name']); ?>"
                                class="w-full h-64 object-cover hover:scale-105 transition-transform duration-300">
                        </a>
                        <div class="p-4">
                            <h3 class="font-semibold text-gray-900 mb-2">
                                <a href="/ecommerce-project/products/product_detail.php?id=<?php echo $product['id']; ?>"
                                    class="hover:text-pink-600 transition-colors">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </a>
                            </h3>
                            <div class="text-lg font-bold text-gray-900">
                                ₹<?php echo number_format($product['final_price'], 2); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check if there are out-of-stock items
        const hasOutOfStock = <?php echo $has_out_of_stock ? 'true' : 'false'; ?>;

        // Quantity update functionality
        const quantityControls = document.querySelectorAll('.quantity-controls:not(.out-of-stock-controls)');

        quantityControls.forEach(control => {
            const decreaseBtn = control.querySelector('.decrease-btn');
            const increaseBtn = control.querySelector('.increase-btn');
            const quantityInput = control.querySelector('.quantity-input');
            const cartId = control.dataset.cartId;

            // Skip if this is an out-of-stock item
            if (control.closest('.out-of-stock-controls')) {
                return;
            }

            decreaseBtn.addEventListener('click', function() {
                const currentValue = parseInt(quantityInput.value);
                if (currentValue > 1) {
                    updateQuantity(cartId, currentValue - 1, quantityInput);
                }
            });

            increaseBtn.addEventListener('click', function() {
                const currentValue = parseInt(quantityInput.value);
                updateQuantity(cartId, currentValue + 1, quantityInput);
            });

            // Handle direct input changes
            quantityInput.addEventListener('change', function() {
                const newValue = parseInt(this.value);
                const originalValue = parseInt(this.dataset.originalValue);
                if (newValue >= 1 && newValue !== originalValue) {
                    updateQuantity(cartId, newValue, this);
                } else if (newValue < 1) {
                    this.value = 1;
                    updateQuantity(cartId, 1, this);
                }
            });
        });

        // Remove item functionality (works for both in-stock and out-of-stock items)
        const removeButtons = document.querySelectorAll('.remove-item-btn');
        removeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const cartId = this.dataset.cartId;
                const productName = this.dataset.productName;
                const isOutOfStock = this.dataset.isOutOfStock === '1';

                if (confirm(`Are you sure you want to remove "${productName}" from your cart?`)) {
                    removeItem(cartId, isOutOfStock);
                }
            });
        });

        // Coupon functionality - Commented out but kept for future use
        /*
        const applyCouponBtn = document.getElementById('apply-coupon-btn');
        const removeCouponBtn = document.getElementById('remove-coupon-btn');
        const couponInput = document.getElementById('coupon-code');

        if (applyCouponBtn) {
            applyCouponBtn.addEventListener('click', function() {
                const couponCode = couponInput.value.trim();
                if (couponCode) {
                    applyCoupon(couponCode);
                } else {
                    showToast('Please enter a coupon code', 'error');
                }
            });
        }

        if (removeCouponBtn) {
            removeCouponBtn.addEventListener('click', function() {
                removeCoupon();
            });
        }

        if (couponInput) {
            couponInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    applyCouponBtn.click();
                }
            });
        }
        */

        // Prevent checkout form submission if there are out-of-stock items
        const checkoutForm = document.getElementById('checkout-form');
        if (checkoutForm) {
            checkoutForm.addEventListener('submit', function(e) {
                if (hasOutOfStock) {
                    e.preventDefault();
                    showToast('Please remove out-of-stock items before proceeding to checkout', 'error');

                    // Scroll to first out-of-stock item
                    const firstOutOfStock = document.querySelector('.out-of-stock-item');
                    if (firstOutOfStock) {
                        firstOutOfStock.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });

                        // Add highlight animation
                        firstOutOfStock.classList.add('ring-2', 'ring-red-500');
                        setTimeout(() => {
                            firstOutOfStock.classList.remove('ring-2', 'ring-red-500');
                        }, 2000);
                    }
                }
            });
        }
    });

    function updateQuantity(cartId, quantity, inputElement) {
        const formData = new FormData();
        formData.append('action', 'update_quantity');
        formData.append('cart_id', cartId);
        formData.append('quantity', quantity);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        // Show loading state
        const originalValue = inputElement.value;
        inputElement.disabled = true;

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    inputElement.value = quantity;
                    inputElement.dataset.originalValue = quantity;
                    showToast(data.message, 'success');

                    // Update cart totals
                    updateCartTotals();

                    // Update button states
                    updateButtonStates(inputElement);
                } else {
                    inputElement.value = originalValue;
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                inputElement.value = originalValue;
                showToast('An error occurred. Please try again.', 'error');
            })
            .finally(() => {
                inputElement.disabled = false;
            });
    }

    function removeItem(cartId, isOutOfStock) {
        const formData = new FormData();
        formData.append('action', 'remove_item');
        formData.append('cart_id', cartId);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');

                    // Check if the removed item was out of stock
                    // We check both the data returned and the parameter passed
                    const wasOutOfStock = data.was_out_of_stock || isOutOfStock;

                    // If the item was out of stock, reload the page
                    if (wasOutOfStock) {
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        // For in-stock items, proceed with normal DOM updates
                        // Check if this is the last item
                        const cartItems = document.querySelectorAll('.cart-item');
                        if (cartItems.length === 1) {
                            // This was the last item, reload to show empty cart
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            // Remove the item from DOM and update totals
                            const itemElement = document.querySelector(`.cart-item[data-cart-id="${cartId}"]`);
                            if (itemElement) {
                                itemElement.remove();
                            }

                            // Check if there are still out-of-stock items
                            const hasOutOfStock = document.querySelectorAll('.out-of-stock-item').length > 0;

                            // Update checkout button
                            const checkoutBtn = document.querySelector('#checkout-form button[type="submit"]');
                            if (checkoutBtn) {
                                if (hasOutOfStock) {
                                    checkoutBtn.disabled = true;
                                    checkoutBtn.classList.add('checkout-disabled');
                                    checkoutBtn.innerHTML = `
                                        <div class="flex items-center justify-center gap-2">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                            </svg>
                                            Remove Out of Stock Items
                                        </div>
                                    `;
                                } else {
                                    checkoutBtn.disabled = false;
                                    checkoutBtn.classList.remove('checkout-disabled');
                                    checkoutBtn.textContent = 'Proceed to Checkout';
                                }
                            }

                            updateCartTotals();
                        }
                    }
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            });
    }

    function applyCoupon(couponCode) {
        const formData = new FormData();
        formData.append('action', 'apply_coupon');
        formData.append('coupon_code', couponCode);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        const applyBtn = document.getElementById('apply-coupon-btn');
        const originalText = applyBtn.textContent;
        applyBtn.textContent = 'Applying...';
        applyBtn.disabled = true;

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            })
            .finally(() => {
                applyBtn.textContent = originalText;
                applyBtn.disabled = false;
            });
    }

    function removeCoupon() {
        const formData = new FormData();
        formData.append('action', 'remove_coupon');
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            });
    }

    function updateButtonStates(inputElement) {
        const control = inputElement.closest('.quantity-controls');
        const decreaseBtn = control.querySelector('.decrease-btn');
        const increaseBtn = control.querySelector('.increase-btn');
        const quantity = parseInt(inputElement.value);

        decreaseBtn.disabled = quantity <= 1;
    }

    function updateCartTotals() {
        // Recalculate totals from current cart items
        let subtotal = 0;
        const cartItems = document.querySelectorAll('.cart-item:not(.out-of-stock-item)');

        cartItems.forEach(item => {
            const priceElement = item.querySelector('.item-price');
            const quantityElement = item.querySelector('.quantity-input');

            if (priceElement && quantityElement) {
                const price = parseFloat(priceElement.dataset.price || 0);
                const quantity = parseInt(quantityElement.value || 0);
                const itemTotal = price * quantity;
                subtotal += itemTotal;

                // Update individual item total
                const itemTotalElement = item.querySelector('.quantity-controls .font-semibold');
                if (itemTotalElement) {
                    itemTotalElement.textContent = '₹' + itemTotal.toFixed(2);
                }
            }
        });

        // Update subtotal display
        const subtotalElement = document.querySelector('.cart-subtotal');
        if (subtotalElement) {
            subtotalElement.textContent = '₹' + subtotal.toFixed(2);
        }

        // Update final total (considering discount)
        const discountElement = document.querySelector('.cart-discount');
        const discount = discountElement ? parseFloat(discountElement.dataset.discount || 0) : 0;
        const finalTotal = subtotal - discount;

        const totalElement = document.querySelector('.cart-total');
        if (totalElement) {
            totalElement.textContent = '₹' + finalTotal.toFixed(2);
        }
    }

    function showToast(message, type = 'info') {
        // Remove existing toast
        const existingToast = document.querySelector('.toast-message');
        if (existingToast) {
            existingToast.remove();
        }

        // Create new toast
        const toast = document.createElement('div');
        toast.className = `toast-message toast-${type}`;
        toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        max-width: 300px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateX(400px);
        transition: transform 0.3s ease;
        display: block;
    `;

        // Set background color based on type
        if (type === 'success') {
            toast.style.backgroundColor = '#10b981';
        } else if (type === 'error') {
            toast.style.backgroundColor = '#ef4444';
        } else {
            toast.style.backgroundColor = '#3b82f6';
        }

        toast.textContent = message;
        document.body.appendChild(toast);

        // Show toast
        setTimeout(() => {
            toast.style.transform = 'translateX(0)';
        }, 100);

        // Hide toast after 3 seconds
        setTimeout(() => {
            toast.style.transform = 'translateX(400px)';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }
</script>

<?php include '../includes/footer.php'; ?>