<?php
require_once '../includes/admin_auth.php';
require_once '../includes/admin_functions.php';

// Require admin login
requireAdminLogin();

$product_id = intval($_GET['id'] ?? 0);
$errors = [];
$success_message = '';

// Get product details
$product = $db->fetchRow("SELECT * FROM " . DB_PREFIX . "products WHERE id = ?", [$product_id]);

if (!$product) {
    $_SESSION['error_message'] = 'Product not found.';
    header('Location: index.php');
    exit;
}

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
        
        $main_image = $product['image']; // Keep existing image by default
        $gallery_images = $product['gallery'] ? explode(',', $product['gallery']) : [];
        
        // Handle main image upload (if new image provided)
        if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
            $upload_result = handleFileUpload($_FILES['main_image'], IMAGES_PATH);
            if ($upload_result['success']) {
                // Delete old image
                deleteFile(IMAGES_PATH . '/' . $product['image']);
                $main_image = $upload_result['filename'];
            } else {
                $errors[] = 'Main image upload failed: ' . $upload_result['message'];
            }
        }
        
        // Handle gallery images (if new images provided)
        if (isset($_FILES['gallery_images'])) {
            $new_gallery_images = [];
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
                        $new_gallery_images[] = $upload_result['filename'];
                    }
                }
            }
            
            if (!empty($new_gallery_images)) {
                // Delete old gallery images
                foreach ($gallery_images as $old_image) {
                    deleteFile(IMAGES_PATH . '/' . $old_image);
                }
                $gallery_images = $new_gallery_images;
            }
        }
        
        // Update product if no errors
        if (empty($errors)) {
            try {
                $update_data = [
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
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                $result = $db->update('products', $update_data, 'id = ?', [$product_id]);
                
                if ($result !== false) {
                    // Log admin activity
                    logAdminActivity('update', 'products', $product_id, "Updated product: {$name}");
                    
                    $_SESSION['success_message'] = 'Product updated successfully!';
                    header('Location: index.php');
                    exit;
                } else {
                    throw new Exception('Failed to update product');
                }
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
} else {
    // Pre-populate form with existing product data
    $_POST = $product;
}

$page_title = 'Edit Product';
include '../includes/admin_header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Edit Product</h1>
            <p class="text-gray-600">Update product information</p>
        </div>
        <div class="flex space-x-2">
            <a href="<?php echo BASE_URL; ?>/products/product_detail.php?id=<?php echo $product_id; ?>" 
               target="_blank" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                View Product
            </a>
            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-md text-sm font-medium">
                ← Back to Products
            </a>
        </div>
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
                        <option value="draft" <?php echo (($_POST['status'] ?? '') === 'draft') ? 'selected' : ''; ?>>Draft</option>
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
                               <?php echo (($_POST['featured'] ?? 0) == 1) ? 'checked' : ''; ?>>
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
                <!-- Current Main Image -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Current Main Image</label>
                    <?php if ($product['image']): ?>
                    <img src="<?php echo IMAGES_URL . '/' . htmlspecialchars($product['image']); ?>" 
                         alt="Current main image" class="w-32 h-32 object-cover rounded-lg border">
                    <?php endif; ?>
                </div>
                
                <div>
                    <label for="main_image" class="block text-sm font-medium text-gray-700">Update Main Image</label>
                    <input type="file" name="main_image" id="main_image" accept="image/*"
                           class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-primary file:text-white hover:file:bg-primary-dark">
                    <p class="mt-1 text-sm text-gray-500">Leave empty to keep current image. Max size: 5MB. Formats: JPG, PNG, GIF, WebP</p>
                </div>

                <!-- Current Gallery Images -->
                <?php if ($product['gallery']): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Current Gallery Images</label>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach (explode(',', $product['gallery']) as $gallery_img): ?>
                        <img src="<?php echo IMAGES_URL . '/' . htmlspecialchars($gallery_img); ?>" 
                             alt="Gallery image" class="w-24 h-24 object-cover rounded border">
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div>
                    <label for="gallery_images" class="block text-sm font-medium text-gray-700">Update Gallery Images</label>
                    <input type="file" name="gallery_images[]" id="gallery_images" accept="image/*" multiple
                           class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200">
                    <p class="mt-1 text-sm text-gray-500">Leave empty to keep current gallery. Selecting new images will replace all current gallery images.</p>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end space-x-4">
            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-md text-sm font-medium">
                Cancel
            </a>
            <button type="submit" class="bg-primary hover:bg-primary-dark text-white px-6 py-2 rounded-md text-sm font-medium">
                Update Product
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
</script>

<?php include '../includes/admin_footer.php'; ?>