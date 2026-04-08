<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

// Auto-regenerate session ID for security
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > SESSION_LIFETIME) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Check if user or guest is present (either user_id or session_id)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['session_id'])) {
    $_SESSION['session_id'] = session_id();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// === HANDLE CART ACTIONS FROM WISHLIST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['cart_error'] = 'Security token mismatch';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
  // Get color from form
    $color = trim($_POST['color'] ?? '');
    // Get product ID and validate
    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $quantity = min($quantity, 10); // Max 10 per item

    if ($product_id <= 0) {
        $_SESSION['cart_error'] = 'Invalid product';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    // Check if product exists and is active
    $product = $db->fetchRow(
        "SELECT id FROM " . DB_PREFIX . "products WHERE id = ? AND status = 'active'",
        [$product_id]
    );
    if (!$product) {
        $_SESSION['cart_error'] = 'Product not found or not available';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    // Prepare cart query params (user or guest)
   $where = '';
    $params = [];
    if (isset($_SESSION['user_id'])) {
        $where = 'user_id = :user_id AND product_id = :product_id AND color = :color';
        $params = ['user_id' => $_SESSION['user_id'], 'product_id' => $product_id, 'color' => $color];
    } else {
        $where = 'session_id = :session_id AND product_id = :product_id AND color = :color';
        $params = ['session_id' => $_SESSION['session_id'], 'product_id' => $product_id, 'color' => $color];
    }

    // Check if already in cart
    $existing_item = $db->fetchRow(
        "SELECT id, quantity FROM " . DB_PREFIX . "cart WHERE {$where}",
        $params
    );
    if ($existing_item) {
        $new_quantity = min($existing_item['quantity'] + $quantity, 10);
        $db->update(
            'cart',
            ['quantity' => $new_quantity, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $existing_item['id']]
        );
        $_SESSION['cart_success'] = 'Cart item updated!';
    } else {
        $data = [
        'user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null,
        'session_id' => isset($_SESSION['session_id']) ? $_SESSION['session_id'] : null,
        'product_id' => $product_id,
        'quantity' => $quantity,
        'color' => $color, // Add this line
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
        $db->insert('cart', $data);
        $_SESSION['cart_success'] = 'Product added to cart!';
    }
    // Redirect back to wishlist to prevent form resubmission
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// === HANDLE WISHLIST AJAX ACTIONS (add/remove) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch']);
        exit;
    }
    // User must be logged in for wishlist actions
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please login to manage wishlist', 'redirect' => true]);
        exit;
    }
    $action = $_POST['action'] ?? '';
    $product_id = (int)($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product']);
        exit;
    }
    try {
        $product = $db->fetchRow(
            "SELECT id, name, status FROM " . DB_PREFIX . "products WHERE id = ? AND status = 'active'",
            [$product_id]
        );
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit;
        }
        if ($action === 'add') {
            $exists = $db->exists('wishlist', 'user_id = :user_id AND product_id = :product_id', [
                'user_id' => $_SESSION['user_id'],
                'product_id' => $product_id
            ]);
            if ($exists) {
                echo json_encode(['success' => false, 'message' => 'Product already in wishlist']);
                exit;
            }
            $result = $db->insert('wishlist', [
                'user_id' => $_SESSION['user_id'],
                'product_id' => $product_id,
                'added_at' => date('Y-m-d H:i:s')
            ]);
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Added to wishlist']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to add to wishlist']);
            }
        } elseif ($action === 'remove') {
            $result = $db->delete('wishlist', 'user_id = :user_id AND product_id = :product_id', [
                'user_id' => $_SESSION['user_id'],
                'product_id' => $product_id
            ]);
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Removed from wishlist']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to remove from wishlist']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("Wishlist error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
    exit;
}

// === FETCH USER'S WISHLIST ITEMS ===
$wishlist_items = [];
if (isset($_SESSION['user_id'])) {
    try {
        $wishlist_items = $db->fetchAll(
            "SELECT 
                w.*, 
                p.name, p.description, p.price, p.sale_price, p.image, p.slug, p.stock_quantity, p.available_colors, as category_name,
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
                (p.stock_quantity > 0) as in_stock,
                COALESCE(r.average_rating, 0) as average_rating
             FROM " . DB_PREFIX . "wishlist w
             JOIN " . DB_PREFIX . "products p ON w.product_id = p.id
             LEFT JOIN " . DB_PREFIX . "categories c ON p.category_id = c.id
             LEFT JOIN (
                 SELECT product_id, AVG(rating) as average_rating 
                 FROM " . DB_PREFIX . "testimonials 
                 WHERE status = 'approved' 
                 GROUP BY product_id
             ) r ON p.id = r.product_id
             WHERE w.user_id = ?
             AND p.status = 'active'
             ORDER BY w.added_at DESC",
            [$_SESSION['user_id']]
        );
    } catch (Exception $e) {
        error_log("Error fetching wishlist: " . $e->getMessage());
        $wishlist_items = [];
    }
}

// === PAGE SETTINGS ===
$page_title = 'My Wishlist - ' . SITE_NAME;
$page_description = 'Your saved favorite items from ' . SITE_NAME;

// === INCLUDE HEADER ===
include '../includes/header.php';

// === DISPLAY CART MESSAGES ===
if (isset($_SESSION['cart_success'])) {
    echo '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">';
    echo htmlspecialchars($_SESSION['cart_success']);
    echo '</div>';
    unset($_SESSION['cart_success']);
}
if (isset($_SESSION['cart_error'])) {
    echo '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">';
    echo htmlspecialchars($_SESSION['cart_error']);
    echo '</div>';
    unset($_SESSION['cart_error']);
}
?>
<!-- =============================================
     WISHLIST PAGE STYLES
     ============================================= -->
<style>
    /* Brand color utilities */
    .bg-primary-gradient {
        background: linear-gradient(90deg, #e97393 0%, #d16a8f 100%) !important;
    }

    .bg-primary-hover:hover {
        background: linear-gradient(90deg, #d16a8f 0%, #c4567d 100%) !important;
    }

    .text-primary {
        color: #d16a8f !important;
    }

    .border-primary {
        border-color: #d16a8f !important;
    }

    .bg-pink-50 {
        background: #fdf1f5 !important;
    }

    .bg-pink-100 {
        background: #fce5ee !important;
    }

    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .aspect-\[3\/4\] {
        aspect-ratio: 3/4;
    }

    @media (max-width: 640px) {
        .grid-cols-1 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
    }
</style>

<!-- =============================================
     WISHLIST MAIN CONTENT
     ============================================= -->
<div class="min-h-screen bg-gray-50 py-4 sm:py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="bg-white rounded-lg shadow-sm p-4 sm:p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">My Wishlist</h1>
                    <p class="text-gray-600 mt-1">
                        <?php echo count($wishlist_items); ?> item<?php echo count($wishlist_items) !== 1 ? 's' : ''; ?> saved
                    </p>
                </div>
                <a href="<?php echo BASE_URL; ?>" class="inline-flex items-center px-4 py-2 bg-primary-gradient text-white rounded-md hover:bg-primary-hover transition-colors duration-200">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Continue Shopping
                </a>
            </div>
        </div>

        <?php if (empty($wishlist_items)): ?>
            <!-- Empty Wishlist State -->
            <div class="bg-white rounded-lg shadow-sm p-8 sm:p-12 text-center">
                <div class="w-24 h-24 mx-auto mb-6 bg-gray-100 rounded-full flex items-center justify-center">
                    <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                    </svg>
                </div>
                <h2 class="text-xl sm:text-2xl font-semibold text-gray-900 mb-2">Your wishlist is empty</h2>
                <p class="text-gray-600 mb-8">Start exploring our beautiful collection and save your favorite items</p>
                <a href="<?php echo BASE_URL; ?>/products/product_list.php" class="inline-flex items-center px-6 py-3 bg-primary-gradient text-white font-medium rounded-md hover:bg-primary-hover transition-colors duration-200">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    Shop Dresses
                </a>
            </div>
        <?php else: ?>
            <!-- Wishlist Items Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-6">
                <?php foreach ($wishlist_items as $item): ?>
                    <div class="bg-white rounded-lg shadow-sm overflow-hidden group hover:shadow-md transition-shadow duration-200" data-product-id="<?php echo $item['product_id']; ?>">
                        <!-- Product Image -->
                        <div class="relative aspect-[3/4] bg-gray-200">
                            <?php if (!empty($item['image'])): ?>
                                <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($item['image']); ?>"
                                    alt="<?php echo htmlspecialchars($item['name']); ?>"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                    loading="lazy">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-gray-200">
                                    <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                            <?php endif; ?>
                            <!-- Remove from Wishlist Button -->
                            <button onclick="removeFromWishlist(<?= $item['product_id']; ?>)" class="absolute top-2 right-2 w-8 h-8 bg-white rounded-full shadow-sm flex items-center justify-center hover:bg-red-50 transition duration-200 remove-wishlist-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <!-- Discount Badge -->
                            <?php if ($item['discount_percentage'] > 0): ?>
                                <div class="absolute top-2 left-2 bg-primary-gradient text-white px-2 py-1 rounded text-xs font-medium">
                                    <?php echo $item['discount_percentage']; ?>% OFF
                                </div>
                            <?php endif; ?>
                            <!-- Stock Status -->
                            <?php if (!$item['in_stock']): ?>
                                <div class="absolute bottom-2 left-2 bg-gray-900 bg-opacity-80 text-white px-2 py-1 rounded text-xs">
                                    Out of Stock
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Product Details -->
                        <div class="p-4">
                            <h3 class="font-medium text-gray-900 mb-1 line-clamp-2">
                                <a href="..." class="hover:text-primary ...">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </a>
                            </h3>
                            <?php if (!empty($item['category_name'])): ?>
                                <p class="text-sm text-gray-500 mb-2 capitalize"><?php echo htmlspecialchars($item['category_name']); ?></p>
                            <?php endif; ?>

                            <!-- RATING STARS - ADD THIS BLOCK -->
                            <div class="flex items-center mb-2">
                                <?php
                                $rating = $item['average_rating'] ?? 0;
                                $fullStars = floor($rating);
                                $halfStar = ($rating - $fullStars) >= 0.5;
                                $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                                ?>
                                <?php for ($i = 0; $i < $fullStars; $i++): ?>
                                    <svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                <?php endfor; ?>
                                <?php if ($halfStar): /* You can add a half-star SVG here if desired */ endif; ?>
                                <?php for ($i = 0; $i < $emptyStars; $i++): ?>
                                    <svg class="w-4 h-4 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                <?php endfor; ?>
                                <span class="text-xs text-gray-500 ml-1"><?php echo number_format($rating, 1); ?></span>
                            </div>
                            <div class="flex items-center space-x-2 mb-3">
                                <span class="text-lg font-semibold text-gray-900">
                                    ₹<?php echo number_format($item['final_price'], 2); ?>
                                </span>
                                <?php if ($item['sale_price'] && $item['sale_price'] < $item['price']): ?>
                                    <span class="text-sm text-gray-500 line-through">
                                        ₹<?php echo number_format($item['price'], 2); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex flex-col space-y-2">
                                <?php if ($item['in_stock']): ?>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/user/wishlist.php" class="add-to-cart-form">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="add_to_cart">
    <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
    <input type="hidden" name="quantity" value="1">
    <input type="hidden" name="color" value="<?php echo htmlspecialchars($item['available_colors']); ?>">
    <button type="submit" class="w-full bg-primary-gradient text-white px-4 py-2 rounded-md hover:bg-primary-hover transition-colors duration-200 font-medium text-sm">
        Add to Cart
    </button>
</form>
                                <?php else: ?>
                                    <button disabled class="w-full bg-gray-300 text-gray-500 px-4 py-2 rounded-md cursor-not-allowed font-medium text-sm">
                                        Out of Stock
                                    </button>
                                <?php endif; ?>
                                <!-- <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $item['product_id']; ?>" class="w-full text-center border border-primary text-primary px-4 py-2 rounded-md hover:bg-pink-100 transition-colors duration-200 font-medium text-sm">
    View Details
</a> -->
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <!-- Bulk Actions -->
            <div class="mt-8 bg-white rounded-lg shadow-sm p-4 sm:p-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-4 sm:space-y-0">
                    <p class="text-gray-600">
                        Added on <?php echo date('M d, Y', strtotime($wishlist_items[0]['added_at'])); ?>
                    </p>
                    <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                        <button onclick="clearWishlist()" class="px-4 py-2 text-[#d16a8f] border border-[#f5b7cf] rounded-md hover:bg-pink-100 transition-colors duration-200">
                            Clear Wishlist
                        </button>
                        <a href="<?php echo BASE_URL; ?>/products/product_list.php" class="px-4 py-2 bg-primary-gradient text-white rounded-md hover:bg-primary-hover transition-colors duration-200 text-center">
                            Continue Shopping
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- =============================================
     WISHLIST JAVASCRIPT
     ============================================= -->
<script>
    // Remove single item from wishlist
    function removeFromWishlist(productId) {
        if (!confirm('Remove this item from your wishlist?')) return;
        const formData = new FormData();
        formData.append('action', 'remove');
        formData.append('product_id', productId);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        const productCard = document.querySelector(`[data-product-id="${productId}"]`);
        const removeBtn = productCard.querySelector('.remove-wishlist-btn');
        const originalContent = removeBtn.innerHTML;
        removeBtn.innerHTML = '<div class="w-4 h-4 border-2 border-gray-300 border-t-[#d16a8f] rounded-full animate-spin"></div>';
        removeBtn.disabled = true;
        fetch('<?php echo BASE_URL; ?>/user/wishlist.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    productCard.style.transform = 'scale(0.95)';
                    productCard.style.opacity = '0.5';
                    setTimeout(() => {
                        productCard.remove();
                        updateWishlistCount();
                        if (document.querySelectorAll('[data-product-id]').length === 0) {
                            location.reload();
                        }
                    }, 200);
                    showNotification(data.message, 'success');
                } else {
                    removeBtn.innerHTML = originalContent;
                    removeBtn.disabled = false;
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                removeBtn.innerHTML = originalContent;
                removeBtn.disabled = false;
                showNotification('An error occurred. Please try again.', 'error');
            });
    }

    // Clear entire wishlist
    function clearWishlist() {
        if (!confirm('Are you sure you want to clear your entire wishlist?')) return;
        const productIds = Array.from(document.querySelectorAll('[data-product-id]')).map(el =>
            el.getAttribute('data-product-id')
        );
        Promise.all(productIds.map(productId => {
                const formData = new FormData();
                formData.append('action', 'remove');
                formData.append('product_id', productId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                return fetch('<?php echo BASE_URL; ?>/user/wishlist.php', {
                    method: 'POST',
                    body: formData
                }).then(response => response.json());
            }))
            .then(results => {
                const successful = results.filter(result => result.success).length;
                if (successful > 0) {
                    showNotification(`Removed ${successful} items from wishlist`, 'success');
                    setTimeout(() => location.reload(), 1000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while clearing wishlist', 'error');
            });
    }

    // Update wishlist count in header (add your own if needed)
    function updateWishlistCount() {}

    // Show notification
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 p-4 rounded-md shadow-lg z-50 transition-opacity duration-300 ${
        type === 'success' ? 'bg-[#d16a8f] text-white' : 'bg-red-500 text-white'
    }`;
        notification.textContent = message;
        document.body.appendChild(notification);
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    // Handle add to cart forms
    document.querySelectorAll('.add-to-cart-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            const originalText = button.textContent;
            button.textContent = 'Adding...';
            button.disabled = true;
            setTimeout(() => {
                button.textContent = originalText;
                button.disabled = false;
            }, 2000);
        });
    });
</script>

<?php include '../includes/footer.php'; ?>