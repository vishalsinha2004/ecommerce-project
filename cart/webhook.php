<?php

/**
 * Razorpay Webhook Handler
 * Handles asynchronous payment notifications and sends customer emails.
 */

require_once '../includes/config.php';
require_once '../includes/db.php';
// This file is still needed for the main sendSecureEmail() function
require_once '../includes/email_handler.php';

// Log webhook request
$input = file_get_contents('php://input');
$headers = getallheaders();

logMessage('Webhook received: ' . $input, 'INFO');

// Verify webhook signature
$webhook_secret = RAZORPAY_WEBHOOK_SECRET;
$webhook_signature = $headers['X-Razorpay-Signature'] ?? '';

if (empty($webhook_signature)) {
    http_response_code(400);
    exit('Missing signature');
}

$expected_signature = hash_hmac('sha256', $input, $webhook_secret);

if (!hash_equals($expected_signature, $webhook_signature)) {
    logMessage('Invalid webhook signature', 'ERROR');
    http_response_code(400);
    exit('Invalid signature');
}

// Parse webhook data
$webhook_data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('Invalid JSON');
}

$event = $webhook_data['event'] ?? '';
$payload = $webhook_data['payload'] ?? [];

try {
    switch ($event) {
        case 'payment.captured':
            handlePaymentCaptured($payload);
            break;
        case 'payment.failed':
            handlePaymentFailed($payload);
            break;
        case 'refund.created':
            handleRefundCreated($payload);
            break;
        case 'dispute.created':
            handleDisputeCreated($payload);
            break;
        case 'order.paid':
            logMessage('Order paid webhook received', 'INFO');
            break;
        default:
            logMessage('Unhandled webhook event: ' . $event, 'INFO');
    }
    http_response_code(200);
    echo 'OK';
} catch (Exception $e) {
    logMessage('Webhook processing error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    exit('Processing error');
}

// --- WEBHOOK HANDLER FUNCTIONS ---

function handlePaymentCaptured($payload)
{
    global $db;
    $payment = $payload['payment']['entity'];
    $order_id_from_rzp = $payment['order_id'];
    $payment_id = $payment['id'];

    $order = $db->fetchRow('SELECT * FROM ' . DB_PREFIX . 'orders WHERE razorpay_order_id = :order_id', ['order_id' => $order_id_from_rzp]);

    if ($order && $order['payment_status'] !== 'completed') {
        $db->update('orders', [
            'payment_status' => 'completed',
            'status' => 'processing',
            'razorpay_payment_id' => $payment_id,
            'payment_method' => $payment['method'],
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $order['id']]);

        logMessage("Order {$order['order_number']} payment captured.", 'INFO');
        sendOrderStatusEmail($order['id'], 'processing');
    }
}

function handlePaymentFailed($payload)
{
    global $db;
    $payment = $payload['payment']['entity'];
    $order_id_from_rzp = $payment['order_id'];
    $order = $db->fetchRow('SELECT * FROM ' . DB_PREFIX . 'orders WHERE razorpay_order_id = :order_id', ['order_id' => $order_id_from_rzp]);

    if ($order && $order['status'] !== 'cancelled') {
        $db->update('orders', [
            'payment_status' => 'failed',
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $order['id']]);

        logMessage("Order {$order['order_number']} payment failed, status set to cancelled.", 'INFO');
        sendOrderStatusEmail($order['id'], 'cancelled');
    }
}

function handleRefundCreated($payload)
{
    global $db;
    $refund = $payload['refund']['entity'];
    $payment_id = $refund['payment_id'];
    $order = $db->fetchRow('SELECT * FROM ' . DB_PREFIX . 'orders WHERE razorpay_payment_id = :payment_id', ['payment_id' => $payment_id]);

    if ($order && $order['status'] !== 'refunded') {
        $db->update('orders', [
            'payment_status' => 'refunded',
            'status' => 'refunded',
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $order['id']]);

        logMessage("Order {$order['order_number']} status updated to refunded.", 'INFO');
        sendOrderStatusEmail($order['id'], 'refunded');
    }
}

function handleDisputeCreated($payload)
{
    global $db;
    $dispute = $payload['dispute']['entity'];
    $payment_id = $dispute['payment_id'];
    $order = $db->fetchRow('SELECT * FROM ' . DB_PREFIX . 'orders WHERE razorpay_payment_id = :payment_id', ['payment_id' => $payment_id]);

    if ($order && $order['status'] !== 'disputed') {
        $new_note = $order['notes'] . "\n\nDispute Created on " . date('Y-m-d') . ". Dispute ID: " . $dispute['id'];
        $db->update('orders', [
            'status' => 'disputed',
            'notes' => $new_note,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $order['id']]);

        logMessage("Order {$order['order_number']} status updated to disputed.", 'INFO');
    }
}

// --- EMAIL FUNCTIONS AND TEMPLATES COPIED FROM ADMIN/ORDERS.PHP ---

function sendOrderStatusEmail($order_id, $new_status)
{
    global $db;
    try {
        $order = $db->fetchRow(
            "SELECT o.*, u.first_name, u.last_name, u.email 
             FROM " . DB_PREFIX . "orders o 
             LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
             WHERE o.id = ?",
            [$order_id]
        );
        if (!$order) {
            error_log("Order not found for email: $order_id");
            return false;
        }
        $status_templates = [
            'processing' => ['subject' => 'Your Order #' . $order['order_number'] . ' is Being Processed', 'message' => createProcessingEmail($order)],
            'cancelled' => ['subject' => 'Update on Your Order #' . $order['order_number'], 'message' => createCancelledEmail($order)],
            'refunded' => ['subject' => 'Refund Processed for Order #' . $order['order_number'], 'message' => createRefundedEmail($order)],
        ];
        if (isset($status_templates[$new_status])) {
            $template = $status_templates[$new_status];
            return sendSecureEmail($order['email'], $template['subject'], $template['message']);
        }
        return true;
    } catch (Exception $e) {
        error_log("Error sending order status email: " . $e->getMessage());
        return false;
    }
}

function createProcessingEmail($order)
{
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Order Processing</title><style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.6;color:#333;background-color:#f8f9fa;margin:0;padding:20px}.container{max-width:600px;margin:0 auto;background:white;border-radius:10px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.1)}.header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:30px 20px;text-align:center;color:white}.header h1{margin:0;font-size:24px;font-weight:600}.content{padding:30px}.footer{background-color:#f8f9fa;padding:20px;font-size:12px;color:#666;text-align:center;border-top:1px solid #eee}.order-details{background-color:#f8f9fa;padding:15px;border-radius:8px;margin:20px 0}</style></head><body><div class='container'><div class='header'><h1>" . htmlspecialchars(SITE_NAME) . "</h1><p>Order Processing Update</p></div><div class='content'><h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2><p>Your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> is now being processed!</p><div class='order-details'><h3>Order Details:</h3><p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p><p><strong>Order Date:</strong> " . date('F j, Y', strtotime($order['created_at'])) . "</p><p><strong>Order Total:</strong> ₹" . number_format($order['total_amount'], 2) . "</p></div><p>Our team is preparing your items for shipment. You'll receive another notification when your order ships.</p><p>If you have any questions about your order, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p><p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p></div><div class='footer'><p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>" . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p></div></div></body></html>";
}

function createCancelledEmail($order)
{
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Order Cancelled</title><style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.6;color:#333;background-color:#f8f9fa;margin:0;padding:20px}.container{max-width:600px;margin:0 auto;background:white;border-radius:10px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.1)}.header{background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%);padding:30px 20px;text-align:center;color:white}.header h1{margin:0;font-size:24px;font-weight:600}.content{padding:30px}.footer{background-color:#f8f9fa;padding:20px;font-size:12px;color:#666;text-align:center;border-top:1px solid #eee}.order-details{background-color:#f8f9fa;padding:15px;border-radius:8px;margin:20px 0}</style></head><body><div class='container'><div class='header'><h1>" . htmlspecialchars(SITE_NAME) . "</h1><p>Order Update</p></div><div class='content'><h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2><p>We're writing to inform you that your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> has been cancelled.</p><div class='order-details'><h3>Order Details:</h3><p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p><p><strong>Order Date:</strong> " . date('F j, Y', strtotime($order['created_at'])) . "</p><p><strong>Order Total:</strong> ₹" . number_format($order['total_amount'], 2) . "</p></div><p>If this cancellation was unexpected or if you have any questions, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p><p>We hope to see you again soon at " . htmlspecialchars(SITE_NAME) . "!</p></div><div class='footer'><p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>" . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p></div></div></body></html>";
}

function createRefundedEmail($order)
{
    $product_total = $order['total_amount'] - $order['shipping_cost'];
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Refund Processed</title><style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.6;color:#333;background-color:#f8f9fa;margin:0;padding:20px}.container{max-width:600px;margin:0 auto;background:white;border-radius:10px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.1)}.header{background:linear-gradient(135deg,#6366f1 0%,#4f46e5 100%);padding:30px 20px;text-align:center;color:white}.header h1{margin:0;font-size:24px;font-weight:600}.content{padding:30px}.footer{background-color:#f8f9fa;padding:20px;font-size:12px;color:#666;text-align:center;border-top:1px solid #eee}.refund-details{background-color:#f0f9ff;padding:15px;border-radius:8px;margin:20px 0;border-left:4px solid #6366f1}</style></head><body><div class='container'><div class='header'><h1>" . htmlspecialchars(SITE_NAME) . "</h1><p>Refund Processed</p></div><div class='content'><h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2><p>We've processed a refund for your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong>.</p><div class='refund-details'><h3>Refund Details:</h3><p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p><p><strong>Refund Amount:</strong> ₹" . number_format($product_total, 2) . "</p><p><strong>Discount Applied:</strong> ₹" . number_format($order['discount_amount'], 2) . "</p><p><strong>Refund Method:</strong> Online payment method</p><p><strong>Processing Time:</strong> 5-10 business days (depending on your bank)</p></div><p>If you have any questions about your refund, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p><p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p></div><div class='footer'><p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>" . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p></div></div></body></html>";
}
