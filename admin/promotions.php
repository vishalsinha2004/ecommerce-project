<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
// Admin authentication - same as dashboard.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/email_handler.php';

// Basic admin check
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

// Function to create promotion email template
function createPromotionEmailTemplate($name, $content, $link_url, $image_url = null) {
    $site_name = htmlspecialchars(SITE_NAME);
    $support_email = htmlspecialchars(SUPPORT_EMAIL);
    $company_name = htmlspecialchars(COMPANY_NAME);
    $company_address = htmlspecialchars(COMPANY_ADDRESS);
    
    // Start building the email HTML
    $email_html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>New Promotion - $site_name</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background-color: #f8f9fa; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 20px; text-align: center; color: white; }
            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
            .content { padding: 30px; }
            .footer { background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; }
            .promo-button { display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; font-weight: 600; }
            .banner-image { max-width: 100%; height: auto; border-radius: 8px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>$site_name</h1>
                <p>Special Promotion</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($name) . ",</h2>
                <p>We have an exciting offer just for you!</p>";
    
    // Add banner image if provided
    if ($image_url) {
        $email_html .= "<div style='text-align: center;'><img src='" . IMAGES_URL . "/" . htmlspecialchars($image_url) . "' alt='Promotion Banner' class='banner-image'></div>";
    }
    
    // Add promotion content and button
    $email_html .= "
                <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    " . nl2br(htmlspecialchars($content)) . "
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . htmlspecialchars($link_url) . "' class='promo-button'>Check Out This Offer</a>
                </div>
                
                <p>Don't miss this limited-time opportunity! This offer won't last long.</p>
                
                <p>If you have any questions, please contact our support team at $support_email</p>
                
                <p>Happy shopping!<br>The $site_name Team</p>
            </div>
            <div class='footer'>
                <p>This email was sent by $site_name.<br>
                $company_name | $company_address</p>
            </div>
        </div>
    </body>
    </html>";
    
    return $email_html;
}

// AJAX endpoint for sending emails with progress
if (isset($_GET['send_emails']) && $_GET['send_emails'] === '1') {
    header('Content-Type: application/json');
    
    $subject = $_POST['subject'] ?? '';
    $content = $_POST['content'] ?? '';
    $link_url = $_POST['link_url'] ?? '';
    $image_url = $_POST['image_url'] ?? null;
    
    try {
        // Fetch all active user emails
        $users = $db->fetchAll("SELECT email, first_name FROM " . DB_PREFIX . "users WHERE status = 'active'");
        $total_users = count($users);
        $sent_count = 0;
        $errors = [];
        
        foreach ($users as $index => $user) {
            try {
                $to = $user['email'];
                $name = $user['first_name'];
                
                // Create personalized email content
                $emailContent = createPromotionEmailTemplate($name, $content, $link_url, $image_url);
                
                // Call email handler function
                sendSecureEmail($to, $subject, $emailContent);
                $sent_count++;
                
                // Calculate progress
                $progress = round((($index + 1) / $total_users) * 100);
                
                // Send progress update
                echo json_encode([
                    'progress' => $progress,
                    'current' => $index + 1,
                    'total' => $total_users,
                    'email' => $to,
                    'status' => 'success'
                ]) . "\n";
                
                // Flush output
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                
                // Small delay to show progress (remove in production if needed)
                usleep(100000); // 0.1 second
                
            } catch (Exception $e) {
                $errors[] = "Failed to send to " . $user['email'] . ": " . $e->getMessage();
            }
        }
        
        // Final response
        echo json_encode([
            'progress' => 100,
            'complete' => true,
            'sent_count' => $sent_count,
            'total' => $total_users,
            'errors' => $errors
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    
    exit;
}

// Function to send promotion activation emails to all users
function sendPromotionEmailToUsers($subject, $content, $link_url, $image_url = null) {
    global $db;
    
    // Fetch all active user emails
    $users = $db->fetchAll("SELECT email, first_name FROM " . DB_PREFIX . "users WHERE status = 'active'");
    
    foreach ($users as $user) {
        $to = $user['email'];
        $name = $user['first_name'];
        
        // Create personalized email content
        $emailContent = createPromotionEmailTemplate($name, $content, $link_url, $image_url);
        
        // Call email handler function
        sendSecureEmail($to, $subject, $emailContent);
    }
}

// Handle form actions
$action = $_POST['action'] ?? null;
$error = '';
$success = '';

// Image upload settings
$upload_dir = ASSETS_PATH . '/images/';
$web_images_dir = IMAGES_URL . '/';

// Handle JSON request for edit
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
    $promo = $db->fetchRow("SELECT * FROM " . DB_PREFIX . "promotions WHERE id = ?", [$id]);
    header('Content-Type: application/json');
    if ($promo) {
        echo json_encode(['promotion' => $promo]);
    } else {
        echo json_encode(['error' => 'Promotion not found']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add' || $action === 'update') {
        $type = $_POST['type'] ?? 'banner';
        $content = trim($_POST['content'] ?? '');
        $link_url = trim($_POST['link_url']) ?: '/ecommerce-project/products/product_list.php?sale=1';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validation
        if (empty($content)) {
            $error = 'Content is required';
        }

        // Handle image upload for banners
        $image_url = null;
        $image_changed = false;

        if ($type === 'banner' && !$error) {
            if ($action === 'add' || !empty($_FILES['image_url']['name'])) {
                if (!isset($_FILES['image_url']) || $_FILES['image_url']['error'] !== UPLOAD_ERR_OK) {
                    if ($action === 'add') {
                        $error = 'Banner image is required';
                    }
                } else {
                    $file = $_FILES['image_url'];
                    $allowed_types = ALLOWED_IMAGE_TYPES;
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowed_types)) {
                        $error = 'Invalid image file type. Allowed: ' . implode(', ', $allowed_types);
                    } elseif ($file['size'] > MAX_FILE_SIZE) {
                        $error = 'File size too large. Maximum: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB';
                    } else {
                        // Generate unique filename
                        $filename = 'promo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $target_path = $upload_dir . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                            $error = 'Failed to upload image';
                        } else {
                            $image_url = $filename;
                            $image_changed = true;
                        }
                    }
                }
            }
        }

        if (!$error) {
            if ($action === 'add') {
                // Insert new promotion
                $data = [
                    'type' => $type,
                    'content' => $content,
                    'image_url' => $type === 'marquee' ? null : $image_url,
                    'link_url' => $link_url,
                    'is_active' => $is_active
                ];
                
                $insert_id = $db->insert('promotions', $data);

                if ($insert_id) {
                    $success = 'Promotion added successfully';
                    
                    // Store email data for AJAX sending if promotion is active
                    if ($is_active) {
                        $_SESSION['pending_email'] = [
                            'subject' => "New Promotion Active on " . SITE_NAME,
                            'content' => $content,
                            'link_url' => $link_url,
                            'image_url' => ($type === 'banner') ? $image_url : null
                        ];
                    }
                } else {
                    $error = 'Failed to add promotion';
                }
                
            } elseif ($action === 'update') {
                $id = intval($_POST['id']);
                if ($id <= 0) {
                    $error = 'Invalid promotion ID';
                } else {
                    // Fetch old promotion
                    $old_promo = $db->fetchRow("SELECT * FROM " . DB_PREFIX . "promotions WHERE id = ?", [$id]);

                    if (!$old_promo) {
                        $error = 'Promotion not found';
                    } else {
                        // Handle old image deletion
                        if ($type === 'banner' && $image_changed && !empty($old_promo['image_url'])) {
                            $old_image_path = $upload_dir . $old_promo['image_url'];
                            if (file_exists($old_image_path)) {
                                unlink($old_image_path);
                            }
                        }
                        
                        // If changing from banner to marquee, delete old image
                        if ($type === 'marquee' && !empty($old_promo['image_url'])) {
                            $old_image_path = $upload_dir . $old_promo['image_url'];
                            if (file_exists($old_image_path)) {
                                unlink($old_image_path);
                            }
                        }

                        $update_data = [
                            'type' => $type,
                            'content' => $content,
                            'image_url' => $type === 'marquee' ? null : ($image_changed ? $image_url : $old_promo['image_url']),
                            'link_url' => $link_url,
                            'is_active' => $is_active
                        ];

                        $rows_affected = $db->update('promotions', $update_data, 'id = :id', ['id' => $id]);

                        if ($rows_affected !== false) {
                            $success = 'Promotion updated successfully';
                            
                            // Store email data for AJAX sending only if it changed from inactive to active
                            if ($is_active && !$old_promo['is_active']) {
                                $_SESSION['pending_email'] = [
                                    'subject' => "Promotion Activated on " . SITE_NAME,
                                    'content' => $content,
                                    'link_url' => $link_url,
                                    'image_url' => ($type === 'banner') ? ($image_changed ? $image_url : $old_promo['image_url']) : null
                                ];
                            }
                        } else {
                            $error = 'Failed to update promotion';
                        }
                    }
                }
            }
        }
        
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        if ($id > 0) {
            $old_promo = $db->fetchRow("SELECT * FROM " . DB_PREFIX . "promotions WHERE id = ?", [$id]);
            if ($old_promo) {
                // Delete image file if banner
                if ($old_promo['type'] === 'banner' && !empty($old_promo['image_url'])) {
                    $old_image_path = $upload_dir . $old_promo['image_url'];
                    if (file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }
                }
                
                $deleted_rows = $db->delete('promotions', 'id = :id', ['id' => $id]);

                if ($deleted_rows) {
                    $success = 'Promotion deleted successfully';
                } else {
                    $error = 'Failed to delete promotion';
                }
            } else {
                $error = 'Promotion not found';
            }
        } else {
            $error = 'Invalid ID for deletion';
        }
    }
}

// Fetch all promotions
try {
    $promotions = $db->fetchAll("SELECT * FROM " . DB_PREFIX . "promotions ORDER BY created_at DESC");
} catch (Exception $e) {
    $promotions = [];
    $error = 'Failed to load promotions';
}

$page_title = 'Promotion Management - ' . SITE_NAME;
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
        body { font-family: 'Inter', sans-serif; }
        
        /* Custom Loader Styles */
        .email-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .loader-content {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }
        
        .envelope-animation {
            width: 80px;
            height: 60px;
            margin: 0 auto 20px;
            position: relative;
            animation: float 2s ease-in-out infinite;
        }
        
        .envelope-base {
            width: 80px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 5px;
            position: relative;
        }
        
        .envelope-flap {
            width: 0;
            height: 0;
            border-left: 40px solid transparent;
            border-right: 40px solid transparent;
            border-top: 30px solid #5a67d8;
            position: absolute;
            top: -15px;
            left: 0;
            animation: flapOpen 3s ease-in-out infinite;
        }
        
        .email-sparkles {
            position: absolute;
            width: 120px;
            height: 120px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        
        .sparkle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: #fbbf24;
            border-radius: 50%;
            animation: sparkleFloat 2s ease-in-out infinite;
        }
        
        .sparkle:nth-child(1) { top: 10%; left: 20%; animation-delay: 0s; }
        .sparkle:nth-child(2) { top: 20%; right: 15%; animation-delay: 0.3s; }
        .sparkle:nth-child(3) { bottom: 25%; left: 10%; animation-delay: 0.6s; }
        .sparkle:nth-child(4) { bottom: 15%; right: 20%; animation-delay: 0.9s; }
        .sparkle:nth-child(5) { top: 50%; left: 5%; animation-delay: 1.2s; }
        .sparkle:nth-child(6) { top: 60%; right: 5%; animation-delay: 1.5s; }
        
        .progress-container {
            background: #f3f4f6;
            border-radius: 10px;
            padding: 3px;
            margin: 20px 0;
        }
        
        .progress-bar {
            height: 20px;
            background: linear-gradient(90deg, #10b981, #34d399, #6ee7b7);
            border-radius: 8px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }
        
        .email-list {
            max-height: 120px;
            overflow-y: auto;
            background: #f9fafb;
            border-radius: 8px;
            padding: 10px;
            margin: 15px 0;
            font-size: 12px;
        }
        
        .email-item {
            padding: 2px 0;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .email-item.success {
            color: #059669;
        }
        
        .email-item.current {
            color: #1d4ed8;
            font-weight: 600;
        }
        
        .check-icon {
            width: 12px;
            height: 12px;
            color: #059669;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        @keyframes flapOpen {
            0%, 50% { transform: rotateX(0deg); }
            25% { transform: rotateX(-20deg); }
        }
        
        @keyframes sparkleFloat {
            0%, 100% { opacity: 0; transform: scale(0) translateY(0px); }
            50% { opacity: 1; transform: scale(1) translateY(-10px); }
        }
        
        .pulse-text {
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Responsive table container */
        .table-scroll-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Ensure table has proper minimum width to force scrolling on small screens */
        .promotions-table {
            min-width: 640px;
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
    </style>
</head>

<body class="bg-gray-50">
    <!-- Email Sending Loader -->
    <div id="email-loader" class="email-loader hidden">
        <div class="loader-content">
            <div class="email-sparkles">
                <div class="sparkle"></div>
                <div class="sparkle"></div>
                <div class="sparkle"></div>
                <div class="sparkle"></div>
                <div class="sparkle"></div>
                <div class="sparkle"></div>
            </div>
            
            <div class="envelope-animation">
                <div class="envelope-base"></div>
                <div class="envelope-flap"></div>
            </div>
            
            <h3 class="text-xl font-bold text-gray-800 mb-2">Sending Promotional Emails</h3>
            <p class="text-gray-600 pulse-text mb-4">Please wait while we notify your customers...</p>
            
            <div class="progress-container">
                <div id="progress-bar" class="progress-bar" style="width: 0%">0%</div>
            </div>
            
            <div class="flex justify-between text-sm text-gray-600 mb-3">
                <span>Progress: <span id="current-count">0</span> / <span id="total-count">0</span></span>
                <span id="percentage">0%</span>
            </div>
            
            <div id="email-list" class="email-list">
                <div class="text-gray-500">Preparing to send emails...</div>
            </div>
            
            <div id="completion-message" class="hidden">
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 mt-4">
                    <h4 class="text-green-800 font-semibold">✅ All emails sent successfully!</h4>
                    <p class="text-green-600 text-sm mt-1">Your promotion has been sent to all active users.</p>
                </div>
                <button onclick="closeEmailLoader()" class="mt-4 bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition">
                    Continue
                </button>
            </div>
        </div>
    </div>

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
                <a href="orders.php" class="flex text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
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
                <a href="promotions.php" class="flex bg-primary-50 border-r-4 border-primary-500 text-primary-700 block px-4 py-2 text-sm font-medium">
                    <svg class="h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    Promotions
                </a>
            </nav>
        </div>

        <!-- Overlay for mobile -->
        <div id="sidebar-overlay" class="fixed inset-0 bg-black opacity-50 z-30 lg:hidden hidden"></div>

        <!-- Main Content -->
        <!-- THE KEY FIX IS HERE: Added min-w-0 to allow the flex container to shrink -->
        <div class="flex-1 p-4 lg:p-8 mobile-padding min-w-0">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Promotion Management</h1>
                <p class="mt-1 text-sm text-gray-600">Manage your site promotions and marketing campaigns</p>
            </div>

            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm"><?= htmlspecialchars($error) ?></p>
                        </div>
                    </div>
                </div>
            <?php elseif ($success): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm"><?= htmlspecialchars($success) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Add New Promotion Button -->
            <div class="mb-6">
                <button onclick="showAddForm()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition duration-200">
                    Add New Promotion
                </button>
            </div>

            <!-- Promotion Form (Add/Edit) -->
            <div id="promo-form" class="hidden mb-6">
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4" id="form-title">Add New Promotion</h3>
                        <form method="POST" enctype="multipart/form-data" class="space-y-4" onsubmit="return handleFormSubmit(event)">
                            <input type="hidden" name="action" id="action" value="add">
                            <input type="hidden" name="id" id="promo_id" value="">

                            <div>
                                <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                                <select name="type" id="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" onchange="toggleImageInput()" required>
                                    <option value="marquee">Marquee</option>
                                    <option value="banner">Banner</option>
                                </select>
                            </div>

                            <div>
                                <label for="content" class="block text-sm font-medium text-gray-700">Content</label>
                                <textarea name="content" id="content" rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Enter promotion content..." required></textarea>
                            </div>

                            <div id="link-url-wrapper">
                                <label for="link_url" class="block text-sm font-medium text-gray-700">Link URL</label>
                                <input type="url" name="link_url" id="link_url" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="/ecommerce-project/products/product_list.php?sale=1">
                                <p class="mt-1 text-sm text-gray-500">Leave empty to use default sale page link</p>
                            </div>

                            <div id="image-upload-wrapper">
                                <label for="image_url" class="block text-sm font-medium text-gray-700">Banner Image</label>
                                <input type="file" name="image_url" id="image_url" accept=".jpg,.jpeg,.png,.gif,.webp" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                <p class="mt-1 text-sm text-gray-500">Supported formats: JPG, PNG, GIF, WebP. Max size: <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB</p>
                                <div id="current-image" class="mt-2"></div>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" name="is_active" id="is_active" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="is_active" class="ml-2 block text-sm text-gray-900">
                                    Active (will send email notifications to users)
                                </label>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="resetForm()" class="bg-white border border-gray-300 rounded-md py-2 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Cancel
                                </button>
                                <button type="submit" class="bg-blue-600 border border-transparent rounded-md py-2 px-4 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Save Promotion
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Promotions List -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">All Promotions</h3>
                    
                    <!-- THE SECOND FIX: Ensured this div has overflow-x-auto -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($promotions)): ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">No promotions found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($promotions as $promo): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($promo['id']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $promo['type'] === 'banner' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' ?>">
                                                <?= htmlspecialchars(ucfirst($promo['type'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?php if ($promo['type'] === 'banner'): ?>
                                                <span class="text-gray-400">—</span>
                                            <?php else: ?>
                                                <div class="max-w-xs truncate" title="<?= htmlspecialchars($promo['content']) ?>">
                                                    <?= htmlspecialchars($promo['content']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($promo['type'] === 'banner' && $promo['image_url']): ?>
                                                <img src="<?= IMAGES_URL . '/' . htmlspecialchars($promo['image_url']) ?>" alt="Banner" class="h-12 w-20 object-cover rounded">
                                            <?php else: ?>
                                                <span class="text-gray-400">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($promo['is_active']): ?>
                                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                            <?php else: ?>
                                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= date('M j, Y', strtotime($promo['created_at'])) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button onclick="editPromotion(<?= htmlspecialchars($promo['id']) ?>)" class="text-blue-600 hover:text-blue-900 mr-3">Edit</button>
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this promotion?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= htmlspecialchars($promo['id']) ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                                </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-toggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        });

        // Close sidebar when overlay is clicked
        document.getElementById('sidebar-overlay').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        });

        // Track if emails are currently being sent
        let isSendingEmails = false;
        
        // Check if there are pending emails to send
        <?php if (isset($_SESSION['pending_email'])): ?>
            const pendingEmail = <?= json_encode($_SESSION['pending_email']) ?>;
            document.addEventListener('DOMContentLoaded', function() {
                if (!isSendingEmails) {
                    isSendingEmails = true;
                    sendEmailsWithProgress(pendingEmail);
                }
            });
            <?php unset($_SESSION['pending_email']); ?>
        <?php endif; ?>

        function showAddForm() {
            document.getElementById('promo-form').classList.remove('hidden');
            document.getElementById('form-title').textContent = 'Add New Promotion';
            resetFormFields();
        }

        function resetForm() {
            document.getElementById('promo-form').classList.add('hidden');
            resetFormFields();
        }

        function resetFormFields() {
            document.getElementById('action').value = 'add';
            document.getElementById('promo_id').value = '';
            document.getElementById('type').value = 'marquee';
            document.getElementById('content').value = '';
            document.getElementById('link_url').value = '';
            document.getElementById('is_active').checked = false;
            document.getElementById('current-image').innerHTML = '';
            toggleImageInput();
        }

        function toggleImageInput() {
            const type = document.getElementById('type').value;
            const wrapper = document.getElementById('image-upload-wrapper');
            const linkUrlWrapper = document.getElementById('link-url-wrapper');
            
            if (type === 'marquee') {
                wrapper.style.display = 'none';
                linkUrlWrapper.style.display = 'none';
                document.getElementById('image_url').removeAttribute('required');
            } else {
                wrapper.style.display = 'block';
                linkUrlWrapper.style.display = 'block';
                if (document.getElementById('action').value === 'add') {
                    document.getElementById('image_url').setAttribute('required', 'required');
                }
            }
        }

        function handleFormSubmit(event) {
            const isActive = document.getElementById('is_active').checked;
            const action = document.getElementById('action').value;
            
            // If adding a new promotion or updating to active, we'll handle email sending via AJAX
            if (isActive) {
                // Let the form submit normally, emails will be sent via session check
                return true;
            }
            
            return true;
        }

        function sendEmailsWithProgress(emailData) {
            showEmailLoader();
            
            const formData = new FormData();
            formData.append('subject', emailData.subject);
            formData.append('content', emailData.content);
            formData.append('link_url', emailData.link_url);
            if (emailData.image_url) {
                formData.append('image_url', emailData.image_url);
            }

            fetch('promotions.php?send_emails=1', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                
                function readStream() {
                    return reader.read().then(({ done, value }) => {
                        if (done) return;
                        
                        const chunk = decoder.decode(value, { stream: true });
                        const lines = chunk.split('\n').filter(line => line.trim());
                        
                        lines.forEach(line => {
                            try {
                                const data = JSON.parse(line);
                                updateProgress(data);
                            } catch (e) {
                                console.log('Non-JSON line:', line);
                            }
                        });
                        
                        return readStream();
                    });
                }
                
                return readStream();
            })
            .catch(error => {
                console.error('Error:', error);
                hideEmailLoader();
                alert('Error sending emails');
            });
        }

        function showEmailLoader() {
            document.getElementById('email-loader').classList.remove('hidden');
            document.getElementById('progress-bar').style.width = '0%';
            document.getElementById('progress-bar').textContent = '0%';
            document.getElementById('current-count').textContent = '0';
            document.getElementById('percentage').textContent = '0%';
            document.getElementById('email-list').innerHTML = '<div class="text-gray-500">Preparing to send emails...</div>';
            document.getElementById('completion-message').classList.add('hidden');
        }

        function updateProgress(data) {
            if (data.error) {
                hideEmailLoader();
                alert('Error: ' + data.error);
                return;
            }

            const progressBar = document.getElementById('progress-bar');
            const currentCount = document.getElementById('current-count');
            const totalCount = document.getElementById('total-count');
            const percentage = document.getElementById('percentage');
            const emailList = document.getElementById('email-list');

            if (data.total) {
                totalCount.textContent = data.total;
            }

            if (data.progress !== undefined) {
                progressBar.style.width = data.progress + '%';
                progressBar.textContent = data.progress + '%';
                percentage.textContent = data.progress + '%';
            }

            if (data.current) {
                currentCount.textContent = data.current;
            }

            if (data.email && data.status) {
                const emailItem = document.createElement('div');
                emailItem.className = `email-item ${data.status}`;
                emailItem.innerHTML = `
                    <span>${data.email}</span>
                    ${data.status === 'success' ? '<svg class="check-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>' : ''}
                `;
                
                // Remove the "preparing" message if it exists
                const preparing = emailList.querySelector('.text-gray-500');
                if (preparing) {
                    preparing.remove();
                }
                
                emailList.appendChild(emailItem);
                emailList.scrollTop = emailList.scrollHeight;
            }

            if (data.complete) {
                setTimeout(() => {
                    document.getElementById('completion-message').classList.remove('hidden');
                }, 500);
            }
        }

        function closeEmailLoader() {
            isSendingEmails = false;
            hideEmailLoader();
        }

        function hideEmailLoader() {
            document.getElementById('email-loader').classList.add('hidden');
        }

        function editPromotion(id) {
            fetch(`promotions.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.error) {
                        const promo = data.promotion;
                        
                        document.getElementById('promo-form').classList.remove('hidden');
                        document.getElementById('form-title').textContent = 'Edit Promotion';
                        document.getElementById('action').value = 'update';
                        document.getElementById('promo_id').value = promo.id;
                        document.getElementById('type').value = promo.type;
                        document.getElementById('content').value = promo.content;
                        document.getElementById('link_url').value = promo.link_url || '';
                        document.getElementById('is_active').checked = promo.is_active == 1;

                        // Handle current image display
                        if (promo.type === 'banner' && promo.image_url) {
                            document.getElementById('current-image').innerHTML = 
                                `<div class="mt-2"><img src="<?= IMAGES_URL ?>/${promo.image_url}" alt="Current Image" class="h-20 w-auto rounded border"><p class="text-sm text-gray-500 mt-1">Current image</p></div>`;
                        } else {
                            document.getElementById('current-image').innerHTML = '';
                        }

                        // Remove required attribute for update
                        document.getElementById('image_url').removeAttribute('required');
                        
                        toggleImageInput();
                        
                        // Scroll to form
                        document.getElementById('promo-form').scrollIntoView({ behavior: 'smooth' });
                    } else {
                        alert('Failed to load promotion data: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading promotion data');
                });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('type').addEventListener('change', toggleImageInput);
            toggleImageInput();
        });
    </script>
</body>
</html>