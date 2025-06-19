<?php
include '../config/database.php';
include '../core/functions.php';

header('Content-Type: application/json');

$sale_id = isset($_GET['sale_id']) ? intval($_GET['sale_id']) : 0;
$response = ['products' => []];

if ($sale_id > 0) {
    $sql = "SELECT sale_items.*, products.name as product_name
            FROM sale_items
            LEFT JOIN products ON sale_items.product_id = products.id
            WHERE sale_items.sale_id = $sale_id";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $response['products'][] = $row;
        }
    } else {
        $response['error'] = "Error fetching sale items: " . $conn->error;
    }
} else {
    $response['error'] = "Invalid sale ID";
}

echo json_encode($response);
$conn->close();
?>