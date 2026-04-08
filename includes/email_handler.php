<?php
/**
 * Email Handler using PHPMailer
 * Secure and reliable email sending functionality
 */

// Include PHPMailer files
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send email using PHPMailer with Gmail SMTP
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $message HTML email content
 * @param string $from_name Sender name (optional)
 * @param string $reply_to_email Reply-to email (optional)
 * @param string $reply_to_name Reply-to name (optional)
 * @param array $attachments Array of file paths to attach (optional)
 * @return bool True if email sent successfully, false otherwise
 */
function sendSecureEmail($to, $subject, $message, $from_name = null, $reply_to_email = null, $reply_to_name = null, $attachments = []) {
    $mail = new PHPMailer(true);
    
    try {
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
        $mail->setFrom(SMTP_USERNAME, $from_name ?: SITE_NAME);
        $mail->addAddress($to);
        
        // Add reply-to if provided
        if ($reply_to_email && $reply_to_name) {
            $mail->addReplyTo($reply_to_email, $reply_to_name);
        } else {
            $mail->addReplyTo(SUPPORT_EMAIL, SITE_NAME);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        
        // Alternative plain text version
        $mail->AltBody = strip_tags($message);
        
        // Add attachments
        foreach ($attachments as $attachment) {
            if (file_exists($attachment)) {
                $mail->addAttachment($attachment);
            }
        }
        
        // Send email
        $mail->send();
        
        logMessage("Email sent successfully via PHPMailer to: $to", 'INFO');
        return true;
        
    } catch (Exception $e) {
        logMessage("PHPMailer error: " . $mail->ErrorInfo, 'ERROR');
        return false;
    }
}

/**
 * Send password reset email
 * 
 * @param array $user User data array with id, first_name, email
 * @param string $reset_token Password reset token
 * @return bool True if email sent successfully, false otherwise
 */
function sendPasswordResetEmail($user, $reset_token) {
    $to = $user['email'];
    $subject = "Password Reset Request - " . SITE_NAME;
    
    // Create secure reset link
    $reset_link = SITE_URL . "/auth/reset_password.php?token=" . urlencode($reset_token);
    
    // Enhanced email template
    $message = createPasswordResetEmailTemplate($user, $reset_link);
    
    return sendSecureEmail($to, $subject, $message);
}

/**
 * Create password reset email template
 * 
 * @param array $user User data
 * @param string $reset_link Password reset link
 * @return string HTML email content
 */
function createPasswordResetEmailTemplate($user, $reset_link) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset Request</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .button { display: inline-block; padding: 15px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 20px 0; }
            .security-notice { background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px; margin: 20px 0; }
            .security-notice h3 { color: #856404; margin-top: 0; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .link-box { background-color: #f8f9fa; padding: 15px; border-radius: 8px; word-break: break-all; font-family: monospace; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(SITE_NAME) . "</h1>
                <p>Password Reset Request</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($user['first_name']) . ",</h2>
                <p>We received a request to reset your password for your " . htmlspecialchars(SITE_NAME) . " account.</p>
                <p>Click the button below to reset your password securely:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . htmlspecialchars($reset_link) . "' class='button'>Reset My Password</a>
                </div>
                
                <div class='security-notice'>
                    <h3>🔒 Security Notice</h3>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>This link expires in 1 hour</strong> for your security</li>
                        <li>If you didn't request this reset, please ignore this email</li>
                        <li>Never share this link with anyone</li>
                        <li>Our team will never ask for your password via email</li>
                    </ul>
                </div>
                
                <p><strong>Having trouble with the button?</strong> Copy and paste this link into your browser:</p>
                <div class='link-box'>" . htmlspecialchars($reset_link) . "</div>
                
                <p style='margin-top: 30px; font-size: 14px; color: #666;'>
                    <strong>Request Details:</strong><br>
                    Time: " . date('F j, Y \a\t g:i A T') . "<br>
                    IP Address: " . htmlspecialchars($_SERVER['REMOTE_ADDR']) . "
                </p>
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
}

/**
 * Send registration welcome email
 * 
 * @param array $user User data
 * @return bool True if email sent successfully
 */
function sendWelcomeEmail($user) {
    $to = $user['email'];
    $subject = "Welcome to " . SITE_NAME . "!";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Welcome to " . SITE_NAME . "</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 8px; }
            .content { padding: 20px 0; }
            .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to " . htmlspecialchars(SITE_NAME) . "!</h1>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($user['first_name']) . ",</h2>
                <p>Thank you for joining " . htmlspecialchars(SITE_NAME) . "! We're excited to have you as part of our fashion community.</p>
                <p>You can now:</p>
                <ul>
                    <li>Browse our latest collection</li>
                    <li>Save your favorite items</li>
                    <li>Enjoy faster checkout</li>
                    <li>Track your orders</li>
                </ul>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . SITE_URL . "' class='button'>Start Shopping</a>
                </div>
                <p>If you have any questions, feel free to contact us at " . SUPPORT_EMAIL . "</p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendSecureEmail($to, $subject, $message);
}

/**
 * Send order confirmation email
 * 
 * @param array $user User data
 * @param array $order Order data
 * @return bool True if email sent successfully
 */
function sendOrderConfirmationEmail($user, $order) {
    $to = $user['email'];
    $subject = "Order Confirmation #" . $order['order_number'] . " - " . SITE_NAME;
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Order Confirmation</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px; }
            .content { padding: 20px 0; }
            .order-details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Order Confirmed!</h1>
                <p>Order #" . htmlspecialchars($order['order_number']) . "</p>
            </div>
            <div class='content'>
                <h2>Thank you, " . htmlspecialchars($user['first_name']) . "!</h2>
                <p>Your order has been received and is being processed.</p>
                
                <div class='order-details'>
                    <h3>Order Details:</h3>
                    <p><strong>Order Number:</strong> " . htmlspecialchars($order['order_number']) . "</p>
                    <p><strong>Order Date:</strong> " . date('F j, Y') . "</p>
                    <p><strong>Total:</strong> " . formatCurrency($order['total']) . "</p>
                </div>
                
                <p>We'll send you another email when your order ships.</p>
                <p>Thank you for shopping with " . htmlspecialchars(SITE_NAME) . "!</p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendSecureEmail($to, $subject, $message);
}
?>