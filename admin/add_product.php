<?php
/** @var mysqli $db */
/** @var mysqli::fetchRow $db->fetchRow */
/** @var bool $is_out_of_stock */
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

// Initialize variables
$success_message = '';
$error_message = '';
$variants = [];
$categories = [];
$form_data = [];

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

// Function to generate unique SKU
function generateSKU($name, $db)
{
    $base_sku = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 6));
    if (empty($base_sku)) {
        $base_sku = 'PROD';
    }

    $counter = 1;
    $sku = $base_sku . str_pad($counter, 3, '0', STR_PAD_LEFT);

    // Check if SKU exists and increment if necessary
    while (true) {
        try {
            $existing = $db->fetchRow(
                "SELECT id FROM " . DB_PREFIX . "products WHERE sku = ?",
                [$sku]
            );
            if (!$existing) {
                break;
            }
            $counter++;
            $sku = $base_sku . str_pad($counter, 3, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            error_log("SKU generation error: " . $e->getMessage());
            break;
        }
    }

    return $sku;
}

// Function to generate slug
function generateSlug($name)
{
    $slug = preg_replace('/[^a-zA-Z0-9\s]/', '', $name); // Remove special characters
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/\s+/', '-', $slug); // Replace spaces with hyphens
    $slug = preg_replace('/-+/', '-', $slug); // Replace multiple hyphens
    return $slug;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        if ($_POST['action'] === 'add_product') {
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
                $status = in_array($_POST['status'], ['active', 'inactive', 'draft']) ? $_POST['status'] : 'draft';
                $featured = isset($_POST['featured']) ? 1 : 0;
                $tags = trim($_POST['tags'] ?? '');
                $sku = trim($_POST['sku'] ?? '');
                $available_colors = trim($_POST['available_colors'] ?? '');

                // Auto-generate SKU if not provided
                if (empty($sku)) {
                    $sku = generateSKU($name, $db);
                }

                // Generate slug
                $slug = generateSlug($name);

                // Handle variant_id properly
                $parent_product_id = null;
                if (!empty($_POST['variant_id']) && $_POST['variant_id'] === 'new') {
                    // Get next auto increment ID for new variant group
                    $parent_product_id = getNextAutoIncrement($db, DB_PREFIX . 'products');
                } elseif (!empty($_POST['variant_id']) && $_POST['variant_id'] !== 'new') {
                    // Get the selected variant's product_id, not its id
                    $selected_variant_id = (int)$_POST['variant_id'];
                    $selected_variant = $db->fetchRow(
                        "SELECT product_id FROM " . DB_PREFIX . "products WHERE id = ?",
                        [$selected_variant_id]
                    );

                    if ($selected_variant && $selected_variant['product_id']) {
                        // Use the variant's product_id (the parent/main product ID)
                        $parent_product_id = $selected_variant['product_id'];
                    } else {
                        // If selected variant has no parent, use its own ID as the parent
                        $parent_product_id = $selected_variant_id;
                    }
                }

                // Basic validation
                if (empty($name)) throw new Exception('Product name is required.');
                if ($price <= 0) throw new Exception('Price must be greater than 0.');
                if ($sale_price !== null && $sale_price >= $price) throw new Exception('Sale price must be less than regular price.');
                if ($stock_quantity < 0) throw new Exception('Stock quantity cannot be negative.');
                if ($category_id <= 0) throw new Exception('Please select a valid category.');

                // Check if SKU already exists
                $existing_sku = $db->fetchRow(
                    "SELECT id FROM " . DB_PREFIX . "products WHERE sku = ?",
                    [$sku]
                );
                if ($existing_sku) {
                    throw new Exception('SKU already exists. Please use a different SKU.');
                }

                // Color validation for variants
                if ($parent_product_id && !empty($available_colors)) {
                    $existing_colors = $db->fetchAll(
                        "SELECT available_colors FROM " . DB_PREFIX . "products WHERE product_id = :parent_id AND status != 'inactive'",
                        ['parent_id' => $parent_product_id]
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

                // Prepare insert data
                $insert_data = [
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
                    'product_id' => $parent_product_id,
                    'image' => $main_image,
                    'gallery' => implode(',', array_filter($gallery_images)),
                    'slug' => $slug,
                    'sort_order' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // Insert product
                $result = $db->insert('products', $insert_data);

                if ($result !== false) {
                    // Get last insert ID using MySQL function
                    $last_id_result = $db->fetchRow("SELECT LAST_INSERT_ID() AS last_id");
                    $new_product_id = $last_id_result ? (int)$last_id_result['last_id'] : 0;

                    $db->commit();
                    $success_message = 'Product added successfully!';

                    // Log admin action
                    $log_message = date('Y-m-d H:i:s') . " - ADMIN ACTION: User {$_SESSION['user_id']} added product {$new_product_id} ({$name})" . PHP_EOL;
                    file_put_contents(LOGS_PATH . '/app.log', $log_message, FILE_APPEND | LOCK_EX);

                    // Redirect to edit page or clear form
                    if (isset($_POST['save_and_edit'])) {
                        header("Location: edit_product.php?id={$new_product_id}&added=1");
                        exit;
                    } else {
                        // Clear form data after successful submission
                        $form_data = [];
                    }
                } else {
                    throw new Exception('Failed to add product.');
                }
            } catch (Exception $e) {
                $db->rollback();
                error_log("Product add error: " . $e->getMessage());
                $error_message = $e->getMessage();

                // Preserve form data on error
                $form_data = $_POST;
            }
        }
    }
}

// Fetch data for dropdowns
try {
    // Get available variants for this product
    $variants = $db->fetchAll(
        "SELECT DISTINCT id, name, product_id FROM " . DB_PREFIX . "products WHERE status != 'inactive' ORDER BY name ASC"
    );

    // Get categories from categories table
    $categories = $db->fetchAll(
        "SELECT id, name FROM " . DB_PREFIX . "categories WHERE status = 'active' ORDER BY name"
    );
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $variants = [];
    $categories = [];
}

// Image upload handler function
function handleImageUpload($file, $subfolder = 'products')
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

$page_title = 'Add New Product - Admin Panel';
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
                    <span class="text-gray-600">Add Product</span>
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
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Add New Product</h1>
            <p class="mt-2 text-gray-600">Create a new product with images, variants, and detailed information</p>
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

        <!-- Add Form -->
        <form method="POST" enctype="multipart/form-data" class="space-y-6" id="productForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add_product">

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
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($form_data['name'] ?? '') ?>"
                                    required maxlength="255"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                            </div>

                            <div>
                                <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">Category *</label>
                                <select id="category_id" name="category_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($form_data['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($cat['name'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="sku" class="block text-sm font-medium text-gray-700 mb-2">SKU</label>
                                <input type="text" id="sku" name="sku" value="<?= htmlspecialchars($form_data['sku'] ?? '') ?>"
                                    maxlength="100" placeholder="Leave empty for auto-generation"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <p class="mt-1 text-sm text-gray-500">Unique product identifier (auto-generated if empty)</p>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="short_description" class="block text-sm font-medium text-gray-700 mb-2">Short Description</label>
                                <textarea id="short_description" name="short_description" rows="3" maxlength="500"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors resize-none"><?= htmlspecialchars($form_data['short_description'] ?? '') ?></textarea>
                                <div class="text-right text-xs text-gray-500 mt-1">
                                    <span id="short_desc_count">0</span>/500 characters
                                </div>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Full Description</label>
                                <textarea id="description" name="description" rows="6"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors resize-none"><?= htmlspecialchars($form_data['description'] ?? '') ?></textarea>
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
                                <input type="number" id="price" name="price" value="<?= $form_data['price'] ?? '' ?>"
                                    step="0.01" min="0.01" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                            </div>

                            <div>
                                <label for="sale_price" class="block text-sm font-medium text-gray-700 mb-2">Sale Price (₹)</label>
                                <input type="number" id="sale_price" name="sale_price" value="<?= $form_data['sale_price'] ?? '' ?>"
                                    step="0.01" min="0"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <div id="discount_display" class="text-sm text-gray-500 mt-1"></div>
                            </div>

                            <div>
                                <label for="stock_quantity" class="block text-sm font-medium text-gray-700 mb-2">Stock Quantity *</label>
                                <input type="number" id="stock_quantity" name="stock_quantity" value="<?= $form_data['stock_quantity'] ?? '0' ?>"
                                    min="0" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <div class="text-sm mt-1 text-gray-500">
                                    Initial stock quantity
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
                                    value="<?= htmlspecialchars($form_data['available_colors'] ?? '') ?>"
                                    placeholder="e.g., Red, Blue, Green (comma separated)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                                <p class="mt-1 text-sm text-gray-500">Enter colors separated by commas. Each variant must have unique colors.</p>
                                <div id="color_preview" class="mt-2 flex flex-wrap gap-2"></div>
                                <div id="variant-warning" class="hidden mt-2 bg-yellow-50 border border-yellow-200 rounded-md p-3">
                                    <p class="text-sm text-yellow-700 flex items-center">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        Color validation will be performed against selected variant group
                                    </p>
                                </div>
                            </div>

                            <div>
                                <label for="tags" class="block text-sm font-medium text-gray-700 mb-2">Tags</label>
                                <input type="text" id="tags" name="tags" value="<?= htmlspecialchars($form_data['tags'] ?? '') ?>"
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

                        <!-- Main Image Upload -->
                        <div class="mb-6">
                            <label for="main_image" class="block text-sm font-medium text-gray-700 mb-2">Main Product Image</label>
                            <input type="file" id="main_image" name="main_image" accept="image/*"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-blue-600 file:transition-colors">
                            <p class="mt-1 text-sm text-gray-500">Recommended: 800x800px, Max 5MB, JPG/PNG/GIF/WebP</p>
                            <div id="main-image-preview" class="mt-3"></div>
                        </div>

                        <!-- Gallery Images Upload -->
                        <div>
                            <label for="gallery_images" class="block text-sm font-medium text-gray-700 mb-2">Gallery Images</label>
                            <input type="file" id="gallery_images" name="gallery_images[]" accept="image/*" multiple
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-colors file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-secondary file:text-white hover:file:bg-gray-600 file:transition-colors">
                            <p class="mt-1 text-sm text-gray-500">Select multiple images for product gallery (Max 5MB each)</p>
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
                                    <option value="active">✅ Active</option>
                                    <option value="draft" selected>📝 Draft</option>
                                    <option value="inactive">❌ Inactive</option>
                                </select>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="featured" name="featured" value="1"
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
                                    <option value="" selected>🔗 Standalone Product</option>
                                    <option value="new">🆕 Create New Variant Group</option>
                                    <?php foreach ($variants as $variant): ?>
                                        <option value="<?= $variant['id'] ?>">
                                            🔗 <?= htmlspecialchars($variant['name'] ?? 'Unnamed Product') ?> (ID: <?= $variant['id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="mt-2 text-sm text-gray-500">Link this product as a variant of another product or keep as standalone</p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                  <div class="bg-white shadow rounded-lg p-6">
    <div class="space-y-3">
        <button type="submit" id="save-btn" class="action-btn w-full bg-primary hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-md transition-colors duration-200 flex items-center justify-center">
            <span class="btn-text"><i class="fas fa-save mr-2"></i>Save Product</span>
            <span class="loader hidden"></span>
        </button>

        <button type="submit" name="save_and_edit" value="1" id="save-edit-btn" class="action-btn w-full bg-green-500 hover:bg-green-600 text-white font-medium py-3 px-4 rounded-md transition-colors duration-200 flex items-center justify-center">
            <span class="btn-text"><i class="fas fa-edit mr-2"></i>Save and Edit</span>
            <span class="loader hidden"></span>
        </button>

        <a href="products.php" id="cancel-btn" class="action-btn w-full bg-gray-500 hover:bg-gray-600 text-white font-medium py-3 px-4 rounded-md transition-colors duration-200 block text-center">
            <span class="btn-text"><i class="fas fa-times mr-2"></i>Cancel</span>
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
        pointer-events: none; /* Disable clicks */
        opacity: 0.8;
    }
</style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
    // Select all buttons that should have a loader
    const actionButtons = document.querySelectorAll('#save-btn, #save-edit-btn, #cancel-btn');

    actionButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            // If the button is inside a form that will be submitted
            const form = button.closest('form');
            if (form && button.type === 'submit') {
                // Check form validity before showing loader
                if (!form.checkValidity()) {
                    // If the form is invalid, the browser will show a validation message.
                    // We don't want to show the loader, so we stop here.
                    return;
                }
            }
            
            // Add the loading class to the clicked button
            button.classList.add('loading');
        });
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
                if (this.value !== 'new' && this.value !== '' && colorsInput.value.trim()) {
                    variantWarning.classList.remove('hidden');
                } else {
                    variantWarning.classList.add('hidden');
                }
            });

            // Image preview functionality with remove buttons
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
                                    <button type="button" class="absolute top-0 right-0 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs" onclick="removePreviewImage(this, '${input.id}')">
                                        &times;
                                    </button>
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
                const saveBtn = document.getElementById('save-btn');
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving Product...';
                saveBtn.className = saveBtn.className.replace('hover:bg-blue-700', 'cursor-not-allowed opacity-75');

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

        // Function to remove preview image
        function removePreviewImage(button, inputId) {
            // Remove the preview element
            const previewContainer = button.closest('div');
            previewContainer.remove();

            // Clear the file input
            document.getElementById(inputId).value = '';
        }
    </script>
</body>

</html>