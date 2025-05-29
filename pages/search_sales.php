<?php
include '../config/database.php';
include '../core/functions.php';

// Check database connection
if ($conn->connect_error) {
    echo "<tr><td colspan='9'>Database connection failed: " . htmlspecialchars($conn->connect_error) . "</td></tr>";
    exit();
}

// Get the search term from the query string
$search_term = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Sanitize search term for full-text search (remove special characters that interfere with MATCH ... AGAINST)
$fulltext_search_term = preg_replace('/[+\-<>\(\)~*"@]+/', ' ', $search_term);

// Build the search query
$sql = "SELECT sales.*, customers.name as customer_name, customers.phone as customer_phone, customers.address as customer_address 
        FROM sales 
        LEFT JOIN customers ON sales.customer_id = customers.id 
        WHERE 1=1";

// Check if FULLTEXT index exists on customers.address
$fulltext_index_exists = false;
$index_check = $conn->query("SHOW INDEX FROM customers WHERE Key_name LIKE 'fulltext%' AND Column_name = 'address'");
if ($index_check && $index_check->num_rows > 0) {
    $fulltext_index_exists = true;
}

if (!empty($search_term)) {
    $search_term_like = "%$search_term%";
    if ($fulltext_index_exists) {
        // Use MATCH ... AGAINST for address if FULLTEXT index exists, otherwise fall back to LIKE
        $sql .= " AND (customers.name LIKE ? OR COALESCE(customers.phone, '') LIKE ? OR MATCH(customers.address) AGAINST(? IN BOOLEAN MODE) OR sales.invoice_number LIKE ?)";
    } else {
        $sql .= " AND (customers.name LIKE ? OR COALESCE(customers.phone, '') LIKE ? OR COALESCE(customers.address, '') LIKE ? OR sales.invoice_number LIKE ?)";
    }
}

// Order by sale date
$sql .= " ORDER BY sales.sale_date DESC";

// Prepare and execute the query
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<tr><td colspan='9'>Error preparing query: " . htmlspecialchars($conn->error) . "</td></tr>";
    exit();
}

if (!empty($search_term)) {
    if ($fulltext_index_exists) {
        $stmt->bind_param("ssss", $search_term_like, $search_term_like, $fulltext_search_term, $search_term_like);
    } else {
        $stmt->bind_param("ssss", $search_term_like, $search_term_like, $search_term_like, $search_term_like);
    }
}

if (!$stmt->execute()) {
    echo "<tr><td colspan='9'>Error executing query: " . htmlspecialchars($stmt->error) . "</td></tr>";
    $stmt->close();
    $conn->close();
    exit();
}

$result = $stmt->get_result();

// Output the table rows
if ($result->num_rows > 0) {
    while ($saleRow = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($saleRow['invoice_number'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($saleRow['customer_name'] ?? 'N/A') . "</td>";
        echo "<td>";
        // Fetch sale items only for this specific sale (more efficient)
        $stmt_sale_items = $conn->prepare("SELECT sale_items.*, products.name as product_name 
                                          FROM sale_items 
                                          LEFT JOIN products ON sale_items.product_id = products.id 
                                          WHERE sale_items.sale_id = ?");
        if (!$stmt_sale_items) {
            echo "Error fetching sale items: " . htmlspecialchars($conn->error);
            continue;
        }
        $stmt_sale_items->bind_param("i", $saleRow['id']);
        if (!$stmt_sale_items->execute()) {
            echo "Error executing sale items query: " . htmlspecialchars($stmt_sale_items->error);
            $stmt_sale_items->close();
            continue;
        }
        $saleItemsResult = $stmt_sale_items->get_result();
        while ($saleItemRow = $saleItemsResult->fetch_assoc()) {
            echo htmlspecialchars($saleItemRow['product_name'] ?? 'Unknown Product') . " (" . htmlspecialchars($saleItemRow['quantity'] ?? 0) . ")<br>";
        }
        $stmt_sale_items->close();
        echo "</td>";
        echo "<td>" . number_format($saleRow['total'] ?? 0, 2) . "</td>";
        echo "<td>" . number_format($saleRow['paid'] ?? 0, 2) . "</td>";
        echo "<td>" . number_format($saleRow['due'] ?? 0, 2) . "</td>";
        echo "<td>" . htmlspecialchars($saleRow['sale_date'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($saleRow['payment_method'] ?? 'N/A') . "</td>";
        echo "<td>";
        echo "<button onclick=\"generateInvoice('" . htmlspecialchars($saleRow['invoice_number'] ?? '') . "')\">Invoice</button>";
        echo "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='9'>No sales found</td></tr>";
}

// Close statement and connection
$stmt->close();
$conn->close();
?>