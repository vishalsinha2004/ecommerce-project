<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
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
    $params = [];
    $where = '';
    if (isset($_SESSION['user_id'])) {
        $where = 'user_id = :user_id AND product_id = :product_id';
        $params = ['user_id' => $_SESSION['user_id'], 'product_id' => $product_id];
    } else {
        $where = 'session_id = :session_id AND product_id = :product_id';
        $params = ['session_id' => $_SESSION['session_id'], 'product_id' => $product_id];
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
                p.name, p.description, p.price, p.sale_price, p.image, p.slug, p.stock_quantity, p.available_colors,
                c.name as category_name,
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
    echo '<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4 max-w-7xl mx-auto mt-4">';
    echo htmlspecialchars($_SESSION['cart_success']);
    echo '</div>';
    unset($_SESSION['cart_success']);
}
if (isset($_SESSION['cart_error'])) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4 max-w-7xl mx-auto mt-4">';
    echo htmlspecialchars($_SESSION['cart_error']);
    echo '</div>';
    unset($_SESSION['cart_error']);
}
?>
<style>
    /* UI Theme */
    body {
        font-family: 'Nunito', 'Inter', sans-serif;
        background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
        min-height: 100vh;
    }
    .glass-card {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(12px);
        border-radius: 1.25rem;
        border: 1px solid rgba(255, 255, 255, 0.6);
        box-shadow: 0 8px 32px 0 rgba(200, 175, 220, 0.1);
    }
    
    /* Slimmer Buttons */
    .btn-primary {
        background: linear-gradient(90deg, #d86990 0%, #e995b5 100%);
        color: #fff;
        transition: all 0.3s cubic-bezier(.4, 0, .2, 1);
        box-shadow: 0 2px 10px 0 #e995b544;
        border: none;
    }
    .btn-primary:hover:not(:disabled) {
        filter: brightness(1.05);
        transform: translateY(-1px);
        box-shadow: 0 4px 15px 0 #e995b566;
    }
    
    .btn-outline-danger {
        background: white;
        border: 1px solid #fecaca;
        color: #ef4444;
        transition: all 0.2s;
    }
    .btn-outline-danger:hover {
        background: #fef2f2;
        border-color: #fca5a5;
    }

    .aspect-\[3\/4\] {
        aspect-ratio: 3/4;
    }
</style>

<div class="min-h-screen py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="glass-card p-6 mb-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">My Wishlist</h1>
                <p class="text-gray-500 text-sm mt-1">
                    <span id="wishlist-count" class="font-semibold text-[#d86990]"><?php echo count($wishlist_items); ?></span> item<?php echo count($wishlist_items) !== 1 ? 's' : ''; ?> saved
                </p>
            </div>
            
            <div class="flex items-center gap-3">
                <button id="clear-wishlist-btn" onclick="clearWishlist(this)" class="btn-outline-danger px-4 py-2 rounded-xl text-sm font-semibold flex items-center <?php echo empty($wishlist_items) ? 'hidden' : ''; ?>">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    Clear All
                </button>
                <a href="<?php echo BASE_URL; ?>/products/product_list.php" class="btn-primary px-5 py-2 rounded-xl text-sm font-semibold flex items-center">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    Continue Shopping
                </a>
            </div>
        </div>

        <?php if (empty($wishlist_items)): ?>
            <div class="glass-card p-12 text-center">
                <div class="w-20 h-20 mx-auto mb-6 bg-pink-50 rounded-full flex items-center justify-center">
                    <svg class="w-10 h-10 text-[#d86990]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">Your wishlist is empty</h2>
                <p class="text-gray-500 mb-8 max-w-sm mx-auto text-sm">Start exploring our beautiful collection and save your favorite items for later.</p>
                <a href="<?php echo BASE_URL; ?>/products/product_list.php" class="btn-primary inline-flex items-center px-6 py-2.5 rounded-xl font-semibold text-sm">
                    Shop Dresses
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-6">
                <?php foreach ($wishlist_items as $item): ?>
                    <div class="glass-card overflow-hidden group hover:shadow-lg transition-all duration-300" data-product-id="<?php echo $item['product_id']; ?>">
                        <div class="relative w-full aspect-[3/4] overflow-hidden">
                            <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $item['product_id']; ?>">
                                <?php if (!empty($item['image'])): ?>
                                    <img src="<?php echo IMAGES_URL; ?>/<?php echo htmlspecialchars($item['image']); ?>"
                                        alt="<?php echo htmlspecialchars($item['name']); ?>"
                                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                        loading="lazy">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center bg-gray-50">
                                        <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                <?php endif; ?>
                            </a>

                            <button onclick="removeFromWishlist(<?= $item['product_id']; ?>)" class="absolute top-3 right-3 w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full shadow-sm flex items-center justify-center hover:bg-red-50 text-gray-400 hover:text-red-500 transition duration-200 remove-wishlist-btn z-10">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>

                            <?php if ($item['discount_percentage'] > 0): ?>
                                <div class="absolute top-3 left-3 bg-red-500 text-white px-2 py-1 rounded-lg text-xs font-bold shadow-sm">
                                    -<?php echo $item['discount_percentage']; ?>%
                                </div>
                            <?php endif; ?>

                            <?php if (!$item['in_stock']): ?>
                                <div class="absolute inset-0 bg-white/60 backdrop-blur-[1px] flex items-center justify-center">
                                    <span class="bg-gray-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wide">Out of Stock</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="p-3 sm:p-4 flex flex-col flex-grow">
                            <h3 class="text-sm font-bold text-gray-800 truncate mb-1">
                                <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $item['product_id']; ?>" class="hover:text-[#d86990] transition-colors">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </a>
                            </h3>
                            
                            <p class="text-xs text-gray-500 mb-2 sm:mb-3"><?php echo htmlspecialchars($item['category_name'] ?? 'Dress'); ?></p>

                            <div class="flex items-center space-x-2 mb-3 sm:mb-4">
                                <span class="text-sm sm:text-base font-bold text-gray-900">
                                    ₹<?php echo number_format($item['final_price'], 2); ?>
                                </span>
                                <?php if ($item['sale_price'] && $item['sale_price'] < $item['price']): ?>
                                    <span class="text-xs text-gray-400 line-through">
                                        ₹<?php echo number_format($item['price'], 2); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="mt-auto">
                                <?php if ($item['in_stock']): ?>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/user/wishlist.php" class="add-to-cart-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="add_to_cart">
                                        <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="w-full btn-primary py-2 rounded-xl text-xs sm:text-sm font-bold shadow-sm">
                                            Add to Cart
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button disabled class="w-full bg-gray-100 text-gray-400 py-2 rounded-xl font-bold text-xs sm:text-sm cursor-not-allowed border border-gray-200">
                                        Unavailable
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

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
        
        removeBtn.innerHTML = '<div class="w-3 h-3 border-2 border-gray-300 border-t-[#d86990] rounded-full animate-spin"></div>';
        removeBtn.disabled = true;
        
        fetch('<?php echo BASE_URL; ?>/user/wishlist.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    productCard.style.transform = 'scale(0.95)';
                    productCard.style.opacity = '0';
                    setTimeout(() => {
                        productCard.remove();
                        updateWishlistCount();
                        // Reload if empty to show empty state
                        if (document.querySelectorAll('[data-product-id]').length === 0) {
                            location.reload();
                        }
                    }, 300);
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
        
        const clearBtn = document.getElementById('clear-wishlist-btn');
        const originalText = clearBtn.innerHTML;
        clearBtn.innerHTML = 'Clearing...';
        clearBtn.disabled = true;

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
                    setTimeout(() => location.reload(), 800);
                } else {
                    clearBtn.innerHTML = originalText;
                    clearBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                clearBtn.innerHTML = originalText;
                clearBtn.disabled = false;
                showNotification('An error occurred while clearing wishlist', 'error');
            });
    }

    function updateWishlistCount() {
        const countSpan = document.getElementById('wishlist-count');
        if(countSpan) {
            const current = parseInt(countSpan.innerText);
            countSpan.innerText = Math.max(0, current - 1);
        }
    }

    // Show notification
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `fixed bottom-5 right-5 px-6 py-3 rounded-xl shadow-lg z-50 transition-all duration-300 transform translate-y-10 opacity-0 font-medium text-sm flex items-center ${
        type === 'success' ? 'bg-[#d86990] text-white' : 'bg-red-500 text-white'
    }`;
        
        // Add icon
        const icon = type === 'success' 
            ? '<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>'
            : '<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
            
        notification.innerHTML = icon + message;
        document.body.appendChild(notification);
        
        // Animate in
        requestAnimationFrame(() => {
            notification.classList.remove('translate-y-10', 'opacity-0');
        });

        setTimeout(() => {
            notification.classList.add('translate-y-10', 'opacity-0');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    // Handle add to cart forms
    document.querySelectorAll('.add-to-cart-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<span class="flex items-center justify-center"><svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Adding...</span>';
            button.disabled = true;
            
            // Re-enable after delay (since it's a form submit, page will reload usually, but just in case)
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 5000);
        });
    });
</script>

<?php include '../includes/footer.php'; ?>