<?php
// core/functions.php

// Include database connection
include_once __DIR__ . '/../config/database.php';

// Define log path
$log_dir = __DIR__ . '/../logs';
$log_file = $log_dir . '/functions.log';

// Create logs directory if it doesn't exist
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Sanitize input for database queries
function sanitizeInput($data) {
    global $conn;
    if ($conn && $data !== null) {
        return mysqli_real_escape_string($conn, trim($data));
    }
    file_put_contents($log_file, "sanitizeInput: No database connection\n", FILE_APPEND);
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Fetch all shop settings
function getAllShopSettings($conn) {
    $settings = [];
    if (!$conn) {
        file_put_contents($log_file, "getAllShopSettings: No database connection\n", FILE_APPEND);
        return $settings;
    }
    try {
        $sql = "SELECT setting_key, value FROM settings";
        file_put_contents($log_file, "getAllShopSettings: Executing query: $sql\n", FILE_APPEND);
        $result = $conn->query($sql);
        if (!$result) {
            file_put_contents($log_file, "getAllShopSettings Query Error: " . $conn->error . "\n", FILE_APPEND);
            return $settings;
        }
        $row_count = $result->num_rows;
        file_put_contents($log_file, "getAllShopSettings: Found $row_count rows\n", FILE_APPEND);
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['value'];
            file_put_contents($log_file, "getAllShopSettings: Added setting: {$row['setting_key']} = {$row['value']}\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        file_put_contents($log_file, "getAllShopSettings Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    return $settings;
}

// Generate a unique invoice number for purchases
function generateInvoiceNumber($conn) {
    $prefix = "PUR-" . date("Ymd");
    $sql = "SELECT invoice_number FROM purchase_headers WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("generateInvoiceNumber Prepare Error: " . $conn->error);
        return false;
    }
    $likePattern = $prefix . '%';
    $stmt->bind_param("s", $likePattern);
    if (!$stmt->execute()) {
        error_log("generateInvoiceNumber Execute Error: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    $number = 1;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastNumber = (int)substr($row['invoice_number'], -3);
        $number = $lastNumber + 1;
    }
    return $prefix . str_pad($number, 3, "0", STR_PAD_LEFT);
}

// Get category name by ID
function getCategoryName($category_id, $conn) {
    if (!$category_id) {
        return 'Unknown';
    }
    $category_id = (int)$category_id;
    $sql = "SELECT name FROM categories WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("getCategoryName Prepare Error: " . $conn->error);
        return 'Unknown';
    }
    $stmt->bind_param("i", $category_id);
    if (!$stmt->execute()) {
        error_log("getCategoryName Execute Error: " . $stmt->error);
        return 'Unknown';
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['name'] ?? 'Unknown';
}

// Function to get total sales
function getTotalSales($conn) {
    $sql = "SELECT SUM(total) as total_sales FROM sales";
    $result = $conn->query($sql);
    if (!$result) {
        error_log("getTotalSales Query Error: " . $conn->error);
        return 0;
    }
    $row = $result->fetch_assoc();
    return $row['total_sales'] ? floatval($row['total_sales']) : 0;
}

// Function to get monthly sales
function getMonthlySales($conn) {
    $monthlySales = array();
    for ($month = 1; $month <= 12; $month++) {
        $sql = "SELECT SUM(total) as monthly_sale FROM sales WHERE MONTH(sale_date) = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("getMonthlySales Prepare Error: " . $conn->error);
            $monthlySales[$month] = 0;
            continue;
        }
        $stmt->bind_param("i", $month);
        if (!$stmt->execute()) {
            error_log("getMonthlySales Execute Error: " . $stmt->error);
            $monthlySales[$month] = 0;
            continue;
        }
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $monthlySales[$month] = $row['monthly_sale'] ? floatval($row['monthly_sale']) : 0;
    }
    return $monthlySales;
}

// Function to get product stock
function getProductStock($conn) {
    $sql = "SELECT p.id, p.name, p.category_id, p.quantity, p.price, p.unit, p.brand_name, p.type, c.name as category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            ORDER BY p.category_id ASC, p.name ASC";
    $result = $conn->query($sql);
    if (!$result) {
        error_log("getProductStock Query Error: " . $conn->error);
        return [];
    }
    $products = array();
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $row['quantity'] = floatval($row['quantity']);
            $row['price'] = floatval($row['price']);
            $products[] = $row;
        }
    }
    return $products;
}

// Function to get product stock by ID
function getProductStockById($conn, $product_id) {
    $product_id = (int)$product_id;
    $sql = "SELECT * FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("getProductStockById Prepare Error: " . $conn->error);
        return ['quantity' => 0, 'unit' => ''];
    }
    $stmt->bind_param("i", $product_id);
    if (!$stmt->execute()) {
        error_log("getProductStockById Execute Error: " . $stmt->error);
        return ['quantity' => 0, 'unit' => ''];
    }
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $row['quantity'] = floatval($row['quantity']);
        $row['price'] = floatval($row['price']);
        return $row;
    }
    return ['quantity' => 0, 'unit' => ''];
}

// Function to get shop settings
function getShopSetting($key, $conn) {
    $key = sanitizeInput($key);
    $sql = "SELECT value FROM settings WHERE setting_key = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("getShopSetting Prepare Error: " . $conn->error);
        return '';
    }
    $stmt->bind_param("s", $key);
    if (!$stmt->execute()) {
        error_log("getShopSetting Execute Error: " . $stmt->error);
        return '';
    }
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['value'];
    }
    return '';
}

// Function to update shop settings
function updateShopSetting($key, $value, $conn) {
    $key = sanitizeInput($key);
    $value = sanitizeInput($value);
    $sql = "INSERT INTO settings (setting_key, value) VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE value = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("updateShopSetting Prepare Error: " . $conn->error);
        return false;
    }
    $stmt->bind_param("sss", $key, $value, $value);
    if (!$stmt->execute()) {
        error_log("updateShopSetting Execute Error: " . $stmt->error);
        return false;
    }
    return true;
}
?>