<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
/**
 * Secure Checkout Page
 * Mobile-first responsive design with Tailwind CSS
 * Razorpay integration with webhooks
 * Advanced security features
 * ADDED: Stock validation before order creation
 */

// Start session and include required files
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

// Check if user is logged in or has items in cart
$user_id = $_SESSION['user_id'] ?? null;
$session_id = session_id();

// Get cart items BEFORE any output
function getCartItemsForCheckout($user_id, $session_id)
{
    global $db;

    if ($user_id) {
        $cart_items = $db->fetchAll(
            'SELECT c.*, p.name, p.image, p.price, p.sale_price
             FROM ' . DB_PREFIX . 'cart c 
             JOIN ' . DB_PREFIX . 'products p ON c.product_id = p.id 
             WHERE c.user_id = :user_id',
            ['user_id' => $user_id]
        );
    } else {
        $cart_items = $db->fetchAll(
            'SELECT c.*, p.name, p.image, p.price, p.sale_price
             FROM ' . DB_PREFIX . 'cart c 
             JOIN ' . DB_PREFIX . 'products p ON c.product_id = p.id 
             WHERE c.session_id = :session_id AND c.user_id IS NULL',
            ['session_id' => $session_id]
        );
    }

    return $cart_items ?: [];
}

// Get cart items
$cart_items = getCartItemsForCheckout($user_id, $session_id);

// Check if cart is empty - redirect early if so
if (empty($cart_items) && !isset($_POST['action'])) {
    $_SESSION['checkout_error'] = 'Your cart is empty. Please add items to your cart before checking out.';
    header('Location: cart.php');
    exit();
}

// Include header AFTER potential redirects
require_once '../includes/header.php';

// Include PHPMailer files
require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

// Get user details if logged in
$user_details = [];
if ($user_id) {
    $user_details = $db->fetchRow('SELECT * FROM ' . DB_PREFIX . 'users WHERE id = :id', ['id' => $user_id]);
}

// Calculate totals
$subtotal = 0;
$total_items = 0;
foreach ($cart_items as $key => $item) {
    // Logic from cart.php
    $unit_price = ($item['sale_price'] && $item['sale_price'] < $item['price']) ? $item['sale_price'] : $item['price'];

    $item_total = $unit_price * $item['quantity'];
    $subtotal += $item_total;
    $total_items += $item['quantity'];

    // Save this effective price for later use in DB insert and HTML
    $cart_items[$key]['final_price'] = $unit_price;
}

$shipping_cost = ($subtotal >= 5000) ? 0 : 100; // Free shipping for orders above ₹5000
$tax_amount = $subtotal * (18 / 100);
$online_total_amount = $subtotal + $shipping_cost + $tax_amount;
$cod_handling_fee = $online_total_amount * 0.021; // 2.1% handling fee for COD
$cod_total_amount = $online_total_amount + $cod_handling_fee;

// Handle form submission
$errors = [];
$success = false;
$payment_method = 'online'; // Default payment method
$order_number = '';
$order_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security validation failed. Please try again.';
    }

    // Get payment method
    $payment_method = $_POST['payment_method'] ?? 'online';

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

        // NEW: Check stock availability for ALL items before proceeding
        if (empty($errors)) {
            $out_of_stock_items = [];
            $updated_cart_items = [];

            foreach ($cart_items as $item) {
                // Check product status and stock
                $product = $db->fetchRow(
                    'SELECT name, status, stock_quantity FROM ' . DB_PREFIX . 'products WHERE id = :id',
                    ['id' => $item['product_id']]
                );

                if (!$product || $product['status'] !== 'active') {
                    $out_of_stock_items[] = [
                        'name' => $item['name'],
                        'reason' => 'Product is not available'
                    ];

                    // Remove from cart
                    if ($user_id) {
                        $db->delete(
                            'cart',
                            'user_id = :user_id AND product_id = :product_id',
                            ['user_id' => $user_id, 'product_id' => $item['product_id']]
                        );
                    } else {
                        $db->delete(
                            'cart',
                            'session_id = :session_id AND product_id = :product_id AND user_id IS NULL',
                            ['session_id' => $session_id, 'product_id' => $item['product_id']]
                        );
                    }
                } elseif ($product['stock_quantity'] < $item['quantity']) {
                    $out_of_stock_items[] = [
                        'name' => $item['name'],
                        'reason' => 'Insufficient stock. Available: ' . $product['stock_quantity'] . ', Requested: ' . $item['quantity']
                    ];

                    // Remove from cart
                    if ($user_id) {
                        $db->delete(
                            'cart',
                            'user_id = :user_id AND product_id = :product_id',
                            ['user_id' => $user_id, 'product_id' => $item['product_id']]
                        );
                    } else {
                        $db->delete(
                            'cart',
                            'session_id = :session_id AND product_id = :product_id AND user_id IS NULL',
                            ['session_id' => $session_id, 'product_id' => $item['product_id']]
                        );
                    }
                } else {
                    // Item is available, keep it
                    $updated_cart_items[] = $item;
                }
            }

            // If we have out of stock items
            if (!empty($out_of_stock_items)) {
                $error_messages = [];
                foreach ($out_of_stock_items as $item) {
                    $error_messages[] = 'Product "' . $item['name'] . '" - ' . $item['reason'];
                }
                $errors[] = 'Some items in your cart are no longer available. They have been removed from your cart.';

                // Add detailed error messages
                foreach ($error_messages as $msg) {
                    $errors[] = $msg;
                }

                // Update cart items to remove out of stock items
                $cart_items = $updated_cart_items;

                // Recalculate totals with updated cart
                $subtotal = 0;
                $total_items = 0;
                foreach ($cart_items as $key => $item) {
                    // Same logic as above
                    $unit_price = ($item['sale_price'] && $item['sale_price'] < $item['price']) ? $item['sale_price'] : $item['price'];

                    $item_total = $unit_price * $item['quantity'];
                    $subtotal += $item_total;
                    $total_items += $item['quantity'];

                    // Update the final price again just in case
                    $cart_items[$key]['final_price'] = $unit_price;
                }

                $shipping_cost = ($subtotal >= 5000) ? 0 : 100;
                $tax_amount = $subtotal * (18 / 100);
                $online_total_amount = $subtotal + $shipping_cost + $tax_amount;
                $cod_handling_fee = $online_total_amount * 0.021;
                $cod_total_amount = $online_total_amount + $cod_handling_fee;

                // If cart is empty after removing items - use JavaScript redirect
                if (empty($cart_items)) {
                    $errors[] = 'Your cart is now empty. Please add items to your cart before checking out.';
                    // Store error in session for redirect
                    $_SESSION['checkout_error'] = 'Your cart is empty. Please add items to your cart before checking out.';

                    // Force reload the page to show updated cart
                    echo '<script>window.location.href = "cart.php";</script>';
                    exit();
                }
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Create order number
                $order_number = 'ORD' . date('YmdHis') . rand(1000, 9999);

                // Calculate final amount based on payment method
                $final_amount = ($payment_method === 'cod') ? $cod_total_amount : $online_total_amount;
                $payment_status = ($payment_method === 'cod') ? 'pending' : 'pending';
                $order_status = 'pending'; // Both COD and online start as pending

                // Build formatted address strings
                $billing_address_parts = [
                    $billing_data['first_name'] . ' ' . $billing_data['last_name'],
                    $billing_data['address_line_1'],
                    $billing_data['address_line_2'],
                    $billing_data['city'] . ', ' . $billing_data['state'] . ' ' . $billing_data['postal_code'],
                    $billing_data['country'],
                    'Phone: ' . $billing_data['phone']
                ];
                $billing_address = implode("\n", array_filter($billing_address_parts));

                $shipping_address_parts = [
                    $shipping_data['first_name'] . ' ' . $shipping_data['last_name'],
                    $shipping_data['address_line_1'],
                    $shipping_data['address_line_2'],
                    $shipping_data['city'] . ', ' . $shipping_data['state'] . ' ' . $shipping_data['postal_code'],
                    $shipping_data['country']
                ];
                $shipping_address = implode("\n", array_filter($shipping_address_parts));

                // Create order in database
                $order_data = [
                    'user_id' => $user_id,
                    'order_number' => $order_number,
                    'status' => $order_status,
                    'total_amount' => $final_amount,
                    'shipping_cost' => $shipping_cost,
                    'tax_amount' => $tax_amount,
                    'discount_amount' => 0.00,
                    'payment_method' => ($payment_method === 'cod') ? 'Cash on Delivery' : 'Online Payment',
                    'payment_status' => $payment_status,
                    'shipping_address' => $shipping_address,
                    'billing_address' => $billing_address,
                    'notes' => htmlspecialchars(trim($_POST['order_notes'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // Add COD handling fee if applicable
                if ($payment_method === 'cod') {
                    $order_data['discount_amount'] = $cod_handling_fee * -1; // Negative to show as additional fee
                }

                $order_id = $db->insert('orders', $order_data);

                if (!$order_id) {
                    throw new Exception('Failed to create order');
                }

                // Add order items (do NOT update stock for COD orders yet)
                foreach ($cart_items as $item) {
                    $db->insert('order_items', [
                        'order_id' => $order_id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['final_price']
                    ]);
                }

                $db->commit();

                // If online payment, create Razorpay order
                if ($payment_method === 'online') {
                    require_once '../vendor/autoload.php';
                    $api = new Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

                    $razorpay_order = $api->order->create([
                        'receipt' => $order_number,
                        'amount' => $online_total_amount * 100, // Amount in paise
                        'currency' => 'INR',
                        'notes' => [
                            'order_id' => $order_id,
                            'user_email' => $billing_data['email']
                        ]
                    ]);

                    // Store Razorpay order ID
                    $db->update(
                        'orders',
                        ['razorpay_order_id' => $razorpay_order['id']],
                        'id = :id',
                        ['id' => $order_id]
                    );

                    // Prepare data for payment
                    $_SESSION['checkout_order_id'] = $order_id;
                    $_SESSION['razorpay_order_id'] = $razorpay_order['id'];
                    $_SESSION['payment_amount'] = $online_total_amount;
                    $_SESSION['customer_details'] = $billing_data;
                } else {
                    // For COD, clear the cart and prepare success data
                    if ($user_id) {
                        $db->delete('cart', 'user_id = :user_id', ['user_id' => $user_id]);
                    } else {
                        $db->delete('cart', 'session_id = :session_id AND user_id IS NULL', ['session_id' => $session_id]);
                    }

                    // Store order info for success page
                    $_SESSION['cod_order_id'] = $order_id;
                    $_SESSION['cod_order_number'] = $order_number;

                    // Send COD order confirmation email using PHPMailer
                    sendCODOrderConfirmationEmail($billing_data, $order_number, $cod_total_amount, $order_status);
                }

                $success = true;
            } catch (Exception $e) {
                $db->rollback();
                logMessage('Checkout error: ' . $e->getMessage(), 'ERROR');
                $errors[] = 'An error occurred while processing your order. Please try again.';
            }
        }
    }
}

// Function to send COD order confirmation email using PHPMailer
function sendCODOrderConfirmationEmail($customer_data, $order_number, $total_amount, $order_status)
{
    try {
        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // For development (XAMPP) - disable SSL verification
        if (ENVIRONMENT === 'development') {
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
        }

        // Recipients
        $mail->setFrom(SMTP_USERNAME, SITE_NAME);
        $mail->addAddress($customer_data['email']);
        $mail->addReplyTo(SUPPORT_EMAIL, SITE_NAME . ' Support');

        // Content
        $mail->isHTML(true);
        $mail->Subject = "Order Received #" . $order_number . " - " . SITE_NAME;

        // Email template
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Order Received</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 30px 20px; text-align: center; color: white; }
                .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                .content { padding: 30px; }
                .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
                .order-details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .cod-notice { background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px; margin: 20px 0; }
                .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px; }
                .status-pending { background-color: #fef3c7; color: #92400e; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                    <p>Order Received!</p>
                </div>
                <div class='content'>
                    <h2>Thank you, " . htmlspecialchars($customer_data['first_name']) . "!</h2>
                    <p>Your order has been received and is awaiting confirmation.</p>
                    
                    <div class='order-details'>
                        <h3>Order Details:</h3>
                        <p><strong>Order Number:</strong> " . htmlspecialchars($order_number) . "</p>
                        <p><strong>Order Date:</strong> " . date('F j, Y') . "</p>
                        <p><strong>Order Status:</strong> " . htmlspecialchars(ucfirst($order_status)) . " <span class='status-badge status-pending'>Pending</span></p>
                        <p><strong>Payment Method:</strong> Cash on Delivery</p>
                        <p><strong>Total Amount:</strong> ₹" . number_format($total_amount, 2) . "</p>
                    </div>
                    
                    <div class='cod-notice'>
                        <h3>📦 Cash on Delivery Information:</h3>
                        <p>Your order is currently <strong>pending confirmation</strong>. Once we confirm your order, you'll receive another email with shipping details.</p>
                        <p><strong>Note:</strong> 2.1% handling fee included in the total amount.</p>
                        <p><em>Please keep the exact amount ready when the delivery arrives.</em></p>
                    </div>
                    
                    <p>We'll process your order and send you a confirmation email within 24 hours.</p>
                    <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
                </div>
                <div class='footer'>
                    <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                    If you have questions, contact us at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                    <p style='margin-top: 10px;'>
                        " . htmlspecialchars(COMPANY_NAME) . "<br>
                        " . htmlspecialchars(COMPANY_ADDRESS) . "
                    </p>
                </div>
            </div>
        </body>
        </html>";

        // Alternative plain text version
        $mail->AltBody = "Thank you, " . $customer_data['first_name'] . "! Your order #" . $order_number . " has been received and is pending confirmation. Total amount: ₹" . number_format($total_amount, 2) . ". Payment method: Cash on Delivery. We'll process your order and send you a confirmation email within 24 hours.";

        // Send email
        $mail->send();
        logMessage("COD confirmation email sent to: " . $customer_data['email'] . " | Status: " . $order_status, 'INFO');
        return true;
    } catch (Exception $e) {
        logMessage("PHPMailer error for COD order: " . $mail->ErrorInfo, 'ERROR');
        return false;
    }
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

            <!-- Out of Stock Warning Section -->
            <?php if (strpos(implode(' ', $errors), 'no longer available') !== false): ?>
                <div class="mb-6 bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-r-lg">
                    <div class="flex">
                        <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">Cart Updated</h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>Some items were removed from your cart because they're no longer available.</p>
                                <p class="mt-2">Your cart has been updated with available items. Please review the order summary below and proceed with checkout.</p>
                                <a href="cart.php" class="inline-flex items-center mt-3 text-yellow-700 hover:text-yellow-900 font-medium">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                    </svg>
                                    View Updated Cart
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($success && $payment_method === 'cod'): ?>
            <!-- COD Success Popup -->
            <div id="codSuccessModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-2xl bg-white animate-fade-in">
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Order Received Successfully!</h3>
                        <p class="text-gray-600 mb-4">Your order has been received and is awaiting confirmation.</p>

                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <p class="text-sm text-gray-700 mb-1">
                                <span class="font-semibold">Order #:</span> <?= htmlspecialchars($order_number) ?>
                            </p>
                            <p class="text-sm text-gray-700 mb-1">
                                <span class="font-semibold">Order Status:</span>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                    Pending Confirmation
                                </span>
                            </p>
                            <p class="text-sm text-gray-700 mb-1">
                                <span class="font-semibold">Payment Method:</span> Cash on Delivery
                            </p>
                            <p class="text-sm text-gray-700">
                                <span class="font-semibold">Total Amount:</span> ₹<?= number_format($cod_total_amount, 2) ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2 text-center">
                                *Includes 2.1% COD handling fee
                            </p>
                        </div>

                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                            <p class="text-sm text-yellow-800">
                                <strong>Note:</strong> Your order is currently <strong>pending confirmation</strong>. We'll process your order and send you a confirmation email within 24 hours.
                            </p>
                        </div>

                        <p class="text-sm text-gray-500 mb-6">
                            A confirmation email has been sent to <?= htmlspecialchars($billing_data['email'] ?? $user_details['email'] ?? '') ?>
                        </p>

                        <div class="flex flex-col gap-3">
                            <a href="/ecommerce-project/products/product_list.php"
                                class="w-full px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 text-white font-semibold rounded-lg hover:from-purple-700 hover:to-pink-700 transform hover:scale-105 transition-all duration-200 shadow-lg text-center">
                                Shop More
                            </a>
                            <a href="../index.php"
                                class="w-full px-6 py-3 bg-gradient-to-r from-gray-600 to-gray-700 text-white font-semibold rounded-lg hover:from-gray-700 hover:to-gray-800 transform hover:scale-105 transition-all duration-200 shadow-lg text-center">
                                Go to Home
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                // Show COD success modal immediately
                document.addEventListener('DOMContentLoaded', function() {
                    document.getElementById('codSuccessModal').classList.remove('hidden');
                });
            </script>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Checkout Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                    <?php if ($success && $payment_method === 'online' && isset($_SESSION['razorpay_order_id'])): ?>
                        <!-- Online Payment Section -->
                        <div class="text-center py-8">
                            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900 mb-2">Order Created Successfully!</h2>
                            <p class="text-gray-600 mb-6">Please complete your payment to confirm your order.</p>

                            <button id="rzp-button" class="inline-flex items-center px-8 py-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold rounded-lg hover:from-blue-700 hover:to-purple-700 transform hover:scale-105 transition-all duration-200 shadow-lg mb-4">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                Pay Online ₹<?= number_format($online_total_amount, 2) ?>
                            </button>

                            <div class="mt-6 flex items-center justify-center space-x-6 text-sm text-gray-500">
                                <div class="flex items-center">
                                    <img src="https://razorpay.com/assets/razorpay-logo.svg" alt="Razorpay" class="h-6 w-auto mr-2">
                                    <span>Powered by Razorpay</span>
                                </div>
                            </div>

                            <div class="mt-4 text-xs text-gray-400">
                                Order #<?= htmlspecialchars($order_number ?? 'N/A') ?> |
                                Amount: ₹<?= number_format($online_total_amount, 2) ?>
                            </div>
                        </div>

                        <!-- Razorpay Payment Script -->
                        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                        <script>
                            // Wait for the Razorpay script to load before setting up the button
                            function initRazorpay() {
                                document.getElementById('rzp-button').onclick = function(e) {
                                    e.preventDefault();

                                    var options = {
                                        "key": "<?= RAZORPAY_KEY_ID ?>",
                                        "amount": "<?= $online_total_amount * 100 ?>",
                                        "currency": "INR",
                                        "name": "<?= SITE_NAME ?>",
                                        "description": "Order Payment",
                                        "order_id": "<?= $_SESSION['razorpay_order_id'] ?>",
                                        "handler": function(response) {
                                            // Payment successful
                                            var form = document.createElement('form');
                                            form.method = 'POST';
                                            form.action = 'payment_success.php';

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
                                };
                            }

                            // Check if Razorpay is already loaded or wait for it to load
                            if (typeof Razorpay !== 'undefined') {
                                initRazorpay();
                            } else {
                                // Wait for the script to load
                                var razorpayScript = document.querySelector('script[src="https://checkout.razorpay.com/v1/checkout.js"]');
                                razorpayScript.onload = initRazorpay;
                            }
                        </script>
                    <?php else: ?>
                        <!-- Checkout Form -->
                        <form method="POST" action="" class="space-y-8" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="process_order">
                            <input type="hidden" name="payment_method" id="payment_method" value="online">

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
                                        value="<?= htmlspecialchars($_POST['address_line_1'] ?? $user_details['address_1'] ?? '') ?>"
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
                                            value="<?= htmlspecialchars($_POST['city'] ?? $user_details['city'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div>
                                        <label for="state" class="block text-sm font-medium text-gray-700 mb-2">State *</label>
                                        <input type="text" id="state" name="state" required
                                            value="<?= htmlspecialchars($_POST['state'] ?? $user_details['state'] ?? '') ?>"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div>
                                        <label for="postal_code" class="block text-sm font-medium text-gray-700 mb-2">Postal Code *</label>
                                        <input type="text" id="postal_code" name="postal_code" required
                                            value="<?= htmlspecialchars($_POST['postal_code'] ?? $user_details['postal_code'] ?? '') ?>"
                                            placeholder="123456"
                                            pattern="[0-9]{6}"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label for="country" class="block text-sm font-medium text-gray-700 mb-2">Country *</label>
                                    <input type="text" id="country" name="country" required
                                        value="<?= htmlspecialchars($_POST['country'] ?? $user_details['country'] ?? 'India') ?>"
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
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

                                    <div class="mt-4">
                                        <label for="shipping_address_line_1" class="block text-sm font-medium text-gray-700 mb-2">Address Line 1</label>
                                        <input type="text" id="shipping_address_line_1" name="shipping_address_line_1"
                                            placeholder="Street address, P.O. Box, company name, c/o"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div class="mt-4">
                                        <label for="shipping_address_line_2" class="block text-sm font-medium text-gray-700 mb-2">Address Line 2</label>
                                        <input type="text" id="shipping_address_line_2" name="shipping_address_line_2"
                                            placeholder="Apartment, suite, unit, building, floor, etc."
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                                        <div>
                                            <label for="shipping_city" class="block text-sm font-medium text-gray-700 mb-2">City</label>
                                            <input type="text" id="shipping_city" name="shipping_city"
                                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                        </div>

                                        <div>
                                            <label for="shipping_state" class="block text-sm font-medium text-gray-700 mb-2">State</label>
                                            <input type="text" id="shipping_state" name="shipping_state"
                                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                        </div>

                                        <div>
                                            <label for="shipping_postal_code" class="block text-sm font-medium text-gray-700 mb-2">Postal Code</label>
                                            <input type="text" id="shipping_postal_code" name="shipping_postal_code"
                                                placeholder="123456"
                                                pattern="[0-9]{6}"
                                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <label for="shipping_country" class="block text-sm font-medium text-gray-700 mb-2">Country</label>
                                        <input type="text" id="shipping_country" name="shipping_country" value="India"
                                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors">
                                    </div>
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

                            <!-- Payment Method -->
                            <div>
                                <div class="flex items-center mb-6">
                                    <div class="flex items-center justify-center w-8 h-8 bg-purple-100 text-purple-600 rounded-full mr-3">
                                        <span class="text-sm font-semibold">4</span>
                                    </div>
                                    <h2 class="text-xl font-bold text-gray-900">Payment Method</h2>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="relative">
                                        <input type="radio" id="online_payment" name="payment_method_radio" value="online" checked class="absolute opacity-0 w-0 h-0 peer">
                                        <label for="online_payment" class="flex items-center p-4 border-2 border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50 peer-checked:border-purple-500 peer-checked:bg-purple-50 transition-all duration-200">
                                            <div class="mr-4">
                                                <div class="w-6 h-6 rounded-full border-2 border-gray-300 flex items-center justify-center transition-all duration-200 peer-checked:border-purple-500">
                                                    <div class="w-3 h-3 rounded-full bg-white transition-all duration-200 peer-checked:bg-purple-500"></div>
                                                </div>
                                            </div>
                                            <div class="flex-1">
                                                <h3 class="font-semibold text-gray-900">Pay Online</h3>
                                                <p class="text-sm text-gray-600">Secure payment via Razorpay</p>
                                            </div>
                                            <div class="ml-4">
                                                <img src="https://razorpay.com/assets/razorpay-logo.svg" alt="Razorpay" class="h-6">
                                            </div>
                                        </label>
                                    </div>

                                    <div class="relative">
                                        <input type="radio" id="cod_payment" name="payment_method_radio" value="cod" class="absolute opacity-0 w-0 h-0 peer">
                                        <label for="cod_payment" class="flex items-center p-4 border-2 border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50 peer-checked:border-purple-500 peer-checked:bg-purple-50 transition-all duration-200">
                                            <div class="mr-4">
                                                <div class="w-6 h-6 rounded-full border-2 border-gray-300 flex items-center justify-center transition-all duration-200 peer-checked:border-purple-500">
                                                    <div class="w-3 h-3 rounded-full bg-white transition-all duration-200 peer-checked:bg-purple-500"></div>
                                                </div>
                                            </div>
                                            <div class="flex-1">
                                                <h3 class="font-semibold text-gray-900">Cash on Delivery</h3>
                                                <p class="text-sm text-gray-600">Pay when you receive the order</p>
                                                <p class="text-xs text-red-600 font-medium mt-1">* 2.1% handling fee applies</p>
                                            </div>
                                            <div class="ml-4">
                                                <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                                </svg>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Buttons -->
                            <div class="pt-6 border-t border-gray-200">
                                <div id="online_payment_button" class="space-y-4">
                                    <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold py-4 px-6 rounded-lg hover:from-blue-700 hover:to-purple-700 transform hover:scale-[1.02] transition-all duration-200 shadow-lg">
                                        <span class="flex items-center justify-center">
                                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            </svg>
                                            Pay Online ₹<span id="online_total_display"><?= number_format($online_total_amount, 2) ?></span>
                                        </span>
                                    </button>
                                </div>

                                <div id="cod_payment_button" class="hidden space-y-4">
                                    <button type="submit" class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white font-semibold py-4 px-6 rounded-lg hover:from-green-700 hover:to-emerald-700 transform hover:scale-[1.02] transition-all duration-200 shadow-lg">
                                        <span class="flex items-center justify-center">
                                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                            </svg>
                                            Place COD Order ₹<span id="cod_total_display"><?= number_format($cod_total_amount, 2) ?></span>
                                        </span>
                                    </button>
                                    <p class="text-sm text-center text-gray-600">
                                        * 2.1% handling fee of ₹<span id="cod_fee_display"><?= number_format($cod_handling_fee, 2) ?></span> included
                                    </p>
                                </div>
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
                    <div class="space-y-4 mb-6 max-h-64 overflow-y-auto pr-2">
                        <?php if (empty($cart_items)): ?>
                            <div class="text-center py-8">
                                <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                                <p class="mt-2 text-sm text-gray-500">Your cart is empty</p>
                                <a href="products/product_list.php" class="mt-4 inline-block text-sm font-medium text-purple-600 hover:text-purple-500">
                                    Continue Shopping →
                                </a>
                            </div>
                        <?php else: ?>
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
                                            <span class="text-sm font-semibold text-gray-900">₹<?= number_format($item['final_price'] * $item['quantity'], 2) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($cart_items)): ?>
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
                                <span class="text-gray-600">Tax (18%)</span>
                                <span class="font-semibold text-gray-900">₹<?= number_format($tax_amount, 2) ?></span>
                            </div>

                            <!-- COD Handling Fee (hidden by default) -->
                            <div id="cod_fee_section" class="hidden flex justify-between text-sm">
                                <span class="text-gray-600">
                                    COD Handling Fee
                                    <span class="text-red-500 text-xs">(2.1%)</span>
                                </span>
                                <span class="font-semibold text-red-600">+₹<span id="cod_fee_summary"><?= number_format($cod_handling_fee, 2) ?></span></span>
                            </div>

                            <div class="flex justify-between text-lg font-bold text-gray-900 pt-3 border-t border-gray-200">
                                <span>Total</span>
                                <span id="order_total_summary">₹<?= number_format($online_total_amount, 2) ?></span>
                            </div>

                            <!-- Payment Method Indicator -->
                            <div id="payment_method_indicator" class="text-xs text-center text-gray-500 mt-2 pt-2 border-t border-gray-100">
                                <span id="current_payment_method">Online Payment</span>
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
                                        <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Different shipping address toggle
    const shippingCheckbox = document.getElementById('different_shipping');
    if (shippingCheckbox) {
        shippingCheckbox.addEventListener('change', function() {
            const shippingFields = document.getElementById('shipping_fields');
            if (this.checked) {
                shippingFields.classList.remove('hidden');
            } else {
                shippingFields.classList.add('hidden');
            }
        });
    }

    // Payment method toggle
    const onlinePaymentRadio = document.getElementById('online_payment');
    const codPaymentRadio = document.getElementById('cod_payment');
    const onlinePaymentButton = document.getElementById('online_payment_button');
    const codPaymentButton = document.getElementById('cod_payment_button');
    const paymentMethodField = document.getElementById('payment_method');
    const codFeeSection = document.getElementById('cod_fee_section');
    const orderTotalSummary = document.getElementById('order_total_summary');
    const codFeeSummary = document.getElementById('cod_fee_summary');
    const onlineTotalDisplay = document.getElementById('online_total_display');
    const codTotalDisplay = document.getElementById('cod_total_display');
    const codFeeDisplay = document.getElementById('cod_fee_display');
    const paymentMethodIndicator = document.getElementById('current_payment_method');

    // Totals from PHP
    const onlineTotal = <?= $online_total_amount ?>;
    const codFee = <?= $cod_handling_fee ?>;
    const codTotal = <?= $cod_total_amount ?>;

    // Update payment method display
    function updatePaymentMethod(method) {
        paymentMethodField.value = method;

        if (method === 'online') {
            onlinePaymentButton.classList.remove('hidden');
            codPaymentButton.classList.add('hidden');
            codFeeSection.classList.add('hidden');
            orderTotalSummary.textContent = '₹' + onlineTotal.toFixed(2);
            paymentMethodIndicator.textContent = 'Online Payment';
        } else {
            onlinePaymentButton.classList.add('hidden');
            codPaymentButton.classList.remove('hidden');
            codFeeSection.classList.remove('hidden');
            orderTotalSummary.textContent = '₹' + codTotal.toFixed(2);
            codFeeSummary.textContent = codFee.toFixed(2);
            paymentMethodIndicator.textContent = 'Cash on Delivery';
        }
    }

    // Add event listeners to payment method radios
    if (onlinePaymentRadio && codPaymentRadio) {
        onlinePaymentRadio.addEventListener('change', function() {
            if (this.checked) {
                updatePaymentMethod('online');
            }
        });

        codPaymentRadio.addEventListener('change', function() {
            if (this.checked) {
                updatePaymentMethod('cod');
            }
        });
    }

    // Form validation
    const checkoutForm = document.querySelector('form');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function(e) {
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
            } else {
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                if (submitButton) {
                    const originalText = submitButton.innerHTML;
                    submitButton.innerHTML = '<span class="flex items-center justify-center"><svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...</span>';
                    submitButton.disabled = true;

                    // Restore button text after 5 seconds (in case of error)
                    setTimeout(() => {
                        submitButton.innerHTML = originalText;
                        submitButton.disabled = false;
                    }, 5000);
                }
            }
        });
    }

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

    // Auto-fill shipping fields when "different shipping" is unchecked
    if (shippingCheckbox) {
        shippingCheckbox.addEventListener('change', function() {
            if (!this.checked) {
                const billingFields = ['first_name', 'last_name', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country'];
                billingFields.forEach(function(field) {
                    const billingField = document.getElementById(field);
                    const shippingField = document.getElementById('shipping_' + field);
                    if (billingField && shippingField) {
                        shippingField.value = billingField.value;
                    }
                });
            }
        });
    }

    // Initialize payment method display
    updatePaymentMethod('online');
</script>

<style>
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-fade-in {
        animation: fadeIn 0.3s ease-out;
    }

    /* Custom scrollbar for order summary */
    .overflow-y-auto::-webkit-scrollbar {
        width: 4px;
    }

    .overflow-y-auto::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }

    .overflow-y-auto::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
    }

    .overflow-y-auto::-webkit-scrollbar-thumb:hover {
        background: #555;
    }
</style>

<?php require_once '../includes/footer.php'; ?>