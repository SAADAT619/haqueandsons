<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}
include '../config/database.php';
include_once '../core/functions.php';

$invoice_number = isset($_GET['invoice_number']) ? sanitizeInput($_GET['invoice_number']) : '';

if (empty($invoice_number)) {
    die("Invalid invoice number");
}

// Fetch purchase details
$sql = "SELECT purchases.*, sellers.name as seller_name, sellers.address as seller_address, 
        products.name as product_name 
        FROM purchases 
        LEFT JOIN sellers ON purchases.seller_id = sellers.id 
        LEFT JOIN products ON purchases.product_id = products.id 
        WHERE purchases.invoice_number = '$invoice_number'";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    die("Invoice not found");
}

$purchase = $result->fetch_assoc();

// Include the invoice template
include 'invoice_template.php';
?>