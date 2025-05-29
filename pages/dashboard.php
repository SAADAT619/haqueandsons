<?php
session_start();
if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}

include '../config/database.php';
include '../core/functions.php';
include '../includes/header.php';
include '../includes/sidebar.php';

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Initialize variables for statistics
$dailyBuy = $dailySell = $dailyDue = 0;
$monthlyBuy = $monthlySell = $monthlyDue = 0;

// Fetch daily statistics (today's data)
$today = date('Y-m-d');

// Daily Purchases (Buy)
$dailyBuySql = "SELECT COALESCE(SUM(total), 0) as total_buy, COALESCE(SUM(due), 0) as total_due 
                FROM purchase_headers 
                WHERE DATE(purchase_date) = CURDATE()";
$dailyBuyResult = $conn->query($dailyBuySql);
if ($dailyBuyResult === false) {
    $error = "Error fetching daily purchases: " . $conn->error;
    error_log("Daily purchases query failed in dashboard.php: " . $conn->error);
} else {
    $dailyBuyRow = $dailyBuyResult->fetch_assoc();
    $dailyBuy = $dailyBuyRow['total_buy'];
    $dailyDue += $dailyBuyRow['total_due']; // Add purchase due to total daily due
}

// Daily Sales (Sell)
$dailySellSql = "SELECT COALESCE(SUM(total), 0) as total_sell, COALESCE(SUM(due), 0) as total_due 
                 FROM sales 
                 WHERE DATE(sale_date) = CURDATE()";
$dailySellResult = $conn->query($dailySellSql);
if ($dailySellResult === false) {
    $error = "Error fetching daily sales: " . $conn->error;
    error_log("Daily sales query failed in dashboard.php: " . $conn->error);
} else {
    $dailySellRow = $dailySellResult->fetch_assoc();
    $dailySell = $dailySellRow['total_sell'];
    $dailyDue += $dailySellRow['total_due']; // Add sales due to total daily due
}

// Monthly Statistics (current month)
$monthlyBuySql = "SELECT COALESCE(SUM(total), 0) as total_buy, COALESCE(SUM(due), 0) as total_due 
                  FROM purchase_headers 
                  WHERE YEAR(purchase_date) = YEAR(CURDATE()) 
                  AND MONTH(purchase_date) = MONTH(CURDATE())";
$monthlyBuyResult = $conn->query($monthlyBuySql);
if ($monthlyBuyResult === false) {
    $error = "Error fetching monthly purchases: " . $conn->error;
    error_log("Monthly purchases query failed in dashboard.php: " . $conn->error);
} else {
    $monthlyBuyRow = $monthlyBuyResult->fetch_assoc();
    $monthlyBuy = $monthlyBuyRow['total_buy'];
    $monthlyDue += $monthlyBuyRow['total_due']; // Add purchase due to total monthly due
}

$monthlySellSql = "SELECT COALESCE(SUM(total), 0) as total_sell, COALESCE(SUM(due), 0) as total_due 
                   FROM sales 
                   WHERE YEAR(sale_date) = YEAR(CURDATE()) 
                   AND MONTH(sale_date) = MONTH(CURDATE())";
$monthlySellResult = $conn->query($monthlySellSql);
if ($monthlySellResult === false) {
    $error = "Error fetching monthly sales: " . $conn->error;
    error_log("Monthly sales query failed in dashboard.php: " . $conn->error);
} else {
    $monthlySellRow = $monthlySellResult->fetch_assoc();
    $monthlySell = $monthlySellRow['total_sell'];
    $monthlyDue += $monthlySellRow['total_due']; // Add sales due to total monthly due
}

// Fetch stock list
$stockSql = "SELECT p.*, c.name as category_name 
             FROM products p 
             LEFT JOIN categories c ON p.category_id = c.id 
             ORDER BY p.name ASC";
$stockResult = $conn->query($stockSql);
if ($stockResult === false) {
    $error = "Error fetching stock list: " . $conn->error;
    error_log("Stock list query failed in dashboard.php: " . $conn->error);
}
?>

<h2>Dashboard</h2>

<?php if (isset($error)) { ?>
    <p class="error"><?php echo htmlspecialchars($error); ?></p>
<?php } ?>

<!-- Daily Statistics -->
<div class="stats-container">
    <h3>Daily Statistics (<?php echo htmlspecialchars($today); ?>)</h3>
    <div class="stats-box">
        <div class="stat">
            <h4>Total Buy</h4>
            <p><?php echo number_format($dailyBuy, 2); ?></p>
        </div>
        <div class="stat">
            <h4>Total Sell</h4>
            <p><?php echo number_format($dailySell, 2); ?></p>
        </div>
        <div class="stat">
            <h4>Total Due</h4>
            <p><?php echo number_format($dailyDue, 2); ?></p>
        </div>
    </div>
</div>

<!-- Monthly Statistics -->
<div class="stats-container">
    <h3>Monthly Statistics (<?php echo date('F Y'); ?>)</h3>
    <div class="stats-box">
        <div class="stat">
            <h4>Total Buy</h4>
            <p><?php echo number_format($monthlyBuy, 2); ?></p>
        </div>
        <div class="stat">
            <h4>Total Sell</h4>
            <p><?php echo number_format($monthlySell, 2); ?></p>
        </div>
        <div class="stat">
            <h4>Total Due</h4>
            <p><?php echo number_format($monthlyDue, 2); ?></p>
        </div>
    </div>
</div>

<!-- Stock List -->
<h3>Current Stock</h3>
<input type="text" id="stockSearch" placeholder="Search stock by name, category, or brand" onkeyup="filterStock()">
<table id="stockTable">
    <thead>
        <tr>
            <th>Product Name</th>
            <th>Category</th>
            <th>Brand</th>
            <th>Unit</th>
            <th>Price</th>
            <th>Quantity</th>
            <th>Last Updated</th>
        </tr>
    </thead>
    <tbody>
        <?php
        if (isset($stockResult) && $stockResult !== false) {
            if ($stockResult->num_rows > 0) {
                while ($stockRow = $stockResult->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($stockRow['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($stockRow['category_name'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($stockRow['brand_name'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($stockRow['unit'] ?? 'N/A') . "</td>";
                    echo "<td>" . number_format($stockRow['price'], 2) . "</td>";
                    echo "<td>" . number_format($stockRow['quantity'], 2) . "</td>";
                    echo "<td>" . htmlspecialchars($stockRow['updated_at']) . "</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='7'>No stock found</td></tr>";
            }
        } else {
            echo "<tr><td colspan='7'>Error loading stock list</td></tr>";
        }
        ?>
    </tbody>
</table>

<script>
function filterStock() {
    const input = document.getElementById('stockSearch').value.toLowerCase();
    const table = document.getElementById('stockTable');
    const rows = table.getElementsByTagName('tr');

    for (let i = 1; i < rows.length; i++) { // Start from 1 to skip header
        const cells = rows[i].getElementsByTagName('td');
        let match = false;
        for (let j = 0; j < cells.length - 1; j++) { // Exclude last column (Last Updated)
            if (cells[j].textContent.toLowerCase().indexOf(input) > -1) {
                match = true;
                break;
            }
        }
        rows[i].style.display = match ? '' : 'none';
    }
}
</script>

<style>
.stats-container {
    margin-bottom: 30px;
}

.stats-box {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.stat {
    background-color: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    flex: 1;
    min-width: 200px;
    text-align: center;
}

.stat h4 {
    margin: 0 0 10px;
    color: #4CAF50;
}

.stat p {
    margin: 0;
    font-size: 1.2em;
    color: #333;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

th {
    background-color: #4CAF50;
    color: white;
}

tr:nth-child(even) {
    background-color: #f9f9f9;
}

tr:hover {
    background-color: #f1f1f1;
}

#stockSearch {
    width: 100%;
    padding: 10px;
    margin-bottom: 20px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.error {
    color: #d32f2f;
    background-color: #ffebee;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
}
</style>

<?php include '../includes/footer.php'; ?>