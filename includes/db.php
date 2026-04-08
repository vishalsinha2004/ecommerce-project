<?php

/**
 * Database Connection and Utilities
 * Dynamic Ecommerce Website - Women's Dresses
 * * This file handles database connections, query utilities, and database operations
 * with security best practices and performance optimizations.
 * * @author Your Name
 * @version 1.3
 * @since 2025-01-01
 */

// Prevent direct access
if (!defined('CONFIG_LOADED')) {
    require_once __DIR__ . '/config.php';
}

/**
 * =============================================================================
 * DATABASE CONNECTION CLASS
 * =============================================================================
 */

class Database
{
    private static $instance = null;
    private $pdo = null;
    private $transaction_count = 0;
    private $query_count = 0;
    private $query_log = [];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * Get database instance (Singleton pattern)
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish database connection
     */
    private function connect()
    {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);

            // Set SQL mode for better MySQL compatibility
            $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");

            // Set timezone
            $this->pdo->exec("SET time_zone = '+00:00'");

            if (ENABLE_DEBUG_MODE) {
                logMessage('Database connection established successfully', 'INFO');
            }
        } catch (PDOException $e) {
            $error_message = "Database connection failed: " . $e->getMessage();
            logMessage($error_message, 'ERROR');

            if (ENVIRONMENT === 'development') {
                throw new Exception($error_message);
            } else {
                // In production, show generic error and exit gracefully
                http_response_code(503);
                include ROOT_PATH . '/error-pages/503.html';
                exit();
            }
        }
    }

    /**
     * Get PDO instance
     */
    public function getPDO()
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    /**
     * Execute a prepared statement
     */
    public function execute($query, $params = [])
    {
        try {
            $this->query_count++;

            if (ENABLE_DEBUG_MODE) {
                $this->query_log[] = [
                    'query' => $query,
                    'params' => $params,
                    'time' => microtime(true)
                ];
            }

            $stmt = $this->pdo->prepare($query);
            $result = $stmt->execute($params);

            if (ENABLE_DEBUG_MODE) {
                $last_query = &$this->query_log[count($this->query_log) - 1];
                $last_query['execution_time'] = microtime(true) - $last_query['time'];
            }

            return $stmt;
        } catch (PDOException $e) {
            $error_message = "Query execution failed: " . $e->getMessage() . " | Query: " . $query;
            logMessage($error_message, 'ERROR');

            if (ENVIRONMENT === 'development') {
                throw new Exception($error_message);
            }

            return false;
        }
    }

    /**
     * Fetch single row
     */
    public function fetchRow($query, $params = [])
    {
        $stmt = $this->execute($query, $params);
        return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }

    /**
     * Fetch all rows
     */
    public function fetchAll($query, $params = [])
    {
        $stmt = $this->execute($query, $params);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : false;
    }

    /**
     * Insert record and return last insert ID
     */
    public function insert($table, $data)
    {
        $columns = implode(',', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $query = "INSERT INTO " . DB_PREFIX . "{$table} ({$columns}) VALUES ({$placeholders})";

        $stmt = $this->execute($query, $data);

        if ($stmt) {
            return $this->pdo->lastInsertId();
        }

        return false;
    }

    /**
     * Update records
     */
    public function update($table, $data, $where, $where_params = [])
    {
        $set_clause = [];
        foreach (array_keys($data) as $key) {
            $set_clause[] = "{$key} = :{$key}";
        }
        $set_clause = implode(', ', $set_clause);

        $query = "UPDATE " . DB_PREFIX . "{$table} SET {$set_clause} WHERE {$where}";

        $params = array_merge($data, $where_params);
        $stmt = $this->execute($query, $params);

        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * Delete records
     */
    public function delete($table, $where, $params = [])
    {
        $query = "DELETE FROM " . DB_PREFIX . "{$table} WHERE {$where}";
        $stmt = $this->execute($query, $params);

        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * Count records
     */
    public function count($table, $where = '1', $params = [])
    {
        $query = "SELECT COUNT(*) as count FROM " . DB_PREFIX . "{$table} WHERE {$where}";
        $result = $this->fetchRow($query, $params);

        return $result ? (int)$result['count'] : 0;
    }

    /**
     * Check if record exists
     */
    public function exists($table, $where, $params = [])
    {
        return $this->count($table, $where, $params) > 0;
    }

    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        if ($this->transaction_count == 0) {
            $this->pdo->beginTransaction();
        }
        $this->transaction_count++;
    }

    /**
     * Commit transaction
     */
    public function commit()
    {
        $this->transaction_count--;
        if ($this->transaction_count == 0) {
            $this->pdo->commit();
        }
    }

    /**
     * Rollback transaction
     */
    public function rollback()
    {
        if ($this->transaction_count > 0) {
            $this->transaction_count = 0;
            $this->pdo->rollback();
        }
    }

    /**
     * Get query statistics
     */
    public function getQueryStats()
    {
        return [
            'query_count' => $this->query_count,
            'query_log' => $this->query_log
        ];
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization - FIXED VISIBILITY
     */
    public function __wakeup() {}
}

/**
 * =============================================================================
 * GLOBAL DATABASE INSTANCE
 * =============================================================================
 */

// Create global database instance
try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
} catch (Exception $e) {
    logMessage("Failed to initialize database: " . $e->getMessage(), 'ERROR');

    if (ENVIRONMENT === 'development') {
        die("Database initialization failed: " . $e->getMessage());
    } else {
        http_response_code(503);
        include ROOT_PATH . '/error-pages/503.html';
        exit();
    }
}

/**
 * =============================================================================
 * DATABASE UTILITY FUNCTIONS
 * =============================================================================
 */

/**
 * Escape and quote string for SQL
 */
function escapeString($string)
{
    global $db;
    return $db->getPDO()->quote($string);
}

/**
 * Build WHERE clause from array conditions
 */
function buildWhereClause($conditions, &$params = [])
{
    if (empty($conditions)) {
        return '1';
    }

    $where_parts = [];
    foreach ($conditions as $field => $value) {
        if (is_array($value)) {
            // Handle IN clause
            $placeholders = [];
            foreach ($value as $i => $v) {
                $param_name = "{$field}_{$i}";
                $placeholders[] = ":{$param_name}";
                $params[$param_name] = $v;
            }
            $where_parts[] = "{$field} IN (" . implode(',', $placeholders) . ")";
        } elseif ($value === null) {
            $where_parts[] = "{$field} IS NULL";
        } else {
            $where_parts[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }
    }

    return implode(' AND ', $where_parts);
}

/**
 * Build ORDER BY clause
 */
function buildOrderClause($order_by)
{
    if (empty($order_by)) {
        return '';
    }

    if (is_string($order_by)) {
        return "ORDER BY {$order_by}";
    }

    if (is_array($order_by)) {
        $order_parts = [];
        foreach ($order_by as $field => $direction) {
            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $order_parts[] = "{$field} {$direction}";
        }
        return "ORDER BY " . implode(', ', $order_parts);
    }

    return '';
}

/**
 * Build LIMIT clause with pagination
 */
function buildLimitClause($page = 1, $per_page = 10)
{
    $page = max(1, (int)$page);
    $per_page = max(1, min(100, (int)$per_page)); // Max 100 items per page
    $offset = ($page - 1) * $per_page;

    return "LIMIT {$offset}, {$per_page}";
}

/**
 * =============================================================================
 * ECOMMERCE-SPECIFIC DATABASE FUNCTIONS
 * =============================================================================
 */

/**
 * Get products with filtering and pagination - FULLY WORKING SEARCH
 */
function getProducts($filters = [], $order_by = 'created_at DESC', $page = 1, $per_page = PRODUCTS_PER_PAGE)
{
    global $db;

    $params = [];
    $where_conditions = ['p.status = :status'];
    $params['status'] = 'active';
    
    // Default order clause
    $order_clause = "ORDER BY p.featured DESC, p.created_at DESC";

    // ===============================================
    // SEARCH FILTER (EXPANDED & FIXED)
    // ===============================================
    if (!empty($filters['search'])) {
        $search_term = $filters['search'];
        $search_param_wildcard = '%' . $search_term . '%';
        
        // Define fields to search against
        $search_fields = [
            'p.name', 'p.description', 'p.short_description', 'p.tags', 'p.sku', 
            'p.available_colors', 'p.available_sizes', 'c.name', 'c.description'
        ];
        
        $search_conditions = [];
        // Create a unique placeholder for each field to avoid PDO error
        foreach ($search_fields as $index => $field) {
            $placeholder = ':search' . $index;
            $search_conditions[] = "{$field} LIKE {$placeholder}";
            $params[$placeholder] = $search_param_wildcard;
        }
        $where_conditions[] = '(' . implode(' OR ', $search_conditions) . ')';

        // RELEVANCE-BASED SORTING when a search is active
        $params['exact_search'] = $search_term;
        $params['search_name_order'] = $search_param_wildcard;
        $params['search_cat_name_order'] = $search_param_wildcard;
        $params['search_tags_order'] = $search_param_wildcard;
        $params['search_short_desc_order'] = $search_param_wildcard;

        $order_clause = "ORDER BY 
            (CASE
                WHEN p.sku = :exact_search THEN 0
                WHEN p.name LIKE :search_name_order THEN 1
                WHEN c.name LIKE :search_cat_name_order THEN 2
                WHEN p.tags LIKE :search_tags_order THEN 3
                WHEN p.short_description LIKE :search_short_desc_order THEN 4
                ELSE 5 
            END) ASC, 
            p.featured DESC,
            p.created_at DESC";
    }

    // Category Filter
    if (!empty($filters['category'])) {
        $where_conditions[] = 'p.category_id = :category_id';
        $params['category_id'] = $filters['category'];
    }

    // Price Range Filters
    if (!empty($filters['min_price'])) {
        $where_conditions[] = '(COALESCE(p.sale_price, p.price) >= :min_price)';
        $params['min_price'] = $filters['min_price'];
    }
    if (!empty($filters['max_price'])) {
        $where_conditions[] = '(COALESCE(p.sale_price, p.price) <= :max_price)';
        $params['max_price'] = $filters['max_price'];
    }

    // Color Filter
    if (!empty($filters['color'])) {
        $where_conditions[] = 'FIND_IN_SET(:color, p.available_colors)';
        $params['color'] = $filters['color'];
    }
    
    // Size Filter
    if (!empty($filters['size'])) {
        $where_conditions[] = 'FIND_IN_SET(:size, p.available_sizes)';
        $params['size'] = $filters['size'];
    }

    // Special Filters
    if (!empty($filters['sale'])) {
        $where_conditions[] = 'p.sale_price IS NOT NULL AND p.sale_price < p.price';
    }
    if (!empty($filters['new'])) {
        $where_conditions[] = 'p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    }
    if (!empty($filters['featured'])) {
        $where_conditions[] = 'p.featured = 1';
    }

    $where_clause = implode(' AND ', $where_conditions);
    
    // ===============================================
    // DYNAMIC SORTING LOGIC (if not searching)
    // ===============================================
    if (empty($filters['search'])) {
        $sort_parts = explode(' ', $order_by);
        $sort_field = $sort_parts[0];
        $sort_direction = isset($sort_parts[1]) && in_array(strtoupper($sort_parts[1]), ['ASC', 'DESC']) ? strtoupper($sort_parts[1]) : 'DESC';

        $sort_mapping = [
            'created_at' => 'p.created_at',
            'price'      => 'final_price',
            'name'       => 'p.name',
            'featured'   => 'p.featured',
            'rating'     => 'average_rating'
        ];
        
        $order_field = $sort_mapping[$sort_field] ?? 'p.created_at';
        $order_clause = "ORDER BY {$order_field} {$sort_direction}";
    }

    $limit_clause = buildLimitClause($page, $per_page);
    
    // ===============================================
    // MAIN QUERY WITH RATING JOIN
    // ===============================================
    $query = "SELECT 
                p.*,
                c.name as category_name,
                (CASE WHEN p.sale_price IS NOT NULL THEN p.sale_price ELSE p.price END) as final_price,
                (CASE WHEN p.sale_price IS NOT NULL AND p.price > 0 THEN ROUND(((p.price - p.sale_price) / p.price) * 100) ELSE 0 END) as discount_percentage,
                COALESCE(tr.average_rating, 0) as average_rating
              FROM " . DB_PREFIX . "products p
              LEFT JOIN " . DB_PREFIX . "categories c ON p.category_id = c.id
              LEFT JOIN (
                  SELECT product_id, AVG(rating) as average_rating 
                  FROM " . DB_PREFIX . "testimonials 
                  WHERE status = 'approved' 
                  GROUP BY product_id
              ) tr ON p.id = tr.product_id
              WHERE {$where_clause}
              {$order_clause}
              {$limit_clause}";

    return $db->fetchAll($query, $params);
}

/**
 * Get the total count of products matching filters.
 * This is used for pagination.
 */
function getProductsCount($filters = [])
{
    global $db;
    $params = [];
    $where_conditions = ['p.status = :status'];
    $params['status'] = 'active';

    // This logic MUST mirror the filtering in getProducts()
    if (!empty($filters['search'])) {
        $search_term = $filters['search'];
        $search_param_wildcard = '%' . $search_term . '%';
        $search_fields = [
            'p.name', 'p.description', 'p.short_description', 'p.tags', 'p.sku', 
            'p.available_colors', 'p.available_sizes', 'c.name', 'c.description'
        ];
        $search_conditions = [];
        foreach ($search_fields as $index => $field) {
            $placeholder = ':search' . $index;
            $search_conditions[] = "{$field} LIKE {$placeholder}";
            $params[$placeholder] = $search_param_wildcard;
        }
        $where_conditions[] = '(' . implode(' OR ', $search_conditions) . ')';
    }

    if (!empty($filters['category'])) {
        $where_conditions[] = 'p.category_id = :category_id';
        $params['category_id'] = $filters['category'];
    }

    if (!empty($filters['min_price'])) {
        $where_conditions[] = '(COALESCE(p.sale_price, p.price) >= :min_price)';
        $params['min_price'] = $filters['min_price'];
    }
    if (!empty($filters['max_price'])) {
        $where_conditions[] = '(COALESCE(p.sale_price, p.price) <= :max_price)';
        $params['max_price'] = $filters['max_price'];
    }

    if (!empty($filters['color'])) {
        $where_conditions[] = 'FIND_IN_SET(:color, p.available_colors)';
        $params['color'] = $filters['color'];
    }
    
    if (!empty($filters['size'])) {
        $where_conditions[] = 'FIND_IN_SET(:size, p.available_sizes)';
        $params['size'] = $filters['size'];
    }

    if (!empty($filters['sale'])) {
        $where_conditions[] = 'p.sale_price IS NOT NULL AND p.sale_price < p.price';
    }
    if (!empty($filters['new'])) {
        $where_conditions[] = 'p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    }
    if (!empty($filters['featured'])) {
        $where_conditions[] = 'p.featured = 1';
    }

    $where_clause = implode(' AND ', $where_conditions);

    $query = "SELECT COUNT(p.id) as total
              FROM " . DB_PREFIX . "products p
              LEFT JOIN " . DB_PREFIX . "categories c ON p.category_id = c.id
              WHERE {$where_clause}";
              
    $result = $db->fetchRow($query, $params);
    return $result ? (int)$result['total'] : 0;
}


/**
 * Get cart items for a user
 */
function getCartItems($user_id = null, $session_id = null)
{
    global $db;

    if ($user_id) {
        $where = 'user_id = :user_id';
        $params = ['user_id' => $user_id];
    } else {
        $where = 'session_id = :session_id AND user_id IS NULL';
        $params = ['session_id' => $session_id];
    }

    $query = "SELECT c.*, p.name, p.price, p.sale_price, p.image, p.stock_quantity,
                CASE 
                    WHEN p.sale_price IS NOT NULL AND p.sale_price < p.price 
                    THEN p.sale_price 
                    ELSE p.price 
                END as unit_price
              FROM " . DB_PREFIX . "cart c
              JOIN " . DB_PREFIX . "products p ON c.product_id = p.id
              WHERE {$where} AND p.status = 'active'
              ORDER BY c.created_at DESC";

    return $db->fetchAll($query, $params);
}

/**
 * Clean up old cart items
 */
function cleanupOldCartItems()
{
    global $db;

    $lifetime_days = CART_LIFETIME / 86400; // Convert seconds to days

    $deleted_count = $db->delete(
        'cart',
        'updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)',
        ['days' => $lifetime_days]
    );

    if ($deleted_count > 0) {
        logMessage("Cleaned up {$deleted_count} old cart items", 'INFO');
    }

    return $deleted_count;
}

/**
 * Add product to wishlist
 */
function addToWishlist($user_id, $product_id)
{
    global $db;

    // Check if already in wishlist
    $exists = $db->exists('wishlist', 'user_id = :user_id AND product_id = :product_id', [
        'user_id' => $user_id,
        'product_id' => $product_id
    ]);

    if ($exists) {
        return false; // Already in wishlist
    }

    // Add to wishlist
    return $db->insert('wishlist', [
        'user_id' => $user_id,
        'product_id' => $product_id,
        'added_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Remove product from wishlist
 */
function removeFromWishlist($user_id, $product_id)
{
    global $db;

    return $db->delete('wishlist', 'user_id = :user_id AND product_id = :product_id', [
        'user_id' => $user_id,
        'product_id' => $product_id
    ]);
}

/**
 * Check if product is in user's wishlist
 */
function isInWishlist($user_id, $product_id)
{
    global $db;

    if (!$user_id) return false;

    return $db->exists('wishlist', 'user_id = :user_id AND product_id = :product_id', [
        'user_id' => $user_id,
        'product_id' => $product_id
    ]);
}

/**
 * Get wishlist count for user
 */
function getWishlistCount($user_id)
{
    global $db;

    if (!$user_id) return 0;

    return $db->count('wishlist', 'user_id = :user_id', ['user_id' => $user_id]);
}

// Log successful database initialization
if (ENABLE_DEBUG_MODE) {
    logMessage('Database utilities loaded successfully', 'INFO');
}