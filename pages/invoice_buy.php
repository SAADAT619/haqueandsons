<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}

// Enable MySQLi error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Define log path
$log_dir = __DIR__ . '/logs';
$log_file = $log_dir . '/invoice_buy.log';

// Create logs directory if it doesn't exist
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

try {
    // Include dependencies
    include '../config/database.php';
    include_once '../core/functions.php';

    // Debug: Log connection status
    file_put_contents($log_file, "Connection status: " . (isset($conn) && $conn ? 'Connected' : 'Not connected') . "\n", FILE_APPEND);

    // Check database connection
    if (!$conn) {
        throw new Exception("Database connection failed: " . mysqli_connect_error());
    }

    // Initialize variables
    $settings = [
        'shop_name' => 'N/A',
        'address' => 'N/A',
        'phone' => 'N/A',
        'email' => 'N/A'
    ];
    $items = [];
    $purchase = null;

    $invoice_number = isset($_GET['invoice_number']) ? sanitizeInput($_GET['invoice_number']) : '';
    if (empty($invoice_number)) {
        throw new Exception("Invalid invoice number");
    }

    // Fetch settings
    $settings = getAllShopSettings($conn);
    file_put_contents($log_file, "Settings fetched: " . print_r($settings, true) . "\n", FILE_APPEND);
    if (empty($settings)) {
        // Debug: Output for testing (remove in production)
        file_put_contents($log_file, "No settings found, using defaults\n", FILE_APPEND);
        echo "<pre>Debug: No settings found in database. Check logs/functions.log and database connection.</pre>";
        $settings = [
            'shop_name' => 'N/A',
            'address' => 'N/A',
            'phone' => 'N/A',
            'email' => 'N/A'
        ];
    } else {
        $settings['phone'] = formatPhoneNumber($settings['phone'] ?? 'N/A');
    }

    // Fetch purchase header
    $stmt = $conn->prepare("
        SELECT ph.*, 
               s.name AS seller_name, s.address AS seller_address, s.phone AS seller_phone,
               pm.method AS payment_method
        FROM purchase_headers ph
        LEFT JOIN sellers s ON ph.seller_id = s.id
        LEFT JOIN payment_methods pm ON ph.payment_method_id = pm.id
        WHERE ph.invoice_number = ?
    ");
    $stmt->bind_param("s", $invoice_number);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $purchase = $result->fetch_assoc();
    } else {
        throw new Exception("Invoice not found for number: " . htmlspecialchars($invoice_number));
    }
    $stmt->close();

    // Fetch purchase items
    $stmt_items = $conn->prepare("
        SELECT pi.*, p.name AS product_name, p.brand_name, p.type AS product_type, p.unit AS product_unit
        FROM purchase_items pi
        LEFT JOIN products p ON pi.product_id = p.id
        WHERE pi.purchase_id = ?
    ");
    $stmt_items->bind_param("i", $purchase['id']);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();

    while ($row = $result_items->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt_items->close();
} catch (Exception $e) {
    file_put_contents($log_file, "Error in invoice_buy.php: " . $e->getMessage() . "\n", FILE_APPEND);
    die("Error: " . htmlspecialchars($e->getMessage()));
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}

// Include the invoice template
include 'invoice_template.php';
?>