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

// Function to check if stock is sufficient
function checkStockAvailability($conn, $product_id, $quantity) {
    if (!is_numeric($product_id) || $product_id <= 0 || !is_numeric($quantity) || $quantity <= 0) {
        return false;
    }
    $product_id = (int)$product_id;
    $sql = "SELECT quantity FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("checkStockAvailability Prepare Error: " . $conn->error);
        return false;
    }
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        return $product['quantity'] >= $quantity;
    }
    return false;
}

// Function to get the total previous due for a customer
function getCustomerPreviousDue($conn, $customer_id) {
    if (!is_numeric($customer_id) || $customer_id <= 0) {
        return 0;
    }
    $customer_id = (int)$customer_id;
    $sql = "SELECT SUM(due) as total_due FROM sales WHERE customer_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("getCustomerPreviousDue Prepare Error: " . $conn->error);
        return 0;
    }
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['total_due'] ?? 0;
}

// Function to generate a unique invoice number for sales
function generateSaleInvoiceNumber($conn) {
    $prefix = "SALE-" . date("Ymd");
    $likePattern = $conn->real_escape_string($prefix . '%');
    $sql = "SELECT invoice_number FROM sales WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("generateSaleInvoiceNumber Prepare Error: " . $conn->error);
        return false;
    }
    $stmt->bind_param("s", $likePattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $number = 1;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastNumber = (int)substr($row['invoice_number'], -3);
        $number = $lastNumber + 1;
    }
    return $prefix . str_pad($number, 3, "0", STR_PAD_LEFT);
}

// Handle sale form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_sale'])) {
    $customer_id = (int)sanitizeInput($_POST['customer_id']);
    $sale_date = sanitizeInput($_POST['sale_date']);
    $payment_method_id = (int)sanitizeInput($_POST['payment_method']);
    $invoice_number = generateSaleInvoiceNumber($conn);
    $paid = floatval(sanitizeInput($_POST['paid']));
    $include_previous_due = isset($_POST['include_previous_due']) ? 1 : 0;

    // Validate inputs
    if (empty($customer_id) || $customer_id <= 0) {
        $error = "Invalid customer selected.";
    } elseif (empty($sale_date) || !strtotime($sale_date)) {
        $error = "Invalid sale date.";
    } elseif (empty($payment_method_id) || $payment_method_id <= 0) {
        $error = "Invalid payment method selected.";
    } elseif (!isset($_POST['product_id']) || !is_array($_POST['product_id']) || empty($_POST['product_id'])) {
        $error = "At least one product must be selected.";
    } elseif (!isset($_POST['quantity']) || !is_array($_POST['quantity']) || empty($_POST['quantity'])) {
        $error = "Product quantities are required.";
    } elseif (!isset($_POST['price']) || !is_array($_POST['price']) || empty($_POST['price'])) {
        $error = "Product prices are required.";
    } elseif ($paid < 0) {
        $error = "Paid amount cannot be negative.";
    } elseif (!$invoice_number) {
        $error = "Failed to generate invoice number.";
    } else {
        // Validate stock availability
        $stock_error = false;
        $product_count = count($_POST['product_id']);
        for ($i = 0; $i < $product_count; $i++) {
            $product_id = (int)sanitizeInput($_POST['product_id'][$i]);
            $quantity = floatval(sanitizeInput($_POST['quantity'][$i]));
            if ($product_id <= 0) {
                $stock_error = true;
                $error = "Invalid product selected at position " . ($i + 1) . ".";
                break;
            }
            if ($quantity <= 0) {
                $stock_error = true;
                $error = "Quantity must be greater than 0 for product at position " . ($i + 1) . ".";
                break;
            }
            if (!checkStockAvailability($conn, $product_id, $quantity)) {
                $stock_error = true;
                $error = "Insufficient stock for product ID $product_id. Requested: $quantity, Available: " . (getProductStockById($conn, $product_id)['quantity'] ?? 0);
                break;
            }
        }

        if (!$stock_error) {
            try {
                $conn->begin_transaction();

                $previous_due = getCustomerPreviousDue($conn, $customer_id);
                $previous_due_to_add = $include_previous_due ? $previous_due : 0;

                // Insert into 'sales' table
                $stmt = $conn->prepare("INSERT INTO sales (customer_id, sale_date, invoice_number, payment_method_id, paid, total, due) 
                                        VALUES (?, ?, ?, ?, ?, 0, 0)");
                $stmt->bind_param("issid", $customer_id, $sale_date, $invoice_number, $payment_method_id, $paid);
                if (!$stmt->execute()) {
                    throw new Exception("Error adding sale: " . $conn->error);
                }
                $sale_id = $conn->insert_id;

                // Debug: Log the sale_id and sale_date
                error_log("New Sale Added - ID: $sale_id, Date: $sale_date");

                $total = 0;

                // Insert into 'sale_items' table
                for ($i = 0; $i < $product_count; $i++) {
                    $product_id = (int)sanitizeInput($_POST['product_id'][$i]);
                    $quantity = floatval(sanitizeInput($_POST['quantity'][$i]));
                    $price = floatval(sanitizeInput($_POST['price'][$i]));
                    $subtotal = $quantity * $price;

                    if ($price <= 0) {
                        throw new Exception("Price must be greater than 0 for product at position " . ($i + 1) . ".");
                    }

                    $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal) 
                                            VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("iidd", $sale_id, $product_id, $quantity, $price, $subtotal);
                    if (!$stmt->execute()) {
                        throw new Exception("Error adding sale item: " . $conn->error);
                    }

                    $total += $subtotal;
                }

                // Update the 'sales' table with the total and due
                $total_with_previous_due = $total + $previous_due_to_add;
                $due = $total_with_previous_due - $paid;
                $stmt = $conn->prepare("UPDATE sales SET total = ?, due = ? WHERE id = ?");
                $stmt->bind_param("ddi", $total_with_previous_due, $due, $sale_id);
                if (!$stmt->execute()) {
                    throw new Exception("Error updating sale total: " . $conn->error);
                }

                // Reset previous due if included
                if ($include_previous_due && $previous_due > 0) {
                    $stmt = $conn->prepare("UPDATE sales SET due = 0 WHERE customer_id = ? AND id != ?");
                    $stmt->bind_param("ii", $customer_id, $sale_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Error resetting previous due: " . $conn->error);
                    }
                }

                $conn->commit();
                $message = "Sale added successfully. Invoice Number: $invoice_number";
                header("Location: sell.php?message=" . urlencode($message));
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
                error_log("Add Sale Error: " . $error);
            }
        }
    }
}

// Pagination settings
$records_per_page = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Count total sales for pagination
$count_sql = "SELECT COUNT(*) as total FROM sales";
$count_result = $conn->query($count_sql);
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch customers for dropdown
$customerSql = "SELECT * FROM customers";
$customerResult = $conn->query($customerSql);
if ($customerResult === false) {
    $error = "Error fetching customers: " . $conn->error;
}

// Fetch products for dropdown
$products = getProductStock($conn);
if (empty($products)) {
    error_log("No products found in the database.");
}

// Fetch payment methods
$paymentSql = "SELECT id, method FROM payment_methods ORDER BY method ASC";
$paymentResult = $conn->query($paymentSql);
if ($paymentResult === false) {
    $error = "Error fetching payment methods: " . $conn->error;
}

// Fetch sales for listing with pagination
$sql = "SELECT sales.*, customers.name as customer_name, customers.phone as customer_phone, customers.address as customer_address 
        FROM sales 
        LEFT JOIN customers ON sales.customer_id = customers.id 
        ORDER BY DATE(sale_date) DESC, id DESC 
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $records_per_page, $offset);
$stmt->execute();
$salesResult = $stmt->get_result();
if ($salesResult === false) {
    $error = "Error fetching sales: " . $conn->error;
}

// Fetch sale items for listing
$sql = "SELECT sale_items.*, products.name as product_name FROM sale_items LEFT JOIN products ON sale_items.product_id = products.id";
$saleItemsResult = $conn->query($sql);
if ($saleItemsResult === false) {
    $error = "Error fetching sale items: " . $conn->error;
}
?>

<h2>Sell Products</h2>

<?php if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    echo "<p class='success'>$message</p>";
} ?>
<?php if (isset($error)) {
    echo "<p class='error'>$error</p>";
} ?>

<form method="post">
    <div class="form-group">
        <label for="customer_id">Select Customer</label>
        <select name="customer_id" id="customer_id" required onchange="fetchPreviousDue(this.value)">
            <option value="">Select Customer</option>
            <?php
            if ($customerResult && $customerResult->num_rows > 0) {
                while ($customerRow = $customerResult->fetch_assoc()) {
                    echo "<option value='" . $customerRow['id'] . "'>" . htmlspecialchars($customerRow['name']) . "</option>";
                }
            } else {
                echo "<option value='' disabled>No customers available</option>";
            }
            ?>
        </select>
    </div>

    <div id="previous_due_container" style="display:none;">
        <strong>Previous Due:</strong> <span id="previous_due">0.00</span><br>
        <label><input type="checkbox" name="include_previous_due" id="include_previous_due" onchange="calculateTotal()"> Include Previous Due in This Sale</label><br>
    </div>

    <div id="product_list">
        <div class="product_item">
            <select name="product_id[]" required>
                <option value="">Select Product</option>
                <?php
                if (!empty($products)) {
                    foreach ($products as $product) {
                        $stock_status = $product['quantity'] > 0 ? "" : "disabled";
                        $stock_display = "Stock: " . $product['quantity'] . " " . ($product['unit'] ?? '');
                        $display_text = htmlspecialchars("{$product['name']} ({$product['category_name']}) - {$product['brand_name']} - {$stock_display}");
                        echo "<option value='{$product['id']}' $stock_status>$display_text</option>";
                    }
                } else {
                    echo "<option value='' disabled>No products available</option>";
                }
                ?>
            </select>
            <input type="number" name="quantity[]" placeholder="Quantity" min="0" step="0.01" required oninput="validateInput(this); calculateTotal()">
            <input type="number" name="price[]" placeholder="Price" min="0" step="0.01" required oninput="validateInput(this); calculateTotal()">
            <span>Subtotal: <span class="subtotal">0.00</span></span>
            <button type="button" class="remove_product" onclick="removeProduct(this)">✖</button><br>
        </div>
    </div>
    <button type="button" id="add_product">Add Product</button><br>

    <div class="form-group">
        <label for="paid">Paid Amount</label>
        <input type="number" name="paid" id="paid" placeholder="Paid Amount" min="0" step="0.01" required oninput="validateInput(this); calculateDue()">
    </div>

    <div class="form-group">
        <strong>Total:</strong> <span id="total">0.00</span><br>
        <strong>Due:</strong> <span id="due">0.00</span>
    </div>

    <div class="form-group">
        <label for="sale_date">Sale Date</label>
        <input type="date" name="sale_date" required value="<?php echo date('Y-m-d'); ?>">
    </div>

    <div class="form-group">
        <label for="payment_method">Payment Method</label>
        <select name="payment_method" id="payment_method" required>
            <option value="">Select Payment Method</option>
            <?php
            if ($paymentResult && $paymentResult->num_rows > 0) {
                while ($payment = $paymentResult->fetch_assoc()) {
                    echo "<option value='{$payment['id']}'>" . htmlspecialchars($payment['method']) . "</option>";
                }
            } else {
                echo "<option value='' disabled>No payment methods available</option>";
            }
            ?>
        </select>
    </div>

    <button type="submit" name="add_sale">Add Sale</button>
</form>

<hr>

<h3>Sale List</h3>

<div class="search-container">
    <input type="text" id="search_input" placeholder="Search by Name, Phone, Address, or Invoice Number" oninput="searchSales()" value="">
</div>

<table id="sales_table">
    <thead>
        <tr>
            <th>Invoice #</th>
            <th>Customer</th>
            <th>Products</th>
            <th>Total</th>
            <th>Paid</th>
            <th>Due</th>
            <th>Sale Date</th>
            <th>Payment Method</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody id="sales_table_body">
        <?php
        if ($salesResult && $salesResult->num_rows > 0) {
            while ($saleRow = $salesResult->fetch_assoc()) {
                $payment_method_id = (int)$saleRow['payment_method_id'];
                $paymentMethodSql = "SELECT method FROM payment_methods WHERE id = ?";
                $stmt = $conn->prepare($paymentMethodSql);
                $stmt->bind_param("i", $payment_method_id);
                $stmt->execute();
                $paymentResultDisplay = $stmt->get_result();
                $paymentMethod = ($paymentResultDisplay && $paymentResultDisplay->num_rows > 0) ? $paymentResultDisplay->fetch_assoc()['method'] : 'N/A';

                echo "<tr>";
                echo "<td>" . htmlspecialchars($saleRow['invoice_number']) . "</td>";
                echo "<td>" . htmlspecialchars($saleRow['customer_name'] ?? 'N/A') . "</td>";
                echo "<td>";
                $sale_id = $saleRow['id'];
                $saleItemsResult->data_seek(0);
                while ($saleItemRow = $saleItemsResult->fetch_assoc()) {
                    if ($saleItemRow['sale_id'] == $sale_id) {
                        echo htmlspecialchars($saleItemRow['product_name'] ?? 'Unknown Product') . " (" . $saleItemRow['quantity'] . ")<br>";
                    }
                }
                echo "</td>";
                echo "<td>" . number_format($saleRow['total'], 2) . "</td>";
                echo "<td>" . number_format($saleRow['paid'], 2) . "</td>";
                echo "<td>" . number_format($saleRow['due'], 2) . "</td>";
                echo "<td>" . htmlspecialchars($saleRow['sale_date']) . "</td>";
                echo "<td>" . htmlspecialchars($paymentMethod) . "</td>";
                echo "<td>";
                echo "<button onclick=\"generateInvoice('" . htmlspecialchars($saleRow['invoice_number']) . "')\">Invoice</button>";
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='9'>No sales found</td></tr>";
        }
        ?>
    </tbody>
</table>

<!-- Pagination Links -->
<div class="pagination">
    <?php
    // Previous page link
    if ($page > 1) {
        echo "<a href='sell.php?page=" . ($page - 1) . "'>&laquo; Previous</a>";
    } else {
        echo "<span class='disabled'>&laquo; Previous</span>";
    }

    // Page number links
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i == $page) {
            echo "<span class='current'>$i</span>";
        } else {
            echo "<a href='sell.php?page=$i'>$i</a>";
        }
    }

    // Next page link
    if ($page < $total_pages) {
        echo "<a href='sell.php?page=" . ($page + 1) . "'>Next &raquo;</a>";
    } else {
        echo "<span class='disabled'>Next &raquo;</span>";
    }
    ?>
</div>

<script>
function validateInput(input) {
    if (input.value < 0) {
        input.value = 0;
    }
}

function fetchPreviousDue(customerId) {
    if (customerId === "") {
        document.getElementById('previous_due_container').style.display = 'none';
        document.getElementById('previous_due').textContent = "0.00";
        document.getElementById('include_previous_due').checked = false;
        calculateTotal();
        return;
    }

    var xhr = new XMLHttpRequest();
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.error) {
                    alert("Error: " + response.error);
                    return;
                }
                var previousDue = parseFloat(response.previous_due) || 0;
                document.getElementById('previous_due').textContent = previousDue.toFixed(2);
                document.getElementById('previous_due_container').style.display = previousDue > 0 ? 'block' : 'none';
                document.getElementById('include_previous_due').checked = false;
                calculateTotal();
            } catch (e) {
                alert("Error parsing previous due: " + e.message);
            }
        }
    };
    xhr.open("GET", "get_customer_due.php?customer_id=" + customerId, true);
    xhr.send();
}

function calculateTotal() {
    var productItems = document.getElementById('product_list').getElementsByClassName('product_item');
    var total = 0;

    for (var i = 0; i < productItems.length; i++) {
        var quantityInput = productItems[i].querySelector('input[name="quantity[]"]');
        var priceInput = productItems[i].querySelector('input[name="price[]"]');
        var subtotalDisplay = productItems[i].querySelector('.subtotal');

        var quantity = quantityInput.value && quantityInput.value >= 0 ? parseFloat(quantityInput.value) : 0;
        var price = priceInput.value && priceInput.value >= 0 ? parseFloat(priceInput.value) : 0;

        var subtotal = quantity * price;
        subtotalDisplay.textContent = subtotal.toFixed(2);
        total += subtotal;
    }

    var includePreviousDue = document.getElementById('include_previous_due').checked;
    var previousDue = parseFloat(document.getElementById('previous_due').textContent) || 0;
    if (includePreviousDue) {
        total += previousDue;
    }

    document.getElementById('total').textContent = total.toFixed(2);
    calculateDue();
}

function calculateDue() {
    var total = parseFloat(document.getElementById('total').textContent) || 0;
    var paid = document.getElementById('paid').value && document.getElementById('paid').value >= 0 ? parseFloat(document.getElementById('paid').value) : 0;
    var due = total - paid;

    document.getElementById('due').textContent = due.toFixed(2);
}

function removeProduct(button) {
    var productItem = button.parentElement;
    productItem.remove();
    calculateTotal();
}

function generateInvoice(invoiceNumber) {
    window.location.href = "invoice_sell.php?invoice_number=" + encodeURIComponent(invoiceNumber);
}

function addProductRow() {
    var productList = document.getElementById('product_list');
    var newProductItem = document.createElement('div');
    newProductItem.className = 'product_item';

    var select = document.createElement('select');
    select.name = 'product_id[]';
    select.required = true;
    
    var defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.text = 'Select Product';
    select.appendChild(defaultOption);

    <?php
    if (!empty($products)) {
        foreach ($products as $product) {
            $stock_status = $product['quantity'] > 0 ? "" : "disabled";
            $stock_display = "Stock: " . $product['quantity'] . " " . ($product['unit'] ?? '');
            $display_text = htmlspecialchars("{$product['name']} ({$product['category_name']}) - {$product['brand_name']} - {$stock_display}");
            echo "var option = document.createElement('option');";
            echo "option.value = '" . $product['id'] . "';";
            echo "option.text = '" . $display_text . "';";
            if ($product['quantity'] <= 0) {
                echo "option.disabled = true;";
            }
            echo "select.appendChild(option);";
        }
    } else {
        echo "var option = document.createElement('option');";
        echo "option.value = '';";
        echo "option.text = 'No products available';";
        echo "option.disabled = true;";
        echo "select.appendChild(option);";
    }
    ?>

    var quantityInput = document.createElement('input');
    quantityInput.type = 'number';
    quantityInput.name = 'quantity[]';
    quantityInput.placeholder = 'Quantity';
    quantityInput.min = '0';
    quantityInput.step = '0.01';
    quantityInput.required = true;
    quantityInput.addEventListener('input', function() { validateInput(this); calculateTotal(); });

    var priceInput = document.createElement('input');
    priceInput.type = 'number';
    priceInput.name = 'price[]';
    priceInput.placeholder = 'Price';
    priceInput.min = '0';
    priceInput.step = '0.01';
    priceInput.required = true;
    priceInput.addEventListener('input', function() { validateInput(this); calculateTotal(); });

    var subtotalSpan = document.createElement('span');
    subtotalSpan.innerHTML = "Subtotal: <span class='subtotal'>0.00</span>";

    var removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'remove_product';
    removeButton.textContent = '✖';
    removeButton.onclick = function() { removeProduct(this); };

    newProductItem.appendChild(select);
    newProductItem.appendChild(quantityInput);
    newProductItem.appendChild(priceInput);
    newProductItem.appendChild(subtotalSpan);
    newProductItem.appendChild(removeButton);
    newProductItem.appendChild(document.createElement('br'));
    productList.appendChild(newProductItem);

    calculateTotal();
}

document.getElementById('add_product').addEventListener('click', function() {
    addProductRow();
});

function searchSales() {
    var searchTerm = document.getElementById('search_input').value;
    var page = <?php echo $page; ?>;

    var xhr = new XMLHttpRequest();
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            document.getElementById('sales_table_body').innerHTML = xhr.responseText;
        }
    };
    xhr.open("GET", "search_sales.php?search=" + encodeURIComponent(searchTerm) + "&page=" + page, true);
    xhr.send();
}

window.onload = function() {
    document.getElementById('search_input').value = '';
};
</script>

<style>
.form-group {
    margin-bottom: 15px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
}
.form-group input, .form-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.product_item {
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.product_item select {
    width: 300px;
}
.product_item input[type="number"] {
    width: 100px;
}
.remove_product {
    margin-left: 10px;
    color: red;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 16px;
}
.remove_product:hover {
    color: darkred;
}
button {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin: 5px 0;
}
button[type="submit"], button[type="button"] {
    background-color: #4CAF50;
    color: white;
}
button[type="button"]:hover, button[type="submit"]:hover {
    background-color: #45a049;
}
.success {
    color: green;
    background-color: #e0f7fa;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
}
.error {
    color: #d32f2f;
    background-color: #ffebee;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
}
.search-container {
    margin-bottom: 20px;
}
.search-container input {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    width: 300px;
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
}
tr:nth-child(even) {
    background-color: #f9f9f9;
}
tr:hover {
    background-color: #f1f1f1;
}
.pagination {
    margin-top: 20px;
}
.pagination a, .pagination span {
    margin: 0 5px;
    padding: 8px 12px;
    text-decoration: none;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #333;
}
.pagination a:hover {
    background-color: #f1f1f1;
}
.pagination .current {
    background-color: #4CAF50;
    color: white;
    border-color: #4CAF50;
}
.pagination .disabled {
    color: #aaa;
    border-color: #ddd;
    cursor: not-allowed;
}
</style>

<?php
include '../includes/footer.php';
$conn->close();
?>