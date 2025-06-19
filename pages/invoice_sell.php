<?php
session_start();
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}
include '../config/database.php';
include '../core/functions.php';

// Fetch invoice details based on invoice number
$invoice_number = isset($_GET['invoice_number']) ? sanitizeInput($_GET['invoice_number']) : '';
if (empty($invoice_number)) {
    die("Invalid invoice number");
}

// Fetch sale details with payment method
$saleSql = "SELECT sales.*, 
                   customers.name as customer_name, 
                   customers.phone, 
                   customers.address,
                   payment_methods.method as payment_method_name
            FROM sales
            LEFT JOIN customers ON sales.customer_id = customers.id
            LEFT JOIN payment_methods ON sales.payment_method_id = payment_methods.id
            WHERE sales.invoice_number = ?";
$stmt = $conn->prepare($saleSql);
if ($stmt === false) {
    die("Prepare error: " . $conn->error);
}
$stmt->bind_param("s", $invoice_number);
$stmt->execute();
$saleResult = $stmt->get_result();

if (!$saleResult || $saleResult->num_rows == 0) {
    die("Sale not found for invoice number: " . htmlspecialchars($invoice_number));
}
$sale = $saleResult->fetch_assoc();
$stmt->close();

// Fetch sale items
$saleItemsSql = "SELECT sale_items.*, 
                        products.name as product_name, 
                        products.unit
                 FROM sale_items
                 LEFT JOIN products ON sale_items.product_id = products.id
                 WHERE sale_items.sale_id = ?";
$stmt = $conn->prepare($saleItemsSql);
if ($stmt === false) {
    die("Prepare error: " . $conn->error);
}
$stmt->bind_param("i", $sale['id']);
$stmt->execute();
$saleItemsResult = $stmt->get_result();
if (!$saleItemsResult) {
    die("Error fetching sale items: " . $conn->error);
}
$stmt->close();

// Initialize shop details with default values
$shopDetails = [
    'shop_name' => 'Demo Cement Shop',
    'address' => '123 Demo Street, Sample City, SC 12345',
    'phone' => '(123) 456-7890',
    'email' => 'contact@demolocalshop.com'
];

// Fetch settings from the settings table
$settingsSql = "SELECT setting_key, value FROM settings WHERE setting_key IN ('shop_name', 'address', 'phone', 'email')";
$settingsResult = $conn->query($settingsSql);
if ($settingsResult && $settingsResult->num_rows > 0) {
    while ($row = $settingsResult->fetch_assoc()) {
        $shopDetails[$row['setting_key']] = $row['value'];
    }
}

// Handle shop details update (currently commented out but updated for settings table)
/*
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_shop_details'])) {
    $shop_name = isset($_POST['shop_name']) ? sanitizeInput($_POST['shop_name']) : '';
    $phone = isset($_POST['shop_phone']) ? sanitizeInput($_POST['shop_phone']) : '';
    $address = isset($_POST['shop_address']) ? sanitizeInput($_POST['shop_address']) : '';
    $email = isset($_POST['shop_email']) ? sanitizeInput($_POST['shop_email']) : '';
    
    // Validate inputs
    $errors = [];
    if (empty($shop_name)) {
        $errors[] = "Shop name is required.";
    }
    if (empty($phone)) {
        $errors[] = "Shop phone is required.";
    }
    if (empty($address)) {
        $errors[] = "Shop address is required.";
    }
    if (empty($email)) {
        $errors[] = "Shop email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (empty($errors)) {
        // Update shop details in the settings table
        $conn->begin_transaction();
        try {
            $sql = "INSERT INTO settings (setting_key, value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE value = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                throw new Exception("Prepare Error: " . $conn->error);
            }
            // Update shop_name
            $stmt->bind_param("sss", $key = "shop_name", $shop_name, $shop_name);
            if (!$stmt->execute()) {
                throw new Exception("Execute Error for shop_name: " . $stmt->error);
            }
            // Update phone
            $stmt->bind_param("sss", $key = "phone", $phone, $phone);
            if (!$stmt->execute()) {
                throw new Exception("Execute Error for phone: " . $stmt->error);
            }
            // Update address
            $stmt->bind_param("sss", $key = "address", $address, $address);
            if (!$stmt->execute()) {
                throw new Exception("Execute Error for address: " . $stmt->error);
            }
            // Update email
            $stmt->bind_param("sss", $key = "email", $email, $email);
            if (!$stmt->execute()) {
                throw new Exception("Execute Error for email: " . $stmt->error);
            }
            $stmt->close();
            $conn->commit();
            header("Location: invoice_sell.php?invoice_number=" . urlencode($invoice_number) . "&update=success");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = "Error updating shop details: " . $e->getMessage();
            error_log($errorMessage);
        }
    } else {
        $errorMessage = "Validation errors: " . implode("; ", $errors);
    }
}
*/
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo htmlspecialchars($invoice_number); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .invoice-container {
            max-width: 900px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }
        .header img.logo {
            max-width: 120px;
            margin-bottom: 10px;
        }
        .header h1 {
            font-size: 28px;
            color: #007bff;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 14px;
            color: #666;
        }
        .shop-details {
            margin: 20px 0;
            text-align: center;
            border-bottom: 2px solid #2e7d32;
            padding-bottom: 10px;
        }
        .shop-details h2 {
            margin: 0;
            color: #2e7d32;
            font-size: 24px;
        }
        .shop-details p {
            margin: 5px 0;
            color: #555;
            font-size: 14px;
        }
        .invoice-details {
            margin: 20px 0;
            padding: 15px;
            background-color: #e8f5e9;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
        }
        .invoice-details p {
            margin: 5px 0;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
        }
        td {
            font-size: 14px;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .totals {
            margin-top: 20px;
            text-align: right;
            padding: 10px;
            background-color: #e8f5e9;
            border-radius: 8px;
        }
        .totals p {
            margin: 5px 0;
            font-weight: bold;
            font-size: 16px;
            color: #2e7d32;
        }
        .signature-section {
            margin: 30px 0;
            padding: 15px;
            background-color: #e8f5e9;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
        }
        .signature-section div {
            width: 45%;
            text-align: center;
        }
        .signature-section p {
            margin: 10px 0;
            font-size: 14px;
            color: #2e7d32;
        }
        .signature-section .signature-line {
            border-top: 1px solid #2e7d32;
            margin-top: 40px;
            padding-top: 5px;
        }
        .print-button {
            text-align: center;
            margin-top: 20px;
        }
        .print-button button {
            background-color: #1976D2;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .print-button button:hover {
            background-color: #1565C0;
        }
        /*
        .edit-shop-details {
            margin: 30px 0;
            padding: 15px;
            background-color: #fff3e0;
            border-radius: 8px;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.1);
        }
        .edit-shop-details h3 {
            margin-top: 0;
            color: #e65100;
        }
        .edit-shop-details input, .edit-shop-details textarea {
            width: 100%;
            padding: 10px;
            margin: 8px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .edit-shop-details textarea {
            height: 80px;
            resize: vertical;
        }
        .edit-shop-details button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .edit-shop-details button:hover {
            background-color: #45a049;
        }
        .error-message {
            color: #d32f2f;
            background-color: #ffebee;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 14px;
        }
        */
        @media print {
            .print-button {
                display: none;
            }
            .invoice-container {
                box-shadow: none;
                padding: 0;
            }
        }
        @media (max-width: 600px) {
            .invoice-container {
                padding: 20px;
            }
            .header h1 {
                font-size: 24px;
            }
            th, td {
                font-size: 12px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="header">
            <!-- <img src="https://via.placeholder.com/120x60?text=Logo" alt="Company Logo" class="logo"> -->
            <h1><?php echo htmlspecialchars($shopDetails['shop_name']); ?></h1>
            <p><?php echo htmlspecialchars($shopDetails['address']); ?></p>
            <p>Phone: <?php echo htmlspecialchars($shopDetails['phone']); ?></p>
            <p>Email: <?php echo htmlspecialchars($shopDetails['email']); ?></p>
            <p>Invoice Number: <?php echo htmlspecialchars($invoice_number); ?></p>
        </div>

        <div class="invoice-details">
            <div>
                <p><strong>Customer:</strong> <?php echo htmlspecialchars($sale['customer_name']); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($sale['phone'] ?? 'N/A'); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($sale['address'] ?? 'N/A'); ?></p>
                <p><strong>Sale Date:</strong> <?php echo htmlspecialchars($sale['sale_date']); ?></p>
            </div>
            <div>
                <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($sale['payment_method_name'] ?? 'N/A'); ?></p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Unit</th>
                    <th>Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($saleItemsResult->num_rows > 0) {
                    while ($item = $saleItemsResult->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($item['product_name']) . "</td>";
                        echo "<td>" . number_format($item['quantity'], 2) . "</td>";
                        echo "<td>" . htmlspecialchars($item['unit'] ?? 'N/A') . "</td>";
                        echo "<td>" . number_format($item['price'], 2) . "</td>";
                        echo "<td>" . number_format($item['subtotal'], 2) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5'>No items found for this sale.</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <div class="totals">
            <p>Total: <?php echo number_format($sale['total'], 2); ?></p>
            <p>Paid: <?php echo number_format($sale['paid'], 2); ?></p>
            <p>Due: <?php echo number_format($sale['due'], 2); ?></p>
        </div>

        <div class="signature-section">
            <div>
                <p class="signature-line">&nbsp;</p>
                <p><strong>Customer Signature</strong></p>
            </div>
            <div>
                <p class="signature-line">&nbsp;</p>
                <p><strong>Shop Representative Signature</strong></p>
            </div>
        </div>

        <div class="print-button">
            <button onclick="window.print()">Print Invoice</button>
        </div>
    </div>
</body>
</html>

<?php
$conn->close();
?>