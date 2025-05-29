<?php
// edit_purchase.php

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Assuming you have a database connection established and stored in $conn
// Replace with your actual database connection code
// Example:
$servername = "localhost";
$username = "your_username";
$password = "your_password";
$dbname = "your_dbname";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    // Fetch purchase data for editing
    $sql = "SELECT * FROM buys WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $purchase = $result->fetch_assoc();

    if (!$purchase) {
        echo "Purchase not found.";
        exit;
    }
} else {
    echo "Invalid request.";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $seller_id = $_POST['seller_id'];
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $price = $_POST['price'];
    $paid_amount = $_POST['paid_amount'];
    $buy_date = $_POST['buy_date'];
    $payment_method = $_POST['payment_method'];

    // Update purchase data in the database
    $sql = "UPDATE buys SET seller_id = ?, product_id = ?, quantity = ?, price = ?, paid_amount = ?, buy_date = ?, payment_method = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiissi", $seller_id, $product_id, $quantity, $price, $paid_amount, $buy_date, $payment_method, $id);

    if ($stmt->execute()) {
        header("Location: buy.php"); // Redirect to the buys list
        exit;
    } else {
        echo "Error updating purchase: " . $stmt->error;
    }
}

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Purchase</title>
</head>
<body>
    <h2>Edit Purchase</h2>
    <form method="post">
        <label>Seller ID:</label><input type="number" name="seller_id" value="<?php echo $purchase['seller_id']; ?>