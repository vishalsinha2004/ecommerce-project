<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */

/**
 * View Order Details - Admin Panel
 * ElegantDresses E-commerce Platform
 * 
 * This file displays detailed information about a specific order
 * including customer details, order items, payment info, and shipping details
 */

// Start session and include required files
session_start();

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/email_handler.php';

// Security: Check if user is authenticated and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Verify admin role
try {
    $admin_check = $db->fetchRow(
        "SELECT role FROM " . DB_PREFIX . "users WHERE id = ? AND role = 'admin' AND status = 'active'",
        [$_SESSION['user_id']]
    );

    if (!$admin_check) {
        session_destroy();
        header('Location: ../auth/login.php?error=access_denied');
        exit;
    }
} catch (Exception $e) {
    error_log("Admin auth error: " . $e->getMessage());
    header('Location: ../auth/login.php?error=system_error');
    exit;
}

// Get order ID from URL
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id <= 0) {
    header('Location: orders.php?error=invalid_order');
    exit;
}

// Initialize variables
$success_message = '';
$error_message = '';
$order = null;
$order_items = [];
$customer = null;

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        if ($_POST['action'] === 'update_status' && isset($_POST['new_status'])) {
            $new_status = $_POST['new_status'];
            $allowed_statuses = [
                'pending',
                'processing',
                'shipped',
                'delivered',
                'cancelled',
                'refunded',
                'cancel_requested',
                'cancel_confirmed',
                'return_requested',
                'return_confirmed',
                'request_rejected'
            ];

            if (in_array($new_status, $allowed_statuses)) {
                try {
                    // Build update data
                    $update_data = [
                        'status' => $new_status,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    // If status is refunded, also update payment_status
                    if ($new_status === 'refunded') {
                        $update_data['payment_status'] = 'refunded';
                    }

                    // If status is delivered, set delivered_on date
                    if ($new_status === 'delivered') {
                        $update_data['delivered_on'] = date('Y-m-d H:i:s');
                    }

                    $result = $db->update(
                        'orders',
                        $update_data,
                        'id = :id',
                        ['id' => $order_id]
                    );

                    if ($result !== false) {
                        $success_message = "Order status updated successfully to " . ucfirst($new_status);

                        // Log admin action
                        $log_message = date('Y-m-d H:i:s') . " - ADMIN ACTION: User {$_SESSION['user_id']} updated order {$order_id} status to '{$new_status}'" . PHP_EOL;
                        file_put_contents(LOGS_PATH . '/app.log', $log_message, FILE_APPEND | LOCK_EX);

                        // Send email notification to customer
                        sendOrderStatusEmail($order_id, $new_status);
                    } else {
                        $error_message = 'Failed to update order status.';
                    }
                } catch (Exception $e) {
                    error_log("Order status update error: " . $e->getMessage());
                    $error_message = 'An error occurred while updating the order status.';
                }
            } else {
                $error_message = 'Invalid status selected.';
            }
        }
    }
}

// Fetch order details with customer information
try {
    $order = $db->fetchRow(
        "SELECT o.*, u.first_name, u.last_name, u.email, u.phone, u.address_1, u.city, u.state, u.country, u.postal_code
         FROM " . DB_PREFIX . "orders o 
         JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
         WHERE o.id = ?",
        [$order_id]
    );

    if (!$order) {
        header('Location: orders.php?error=order_not_found');
        exit;
    }

    // Fetch order items
    $order_items = $db->fetchAll(
        "SELECT oi.*, p.name, p.image 
     FROM " . DB_PREFIX . "order_items oi 
     JOIN " . DB_PREFIX . "products p ON oi.product_id = p.id 
     WHERE oi.order_id = ?",
        [$order_id]
    );
} catch (Exception $e) {
    error_log("Order fetch error: " . $e->getMessage());
    $error_message = 'Failed to load order details.';
}

// Function to send order status email
function sendOrderStatusEmail($order_id, $new_status)
{
    global $db;

    try {
        // Get order details with customer info
        $order = $db->fetchRow(
            "SELECT o.*, u.first_name, u.last_name, u.email 
             FROM " . DB_PREFIX . "orders o 
             JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
             WHERE o.id = ?",
            [$order_id]
        );

        if (!$order) {
            error_log("Order not found for email: $order_id");
            return false;
        }

        // Prepare email content based on status
        $status_templates = [
            'processing' => [
                'subject' => 'Your Order #' . $order['order_number'] . ' is Being Processed',
                'message' => createProcessingEmail($order)
            ],
            'shipped' => [
                'subject' => 'Your Order #' . $order['order_number'] . ' Has Shipped!',
                'message' => createShippedEmail($order)
            ],
            'delivered' => [
                'subject' => 'Your Order #' . $order['order_number'] . ' Has Been Delivered',
                'message' => createDeliveredEmail($order)
            ],
            'cancelled' => [
                'subject' => 'Update on Your Order #' . $order['order_number'],
                'message' => createCancelledEmail($order)
            ],
            'refunded' => [
                'subject' => 'Refund Processed for Order #' . $order['order_number'],
                'message' => createRefundedEmail($order)
            ],
            'return_requested' => [
                'subject' => 'Return Requested for Order #' . $order['order_number'],
                'message' => createReturnRequestedEmail($order)
            ],
            'return_confirmed' => [
                'subject' => 'Return Confirmed for Order #' . $order['order_number'],
                'message' => createReturnConfirmedEmail($order)
            ],
            'request_rejected' => [
                'subject' => 'Return Request Rejected for Order #' . $order['order_number'],
                'message' => createRequestRejectedEmail($order)
            ],
        ];

        // Check if we have a template for this status
        if (isset($status_templates[$new_status])) {
            $template = $status_templates[$new_status];

            // Send email
            return sendSecureEmail(
                $order['email'],
                $template['subject'],
                $template['message'],
                SITE_NAME,
                SUPPORT_EMAIL,
                SITE_NAME . ' Support'
            );
        }

        return true; // No email needed for this status

    } catch (Exception $e) {
        error_log("Error sending order status email: " . $e->getMessage());
        return false;
    }
}

// Email template functions
function createProcessingEmail($order)
{
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Order Processing</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .order-details { background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Order Processing Update</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2>
                <p>Your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> is now being processed!</p>
                
                <div class='order-details'>
                    <h3>Order Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                    <p><strong>Order Date:</strong> " . date('F j, Y', strtotime($order['created_at'])) . "</p>
                    <p><strong>Order Total:</strong> ₹" . number_format($order['total_amount'], 2) . "</p>
                </div>
                
                <p>Our team is preparing your items for shipment. You'll receive another notification when your order ships.</p>
                
                <p>If you have any questions about your order, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                
                <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
            </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

function createShippedEmail($order)
{
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Order Shipped</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .order-details { background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .tracking-button { display: inline-block; padding: 12px 24px; background: #10b981; color: white; text-decoration: none; border-radius: 5px; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Your Order Has Shipped!</p>
            </div>
            <div class='content'>
                <h2>Great news, " . htmlspecialchars($order['first_name']) . "!</h2>
                <p>Your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> has been shipped and is on its way to you.</p>
                
                <div class='order-details'>
                    <h3>Shipping Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                    <p><strong>Shipping Method:</strong> Standard Shipping</p>
                    <p><strong>Estimated Delivery:</strong> 3-5 business days</p>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . SITE_URL . "/track-order.php?order=" . htmlspecialchars($order['order_number']) . "' class='tracking-button'>Track Your Order</a>
                </div>
                
                <p>If you have any questions about your shipment, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                
                <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
            </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

function createDeliveredEmail($order)
{
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Order Delivered</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #059669 0%, #047857 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .review-button { display: inline-block; padding: 12px 24px; background: #059669; color: white; text-decoration: none; border-radius: 5px; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Your Order Has Been Delivered!</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2>
                <p>Your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> has been successfully delivered.</p>
                
                <p>We hope you love your new items! If you have a moment, we'd greatly appreciate your feedback on the products you purchased.</p>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . SITE_URL . "/account/orders.php' class='review-button'>Leave a Review</a>
                </div>
                
                <p>If you have any questions or concerns about your order, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                
                <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
            </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

function createCancelledEmail($order)
{
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Order Cancelled</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .order-details { background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Order Update</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2>
                <p>We're writing to inform you that your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> has been cancelled.</p>
                
                <div class='order-details'>
                    <h3>Order Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                    <p><strong>Order Date:</strong> " . date('F j, Y', strtotime($order['created_at'])) . "</p>
                    <p><strong>Order Total:</strong> ₹" . number_format($order['total_amount'], 2) . "</p>
                </div>
                
                <p>If this cancellation was unexpected or if you have any questions, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                
                <p>We hope to see you again soon at " . htmlspecialchars(SITE_NAME) . "!</p>
            </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

function createRefundedEmail($order)
{
    $product_total = $order['total_amount'] - $order['shipping_cost'];
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Refund Processed</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .refund-details { background-color: #f0f9ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #6366f1; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Refund Processed</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2>
                <p>We've processed a refund for your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong>.</p>
                
                <div class='refund-details'>
                    <h3>Refund Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                    <p><strong>Refund Amount:</strong> ₹" . number_format($product_total, 2) . "</p>
                    <p><strong>Refund Method:</strong> Online payment method</p>
                    <p><strong>Processing Time:</strong> 5-10 business days (depending on your bank)</p>
                </div>
                
                <p>If you have any questions about your refund, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                
                <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
            </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

function createReturnRequestedEmail($order)
{
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Return Requested</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .return-details { background-color: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #f59e0b; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Return Requested</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2>
                <p>We have received your request to return items from your order <strong>#" . htmlspecialchars($order['order_number']) . "</strong>.</p>

                <div class='return-details'>
                    <h3>Return Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                    <p><strong>Return Reason:</strong> Please contact our support team for details.</p>
                    <p><strong>Next Steps:</strong> Our team will review your request and contact you with further instructions.</p>
                </div>

                <p>If you have any questions about your return request, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>

                <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
            </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

function createReturnConfirmedEmail($order)
{
    $product_total = $order['total_amount'] - $order['shipping_cost'] + $order['discount_amount'];
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Return Confirmed</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #4ade80 0%, #22c55e 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .return-details { background-color: #d1fae5; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #4ade80; }
        </style>
    </head>
    <body>  
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Return Confirmed</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2>
                <p>We have successfully processed your return request for order <strong>#" . htmlspecialchars($order['order_number']) . "</strong>.</p>
                
                <div class='return-details'>
                    <h3>Return Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                    <p><strong>Return Status:</strong> Confirmed</p>
                    <p><strong>Refund Amount:</strong> ₹" . number_format($product_total, 2) . "</p>
                </div>
                
                <p>If you have any questions about your return, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                
                <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
                </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

function createRequestRejectedEmail($order)
{
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Return Request Rejected</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #f87171 0%, #dc2626 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .rejection-details { background-color: #fee2e2; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #f87171; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Return Request Rejected</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($order['first_name']) . ",</h2>
                <p>We regret to inform you that your return request for order <strong>#" . htmlspecialchars($order['order_number']) . "</strong> has been rejected.</p>

                <div class='rejection-details'>
                    <h3>Rejection Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                </div>

                <p>If you have any questions or believe this was an error, please contact our support team at " . htmlspecialchars(SUPPORT_EMAIL) . "</p>
                <p>Thank you for your understanding.</p>
            </div>
            <div class='footer'>
                <p>This email was sent by " . htmlspecialchars(SITE_NAME) . ".<br>
                " . htmlspecialchars(COMPANY_NAME) . " | " . htmlspecialchars(COMPANY_ADDRESS) . "</p>
            </div>
        </div>
    </body>
    </html>";
}

$page_title = 'View Order #' . ($order['order_number'] ?? $order_id) . ' - Admin Panel';

// Status color mapping
$status_colors = [
    'pending' => 'bg-yellow-100 text-yellow-800',
    'processing' => 'bg-blue-100 text-blue-800',
    'shipped' => 'bg-purple-100 text-purple-800',
    'delivered' => 'bg-green-100 text-green-800',
    'cancelled' => 'bg-red-100 text-red-800',
    'refunded' => 'bg-gray-100 text-gray-800',
    'cancel_requested' => 'bg-orange-100 text-orange-800',
    'cancel_confirmed' => 'bg-red-100 text-red-800',
    'return_requested' => 'bg-yellow-100 text-yellow-800',
    'return_confirmed' => 'bg-gray-100 text-gray-800',
    'request_rejected' => 'bg-red-100 text-red-800'
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 font-sans">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Left side: Title and Breadcrumbs -->
                <div class="flex items-center">
                    <h1 class="text-lg sm:text-xl font-semibold text-gray-900">
                        <a href="dashboard.php" class="text-blue-600 hover:text-blue-700">Admin Panel</a>
                    </h1>
                    <!-- Breadcrumbs hidden on mobile (screens smaller than 640px) -->
                    <div class="hidden sm:flex sm:items-center">
                        <span class="mx-2 text-gray-400">/</span>
                        <a href="orders.php" class="text-sm text-gray-600 hover:text-gray-900">Orders</a>
                        <span class="mx-2 text-gray-400">/</span>
                        <span class="text-sm text-gray-900">View Order</span>
                    </div>
                </div>
                <!-- Right side: Welcome message and Logout -->
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <!-- Welcome message is smaller on mobile -->
                    <span class="text-xs sm:text-sm text-gray-600">Welcome, <?= htmlspecialchars($_SESSION['first_name']) ?></span>
                    <!-- Logout is smaller on mobile -->
                    <a href="../auth/logout.php" class="text-xs sm:text-sm text-red-600 hover:text-red-700">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Back Button -->
        <div class="mb-6">
            <a href="orders.php" class="inline-flex items-center text-blue-600 hover:text-blue-700">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Back to Orders
            </a>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($order): ?>
           <!-- Order Header -->
<div class="bg-white shadow rounded-lg mb-6">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
        <!-- Main container: flex on sm+, default block on mobile -->
        <div class="sm:flex sm:justify-between sm:items-start">
            
            <!-- Left Section: Order Number and Date -->
            <div class="flex-grow">
                <h2 class="text-xl sm:text-2xl font-semibold text-gray-900 leading-tight">
                    Order #<?= htmlspecialchars($order['order_number']) ?>
                </h2>
                <p class="text-xs sm:text-sm text-gray-600 mt-1">
                    Placed on <?= date('F j, Y, g:i A', strtotime($order['created_at'])) ?>
                </p>
            </div>

            <!-- Right Section: Price and Status -->
            <!-- This container handles the responsive shift -->
            <div class="mt-4 sm:mt-0 flex justify-between items-center sm:flex-col sm:items-end">
                <span class="text-xl sm:text-2xl font-semibold text-gray-900">
                    ₹<?= number_format($order['total_amount'], 2) ?>
                </span>
                
                <span class="sm:mt-1 inline-flex items-center px-3 py-1 rounded-full text-xs sm:text-sm font-medium <?= $status_colors[$order['status']] ?? 'bg-gray-100 text-gray-800' ?>">
                    <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                </span>
            </div>

        </div>
    </div>
    
    <!-- Status Update Form -->
    <div class="px-4 py-4 bg-gray-50 border-gray-200">
        <form method="POST" class="flex flex-wrap items-center gap-y-2 gap-x-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_status">
            
            <div class="flex items-center flex-grow sm:flex-grow-0">
                <label for="new_status" class="text-sm font-medium text-gray-700 mr-2">Update Status:</label>
                <select name="new_status" id="new_status" class="w-full sm:w-auto border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                    <?php
                    $statuses = [
                        'pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded',
                        'cancel_requested', 'cancel_confirmed', 'return_requested', 'return_confirmed', 'request_rejected'
                    ];
                    foreach ($statuses as $status) {
                        $selected = ($order['status'] === $status) ? 'selected' : '';
                        echo "<option value=\"{$status}\" {$selected}>" . ucfirst(str_replace('_', ' ', $status)) . "</option>";
                    }
                    ?>
                </select>
            </div>
            
            <button type="submit" class="w-full sm:w-auto bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Update Status
            </button>
        </form>
    </div>
</div>


            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Customer Information -->
                <div class="lg:col-span-2">
                    <!-- Order Items -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">Order Items</h3>
                        </div>
                        <div class="px-6 py-4">
                            <?php if (!empty($order_items)): ?>
                                <div class="space-y-4">
                                    <?php foreach ($order_items as $item): ?>
                                        <div class="flex items-center space-x-4 py-2 border-b border-gray-100">
                                            <div class="flex-shrink-0">
                                                <?php if (!empty($item['image'])): ?>
                                                    <img src="<?= BASE_URL ?>/assets/images/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-16 h-16 object-cover rounded">
                                                <?php else: ?>
                                                    <div class="w-16 h-16 bg-gray-200 rounded flex items-center justify-center">
                                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                        </svg>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 truncate">
                                                    <?= htmlspecialchars($item['name']) ?>
                                                </p>
                                                <p class="text-sm text-gray-500">
                                                    Quantity: <?= $item['quantity'] ?>
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-sm font-medium text-gray-900">
                                                    ₹<?= number_format($item['unit_price'], 2) ?>
                                                </p>
                                                <p class="text-sm text-gray-500">
                                                    Total: ₹<?= number_format($item['unit_price'] * $item['quantity'], 2) ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500">No items found for this order.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Customer Information -->
                    <div class="bg-white shadow rounded-lg mt-6">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">Customer Information</h3>
                        </div>
                        <div class="px-6 py-4">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Full Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        <?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Email</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        <a href="mailto:<?= htmlspecialchars($order['email']) ?>" class="text-blue-600 hover:text-blue-700">
                                            <?= htmlspecialchars($order['email']) ?>
                                        </a>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Phone</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        <?= htmlspecialchars($order['phone'] ?? 'Not provided') ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Customer ID</dt>
                                    <dd class="mt-1 text-sm text-gray-900"><?= $order['user_id'] ?></dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Shipping Address -->
                    <div class="bg-white shadow rounded-lg mt-6">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">Shipping Address</h3>
                        </div>
                        <div class="px-6 py-4">
                            <?php if (!empty($order['shipping_address'])): ?>
                                <div class="text-sm text-gray-900 whitespace-pre-line">
                                    <?= htmlspecialchars($order['shipping_address']) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-sm text-gray-900">
                                    <?= htmlspecialchars($order['address_1'] ?? 'Address not available') ?><br>
                                    <?= htmlspecialchars($order['city'] ?? '') ?>, <?= htmlspecialchars($order['state'] ?? '') ?> <?= htmlspecialchars($order['postal_code'] ?? '') ?><br>
                                    <?= htmlspecialchars($order['country'] ?? '') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Billing Address -->
                    <?php if (!empty($order['billing_address'])): ?>
                        <div class="bg-white shadow rounded-lg mt-6">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900">Billing Address</h3>
                            </div>
                            <div class="px-6 py-4">
                                <div class="text-sm text-gray-900 whitespace-pre-line">
                                    <?= htmlspecialchars($order['billing_address']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Order Notes -->
                    <?php if (!empty($order['notes'])): ?>
                        <div class="bg-white shadow rounded-lg mt-6">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900">Order Notes</h3>
                            </div>
                            <div class="px-6 py-4">
                                <div class="text-sm text-gray-900 whitespace-pre-line">
                                    <?= htmlspecialchars($order['notes']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Order Summary -->
                <div class="lg:col-span-1">
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">Order Summary</h3>
                        </div>
                        <div class="px-6 py-4">
                            <dl class="space-y-4">
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-600">Order ID</dt>
                                    <dd class="text-sm font-medium text-gray-900"><?= $order['id'] ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-600">Payment Method</dt>
                                    <dd class="text-sm font-medium text-gray-900">
                                        <?= ucfirst($order['payment_method'] ?? 'Not specified') ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-600">Payment Status</dt>
                                    <dd class="text-sm font-medium text-gray-900">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?=
                                                                                                                                $order['payment_status'] == 'paid' ? 'bg-green-100 text-green-800' : ($order['payment_status'] == 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800')
                                                                                                                                ?>">
                                            <?= ucfirst($order['payment_status'] ?? 'Unknown') ?>
                                        </span>
                                    </dd>
                                </div>

                                <div class="border-t border-gray-200 pt-4">
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Subtotal</dt>
                                        <dd class="text-sm text-gray-900">₹<?= number_format(($order['total_amount'] - $order['shipping_cost'] - $order['tax_amount'] + $order['discount_amount']), 2) ?></dd>
                                    </div>
                                    <?php if ($order['discount_amount'] > 0): ?>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Discount</dt>
                                            <dd class="text-sm text-green-600">-₹<?= number_format($order['discount_amount'], 2) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Shipping</dt>
                                        <dd class="text-sm text-gray-900">₹<?= number_format($order['shipping_cost'], 2) ?></dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-sm text-gray-600">Tax</dt>
                                        <dd class="text-sm text-gray-900">₹<?= number_format($order['tax_amount'], 2) ?></dd>
                                    </div>
                                    <div class="flex justify-between border-t border-gray-200 pt-4">
                                        <dt class="text-base font-medium text-gray-900">Total</dt>
                                        <dd class="text-base font-medium text-gray-900">₹<?= number_format($order['total_amount'], 2) ?></dd>
                                    </div>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <div class="bg-white shadow rounded-lg mt-6">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">Order Timeline</h3>
                        </div>
                        <div class="px-6 py-12">
                            <div class="flow-root">
                                <ul class="-mb-4">
                                    <li>
                                        <div class="relative pb-4">
                                            <div class="relative flex items-start space-x-3">
                                                <div class="relative">
                                                    <div class="h-8 w-8 bg-green-500 rounded-full flex items-center justify-center ring-8 ring-white">
                                                        <svg class="h-4 w-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                        </svg>
                                                    </div>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div>
                                                        <div class="text-sm">
                                                            <span class="font-medium text-gray-900">Order Created</span>
                                                        </div>
                                                        <p class="mt-0.5 text-sm text-gray-500">
                                                            <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>

                                    <?php if ($order['updated_at'] != $order['created_at']): ?>
                                        <li>
                                            <div class="relative pb-4">
                                                <div class="relative flex items-start space-x-3">
                                                    <div class="relative">
                                                        <div class="h-8 w-8 bg-blue-500 rounded-full flex items-center justify-center ring-8 ring-white">
                                                            <svg class="h-4 w-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                                                            </svg>
                                                        </div>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <div>
                                                            <div class="text-sm">
                                                                <span class="font-medium text-gray-900">Status: <?= ucfirst(str_replace('_', ' ', $order['status'])) ?></span>
                                                            </div>
                                                            <p class="mt-0.5 text-sm text-gray-500">
                                                                <?= date('F j, Y \a\t g:i A', strtotime($order['updated_at'])) ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endif; ?>

                                    <?php if ($order['delivered_on'] != null): ?>
                                        <li>
                                            <!-- This flex container correctly aligns the icon and text side-by-side -->
                                            <div class="relative flex items-start space-x-3">
                                                <!-- Icon Element -->
                                                <div class="relative">
                                                    <div class="h-8 w-8 bg-blue-500 rounded-full flex items-center justify-center ring-8 ring-white">
                                                        <!-- Replaced with a 'check' SVG for 'Delivered' status -->
                                                        <svg class="h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                        </svg>
                                                    </div>
                                                </div>
                                                <!-- Text Content -->
                                                <div class="min-w-0 flex-1">
                                                    <div>
                                                        <div class="text-sm">
                                                            <span class="font-medium text-gray-900">Delivered on</span>
                                                        </div>
                                                        <p class="mt-0.5 text-sm text-gray-500">
                                                            <?= date('F j, Y \a\t g:i A', strtotime($order['delivered_on'])) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Order Not Found -->
            <div class="text-center py-12">
                <div class="mx-auto h-24 w-24 text-gray-400">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <h3 class="mt-4 text-lg font-medium text-gray-900">Order not found</h3>
                <p class="mt-2 text-sm text-gray-500">The order you're looking for doesn't exist or has been removed.</p>
                <div class="mt-6">
                    <a href="orders.php" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        Back to Orders
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <p class="text-center text-sm text-gray-500">
                &copy; <?= date('Y') ?> <?= SITE_NAME ?>. Admin Panel - All rights reserved.
            </p>
        </div>
    </footer>
</body>

</html>