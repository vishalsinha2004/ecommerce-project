<?php
require_once '../includes/admin_auth.php';
require_once '../includes/admin_functions.php';

// Require admin login
requireAdminLogin();

// Handle search and filters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$low_stock = isset($_GET['low_stock']) ? 1 : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ? OR p.tags LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($category)) {
    $where_conditions[] = "p.category = ?";
    $params[] = $category;
}

if (!empty($status_filter)) {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
}

if ($low_stock) {
    $where_conditions[] = "p.stock_quantity <= 5";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total FROM " . DB_PREFIX . "products p " . $where_clause;
$total_result = $db->fetchRow($count_query, $params);
$total_products = $total_result['total'];
$total_pages = ceil($total_products / $per_page);

// Get products
$offset = ($page - 1) * $per_page;
$products_query = "SELECT p.*, c.name as category_name 
                   FROM " . DB_PREFIX . "products p 
                   LEFT JOIN " . DB_PREFIX . "categories c ON p.category = c.slug 
                   " . $where_clause . " 
                   ORDER BY p.created_at DESC 
                   LIMIT {$offset}, {$per_page}";

$products = $db->fetchAll($products_query, $params);

// Get categories for filter
$categories = $db->fetchAll("SELECT * FROM " . DB_PREFIX . "categories WHERE status = 'active' ORDER BY name");

$page_title = 'Products';
include '../includes/admin_header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Products</h1>
            <p class="text-gray-600">Manage your product catalog</p>
        </div>
        <a href="add.php" class="bg-primary hover:bg-primary-dark text-white px-4 py-2 rounded-md text-sm font-medium">
            Add Product
        </a>
    </div>

    <!-- Search and Filters -->
    <div class="bg-white shadow rounded-lg p-6">
        <form method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Search products..." 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['slug']); ?>" 
                            <?php echo ($category === $cat['slug']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
                    <option value="">All Status</option>
                    <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="draft" <?php echo ($status_filter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                    <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
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

    <!-- Products Table -->
    <div class="bg-white shadow overflow-hidden sm:rounded-md">
        <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
            <h3 class="text-lg leading-6 font-medium text-gray-900">
                Products (<?php echo number_format($total_products); ?>)
            </h3>
        </div>
        
        <?php if (empty($products)): ?>
        <div class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No products found</h3>
            <p class="mt-1 text-sm text-gray-500">Get started by creating your first product.</p>
            <div class="mt-6">
                <a href="add.php" class="bg-primary hover:bg-primary-dark text-white px-4 py-2 rounded-md text-sm font-medium">
                    Add Product
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
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
                    <?php foreach ($products as $product): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-16 w-16">
                                    <img class="h-16 w-16 rounded-lg object-cover" 
                                         src="<?php echo IMAGES_URL . '/' . htmlspecialchars($product['image']); ?>" 
                                         alt="<?php echo htmlspecialchars($product['name']); ?>">
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        ID: <?php echo $product['id']; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($product['category_name'] ?? ucfirst($product['category'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php if ($product['sale_price'] && $product['sale_price'] < $product['price']): ?>
                                <div class="text-red-600 font-medium">₹<?php echo number_format($product['sale_price'], 2); ?></div>
                                <div class="text-gray-400 line-through text-xs">₹<?php echo number_format($product['price'], 2); ?></div>
                            <?php else: ?>
                                ₹<?php echo number_format($product['price'], 2); ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm <?php echo ($product['stock_quantity'] <= 5) ? 'text-red-600 font-medium' : 'text-gray-900'; ?>">
                                <?php echo $product['stock_quantity']; ?>
                                <?php if ($product['stock_quantity'] <= 5): ?>
                                    <span class="text-xs text-red-500">(Low)</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $status_classes = [
                                'active' => 'bg-green-100 text-green-800',
                                'draft' => 'bg-yellow-100 text-yellow-800',
                                'inactive' => 'bg-red-100 text-red-800'
                            ];
                            $class = $status_classes[$product['status']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $class; ?>">
                                <?php echo ucfirst($product['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $product['id']; ?>" 
                                   class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $product['id']; ?>" 
                                   target="_blank" class="text-green-600 hover:text-green-900">View</a>
                                <button onclick="deleteProduct(<?php echo $product['id']; ?>)" 
                                        class="text-red-600 hover:text-red-900">Delete</button>
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

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5C2.962 18.333 3.924 20 5.464 20z" />
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mt-4">Delete Product</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">Are you sure you want to delete this product? This action cannot be undone.</p>
            </div>
            <div class="flex gap-4 justify-center mt-4">
                <button id="confirmDelete" class="px-4 py-2 bg-red-600 text-white text-base font-medium rounded-md hover:bg-red-700">Delete</button>
                <button onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-300 text-gray-700 text-base font-medium rounded-md hover:bg-gray-400">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
let productToDelete = null;

function deleteProduct(productId) {
    productToDelete = productId;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    productToDelete = null;
    document.getElementById('deleteModal').classList.add('hidden');
}

document.getElementById('confirmDelete').addEventListener('click', function() {
    if (productToDelete) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';
        
        const productInput = document.createElement('input');
        productInput.type = 'hidden';
        productInput.name = 'product_id';
        productInput.value = productToDelete;
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo $csrf_token; ?>';
        
        form.appendChild(productInput);
        form.appendChild(csrfInput);
        document.body.appendChild(form);
        form.submit();
    }
});
</script>

<?php include '../includes/admin_footer.php'; ?>