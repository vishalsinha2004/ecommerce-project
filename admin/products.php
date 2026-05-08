<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
// admin/products.php
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';

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
$products = [];
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$search = trim($_GET['search'] ?? '');
$category_filter = trim($_GET['category'] ?? '');

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle product deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        if ($_POST['action'] === 'delete' && isset($_POST['product_id'])) {
            $product_id = (int)$_POST['product_id'];

            try {
                // Get product details for image deletion
                $product = $db->fetchRow(
                    "SELECT name, image, gallery FROM " . DB_PREFIX . "products WHERE id = ?",
                    [$product_id]
                );

                if ($product) {
                    // Delete product from database
                    $result = $db->delete(
                        'products',
                        'id = ?',
                        [$product_id]
                    );

                    if ($result !== false) {
                        $success_message = 'Product deleted successfully.';

                        // Delete main image if exists
                        if (!empty($product['image'])) {
                            $image_path = '../assets/images/' . $product['image'];
                            if (file_exists($image_path)) {
                                unlink($image_path);
                            }
                        }

                        // Delete gallery images if exist
                        if (!empty($product['gallery'])) {
                            $gallery_images = explode(',', $product['gallery']);
                            foreach ($gallery_images as $image) {
                                $image = trim($image);
                                if (!empty($image)) {
                                    $gallery_path = '../assets/images/' . $image;
                                    if (file_exists($gallery_path)) {
                                        unlink($gallery_path);
                                    }
                                }
                            }
                        }

                        // Log admin action
                        $log_message = date('Y-m-d H:i:s') . " - ADMIN ACTION: User {$_SESSION['user_id']} deleted product {$product_id} ({$product['name']}) and its images" . PHP_EOL;
                        file_put_contents(LOGS_PATH . '/app.log', $log_message, FILE_APPEND | LOCK_EX);
                    } else {
                        $error_message = 'Failed to delete product.';
                    }
                } else {
                    $error_message = 'Product not found.';
                }
            } catch (Exception $e) {
                error_log("Product deletion error: " . $e->getMessage());
                $error_message = 'An error occurred while deleting the product.';
            }
        }
    }
}

// Build query conditions
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)";
    $search_param = '%' . $search . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

if (!empty($category_filter)) {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $category_filter;
}

$where_clause = empty($where_conditions) ? "1" : implode(' AND ', $where_conditions);

try {
    // Get total count for pagination - use count() method instead of fetchOne()
    $total_count = $db->count(
        "products p LEFT JOIN " . DB_PREFIX . "categories c ON p.category_id = c.id",
        $where_clause,
        $params
    );

    $total_pages = ceil($total_count / $per_page);

    // Get products with pagination
    $offset = ($page - 1) * $per_page;
    $products = $db->fetchAll(
        "SELECT p.*, c.name as category_name FROM " . DB_PREFIX . "products p 
         LEFT JOIN " . DB_PREFIX . "categories c ON p.category_id = c.id 
         WHERE {$where_clause}
         ORDER BY p.created_at DESC 
         LIMIT {$offset}, {$per_page}",
        $params
    );

    // Get categories for filter
    $categories = $db->fetchAll(
        "SELECT id, name FROM " . DB_PREFIX . "categories 
         ORDER BY name"
    );
} catch (Exception $e) {
    error_log("Products fetch error: " . $e->getMessage());
    $error_message = 'Failed to load products.';
    $products = [];
    $categories = [];
    $total_pages = 0;
}

$page_title = 'Product Management - Admin Panel';
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
                <a href="products.php" class="flex bg-primary-50 border-r-4 border-primary-500 text-primary-700 block px-4 py-2 text-sm font-medium">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                <a href="promotions.php" class="text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                    <svg class="h-5 w-5 mr-3 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Product Management</h1>
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
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.293 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm"><?= htmlspecialchars($error_message) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Add New Product Button -->
            <div class="mb-6">
                <a href="add_product.php" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg font-medium transition duration-200 inline-flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="mr-2" viewBox="0 0 16 16">
                        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z" />
                    </svg>
                    Add New Product
                </a>
            </div>

            <!-- Filters -->
            <div class="bg-white shadow-sm rounded-lg mb-6">
                <div class="p-4 sm:p-6">
                    <form method="GET" class="space-y-4 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Search products..."
                                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <select id="category" name="category"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['id']) ?>"
                                        <?= $category_filter === $cat['id'] ? 'selected' : '' ?>>
                                        <?= ucfirst(htmlspecialchars($cat['name'] ?? 'Uncategorized')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end space-x-2">
                            <button type="submit" class="flex-1 lg:flex-none bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-indigo-700">
                                <svg class="inline-block w-4 h-4 mr-1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor">
                                    <path d="M3.9 54.9C10.5 40.9 24.5 32 40 32H472c15.5 0 29.5 8.9 36.1 22.9s4.6 30.5-5.2 42.5L320 320.9V448c0 12.1-6.8 23.2-17.7 28.6s-23.8 4.3-33.5-3l-64-48c-8.1-6-12.8-15.5-12.8-25.6V320.9L9 97.3C-.7 85.4-2.8 68.8 3.9 54.9z" />
                                </svg>
                                Filter
                            </button>
                            <a href="products.php" class="flex-1 lg:flex-none bg-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm font-medium hover:bg-gray-400 text-center">
                                Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Products Table -->
            <div class="bg-white shadow-sm rounded-lg">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-medium text-gray-900">
                        Products (<?= number_format($total_count) ?> total)
                    </h2>
                </div>

                <!-- Mobile Card View -->
                <div class="block lg:hidden">
                    <?php if (empty($products)): ?>
                        <div class="p-8 text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-2.5 5H21M9 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM20.5 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"></path>
                            </svg>
                            <p>No products found</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 p-4">
                            <?php foreach ($products as $product): ?>
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center mb-3">
                                        <?php if ($product['image']): ?>
                                            <img class="h-12 w-12 rounded-lg object-cover mr-3"
                                                src="../assets/images/<?= htmlspecialchars($product['image']) ?>"
                                                alt="<?= htmlspecialchars($product['name']) ?>">
                                        <?php else: ?>
                                            <div class="h-12 w-12 rounded-lg bg-gray-200 flex items-center justify-center mr-3">
                                                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium text-gray-900 truncate">
                                                <?= htmlspecialchars($product['name']) ?>
                                            </div>
                                            <div class="text-sm text-gray-500">ID: <?= $product['id'] ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-2 mb-3">
                                        <div>
                                            <div class="text-xs text-gray-500">Category</div>
                                            <div class="text-sm font-medium">
                                                <?= ucfirst(htmlspecialchars($product['category_name'] ?? 'Uncategorized')) ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Price</div>
                                            <div class="text-sm font-medium">
                                                ₹<?= number_format($product['price'], 2) ?>
                                                <?php if ($product['sale_price'] && $product['sale_price'] < $product['price']): ?>
                                                    <span class="text-green-600 ml-1">
                                                        (Sale: ₹<?= number_format($product['sale_price'], 2) ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Stock</div>
                                            <div class="text-sm font-medium <?= $product['stock_quantity'] <= 5 ? 'text-red-600' : '' ?>">
                                                <?= number_format($product['stock_quantity']) ?>
                                                <?php if ($product['stock_quantity'] <= 5): ?>
                                                    <span class="text-xs">(Low)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Status</div>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full
                                                <?= $product['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= ucfirst(htmlspecialchars($product['status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="flex justify-start space-x-2">
                                        <a href="edit_product.php?id=<?= $product['id'] ?>"
                                            class="text-indigo-600 hover:text-indigo-900 p-1" title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        <a href="../products/product_detail.php?id=<?= $product['id'] ?>"
                                            class="text-blue-600 hover:text-blue-900 p-1" title="View" target="_blank">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7"></path>
                                            </svg>
                                        </a>
                                        <?php if ($product['status'] === 'active'): ?>
                                            <button onclick="confirmDelete(<?= $product['id'] ?>, '<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>')"
                                                class="text-red-600 hover:text-red-900 p-1" title="Delete">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        <?php endif; ?>
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-2.5 5H21M9 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM20.5 19.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"></path>
                                        </svg>
                                        <p>No products found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-12 w-12">
                                                    <?php if ($product['image']): ?>
                                                        <img class="h-12 w-12 rounded-lg object-cover"
                                                            src="../assets/images/<?= htmlspecialchars($product['image']) ?>"
                                                            alt="<?= htmlspecialchars($product['name']) ?>">
                                                    <?php else: ?>
                                                        <div class="h-12 w-12 rounded-lg bg-gray-200 flex items-center justify-center">
                                                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                            </svg>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($product['name']) ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        ID: <?= $product['id'] ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                                <?= ucfirst(htmlspecialchars($product['category_name'] ?? 'Uncategorized')) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                ₹<?= number_format($product['price'], 2) ?>
                                            </div>
                                            <?php if ($product['sale_price'] && $product['sale_price'] < $product['price']): ?>
                                                <div class="text-sm text-green-600">
                                                    Sale: ₹<?= number_format($product['sale_price'], 2) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900 <?= $product['stock_quantity'] <= 5 ? 'text-red-600' : '' ?>">
                                                <?= number_format($product['stock_quantity']) ?>
                                            </div>
                                            <?php if ($product['stock_quantity'] <= 5): ?>
                                                <div class="text-sm text-red-600">
                                                    Low Stock
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full
                                                <?= $product['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= ucfirst(htmlspecialchars($product['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="edit_product.php?id=<?= $product['id'] ?>"
                                                    class="text-indigo-600 hover:text-indigo-900" title="Edit">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </a>
                                                <a href="../products/product_detail.php?id=<?= $product['id'] ?>"
                                                    class="text-blue-600 hover:text-blue-900" title="View" target="_blank">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                </a>
                                                <?php if ($product['status'] === 'active'): ?>
                                                    <button onclick="confirmDelete(<?= $product['id'] ?>, '<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>')"
                                                        class="text-red-600 hover:text-red-900" title="Delete">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                    </button>
                                                <?php endif; ?>
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
                                    <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category_filter ? '&category=' . urlencode($category_filter) : '' ?>"
                                        class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded">
                                        Previous
                                    </a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category_filter ? '&category=' . urlencode($category_filter) : '' ?>"
                                        class="px-3 py-2 text-sm border <?= $i === $page ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' ?> rounded">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category_filter ? '&category=' . urlencode($category_filter) : '' ?>"
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

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Confirm Deletion</h3>
            <p class="text-gray-600 mb-4">Are you sure you want to delete "<span id="productName" class="font-medium"></span>"? This action will permanently remove the product and all its images.</p>
            <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-3">
                <button onclick="closeDeleteModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 order-2 sm:order-1">
                    Cancel
                </button>
                <form id="deleteForm" method="POST" class="inline order-1 sm:order-2">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" id="deleteProductId">
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700">
                        Delete
                    </button>
                </form>
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

        // Delete modal functions
        function confirmDelete(productId, productName) {
            document.getElementById('deleteProductId').value = productId;
            document.getElementById('productName').textContent = productName;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
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