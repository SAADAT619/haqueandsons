<?php
session_start();
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}
include '../config/database.php';

// Get current date components
$currentDate = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

// Daily Sales
$dailySalesSql = "SELECT COALESCE(SUM(total), 0) as daily_sales 
                 FROM sales 
                 WHERE sale_date = ?";
$stmt = $conn->prepare($dailySalesSql);
$stmt->bind_param("s", $currentDate);
$stmt->execute();
$dailySales = $stmt->get_result()->fetch_assoc()['daily_sales'];

// Monthly Sales
$monthlySalesSql = "SELECT COALESCE(SUM(total), 0) as monthly_sales 
                   FROM sales 
                   WHERE sale_date BETWEEN ? AND ?";
$stmt = $conn->prepare($monthlySalesSql);
$stmt->bind_param("ss", $currentMonthStart, $currentMonthEnd);
$stmt->execute();
$monthlySales = $stmt->get_result()->fetch_assoc()['monthly_sales'];

// Daily Buy
$dailyBuySql = "SELECT COALESCE(SUM(total), 0) as daily_buy 
               FROM purchases 
               WHERE purchase_date = ? AND product_id IS NULL";
$stmt = $conn->prepare($dailyBuySql);
$stmt->bind_param("s", $currentDate);
$stmt->execute();
$dailyBuy = $stmt->get_result()->fetch_assoc()['daily_buy'];

// Monthly Buy
$monthlyBuySql = "SELECT COALESCE(SUM(total), 0) as monthly_buy 
                 FROM purchases 
                 WHERE purchase_date BETWEEN ? AND ? AND product_id IS NULL";
$stmt = $conn->prepare($monthlyBuySql);
$stmt->bind_param("ss", $currentMonthStart, $currentMonthEnd);
$stmt->execute();
$monthlyBuy = $stmt->get_result()->fetch_assoc()['monthly_buy'];

// Total Due
$totalDueSql = "SELECT COALESCE(SUM(due), 0) as total_due 
               FROM purchases 
               WHERE product_id IS NULL";
$stmt = $conn->prepare($totalDueSql);
$stmt->execute();
$totalDue = $stmt->get_result()->fetch_assoc()['total_due'];

// Return metrics as JSON
header('Content-Type: application/json');
echo json_encode([
    'daily_sales' => number_format($dailySales, 2),
    'monthly_sales' => number_format($monthlySales, 2),
    'daily_buy' => number_format($dailyBuy, 2),
    'monthly_buy' => number_format($monthlyBuy, 2),
    'total_due' => number_format($totalDue, 2)
]);
?>