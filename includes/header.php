<?php
// Ensure $conn is available by including the database configuration
if (!isset($conn)) {
    include '../config/database.php';
}
if (!function_exists('getShopSetting')) {
    include '../core/functions.php';
}
$shopName = getShopSetting('shop_name', $conn) ?: 'Haque&Sons';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($shopName); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="container">