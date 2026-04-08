<?php
require_once 'admin_auth.php';
$current_admin = getCurrentAdmin();
$csrf_token = generateAdminCSRF();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Admin Panel'; ?> - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .table-fixed { table-layout: fixed; }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#6366f1',
                        'primary-dark': '#4f46e5'
                    }
                }
            }
        }
    </script>
</head>
<body class="h-full">
    <div class="min-h-full">
        <!-- Navigation -->
        <nav class="bg-white shadow-sm border-b border-gray-200">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <div class="flex flex-shrink-0 items-center">
                            <h1 class="text-xl font-bold text-gray-900"><?php echo SITE_NAME; ?> Admin</h1>
                        </div>
                        <div class="hidden sm:-my-px sm:ml-6 sm:flex sm:space-x-8">
                            <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" 
                               class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'border-primary text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'; ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium">
                                Dashboard
                            </a>
                            <a href="<?php echo BASE_URL; ?>/admin/products/index.php" 
                               class="<?php echo (strpos($_SERVER['REQUEST_URI'], '/products/') !== false) ? 'border-primary text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'; ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium">
                                Products
                            </a>
                            <a href="<?php echo BASE_URL; ?>/admin/orders/index.php" 
                               class="<?php echo (strpos($_SERVER['REQUEST_URI'], '/orders/') !== false) ? 'border-primary text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'; ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium">
                                Orders
                            </a>
                            <a href="<?php echo BASE_URL; ?>/admin/users/index.php" 
                               class="<?php echo (strpos($_SERVER['REQUEST_URI'], '/users/') !== false) ? 'border-primary text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'; ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium">
                                Users
                            </a>
                            <?php if (hasAdminAccess('admin')): ?>
                            <a href="<?php echo BASE_URL; ?>/admin/settings/index.php" 
                               class="<?php echo (strpos($_SERVER['REQUEST_URI'], '/settings/') !== false) ? 'border-primary text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'; ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium">
                                Settings
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="hidden sm:ml-6 sm:flex sm:items-center">
                        <!-- Admin dropdown -->
                        <div class="relative ml-3">
                            <div>
                                <button type="button" class="relative flex rounded-full bg-white text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2" id="user-menu-button" onclick="toggleDropdown()">
                                    <span class="sr-only">Open user menu</span>
                                    <div class="h-8 w-8 rounded-full bg-primary flex items-center justify-center">
                                        <span class="text-sm font-medium text-white"><?php echo strtoupper(substr($current_admin['first_name'], 0, 1)); ?></span>
                                    </div>
                                    <div class="ml-3 text-left">
                                        <p class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($current_admin['first_name'] . ' ' . $current_admin['last_name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo ucfirst($current_admin['role']); ?></p>
                                    </div>
                                </button>
                            </div>
                            <div class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none hidden" role="menu" id="dropdown-menu">
                                <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Sign out</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <main class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Flash Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                <div class="mb-4 rounded-md bg-green-50 p-4">
                    <div class="text-sm text-green-700"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="mb-4 rounded-md bg-red-50 p-4">
                    <div class="text-sm text-red-700"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
                </div>
                <?php endif; ?>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('dropdown-menu');
    dropdown.classList.toggle('hidden');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const button = document.getElementById('user-menu-button');
    const dropdown = document.getElementById('dropdown-menu');
    
    if (!button.contains(event.target)) {
        dropdown.classList.add('hidden');
    }
});
</script>
