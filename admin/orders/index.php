<?php
require_once '../includes/admin_auth.php';
require_once '../includes/admin_functions.php';

// Require admin login
requireAdminLogin();

// Handle search and filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(o.order_number LIKE ? OR o.id LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($status_filter)) {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total 
                FROM " . DB_PREFIX . "orders o 
                LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
                " . $where_clause;
$total_result = $db->fetchRow($count_query, $params);
$total_orders = $total_result['total'];
$total_pages = ceil($total_orders / $per_page);

// Get orders
$offset = ($page - 1) * $per_page;
$orders_query = "SELECT o.*, 
                        u.first_name, u.last_name, u.email,
                        COUNT(oi.id) as item_count
                 FROM " . DB_PREFIX . "orders o 
                 LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
                 LEFT JOIN " . DB_PREFIX . "order_items oi ON o.id = oi.order_id
                 " . $where_clause . " 
                 GROUP BY o.id
                 ORDER BY o.created_at DESC 
                 LIMIT {$offset}, {$per_page}";

$orders = $db->fetchAll($orders_query, $params);

$page_title = 'Orders';
include '../includes/admin_header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Orders</h1>
            <p class="text-gray-600">Manage customer orders and fulfillment</p>
        </div>
        <div class="flex space-x-2">
            <button onclick="exportOrders()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                Export CSV
            </button>
        </div>
    </div>

    <!-- Search and Filters -->
    <div class="bg-white shadow rounded-lg p-6">
        <form method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Order ID, customer name, email..." 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="processing" <?php echo ($status_filter === 'processing') ? 'selected' : ''; ?>>Processing</option>
                    <option value="shipped" <?php echo ($status_filter === 'shipped') ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo ($status_filter === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo ($status_filter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="refunded" <?php echo ($status_filter === 'refunded') ? 'selected' : ''; ?>>Refunded</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
            
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-primary hover:bg-primary-dark text-white px-4 py-2 rounded-md text-sm font-medium">
                    Filter
                </button>
                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-md text-sm font-medium">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="bg-white shadow overflow-hidden sm:rounded-md">
        <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
            <h3 class="text-lg leading-6 font-medium text-gray-900">
                Orders (<?php echo number_format($total_orders); ?>)
            </h3>
        </div>
        
        <?php if (empty($orders)): ?>
        <div class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No orders found</h3>
            <p class="mt-1 text-sm text-gray-500">No orders match your current filters.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($orders as $order): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div>
                                <div class="text-sm font-medium text-gray-900">
                                    #<?php echo htmlspecialchars($order['order_number'] ?? $order['id']); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo $order['item_count']; ?> items
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div>
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo htmlspecialchars($order['email']); ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php echo formatOrderStatus($order['status']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            ₹<?php echo number_format($order['total_amount'], 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="view.php?id=<?php echo $order['id']; ?>" 
                                   class="text-indigo-600 hover:text-indigo-900">View</a>
                                <select onchange="updateOrderStatus(<?php echo $order['id']; ?>, this.value)" 
                                        class="text-xs border-gray-300 rounded focus:border-primary focus:ring-primary">
                                    <option value="">Change Status</option>
                                    <option value="pending" <?php echo ($order['status'] === 'pending') ? 'disabled' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo ($order['status'] === 'processing') ? 'disabled' : ''; ?>>Processing</option>
                                    <option value="shipped" <?php echo ($order['status'] === 'shipped') ? 'disabled' : ''; ?>>Shipped</option>
                                    <option value="delivered" <?php echo ($order['status'] === 'delivered') ? 'disabled' : ''; ?>>Delivered</option>
                                    <option value="cancelled" <?php echo ($order['status'] === 'cancelled') ? 'disabled' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php echo generatePagination($page, $total_pages, 'index.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); ?>
        <?php endif; ?>
    </div>
</div>

<script>
function updateOrderStatus(orderId, newStatus) {
    if (!newStatus || !confirm('Are you sure you want to change the order status?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('status', newStatus);
    formData.append('csrf_token', '<?php echo $csrf_token; ?>');
    
    fetch('update-status.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating the order status.');
    });
}

function exportOrders() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'index.php?' + params.toString();
}

// Handle CSV export
<?php
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Order ID', 'Order Number', 'Customer Name', 'Email', 
        'Status', 'Total Amount', 'Payment Method', 'Date'
    ]);
    
    // Get all orders for export
    $export_orders = $db->fetchAll("
        SELECT o.*, u.first_name, u.last_name, u.email
        FROM " . DB_PREFIX . "orders o 
        LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.id 
        " . $where_clause . " 
        ORDER BY o.created_at DESC
    ", $params);
    
    foreach ($export_orders as $order) {
        fputcsv($output, [
            $order['id'],
            $order['order_number'] ?? $order['id'],
            $order['first_name'] . ' ' . $order['last_name'],
            $order['email'],
            ucfirst($order['status']),
            $order['total_amount'],
            $order['payment_method'] ?? 'N/A',
            date('Y-m-d H:i:s', strtotime($order['created_at']))
        ]);
    }
    
    fclose($output);
    exit;
}
?>
</script>

<?php include '../includes/admin_footer.php'; ?>