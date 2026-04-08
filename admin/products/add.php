<?php
require_once '../includes/admin_auth.php';
require_once '../includes/admin_functions.php';

// Require admin login
requireAdminLogin();

$errors = [];
$success_message = '';

// Get categories for dropdown
$categories = $db->fetchAll("SELECT * FROM " . DB_PREFIX . "categories WHERE status = 'active' ORDER BY name");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateAdminCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Get form data
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $sale_price = !empty($_POST['sale_price']) ? floatval($_POST['sale_price']) : null;
        $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
        $short_description = trim($_POST['short_description'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $available_colors = trim($_POST['available_colors'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $featured = isset($_POST['featured']) ? 1 : 0;
        $status = $_POST['status'] ?? 'draft';
        
        // Validation
        if (empty($name)) $errors[] = 'Product name is required.';
        if (empty($category)) $errors[] = 'Category is required.';
        if ($price <= 0) $errors[] = 'Price must be greater than 0.';
        if ($sale_price !== null && $sale_price >= $price) $errors[] = 'Sale price must be less than regular price.';
        if ($stock_quantity < 0) $errors[] = 'Stock quantity cannot be negative.';
        if (empty($short_description)) $errors[] = 'Short description is required.';
        if (empty($description)) $errors[] = 'Description is required.';
        
        // Handle main image upload
        $main_image = '';
        if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
            $upload_result = handleFileUpload($_FILES['main_image'], IMAGES_PATH);
            if ($upload_result['success']) {
                $main_image = $upload_result['filename'];
            } else {
                $errors[] = 'Main image upload failed: ' . $upload_result['message'];
            }
        } else {
            $errors[] = 'Main product image is required.';
        }
        
        // Handle gallery images
        $gallery_images = [];
        if (isset($_FILES['gallery_images'])) {
            foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES['gallery_images']['name'][$key],
                        'type' => $_FILES['gallery_images']['type'][$key],
                        'tmp_name' => $tmp_name,
                        'error' => $_FILES['gallery_images']['error'][$key],
                        'size' => $_FILES['gallery_images']['size'][$key]
                    ];
                    $upload_result = handleFileUpload($file, IMAGES_PATH);
                    if ($upload_result['success']) {
                        $gallery_images[] = $upload_result['filename'];
                    }
                }
            }
        }
        
        // Insert product if no errors
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Generate product_id for grouping variants
                $product_id = uniqid('prod_');
                
                $product_data = [
                    'product_id' => $product_id,
                    'name' => $name,
                    'category' => $category,
                    'price' => $price,
                    'sale_price' => $sale_price,
                    'stock_quantity' => $stock_quantity,
                    'short_description' => $short_description,
                    'description' => $description,
                    'image' => $main_image,
                    'gallery' => implode(',', $gallery_images),
                    'available_colors' => $available_colors,
                    'tags' => $tags,
                    'featured' => $featured,
                    'status' => $status,
                    'sort_order' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                $product_insert_id = $db->insert('products', $product_data);
                
                if ($product_insert_id) {
                    $db->commit();
                    
                    // Log admin activity
                    logAdminActivity('create', 'products', $product_insert_id, "Created product: {$name}");
                    
                    $_SESSION['success_message'] = 'Product added successfully!';
                    header('Location: index.php');
                    exit;
                } else {
                    throw new Exception('Failed to insert product');
                }
            } catch (Exception $e) {
                $db->rollback();
                $errors[] = 'Database error: ' . $e->getMessage();
                
                // Clean up uploaded files on error
                if ($main_image) deleteFile(IMAGES_PATH . '/' . $main_image);
                foreach ($gallery_images as $img) {
                    deleteFile(IMAGES_PATH . '/' . $img);
                }
            }
        }
    }
}

$page_title = 'Add Product';
include '../includes/admin_header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Add Product</h1>
            <p class="text-gray-600">Create a new product for your store</p>
        </div>
        <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-md text-sm font-medium">
            ← Back to Products
        </a>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-md p-4">
        <ul class="list-disc list-inside text-red-700">
            <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Product Form -->
    <form method="POST" enctype="multipart/form-data" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
            
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-gray-700">Product Name *</label>
                    <input type="text" name="name" id="name" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>

                <div>
                    <label for="category" class="block text-sm font-medium text-gray-700">Category *</label>
                    <select name="category" id="category" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['slug']); ?>"
                                <?php echo (($_POST['category'] ?? '') === $cat['slug']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" id="status"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
                        <option value="draft" <?php echo (($_POST['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="active" <?php echo (($_POST['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (($_POST['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div>
                    <label for="price" class="block text-sm font-medium text-gray-700">Regular Price (₹) *</label>
                    <input type="number" name="price" id="price" step="0.01" min="0" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                           value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>">
                </div>

                <div>
                    <label for="sale_price" class="block text-sm font-medium text-gray-700">Sale Price (₹)</label>
                    <input type="number" name="sale_price" id="sale_price" step="0.01" min="0"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                           value="<?php echo htmlspecialchars($_POST['sale_price'] ?? ''); ?>">
                </div>

                <div>
                    <label for="stock_quantity" class="block text-sm font-medium text-gray-700">Stock Quantity *</label>
                    <input type="number" name="stock_quantity" id="stock_quantity" min="0" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                           value="<?php echo htmlspecialchars($_POST['stock_quantity'] ?? ''); ?>">
                </div>

                <div class="sm:col-span-2">
                    <label for="short_description" class="block text-sm font-medium text-gray-700">Short Description *</label>
                    <textarea name="short_description" id="short_description" rows="3" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                              placeholder="Brief product description for listings..."><?php echo htmlspecialchars($_POST['short_description'] ?? ''); ?></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700">Full Description *</label>
                    <textarea name="description" id="description" rows="6" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                              placeholder="Detailed product description..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div>
                    <label for="available_colors" class="block text-sm font-medium text-gray-700">Available Colors</label>
                    <input type="text" name="available_colors" id="available_colors"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                           placeholder="e.g., Red, Blue, Green"
                           value="<?php echo htmlspecialchars($_POST['available_colors'] ?? ''); ?>">
                </div>

                <div>
                    <label for="tags" class="block text-sm font-medium text-gray-700">Tags</label>
                    <input type="text" name="tags" id="tags"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                           placeholder="e.g., summer, casual, elegant"
                           value="<?php echo htmlspecialchars($_POST['tags'] ?? ''); ?>">
                </div>

                <div class="sm:col-span-2">
                    <div class="flex items-center">
                        <input type="checkbox" name="featured" id="featured" value="1"
                               class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded"
                               <?php echo (isset($_POST['featured'])) ? 'checked' : ''; ?>>
                        <label for="featured" class="ml-2 block text-sm text-gray-900">
                            Featured Product (will appear in featured sections)
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Images Section -->
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Product Images</h3>
            
            <div class="space-y-4">
                <div>
                    <label for="main_image" class="block text-sm font-medium text-gray-700">Main Product Image *</label>
                    <input type="file" name="main_image" id="main_image" accept="image/*" required
                           class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-primary file:text-white hover:file:bg-primary-dark">
                    <p class="mt-1 text-sm text-gray-500">Upload the main product image. Max size: 5MB. Formats: JPG, PNG, GIF, WebP</p>
                </div>

                <div>
                    <label for="gallery_images" class="block text-sm font-medium text-gray-700">Gallery Images (Optional)</label>
                    <input type="file" name="gallery_images[]" id="gallery_images" accept="image/*" multiple
                           class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200">
                    <p class="mt-1 text-sm text-gray-500">Upload additional product images. You can select multiple files.</p>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end space-x-4">
            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-md text-sm font-medium">
                Cancel
            </a>
            <button type="submit" class="bg-primary hover:bg-primary-dark text-white px-6 py-2 rounded-md text-sm font-medium">
                Add Product
            </button>
        </div>
    </form>
</div>

<script>
// Form validation
document.getElementById('sale_price').addEventListener('input', function() {
    const regularPrice = parseFloat(document.getElementById('price').value) || 0;
    const salePrice = parseFloat(this.value) || 0;
    
    if (salePrice > 0 && salePrice >= regularPrice) {
        this.setCustomValidity('Sale price must be less than regular price');
    } else {
        this.setCustomValidity('');
    }
});

// File upload preview (optional enhancement)
document.getElementById('main_image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            // You can add image preview functionality here
        };
        reader.readAsDataURL(file);
    }
});
</script>

<?php include '../includes/admin_footer.php'; ?>