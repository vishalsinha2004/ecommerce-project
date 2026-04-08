<?php
require_once '../includes/config.php';
require_once '../includes/db.php';

// Check admin authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

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

// Get product ID
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$product_id) {
    header('Location: products.php?error=invalid_product');
    exit;
}

// Initialize variables
$success_message = '';
$error_message = '';
$product = null;
$variants = [];
$categories = [];

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Function to get next auto increment ID
function getNextAutoIncrement($db, $tableName, $productIdColumn = 'product_id')
{
    // basic validation to avoid SQL injection via table/column names
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !preg_match('/^[A-Za-z0-9_]+$/', $productIdColumn)) {
        error_log("Invalid table or column name in getNextAutoIncrement()");
        return 1;
    }

    try {
        $sql = "SELECT COALESCE(MAX(`{$productIdColumn}`), 0) + 1 AS next_id FROM `{$tableName}`";
        $result = $db->fetchRow($sql);
        return $result ? (int)$result['next_id'] : 1;
    } catch (Exception $e) {
        error_log("Failed to get next auto increment by MAX(product_id): " . $e->getMessage());
        return 1; // Fallback to 1
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        if ($_POST['action'] === 'update_product') {
            try {
                $db->beginTransaction();

                // Sanitize input data
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $short_description = trim($_POST['short_description'] ?? '');
                $category_id = intval($_POST['category_id'] ?? 0);
                $price = floatval($_POST['price'] ?? 0);
                $sale_price = !empty($_POST['sale_price']) ? floatval($_POST['sale_price']) : null;
                $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
                $status = in_array($_POST['status'], ['active', 'inactive', 'draft']) ? $_POST['status'] : 'active';
                $featured = isset($_POST['featured']) ? 1 : 0;
                $tags = trim($_POST['tags'] ?? '');
                $sku = trim($_POST['sku'] ?? '');
                $available_colors = trim($_POST['available_colors'] ?? '');

                // FIXED: Handle variant_id properly
                if (!empty($_POST['variant_id']) && $_POST['variant_id'] === 'new') {
                    // Create new variant group - use next auto increment ID
                    $parent_product_id = getNextAutoIncrement($db, DB_PREFIX . 'products');
                } elseif (!empty($_POST['variant_id']) && $_POST['variant_id'] !== 'new') {
                    // Link to existing variant group - use the selected group ID directly
                    $parent_product_id = (int)$_POST['variant_id'];
                } else {
                    // Standalone product (no variant linking)
                    $parent_product_id = null;
                }

                // Basic validation
                if (empty($name)) throw new Exception('Product name is required.');
                if ($price <= 0) throw new Exception('Price must be greater than 0.');
                if ($sale_price !== null && $sale_price >= $price) throw new Exception('Sale price must be less than regular price.');
                if ($stock_quantity < 0) throw new Exception('Stock quantity cannot be negative.');
                if ($category_id <= 0) throw new Exception('Please select a valid category.');

                // Color validation for variants - using correct parent product ID
                if ($parent_product_id && !empty($available_colors)) {
                    $existing_colors = $db->fetchAll(
                        "SELECT available_colors FROM " . DB_PREFIX . "products WHERE id != :current_id AND product_id = :parent_id AND status != 'inactive'",
                        [
                            'current_id' => $product_id,
                            'parent_id' => $parent_product_id
                        ]
                    );

                    $input_colors = array_map('trim', array_filter(explode(',', strtolower($available_colors))));
                    foreach ($existing_colors as $row) {
                        if (!empty($row['available_colors'])) {
                            $existing_color_list = array_map('trim', array_filter(explode(',', strtolower($row['available_colors']))));
                            $duplicate_colors = array_intersect($input_colors, $existing_color_list);
                            if (!empty($duplicate_colors)) {
                                throw new Exception('Color "' . implode(', ', $duplicate_colors) . '" already exists in this variant.');
                            }
                        }
                    }
                }

                // Handle main image upload
                $main_image = null;
                if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleImageUpload($_FILES['main_image'], 'products');
                    if ($upload_result['success']) {
                        $main_image = $upload_result['filename'];
                    } else {
                        throw new Exception('Main image upload failed: ' . $upload_result['error']);
                    }
                }

                // Handle gallery images
                $gallery_images = [];
                if (isset($_FILES['gallery_images'])) {
                    foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_info = [
                                'name' => $_FILES['gallery_images']['name'][$key],
                                'type' => $_FILES['gallery_images']['type'][$key],
                                'tmp_name' => $tmp_name,
                                'error' => $_FILES['gallery_images']['error'][$key],
                                'size' => $_FILES['gallery_images']['size'][$key]
                            ];
                            $upload_result = handleImageUpload($file_info, 'products');
                            if ($upload_result['success']) {
                                $gallery_images[] = $upload_result['filename'];
                            }
                        }
                    }
                }

                // Prepare update data
                $update_data = [
                    'name' => $name,
                    'description' => $description,
                    'short_description' => $short_description,
                    'category_id' => $category_id,
                    'price' => $price,
                    'sale_price' => $sale_price,
                    'stock_quantity' => $stock_quantity,
                    'status' => $status,
                    'featured' => $featured,
                    'tags' => $tags,
                    'sku' => $sku,
                    'available_colors' => $available_colors,
                    'product_id' => $parent_product_id, // Now correctly assigns the parent product ID
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // Add main image if uploaded
                if ($main_image) {
                    $update_data['image'] = $main_image;
                }

                // Handle gallery images
                $existing_gallery = isset($_POST['existing_gallery']) ? $_POST['existing_gallery'] : [];
                $all_gallery = array_merge($existing_gallery, $gallery_images);
                $update_data['gallery'] = implode(',', array_filter($all_gallery));

                // Update product using different parameter name for WHERE clause
                $result = $db->update('products', $update_data, 'id = :update_product_id', ['update_product_id' => $product_id]);

                if ($result !== false) {
                    $db->commit();
                    $success_message = 'Product updated successfully.';

                    // Log admin action
                    $log_message = date('Y-m-d H:i:s') . " - ADMIN ACTION: User {$_SESSION['user_id']} updated product {$product_id} ({$name})" . PHP_EOL;
                    file_put_contents(LOGS_PATH . '/app.log', $log_message, FILE_APPEND | LOCK_EX);
                } else {
                    throw new Exception('Failed to update product.');
                }
            } catch (Exception $e) {
                $db->rollback();
                error_log("Product update error: " . $e->getMessage());
                $error_message = $e->getMessage();
            }
        }
    }
}

// Fetch current product data - FIXED ERROR HANDLING
try {
    $product = $db->fetchRow(
        "SELECT * FROM " . DB_PREFIX . "products WHERE id = ?",
        [$product_id]
    );

    if (!$product) {
        header('Location: products.php?error=product_not_found');
        exit;
    }

    // Get available variant groups and current product's siblings
    $variants = [];
    $current_group_siblings = [];
    
    // First, check if current product is part of a group with multiple products
    if ($product['product_id']) {
        $group_count = $db->fetchRow(
            "SELECT COUNT(*) as count FROM " . DB_PREFIX . "products WHERE product_id = ? AND status != 'inactive'",
            [$product['product_id']]
        );
        
        if ($group_count['count'] > 1) {
            // Get siblings in the same group (excluding current product)
            $current_group_siblings = $db->fetchAll(
                "SELECT id, name, product_id FROM " . DB_PREFIX . "products 
                 WHERE product_id = ? AND id != ? AND status != 'inactive' 
                 ORDER BY name ASC",
                [$product['product_id'], $product_id]
            );
        }
    }
    
    // Get all other variant groups (excluding current product's group if it exists)
    $exclude_condition = $product['product_id'] ? "AND p1.product_id != ?" : "";
    $params = $product['product_id'] ? [$product['product_id']] : [];
    
    $variants = $db->fetchAll(
        "SELECT p1.id, p1.name, p1.product_id 
         FROM " . DB_PREFIX . "products p1 
         WHERE p1.product_id IS NOT NULL 
         AND p1.status != 'inactive' 
         {$exclude_condition}
         AND p1.id = (
             SELECT MIN(p2.id) 
             FROM " . DB_PREFIX . "products p2 
             WHERE p2.product_id = p1.product_id
         )
         ORDER BY p1.name ASC",
        $params
    );

    // Get categories from categories table
    $categories = $db->fetchAll(
        "SELECT id, name FROM " . DB_PREFIX . "categories WHERE status = 'active' ORDER BY name"
    );
} catch (Exception $e) {
    error_log("Product fetch error: " . $e->getMessage());
    header('Location: products.php?error=fetch_failed');
    exit;
}

// Image upload handler function
function handleImageUpload($file)
{
    $upload_dir = IMAGES_PATH . '/';

    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file type
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
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

$page_title = 'Edit Product - Admin Panel';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3b82f6',
                        secondary: '#64748b'
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="text-primary hover:text-blue-700 font-medium transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Dashboard
                    </a>
                    <span class="text-gray-400">|</span>
                    <a href="products.php" class="text-primary hover:text-blue-700 font-medium transition-colors">Products</a>
                    <span class="text-gray-400">|</span>
                    <span class="text-gray-600">Edit Product</span>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600 hidden sm:block">Welcome, <?= htmlspecialchars($_SESSION['first_name']) ?></span>
                    <a href="../auth/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Edit Product</h1>
            <p class="mt-2 text-gray-600">Update product information and manage variants</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-400 mr-3"></i>
                    <span class="text-green-700"><?= htmlspecialchars($success_message) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-400 mr-3"></i>
                    <span class="text-red-700"><?= htmlspecialchars($error_message) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <form method="POST" enctype="multipart/form-data" class="space-y-6" id="productForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_product">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Basic Information -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-info-circle mr-2 text-primary"></i>Basic Information
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Product Name *</label>
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($product['name'] ?? '') ?>"
                                    required maxlength="255"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                            </div>

                            <div>
                                <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">Category *</label>
                                <select id="category_id" name="category_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($product['category_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($cat['name'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="sku" class="block text-sm font-medium text-gray-700 mb-2">SKU</label>
                                <input type="text" id="sku" name="sku" value="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                                    maxlength="100"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                            </div>

                            <div class="sm:col-span-2" id="custom-category" style="display: none;">
                                <label for="new_category" class="block text-sm font-medium text-gray-700 mb-2">New Category Name</label>
                                <input type="text" id="new_category" name="new_category" maxlength="100"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                            </div>

                            <div class="sm:col-span-2">
                                <label for="short_description" class="block text-sm font-medium text-gray-700 mb-2">Short Description</label>
                                <textarea id="short_description" name="short_description" rows="3" maxlength="500"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors resize-none"><?= htmlspecialchars($product['short_description'] ?? '') ?></textarea>
                                <div class="text-right text-xs text-gray-500 mt-1">
                                    <span id="short_desc_count">0</span>/500 characters
                                </div>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Full Description</label>
                                <textarea id="description" name="description" rows="6"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors resize-none"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                            </div>
                        </div>

                    </div>

                    <!-- Pricing & Inventory -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-dollar-sign mr-2 text-primary"></i>Pricing & Inventory
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label for="price" class="block text-sm font-medium text-gray-700 mb-2">Regular Price * (₹)</label>
                                <input type="number" id="price" name="price" value="<?= $product['price'] ?>"
                                    step="0.01" min="0.01" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                            </div>

                            <div>
                                <label for="sale_price" class="block text-sm font-medium text-gray-700 mb-2">Sale Price (₹)</label>
                                <input type="number" id="sale_price" name="sale_price" value="<?= $product['sale_price'] ?>"
                                    step="0.01" min="0"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <div id="discount_display" class="text-sm text-gray-500 mt-1"></div>
                            </div>

                            <div>
                                <label for="stock_quantity" class="block text-sm font-medium text-gray-700 mb-2">Stock Quantity *</label>
                                <input type="number" id="stock_quantity" name="stock_quantity" value="<?= $product['stock_quantity'] ?>"
                                    min="0" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <div class="text-sm mt-1 <?= $product['stock_quantity'] < 10 ? 'text-red-500' : 'text-green-500' ?>">
                                    <?= $product['stock_quantity'] < 10 ? 'Low Stock' : 'In Stock' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Product Attributes -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-palette mr-2 text-primary"></i>Product Attributes
                        </h2>

                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label for="available_colors" class="block text-sm font-medium text-gray-700 mb-2">Available Colors</label>
                                <input type="text" id="available_colors" name="available_colors"
                                    value="<?= htmlspecialchars($product['available_colors'] ?? '') ?>"
                                    placeholder="e.g., Red, Blue, Green (comma separated)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <p class="mt-1 text-sm text-gray-500">Enter colors separated by commas. Each variant must have unique colors.</p>
                                <div id="color_preview" class="mt-2 flex flex-wrap gap-2"></div>
                            </div>

                            <div>
                                <label for="tags" class="block text-sm font-medium text-gray-700 mb-2">Tags</label>
                                <input type="text" id="tags" name="tags" value="<?= htmlspecialchars($product['tags'] ?? '') ?>"
                                    placeholder="e.g., summer, casual, trendy (comma separated)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <p class="mt-1 text-sm text-gray-500">Enter tags separated by commas for better searchability</p>
                            </div>
                        </div>
                    </div>

                    <!-- Images -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-images mr-2 text-primary"></i>Product Images
                        </h2>

                        <!-- Current Main Image -->
                        <?php if (!empty($product['image'])): ?>
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Current Main Image</label>
                                <div class="relative inline-block">
                                    <img src="<?= IMAGES_URL ?>/<?= htmlspecialchars($product['image']) ?>"
                                        alt="Current main image" class="w-32 h-32 object-cover rounded-lg border border-gray-200 shadow-sm">
                                    <span class="absolute -top-1 -right-1 bg-primary text-white text-xs px-2 py-1 rounded-full shadow">Main</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Main Image Upload -->
                        <div class="mb-6">
                            <label for="main_image" class="block text-sm font-medium text-gray-700 mb-2">Update Main Image</label>
                            <input type="file" id="main_image" name="main_image" accept="image/*"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-blue-600 file:transition-colors">
                            <div id="main-image-preview" class="mt-3"></div>
                        </div>

                        <!-- Current Gallery Images with removal option -->
                        <?php if (!empty($product['gallery'])): ?>
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Current Gallery Images</label>
                                <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3" id="current-gallery-container">
                                    <?php
                                    $gallery_images_list = !empty($product['gallery']) ? explode(',', $product['gallery']) : [];
                                    foreach ($gallery_images_list as $index => $gallery_image):
                                        $gallery_image = trim($gallery_image);
                                        if ($gallery_image):
                                    ?>
                                            <div class="relative group">
                                                <img src="<?= IMAGES_URL ?>/<?= htmlspecialchars($gallery_image) ?>"
                                                    alt="Gallery image" class="w-full h-20 object-cover rounded border border-gray-200 shadow-sm group-hover:shadow-md transition-shadow">
                                                <input type="hidden" name="existing_gallery[]" value="<?= htmlspecialchars($gallery_image) ?>">
                                                <button type="button" class="absolute top-0 right-0 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs opacity-100 focus:outline-none" onclick="removeGalleryImage(this)">
                                                    &times;
                                                </button>
                                            </div>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Gallery Images Upload -->
                        <div>
                            <label for="gallery_images" class="block text-sm font-medium text-gray-700 mb-2">Add Gallery Images</label>
                            <input type="file" id="gallery_images" name="gallery_images[]" accept="image/*" multiple
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-secondary file:text-white hover:file:bg-gray-600 file:transition-colors">
                            <p class="mt-1 text-sm text-gray-500">Select multiple images to add to gallery (Max 5MB each)</p>
                            <div id="gallery-images-preview" class="mt-3"></div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Product Status -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-cog mr-2 text-primary"></i>Product Status
                        </h3>

                        <div class="space-y-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                <select id="status" name="status"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                    <option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>✅ Active</option>
                                    <option value="draft" <?= $product['status'] === 'draft' ? 'selected' : '' ?>>📝 Draft</option>
                                    <option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>❌ Inactive</option>
                                </select>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="featured" name="featured" value="1"
                                    <?= $product['featured'] ? 'checked' : '' ?>
                                    class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded transition-colors">
                                <label for="featured" class="ml-3 block text-sm text-gray-900">
                                    <span class="font-medium">Featured Product</span>
                                    <span class="block text-gray-500 text-xs">Show on homepage</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Variant Management -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-sitemap mr-2 text-primary"></i>Variant Management
                        </h3>

                        <div class="space-y-4">
                            <div>
                                <label for="variant_id" class="block text-sm font-medium text-gray-700 mb-2">Link to Variant</label>
                                <select id="variant_id" name="variant_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                    
                                    <?php if (empty($product['product_id']) || empty($current_group_siblings)): ?>
                                        <option value="" selected>🔗 Standalone Product</option>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($current_group_siblings)): ?>
                                        <!-- Show siblings in current group -->
                                        <?php foreach ($current_group_siblings as $sibling): ?>
                                            <option value="<?= $sibling['product_id'] ?>" selected>
                                                🔗 <?= htmlspecialchars($sibling['name']) ?> (Current Group ID: <?= $sibling['product_id'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                        
                                        <!-- Option to make standalone -->
                                        <option value="">🔗 Make Standalone Product</option>
                                    <?php endif; ?>
                                    
                                    <option value="new">🆕 Create New Variant Group</option>
                                    
                                    <!-- Show other variant groups -->
                                    <?php foreach ($variants as $variant): ?>
                                        <option value="<?= $variant['product_id'] ?>">
                                            🔗 <?= htmlspecialchars($variant['name']) ?> (Group ID: <?= $variant['product_id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="mt-2 text-sm text-gray-500">
                                    <?php if (!empty($current_group_siblings)): ?>
                                        Currently part of a variant group. Select "Make Standalone" to unlink from group.
                                    <?php else: ?>
                                        Link this product as a variant of another product or keep as standalone.
                                    <?php endif; ?>
                                </p>
                            </div>

                            <?php if ($product['product_id']): ?>
                                <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                                    <p class="text-sm text-blue-700 flex items-center">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        <?php if (!empty($current_group_siblings)): ?>
                                            Part of variant group ID: <strong class="ml-1"><?= $product['product_id'] ?></strong> with <?= count($current_group_siblings) ?> other variant(s)
                                        <?php else: ?>
                                            Only product in variant group ID: <strong class="ml-1"><?= $product['product_id'] ?></strong>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-chart-bar mr-2 text-primary"></i>Quick Stats
                        </h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">Created:</span>
                                <span class="text-sm font-medium"><?= date('M j, Y', strtotime($product['created_at'])) ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">Last Updated:</span>
                                <span class="text-sm font-medium"><?= date('M j, Y', strtotime($product['updated_at'])) ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">Product ID:</span>
                                <span class="text-sm font-medium"><?= $product['id'] ?></span>
                            </div>
                        </div>
                    </div>

                  <!-- Actions -->
<div class="bg-white shadow rounded-lg p-6">
    <div class="space-y-3">
        <button type="submit" id="update-btn" name="action" value="update_product" class="action-btn w-full bg-primary hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-md transition-colors duration-200 flex items-center justify-center">
            <span class="btn-text"><i class="fas fa-save mr-2"></i>Update Product</span>
            <span class="loader hidden"></span>
        </button>

        <a href="products.php" id="cancel-btn" class="action-btn w-full bg-gray-500 hover:bg-gray-600 text-white font-medium py-3 px-4 rounded-md transition-colors duration-200 block text-center">
            <span class="btn-text"><i class="fas fa-times mr-2"></i>Cancel</span>
            <span class="loader hidden"></span>
        </a>

        <a href="../product.php?id=<?= $product_id ?>" id="preview-btn" class="action-btn w-full bg-green-500 hover:bg-green-600 text-white font-medium py-3 px-4 rounded-md transition-colors duration-200 block text-center" target="_blank">
            <span class="btn-text"><i class="fas fa-eye mr-2"></i>Preview Product</span>
            <span class="loader hidden"></span>
        </a>
    </div>
</div>

                </div>
            </div>
        </form>
    </div>
<style>
    .loader {
        border: 4px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top: 4px solid #ffffff;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
        display: none; /* Hidden by default */
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
        pointer-events: none; /* Disable further clicks */
        opacity: 0.8;
    }
</style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
    // Select all action buttons by their IDs
    const updateBtn = document.getElementById('update-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const previewBtn = document.getElementById('preview-btn');

    const buttons = [updateBtn, cancelBtn, previewBtn];

    buttons.forEach(button => {
        if (button) {
            button.addEventListener('click', function(event) {
                const form = button.closest('form');

                // For the submit button, check form validity first
                if (button.type === 'submit' && form && !form.checkValidity()) {
                    // If the form is not valid, the browser will handle showing the
                    // validation messages, so we stop here and don't show the loader.
                    return;
                }

                // Add the loading class to show the spinner
                button.classList.add('loading');

                // For the preview button, which opens a new tab, we remove the loader
                // after a short delay so the user can click it again if they want.
                if (button.id === 'preview-btn') {
                    setTimeout(() => {
                        button.classList.remove('loading');
                    }, 1500); // Remove loader after 1.5 seconds
                }
            });
        }
    });
});

        // Enhanced form validation and UX improvements
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('productForm');
            const priceInput = document.getElementById('price');
            const salePriceInput = document.getElementById('sale_price');
            const variantSelect = document.getElementById('variant_id');
            const colorsInput = document.getElementById('available_colors');
            const categorySelect = document.getElementById('category');
            const customCategoryDiv = document.getElementById('custom-category');
            const shortDescInput = document.getElementById('short_description');
            const shortDescCount = document.getElementById('short_desc_count');
            const colorPreview = document.getElementById('color_preview');
            const discountDisplay = document.getElementById('discount_display');
            const variantWarning = document.getElementById('variant-warning');

            // Initialize character count
            updateCharacterCount();
            updateColorPreview();
            updateDiscountDisplay();

            // Category selection handler
            categorySelect.addEventListener('change', function() {
                if (this.value === 'custom') {
                    customCategoryDiv.style.display = 'block';
                    document.getElementById('new_category').required = true;
                } else {
                    customCategoryDiv.style.display = 'none';
                    document.getElementById('new_category').required = false;
                }
            });

            // Character count for short description
            shortDescInput.addEventListener('input', updateCharacterCount);

            function updateCharacterCount() {
                const count = shortDescInput.value.length;
                shortDescCount.textContent = count;
                shortDescCount.className = count > 450 ? 'text-red-500 font-medium' : 'text-gray-500';
            }

            // Price validation and discount calculation
            function validatePrices() {
                const price = parseFloat(priceInput.value) || 0;
                const salePrice = parseFloat(salePriceInput.value) || 0;

                if (salePrice > 0 && salePrice >= price) {
                    salePriceInput.setCustomValidity('Sale price must be less than regular price');
                    salePriceInput.classList.add('border-red-500');
                } else {
                    salePriceInput.setCustomValidity('');
                    salePriceInput.classList.remove('border-red-500');
                }

                updateDiscountDisplay();
            }

            function updateDiscountDisplay() {
                const price = parseFloat(priceInput.value) || 0;
                const salePrice = parseFloat(salePriceInput.value) || 0;

                if (salePrice > 0 && salePrice < price) {
                    const discount = Math.round(((price - salePrice) / price) * 100);
                    discountDisplay.textContent = `${discount}% off`;
                    discountDisplay.className = 'text-sm text-green-600 font-medium mt-1';
                } else {
                    discountDisplay.textContent = '';
                }
            }

            priceInput.addEventListener('input', validatePrices);
            salePriceInput.addEventListener('input', validatePrices);

            // Color preview functionality
            colorsInput.addEventListener('input', updateColorPreview);

            function updateColorPreview() {
                const colors = colorsInput.value.split(',').map(c => c.trim()).filter(c => c);
                colorPreview.innerHTML = '';

                colors.forEach(color => {
                    const span = document.createElement('span');
                    span.className = 'px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-sm border';
                    span.textContent = color;
                    colorPreview.appendChild(span);
                });
            }

            // Variant selection handler
            variantSelect.addEventListener('change', function() {
                if (this.value !== 'new' && colorsInput.value.trim()) {
                    variantWarning.classList.remove('hidden');
                } else {
                    variantWarning.classList.add('hidden');
                }
            });

            // Image preview functionality
            function handleImagePreview(input, previewContainer, isMultiple = false) {
                if (input.files && input.files.length > 0) {
                    previewContainer.innerHTML = '';

                    Array.from(input.files).forEach((file, index) => {
                        if (file.type.startsWith('image/')) {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                const div = document.createElement('div');
                                div.className = 'relative inline-block mr-3 mb-3';
                                div.innerHTML = `
                                    <img src="${e.target.result}" alt="Preview ${index + 1}" 
                                         class="w-20 h-20 object-cover rounded-lg border border-gray-200 shadow-sm">
                                    <span class="absolute -top-1 -right-1 bg-green-500 text-white text-xs px-2 py-1 rounded-full shadow">New</span>
                                    ${isMultiple ? `<span class="absolute top-0 left-0 bg-blue-500 text-white text-xs px-1 py-1 rounded-br">${index + 1}</span>` : ''}
                                `;
                                previewContainer.appendChild(div);
                            };
                            reader.readAsDataURL(file);
                        }
                    });
                }
            }

            // Add preview functionality to image inputs
            const mainImageInput = document.getElementById('main_image');
            const galleryImagesInput = document.getElementById('gallery_images');
            const mainImagePreview = document.getElementById('main-image-preview');
            const galleryImagesPreview = document.getElementById('gallery-images-preview');

            mainImageInput.addEventListener('change', function() {
                handleImagePreview(this, mainImagePreview);
            });

            galleryImagesInput.addEventListener('change', function() {
                handleImagePreview(this, galleryImagesPreview, true);
            });

            // Form submission loading state
            form.addEventListener('submit', function(e) {
                const updateBtn = document.getElementById('update-btn');
                updateBtn.disabled = true;
                updateBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Updating Product...';
                updateBtn.className = updateBtn.className.replace('hover:bg-blue-700', 'cursor-not-allowed opacity-75');

                // Handle custom category
                const categorySelect = document.getElementById('category');
                const newCategoryInput = document.getElementById('new_category');
                if (categorySelect.value === 'custom' && newCategoryInput.value.trim()) {
                    categorySelect.removeAttribute('name');
                    newCategoryInput.name = 'category';
                }
            });

            // Auto-save draft functionality (optional)
            let autoSaveTimeout;
            const formInputs = form.querySelectorAll('input, textarea, select');

            formInputs.forEach(input => {
                input.addEventListener('input', function() {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = setTimeout(function() {
                        // You can implement auto-save here if needed
                        console.log('Auto-save triggered');
                    }, 30000); // 30 seconds
                });
            });
        });

        // Function to remove gallery images
        function removeGalleryImage(button) {
            // Find the parent container of the button
            const container = button.closest('.relative');
            // Remove the entire image container from DOM
            container.remove();
        }
    </script>
</body>

</html>