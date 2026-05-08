<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */

/**
 * Admin Testimonials Management Page
 * Manage product reviews and testimonials
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and database
require_once '../includes/config.php';
require_once '../includes/db.php';

// Check if user is logged in and is admin
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
$search_query = '';
$status_filter = '';
$rating_filter = '';
$page = 1;
$per_page = 20;

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Security token mismatch. Please try again.";
    } else {
        $action = $_POST['action'];
        $testimonial_id = (int)($_POST['testimonial_id'] ?? 0);

        try {
            switch ($action) {
                case 'approve':
                    if ($testimonial_id > 0) {
                        $updated = $db->update(
                            'testimonials',
                            ['status' => 'approved'],
                            'id = :id',
                            ['id' => $testimonial_id]
                        );
                        if ($updated) {
                            $success_message = "Testimonial approved successfully.";
                        } else {
                            $error_message = "Failed to approve testimonial.";
                        }
                    }
                    break;

                case 'reject':
                    if ($testimonial_id > 0) {
                        $updated = $db->update(
                            'testimonials',
                            ['status' => 'rejected'],
                            'id = :id',
                            ['id' => $testimonial_id]
                        );
                        if ($updated) {
                            $success_message = "Testimonial rejected successfully.";
                        } else {
                            $error_message = "Failed to reject testimonial.";
                        }
                    }
                    break;

                case 'delete':
                    if ($testimonial_id > 0) {
                        $deleted = $db->delete('testimonials', 'id = :id', ['id' => $testimonial_id]);
                        if ($deleted) {
                            $success_message = "Testimonial deleted successfully.";
                        } else {
                            $error_message = "Failed to delete testimonial.";
                        }
                    }
                    break;

                case 'bulk_action':
                    $bulk_action = $_POST['bulk_action'] ?? '';
                    $selected_testimonials = $_POST['selected_testimonials'] ?? [];

                    if (!empty($bulk_action) && !empty($selected_testimonials)) {
                        $updated_count = 0;
                        foreach ($selected_testimonials as $id) {
                            $id = (int)$id;
                            if ($id > 0) {
                                switch ($bulk_action) {
                                    case 'approve':
                                        $updated = $db->update(
                                            'testimonials',
                                            ['status' => 'approved'],
                                            'id = :id',
                                            ['id' => $id]
                                        );
                                        break;
                                    case 'reject':
                                        $updated = $db->update(
                                            'testimonials',
                                            ['status' => 'rejected'],
                                            'id = :id',
                                            ['id' => $id]
                                        );
                                        break;
                                    case 'delete':
                                        $updated = $db->delete('testimonials', 'id = :id', ['id' => $id]);
                                        break;
                                    default:
                                        $updated = false;
                                }
                                if ($updated) $updated_count++;
                            }
                        }
                        if ($updated_count > 0) {
                            $success_message = "Successfully {$bulk_action}d {$updated_count} testimonial(s).";
                        } else {
                            $error_message = "No testimonials were updated.";
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            $error_message = "An error occurred: " . $e->getMessage();
            error_log("Testimonials management error: " . $e->getMessage());
        }
    }
}

// Handle GET parameters for filtering and pagination
$search_query = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$rating_filter = (int)($_GET['rating'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));

// Build query conditions - FIXED THE SEARCH PARAMETER ISSUE
$conditions = [];
$params = [];

if (!empty($search_query)) {
    $conditions[] = "(t.title LIKE :search1 OR t.review LIKE :search2 OR t.customer_name LIKE :search3 OR p.name LIKE :search4)";
    $params['search1'] = '%' . $search_query . '%';
    $params['search2'] = '%' . $search_query . '%';
    $params['search3'] = '%' . $search_query . '%';
    $params['search4'] = '%' . $search_query . '%';
}

if (!empty($status_filter)) {
    $conditions[] = "t.status = :status";
    $params['status'] = $status_filter;
}

if ($rating_filter > 0 && $rating_filter <= 5) {
    $conditions[] = "t.rating = :rating";
    $params['rating'] = $rating_filter;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Count total testimonials
$count_query = "SELECT COUNT(*) as total FROM " . DB_PREFIX . "testimonials t 
                LEFT JOIN " . DB_PREFIX . "products p ON t.product_id = p.id 
                LEFT JOIN " . DB_PREFIX . "users u ON t.user_id = u.id 
                {$where_clause}";
$total_result = $db->fetchRow($count_query, $params);
$total_count = $total_result['total'] ?? 0;

// Calculate pagination
$total_pages = ceil($total_count / $per_page);
$offset = ($page - 1) * $per_page;

// Fetch testimonials
$testimonials_query = "SELECT t.*, 
                      p.name as product_name, 
                      p.image as product_image,
                      u.email as user_email,
                      u.first_name, 
                      u.last_name
                      FROM " . DB_PREFIX . "testimonials t 
                      LEFT JOIN " . DB_PREFIX . "products p ON t.product_id = p.id 
                      LEFT JOIN " . DB_PREFIX . "users u ON t.user_id = u.id 
                      {$where_clause} 
                      ORDER BY t.created_at DESC 
                      LIMIT {$offset}, {$per_page}";

$testimonials = $db->fetchAll($testimonials_query, $params);

// Get status counts for filter tabs
$status_counts = [
    'all' => $total_count,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

$status_count_query = "SELECT t.status, COUNT(*) as count FROM " . DB_PREFIX . "testimonials t 
                      LEFT JOIN " . DB_PREFIX . "products p ON t.product_id = p.id 
                      LEFT JOIN " . DB_PREFIX . "users u ON t.user_id = u.id 
                      GROUP BY t.status";
$status_results = $db->fetchAll($status_count_query);

foreach ($status_results as $result) {
    if (isset($status_counts[$result['status']])) {
        $status_counts[$result['status']] = $result['count'];
    }
}

// Helper functions
function getStarRating($rating)
{
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $stars .= '<svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>';
        } else {
            $stars .= '<svg class="w-4 h-4 text-gray-300" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>';
        }
    }
    return $stars;
}

function getStatusBadge($status)
{
    switch ($status) {
        case 'approved':
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Approved</span>';
        case 'rejected':
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Rejected</span>';
        case 'pending':
        default:
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending</span>';
    }
}

function truncateText($text, $length = 100)
{
    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
}

// Page title
$page_title = 'Testimonials Management - ' . SITE_NAME;
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
                <a href="testimonials.php" class="flex bg-primary-50 border-r-4 border-primary-500 text-primary-700 block px-4 py-2 text-sm font-medium">
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Testimonials Management</h1>
                </div>
                <p class="mt-1 text-gray-600">Manage product reviews and testimonials (<?= number_format($total_count) ?> total)</p>
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
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="CurrentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm"><?= htmlspecialchars($error_message) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filters and Search -->
            <div class="bg-white shadow-sm rounded-lg mb-6">
                <div class="p-4 sm:p-6">
                    <form method="GET" class="space-y-4 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_query) ?>"
                                placeholder="Search reviews, customers, products..."
                                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">All Statuses</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>
                        <div>
                            <label for="rating" class="block text-sm font-medium text-gray-700 mb-1">Rating</label>
                            <select id="rating" name="rating"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">All Ratings</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?= $i ?>" <?= $rating_filter == $i ? 'selected' : '' ?>>
                                        <?= $i ?> Star<?= $i > 1 ? 's' : '' ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="flex items-end space-x-2">
                            <button type="submit" class="flex-1 lg:flex-none bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-indigo-700">
                                <svg class="inline-block w-4 h-4 mr-1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor">
                                    <path d="M3.9 54.9C10.5 40.9 24.5 32 40 32H472c15.5 0 29.5 8.9 36.1 22.9s4.6 30.5-5.2 42.5L320 320.9V448c 0 12.1-6.8 23.2-17.7 28.6s-23.8 4.3-33.5-3l-64-48c-8.1-6-12.8-15.5-12.8-25.6V320.9L9 97.3C-.7 85.4-2.8 68.8 3.9 54.9z" />
                                </svg>
                                Filter
                            </button>
                            <a href="testimonials.php" class="flex-1 lg:flex-none bg-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm font-medium hover:bg-gray-400 text-center">
                                Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="bg-white shadow-sm rounded-lg mb-6">
                <div class="border-b border-gray-200">
                    <nav class="flex space-x-8 px-6" aria-label="Tabs">
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => '', 'page' => 1])) ?>"
                            class="py-4 px-1 border-b-2 font-medium text-sm <?= empty($status_filter) ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                            All (<?= number_format($status_counts['all']) ?>)
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'pending', 'page' => 1])) ?>"
                            class="py-4 px-1 border-b-2 font-medium text-sm <?= $status_filter === 'pending' ? 'border-yellow-500 text-yellow-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                            Pending (<?= number_format($status_counts['pending']) ?>)
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'approved', 'page' => 1])) ?>"
                            class="py-4 px-1 border-b-2 font-medium text-sm <?= $status_filter === 'approved' ? 'border-green-500 text-green-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                            Approved (<?= number_format($status_counts['approved']) ?>)
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'rejected', 'page' => 1])) ?>"
                            class="py-4 px-1 border-b-2 font-medium text-sm <?= $status_filter === 'rejected' ? 'border-red-500 text-red-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                            Rejected (<?= number_format($status_counts['rejected']) ?>)
                        </a>
                    </nav>
                </div>
            </div>

            <!-- Testimonials Table -->
            <div class="bg-white shadow-sm rounded-lg">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-medium text-gray-900">
                        Testimonials (<?= number_format($total_count) ?> total)
                    </h2>
                </div>

                <!-- Bulk Actions -->
                <?php if (!empty($testimonials)): ?>
                    <div class="p-4 border-b bg-gray-50">
                        <form method="POST" id="bulkForm" class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="bulk_action">

                            <div class="flex items-center gap-3">
                                <input type="checkbox"
                                    id="selectAll"
                                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                <label for="selectAll" class="text-sm text-gray-600">Select All</label>
                            </div>

                            <select name="bulk_action"
                                class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                                <option value="">Bulk Actions</option>
                                <option value="approve">Approve Selected</option>
                                <option value="reject">Reject Selected</option>
                                <option value="delete">Delete Selected</option>
                            </select>

                            <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Apply
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Mobile Card View -->
                <div class="block lg:hidden">
                    <?php if (empty($testimonials)): ?>
                        <div class="p-8 text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                            </svg>
                            <p>No testimonials found</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 p-4">
                            <?php foreach ($testimonials as $testimonial): ?>
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <div class="font-medium text-gray-900">
                                                <?= htmlspecialchars(truncateText($testimonial['title'], 30)) ?>
                                            </div>
                                            <div class="text-sm text-gray-500">ID: <?= $testimonial['id'] ?></div>
                                        </div>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full
                                            <?php
                                            switch ($testimonial['status']) {
                                                case 'pending':
                                                    echo 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'approved':
                                                    echo 'bg-green-100 text-green-800';
                                                    break;
                                                case 'rejected':
                                                    echo 'bg-red-100 text-red-800';
                                                    break;
                                                default:
                                                    echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?= ucfirst(htmlspecialchars($testimonial['status'])) ?>
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-600 mb-2">
                                        <?= htmlspecialchars($testimonial['customer_name']) ?>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($testimonial['user_email'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="flex items-center mb-2">
                                        <?= getStarRating($testimonial['rating']) ?>
                                        <span class="ml-2 text-sm text-gray-600"><?= $testimonial['rating'] ?>/5</span>
                                    </div>
                                    <div class="text-sm text-gray-600 mb-3">
                                        <?= htmlspecialchars(truncateText($testimonial['review'], 80)) ?>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <div class="text-xs text-gray-500">
                                            <?= date('M j, Y', strtotime($testimonial['created_at'])) ?>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button onclick="viewTestimonial(<?= htmlspecialchars(json_encode($testimonial)) ?>)"
                                                class="text-blue-600 hover:text-blue-900 p-1" title="View Details">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3  0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </button>
                                            <?php if ($testimonial['status'] !== 'approved'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="testimonial_id" value="<?= $testimonial['id'] ?>">
                                                    <button type="submit"
                                                        class="text-green-600 hover:text-green-900 p-1"
                                                        title="Approve"
                                                        onclick="return confirm('Approve this testimonial?')">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                        </svg>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($testimonial['status'] !== 'rejected'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="testimonial_id" value="<?= $testimonial['id'] ?>">
                                                    <button type="submit"
                                                        class="text-yellow-600 hover:text-yellow-900 p-1"
                                                        title="Reject"
                                                        onclick="return confirm('Reject this testimonial?')">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                        </svg>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="testimonial_id" value="<?= $testimonial['id'] ?>">
                                                <button type="submit"
                                                    class="text-red-600 hover:text-red-900 p-1"
                                                    title="Delete"
                                                    onclick="return confirm('Are you sure you want to delete this testimonial? This action cannot be undone.')">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            </form>
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
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <input type="checkbox" id="selectAllHeader" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Review</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rating</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($testimonials)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                                        </svg>
                                        <p>No testimonials found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($testimonials as $testimonial): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="checkbox"
                                                name="selected_testimonials[]"
                                                value="<?= $testimonial['id'] ?>"
                                                class="testimonial-checkbox h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="max-w-xs">
                                                <p class="text-sm font-medium text-gray-900 mb-1">
                                                    <?= htmlspecialchars($testimonial['title']) ?>
                                                </p>
                                                <p class="text-sm text-gray-600 line-clamp-2">
                                                    <?= htmlspecialchars(truncateText($testimonial['review'], 120)) ?>
                                                </p>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <?php if (!empty($testimonial['product_image'])): ?>
                                                    <img class="h-10 w-10 rounded-md object-cover mr-3"
                                                        src="<?= IMAGES_URL ?>/<?= htmlspecialchars($testimonial['product_image']) ?>"
                                                        alt="Product">
                                                <?php else: ?>
                                                    <div class="h-10 w-10 rounded-md bg-gray-200 flex items-center justify-center mr-3">
                                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                        </svg>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($testimonial['product_name'] ?? 'Unknown Product') ?>
                                                    </p>
                                                    <p class="text-xs text-gray-500">ID: <?= $testimonial['product_id'] ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($testimonial['customer_name']) ?>
                                                </p>
                                                <?php if (!empty($testimonial['user_email'])): ?>
                                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($testimonial['user_email']) ?></p>
                                                <?php endif; ?>
                                                <?php if ($testimonial['verified_purchase']): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 mt-1">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                        </svg>
                                                        Verified
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <?= getStarRating($testimonial['rating']) ?>
                                                <span class="ml-2 text-sm text-gray-600"><?= $testimonial['rating'] ?>/5</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?= getStatusBadge($testimonial['status']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= date('M j, Y', strtotime($testimonial['created_at'])) ?>
                                            <div class="text-xs text-gray-400">
                                                <?= date('g:i A', strtotime($testimonial['created_at'])) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex items-center justify-end space-x-2">
                                                <!-- View/Edit Button -->
                                                <button type="button" onclick="viewTestimonial(<?= htmlspecialchars(json_encode($testimonial)) ?>)"
                                                    class="text-blue-600 hover:text-blue-900 p-1" title="View Details">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                </button>

                                                <?php if ($testimonial['status'] !== 'approved'): ?>
                                                    <!-- Approve Button -->
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="testimonial_id" value="<?= $testimonial['id'] ?>">
                                                        <button type="submit"
                                                            class="text-green-600 hover:text-green-900 p-1"
                                                            title="Approve"
                                                            onclick="return confirm('Approve this testimonial?')">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($testimonial['status'] !== 'rejected'): ?>
                                                    <!-- Reject Button -->
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="testimonial_id" value="<?= $testimonial['id'] ?>">
                                                        <button type="submit"
                                                            class="text-yellow-600 hover:text-yellow-900 p-1"
                                                            title="Reject"
                                                            onclick="return confirm('Reject this testimonial?')">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- Delete Button -->
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="testimonial_id" value="<?= $testimonial['id'] ?>">
                                                    <button type="submit"
                                                        class="text-red-600 hover:text-red-900 p-1"
                                                        title="Delete"
                                                        onclick="return confirm('Are you sure you want to delete this testimonial? This action cannot be undone.')">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                    </button>
                                                </form>
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
                                    <a href="?page=<?= $page - 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?><?= $rating_filter ? '&rating=' . urlencode($rating_filter) : '' ?>"
                                        class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded">
                                        Previous
                                    </a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?= $i ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?><?= $rating_filter ? '&rating=' . urlencode($rating_filter) : '' ?>"
                                        class="px-3 py-2 text-sm border <?= $i === $page ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' ?> rounded">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?= $page + 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?><?= $status_filter ? '&status=' . urlencode($status_filter) : '' ?><?= $rating_filter ? '&rating=' . urlencode($rating_filter) : '' ?>"
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

    <!-- Testimonial View Modal -->
    <div id="testimonialModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Testimonial Details</h3>
            <div id="testimonialContent"></div>
            <div class="mt-6 flex justify-end">
                <button type="button" onclick="closeTestimonialModal()"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
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

        // Select all functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        const selectAllHeader = document.getElementById('selectAllHeader');
        const testimonialCheckboxes = document.querySelectorAll('.testimonial-checkbox');

        function updateSelectAllState() {
            const checkedCheckboxes = document.querySelectorAll('.testimonial-checkbox:checked');

            if (checkedCheckboxes.length === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
                selectAllHeader.checked = false;
                selectAllHeader.indeterminate = false;
            } else if (checkedCheckboxes.length === testimonialCheckboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
                selectAllHeader.checked = true;
                selectAllHeader.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
                selectAllHeader.checked = false;
                selectAllHeader.indeterminate = true;
            }
        }

        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            testimonialCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateSelectAllState();
        });

        selectAllHeader.addEventListener('change', function() {
            const isChecked = this.checked;
            testimonialCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateSelectAllState();
        });

        testimonialCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectAllState);
        });

        // Bulk form submission
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const checkedBoxes = document.querySelectorAll('.testimonial-checkbox:checked');
            const bulkAction = document.querySelector('select[name="bulk_action"]').value;

            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one testimonial.');
                return;
            }

            if (!bulkAction) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return;
            }

            // Create hidden inputs for selected testimonials
            checkedBoxes.forEach(checkbox => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'selected_testimonials[]';
                hiddenInput.value = checkbox.value;
                this.appendChild(hiddenInput);
            });

            if (!confirm(`Are you sure you want to ${bulkAction} ${checkedBoxes.length} testimonial(s)?`)) {
                e.preventDefault();
            }
        });

        // View testimonial modal
        function viewTestimonial(testimonial) {
            const modal = document.getElementById('testimonialModal');
            const content = document.getElementById('testimonialContent');

            const stars = '★'.repeat(testimonial.rating) + '☆'.repeat(5 - testimonial.rating);
            const statusBadge = getStatusBadgeHTML(testimonial.status);

            content.innerHTML = `
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-lg font-medium">${escapeHtml(testimonial.title)}</h4>
                            <p class="text-sm text-gray-600">by ${escapeHtml(testimonial.customer_name)}</p>
                        </div>
                        ${statusBadge}
                    </div>
                    
                    <div class="flex items-center space-x-2">
                        <span class="text-yellow-400 text-lg">${stars}</span>
                        <span class="text-sm text-gray-600">${testimonial.rating}/5</span>
                    </div>
                    
                    <div>
                        <h5 class="font-medium mb-2">Review:</h5>
                        <p class="text-gray-700 bg-gray-50 p-3 rounded">${escapeHtml(testimonial.review).replace(/\n/g, '<br>')}</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t">
                        <div>
                            <p class="text-sm text-gray-600">Product:</p>
                            <p class="font-medium">${escapeHtml(testimonial.product_name || 'Unknown')}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Date:</p>
                            <p class="font-medium">${new Date(testimonial.created_at).toLocaleDateString()}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Customer ID:</p>
                            <p class="font-medium">${testimonial.user_id || 'Guest'}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Helpful Count:</p>
                            <p class="font-medium">${testimonial.helpful_count || 0}</p>
                        </div>
                    </div>
                </div>
            `;

            modal.classList.remove('hidden');
        }

        function closeTestimonialModal() {
            document.getElementById('testimonialModal').classList.add('hidden');
        }

        function getStatusBadgeHTML(status) {
            switch (status) {
                case 'approved':
                    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Approved</span>';
                case 'rejected':
                    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Rejected</span>';
                default:
                    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending</span>';
            }
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        }

        // Close modal when clicking outside
        document.getElementById('testimonialModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTestimonialModal();
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