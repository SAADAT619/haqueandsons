<?php
// controllers/BuyController.php

require_once '../models/Buy.php';
require_once '../models/Product.php';

$buyModel = new Buy();
$productModel = new Product();

if ($_GET['action'] == 'add') {
    $data = $_POST;

    // Validate data (important!)
    if (empty($data['seller_id']) || empty($data['product_id']) || empty($data['quantity']) || empty($data['price']) || empty($data['paid_amount']) || empty($data['buy_date'])) {
        echo "Error: Required fields are missing.";
        exit;
    }

    $data['due_amount'] = $data['price'] * $data['quantity'] - $data['paid_amount'];

    if ($buyModel->addBuy($data)) {
        $productModel->updateQuantityAfterBuy($data['product_id'], $data['quantity']);
        header('Location: ../views/buy/buy_invoice.php?id=' . $buyModel->db->dbh->lastInsertId());
    } else {
        echo "Error adding buy.";
    }
}
?>