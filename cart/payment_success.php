<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
/**
 * Payment Success and Verification Page
 * SECURE VERSION: Properly verifies payment signature and status
 * ADDED: Email confirmation for successful payments
 * ADDED: Loading screen during payment verification
 * UPDATED: Order status flow (pending -> processing after payment)
 */

session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../vendor/autoload.php';

// Include PHPMailer files
require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Enable detailed error reporting for debugging
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Function to send order confirmation email for online payments
function sendOnlineOrderConfirmationEmail($customer_data, $order_number, $total_amount, $payment_id) {
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
        $mail->Subject = "Order Confirmed #" . $order_number . " - " . SITE_NAME;
        
        // Email template for online payment
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Order Confirmation</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 30px 20px; text-align: center; color: white; }
                .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                .content { padding: 30px; }
                .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
                .order-details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .online-notice { background-color: #d1ecf1; border: 1px solid #bee5eb; border-radius: 8px; padding: 20px; margin: 20px 0; }
                .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px; }
                .status-processing { background-color: #dbeafe; color: #1e40af; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                    <p>Order Confirmed!</p>
                </div>
                <div class='content'>
                    <h2>Thank you for your order!</h2>
                    <p>Your payment has been successfully processed.</p>
                    
                    <div class='order-details'>
                        <h3>Order Details:</h3>
                        <p><strong>Order Number:</strong> " . htmlspecialchars($order_number) . "</p>
                        <p><strong>Order Date:</strong> " . date('F j, Y') . "</p>
                        <p><strong>Order Status:</strong> Processing <span class='status-badge status-processing'>Processing</span></p>
                        <p><strong>Payment Method:</strong> Online Payment (Razorpay)</p>
                        <p><strong>Transaction ID:</strong> " . htmlspecialchars($payment_id) . "</p>
                        <p><strong>Total Amount:</strong> ₹" . number_format($total_amount, 2) . "</p>
                    </div>
                    
                    <div class='online-notice'>
                        <h3>✅ Payment Successful</h3>
                        <p>Your payment of ₹" . number_format($total_amount, 2) . " has been received and your order is now being processed.</p>
                    </div>
                    
                    <p>We'll send you another email when your order ships.</p>
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
        $mail->AltBody = "Thank you for your order! Your order #" . $order_number . " has been confirmed. Payment of ₹" . number_format($total_amount, 2) . " received via Razorpay (Transaction ID: " . $payment_id . "). Order status: Processing. We'll send you another email when your order ships.";
        
        // Send email
        $mail->send();
        logMessage("Online payment confirmation email sent to: " . $customer_data['email'], 'INFO');
        return true;
        
    } catch (Exception $e) {
        logMessage("PHPMailer error for online order: " . $mail->ErrorInfo, 'ERROR');
        return false;
    }
}

// Log payment process start
logMessage("Payment success process started. POST data: " . json_encode($_POST), 'INFO');

// 1. Check if we have all the necessary data from Razorpay
if (!empty($_POST['razorpay_payment_id']) && !empty($_POST['razorpay_signature']) && isset($_SESSION['razorpay_order_id'])) {
    
    // Output loading page immediately
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verifying Payment - <?= SITE_NAME ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .loader-container {
                background: white;
                border-radius: 20px;
                padding: 40px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                max-width: 500px;
                width: 100%;
                text-align: center;
                animation: fadeIn 0.5s ease-out;
            }
            
            .loader {
                margin: 30px auto;
                width: 80px;
                height: 80px;
                position: relative;
            }
            
            .loader-circle {
                position: absolute;
                width: 100%;
                height: 100%;
                border: 8px solid transparent;
                border-top-color: #667eea;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            
            .loader-circle:nth-child(2) {
                border-top-color: #764ba2;
                animation: spin 1s linear infinite reverse;
                width: 70%;
                height: 70%;
                top: 15%;
                left: 15%;
            }
            
            .loader-circle:nth-child(3) {
                border-top-color: #10b981;
                animation: spin 1.5s linear infinite;
                width: 50%;
                height: 50%;
                top: 25%;
                left: 25%;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            
            .loader-text {
                margin-top: 20px;
                font-size: 18px;
                font-weight: 600;
                color: #333;
                animation: pulse 2s infinite;
            }
            
            .loader-subtext {
                margin-top: 10px;
                color: #666;
                font-size: 14px;
            }
            
            .loader-progress {
                margin-top: 20px;
                height: 6px;
                background: #e5e7eb;
                border-radius: 3px;
                overflow: hidden;
            }
            
            .loader-progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #667eea, #764ba2);
                width: 0%;
                animation: progress 3s ease-in-out infinite;
                border-radius: 3px;
            }
            
            @keyframes progress {
                0% { width: 0%; }
                50% { width: 70%; }
                100% { width: 100%; }
            }
            
            .loader-steps {
                margin-top: 30px;
                display: flex;
                justify-content: space-between;
                position: relative;
            }
            
            .loader-steps::before {
                content: '';
                position: absolute;
                top: 15px;
                left: 0;
                right: 0;
                height: 2px;
                background: #e5e7eb;
                z-index: 1;
            }
            
            .step {
                position: relative;
                z-index: 2;
                display: flex;
                flex-direction: column;
                align-items: center;
                flex: 1;
            }
            
            .step-icon {
                width: 32px;
                height: 32px;
                background: white;
                border: 2px solid #e5e7eb;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 10px;
                font-size: 14px;
                color: #9ca3af;
                transition: all 0.3s ease;
            }
            
            .step.active .step-icon {
                background: #667eea;
                border-color: #667eea;
                color: white;
            }
            
            .step.completed .step-icon {
                background: #10b981;
                border-color: #10b981;
                color: white;
            }
            
            .step-label {
                font-size: 12px;
                color: #9ca3af;
                text-align: center;
            }
            
            .step.active .step-label {
                color: #667eea;
                font-weight: 600;
            }
            
            .step.completed .step-label {
                color: #10b981;
            }
            
            .security-badge {
                margin-top: 30px;
                padding: 15px;
                background: #f8fafc;
                border-radius: 10px;
                border: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                font-size: 14px;
                color: #4b5563;
            }
            
            .security-icon {
                color: #10b981;
                animation: pulse 2s infinite;
            }
            
            @media (max-width: 480px) {
                .loader-container {
                    padding: 30px 20px;
                }
                
                .loader-text {
                    font-size: 16px;
                }
                
                .loader-steps {
                    flex-wrap: wrap;
                    gap: 20px;
                }
                
                .step {
                    flex: none;
                    width: 50%;
                }
            }
        </style>
    </head>
    <body>
        <div class="loader-container">
            <div class="loader">
                <div class="loader-circle"></div>
                <div class="loader-circle"></div>
                <div class="loader-circle"></div>
            </div>
            
            <div class="loader-text">Verifying Your Payment</div>
            <div class="loader-subtext">Please wait while we confirm your transaction...</div>
            
            <div class="loader-progress">
                <div class="loader-progress-bar"></div>
            </div>
            
            <div class="loader-steps">
                <div class="step completed">
                    <div class="step-icon">✓</div>
                    <div class="step-label">Payment Initiated</div>
                </div>
                <div class="step active">
                    <div class="step-icon">2</div>
                    <div class="step-label">Verifying</div>
                </div>
                <div class="step">
                    <div class="step-icon">3</div>
                    <div class="step-label">Processing Order</div>
                </div>
                <div class="step">
                    <div class="step-icon">4</div>
                    <div class="step-label">Complete</div>
                </div>
            </div>
            
            <div class="security-badge">
                <svg class="security-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                </svg>
                <span>256-bit SSL Secure • Transaction Protected</span>
            </div>
        </div>
        
        <script>
            // Update steps with delays to simulate processing
            setTimeout(() => {
                document.querySelectorAll('.step')[2].classList.add('active');
                document.querySelector('.loader-text').textContent = 'Processing Your Order';
                document.querySelector('.loader-subtext').textContent = 'Updating inventory and preparing your order...';
            }, 2000);
            
            setTimeout(() => {
                document.querySelectorAll('.step')[2].classList.add('completed');
                document.querySelectorAll('.step')[3].classList.add('active');
                document.querySelector('.loader-text').textContent = 'Finalizing Order';
                document.querySelector('.loader-subtext').textContent = 'Sending confirmation and redirecting...';
            }, 4000);
            
            // Prevent back button during processing
            history.pushState(null, null, location.href);
            window.onpopstate = function () {
                history.go(1);
            };
        </script>
    </body>
    </html>
    <?php
    // Flush output to show loader immediately
    ob_flush();
    flush();
    
    // Continue with payment processing
    $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

    try {
        // 2. CRITICAL: Verify the payment signature using Razorpay's utility
        $attributes = [
            'razorpay_order_id' => $_SESSION['razorpay_order_id'],
            'razorpay_payment_id' => $_POST['razorpay_payment_id'],
            'razorpay_signature' => $_POST['razorpay_signature']
        ];
        
        logMessage("Verifying payment signature with attributes: " . json_encode($attributes), 'INFO');
        
        // This is the official Razorpay method to verify signatures
        $api->utility->verifyPaymentSignature($attributes);
        logMessage("Payment signature verified successfully", 'INFO');

        // 3. Fetch the payment details from Razorpay to check status
        $payment = $api->payment->fetch($_POST['razorpay_payment_id']);
        $detailed_payment_method = $payment->method;
        
        logMessage("Payment details - Method: {$detailed_payment_method}, Status: {$payment->status}, Amount: {$payment->amount}, Currency: {$payment->currency}", 'INFO');

        // 4. CRITICAL: Verify payment status is 'captured'
        if ($payment->status !== 'captured') {
            $error_message = "Payment not captured. Current status: {$payment->status}";
            logMessage($error_message, 'ERROR');
            
            // Update order status to failed
            if (isset($_SESSION['checkout_order_id'])) {
                $db->update(
                    'orders',
                    [
                        'payment_status' => 'failed',
                        'status' => 'cancelled',
                        'razorpay_payment_id' => $_POST['razorpay_payment_id'],
                        'payment_method' => $detailed_payment_method,
                        'failure_reason' => $error_message,
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    'id = :id',
                    ['id' => $_SESSION['checkout_order_id']]
                );
                logMessage("Order marked as failed due to uncaptured payment: {$_SESSION['checkout_order_id']}", 'INFO');
            }
            
            throw new Exception($error_message);
        }

        // 5. Additional security: Verify payment amount matches expected amount
        if (isset($_SESSION['payment_amount'])) {
            $expected_amount = $_SESSION['payment_amount'] * 100; // Convert to paise
            if ($payment->amount != $expected_amount) {
                $error_message = "Payment amount mismatch. Expected: {$expected_amount}, Got: {$payment->amount}";
                logMessage($error_message, 'ERROR');
                
                // Update order status to failed
                if (isset($_SESSION['checkout_order_id'])) {
                    $db->update(
                        'orders',
                        [
                            'payment_status' => 'failed',
                            'status' => 'cancelled',
                            'razorpay_payment_id' => $_POST['razorpay_payment_id'],
                            'payment_method' => $detailed_payment_method,
                            'failure_reason' => $error_message,
                            'updated_at' => date('Y-m-d H:i:s')
                        ],
                        'id = :id',
                        ['id' => $_SESSION['checkout_order_id']]
                    );
                }
                
                throw new Exception($error_message);
            }
        }

        // 6. Check if order ID exists in session
        if (!isset($_SESSION['checkout_order_id'])) {
            throw new Exception("Checkout order ID not found in session");
        }
        
        $order_id = $_SESSION['checkout_order_id'];
        logMessage("Processing VERIFIED payment for order ID: {$order_id}", 'INFO');

        // 7. Check if order is already processed to prevent duplicate processing
        $existing_order = $db->fetchRow(
            'SELECT payment_status, order_number, status FROM ' . DB_PREFIX . 'orders WHERE id = :id',
            ['id' => $order_id]
        );
        
        if ($existing_order && $existing_order['payment_status'] === 'completed') {
            logMessage("Order {$order_id} already processed. Redirecting to success.", 'INFO');
            $order_number = $existing_order['order_number'];
            
            // Clear session and redirect
            unset(
                $_SESSION['checkout_order_id'], 
                $_SESSION['razorpay_order_id'], 
                $_SESSION['payment_amount'], 
                $_SESSION['customer_details'],
                $_SESSION['cart']
            );
            
            echo '<script>window.location.href = "' . BASE_URL . '/cart/order-success.php?order=' . urlencode($order_number) . '";</script>';
            exit();
        }

        // 8. All verifications passed - Update order as paid and change status to processing
        $update_result = $db->update(
            'orders',
            [
                'payment_status' => 'completed',
                'status' => 'processing', // Changed from 'pending' to 'processing'
                'razorpay_payment_id' => $_POST['razorpay_payment_id'],
                'payment_method' => $detailed_payment_method,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = :id AND status = :current_status', // Additional safety: only update if current status is pending
            [
                'id' => $order_id,
                'current_status' => 'pending' // Only update if order is still pending
            ]
        );

        if (!$update_result) {
            // Check if order status was not pending (maybe already processing or failed)
            $current_status = $db->fetchRow(
                'SELECT status FROM ' . DB_PREFIX . 'orders WHERE id = :id',
                ['id' => $order_id]
            );
            
            if ($current_status && $current_status['status'] !== 'pending') {
                logMessage("Order {$order_id} already has status: {$current_status['status']}. Skipping status update.", 'WARNING');
                
                // Still update payment details even if status is already processing
                $db->update(
                    'orders',
                    [
                        'payment_status' => 'completed',
                        'razorpay_payment_id' => $_POST['razorpay_payment_id'],
                        'payment_method' => $detailed_payment_method,
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    'id = :id',
                    ['id' => $order_id]
                );
            } else {
                throw new Exception("Failed to update order status in database. No rows affected.");
            }
        } else {
            logMessage("Order status updated from pending to processing. Rows affected: {$update_result}", 'INFO');
        }

        // 9. *** DEDUCT STOCK FROM INVENTORY - ONLY AFTER SUCCESSFUL VERIFICATION ***
        $order_items = $db->fetchAll(
            'SELECT product_id, quantity FROM ' . DB_PREFIX . 'order_items WHERE order_id = :order_id',
            ['order_id' => $order_id]
        );

        if (!empty($order_items)) {
            $items_count = count($order_items);
            logMessage("Processing {$items_count} order items for stock deduction", 'INFO');
            
            foreach ($order_items as $item) {
                // Update stock quantity by subtracting the ordered quantity
                $stmt = $db->execute(
                    'UPDATE ' . DB_PREFIX . 'products 
                    SET stock_quantity = stock_quantity - :deduct_quantity,
                        updated_at = :updated_at
                    WHERE id = :product_id AND stock_quantity >= :min_quantity',
                    [
                        'deduct_quantity' => $item['quantity'],
                        'min_quantity' => $item['quantity'],
                        'product_id' => $item['product_id'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                );
                
                if ($stmt) {
                    $rows_affected = $stmt->rowCount();
                    if ($rows_affected > 0) {
                        logMessage("Stock deducted: Product ID {$item['product_id']}, Quantity: {$item['quantity']}, Order ID: {$order_id}", 'INFO');
                    } else {
                        logMessage("Stock deduction failed - insufficient stock or product not found: Product ID {$item['product_id']}", 'WARNING');
                    }
                } else {
                    logMessage("Database error during stock deduction for Product ID {$item['product_id']}", 'ERROR');
                }
            }
        } else {
            logMessage("No order items found for order ID: {$order_id}", 'WARNING');
        }
        
        // 10. Fetch the order number and total amount for the success page
        $order = $db->fetchRow(
            'SELECT order_number, total_amount FROM ' . DB_PREFIX . 'orders WHERE id = :id', 
            ['id' => $order_id]
        );
        
        if (!$order || empty($order['order_number'])) {
            throw new Exception("Could not fetch order number for order ID: {$order_id}");
        }
        
        $order_number = $order['order_number'];
        $order_total = $order['total_amount'];
        logMessage("SECURE PAYMENT COMPLETED: Order Number: {$order_number}, Amount: {$order_total}, Status: processing", 'INFO');

        // 11. SEND ORDER CONFIRMATION EMAIL FOR SUCCESSFUL ONLINE PAYMENT
        if (isset($_SESSION['customer_details']) && !empty($_SESSION['customer_details']['email'])) {
            $email_sent = sendOnlineOrderConfirmationEmail(
                $_SESSION['customer_details'],
                $order_number,
                $order_total,
                $_POST['razorpay_payment_id']
            );
            
            if ($email_sent) {
                logMessage("Order confirmation email sent successfully for order: {$order_number}", 'INFO');
            } else {
                logMessage("Failed to send order confirmation email for order: {$order_number}", 'WARNING');
            }
        } else {
            logMessage("No customer email found in session for order: {$order_number}", 'WARNING');
        }

        // 12. Clear session variables related to checkout
        unset(
            $_SESSION['checkout_order_id'], 
            $_SESSION['razorpay_order_id'], 
            $_SESSION['payment_amount'], 
            $_SESSION['customer_details'],
            $_SESSION['cart']
        );
        
        // 13. Clear cart from database if user is logged in
        if (isset($_SESSION['user_id'])) {
            $db->delete('cart', 'user_id = :user_id', ['user_id' => $_SESSION['user_id']]);
        } else if (isset($_SESSION['session_id'])) {
            $db->delete('cart', 'session_id = :session_id AND user_id IS NULL', ['session_id' => session_id()]);
        }

        // 14. Redirect to the final order success page
        $success_url = BASE_URL . '/cart/order-success.php?order=' . urlencode($order_number);
        logMessage("Redirecting to success URL: {$success_url}", 'INFO');
        
        // Output JavaScript to redirect after showing loader
        echo '<script>window.location.href = "' . $success_url . '";</script>';
        exit();

    } catch (SignatureVerificationError $e) {
        // Payment signature verification failed - DO NOT PROCESS PAYMENT
        $error_message = 'Razorpay Signature Verification Failed: ' . $e->getMessage();
        logMessage($error_message, 'ERROR');
        
        // Update order status to failed due to security issue
        if (isset($_SESSION['checkout_order_id'])) {
            $db->update(
                'orders',
                [
                    'payment_status' => 'failed',
                    'status' => 'cancelled',
                    'failure_reason' => 'Signature verification failed',
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $_SESSION['checkout_order_id']]
            );
        }
        
        // Store error in session for display on failure page
        $_SESSION['payment_error'] = $error_message;
        
        // Output JavaScript to redirect to failure page
        echo '<script>window.location.href = "' . BASE_URL . '/cart/payment-failed.php?error=signature_verification_failed";</script>';
        exit();
        
    } catch (Exception $e) {
        // General payment processing error - DO NOT PROCESS PAYMENT
        $error_message = 'Payment Processing Error: ' . $e->getMessage();
        logMessage($error_message, 'ERROR');
        
        // Update order status to failed
        if (isset($_SESSION['checkout_order_id'])) {
            $db->update(
                'orders',
                [
                    'payment_status' => 'failed',
                    'status' => 'cancelled',
                    'failure_reason' => $e->getMessage(),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $_SESSION['checkout_order_id']]
            );
        }
        
        // Store error in session for display on failure page
        $_SESSION['payment_error'] = $error_message;
        
        // Output JavaScript to redirect to failure page
        echo '<script>window.location.href = "' . BASE_URL . '/cart/payment-failed.php?error=processing_error&message=' . urlencode($e->getMessage()) . '";</script>';
        exit();
    }
} else {
    // Missing required payment data
    $missing_data = [];
    if (empty($_POST['razorpay_payment_id'])) $missing_data[] = 'razorpay_payment_id';
    if (empty($_POST['razorpay_signature'])) $missing_data[] = 'razorpay_signature';
    if (!isset($_SESSION['razorpay_order_id'])) $missing_data[] = 'razorpay_order_id (session)';
    
    $error_message = 'Missing required payment data: ' . implode(', ', $missing_data);
    logMessage($error_message, 'ERROR');
    
    $_SESSION['payment_error'] = $error_message;
    
    header('Location: ' . BASE_URL . '/cart/payment-failed.php?error=missing_data');
    exit();
}
?>