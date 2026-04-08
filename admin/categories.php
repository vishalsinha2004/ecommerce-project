<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

// Admin authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

try {
    $admin_check = $db->fetchRow("SELECT role, first_name FROM " . DB_PREFIX . "users WHERE id = ? AND role = 'admin' AND status = 'active'", [$_SESSION['user_id']]);
    if (!$admin_check) {
        session_destroy();
        header('Location: ../auth/login.php?error=access_denied');
        exit;
    }
    $_SESSION['first_name'] = $admin_check['first_name'];
} catch (Exception $e) {
    error_log("Admin auth error: " . $e->getMessage());
    header('Location: ../auth/login.php?error=system_error');
    exit;
}

$page_title = 'Category Management - Admin Panel';
$success_message = '';
$error_message = '';
$categories = [];
$edit_category = null;
$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Calculate next sort order for new categories
$next_sort_order = $db->fetchRow("SELECT MAX(sort_order) AS max_sort FROM " . DB_PREFIX . "categories")['max_sort'] ?? 0;
$next_sort_order = (int)$next_sort_order + 1;

// Icon upload handler function (same as in add_product.php)
function handleIconUpload($file)
{
    $upload_dir = IMAGES_PATH . '/';

    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file type
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed types: ' . implode(', ', $allowed_types)];
    }

    // Validate file size (5MB max)
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'File size exceeds maximum limit of ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB'];
    }

    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => 'Failed to save uploaded file'];
    }
}

// Handle Add/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'];
        if ($action === 'add' || $action === 'edit') {
            $name = trim($_POST['name'] ?? '');
            $slug = trim($_POST['slug'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
            $show_on_homepage = !empty($_POST['show_on_homepage']) ? 1 : 0;
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            // Validate sort order uniqueness
            $sort_order_exists = false;
            if ($action === 'add') {
                $sort_order_exists = $db->exists('categories', 'sort_order = :sort_order', ['sort_order' => $sort_order]);
            } else {
                $category_id = (int)$_POST['category_id'];
                $sort_order_exists = $db->exists('categories', 'sort_order = :sort_order AND id != :id', [
                    'sort_order' => $sort_order,
                    'id' => $category_id
                ]);
            }

            if ($sort_order_exists) {
                $error_message = 'The sort order value is already taken. Please choose a different one.';
            } elseif ($name === '') {
                $error_message = 'Category name is required.';
            } else {
                $icon = '';

                // Handle icon upload
                if (isset($_FILES['icon_upload']) && $_FILES['icon_upload']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleIconUpload($_FILES['icon_upload']);
                    if ($upload_result['success']) {
                        $icon = $upload_result['filename'];
                    } else {
                        $error_message = $upload_result['error'];
                    }
                }

                if (!$error_message) {
                    try {
                        $data = [
                            'name' => $name,
                            'slug' => $slug,
                            'description' => $description,
                            'status' => $status,
                            'show_on_homepage' => $show_on_homepage,
                            'sort_order' => $sort_order,
                            'updated_at' => date('Y-m-d H:i:s')
                        ];

                        // Handle file updates for existing category
                        if ($action === 'edit') {
                            $category_id = (int)$_POST['category_id'];
                            $current = $db->fetchRow("SELECT icon FROM " . DB_PREFIX . "categories WHERE id = ?", [$category_id]);

                            // Handle icon
                            if (!empty($icon)) {
                                $data['icon'] = $icon;
                                // Delete old icon if exists
                                if (!empty($current['icon'])) {
                                    $oldIcon = IMAGES_PATH . '/' . $current['icon'];
                                    if (file_exists($oldIcon)) {
                                        unlink($oldIcon);
                                    }
                                }
                            } elseif (isset($_POST['remove_icon'])) {
                                $data['icon'] = '';
                                // Delete old icon if exists
                                if (!empty($current['icon'])) {
                                    $oldIcon = IMAGES_PATH . '/' . $current['icon'];
                                    if (file_exists($oldIcon)) {
                                        unlink($oldIcon);
                                    }
                                }
                            }

                            $db->update('categories', $data, 'id = :id', ['id' => $category_id]);
                            $success_message = 'Category updated successfully.';
                        } else {
                            // For new category, set icon if uploaded
                            if (!empty($icon)) $data['icon'] = $icon;
                            $data['created_at'] = date('Y-m-d H:i:s');

                            $db->insert('categories', $data);
                            $success_message = 'Category added successfully.';
                        }
                    } catch (Exception $e) {
                        error_log("Category save error: " . $e->getMessage());
                        $error_message = 'Failed to save category.';
                    }
                }
            }
        } elseif ($action === 'delete' && isset($_POST['category_id'])) {
            $category_id = (int)$_POST['category_id'];
            try {
                // Change this line to check for category_id instead of category
                $product_count = $db->count('products', 'category_id = :category_id', ['category_id' => $category_id]);
                if ($product_count > 0) {
                    $error_message = 'Cannot delete: category is in use by products.';
                } else {
                    // Get category data to delete associated files
                    $category = $db->fetchRow("SELECT icon FROM " . DB_PREFIX . "categories WHERE id = ?", [$category_id]);

                    $db->delete('categories', 'id = :id', ['id' => $category_id]);

                    // Delete associated files
                    if (!empty($category['icon'])) {
                        $iconFile = IMAGES_PATH . '/' . $category['icon'];
                        if (file_exists($iconFile)) {
                            unlink($iconFile);
                        }
                    }

                    $success_message = 'Category deleted successfully.';
                }
            } catch (Exception $e) {
                error_log("Category deletion error: " . $e->getMessage());
                $error_message = 'Failed to delete category.';
            }
        }
    }
}

// Get categories for table view
try {
    $total_count = $db->count('categories', '1');
    $total_pages = ceil($total_count / $per_page);
    $offset = ($page - 1) * $per_page;
    $categories = $db->fetchAll("SELECT * FROM " . DB_PREFIX . "categories ORDER BY created_at DESC LIMIT {$offset}, {$per_page}");
} catch (Exception $e) {
    error_log("Categories fetch error: " . $e->getMessage());
    $categories = [];
    $total_pages = 0;
}

// Edit category view
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_category = $db->fetchRow("SELECT * FROM " . DB_PREFIX . "categories WHERE id = :id", ['id' => (int)$_GET['id']]);
}

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
          .loader {
        border: 4px solid rgba(0, 0, 0, 0.1);
        border-radius: 50%;
        border-top: 4px solid #333;
        width: 16px;
        height: 16px;
        animation: spin 1s linear infinite;
    }
    
    /* For dark background buttons */
    .bg-primary-600 .loader {
        border-top-color: #fff;
        border-color: rgba(255, 255, 255, 0.2);
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
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
        pointer-events: none; /* Disable clicks */
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
                <a href="categories.php" class="flex bg-primary-50 border-r-4 border-primary-500 text-primary-700 block px-4 py-2 text-sm font-medium">
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
                <a href="promotions.php" class="flex text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
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
        <div class="flex-1 p-4 lg:p-8 mobile-padding min-w-0">
            <div class="mb-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Category Management</h1>
                        <p class="mt-1 text-sm text-gray-600">Manage your product categories and organization</p>
                    </div>
                    <button id="toggleFormBtn" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg font-medium transition duration-200 flex items-center">
                        <svg id="addIcon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="mr-2" viewBox="0 0 16 16">
                            <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z" />
                        </svg>
                        <svg id="closeIcon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="mr-2 hidden" viewBox="0 0 16 16">
                            <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z" />
                        </svg>
                        <span id="btnText">Add New Category</span>
                    </button>
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

            <!-- Add/Edit Form -->
            <div id="categoryForm" class="bg-white shadow-sm rounded-lg mb-6 <?= $edit_category ? '' : 'hidden' ?>">
                <div class="p-6">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800"><?= $edit_category ? 'Edit Category' : 'Add New Category' ?></h2>
                    <form method="POST" action="categories.php" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <?php if ($edit_category): ?>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="category_id" value="<?= (int)$edit_category['id'] ?>">
                        <?php else: ?>
                            <input type="hidden" name="action" value="add">
                        <?php endif; ?>
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" id="name" name="name" required value="<?= htmlspecialchars($edit_category['name'] ?? '') ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        </div>
                        <div>
                            <label for="slug" class="block text-sm font-medium text-gray-700 mb-1">Slug (URL-friendly)</label>
                            <input type="text" id="slug" name="slug" value="<?= htmlspecialchars($edit_category['slug'] ?? '') ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea id="description" name="description" rows="3" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"><?= htmlspecialchars($edit_category['description'] ?? '') ?></textarea>
                        </div>

                        <!-- Icon Upload -->
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Icon</label>
                            <?php if ($edit_category && !empty($edit_category['icon'])): ?>
                                <div class="flex items-center mb-2">
                                    <img src="<?= IMAGES_URL . '/' . $edit_category['icon'] ?>" alt="Current Icon" class="h-12 w-12 object-contain">
                                    <button type="button" onclick="removeIcon()" class="ml-2 text-red-600 hover:text-red-800">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                            <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z" />
                                        </svg>
                                    </button>
                                    <input type="hidden" name="remove_icon" id="remove_icon" value="0">
                                </div>
                            <?php endif; ?>
                            <input type="file" id="icon_upload" name="icon_upload" accept="image/*" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <p class="mt-1 text-xs text-gray-500">Recommended size: 100x100 pixels. Only images are allowed.</p>
                        </div>

                        <div>
                            <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" min="1" value="<?= htmlspecialchars($edit_category['sort_order'] ?? $next_sort_order) ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="active" <?= (isset($edit_category['status']) && $edit_category['status'] === 'inactive') ? '' : ' selected' ?>>Active</option>
                                <option value="inactive" <?= (isset($edit_category['status']) && $edit_category['status'] === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="md:col-span-2 flex items-center gap-4">
    <button type="submit" id="submit-category-btn" class="action-btn bg-primary-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-primary-700 flex items-center justify-center">
        <span class="btn-text"><?= $edit_category ? 'Update Category' : 'Add Category' ?></span>
        <span class="loader hidden"></span>
    </button>
    <?php if ($edit_category): ?>
        <a href="categories.php" id="cancel-edit-btn" class="action-btn bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm font-medium hover:bg-gray-300 flex items-center justify-center">
            <span class="btn-text">Cancel Edit</span>
            <span class="loader hidden"></span>
        </a>
    <?php endif; ?>
</div>
                    </form>
                </div>
            </div>

            <!-- Categories Table -->
            <div class="bg-white shadow-sm rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-medium text-gray-900">Categories (<?= number_format($total_count) ?> total)</h2>
                </div>

                <!-- Mobile Card View -->
                <div class="block lg:hidden">
                    <?php if (empty($categories)): ?>
                        <div class="p-8 text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a2 2 0 012 2v5a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm0 0v11m0-11h11m-11 0v11" />
                            </svg>
                            <p>No categories found.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 p-4">
                            <?php foreach ($categories as $cat): ?>
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <div class="font-medium text-gray-900">
                                                <?= htmlspecialchars($cat['name']) ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($cat['slug']) ?></div>
                                        </div>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $cat['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= ucfirst(htmlspecialchars($cat['status'])) ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <div class="text-sm text-gray-500 text-center">
                                                <?= $cat['show_on_homepage'] ? 
                                                    '<svg class="h-5 w-5 text-green-500 inline-block" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>' : 
                                                    '<svg class="h-5 w-5 text-gray-400 inline-block" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>' ?>
                                            </div>
                                        </div>
                                        <div class="flex space-x-2">
                                            <a href="categories.php?action=edit&id=<?= (int)$cat['id'] ?>" class="text-primary-600 hover:text-primary-900 p-1" title="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </a>
                                            <button onclick="confirmDelete(<?= (int)$cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>')" class="text-red-600 hover:text-red-900 p-1" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                    <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z" />
                                                    <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z" />
                                                </svg>
                                            </button>
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slug</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">On Homepage</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($categories)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a2 2 0 012 2v5a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm0 0v11m0-11h11m-11 0v11" />
                                        </svg>
                                        <p class="mt-2 text-sm">No categories found.</p>
                                    </td>
                                </tr>
                                <?php else: foreach ($categories as $cat): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($cat['name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($cat['slug']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $cat['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>"><?= ucfirst(htmlspecialchars($cat['status'])) ?></span></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                            <?= $cat['show_on_homepage'] ? '<svg class="h-5 w-5 text-green-500 inline-block" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>' : '<svg class="h-5 w-5 text-gray-400 inline-block" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>' ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-3">
                                                <a href="categories.php?action=edit&id=<?= (int)$cat['id'] ?>" class="text-primary-600 hover:text-primary-900" title="Edit">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </a>
                                                <button onclick="confirmDelete(<?= (int)$cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>')" class="text-red-600 hover:text-red-900" title="Delete">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z" />
                                                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">Showing <?= ($page - 1) * $per_page + 1 ?> to <?= min($page * $per_page, $total_count) ?> of <?= $total_count ?> results</div>
                            <div class="flex space-x-1"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>" class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded">Previous</a><?php endif; ?><?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?><a href="?page=<?= $i ?>" class="px-3 py-2 text-sm border <?= $i === $page ? 'bg-primary-600 border-primary-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' ?> rounded"><?= $i ?></a><?php endfor; ?><?php if ($page < $total_pages): ?><a href="?page=<?= $page + 1 ?>" class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded">Next</a><?php endif; ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex items-center justify-center">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Confirm Deletion</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to delete the category "<span id="categoryName" class="font-medium"></span>"? This action cannot be undone.</p>
            <div class="flex justify-end space-x-3">
                <button onclick="closeDeleteModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">Cancel</button>
                <form id="deleteForm" method="POST" action="categories.php" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="category_id" id="deleteCategoryId">
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>

        document.addEventListener('DOMContentLoaded', function() {
    const submitBtn = document.getElementById('submit-category-btn');
    const cancelBtn = document.getElementById('cancel-edit-btn');

    if (submitBtn) {
        submitBtn.addEventListener('click', function(event) {
            const form = submitBtn.closest('form');
            if (form && !form.checkValidity()) {
                // Stop if the form is invalid, allowing native browser validation to appear.
                return;
            }
            submitBtn.classList.add('loading');
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function(event) {
            cancelBtn.classList.add('loading');
        });
    }
});

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

        // --- Form Toggle Script ---
        const toggleBtn = document.getElementById('toggleFormBtn');
        const categoryForm = document.getElementById('categoryForm');
        const btnText = document.getElementById('btnText');
        const addIcon = document.getElementById('addIcon');
        const closeIcon = document.getElementById('closeIcon');

        // If the form is visible on page load (for editing), set the button state to "Close"
        if (!categoryForm.classList.contains('hidden')) {
            btnText.textContent = 'Close Form';
            addIcon.classList.add('hidden');
            closeIcon.classList.remove('hidden');
        }

        toggleBtn.addEventListener('click', () => {
            categoryForm.classList.toggle('hidden');
            const isHidden = categoryForm.classList.contains('hidden');

            if (isHidden) {
                btnText.textContent = 'Add New Category';
                addIcon.classList.remove('hidden');
                closeIcon.classList.add('hidden');
                // If we were editing, a click should cancel it and clear the URL
                if (new URLSearchParams(window.location.search).has('action')) {
                    window.location.href = 'categories.php';
                }
            } else {
                btnText.textContent = 'Close Form';
                addIcon.classList.add('hidden');
                closeIcon.classList.remove('hidden');
            }
        });

        // --- Delete Modal Script ---
        function confirmDelete(categoryId, categoryName) {
            document.getElementById('deleteCategoryId').value = categoryId;
            document.getElementById('categoryName').textContent = categoryName;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        // --- File Removal Function ---
        function removeIcon() {
            document.getElementById('remove_icon').value = '1';
            document.querySelector('#categoryForm [for="icon_upload"]').nextElementSibling.remove();
            document.querySelector('#categoryForm [for="icon_upload"]').nextElementSibling.remove();
        }
    </script>
</body>

</html>