<?php
/**
 * Admin Panel Utility Functions
 */

require_once 'admin_auth.php';

/**
 * Get dashboard statistics
 * @return array
 */
function getDashboardStats() {
    global $db;
    
    $stats = [];
    
    // Total orders
    $stats['total_orders'] = $db->count('orders');
    
    // Total revenue
    $revenue = $db->fetchRow("SELECT SUM(total_amount) as total FROM " . DB_PREFIX . "orders WHERE status IN ('completed', 'shipped', 'delivered')");
    $stats['total_revenue'] = $revenue['total'] ?? 0;
    
    // Total products
    $stats['total_products'] = $db->count('products', "status = 'active'");
    
    // Total users
    $stats['total_users'] = $db->count('users', "status = 'active'");
    
    // Pending orders
    $stats['pending_orders'] = $db->count('orders', "status = 'pending'");
    
    // Low stock products
    $stats['low_stock'] = $db->count('products', "stock_quantity <= 5 AND status = 'active'");
    
    // Recent orders (last 7 days)
    $recent_orders = $db->fetchRow("SELECT COUNT(*) as count FROM " . DB_PREFIX . "orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['recent_orders'] = $recent_orders['count'] ?? 0;
    
    // Monthly sales data for charts
    $monthly_sales = $db->fetchAll("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as orders,
            SUM(total_amount) as revenue
        FROM " . DB_PREFIX . "orders 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        AND status IN ('completed', 'shipped', 'delivered')
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stats['monthly_sales'] = $monthly_sales;
    
    return $stats;
}

/**
 * Get top selling products
 * @param int $limit
 * @return array
 */
function getTopProducts($limit = 5) {
    global $db;
    
    return $db->fetchAll("
        SELECT 
            p.id,
            p.name,
            p.image,
            p.price,
            COUNT(c.product_id) as total_in_carts,
            p.stock_quantity
        FROM " . DB_PREFIX . "products p
        LEFT JOIN " . DB_PREFIX . "cart c ON p.id = c.product_id
        WHERE p.status = 'active'
        GROUP BY p.id
        ORDER BY total_in_carts DESC, p.created_at DESC
        LIMIT ?
    ", [$limit]);
}

/**
 * Get recent orders
 * @param int $limit
 * @return array
 */
function getRecentOrders($limit = 10) {
    global $db;
    
    return $db->fetchAll("
        SELECT 
            o.*,
            u.first_name,
            u.last_name,
            u.email
        FROM " . DB_PREFIX . "orders o
        LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
        LIMIT ?
    ", [$limit]);
}

/**
 * Handle file upload with validation
 * @param array $file $_FILES array element
 * @param string $upload_dir
 * @param array $allowed_types
 * @return array
 */
function handleFileUpload($file, $upload_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp']) {
    $result = ['success' => false, 'message' => '', 'filename' => ''];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['message'] = 'Upload failed with error code: ' . $file['error'];
        return $result;
    }
    
    // Check file size (5MB limit)
    if ($file['size'] > MAX_FILE_SIZE) {
        $result['message'] = 'File too large. Maximum size is ' . formatFileSize(MAX_FILE_SIZE);
        return $result;
    }
    
    // Get file extension
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Check file type
    if (!in_array($file_ext, $allowed_types)) {
        $result['message'] = 'Invalid file type. Allowed: ' . implode(', ', $allowed_types);
        return $result;
    }
    
    // Verify it's actually an image
    if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $image_info = getimagesize($file['tmp_name']);
        if ($image_info === false) {
            $result['message'] = 'Invalid image file';
            return $result;
        }
    }
    
    // Generate unique filename
    $filename = uniqid() . '.' . $file_ext;
    $filepath = $upload_dir . '/' . $filename;
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        $result['success'] = true;
        $result['filename'] = $filename;
        $result['message'] = 'File uploaded successfully';
    } else {
        $result['message'] = 'Failed to move uploaded file';
    }
    
    return $result;
}

/**
 * Delete file safely
 * @param string $filepath
 * @return bool
 */
function deleteFile($filepath) {
    if (file_exists($filepath) && is_file($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Generate pagination HTML
 * @param int $current_page
 * @param int $total_pages
 * @param string $base_url
 * @return string
 */
function generatePagination($current_page, $total_pages, $base_url) {
    if ($total_pages <= 1) {
        return '';
    }
    
    $html = '<nav class="flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-6">';
    $html .= '<div class="flex flex-1 justify-between sm:hidden">';
    
    // Previous button (mobile)
    if ($current_page > 1) {
        $prev_url = $base_url . '?page=' . ($current_page - 1);
        $html .= '<a href="' . htmlspecialchars($prev_url) . '" class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Previous</a>';
    }
    
    // Next button (mobile)
    if ($current_page < $total_pages) {
        $next_url = $base_url . '?page=' . ($current_page + 1);
        $html .= '<a href="' . htmlspecialchars($next_url) . '" class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Next</a>';
    }
    
    $html .= '</div>';
    $html .= '<div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">';
    $html .= '<div><p class="text-sm text-gray-700">Showing page ' . $current_page . ' of ' . $total_pages . '</p></div>';
    $html .= '<div><span class="isolate inline-flex rounded-md shadow-sm">';
    
    // Desktop pagination
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        $url = $base_url . '?page=' . $i;
        $active_class = ($i == $current_page) ? 'z-10 bg-indigo-600 text-white' : 'bg-white text-gray-900 hover:bg-gray-50';
        $html .= '<a href="' . htmlspecialchars($url) . '" class="relative inline-flex items-center px-4 py-2 text-sm font-semibold ' . $active_class . ' ring-1 ring-inset ring-gray-300">' . $i . '</a>';
    }
    
    $html .= '</span></div></div></nav>';
    
    return $html;
}

/**
 * Format order status for display
 * @param string $status
 * @return string
 */
function formatOrderStatus($status) {
    $statuses = [
        'pending' => '<span class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800">Pending</span>',
        'processing' => '<span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">Processing</span>',
        'shipped' => '<span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">Shipped</span>',
        'delivered' => '<span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Delivered</span>',
        'cancelled' => '<span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">Cancelled</span>',
        'refunded' => '<span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">Refunded</span>'
    ];
    
    return $statuses[$status] ?? ucfirst($status);
}

/**
 * Format user status for display
 * @param string $status
 * @return string
 */
function formatUserStatus($status) {
    $statuses = [
        'active' => '<span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Active</span>',
        'inactive' => '<span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">Inactive</span>',
        'banned' => '<span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">Banned</span>'
    ];
    
    return $statuses[$status] ?? ucfirst($status);
}
?>
