<?php
// admin/dashboard.php
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

// Fetch dashboard statistics
try {
    // Basic KPIs
    $stats = [
        'total_orders' => $db->count('orders'),
        'pending_orders' => $db->count('orders', "status IN ('pending', 'processing')"),
        'total_products' => $db->count('products', "status = 'active'"),
        'total_users' => $db->count('users', "role = 'user'")
    ];

    // Recent orders
    $recent_orders = $db->fetchAll(
        "SELECT o.id, o.order_number, o.total_amount, o.status, o.created_at, 
                u.first_name, u.last_name, u.email
         FROM " . DB_PREFIX . "orders o 
         JOIN " . DB_PREFIX . "users u ON o.user_id = u.id
         ORDER BY o.created_at DESC LIMIT 5"
    );

    // Monthly revenue (current month)
    $monthly_revenue = $db->fetchRow(
        "SELECT COALESCE(SUM(total_amount), 0) as revenue 
         FROM " . DB_PREFIX . "orders 
         WHERE MONTH(created_at) = MONTH(NOW()) 
         AND YEAR(created_at) = YEAR(NOW())
         AND status NOT IN ('cancelled', 'refunded')"
    );

    // ANALYTICS DATA

    // 1. Sales Overview - Last 30 days revenue
    $sales_overview = $db->fetchAll(
        "SELECT DATE(created_at) as date, 
                COALESCE(SUM(total_amount), 0) as revenue
         FROM " . DB_PREFIX . "orders 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         AND payment_status = 'paid'
         GROUP BY DATE(created_at)
         ORDER BY date ASC"
    );

    // 2. Top 5 Best Selling Products
    // 2. Top 5 Best Selling Products
    $best_selling_products = $db->fetchAll(
        "SELECT p.id, p.name, p.price, 
        COUNT(DISTINCT oi.order_id) as order_count,
        COALESCE(SUM(oi.quantity), 0) as total_quantity
     FROM " . DB_PREFIX . "products p
     LEFT JOIN " . DB_PREFIX . "order_items oi ON p.id = oi.product_id
     LEFT JOIN " . DB_PREFIX . "orders o ON oi.order_id = o.id
     WHERE p.status = 'active'
     AND o.status NOT IN ('cancelled', 'refunded', 'return_requested', 'return_confirmed', 'request_rejected')
     GROUP BY p.id, p.name, p.price
     ORDER BY total_quantity DESC, order_count DESC
     LIMIT 5"
    );

    // 3. New Users Registered - Last 8 weeks
    $new_users_data = $db->fetchAll(
        "SELECT YEAR(created_at) as year,
                WEEK(created_at, 1) as week,
                DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY) as week_start,
                COUNT(*) as user_count
         FROM " . DB_PREFIX . "users 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
         AND role = 'user'
         GROUP BY YEAR(created_at), WEEK(created_at, 1)
         ORDER BY year, week"
    );

    // 4. Order Status Distribution
    $order_status_data = $db->fetchAll(
        "SELECT status, COUNT(*) as count
         FROM " . DB_PREFIX . "orders
         GROUP BY status
         ORDER BY count DESC"
    );

    // 5. Wishlist vs Cart Comparison
    $wishlist_count = $db->count('wishlist');
    $cart_count = $db->count('cart');
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stats = ['total_orders' => 0, 'pending_orders' => 0, 'total_products' => 0, 'total_users' => 0];
    $recent_orders = [];
    $monthly_revenue = ['revenue' => 0];
    $sales_overview = [];
    $best_selling_products = [];
    $new_users_data = [];
    $order_status_data = [];
    $wishlist_count = 0;
    $cart_count = 0;
}

$page_title = 'Admin Dashboard - ' . SITE_NAME;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
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
</head>

<body class="bg-gray-50">
    <div class="lg:hidden fixed top-4 left-4 z-50">
        <button id="mobile-menu-toggle" class="p-2 bg-white rounded-md shadow-md">
            <svg class="h-6 w-6 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </div>

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
        <div id="sidebar" class="w-64 bg-white shadow-lg h-screen fixed lg:relative transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-40">
            <nav class="mt-8">
                <div class="px-4 py-2">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Management</h3>
                </div>
                <a href="dashboard.php" class="flex bg-primary-50 border-r-4 border-primary-500 text-primary-700 block px-4 py-2 text-sm font-medium">
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
                <a href="promotions.php" class="text-gray-700 hover:bg-gray-50 block px-4 py-2 text-sm font-medium">
                    <svg class="inline-block h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    Promotions
                </a>
            </nav>
        </div>

        <div id="sidebar-overlay" class="fixed inset-0 bg-black opacity-50 z-30 lg:hidden hidden"></div>

        <div class="flex-1 p-4 lg:p-8 min-w-0">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                    <div class="p-4 lg:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Orders</p>
                                <p class="text-2xl lg:text-3xl font-bold text-gray-900"><?= number_format($stats['total_orders']) ?></p>
                            </div>
                            <div class="p-3 bg-blue-100 rounded-full">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                    <div class="p-4 lg:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Pending Orders</p>
                                <p class="text-2xl lg:text-3xl font-bold text-gray-900"><?= number_format($stats['pending_orders']) ?></p>
                            </div>
                            <div class="p-3 bg-yellow-100 rounded-full">
                                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                    <div class="p-4 lg:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Products</p>
                                <p class="text-2xl lg:text-3xl font-bold text-gray-900"><?= number_format($stats['total_products']) ?></p>
                            </div>
                            <div class="p-3 bg-green-100 rounded-full">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                    <div class="p-4 lg:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Users</p>
                                <p class="text-2xl lg:text-3xl font-bold text-gray-900"><?= number_format($stats['total_users']) ?></p>
                            </div>
                            <div class="p-3 bg-purple-100 rounded-full">
                                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-6 lg:mt-8">
                <div class="bg-white shadow-sm rounded-lg p-4 lg:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">This Month's Revenue</h3>
                            <p class="text-3xl font-bold text-green-600 mt-2">
                                ₹<?= number_format($monthly_revenue['revenue'], 2) ?>
                            </p>
                        </div>
                        <div class="p-4 bg-green-100 rounded-full">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke="#71bf31" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h3m5 0h-5m5 3h-2m-6.003 0H14m-3-3c1 0 3 .6 3 3m-1 7-5.003-4H11c1 0 3-.6 3-3"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-6 lg:mb-8 mt-6 lg:mt-8">
                <h2 class="text-xl lg:text-2xl font-semibold text-gray-900 mb-4 lg:mb-6">Analytics Overview</h2>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-4 lg:mb-6">
                    <div class="bg-white p-4 lg:p-6 rounded-lg shadow-sm">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Sales Overview (Last 30 Days)</h3>
                        <div class="h-64 lg:h-80">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white p-4 lg:p-6 rounded-lg shadow-sm">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Top 5 Products Overview</h3>
                        <?php if (empty($best_selling_products)): ?>
                            <div class="h-64 lg:h-80 flex items-center justify-center">
                                <div class="text-center text-gray-500">
                                    <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                    <p class="text-sm">No products found</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="h-64 lg:h-80">
                                <canvas id="topProductsChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-4 lg:mb-6">
                    <div class="bg-white p-4 lg:p-6 rounded-lg shadow-sm">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">New Users (Last 8 Weeks)</h3>
                        <div class="h-64 lg:h-80">
                            <canvas id="newUsersChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white p-4 lg:p-6 rounded-lg shadow-sm">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Order Status Distribution</h3>
                        <div class="h-64 lg:h-80">
                            <canvas id="orderStatusChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6">
                    <div class="bg-white p-4 lg:p-6 rounded-lg shadow-sm">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Wishlist vs Cart Items</h3>
                        <div class="h-64 lg:h-80">
                            <canvas id="wishlistCartChart"></canvas>
                        </div>
                    </div>
                    <div class="bg-white p-4 lg:p-6 rounded-lg shadow-sm flex items-center justify-center">
                        <div class="text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            <p class="text-sm">Additional Analytics Coming Soon</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg">
                <div class="px-4 lg:px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-medium text-gray-900">Recent Orders</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order</th>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">Customer</th>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($recent_orders)): ?>
                                <tr>
                                    <td colspan="5" class="px-4 lg:px-6 py-4 text-center text-gray-500">No orders found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_orders as $order): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 lg:px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                #<?= htmlspecialchars($order['order_number']) ?>
                                            </div>
                                            <div class="text-xs text-gray-500 sm:hidden">
                                                <?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?>
                                            </div>
                                        </td>
                                        <td class="px-4 lg:px-6 py-4 whitespace-nowrap hidden sm:table-cell">
                                            <div class="text-sm text-gray-900">
                                                <?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?= htmlspecialchars($order['email']) ?>
                                            </div>
                                        </td>
                                        <td class="px-4 lg:px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                ₹<?= number_format($order['total_amount'], 2) ?>
                                            </div>
                                        </td>
                                        <td class="px-4 lg:px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full
                                                <?php
                                                switch ($order['status']) {
                                                    case 'pending':
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
                                                        echo 'bg-red-100 text-red-800';
                                                        break;
                                                    default:
                                                        echo 'bg-gray-100 text-gray-800';
                                                }
                                                ?>">
                                                <?= ucfirst(htmlspecialchars($order['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-sm text-gray-500 hidden md:table-cell">
                                            <?= date('M j, Y', strtotime($order['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 lg:px-6 py-3 bg-gray-50 border-t border-gray-200">
                    <a href="orders.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">
                        View All Orders →
                    </a>
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

        // Chart.js configurations and data

        // 1. Sales Overview Chart
        const salesData = <?= json_encode($sales_overview) ?>;
        const salesLabels = salesData.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric'
            });
        });
        const salesValues = salesData.map(item => parseFloat(item.revenue));

        const salesCtx = document.getElementById('salesChart').getContext('2d');
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: salesLabels,
                datasets: [{
                    label: 'Revenue (₹)',
                    data: salesValues,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Revenue: ₹' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // 2. Top Products Chart
        const topProductsData = <?= json_encode($best_selling_products) ?>;

        if (topProductsData && topProductsData.length > 0) {
            const productLabels = topProductsData.map(item => {
                // Truncate long product names
                return item.name.length > 15 ? item.name.substring(0, 15) + '...' : item.name;
            });

            // Use total_quantity for the chart values
            const productValues = topProductsData.map(item => parseInt(item.total_quantity) || 0);

            const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
            new Chart(topProductsCtx, {
                type: 'bar',
                data: {
                    labels: productLabels,
                    datasets: [{
                        label: 'Units Sold',
                        data: productValues,
                        backgroundColor: [
                            'rgba(59, 130, 246, 0.8)',
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(239, 68, 68, 0.8)',
                            'rgba(139, 92, 246, 0.8)'
                        ],
                        borderColor: [
                            'rgb(59, 130, 246)',
                            'rgb(16, 185, 129)',
                            'rgb(245, 158, 11)',
                            'rgb(239, 68, 68)',
                            'rgb(139, 92, 246)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Units Sold'
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Units Sold: ${context.parsed.y}`;
                                },
                                afterLabel: function(context) {
                                    const item = topProductsData[context.dataIndex];
                                    return `Product: ${item.name}\nPrice: ₹${parseFloat(item.price).toLocaleString()}\nOrders: ${item.order_count}`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            // If no data, hide the chart canvas and show message
            const chartContainer = document.getElementById('topProductsChart').parentNode;
            chartContainer.innerHTML = `
        <div class="h-64 lg:h-80 flex items-center justify-center">
            <div class="text-center text-gray-500">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <p class="text-sm">No product sales data available</p>
            </div>
        </div>
    `;
        }

        // 3. New Users Chart
        const newUsersData = <?= json_encode($new_users_data) ?>;
        const userLabels = newUsersData.map(item => {
            const date = new Date(item.week_start);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric'
            });
        });
        const userValues = newUsersData.map(item => parseInt(item.user_count));

        const newUsersCtx = document.getElementById('newUsersChart').getContext('2d');
        new Chart(newUsersCtx, {
            type: 'bar',
            data: {
                labels: userLabels,
                datasets: [{
                    label: 'New Users',
                    data: userValues,
                    backgroundColor: 'rgba(245, 158, 11, 0.8)',
                    borderColor: 'rgba(245, 158, 11, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // 4. Order Status Chart
        const orderStatusData = <?= json_encode($order_status_data) ?>;
        const statusLabels = orderStatusData.map(item => item.status.charAt(0).toUpperCase() + item.status.slice(1));
        const statusValues = orderStatusData.map(item => parseInt(item.count));

        const orderStatusCtx = document.getElementById('orderStatusChart').getContext('2d');
        new Chart(orderStatusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: [
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(107, 114, 128, 0.8)'
                    ],
                    borderColor: [
                        'rgb(245, 158, 11)',
                        'rgb(59, 130, 246)',
                        'rgb(139, 92, 246)',
                        'rgb(16, 185, 129)',
                        'rgb(239, 68, 68)',
                        'rgb(107, 114, 128)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // 5. Wishlist vs Cart Chart
        const wishlistCount = <?= json_encode($wishlist_count) ?>;
        const cartCount = <?= json_encode($cart_count) ?>;

        const wishlistCartCtx = document.getElementById('wishlistCartChart').getContext('2d');
        new Chart(wishlistCartCtx, {
            type: 'bar',
            data: {
                labels: ['Wishlist Items', 'Cart Items'],
                datasets: [{
                    label: 'Count',
                    data: [wishlistCount, cartCount],
                    backgroundColor: [
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(59, 130, 246, 0.8)'
                    ],
                    borderColor: [
                        'rgb(239, 68, 68)',
                        'rgb(59, 130, 246)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Auto-refresh dashboard every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>

</html>