<?php
include 'config/database.php';
include 'core/functions.php';

if (isset($_GET['id'])) {
    $sellerId = sanitizeInput($_GET['id']);
    $seller = getSellerDetails($sellerId);
    if ($seller) {
        echo json_encode($seller);
    } else {
        echo json_encode(array('error' => 'Seller not found'));
    }
} else {
    echo json_encode(array('error' => 'Invalid request'));
}
?>