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

$purchase_id = isset($_GET['purchase_id']) ? filter_input(INPUT_GET, 'purchase_id', FILTER_VALIDATE_INT) : 0;

if (!$purchase_id) {
    die("Invalid purchase ID: The purchase ID parameter is missing or invalid.");
}

// Fetch purchase details from purchase_headers
$sql = "SELECT ph.*, s.name as seller_name, s.address as seller_address 
        FROM purchase_headers ph 
        LEFT JOIN sellers s ON ph.seller_id = s.id 
        WHERE ph.id = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare error: " . $conn->error);
}
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows == 0) {
    die("Purchase not found for purchase ID: " . htmlspecialchars($purchase_id));
}

$purchase = $result->fetch_assoc();
$invoice_number = $purchase['invoice_number'];
$stmt->close();

// Fetch purchase items
$itemsSql = "SELECT pi.quantity, pi.price as unit_price, pi.total, pi.unit, pi.type, p.name as product_name 
             FROM purchase_items pi 
             LEFT JOIN products p ON pi.product_id = p.id 
             WHERE pi.purchase_id = ?";
$itemsStmt = $conn->prepare($itemsSql);
if ($itemsStmt === false) {
    die("Prepare error: " . $conn->error);
}
$itemsStmt->bind_param("i", $purchase_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$items = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Include the invoice template
include 'invoice_template.php';
?>