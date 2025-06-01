<?php
include '../config/database.php';
include '../core/functions.php';

header('Content-Type: application/json');

// Initialize response array
$response = [];

// Use a try-catch block to handle errors and ensure connection is closed
try {
    // Check if customer_id is provided
    if (!isset($_GET['customer_id'])) {
        $response = ['error' => 'Customer ID not provided'];
        echo json_encode($response);
        exit;
    }

    // Sanitize and validate customer_id
    $customer_id = sanitizeInput($_GET['customer_id']);
    if (!is_numeric($customer_id) || (int)$customer_id <= 0) {
        $response = ['error' => 'Invalid Customer ID. It must be a positive integer.'];
        echo json_encode($response);
        exit;
    }
    $customer_id = (int)$customer_id;

    // Optional: Check if the customer exists
    $stmt_check = $conn->prepare("SELECT id FROM customers WHERE id = ?");
    if ($stmt_check === false) {
        throw new Exception('Failed to prepare statement for customer check: ' . $conn->error);
    }
    $stmt_check->bind_param("i", $customer_id);
    if (!$stmt_check->execute()) {
        throw new Exception('Failed to execute customer check query: ' . $stmt_check->error);
    }
    $result_check = $stmt_check->get_result();
    if ($result_check->num_rows === 0) {
        $response = ['error' => 'Customer not found'];
        echo json_encode($response);
        $stmt_check->close();
        exit;
    }
    $stmt_check->close();

    // Fetch the total due for the customer
    $stmt = $conn->prepare("SELECT SUM(due) as total_due FROM sales WHERE customer_id = ?");
    if ($stmt === false) {
        throw new Exception('Failed to prepare statement for due calculation: ' . $conn->error);
    }
    $stmt->bind_param("i", $customer_id);
    if (!$stmt->execute()) {
        throw new Exception('Failed to execute due calculation query: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    // Format previous_due as a float with 2 decimal places
    $previous_due = isset($row['total_due']) ? floatval($row['total_due']) : 0.0;
    $response = ['previous_due' => number_format($previous_due, 2, '.', '')];
    echo json_encode($response);

} catch (Exception $e) {
    // Log the error and return a generic error message to the client
    error_log("Error in get_customer_due.php: " . $e->getMessage());
    $response = ['error' => 'An unexpected error occurred while fetching the previous due.'];
    echo json_encode($response);
} finally {
    // Ensure the database connection is closed
    $conn->close();
}
?>