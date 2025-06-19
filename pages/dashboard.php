<?php
session_start();

if (!isset($_SESSION['user_email'])) {
    header("Location: login.php");
    exit();
}

// Clear low stock notification flag on login to ensure it shows each time
unset($_SESSION['low_stock_notified']);

include '../config/database.php';
include '../core/functions.php';
include '../includes/header.php';
include '../includes/sidebar.php';

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Set default date as today and current month based on system time
$today = date('Y-m-d'); // e.g., 2025-06-06
$month = date('Y-m'); // e.g., 2025-06

// Reset filters to current date and month on page refresh (no form submission)
if (!isset($_POST['filter_date']) && !isset($_POST['filter_month'])) {
    $_SESSION['filter_date'] = $today;
    $_SESSION['filter_month'] = $month;
}

// Initialize filter variables, preserving values across form submissions
$filterDate = isset($_SESSION['filter_date']) ? $_SESSION['filter_date'] : $today;
$filterMonth = isset($_SESSION['filter_month']) ? $_SESSION['filter_month'] : $month;

// Handle date filter form submission
if (isset($_POST['filter_date'])) {
    $filterDate = $_POST['filter_date'];
    $_SESSION['filter_date'] = $filterDate; // Store date in session
}

// Handle month filter form submission
if (isset($_POST['filter_month'])) {
    $filterMonth = $_POST['filter_month'];
    $_SESSION['filter_month'] = $filterMonth; // Store month in session
}

// Initialize variables for statistics
$dailyBuy = $dailySell = $dailyDue = 0;
$monthlyBuy = $monthlySell = $monthlyDue = 0;

// Fetch daily statistics (based on filter date)
$dailyBuySql = "SELECT COALESCE(SUM(total), 0) as total_buy, COALESCE(SUM(due), 0) as total_due 
                FROM purchase_headers 
                WHERE DATE(purchase_date) = '$filterDate'";
$dailyBuyResult = $conn->query($dailyBuySql);
if ($dailyBuyResult === false) {
    $error = "Error fetching daily purchases: " . $conn->connect_error;
    error_log("Daily purchases query failed in dashboard.php: " . $conn->connect_error);
} else {
    $dailyBuyRow = $dailyBuyResult->fetch_assoc();
    $dailyBuy = $dailyBuyRow['total_buy'];
    $dailyDue += $dailyBuyRow['total_due']; // Add purchase due to total daily due
}

// Daily Sales (Sell)
$dailySellSql = "SELECT COALESCE(SUM(total), 0) as total_sell, COALESCE(SUM(due), 0) as total_due 
                 FROM sales 
                 WHERE DATE(sale_date) = '$filterDate'";
$dailySellResult = $conn->query($dailySellSql);
if ($dailySellResult === false) {
    $error = "Error fetching daily sales: " . $conn->connect_error;
    error_log("Daily sales query failed in dashboard.php: " . $conn->connect_error);
} else {
    $dailySellRow = $dailySellResult->fetch_assoc();
    $dailySell = $dailySellRow['total_sell'];
    $dailyDue += $dailySellRow['total_due']; // Add sales due to total daily due
}

// Monthly Statistics (based on filter month)
$monthlyBuySql = "SELECT COALESCE(SUM(total), 0) as total_buy, COALESCE(SUM(due), 0) as total_due 
                  FROM purchase_headers 
                  WHERE YEAR(purchase_date) = YEAR('$filterMonth-01') 
                  AND MONTH(purchase_date) = MONTH('$filterMonth-01')";
$monthlyBuyResult = $conn->query($monthlyBuySql);
if ($monthlyBuyResult === false) {
    $error = "Error fetching monthly purchases: " . $conn->connect_error;
    error_log("Monthly purchases query failed in dashboard.php: " . $conn->connect_error);
} else {
    $monthlyBuyRow = $monthlyBuyResult->fetch_assoc();
    $monthlyBuy = $monthlyBuyRow['total_buy'];
    $monthlyDue += $monthlyBuyRow['total_due']; // Add purchase due to total monthly due
}

$monthlySellSql = "SELECT COALESCE(SUM(total), 0) as total_sell, COALESCE(SUM(due), 0) as total_due 
                   FROM sales 
                   WHERE YEAR(sale_date) = YEAR('$filterMonth-01') 
                   AND MONTH(sale_date) = MONTH('$filterMonth-01')";
$monthlySellResult = $conn->query($monthlySellSql);
if ($monthlySellResult === false) {
    $error = "Error fetching monthly sales: " . $conn->connect_error;
    error_log("Monthly sales query failed in dashboard.php: " . $conn->connect_error);
} else {
    $monthlySellRow = $monthlySellResult->fetch_assoc();
    $monthlySell = $monthlySellRow['total_sell'];
    $monthlyDue += $monthlySellRow['total_due']; // Add sales due to total monthly due
}

// Fetch stock list with low stock check
$stockSql = "SELECT p.*, c.name as category_name 
             FROM products p 
             LEFT JOIN categories c ON p.category_id = c.id 
             ORDER BY p.name ASC";
$stockResult = $conn->query($stockSql);
if ($stockResult === false) {
    $error = "Error fetching stock list: " . $conn->connect_error;
    error_log("Stock list query failed in dashboard.php: " . $conn->connect_error);
}

// Check for low stock and prepare data for JavaScript
$lowStockThreshold = 5; // Define threshold for low stock
$lowStockProducts = [];
if ($stockResult && $stockResult->num_rows > 0) {
    $stockResult->data_seek(0); // Reset result pointer
    while ($stockRow = $stockResult->fetch_assoc()) {
        if ($stockRow['quantity'] <= $lowStockThreshold) {
            $lowStockProducts[] = htmlspecialchars($stockRow['name']);
        }
    }
    $stockResult->data_seek(0); // Reset for table display
}
?>

<h2>Dashboard</h2>

<?php if (isset($error)) { ?>
    <p class="error"><?php echo htmlspecialchars($error); ?></p>
<?php } ?>

<!-- Daily Statistics -->
<div class="stats-container">
    <!-- Daily Filter Form -->
    <form method="POST" class="filter-form">
        <label for="filter_date">Select Date:</label>
        <input type="date" id="filter_date" name="filter_date" value="<?php echo $filterDate; ?>" onchange="this.form.submit()">
    </form>
    <h3>Daily Statistics (<?php echo htmlspecialchars($filterDate); ?>)</h3>
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
    <!-- Month Filter for Monthly Statistics -->
    <form method="POST" class="filter-form">
        <label for="filter_month">Select Month:</label>
        <input type="month" id="filter_month" name="filter_month" value="<?php echo $filterMonth; ?>" onchange="this.form.submit()">
    </form>
    <h3>Monthly Statistics (<?php echo date('F Y', strtotime($filterMonth . '-01')); ?>)</h3>
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
                    $quantityStyle = $stockRow['quantity'] <= $lowStockThreshold ? 'color: red;' : '';
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($stockRow['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($stockRow['category_name'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($stockRow['brand_name'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($stockRow['unit'] ?? 'N/A') . "</td>";
                    echo "<td>" . number_format($stockRow['price'], 2) . "</td>";
                    echo "<td style='$quantityStyle'>" . number_format($stockRow['quantity'], 2) . "</td>";
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

// Show low stock notification on page load
window.onload = function() {
    <?php if (!empty($lowStockProducts)): ?>
        console.log('Low stock products detected:', <?php echo json_encode($lowStockProducts); ?>);
        alert('Warning: The following products have low stock (quantity <= <?php echo $lowStockThreshold; ?>): \n- <?php echo implode('\n- ', $lowStockProducts); ?>\nPlease restock soon!');
        <?php $_SESSION['low_stock_notified'] = true; // Set after showing notification ?>
    <?php else: ?>
        console.log('No low stock products detected.');
    <?php endif; ?>
};
</script>

<style>
/* General Reset */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Container Styling */
.stats-container {
    margin-bottom: 1rem;
    padding: 1rem;
    background-color: #ffffff;
    border-radius: 0.5rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Stats Box */
.stats-box {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

/* Individual Stat Card */
.stat {
    background-color: #f9f9f9;
    padding: 1rem;
    border-radius: 0.375rem;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
    flex: 1;
    min-width: 150px;
    text-align: center;
    transition: transform 0.2s ease;
}

.stat:hover {
    transform: translateY(-2px);
}

.stat h4 {
    margin-bottom: 0.5rem;
    color: #4CAF50;
    font-size: 1rem;
    font-weight: 600;
}

.stat p {
    font-size: 1.25rem;
    font-weight: 700;
    color: #333;
}

/* Table Styling */
table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;
    background-color: #ffffff;
    border-radius: 0.375rem;
    overflow: hidden;
}

th, td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

th {
    background-color: #4CAF50;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
}

td {
    color: #333;
    font-size: 0.9rem;
}

tr:nth-child(even) {
    background-color: #f9f9f9;
}

tr:hover {
    background-color: #f1f1f1;
}

/* Search Input */
#stockSearch {
    width: 100%;
    max-width: 400px;
    padding: 0.5rem;
    margin-bottom: 1rem;
    border: 1px solid #ddd;
    border-radius: 0.25rem;
    font-size: 0.9rem;
}

#stockSearch:focus {
    outline: none;
    border-color: #4CAF50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
}

/* Error Message */
.error {
    color: #d32f2f;
    background-color: #ffebee;
    padding: 0.75rem;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

/* Filter Form */
.filter-form {
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    max-width: 300px; /* Consistent width for both forms */
}

.filter-form label {
    font-size: 0.9rem;
    font-weight: 500;
    color: #333;
    white-space: nowrap;
    width: 100px; /* Fixed width for labels to align inputs */
}

.filter-form input[type="date"],
.filter-form input[type="month"] {
    width: 180px; /* Fixed width to match both inputs */
    padding: 0.5rem;
    border: 1px solid #ddd;
    border-radius: 0.25rem;
    font-size: 0.9rem;
    background-color: #fff;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.filter-form input:focus {
    outline: none;
    border-color: #4CAF50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
}

/* Headings */
h2, h3 {
    color: #333;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

h2 {
    font-size: 1.5rem;
}

h3 {
    font-size: 1.1rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .stats-box {
        flex-direction: column;
    }

    .filter-form {
        max-width: 100%;
    }

    .filter-form input[type="date"],
    .filter-form input[type="month"] {
        width: 100%; /* Flexible width on smaller screens */
        max-width: 180px;
    }
}

@media (max-width: 480px) {
    .filter-form {
        flex-direction: column;
        align-items: flex-start;
    }

    .filter-form label {
        width: auto;
        margin-bottom: 0.25rem;
    }

    .filter-form input[type="date"],
    .filter-form input[type="month"] {
        width: 100%;
        max-width: none;
    }
}
</style>

<?php include '../includes/footer.php'; ?>