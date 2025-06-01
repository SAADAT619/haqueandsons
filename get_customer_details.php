<?php
include 'config/database.php';
include 'core/functions.php';

if (isset($_GET['id'])) {
    $customerId = sanitizeInput($_GET['id']);
    $customer = getCustomerDetails($customerId);
    if ($customer) {
        echo json_encode($customer);
    } else {
        echo json_encode(array('error' => 'Customer not found'));
    }
} else {
    echo json_encode(array('error' => 'Invalid request'));
}
?>