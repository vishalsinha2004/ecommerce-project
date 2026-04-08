<?php
/**
 * Contact Page - ElegantDresses (Using Your Function-Based Email Handler)
 * * Features:
 * - Secure contact form with CSRF protection
 * - Email validation and sanitization
 * - Rate limiting using session/file-based tracking
 * - Uses your existing email functions
 * - Responsive mobile-first design
 * - Matches site design philosophy
 * - Full error handling and logging
 */

// Start session and include core files
session_start();
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/email_handler.php'; // ADDED: Include email handler

// Initialize variables
$errors = [];
$success_message = '';
$form_data = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'subject' => '',
    'message' => ''
];

// Rate limiting using session and file-based tracking
function checkRateLimit($ip) {
    $rate_limit_file = LOGS_PATH . '/contact_rate_limit.json';
    $current_time = time();
    $time_window = 3600; // 1 hour
    $max_attempts = 5;
    
    // Initialize rate limit data
    $rate_data = [];
    if (file_exists($rate_limit_file)) {
        $file_content = file_get_contents($rate_limit_file);
        $rate_data = json_decode($file_content, true) ?: [];
    }
    
    // Clean old entries
    foreach ($rate_data as $stored_ip => $attempts) {
        $rate_data[$stored_ip] = array_filter($attempts, function($timestamp) use ($current_time, $time_window) {
            return ($current_time - $timestamp) < $time_window;
        });
        
        if (empty($rate_data[$stored_ip])) {
            unset($rate_data[$stored_ip]);
        }
    }
    
    // Check current IP attempts
    $current_attempts = isset($rate_data[$ip]) ? count($rate_data[$ip]) : 0;
    
    if ($current_attempts >= $max_attempts) {
        return false;
    }
    
    // Add current attempt
    if (!isset($rate_data[$ip])) {
        $rate_data[$ip] = [];
    }
    $rate_data[$ip][] = $current_time;
    
    // Save updated rate data
    file_put_contents($rate_limit_file, json_encode($rate_data), LOCK_EX);
    
    return true;
}

// Log contact form submission
function logContactSubmission($data, $ip, $user_agent) {
    $log_file = LOGS_PATH . '/contact_submissions.log';
    $timestamp = date('Y-m-d H:i:s');
    $submission_id = 'CONTACT_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 6);
    
    $log_entry = [
        'id' => $submission_id,
        'timestamp' => $timestamp,
        'name' => $data['name'],
        'email' => $data['email'],
        'phone' => $data['phone'],
        'subject' => $data['subject'],
        'message' => $data['message'],
        'ip_address' => $ip,
        'user_agent' => $user_agent
    ];
    
    $log_line = json_encode($log_entry) . "\n";
    file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    
    return $submission_id;
}

// Send contact emails using your existing functions
function sendContactEmails($data, $submission_id) {
    try {
        // Admin notification email (HTML format)
        $admin_subject = "[" . SITE_NAME . "] New Contact Form Submission - #" . $submission_id;
        
        $admin_html_content = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f8f9fa;'>
            <div style='background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <h1 style='color: #e91e63; margin: 0; font-size: 24px;'>" . SITE_NAME . "</h1>
                    <p style='color: #666; margin: 10px 0 0 0;'>New Contact Form Submission</p>
                </div>
                
                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
                    <h2 style='color: #333; margin: 0 0 15px 0; font-size: 18px;'>Submission Details</h2>
                    <p style='margin: 5px 0; color: #666;'><strong>Submission ID:</strong> " . htmlspecialchars($submission_id) . "</p>
                    <p style='margin: 5px 0; color: #666;'><strong>Date/Time:</strong> " . date('F j, Y \a\t g:i A T') . "</p>
                </div>
                
                <div style='margin-bottom: 25px;'>
                    <h3 style='color: #333; margin: 0 0 15px 0; font-size: 16px; border-bottom: 2px solid #e91e63; padding-bottom: 5px;'>Customer Information</h3>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; width: 120px; color: #666; font-weight: bold;'>Name:</td>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #333;'>" . htmlspecialchars($data['name']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #666; font-weight: bold;'>Email:</td>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #333;'>" . htmlspecialchars($data['email']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #666; font-weight: bold;'>Phone:</td>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #333;'>" . ($data['phone'] ? htmlspecialchars($data['phone']) : 'Not provided') . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #666; font-weight: bold;'>Subject:</td>
                            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #333;'>" . htmlspecialchars($data['subject']) . "</td>
                        </tr>
                    </table>
                </div>
                
                <div style='margin-bottom: 25px;'>
                    <h3 style='color: #333; margin: 0 0 15px 0; font-size: 16px; border-bottom: 2px solid #e91e63; padding-bottom: 5px;'>Message</h3>
                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #e91e63;'>
                        <p style='margin: 0; color: #333; line-height: 1.6; white-space: pre-wrap;'>" . htmlspecialchars($data['message']) . "</p>
                    </div>
                </div>
                
                <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;'>
                    <p style='color: #666; margin: 0; font-size: 12px;'>
                        This email was automatically generated by the " . SITE_NAME . " contact form system.
                    </p>
                </div>
            </div>
        </div>";
        
        // Send admin notification using sendSecureEmail function
        $admin_result = sendSecureEmail(
            SUPPORT_EMAIL,
            $admin_subject,
            $admin_html_content,
            COMPANY_NAME . " Admin",
            $data['email'], // Reply-to email
            $data['name']   // Reply-to name
        );
        
        if (!$admin_result) {
            logMessage("Failed to send admin notification email for submission: " . $submission_id, 'ERROR');
            return false;
        }
        
        // Customer auto-reply email (HTML format)
        $customer_subject = "Thank you for contacting " . SITE_NAME . " - #" . $submission_id;
        
        $customer_html_content = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f8f9fa;'>
            <div style='background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <h1 style='color: #e91e63; margin: 0; font-size: 28px;'>" . SITE_NAME . "</h1>
                    <p style='color: #666; margin: 10px 0 0 0; font-size: 16px;'>Thank you for reaching out!</p>
                </div>
                
                <div style='margin-bottom: 25px;'>
                    <h2 style='color: #333; margin: 0 0 15px 0; font-size: 20px;'>Hello " . htmlspecialchars($data['name']) . ",</h2>
                    <p style='color: #666; line-height: 1.6; margin-bottom: 20px; font-size: 16px;'>
                        Thank you for contacting us. We have received your message and our team will respond within <strong>7 working days</strong>.
                    </p>
                </div>
                
                <div style='background: #f8f9fa; padding: 25px; border-radius: 8px; margin-bottom: 25px; border-left: 4px solid #e91e63;'>
                    <h3 style='color: #333; margin: 0 0 15px 0; font-size: 16px;'>Your Message Summary</h3>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 8px 0; color: #666; font-weight: bold; width: 100px;'>Reference ID:</td>
                            <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($submission_id) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #666; font-weight: bold;'>Subject:</td>
                            <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($data['subject']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #666; font-weight: bold; vertical-align: top;'>Message:</td>
                            <td style='padding: 8px 0; color: #333; line-height: 1.5;'>" . nl2br(htmlspecialchars($data['message'])) . "</td>
                        </tr>
                    </table>
                </div>
                
                <div style='text-align: center; margin-bottom: 25px;'>
                    <h3 style='color: #333; margin: 0 0 15px 0; font-size: 16px;'>Explore Our Collection</h3>
                    <a href='" . BASE_URL . "/products/product_list.php' style='display: inline-block; background: linear-gradient(135deg, #e91e63, #9c27b0); color: white; text-decoration: none; padding: 12px 30px; border-radius: 25px; font-weight: bold; margin: 0 10px 10px 0;'>Shop Dresses</a>
                    <a href='" . BASE_URL . "/about.php' style='display: inline-block; background: #f8f9fa; color: #333; text-decoration: none; padding: 12px 30px; border-radius: 25px; font-weight: bold; border: 2px solid #e91e63; margin: 0 0 10px 0;'>About Us</a>
                </div>
                
                <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;'>
                    <p style='color: #333; margin: 0 0 10px 0; font-size: 16px; font-weight: bold;'>Best regards,</p>
                    <p style='color: #e91e63; margin: 0 0 15px 0; font-size: 18px; font-weight: bold;'>" . COMPANY_NAME . " Team</p>
                    
                    <div style='margin: 20px 0;'>
                        <p style='color: #666; margin: 0; font-size: 12px;'>
                            This is an automated response. Please do not reply to this email.<br>
                            Visit us online: <a href='" . BASE_URL . "' style='color: #e91e63; text-decoration: none;'>" . BASE_URL . "</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>";
        
        // Send customer auto-reply using sendSecureEmail function
        $customer_result = sendSecureEmail(
            $data['email'],
            $customer_subject,
            $customer_html_content,
            COMPANY_NAME,
            SUPPORT_EMAIL,
            COMPANY_NAME
        );
        
        if (!$customer_result) {
            logMessage("Failed to send customer auto-reply email for submission: " . $submission_id, 'ERROR');
            // Don't return false for customer email failure - admin notification is more critical
        }
        
        return true;
        
    } catch (Exception $e) {
        logMessage("Contact email sending error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    } else {
        // Rate limiting
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!checkRateLimit($user_ip)) {
            $errors[] = 'Too many submissions. Please wait before trying again.';
        } else {
            // Sanitize and validate input
            $form_data['name'] = trim($_POST['name'] ?? '');
            $form_data['email'] = trim($_POST['email'] ?? '');
            $form_data['phone'] = trim($_POST['phone'] ?? '');
            $form_data['subject'] = trim($_POST['subject'] ?? '');
            $form_data['message'] = trim($_POST['message'] ?? '');
            
            // Validation rules
            if (empty($form_data['name'])) {
                $errors[] = 'Name is required.';
            } elseif (strlen($form_data['name']) < 2) {
                $errors[] = 'Name must be at least 2 characters long.';
            } elseif (strlen($form_data['name']) > 100) {
                $errors[] = 'Name cannot exceed 100 characters.';
            } elseif (!preg_match('/^[a-zA-Z\s\'-\.]+$/u', $form_data['name'])) {
                $errors[] = 'Name contains invalid characters.';
            }
            
            if (empty($form_data['email'])) {
                $errors[] = 'Email address is required.';
            } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (strlen($form_data['email']) > 255) {
                $errors[] = 'Email address is too long.';
            }
            
            if (!empty($form_data['phone']) && !preg_match('/^[+]?[0-9\s\-\(\)]{10,20}$/', $form_data['phone'])) {
                $errors[] = 'Please enter a valid phone number.';
            }
            
            if (empty($form_data['subject'])) {
                $errors[] = 'Subject is required.';
            } elseif (strlen($form_data['subject']) < 5) {
                $errors[] = 'Subject must be at least 5 characters long.';
            } elseif (strlen($form_data['subject']) > 200) {
                $errors[] = 'Subject cannot exceed 200 characters.';
            }
            
            if (empty($form_data['message'])) {
                $errors[] = 'Message is required.';
            } elseif (strlen($form_data['message']) < 10) {
                $errors[] = 'Message must be at least 10 characters long.';
            } elseif (strlen($form_data['message']) > 2000) {
                $errors[] = 'Message cannot exceed 2000 characters.';
            }
            
            // Spam protection - check for suspicious patterns
            $suspicious_patterns = [
                '/\b(viagra|cialis|casino|lottery|winner|prize|congratulations)\b/i',
                '/\b(click here|buy now|limited time|act now|free money)\b/i',
                '/(http:\/\/|https:\/\/|www\.)[^\s]{5,}/i' // Multiple URLs
            ];
            
            $combined_text = $form_data['name'] . ' ' . $form_data['subject'] . ' ' . $form_data['message'];
            foreach ($suspicious_patterns as $pattern) {
                if (preg_match($pattern, $combined_text)) {
                    $errors[] = 'Your message appears to contain spam content. Please revise and try again.';
                    break;
                }
            }
            
            // If no errors, process the submission
            if (empty($errors)) {
                try {
                    // Log the submission
                    $submission_id = logContactSubmission($form_data, $user_ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
                    
                    // Send emails using your function-based approach
                    $email_success = sendContactEmails($form_data, $submission_id);
                    
                    if ($email_success) {
                        $success_message = 'Thank you for your message! We\'ll get back to you within 24 hours. Your reference ID is: ' . $submission_id;
                        
                        // Clear form data on success
                        $form_data = [
                            'name' => '',
                            'email' => '',
                            'phone' => '',
                            'subject' => '',
                            'message' => ''
                        ];
                        
                        logMessage("Contact form submitted successfully - ID: $submission_id", 'INFO');
                    } else {
                        $errors[] = 'Sorry, there was an error sending your message. Please try again or contact us directly at ' . SUPPORT_EMAIL;
                        logMessage("Contact form email sending failed - ID: $submission_id", 'ERROR');
                    }
                } catch (Exception $e) {
                    $errors[] = 'Sorry, there was an error processing your message. Please try again later.';
                    logMessage("Contact form error: " . $e->getMessage(), 'ERROR');
                }
            }
        }
    }
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Page meta information
$page_title = 'Contact Us - ' . SITE_NAME;
$page_description = 'Get in touch with ' . SITE_NAME . '. We\'re here to help with any questions about our elegant women\'s fashion collection.';

// Include header
include 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css?family=Nunito:700,800,400&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Nunito', 'Inter', sans-serif;
            background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
            min-height: 100vh;
            color: #4a4a4a;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            border-radius: 1.25rem;
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 8px 32px 0 rgba(200, 175, 220, 0.1);
        }
        /* Slimmer Input Style */
        .input-style {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background-color: #f9fafb; /* gray-50 */
            border: 1px solid #f3f4f6; /* gray-100 */
            transition: all 0.2s ease-in-out;
            color: #1f2937;
        }
        .input-style:focus {
            background-color: #ffffff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(251, 207, 232, 0.5); /* ring-pink-200 */
            border-color: transparent;
        }
        /* Slimmer Button Gradient */
        .btn-primary {
            background: linear-gradient(90deg, #d86990 0%, #e995b5 100%);
            color: #fff;
            transition: all 0.3s cubic-bezier(.4, 0, .2, 1);
            box-shadow: 0 2px 10px 0 #e995b544;
            border: none;
        }
        .btn-primary:hover {
            filter: brightness(1.05);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px 0 #e995b566;
        }
    </style>
</head>
<body class="font-sans antialiased">

<div class="relative pt-16 pb-12 text-center">
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-4xl md:text-5xl font-bold text-gray-800 mb-4 tracking-tight">
            Get in <span class="text-[#d86990]">Touch</span>
        </h1>
        <p class="text-lg text-gray-600 max-w-2xl mx-auto leading-relaxed">
            We'd love to hear from you. Send us a message and we'll respond as soon as possible.
        </p>
        <div class="w-24 h-1 bg-gradient-to-r from-pink-200 to-purple-200 mx-auto mt-6 rounded-full"></div>
    </div>
</div>

<div class="pb-24">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
            
            <div class="order-2 lg:order-1">
                <div class="glass-card p-8 md:p-10">
                    <div class="mb-8">
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Send us a Message</h2>
                        <p class="text-gray-500 text-sm">
                            Fill out the form below and we'll get back to you within 24 hours.
                        </p>
                    </div>
                    
                    <?php if (!empty($success_message)): ?>
                        <div class="mb-6 p-4 bg-green-50 border border-green-100 rounded-xl text-sm">
                            <div class="flex items-start">
                                <svg class="h-5 w-5 text-green-500 flex-shrink-0" fill="none" viewBox="0 0 20 20"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>
                                <p class="ml-3 text-green-700 font-medium"><?= htmlspecialchars($success_message) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($errors)): ?>
                        <div class="mb-6 p-4 bg-red-50 border border-red-100 rounded-xl text-sm">
                            <div class="flex items-start">
                                <svg class="h-5 w-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 20 20"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>
                                <div class="ml-3">
                                    <h3 class="text-red-800 font-medium mb-1">Please fix the following issues:</h3>
                                    <ul class="text-red-600 list-disc list-inside space-y-1">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= htmlspecialchars($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" class="space-y-5" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label for="name" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Full Name <span class="text-red-400">*</span></label>
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($form_data['name']) ?>" required maxlength="100" class="input-style" placeholder="Your full name">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Email Address <span class="text-red-400">*</span></label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($form_data['email']) ?>" required maxlength="255" class="input-style" placeholder="your@email.com">
                            </div>
                        </div>
                        
                        <div>
                            <label for="phone" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Phone Number <span class="text-gray-400 font-normal normal-case">(Optional)</span></label>
                            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($form_data['phone']) ?>" maxlength="20" class="input-style" placeholder="+1 (555) 123-4567">
                        </div>
                        
                        <div>
                            <label for="subject" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Subject <span class="text-red-400">*</span></label>
                            <input type="text" id="subject" name="subject" value="<?= htmlspecialchars($form_data['subject']) ?>" required maxlength="200" class="input-style" placeholder="What can we help you with?">
                        </div>
                        
                        <div>
                            <label for="message" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Message <span class="text-red-400">*</span></label>
                            <textarea id="message" name="message" rows="5" required maxlength="2000" class="input-style resize-none" placeholder="Tell us more about your inquiry..."><?= htmlspecialchars($form_data['message']) ?></textarea>
                            <div class="text-right mt-1">
                                <span class="text-xs text-gray-400" id="char-count">0/2000</span>
                            </div>
                        </div>
                        
                        <div class="pt-2">
                            <button type="submit" class="w-full btn-primary py-3 rounded-xl font-bold text-sm tracking-wide shadow-md">
                                Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="order-1 lg:order-2 lg:pl-8 space-y-8">
                
                <div class="mb-4">
                    <h2 class="text-2xl font-bold text-gray-800 mb-3">Contact Information</h2>
                    <p class="text-gray-600">
                        Reach out to us through any of these channels. We're here to help make your shopping experience exceptional.
                    </p>
                </div>
                
                <div class="space-y-5">
                    <div class="glass-card p-5 hover:shadow-lg transition-all duration-300">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="w-10 h-10 bg-pink-50 rounded-lg flex items-center justify-center text-pink-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 7.89c.39.39 1.02.39 1.41 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-base font-bold text-gray-800 mb-1">Email Support</h3>
                                <p class="text-sm text-gray-500 mb-2">Get help with orders, returns, or general inquiries</p>
                                <a href="mailto:<?= SUPPORT_EMAIL ?>" class="text-[#d86990] hover:underline font-semibold text-sm">
                                    <?= SUPPORT_EMAIL ?>
                                </a>
                            </div>
                        </div>
                    </div>
                                                                            
                    <div class="glass-card p-5 hover:shadow-lg transition-all duration-300">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="w-10 h-10 bg-pink-50 rounded-lg flex items-center justify-center text-pink-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4V2C7 1.45 7.45 1 8 1H16C16.55 1 17 1.45 17 2V4H20C20.55 4 21 4.45 21 5S20.55 6 20 6H4C3.45 6 3 5.55 3 5S3.45 4 4 4H7ZM19 8H5C4.45 8 4 8.45 4 9V19C4 20.1 4.9 21 6 21H18C19.1 21 20 20.1 20 19V9C20 8.45 19.55 8 19 8Z"/></svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-base font-bold text-gray-800 mb-1">Follow Us</h3>
                                <p class="text-sm text-gray-500 mb-3">Stay updated with our latest collections</p>
                                <div class="flex space-x-3">
                                    <a href="<?= FACEBOOK_URL ?>" class="text-gray-400 hover:text-blue-600 transition-colors"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg></a>
                                    <a href="<?= INSTAGRAM_URL ?>" class="text-gray-400 hover:text-pink-600 transition-colors"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 6.618 5.367 11.986 11.988 11.986s11.987-5.368 11.987-11.986C24.014 5.367 18.635.001 12.017.001zM8.449 16.988c-1.297 0-2.448-.49-3.323-1.297C4.198 14.895 3.708 13.744 3.708 12.447s.49-2.448 1.297-3.323C5.902 8.248 7.053 7.758 8.35 7.758s2.448.49 3.323 1.297c.875.875 1.365 2.026 1.365 3.323s-.49 2.448-1.297 3.323c-.875.875-2.026 1.365-3.323 1.365z"/></svg></a>
                                    <a href="<?= TWITTER_URL ?>" class="text-gray-400 hover:text-blue-400 transition-colors"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="glass-card p-6 border-pink-100/50">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Links</h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <a href="<?= BASE_URL ?>/products/product_list.php" class="text-gray-600 hover:text-[#d86990] flex items-center transition">
                            <span class="w-1.5 h-1.5 rounded-full bg-pink-300 mr-2"></span> Shop Dresses
                        </a>
                        <a href="<?= BASE_URL ?>/about.php" class="text-gray-600 hover:text-[#d86990] flex items-center transition">
                            <span class="w-1.5 h-1.5 rounded-full bg-pink-300 mr-2"></span> About Us
                        </a>
                        <a href="#" class="text-gray-600 hover:text-[#d86990] flex items-center transition">
                            <span class="w-1.5 h-1.5 rounded-full bg-pink-300 mr-2"></span> Size Guide
                        </a>
                        <a href="#" class="text-gray-600 hover:text-[#d86990] flex items-center transition">
                            <span class="w-1.5 h-1.5 rounded-full bg-pink-300 mr-2"></span> Return Policy
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="py-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Frequently Asked Questions</h2>
            <p class="text-gray-500">Quick answers to common questions</p>
        </div>
        
        <div class="space-y-4">
            <div class="glass-card p-6">
                <h3 class="text-base font-bold text-gray-800 mb-2">How long does shipping take?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">Standard shipping takes 3-5 business days, while express shipping takes 1-2 business days. International orders may take 7-14 business days.</p>
            </div>
            
            <div class="glass-card p-6">
                <h3 class="text-base font-bold text-gray-800 mb-2">What is your return policy?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">We offer paid/free returns within 7 days of purchase. Items must be unworn, with tags attached, and in original packaging.</p>
            </div>
            
            <div class="glass-card p-6">
                <h3 class="text-base font-bold text-gray-800 mb-2">How can I track my order?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">You can track your order status in your account dashboard under "My Orders".</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Character counter
    const messageTextarea = document.getElementById('message');
    const charCount = document.getElementById('char-count');
    
    if (messageTextarea && charCount) {
        function updateCharCount() {
            const current = messageTextarea.value.length;
            const max = 2000;
            charCount.textContent = `${current}/${max}`;
            
            if (current > max * 0.9) {
                charCount.classList.add('text-red-500');
                charCount.classList.remove('text-gray-400');
            } else {
                charCount.classList.add('text-gray-400');
                charCount.classList.remove('text-red-500');
            }
        }
        
        messageTextarea.addEventListener('input', updateCharCount);
        messageTextarea.addEventListener('paste', () => setTimeout(updateCharCount, 10));
        updateCharCount();
    }
    
    // Form validation
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            const subject = document.getElementById('subject').value.trim();
            const message = document.getElementById('message').value.trim();
            
            let errors = [];
            
            if (!name || name.length < 2) errors.push('Please enter a valid name (at least 2 characters).');
            if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) errors.push('Please enter a valid email address.');
            if (!subject || subject.length < 5) errors.push('Please enter a subject (at least 5 characters).');
            if (!message || message.length < 10) errors.push('Please enter a message (at least 10 characters).');
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following issues:\n\n• ' + errors.join('\n• '));
                return false;
            }
            
            // Show loading state with lighter spinner
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `
                    <span class="flex items-center justify-center">
                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Sending...
                    </span>
                `;
            }
        });
    }
    
    // Auto-hide messages
    const messages = document.querySelectorAll('.bg-green-50, .bg-red-50');
    messages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.5s ease-out';
            message.style.opacity = '0';
            setTimeout(() => { message.remove(); }, 500);
        }, 10000);
    });
});
</script>

<?php
// Include footer
include 'includes/footer.php';
?>
</body>
</html>