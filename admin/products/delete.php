<?php
require_once '../includes/admin_auth.php';
require_once '../includes/admin_functions.php';

// Require admin login
requireAdminLogin();

// Handle POST request only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$product_id = intval($_POST['product_id'] ?? 0);

// Validate CSRF token
if (!validateAdminCSRF($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Invalid security token.';
    header('Location: index.php');
    exit;
}

// Get product details
$product = $db->fetchRow("SELECT * FROM " . DB_PREFIX . "products WHERE id = ?", [$product_id]);

if (!$product) {
    $_SESSION['error_message'] = 'Product not found.';
    header('Location: index.php');
    exit;
}

try {
    $db->beginTransaction();
    
    // Delete product from database
    $result = $db->delete('products', 'id = ?', [$product_id]);
    
    if ($result) {
        // Delete associated images
        if ($product['image']) {
            deleteFile(IMAGES_PATH . '/' . $product['image']);
        }
        
        if ($product['gallery']) {
            $gallery_images = explode(',', $product['gallery']);
            foreach ($gallery_images as $img) {
                deleteFile(IMAGES_PATH . '/' . $img);
            }
        }
        
        // Delete related records (cart items, wishlist items, reviews)
        $db->delete('cart', 'product_id = ?', [$product_id]);
        $db->delete('wishlist', 'product_id = ?', [$product_id]);
        $db->delete('testimonials', 'product_id = ?', [$product_id]);
        
        $db->commit();
        
        // Log admin activity
        logAdminActivity('delete', 'products', $product_id, "Deleted product: {$product['name']}");
        
        $_SESSION['success_message'] = 'Product deleted successfully.';
    } else {
        throw new Exception('Failed to delete product');
    }
} catch (Exception $e) {
    $db->rollback();
    $_SESSION['error_message'] = 'Error deleting product: ' . $e->getMessage();
}

header('Location: index.php');
exit;
?>