<?php
session_start();
if (!isset($_SESSION['user_email'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit();
}
include '../core/functions.php';

header('Content-Type: text/plain');
echo generateInvoiceNumber();
?>