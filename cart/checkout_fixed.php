<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
/**
 * Secure Checkout Page
 * Mobile-first responsive design with Tailwind CSS
 * Razorpay integration with webhooks
 * Advanced security features
 */

// Start session and include required files
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/header.php';

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in or has items in cart
$user_id = $_SESSION['user_id'] ?? null;
$session_id = session_id();

// Handle POST from cart.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['from_cart'])) {
    // Verify CSRF token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Security validation failed');
    }
    
    // Store cart data in session for checkout
    $_SESSION['checkout_ready'] = true;
}

// Rate limiting for checkout attempts
$rate_limit_key = 'checkout_attempts_' . ($user_id ?? $session_id);
if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => time()];
}

// Get cart items
$cart_items = getCartItems($user_id, $session_id);

// Redirect if cart is empty and not coming from cart page
if (empty($cart_items) && !isset($_SESSION['checkout_ready'])) {
    header('Location: cart.php?error=empty_cart');
    exit;
}

// Calculate totals
$subtotal = 0;
$total_items = 0;
foreach ($cart_items as $item) {
    $item_total = $item['unit_price'] * $item['quantity'];
    $subtotal += $item_total;
    $total_items += $item['quantity'];
}

$shipping_cost = ($subtotal >= 5000) ? 0 : 100; // Free shipping for orders above ₹5000
$tax_amount = $subtotal * (18 / 100);
$total_amount = $subtotal + $shipping_cost + $tax_amount;

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security validation failed. Please try again.';
    }

    // Rate limiting check
    $current_time = time();
    if ($_SESSION[$rate_limit_key]['count'] >= 5 && ($current_time - $_SESSION[$rate_limit_key]['last_attempt']) < 300) {
        $errors[] = 'Too many checkout attempts. Please wait 5 minutes before trying again.';
    }

    if ($_POST['action'] === 'process_order' && empty($errors)) {
        // Update rate limiting
        $_SESSION[$rate_limit_key]['count']++;
        $_SESSION[$rate_limit_key]['last_attempt'] = $current_time;

        // Validate and sanitize input
        $billing_data = [
            'first_name' => htmlspecialchars(trim($_POST['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'last_name' => htmlspecialchars(trim($_POST['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'email' => filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL),
            'phone' => htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'address_line_1' => htmlspecialchars(trim($_POST['address_line_1'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'address_line_2' => htmlspecialchars(trim($_POST['address_line_2'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'city' => htmlspecialchars(trim($_POST['city'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'state' => htmlspecialchars(trim($_POST['state'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'postal_code' => htmlspecialchars(trim($_POST['postal_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'country' => htmlspecialchars(trim($_POST['country'] ?? 'India'), ENT_QUOTES, 'UTF-8'),
        ];

        $shipping_data = $billing_data; // Use billing as default
        if (isset($_POST['different_shipping']) && $_POST['different_shipping'] === '1') {
            $shipping_data = [
                'first_name' => htmlspecialchars(trim($_POST['shipping_first_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'last_name' => htmlspecialchars(trim($_POST['shipping_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'address_line_1' => htmlspecialchars(trim($_POST['shipping_address_line_1'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'address_line_2' => htmlspecialchars(trim($_POST['shipping_address_line_2'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'city' => htmlspecialchars(trim($_POST['shipping_city'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'state' => htmlspecialchars(trim($_POST['shipping_state'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'postal_code' => htmlspecialchars(trim($_POST['shipping_postal_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'country' => htmlspecialchars(trim($_POST['shipping_country'] ?? 'India'), ENT_QUOTES, 'UTF-8'),
            ];
        }

        // Validation
        $required_fields = ['first_name', 'last_name', 'email', 'phone', 'address_line_1', 'city', 'state', 'postal_code'];
        foreach ($required_fields as $field) {
            if (empty($billing_data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        if (!filter_var($billing_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!preg_match('/^[0-9+\-\s\(\)]{10,15}$/', $billing_data['phone'])) {
            $errors[] = 'Please enter a valid phone number.';
        }

        if (!preg_match('/^[0-9]{6}$/', $billing_data['postal_code'])) {
            $errors[] = 'Please enter a valid 6-digit postal code.';
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Create order number
                $order_number = 'ORD' . date('YmdHis') . rand(1000, 9999);

                // Format addresses
                $billing_address = json_encode([
                    'first_name' => $billing_data['first_name'],
                    'last_name' => $billing_data['last_name'],
                    'address_line_1' => $billing_data['address_line_1'],
                    'address_line_2' => $billing_data['address_line_2'],
                    'city' => $billing_data['city'],
                    'state' => $billing_data['state'],
                    'postal_code' => $billing_data['postal_code'],
                    'country' => $billing_data['country'],
                    'phone' => $billing_data['phone']
                ]);

                $shipping_address = json_encode($shipping_data);

                // Create order in database
                $order_id = $db->insert('orders', [
                    'user_id' => $user_id,
                    'order_number' => $order_number,
                    'status' => 'pending',
                    'total_amount' => $total_amount,
                    'shipping_cost' => $shipping_cost,
                    'tax_amount' => $tax_amount,
                    'discount_amount' => 0.00,
                    'payment_method' => 'razorpay',
                    'payment_status' => 'pending',
                    'shipping_address' => $shipping_address,
                    'billing_address' => $billing_address,
                    'notes' => htmlspecialchars(trim($_POST['order_notes'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                if (!$order_id) {
                    throw new Exception('Failed to create order');
                }

                // Add order items
                foreach ($cart_items as $item) {
                    $db->insert('order_items', [
                        'order_id' => $order_id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'price' => $item['unit_price'],
                        'total' => $item['unit_price'] * $item['quantity']
                    ]);
                }

                $db->commit();

                // Create Razorpay order
                require_once 'vendor/autoload.php'; // Assuming Composer is used
                $api = new Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

                $razorpay_order = $api->order->create([
                    'receipt' => $order_number,
                    'amount' => $total_amount * 100, // Amount in paise
                    'currency' => 'INR',
                    'notes' => [
                        'order_id' => $order_id,
                        'user_email' => $billing_data['email']
                    ]
                ]);

                // Store Razorpay order ID
                $db->update('orders', 
                    ['razorpay_order_id' => $razorpay_order['id']], 
                    'id = :id', 
                    ['id' => $order_id]
                );

                // Prepare data for payment
                $_SESSION['checkout_order_id'] = $order_id;
                $_SESSION['razorpay_order_id'] = $razorpay_order['id'];
                $_SESSION['payment_amount'] = $total_amount;
                $_SESSION['customer_details'] = $billing_data;

                $success = true;

            } catch (Exception $e) {
                $db->rollback();
                logMessage('Checkout error: ' . $e->getMessage(), 'ERROR');
                $errors[] = 'An error occurred while processing your order. Please try again.';
            }
        }
    }
}

// Get user details if logged in
$user_details = [];
if ($user_id) {
    $user_details = $db->fetchRow('SELECT * FROM ' . DB_PREFIX . 'users WHERE id = :id', ['id' => $user_id]);
}
?>

<div class="min-h-screen bg-gradient-to-br from-rose-50 via-white to-purple-50">
    <!-- Header Section -->
    <div class="bg-white shadow-sm border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="cart.php" class="inline-flex items-center text-gray-600 hover:text-gray-900 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Back to Cart
                    </a>
                    <div class="hidden sm:block text-gray-300">|</div>
                    <h1 class="hidden sm:block text-lg font-semibold text-gray-900">Secure Checkout</h1>
                </div>
                <div class="flex items-center space-x-2 text-sm text-gray-500">
                    <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                    </svg>
                    <span>256-bit SSL Secure</span>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if (!empty($errors)): ?>
            <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded-r-lg">
                <div class="flex">
                    <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Please correct the following errors:</h3>
                        <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Checkout Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <?php if ($success && isset($_SESSION['razorpay_order_id'])): ?>
                        <!-- Payment Section -->
                        <div class="text-center py-8">
                            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900 mb-2">Order Created Successfully!</h2>
                            <p class="text-gray-600 mb-6">Please complete your payment to confirm your order.</p>

                            <button id="rzp-button" class="inline-flex items-center px-8 py-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold rounded-lg hover:from-blue-700 hover:to-purple-700 transform hover:scale-105 transition-all duration-200 shadow-lg">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                Pay ₹<?= number_format($total_amount, 2) ?> Securely
                            </button>

                            <div class="mt-6 flex items-center justify-center space-x-6 text-sm text-gray-500">
                                <div class="flex items-center">
                                    <img src="https://razorpay.com/assets/razorpay-logo.svg" alt="Razorpay" class="h-6 w-auto mr-2">
                                    <span>Powered by Razorpay</span>
                                </div>
                            </div>

                            <div class="mt-4 text-xs text-gray-400">
                                Order #<?= htmlspecialchars($_POST['order_number'] ?? '') ?> |
                                Amount: ₹<?= number_format($total_amount, 2) ?>
                            </div>
                        </div>

                        <!-- Razorpay Payment Script -->
                        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                        <script>
                            document.getElementById('rzp-button').onclick = function(e) {
                                e.preventDefault();

                                var options = {
                                    "key": "<?= RAZORPAY_KEY_ID ?>",
                                    "amount": "<?= $total_amount * 100 ?>",
                                    "currency": "INR",
                                    "name": "<?= SITE_NAME ?>",
                                    "description": "Order Payment",
                                    "order_id": "<?= $_SESSION['razorpay_order_id'] ?>",
                                    "handler": function(response) {
                                        // Payment successful
                                        var form = document.createElement('form');
                                        form.method = 'POST';
                                        form.action = 'payment-success.php';

                                        var inputs = {
                                            'razorpay_payment_id': response.razorpay_payment_id,
                                            'razorpay_order_id': response.razorpay_order_id,
                                            'razorpay_signature': response.razorpay_signature,
                                            'csrf_token': '<?= $_SESSION['csrf_token'] ?>'
                                        };

                                        for (var key in inputs) {
                                            var input = document.createElement('input');
                                            input.type = 'hidden';
                                            input.name = key;
                                            input.value = inputs[key];
                                            form.appendChild(input);
                                        }

                                        document.body.appendChild(form);
                                        form.submit();
                                    },
                                    "prefill": {
                                        "name": "<?= htmlspecialchars(($_SESSION['customer_details']['first_name'] ?? '') . ' ' . ($_SESSION['customer_details']['last_name'] ?? '')) ?>",
                                        "email": "<?= htmlspecialchars($_SESSION['customer_details']['email'] ?? '') ?>",
                                        "contact": "<?= htmlspecialchars($_SESSION['customer_details']['phone'] ?? '') ?>"
                                    },
                                    "theme": {
                                        "color": "#8B5CF6"
                                    },
                                    "modal": {
                                        "ondismiss": function() {
                                            console.log('Payment cancelled by user');
                                        }
                                    }
                                };

                                var rzp = new Razorpay(options);
                                rzp.open();
                            }
                        </script>
                    <?php else: ?>
                        <!-- Checkout Form -->
                        <form method="POST" action="" class="space-y-8" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="process_order">

                            <!-- Billing Information -->
                            <div>
                                <div class="flex items-center mb-6">
                                    <div class="flex items-center justify-center w-8 h-8 bg-purple-100 text-purple-600 rounded-full mr-3">
                                        <span class="text-sm font-semibold">1</span>
                                    </div>
                                    <h2 class="text-xl font-bold text-gray-900">Billing Information</h2>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                                        <input type="text" id="first_name" name="first_name" required
                                            value="<?= htmlspecialchars($_POST['first_name'] ?? $user_details['first_name'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div>
                                        <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                                        <input type="text" id="last_name" name="last_name" required
                                            value="<?= htmlspecialchars($_POST['last_name'] ?? $user_details['last_name'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                                    <div>
                                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                                        <input type="email" id="email" name="email" required
                                            value="<?= htmlspecialchars($_POST['email'] ?? $user_details['email'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div>
                                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">Phone Number *</label>
                                        <input type="tel" id="phone" name="phone" required
                                            value="<?= htmlspecialchars($_POST['phone'] ?? $user_details['phone'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label for="address_line_1" class="block text-sm font-medium text-gray-700 mb-2">Address Line 1 *</label>
                                    <input type="text" id="address_line_1" name="address_line_1" required
                                        value="<?= htmlspecialchars($_POST['address_line_1'] ?? '') ?>"
                                        placeholder="Street address, P.O. Box, company name, c/o"
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                </div>

                                <div class="mt-4">
                                    <label for="address_line_2" class="block text-sm font-medium text-gray-700 mb-2">Address Line 2</label>
                                    <input type="text" id="address_line_2" name="address_line_2"
                                        value="<?= htmlspecialchars($_POST['address_line_2'] ?? '') ?>"
                                        placeholder="Apartment, suite, unit, building, floor, etc."
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                                    <div>
                                        <label for="city" class="block text-sm font-medium text-gray-700 mb-2">City *</label>
                                        <input type="text" id="city" name="city" required
                                            value="<?= htmlspecialchars($_POST['city'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div>
                                        <label for="state" class="block text-sm font-medium text-gray-700 mb-2">State *</label>
                                        <input type="text" id="state" name="state" required
                                            value="<?= htmlspecialchars($_POST['state'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div>
                                        <label for="postal_code" class="block text-sm font-medium text-gray-700 mb-2">Postal Code *</label>
                                        <input type="text" id="postal_code" name="postal_code" required
                                            value="<?= htmlspecialchars($_POST['postal_code'] ?? '') ?>"
                                            placeholder="123456"
                                            pattern="[0-9]{6}"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>
                                </div>
                            </div>

                            <!-- Shipping Information -->
                            <div>
                                <div class="flex items-center mb-6">
                                    <div class="flex items-center justify-center w-8 h-8 bg-purple-100 text-purple-600 rounded-full mr-3">
                                        <span class="text-sm font-semibold">2</span>
                                    </div>
                                    <h2 class="text-xl font-bold text-gray-900">Shipping Information</h2>
                                </div>

                                <div class="mb-4">
                                    <label class="flex items-center">
                                        <input type="checkbox" id="different_shipping" name="different_shipping" value="1"
                                            class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                        <span class="ml-2 text-sm font-medium text-gray-700">Ship to a different address</span>
                                    </label>
                                </div>

                                <div id="shipping_fields" class="hidden space-y-4">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label for="shipping_first_name" class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                                            <input type="text" id="shipping_first_name" name="shipping_first_name"
                                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                        </div>

                                        <div>
                                            <label for="shipping_last_name" class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                                            <input type="text" id="shipping_last_name" name="shipping_last_name"
                                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                        </div>
                                    </div>

                                    <!-- Add similar fields for shipping address -->
                                </div>
                            </div>

                            <!-- Order Notes -->
                            <div>
                                <div class="flex items-center mb-6">
                                    <div class="flex items-center justify-center w-8 h-8 bg-purple-100 text-purple-600 rounded-full mr-3">
                                        <span class="text-sm font-semibold">3</span>
                                    </div>
                                    <h2 class="text-xl font-bold text-gray-900">Additional Information</h2>
                                </div>

                                <div>
                                    <label for="order_notes" class="block text-sm font-medium text-gray-700 mb-2">Order Notes (Optional)</label>
                                    <textarea id="order_notes" name="order_notes" rows="3"
                                        placeholder="Notes about your order, e.g. special notes for delivery."
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors resize-none"><?= htmlspecialchars($_POST['order_notes'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-6 border-t border-gray-200">
                                <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-pink-600 text-white font-semibold py-4 px-6 rounded-lg hover:from-purple-700 hover:to-pink-700 transform hover:scale-[1.02] transition-all duration-200 shadow-lg">
                                    <span class="flex items-center justify-center">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                        </svg>
                                        Place Order & Pay ₹<?= number_format($total_amount, 2) ?>
                                    </span>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sticky top-8">
                    <h3 class="text-lg font-bold text-gray-900 mb-6">Order Summary</h3>

                    <!-- Cart Items -->
                    <div class="space-y-4 mb-6">
                        <?php foreach ($cart_items as $item): ?>
                            <div class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg">
                                <div class="flex-shrink-0">
                                    <img src="<?= IMAGES_URL . '/' . htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>"
                                        class="w-16 h-16 object-cover rounded-lg">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($item['name']) ?></h4>
                                    <div class="flex items-center justify-between mt-1">
                                        <span class="text-sm text-gray-500">Qty: <?= $item['quantity'] ?></span>
                                        <span class="text-sm font-semibold text-gray-900">₹<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Order Totals -->
                    <div class="space-y-3 border-t border-gray-200 pt-6">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Subtotal (<?= $total_items ?> items)</span>
                            <span class="font-semibold text-gray-900">₹<?= number_format($subtotal, 2) ?></span>
                        </div>

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Shipping</span>
                            <span class="font-semibold text-gray-900">
                                <?php if ($shipping_cost > 0): ?>
                                    ₹<?= number_format($shipping_cost, 2) ?>
                                <?php else: ?>
                                    <span class="text-green-600">Free</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Tax (<?= 18 ?>%)</span>
                            <span class="font-semibold text-gray-900">₹<?= number_format($tax_amount, 2) ?></span>
                        </div>

                        <div class="flex justify-between text-lg font-bold text-gray-900 pt-3 border-t border-gray-200">
                            <span>Total</span>
                            <span>₹<?= number_format($total_amount, 2) ?></span>
                        </div>
                    </div>

                    <!-- Security Badges -->
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <div class="flex items-center justify-center space-x-4 text-xs text-gray-500">
                            <div class="flex items-center">
                                <svg class="w-4 h-4 text-green-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                                </svg>
                                <span>SSL Secure</span>
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 text-green-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <span>Verified</span>
                            </div>
                        </div>

                        <div class="mt-3 text-center">
                            <p class="text-xs text-gray-500">
                                Your payment information is encrypted and secure.<br>
                                We never store your card details.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Different shipping address toggle
    document.getElementById('different_shipping').addEventListener('change', function() {
        const shippingFields = document.getElementById('shipping_fields');
        if (this.checked) {
            shippingFields.classList.remove('hidden');
        } else {
            shippingFields.classList.add('hidden');
        }
    });

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const requiredFields = ['first_name', 'last_name', 'email', 'phone', 'address_line_1', 'city', 'state', 'postal_code'];
        let hasErrors = false;

        requiredFields.forEach(function(fieldName) {
            const field = document.getElementById(fieldName);
            if (field && !field.value.trim()) {
                field.classList.add('border-red-500');
                hasErrors = true;
            } else if (field) {
                field.classList.remove('border-red-500');
            }
        });

        // Email validation
        const email = document.getElementById('email');
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !emailRegex.test(email.value)) {
            email.classList.add('border-red-500');
            hasErrors = true;
        }

        // Phone validation
        const phone = document.getElementById('phone');
        const phoneRegex = /^[0-9+\-\s\(\)]{10,15}$/;
        if (phone && !phoneRegex.test(phone.value)) {
            phone.classList.add('border-red-500');
            hasErrors = true;
        }

        // Postal code validation
        const postalCode = document.getElementById('postal_code');
        const postalRegex = /^[0-9]{6}$/;
        if (postalCode && !postalRegex.test(postalCode.value)) {
            postalCode.classList.add('border-red-500');
            hasErrors = true;
        }

        if (hasErrors) {
            e.preventDefault();
            alert('Please fill in all required fields correctly.');
        }
    });

    // Real-time validation
    document.querySelectorAll('input[required]').forEach(function(input) {
        input.addEventListener('blur', function() {
            if (this.value.trim()) {
                this.classList.remove('border-red-500');
                this.classList.add('border-green-500');
            } else {
                this.classList.add('border-red-500');
                this.classList.remove('border-green-500');
            }
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>