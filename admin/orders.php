    <?php
    // admin/orders.php

    // Start secure session
    session_start();

    require_once '../includes/config.php';
    require_once '../includes/db.php';
    require_once '../includes/email_handler.php';

    // Check admin authentication
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

    // Initialize variables
    $success_message = '';
    $error_message = '';
    $orders = [];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 15;
    $status_filter = trim($_GET['status'] ?? '');
    $search = trim($_GET['search'] ?? '');

    // Generate CSRF token
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
                    'subject' => 'Return Request Request Rejected for Order #' . $order['order_number'],
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
                        <a href='" . SITE_URL . "/user/orders.php' class='review-button'>Leave a Review</a>
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
                        <p><strong>Discount Applied:</strong> ₹" . number_format($order['discount_amount'], 2) . "</p>
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

    // Handle status updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        // CSRF check
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error_message = 'Security token mismatch. Please try again.';
        } else {
            if ($_POST['action'] === 'update_status' && isset($_POST['order_id'], $_POST['new_status'])) {
                $order_id = (int)$_POST['order_id'];
                $new_status = $_POST['new_status'];
                $allowed_statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded', 'cancel_requested', 'cancel_confirmed', 'return_requested', 'return_confirmed', 'request_rejected'];

                if (in_array($new_status, $allowed_statuses)) {
                    try {
                        // Get current order details
                        $order = $db->fetchRow(
                            "SELECT order_number, status, user_id FROM " . DB_PREFIX . "orders WHERE id = ?",
                            [$order_id]
                        );

                        if ($order) {
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

                            // Fixed: Use named parameters for consistency
                            $result = $db->update(
                                'orders',
                                $update_data,
                                'id = :id',
                                ['id' => $order_id]
                            );

                            if ($result !== false) {
                                $success_message = "Order #{$order['order_number']} status updated to " . ucfirst($new_status);

                                // Log admin action
                                $log_message = date('Y-m-d H:i:s') . " - ADMIN ACTION: User {$_SESSION['user_id']} updated order {$order_id} status from '{$order['status']}' to '{$new_status}'" . PHP_EOL;
                                file_put_contents(LOGS_PATH . '/app.log', $log_message, FILE_APPEND | LOCK_EX);

                                // Send email notification to customer
                                sendOrderStatusEmail($order_id, $new_status);
                            } else {
                                $error_message = 'Failed to update order status.';
                            }
                        } else {
                            $error_message = 'Order not found.';
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

    // Build query conditions
    $where_conditions = ["1"];
    $params = [];

    if (!empty($status_filter)) {
        $where_conditions[] = "o.status = ?";
        $params[] = $status_filter;
    }

    if (!empty($search)) {
        $where_conditions[] = "(o.order_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
        $search_param = '%' . $search . '%';
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }

    $where_clause = implode(' AND ', $where_conditions);

    try {
        // Get total count for pagination
        $total_count = $db->fetchRow(
            "SELECT COUNT(*) as count FROM " . DB_PREFIX . "orders o 
            JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
            WHERE {$where_clause}",
            $params
        )['count'] ?? 0;

        $total_pages = ceil($total_count / $per_page);

        // Get orders with pagination
        $offset = ($page - 1) * $per_page;
        $orders = $db->fetchAll(
            "SELECT o.*, u.first_name, u.last_name, u.email 
            FROM " . DB_PREFIX . "orders o 
            JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
            WHERE {$where_clause}
            ORDER BY o.created_at DESC 
            LIMIT {$offset}, {$per_page}",
            $params
        );
    } catch (Exception $e) {
        error_log("Orders fetch error: " . $e->getMessage());
        $error_message = 'Failed to load orders.';
        $orders = [];
        $total_pages = 0;
    }

    $page_title = 'Order Management - Admin Panel';
    ?>

    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($page_title) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            primary: {
                                50: '#f0f9ff',
                                500: '#3b82f6',
                                600: '#2563eb',
                                700: '#1d4ed8'
                            }
                        }
                    }
                }
            }
        </script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Inter', sans-serif;
            }

            @media (max-width: 768px) {
                .mobile-padding {
                    padding-left: 1rem;
                    padding-right: 1rem;
                }

                .mobile-margin {
                    margin-left: 1rem;
                    margin-right: 1rem;
                }
            }

            .loader {
                border: 4px solid rgba(255, 255, 255, 0.3);
                border-radius: 50%;
                border-top: 4px solid #ffffff;
                width: 16px;
                height: 16px;
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            .hidden {
                display: none;
            }

            /* Style for button in loading state */
            .loading .loader {
                display: inline-block;
            }

            .loading .btn-text {
                display: none;
            }

            .loading {
                pointer-events: none;
                /* Disable clicks */
                opacity: 0.8;
            }
        </style>
    </head>

    <body class="bg-gray-50">
        <!-- Mobile Menu Toggle -->
        <div class="lg:hidden fixed top-4 left-4 z-50">
            <button id="mobile-menu-toggle" class="p-2 bg-white rounded-md shadow-md">
                <svg class="h-6 w-6 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>

        <!-- Admin Header -->
        <nav class="bg-white shadow-lg border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <a href="dashboard.php" class="text-xl font-semibold text-gray-900 flex items-center ml-12 lg:ml-0">
                            <svg class="h-6 w-6 text-primary-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            Admin Panel
                        </a>
                    </div>
                    <div class="flex items-center space-x-2 sm:space-x-4">
                        <a href="../index.php" class="text-gray-600 hover:text-gray-900 text-sm" target="_blank">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                            <span class="hidden sm:inline">View Site</span>
                        </a>
                        <span class="text-gray-600 text-sm hidden sm:inline">
                            Welcome, <?= htmlspecialchars($_SESSION['first_name'] ?? 'Admin') ?>
                        </span>
                        <a href="../auth/logout.php" class="bg-red-600 text-white px-2 py-1 sm:px-3 sm:py-2 rounded-md text-sm font-medium hover:bg-red-700">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                            </svg>
                            <span class="hidden sm:inline">Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <div class="flex">
            <!-- Sidebar -->
            <div id="sidebar" class="w-64 bg-white shadow-lg h-screen fixed lg:relative transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-40">
                <nav class="mt-8">
                    <div class="px-4 py-2">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Management</h3>
                    </div>
                    <a href="dashboard.php" class="flex text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                        <svg class="h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="products.php" class="text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                        <svg class="w-5 h-5 mr-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                        Products
                    </a>
                    <a href="categories.php" class="flex text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                        <svg class="h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        Categories
                    </a>
                    <a href="orders.php" class="flex bg-primary-50 border-r-4 border-primary-500 text-primary-700 block px-4 py-2 text-sm font-medium">
                        <svg class="h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        Orders
                    </a>
                    <a href="users.php" class="text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                        <svg class="w-5 h-5 mr-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        Users
                    </a>
                    <a href="testimonials.php" class="text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                        <svg class="inline-block w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                        </svg>
                        Testimonials
                    </a>
                    <a href="promotions.php" class="text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                        <svg class="inline-block h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        Promotions
                    </a>
                </nav>
            </div>

            <!-- Overlay for mobile -->
            <div id="sidebar-overlay" class="fixed inset-0 bg-black opacity-50 z-30 lg:hidden hidden"></div>

            <!-- Main Content -->
            <div class="flex-1 p-4 lg:p-8 mobile-padding min-w-0">
                <div class="mb-6">
                    <div class="flex justify-between items-center">
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Order Management</h1>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm"><?= htmlspecialchars($success_message) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm"><?= htmlspecialchars($error_message) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="bg-white shadow-sm rounded-lg mb-6">
                    <div class="p-4 sm:p-6">
                        <form method="GET" class="space-y-4 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-4">
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>"
                                    placeholder="Order number, customer name, email..."
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status"
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Processing</option>
                                    <option value="shipped" <?= $status_filter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                                    <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    <option value="refunded" <?= $status_filter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                                    <option value="cancel_requested" <?= $status_filter === 'cancel_requested' ? 'selected' : '' ?>>Cancel Requested</option>
                                    <option value="cancel_confirmed" <?= $status_filter === 'cancel_confirmed' ? 'selected' : '' ?>>Cancel Confirmed</option>
                                    <option value="return_requested" <?= $status_filter === 'return_requested' ? 'selected' : '' ?>>Return Requested</option>
                                    <option value="return_confirmed" <?= $status_filter === 'return_confirmed' ? 'selected' : '' ?>>Return Confirmed</option>
                                    <option value="request_rejected" <?= $status_filter === 'request_rejected' ? 'selected' : '' ?>>Request Rejected</option>
                                </select>
                            </div>
                            <div class="flex items-end space-x-2">
                                <button type="submit" class="flex-1 lg:flex-none bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-indigo-700">
                                    <svg class="inline-block w-4 h-4 mr-1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor">
                                        <path d="M3.9 54.9C10.5 40.9 24.5 32 40 32H472c15.5 0 29.5 8.9 36.1 22.9s4.6 30.5-5.2 42.5L320 320.9V448c0 12.1-6.8 23.2-17.7 28.6s-23.8 4.3-33.5-3l-64-48c-8.1-6-12.8-15.5-12.8-25.6V320.9L9 97.3C-.7 85.4-2.8 68.8 3.9 54.9z" />
                                    </svg>
                                    Filter
                                </button>
                                <a href="orders.php" class="flex-1 lg:flex-none bg-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm font-medium hover:bg-gray-400 text-center">
                                    Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="bg-white shadow-sm rounded-lg">
                    <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-medium text-gray-900">
                            Orders (<?= number_format($total_count) ?> total)
                        </h2>
                    </div>

                    <!-- Mobile Card View -->
                    <div class="block lg:hidden">
                        <?php if (empty($orders)): ?>
                            <div class="p-8 text-center text-gray-500">
                                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-2.5 5H21M9 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM20.5 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"></path>
                                </svg>
                                <p>No orders found</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4 p-4">
                                <?php foreach ($orders as $order): ?>
                                    <div class="border border-gray-200 rounded-lg p-4">

                                        <!-- ===== Modified Section Start ===== -->
                                       <!-- ===== Modified Section Start ===== -->
<div class="mb-2">
    <div class="flex items-center flex-wrap gap-x-2 gap-y-1 mb-1">
        <div class="font-medium text-gray-900">
            #<?= htmlspecialchars($order['order_number'] ?? '') ?>
        </div>
        <span class="px-2 py-1 text-xs font-semibold rounded-full
            <?php
            switch ($order['status'] ?? '') {
                case 'pending':
                case 'cancel_requested':
                case 'return_requested':
                    echo 'bg-yellow-100 text-yellow-800';
                    break;
                case 'processing':
                    echo 'bg-blue-100 text-blue-800';
                    break;
                case 'shipped':
                    echo 'bg-purple-100 text-purple-800';
                    break;
                case 'delivered':
                    echo 'bg-green-100 text-green-800';
                    break;
                case 'cancelled':
                case 'cancel_confirmed':
                case 'request_rejected':
                    echo 'bg-red-100 text-red-800';
                    break;
                case 'refunded':
                case 'return_confirmed':
                    echo 'bg-gray-100 text-gray-800';
                    break;
                default:
                    echo 'bg-gray-100 text-gray-800';
            }
            ?>">
            <?= ucfirst(htmlspecialchars($order['status'] ?? '')) ?>
        </span>
    </div>
    <div class="text-sm text-gray-500">ID: <?= $order['id'] ?? '' ?></div>
</div>
<!-- ===== Modified Section End ===== -->
                                        <!-- ===== Modified Section End ===== -->

                                        <div class="text-sm text-gray-600 mb-2">
                                            <?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($order['email']) ?></div>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <div class="font-medium text-gray-900">₹<?= number_format($order['total_amount'], 2) ?></div>
                                                <div class="text-xs text-gray-500"><?= date('M j, Y', strtotime($order['created_at'])) ?></div>
                                            </div>
                                            <div class="flex space-x-2">
                                                <button onclick="showStatusModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>', '<?= htmlspecialchars($order['status']) ?>')"
                                                    class="text-indigo-600 hover:text-indigo-900 p-1" title="Update Status">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </button>
                                                <a href="view_orders.php?id=<?= $order['id'] ?>"
                                                    class="text-blue-600 hover:text-blue-900 p-1" title="View Details">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>


                    <!-- Desktop Table View -->
                    <div class="hidden lg:block overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-2.5 5H21M9 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM20.5 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"></path>
                                            </svg>
                                            <p>No orders found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr class="hover:bg-gray-50">
    <td class="px-6 py-4 whitespace-nowrap">
        <div class="text-sm font-medium text-gray-900">
            #<?= htmlspecialchars($order['order_number'] ?? '') ?>
        </div>
        <div class="text-sm text-gray-500">
            ID: <?= $order['id'] ?? '' ?>
        </div>
    </td>
    <td class="px-6 py-4 whitespace-nowrap">
        <div class="text-sm text-gray-900">
            <?= htmlspecialchars(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?>
        </div>
        <div class="text-sm text-gray-500">
            <?= htmlspecialchars($order['email'] ?? '') ?>
        </div>
    </td>
    <td class="px-6 py-4 whitespace-nowrap">
        <div class="text-sm font-medium text-gray-900">
            ₹<?= number_format($order['total_amount'] ?? 0, 2) ?>
        </div>
        <div class="text-sm text-gray-500">
            <?= ucfirst($order['payment_method'] ?? 'N/A') ?>
        </div>
    </td>
    <td class="px-6 py-4 whitespace-nowrap">
        <span class="px-2 py-1 text-xs font-semibold rounded-full
            <?php
            switch ($order['status'] ?? '') {
                case 'pending':
                case 'cancel_requested':
                case 'return_requested':
                    echo 'bg-yellow-100 text-yellow-800';
                    break;
                case 'processing':
                    echo 'bg-blue-100 text-blue-800';
                    break;
                case 'shipped':
                    echo 'bg-purple-100 text-purple-800';
                    break;
                case 'delivered':
                    echo 'bg-green-100 text-green-800';
                    break;
                case 'cancelled':
                case 'cancel_confirmed':
                case 'request_rejected':
                    echo 'bg-red-100 text-red-800';
                    break;
                case 'refunded':
                case 'return_confirmed':
                    echo 'bg-gray-100 text-gray-800';
                    break;
                default:
                    echo 'bg-gray-100 text-gray-800';
            }
            ?>">
            <?= ucfirst(htmlspecialchars($order['status'] ?? '')) ?>
        </span>
    </td>
    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
        <?= isset($order['created_at']) ? date('M j, Y', strtotime($order['created_at'])) : 'N/A' ?>
        <div class="text-xs text-gray-400">
            <?= isset($order['created_at']) ? date('g:i A', strtotime($order['created_at'])) : '' ?>
        </div>
    </td>
    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
        <div class="flex space-x-2">
            <button onclick="showStatusModal(<?= $order['id'] ?? 0 ?>, '<?= htmlspecialchars($order['order_number'] ?? '') ?>', '<?= htmlspecialchars($order['status'] ?? '') ?>')"
                class="text-indigo-600 hover:text-indigo-900" title="Update Status">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
            </button>
            <a href="view_orders.php?id=<?= $order['id'] ?? '' ?>"
                class="text-blue-600 hover:text-blue-900" title="View Details">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                </svg>
            </a>
        </div>
    </td>
</tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-4 sm:px-6 py-3 bg-gray-50 border-t border-gray-200">
                            <div class="flex-col sm:flex-row items-center justify-between space-y-3 sm:space-y-0">
                                <div class="text-sm text-gray-700">
                                    Showing <?= ($page - 1) * $per_page + 1 ?> to <?= min($page * $per_page, $total_count) ?> of <?= $total_count ?> results
                                </div>
                                <div class="flex space-x-1">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>"
                                            class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded">
                                            Previous
                                        </a>
                                    <?php endif; ?>
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>"
                                            class="px-3 py-2 text-sm border <?= $i === $page ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' ?> rounded">
                                            <?= $i ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?>"
                                            class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded">
                                            Next
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Status Update Modal -->
        <div id="statusModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Update Order Status</h3>
                <p class="text-gray-600 mb-4">Change status for order <span id="orderNumber" class="font-medium"></span></p>
                <form id="statusForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="order_id" id="statusOrderId">

                    <div class="mb-4">
                        <label for="newStatus" class="block text-sm font-medium text-gray-700 mb-2">New Status</label>
                        <select id="newStatus" name="new_status" required
                            class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="shipped">Shipped</option>
                            <option value="delivered">Delivered</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="refunded">Refunded</option>
                            <option value="cancel_requested">Cancel Requested</option>
                            <option value="cancel_confirmed">Cancel Confirmed</option>
                            <option value="return_requested">Return Requested</option>
                            <option value="return_confirmed">Return Confirmed</option>
                            <option value="request_rejected">Request Rejected</option>
                        </select>
                    </div>

                    <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-3">
                        <button type="button" onclick="closeStatusModal()"
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 order-2 sm:order-1">
                            Cancel
                        </button>
                        <button type="submit" id="update-status-submit" class="action-btn px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 order-1 sm:order-2 flex items-center justify-center">
                            <span class="btn-text">Update Status</span>
                            <span class="loader hidden"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const updateBtn = document.getElementById('update-status-submit');

                if (updateBtn) {
                    updateBtn.addEventListener('click', function(event) {
                        const form = updateBtn.closest('form');
                        if (form && !form.checkValidity()) {
                            // If form is invalid, stop execution.
                            // The browser will show the validation message.
                            return;
                        }
                        // If the form is valid, add the loading class.
                        updateBtn.classList.add('loading');
                    });
                }
            });
            // Mobile menu functionality
            const mobileMenuBtn = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const mobileOverlay = document.getElementById('sidebar-overlay');

            function toggleMobileMenu() {
                sidebar.classList.toggle('-translate-x-full');
                mobileOverlay.classList.toggle('hidden');
            }

            function closeMobileMenu() {
                sidebar.classList.add('-translate-x-full');
                mobileOverlay.classList.add('hidden');
            }

            mobileMenuBtn.addEventListener('click', toggleMobileMenu);
            mobileOverlay.addEventListener('click', closeMobileMenu);

            // Close mobile menu when clicking on sidebar links
            sidebar.addEventListener('click', function(e) {
                if (e.target.tagName === 'A') {
                    closeMobileMenu();
                }
            });

            // Status modal functions
            function showStatusModal(orderId, orderNumber, currentStatus) {
                document.getElementById('statusOrderId').value = orderId;
                document.getElementById('orderNumber').textContent = '#' + orderNumber;
                document.getElementById('newStatus').value = currentStatus;
                document.getElementById('statusModal').classList.remove('hidden');
            }

            function closeStatusModal() {
                document.getElementById('statusModal').classList.add('hidden');
            }

            // Close modal when clicking outside
            document.getElementById('statusModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeStatusModal();
                }
            });

            // Close mobile menu on window resize if desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 1024) {
                    closeMobileMenu();
                }
            });
        </script>
    </body>

    </html>