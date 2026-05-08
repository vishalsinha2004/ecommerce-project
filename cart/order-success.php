<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
/**
 * Order Success Page
 */

session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/header.php';

$order_number = $_GET['order'] ?? '';
if (empty($order_number)) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Get order details
$order = $db->fetchRow(
    'SELECT * FROM ' . DB_PREFIX . 'orders WHERE order_number = :order_number',
    ['order_number' => $order_number]
);

if (!$order) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Get order items with unit price
$order_items = $db->fetchAll(
    'SELECT oi.*, p.name, p.image FROM ' . DB_PREFIX . 'order_items oi 
     JOIN ' . DB_PREFIX . 'products p ON oi.product_id = p.id 
     WHERE oi.order_id = :order_id',
    ['order_id' => $order['id']]
);

// CORRECTED: The json_decode line is no longer needed and has been removed.
// $billing_address = json_decode($order['billing_address'], true); 
?>

<div class="min-h-screen bg-gradient-to-br from-green-50 via-white to-blue-50">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="text-center mb-12">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-green-100 rounded-full mb-6">
                <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 mb-4">Order Confirmed!</h1>
            <p class="text-lg text-gray-600 mb-2">Thank you for your purchase.</p>
            <p class="text-sm text-gray-500">Order #<?= htmlspecialchars($order['order_number']) ?> | Placed on <?= date('M j, Y', strtotime($order['created_at'])) ?></p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-900">Order Details</h2>
            </div>

            <div class="p-6">
                <div class="space-y-4 mb-8">
                    <?php foreach ($order_items as $item): ?>
                        <div class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg">
                            <div class="flex-shrink-0">
                                <img src="<?= IMAGES_URL . '/' . htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>"
                                     class="w-16 h-16 object-cover rounded-lg">
                            </div>
                            <div class="flex-1">
                                <h3 class="font-medium text-gray-900"><?= htmlspecialchars($item['name']) ?></h3>
                                <div class="flex items-center justify-between mt-1">
                                    <span class="text-sm text-gray-500">Quantity: <?= $item['quantity'] ?></span>
                                    <span class="font-semibold text-gray-900">₹<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-4">Billing Address</h3>
                        <div class="text-sm text-gray-600 whitespace-pre-line">
                            <?= htmlspecialchars($order['billing_address']) ?>
                        </div>
                    </div>

                    <div>
                        <h3 class="font-semibold text-gray-900 mb-4">Order Summary</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Subtotal</span>
                                <span class="font-medium">₹<?= number_format($order['total_amount'] - $order['shipping_cost'] - $order['tax_amount'], 2) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Shipping</span>
                                <span class="font-medium">₹<?= number_format($order['shipping_cost'], 2) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Tax</span>
                                <span class="font-medium">₹<?= number_format($order['tax_amount'], 2) ?></span>
                            </div>
                            <div class="flex justify-between text-lg font-semibold pt-2 border-t border-gray-200">
                                <span>Total</span>
                                <span>₹<?= number_format($order['total_amount'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-center">
            <a href="<?= BASE_URL ?>/index.php" class="inline-flex items-center justify-center px-6 py-3 bg-purple-600 text-white font-medium rounded-lg hover:bg-purple-700 transition-colors">
                Continue Shopping
            </a>
            <a href="<?= BASE_URL ?>/user/orders.php" class="inline-flex items-center justify-center px-6 py-3 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700 transition-colors">
                View My Orders
            </a>
        </div>
    </div>
</div>

<?php 
require_once '../includes/footer.php'; 
?>