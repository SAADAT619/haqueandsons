<?php
session_start();
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}

include '../config/database.php';
include '../core/functions.php';
include '../includes/header.php';

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Check if purchase_id is provided
if (!isset($_GET['purchase_id']) || empty($_GET['purchase_id'])) {
    die("Purchase ID not provided.");
}

$purchase_id = filter_input(INPUT_GET, 'purchase_id', FILTER_VALIDATE_INT);
if ($purchase_id === false || $purchase_id <= 0) {
    die("Invalid purchase ID.");
}

// Handle shop details update
$updateSuccess = null;
$updateError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_shop_details'])) {
    $shop_name = filter_input(INPUT_POST, 'shop_name', FILTER_SANITIZE_STRING);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);

    if (empty($shop_name) || empty($phone) || empty($address)) {
        $updateError = "All fields are required.";
    } else {
        $updateSql = "UPDATE shop_details SET shop_name = ?, phone = ?, address = ? WHERE id = 1";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("sss", $shop_name, $phone, $address);
        if ($updateStmt->execute()) {
            $updateSuccess = "Shop details updated successfully.";
        } else {
            $updateError = "Error updating shop details: " . $conn->error;
        }
    }
}

// Fetch purchase details from purchase_headers
$purchaseSql = "SELECT ph.*, s.name as seller_name, s.phone as seller_phone, s.address as seller_address, pm.method as payment_method
                FROM purchase_headers ph
                LEFT JOIN sellers s ON ph.seller_id = s.id
                JOIN payment_methods pm ON ph.payment_method_id = pm.id
                WHERE ph.id = ?";
$stmt = $conn->prepare($purchaseSql);
if (!$stmt) {
    die("Error preparing purchase query: " . $conn->error);
}
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchaseResult = $stmt->get_result();

if ($purchaseResult->num_rows == 0) {
    die("Invoice not found.");
}

$purchase = $purchaseResult->fetch_assoc();
$invoice_number = $purchase['invoice_number'];

// Fetch purchased products from purchase_items
$itemsSql = "SELECT pi.*, p.name as product_name, p.brand_name
             FROM purchase_items pi
             LEFT JOIN products p ON pi.product_id = p.id
             WHERE pi.purchase_id = ?";
$stmt = $conn->prepare($itemsSql);
if (!$stmt) {
    die("Error preparing items query: " . $conn->error);
}
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$itemsResult = $stmt->get_result();

// Fetch shop details from shop_details table
$shopSql = "SELECT shop_name, address, phone, email FROM shop_details WHERE id = 1";
$shopResult = $conn->query($shopSql);
if ($shopResult === false) {
    die("Error fetching shop details: " . $conn->error);
}

$shop = $shopResult->num_rows > 0 ? $shopResult->fetch_assoc() : [
    'shop_name' => 'Gemini Cement Store',
    'address' => '123 Business Avenue, City, Country',
    'phone' => '+123-456-7890',
    'email' => 'contact@geminicement.com'
];
?>

<div class="container">
    <div class="edit-shop-details">
        <h3>Edit Shop Details</h3>
        <?php if (isset($updateSuccess)) { ?>
            <p class="success"><?php echo htmlspecialchars($updateSuccess); ?></p>
        <?php } ?>

        <?php if (isset($updateError)) { ?>
            <p class="error"><?php echo htmlspecialchars($updateError); ?></p>
        <?php } ?>

        <form method="POST" action="">
            <input type="hidden" name="update_shop_details" value="1">
            <div class="form-group">
                <label for="shop_name">Shop Name</label>
                <input type="text" name="shop_name" id="shop_name" value="<?php echo htmlspecialchars($shop['shop_name']); ?>" required>
            </div>
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" name="phone" id="phone" value="<?php echo htmlspecialchars($shop['phone']); ?>" required>
            </div>
            <div class="form-group">
                <label for="address">Address</label>
                <textarea name="address" id="address" required><?php echo htmlspecialchars($shop['address']); ?></textarea>
            </div>
            <button type="submit">Update Shop Details</button>
            <a href="buy.php" class="btn btn-secondary">Back to Purchase List</a>
        </form>
    </div>

    <div class="invoice">
        <h2>Invoice</h2>
        <div class="invoice-header">
            <h1><?php echo htmlspecialchars($shop['shop_name']); ?></h1>
            <p>Address: <?php echo htmlspecialchars($shop['address']); ?></p>
            <p>Phone: <?php echo htmlspecialchars($shop['phone']); ?></p>
            <p>Email: <?php echo htmlspecialchars($shop['email']); ?></p>
        </div>

        <div class="invoice-details">
            <div class="invoice-info">
                <h3>Invoice Number: <?php echo htmlspecialchars($invoice_number); ?></h3>
                <p>Date: <?php echo htmlspecialchars(date('d M Y', strtotime($purchase['purchase_date']))); ?></p>
            </div>
            <div class="seller-info">
                <h3>Seller Information</h3>
                <p>Name: <?php echo htmlspecialchars($purchase['seller_name'] ?? 'N/A'); ?></p>
                <p>Phone: <?php echo htmlspecialchars($purchase['seller_phone'] ?? 'N/A'); ?></p>
                <p>Address: <?php echo htmlspecialchars($purchase['seller_address'] ?? 'N/A'); ?></p>
            </div>
        </div>

        <h3>Purchased Products</h3>
        <table>
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>Quantity</th>
                    <th>Unit</th>
                    <th>Type</th>
                    <th>Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($itemsResult->num_rows > 0) {
                    while ($item = $itemsResult->fetch_assoc()) {
                        $subtotal = $item['quantity'] * $item['price'];
                        $productDisplay = htmlspecialchars($item['product_name'] . " (" . $item['brand_name'] . ")");
                        echo "<tr>";
                        echo "<td>$productDisplay</td>";
                        echo "<td>" . htmlspecialchars($item['quantity']) . "</td>";
                        echo "<td>" . htmlspecialchars($item['unit']) . "</td>";
                        echo "<td>" . htmlspecialchars($item['type'] ?: 'N/A') . "</td>";
                        echo "<td>" . number_format($item['price'], 2) . "</td>";
                        echo "<td>" . number_format($subtotal, 2) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6'>No products found for this invoice.</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <div class="invoice-summary">
            <p><strong>Total:</strong> <?php echo number_format($purchase['total'], 2); ?></p>
            <p><strong>Paid:</strong> <?php echo number_format($purchase['paid'], 2); ?></p>
            <p><strong>Due:</strong> <?php echo number_format($purchase['due'], 2); ?></p>
            <p><strong>Payment Method:</strong> <?php echo htmlspecialchars(ucfirst($purchase['payment_method'])); ?></p>
        </div>

        <div class="invoice-footer">
            <p>Thank you for your business!</p>
        </div>

        <div class="invoice-actions">
            <button onclick="window.print()">Print Invoice</button>
            <a href="download_invoice.php?purchase_id=<?php echo $purchase_id; ?>" class="btn btn-download">Download</a>
        </div>
    </div>
</div>

<style>
.container {
    display: flex;
    gap: 20px;
    max-width: 1200px;
    margin: 20px auto;
}

.edit-shop-details {
    flex: 1;
    background-color: #f9f9f9;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.invoice {
    flex: 2;
    background-color: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
}

.form-group input, .form-group textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.form-group textarea {
    height: 100px;
    resize: vertical;
}

button[type="submit"] {
    background-color: #4CAF50;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

button[type="submit"]:hover {
    background-color: #45a049;
}

.error {
    color: #d32f2f;
    background-color: #ffebee;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.success {
    color: #2e7d32;
    background-color: #e8f5e9;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.invoice-header {
    text-align: center;
    margin-bottom: 30px;
}

.invoice-header h1 {
    margin: 0;
    font-size: 24px;
}

.invoice-details {
    display: flex;
    justify-content: space-between;
    margin-bottom: 30px;
}

.invoice-info, .seller-info {
    width: 48%;
}

.invoice-info h3, .seller-info h3 {
    margin-top: 0;
    font-size: 18px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 30px;
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

th:first-child, td:first-child {
    width: 30%;
}

tr:nth-child(even) {
    background-color: #f9f9f9;
}

tr:hover {
    background-color: #f1f1f1;
}

.invoice-summary {
    text-align: right;
    margin-top: 20px;
    font-size: 16px;
}

.invoice-footer {
    text-align: center;
    margin-top: 30px;
    font-style: italic;
    color: #555;
}

.invoice-actions {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-top: 20px;
}

button {
    background-color: #4CAF50;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
}

button:hover {
    background-color: #45a049;
}

.btn {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 4px;
    font-size: 16px;
    text-align: center;
    text-decoration: none;
    transition: background-color 0.3s ease;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #5a6268;
}

.btn-download {
    background-color: #007bff;
    color: white;
}

.btn-download:hover {
    background-color: #0056b3;
}

@media print {
    .container {
        display: block;
    }
    .edit-shop-details {
        display: none;
    }
    .invoice {
        box-shadow: none;
        border: none;
        margin: 0;
        padding: 10px;
        width: 100%;
    }
    button, .btn-download, .sidebar, .header, .footer {
        display: none;
    }
    body {
        margin: 0;
    }
}
</style>

<?php include '../includes/footer.php'; ?>