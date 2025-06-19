<?php
include '../config/database.php';
include '../core/functions.php';

header('Content-Type: application/json');

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$response = [];

if ($sale_id > 0) {
    $sql = "SELECT * FROM sales WHERE id = $sale_id";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $response = $result->fetch_assoc();
    } else {
        $response['error'] = "Sale not found for ID: $sale_id";
    }
} else {
    $response['error'] = "Invalid sale ID";
}

echo json_encode($response);
$conn->close();
?>