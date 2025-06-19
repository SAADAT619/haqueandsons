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

// Initialize error and success messages
$error = '';
$success = '';

// Fetch sellers with previous due
$sellerSql = "SELECT s.id, s.name, COALESCE(SUM(ph.due), 0) as previous_due 
              FROM sellers s 
              LEFT JOIN purchase_headers ph ON s.id = ph.seller_id AND ph.due > 0 
              GROUP BY s.id, s.name 
              ORDER BY s.name ASC";
$sellerResult = $conn->query($sellerSql);
if ($sellerResult === false) {
    $error = "Error fetching sellers: " . $conn->error;
}

// Fetch products for dropdown
$productSql = "SELECT p.*, c.name as category_name 
               FROM products p 
               LEFT JOIN categories c ON p.category_id = c.id 
               ORDER BY p.name ASC";
$productResult = $conn->query($productSql);
if ($productResult === false) {
    $error = "Error fetching products: " . $conn->error;
}

// Fetch payment methods
$paymentSql = "SELECT id, method FROM payment_methods ORDER BY method ASC";
$paymentResult = $conn->query($paymentSql);
if ($paymentResult === false) {
    $error = "Error fetching payment methods: " . $conn->error;
}

// Fetch rod types for Rod category
$rodTypeSql = "SELECT id, type FROM rod_types ORDER BY type ASC";
$rodTypeResult = $conn->query($rodTypeSql);
if ($rodTypeResult === false) {
    $error = "Error fetching rod types: " . $conn->error;
}

// Fetch category units
$unitSql = "SELECT cu.*, c.name as category_name 
            FROM category_units cu 
            JOIN categories c ON cu.category_id = c.id 
            ORDER BY c.name, cu.unit";
$unitResult = $conn->query($unitSql);
if ($unitResult === false) {
    $error = "Error fetching category units: " . $conn->error;
}

// Pagination setup
$records_per_page = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $records_per_page;

// Search term
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search = $conn->real_escape_string($search);

// Count total purchases for pagination
$countSql = "SELECT COUNT(*) as total 
             FROM purchase_headers ph
             JOIN sellers s ON ph.seller_id = s.id
             JOIN payment_methods pm ON ph.payment_method_id = pm.id";
if (!empty($search)) {
    $countSql .= " WHERE s.name LIKE '%$search%' 
                   OR ph.invoice_number LIKE '%$search%' 
                   OR ph.purchase_date LIKE '%$search%' 
                   OR pm.method LIKE '%$search%' 
                   OR EXISTS (
                       SELECT 1 
                       FROM purchase_items pi 
                       JOIN products p ON pi.product_id = p.id 
                       WHERE pi.purchase_id = ph.id 
                       AND p.name LIKE '%$search%'
                   )";
}
$countResult = $conn->query($countSql);
$total_purchases = $countResult ? $countResult->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_purchases / $records_per_page);

// Fetch purchase list with pagination
$purchaseListSql = "SELECT ph.id, ph.seller_id, s.name as seller_name, ph.total, ph.paid, ph.due, ph.purchase_date, 
                           pm.method as payment_method, ph.invoice_number, ph.created_at
                    FROM purchase_headers ph
                    JOIN sellers s ON ph.seller_id = s.id
                    JOIN payment_methods pm ON ph.payment_method_id = pm.id";
if (!empty($search)) {
    $purchaseListSql .= " WHERE s.name LIKE '%$search%' 
                         OR ph.invoice_number LIKE '%$search%' 
                         OR ph.purchase_date LIKE '%$search%' 
                         OR pm.method LIKE '%$search%' 
                         OR EXISTS (
                             SELECT 1 
                             FROM purchase_items pi 
                             JOIN products p ON pi.product_id = p.id 
                             WHERE pi.purchase_id = ph.id 
                             AND p.name LIKE '%$search%'
                         )";
}
$purchaseListSql .= " ORDER BY ph.created_at DESC LIMIT ? OFFSET ?";
$purchaseListStmt = $conn->prepare($purchaseListSql);
if ($purchaseListStmt === false) {
    $error = "Error preparing purchase list query: " . $conn->error;
} else {
    $purchaseListStmt->bind_param("ii", $records_per_page, $offset);
    $purchaseListStmt->execute();
    $purchaseListResult = $purchaseListStmt->get_result();
    if ($purchaseListResult === false) {
        $error = "Error fetching purchase list: " . $conn->error;
    }
}

// Process form submission for new purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_purchase'])) {
    $seller_id = filter_input(INPUT_POST, 'seller_id', FILTER_VALIDATE_INT);
    $purchase_date = filter_input(INPUT_POST, 'purchase_date');
    $payment_method_id = filter_input(INPUT_POST, 'payment_method_id', FILTER_VALIDATE_INT);
    $current_payment = filter_input(INPUT_POST, 'current_payment', FILTER_VALIDATE_FLOAT) ?? 0;
    $paid = filter_input(INPUT_POST, 'paid', FILTER_VALIDATE_FLOAT);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $units = $_POST['unit'] ?? [];
    $types = $_POST['type'] ?? [];

    if (!$seller_id || !$purchase_date || !$payment_method_id || empty($product_ids) || $paid === false) {
        $error = "All fields are required, and paid amount must be a valid number.";
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $purchase_date);
        if (!$date || $date->format('Y-m-d') !== $purchase_date) {
            $error = "Invalid purchase date format.";
        } elseif ($date > new DateTime()) {
            $error = "Purchase date cannot be in the future.";
        } else {
            $total = 0;
            for ($i = 0; $i < count($product_ids); $i++) {
                $quantity = floatval($quantities[$i]);
                $price = floatval($prices[$i]);
                if ($quantity <= 0 || $price < 0) {
                    $error = "Quantity must be greater than 0, and price must be non-negative.";
                    break;
                }
                $total += $quantity * $price;
            }

            if (!isset($error)) {
                $prevDueSql = "SELECT COALESCE(SUM(due), 0) as previous_due 
                               FROM purchase_headers 
                               WHERE seller_id = ? AND due > 0";
                $prevDueStmt = $conn->prepare($prevDueSql);
                $prevDueStmt->bind_param("i", $seller_id);
                $prevDueStmt->execute();
                $prevDueResult = $prevDueStmt->get_result();
                $previous_due = $prevDueResult->fetch_assoc()['previous_due'];
                $prevDueStmt->close();

                $due = $total - $paid;
                $effective_current_payment = min($current_payment, $previous_due);
                $excess_payment = max(0, $current_payment - $previous_due);
                $new_paid = $paid + $excess_payment;
                $total_due = ($previous_due - $effective_current_payment) + ($total - $new_paid);

                if ($total_due < 0) {
                    $error = "Total payment exceeds the combined previous due and new total.";
                } else {
                    $datePart = date('Ymd', strtotime($purchase_date));
                    $invoiceSql = "SELECT COUNT(*) as count FROM purchase_headers WHERE invoice_number LIKE 'PUR-$datePart%'";
                    $invoiceResult = $conn->query($invoiceSql);
                    $count = $invoiceResult->fetch_assoc()['count'] + 1;
                    $invoice_number = "PUR-$datePart" . str_pad($count, 3, '0', STR_PAD_LEFT);

                    $conn->begin_transaction();
                    try {
                        if ($effective_current_payment > 0 && $previous_due > 0) {
                            $remaining_payment = $effective_current_payment;
                            $fetchDueSql = "SELECT id, due, paid 
                                            FROM purchase_headers 
                                            WHERE seller_id = ? AND due > 0 
                                            ORDER BY purchase_date ASC";
                            $fetchDueStmt = $conn->prepare($fetchDueSql);
                            $fetchDueStmt->bind_param("i", $seller_id);
                            $fetchDueStmt->execute();
                            $dueRecords = $fetchDueStmt->get_result();

                            while ($remaining_payment > 0 && $record = $dueRecords->fetch_assoc()) {
                                $record_due = $record['due'];
                                $record_paid = $record['paid'];
                                $record_id = $record['id'];

                                $payment_to_apply = min($remaining_payment, $record_due);
                                $new_due = $record_due - $payment_to_apply;
                                $new_paid = $record_paid + $payment_to_apply;

                                $updatePrevDueSql = "UPDATE purchase_headers 
                                                    SET paid = ?, due = ? 
                                                    WHERE id = ?";
                                $updatePrevDueStmt = $conn->prepare($updatePrevDueSql);
                                $updatePrevDueStmt->bind_param("ddi", $new_paid, $new_due, $record_id);
                                if (!$updatePrevDueStmt->execute()) {
                                    throw new Exception("Error updating previous due: " . $updatePrevDueStmt->error);
                                }
                                $updatePrevDueStmt->close();

                                $remaining_payment -= $payment_to_apply;
                            }
                            $fetchDueStmt->close();
                        }

                        $purchaseSql = "INSERT INTO purchase_headers (seller_id, total, paid, due, purchase_date, payment_method_id, invoice_number) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $purchaseStmt = $conn->prepare($purchaseSql);
                        $purchaseStmt->bind_param("idddsis", $seller_id, $total, $new_paid, $due, $purchase_date, $payment_method_id, $invoice_number);
                        if (!$purchaseStmt->execute()) {
                            throw new Exception("Error recording purchase: " . $purchaseStmt->error);
                        }
                        $purchase_id = $conn->insert_id;
                        $purchaseStmt->close();

                        $purchaseItemSql = "INSERT INTO purchase_items (purchase_id, product_id, quantity, price, total, unit, type) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $purchaseItemStmt = $conn->prepare($purchaseItemSql);
                        for ($i = 0; $i < count($product_ids); $i++) {
                            $product_id = intval($product_ids[$i]);
                            $quantity = floatval($quantities[$i]);
                            $price = floatval($prices[$i]);
                            $itemTotal = $quantity * $price;
                            $unit = $units[$i];
                            $type = ($types[$i] !== '' && $types[$i] !== 'null') ? $types[$i] : null;

                            $purchaseItemStmt->bind_param("iiddsss", $purchase_id, $product_id, $quantity, $price, $itemTotal, $unit, $type);
                            if (!$purchaseItemStmt->execute()) {
                                throw new Exception("Error recording purchase item: " . $purchaseStmt->error);
                            }
                        }
                        $purchaseItemStmt->close();

                        $legacyPurchaseSql = "INSERT INTO purchases (seller_id, product_id, quantity, price, total, paid, due, purchase_date, payment_method_id, invoice_number, unit, type) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $legacyStmt = $conn->prepare($legacyPurchaseSql);

                        $nullProductId = null;
                        $nullQuantity = null;
                        $nullPrice = null;
                        $nullUnit = null;
                        $nullType = null;
                        $legacyStmt->bind_param("iiddsddsisss", $seller_id, $nullProductId, $nullQuantity, $nullPrice, $total, $new_paid, $due, $purchase_date, $payment_method_id, $invoice_number, $nullUnit, $nullType);
                        if (!$legacyStmt->execute()) {
                            throw new Exception("Error recording legacy purchase: " . $legacyStmt->error);
                        }

                        for ($i = 0; $i < count($product_ids); $i++) {
                            $product_id = intval($product_ids[$i]);
                            $quantity = floatval($quantities[$i]);
                            $price = floatval($prices[$i]);
                            $itemTotal = $quantity * $price;
                            $unit = $units[$i];
                            $type = ($types[$i] !== '' && $types[$i] !== 'null') ? $types[$i] : null;

                            $legacyStmt->bind_param("iiddsddsisss", $seller_id, $product_id, $quantity, $price, $itemTotal, $new_paid, $due, $purchase_date, $payment_method_id, $invoice_number, $unit, $type);
                            if (!$legacyStmt->execute()) {
                                throw new Exception("Error recording legacy purchase item: " . $legacyStmt->error);
                            }
                        }
                        $legacyStmt->close();

                        $conn->commit();
                        $success = "Purchase recorded successfully. Invoice Number: $invoice_number";
                        $purchaseListStmt = $conn->prepare($purchaseListSql);
                        $purchaseListStmt->bind_param("ii", $records_per_page, $offset);
                        $purchaseListStmt->execute();
                        $purchaseListResult = $purchaseListStmt->get_result();
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = "Failed to record purchase: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Process update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_purchase'])) {
    $purchase_id = filter_input(INPUT_POST, 'purchase_id', FILTER_VALIDATE_INT);
    $seller_id = filter_input(INPUT_POST, 'seller_id', FILTER_VALIDATE_INT);
    $purchase_date = filter_input(INPUT_POST, 'purchase_date');
    $payment_method_id = filter_input(INPUT_POST, 'payment_method_id', FILTER_VALIDATE_INT);
    $additional_paid = filter_input(INPUT_POST, 'additional_paid', FILTER_VALIDATE_FLOAT) ?? 0;
    $current_paid = filter_input(INPUT_POST, 'current_paid', FILTER_VALIDATE_FLOAT);
    $total = filter_input(INPUT_POST, 'total', FILTER_VALIDATE_FLOAT);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $units = $_POST['unit'] ?? [];
    $types = $_POST['type'] ?? [];

    if (!$purchase_id || !$seller_id || !$purchase_date || !$payment_method_id || $additional_paid === false || $current_paid === false || $total === false || empty($product_ids)) {
        $error = "All fields are required, and paid amounts must be valid numbers.";
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $purchase_date);
        if (!$date || $date->format('Y-m-d') !== $purchase_date) {
            $error = "Invalid purchase date format.";
        } elseif ($date > new DateTime()) {
            $error = "Purchase date cannot be in the future.";
        } else {
            $new_total = 0;
            for ($i = 0; $i < count($product_ids); $i++) {
                $quantity = floatval($quantities[$i]);
                $price = floatval($prices[$i]);
                if ($quantity <= 0 || $price < 0) {
                    $error = "Quantity must be greater than 0, and price must be non-negative.";
                    break;
                }
                $new_total += $quantity * $price;
            }

            if (!isset($error)) {
                $new_paid = $current_paid + $additional_paid;
                $due = $new_total - $new_paid;
                if ($due < 0) {
                    $error = "Total paid amount cannot exceed the grand total.";
                } else {
                    $conn->begin_transaction();
                    try {
                        $invoiceSql = "SELECT invoice_number FROM purchase_headers WHERE id = ?";
                        $invoiceStmt = $conn->prepare($invoiceSql);
                        $invoiceStmt->bind_param("i", $purchase_id);
                        $invoiceStmt->execute();
                        $invoiceResult = $invoiceStmt->get_result();
                        $invoice_number = $invoiceResult->fetch_assoc()['invoice_number'];
                        $invoiceStmt->close();

                        $updateSql = "UPDATE purchase_headers 
                                      SET seller_id = ?, total = ?, paid = ?, due = ?, purchase_date = ?, payment_method_id = ?
                                      WHERE id = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->bind_param("idddsis", $seller_id, $new_total, $new_paid, $due, $purchase_date, $payment_method_id, $purchase_id);
                        if (!$updateStmt->execute()) {
                            throw new Exception("Error updating purchase: " . $updateStmt->error);
                        }
                        $updateStmt->close();

                        $deleteItemsSql = "DELETE FROM purchase_items WHERE purchase_id = ?";
                        $deleteItemsStmt = $conn->prepare($deleteItemsSql);
                        $deleteItemsStmt->bind_param("i", $purchase_id);
                        if (!$deleteItemsStmt->execute()) {
                            throw new Exception("Error deleting existing purchase items: " . $deleteItemsStmt->error);
                        }
                        $deleteItemsStmt->close();

                        $purchaseItemSql = "INSERT INTO purchase_items (purchase_id, product_id, quantity, price, total, unit, type) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $purchaseItemStmt = $conn->prepare($purchaseItemSql);
                        for ($i = 0; $i < count($product_ids); $i++) {
                            $product_id = intval($product_ids[$i]);
                            $quantity = floatval($quantities[$i]);
                            $price = floatval($prices[$i]);
                            $itemTotal = $quantity * $price;
                            $unit = $units[$i];
                            $type = ($types[$i] !== '' && $types[$i] !== 'null') ? $types[$i] : null;

                            $purchaseItemStmt->bind_param("iiddsss", $purchase_id, $product_id, $quantity, $price, $itemTotal, $unit, $type);
                            if (!$purchaseItemStmt->execute()) {
                                throw new Exception("Error recording updated purchase item: " . $purchaseItemStmt->error);
                            }
                        }
                        $purchaseItemStmt->close();

                        $deleteLegacySql = "DELETE FROM purchases WHERE invoice_number = ?";
                        $deleteLegacyStmt = $conn->prepare($deleteLegacySql);
                        $deleteLegacyStmt->bind_param("s", $invoice_number);
                        if (!$deleteLegacyStmt->execute()) {
                            throw new Exception("Error deleting legacy purchase entries: " . $deleteLegacyStmt->error);
                        }
                        $deleteLegacyStmt->close();

                        $legacyPurchaseSql = "INSERT INTO purchases (seller_id, product_id, quantity, price, total, paid, due, purchase_date, payment_method_id, invoice_number, unit, type) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $legacyStmt = $conn->prepare($legacyPurchaseSql);

                        $nullProductId = null;
                        $nullQuantity = null;
                        $nullPrice = null;
                        $nullUnit = null;
                        $nullType = null;
                        $legacyStmt->bind_param("iiddsddsisss", $seller_id, $nullProductId, $nullQuantity, $nullPrice, $new_total, $new_paid, $due, $purchase_date, $payment_method_id, $invoice_number, $nullUnit, $nullType);
                        if (!$legacyStmt->execute()) {
                            throw new Exception("Error updating legacy purchase: " . $legacyStmt->error);
                        }

                        for ($i = 0; $i < count($product_ids); $i++) {
                            $product_id = intval($product_ids[$i]);
                            $quantity = floatval($quantities[$i]);
                            $price = floatval($prices[$i]);
                            $itemTotal = $quantity * $price;
                            $unit = $units[$i];
                            $type = ($types[$i] !== '' && $types[$i] !== 'null') ? $types[$i] : null;

                            $legacyStmt->bind_param("iiddsddsisss", $seller_id, $product_id, $quantity, $price, $itemTotal, $new_paid, $due, $purchase_date, $payment_method_id, $invoice_number, $unit, $type);
                            if (!$legacyStmt->execute()) {
                                throw new Exception("Error updating legacy purchase item: " . $legacyStmt->error);
                            }
                        }
                        $legacyStmt->close();

                        $conn->commit();
                        $success = "Purchase updated successfully.";
                        $purchaseListStmt = $conn->prepare($purchaseListSql);
                        $purchaseListStmt->bind_param("ii", $records_per_page, $offset);
                        $purchaseListStmt->execute();
                        $purchaseListResult = $purchaseListStmt->get_result();
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = "Failed to update purchase: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Fetch purchase details for editing if edit_id is set
$editPurchase = null;
if (isset($_GET['edit_id'])) {
    $edit_id = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
    if ($edit_id) {
        $editSql = "SELECT * FROM purchase_headers WHERE id = ?";
        $editStmt = $conn->prepare($editSql);
        $editStmt->bind_param("i", $edit_id);
        $editStmt->execute();
        $editResult = $editStmt->get_result();
        if ($editResult->num_rows > 0) {
            $editPurchase = $editResult->fetch_assoc();

            $itemsSql = "SELECT pi.*, p.name as product_name, p.category_id, c.name as category_name, p.brand_name, p.unit as default_unit, p.price as default_price
                         FROM purchase_items pi
                         JOIN products p ON pi.product_id = p.id
                         JOIN categories c ON p.category_id = c.id
                         WHERE pi.purchase_id = ?";
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bind_param("i", $edit_id);
            $itemsStmt->execute();
            $editPurchase['items'] = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();
        }
        $editStmt->close();
    }
}
?>

<h2>Buy Products</h2>

<?php if (!empty($error)) { ?>
    <p class="error"><?php echo htmlspecialchars($error); ?></p>
<?php } ?>

<?php if (!empty($success)) { ?>
    <p class="success"><?php echo htmlspecialchars($success); ?></p>
<?php } ?>

<!-- Form for adding/updating purchases -->
<h3><?php echo isset($editPurchase) ? 'Update Purchase' : 'Add New Purchase'; ?></h3>
<form method="POST" action="">
    <?php if (isset($editPurchase)) { ?>
        <input type="hidden" name="update_purchase" value="1">
        <input type="hidden" name="purchase_id" value="<?php echo htmlspecialchars($editPurchase['id']); ?>">
        <input type="hidden" name="current_paid" value="<?php echo htmlspecialchars($editPurchase['paid']); ?>">
    <?php } ?>
    <div class="form-group">
        <label for="seller_id">Select Seller</label>
        <select name="seller_id" id="seller_id" required onchange="fetchSellerDue()">
            <option value="">Select Seller</option>
            <?php
            if ($sellerResult && $sellerResult->num_rows > 0) {
                $sellerResult->data_seek(0);
                while ($seller = $sellerResult->fetch_assoc()) {
                    $selected = (isset($editPurchase) && $editPurchase['seller_id'] == $seller['id']) ? 'selected' : '';
                    echo "<option value='{$seller['id']}' data-previous-due='{$seller['previous_due']}' $selected>" . htmlspecialchars($seller['name']) . " (Previous Due: " . number_format($seller['previous_due'], 2) . ")</option>";
                }
            } else {
                echo "<option value='' disabled>No sellers available</option>";
            }
            ?>
        </select>
        <div id="seller_due_display" style="margin-top: 5px; color: #d32f2f;"></div>
    </div>

    <div class="form-group">
        <label for="purchase_date">Purchase Date</label>
        <input type="date" name="purchase_date" id="purchase_date" value="<?php echo isset($editPurchase) ? htmlspecialchars($editPurchase['purchase_date']) : date('Y-m-d'); ?>" required max="<?php echo date('Y-m-d'); ?>">
    </div>

    <div id="product-items">
        <?php
        $isEditMode = isset($editPurchase);
        if ($isEditMode && !empty($editPurchase['items'])) {
            $index = 0;
            foreach ($editPurchase['items'] as $item) {
        ?>
                <div class="product-item">
                    <h4>Product <?php echo $index + 1; ?></h4>
                    <div class="form-group">
                        <label for="product_id_<?php echo $index; ?>">Select Product</label>
                        <select name="product_id[]" id="product_id_<?php echo $index; ?>" class="product-select" required onchange="updateProductDetails(<?php echo $index; ?>, <?php echo $isEditMode ? 'true' : 'false'; ?>)">
                            <option value="">Select Product</option>
                            <?php
                            $productResult->data_seek(0);
                            while ($product = $productResult->fetch_assoc()) {
                                $displayText = htmlspecialchars("{$product['name']} ({$product['category_name']}) - {$product['brand_name']} - {$product['unit']} - Price: {$product['price']} - Stock: {$product['quantity']}");
                                $dataCategory = htmlspecialchars($product['category_name']);
                                $dataUnit = htmlspecialchars($product['unit']);
                                $dataPrice = $product['price'];
                                $selected = ($item['product_id'] == $product['id']) ? 'selected' : '';
                                echo "<option value='{$product['id']}' data-category='$dataCategory' data-unit='$dataUnit' data-price='$dataPrice' $selected>$displayText</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="quantity_<?php echo $index; ?>">Quantity</label>
                        <input type="number" name="quantity[]" id="quantity_<?php echo $index; ?>" step="0.01" min="0.01" value="<?php echo htmlspecialchars($item['quantity']); ?>" required oninput="calculateTotal(<?php echo $index; ?>)">
                    </div>
                    <div class="form-group">
                        <label for="price_<?php echo $index; ?>">Price</label>
                        <input type="number" name="price[]" id="price_<?php echo $index; ?>" step="0.01" min="0" value="<?php echo htmlspecialchars($item['price']); ?>" required oninput="calculateTotal(<?php echo $index; ?>)">
                    </div>
                    <div class="form-group">
                        <label for="total_<?php echo $index; ?>">Total</label>
                        <input type="number" id="total_<?php echo $index; ?>" step="0.01" value="<?php echo htmlspecialchars($item['total']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="unit_<?php echo $index; ?>">Unit</label>
                        <select name="unit[]" id="unit_<?php echo $index; ?>" required>
                            <option value="">Select Unit</option>
                            <?php
                            $unitResult->data_seek(0);
                            while ($unit = $unitResult->fetch_assoc()) {
                                if ($unit['category_name'] == $item['category_name']) {
                                    $selected = ($unit['unit'] == $item['unit']) ? 'selected' : '';
                                    echo "<option value='{$unit['unit']}' $selected>" . htmlspecialchars($unit['unit']) . "</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group type-group" style="display: <?php echo ($item['category_name'] == 'Rod') ? 'block' : 'none'; ?>;">
                        <label for="type_<?php echo $index; ?>">Type (Rod Only)</label>
                        <select name="type[]" id="type_<?php echo $index; ?>">
                            <option value="">Select Type</option>
                            <?php
                            $rodTypeResult->data_seek(0);
                            while ($rodType = $rodTypeResult->fetch_assoc()) {
                                $selected = ($item['type'] == $rodType['type']) ? 'selected' : '';
                                echo "<option value='{$rodType['type']}' $selected>" . htmlspecialchars($rodType['type']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <?php if ($index > 0) { ?>
                        <button type="button" onclick="removeProductItem(this)">Remove</button>
                    <?php } ?>
                </div>
        <?php
                $index++;
            }
        } else {
        ?>
            <div class="product-item">
                <h4>Product 1</h4>
                <div class="form-group">
                    <label for="product_id_0">Select Product</label>
                    <select name="product_id[]" id="product_id_0" class="product-select" required onchange="updateProductDetails(0, false)">
                        <option value="">Select Product</option>
                        <?php
                        if ($productResult && $productResult->num_rows > 0) {
                            $productResult->data_seek(0);
                            while ($product = $productResult->fetch_assoc()) {
                                $displayText = htmlspecialchars("{$product['name']} ({$product['category_name']}) - {$product['brand_name']} - {$product['unit']} - Price: {$product['price']} - Stock: {$product['quantity']}");
                                $dataCategory = htmlspecialchars($product['category_name']);
                                $dataUnit = htmlspecialchars($product['unit']);
                                $dataPrice = $product['price'];
                                echo "<option value='{$product['id']}' data-category='$dataCategory' data-unit='$dataUnit' data-price='$dataPrice'>$displayText</option>";
                            }
                        } else {
                            echo "<option value='' disabled>No products available</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="quantity_0">Quantity</label>
                    <input type="number" name="quantity[]" id="quantity_0" step="0.01" min="0.01" required oninput="calculateTotal(0)">
                </div>
                <div class="form-group">
                    <label for="price_0">Price</label>
                    <input type="number" name="price[]" id="price_0" step="0.01" min="0" required oninput="calculateTotal(0)">
                </div>
                <div class="form-group">
                    <label for="total_0">Total</label>
                    <input type="number" id="total_0" step="0.01" readonly>
                </div>
                <div class="form-group">
                    <label for="unit_0">Unit</label>
                    <select name="unit[]" id="unit_0" required>
                        <option value="">Select Unit</option>
                    </select>
                </div>
                <div class="form-group type-group" style="display: none;">
                    <label for="type_0">Type (Rod Only)</label>
                    <select name="type[]" id="type_0">
                        <option value="">Select Type</option>
                        <?php
                        if ($rodTypeResult && $rodTypeResult->num_rows > 0) {
                            $rodTypeResult->data_seek(0);
                            while ($rodType = $rodTypeResult->fetch_assoc()) {
                                echo "<option value='{$rodType['type']}'>" . htmlspecialchars($rodType['type']) . "</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
        <?php } ?>
    </div>

    <?php if (!isset($editPurchase)) { ?>
        <button type="button" onclick="addProductItem()">Add Another Product</button>
    <?php } ?>

    <div class="form-group">
        <label for="grand_total">Grand Total</label>
        <input type="number" id="grand_total" step="0.01" value="<?php echo isset($editPurchase) ? htmlspecialchars($editPurchase['total']) : '0'; ?>" readonly>
    </div>

    <div class="form-group">
        <?php if (isset($editPurchase)) { ?>
            <label>Current Paid Amount</label>
            <input type="number" value="<?php echo htmlspecialchars($editPurchase['paid']); ?>" readonly>
            <label for="additional_paid">Add to Paid Amount</label>
            <input type="number" name="additional_paid" id="additional_paid" step="0.01" min="0" value="0" oninput="calculateDue()">
        <?php } else { ?>
            <label for="current_payment">Current Payment (for Previous Due)</label>
            <input type="number" name="current_payment" id="current_payment" step="0.01" min="0" value="0" oninput="calculateDue()">
            <label for="paid">Paid Amount (for New Purchase)</label>
            <input type="number" name="paid" id="paid" step="0.01" min="0" required oninput="calculateDue()">
        <?php } ?>
    </div>

    <div class="form-group">
        <label for="due">Due Amount</label>
        <input type="number" id="due" step="0.01" value="<?php echo isset($editPurchase) ? htmlspecialchars($editPurchase['due']) : '0'; ?>" readonly>
    </div>

    <div class="form-group">
        <label for="payment_method_id">Payment Method</label>
        <select name="payment_method_id" id="payment_method_id" required>
            <option value="">Select Payment Method</option>
            <?php
            if ($paymentResult && $paymentResult->num_rows > 0) {
                $paymentResult->data_seek(0);
                while ($payment = $paymentResult->fetch_assoc()) {
                    $selected = (isset($editPurchase) && $editPurchase['payment_method_id'] == $payment['id']) ? 'selected' : '';
                    echo "<option value='{$payment['id']}' $selected>" . htmlspecialchars($payment['method']) . "</option>";
                }
            } else {
                echo "<option value='' disabled>No payment methods available</option>";
            }
            ?>
        </select>
    </div>

    <button type="submit"><?php echo isset($editPurchase) ? 'Update Purchase' : 'Record Purchase'; ?></button>
    <?php if (isset($editPurchase)) { ?>
        <a href="buy.php" class="btn btn-secondary">Cancel Update</a>
    <?php } ?>
</form>

<!-- Search Bar -->
<div class="search-container">
    <input type="text" id="searchInput" placeholder="Search purchases (e.g., seller, invoice, date, product)..." value="<?php echo htmlspecialchars($search); ?>" onkeyup="searchPurchases()">
</div>

<!-- Purchase List -->
<h3>Purchase List</h3>
<?php if ($purchaseListResult && $purchaseListResult->num_rows > 0) { ?>
    <table id="purchaseTable">
        <thead>
            <tr>
                <th>Invoice Number</th>
                <th>Seller</th>
                <th>Products</th>
                <th>Purchase Date</th>
                <th>Payment Method</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Due</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($purchase = $purchaseListResult->fetch_assoc()) { ?>
                <tr class="purchase-row">
                    <td class="invoice-number"><?php echo htmlspecialchars($purchase['invoice_number']); ?></td>
                    <td class="seller-name"><?php echo htmlspecialchars($purchase['seller_name']); ?></td>
                    <td class="product-details-cell">
                        <?php
                        $itemsSql = "SELECT pi.*, p.name as product_name 
                                     FROM purchase_items pi 
                                     LEFT JOIN products p ON pi.product_id = p.id 
                                     WHERE pi.purchase_id = ?";
                        $itemsStmt = $conn->prepare($itemsSql);
                        $itemsStmt->bind_param("i", $purchase['id']);
                        $itemsStmt->execute();
                        $itemsResult = $itemsStmt->get_result();
                        $productNames = [];
                        if ($itemsResult->num_rows > 0) {
                            echo "<ul class='product-details'>";
                            while ($item = $itemsResult->fetch_assoc()) {
                                $productNames[] = htmlspecialchars($item['product_name'] ?? 'Unknown Product');
                                echo "<li>";
                                echo "<strong>" . htmlspecialchars($item['product_name'] ?? 'Unknown Product') . "</strong>";
                                echo "<ul>";
                                echo "<li><strong>Qty:</strong> " . htmlspecialchars($item['quantity']) . "</li>";
                                echo "<li><strong>Price:</strong> " . number_format($item['price'], 2) . "</li>";
                                echo "<li><strong>Total:</strong> " . number_format($item['total'], 2) . "</li>";
                                echo "<li><strong>Unit:</strong> " . htmlspecialchars($item['unit']) . "</li>";
                                if ($item['type']) {
                                    echo "<li><strong>Type:</strong> " . htmlspecialchars($item['type']) . "</li>";
                                }
                                echo "</ul>";
                                echo "</li>";
                            }
                            echo "</ul>";
                        } else {
                            echo "No products found.";
                        }
                        $itemsStmt->close();
                        $productNamesStr = implode(', ', $productNames);
                        ?>
                        <span class="product-names" style="display: none;"><?php echo htmlspecialchars($productNamesStr); ?></span>
                    </td>
                    <td class="purchase-date"><?php echo htmlspecialchars($purchase['purchase_date']); ?></td>
                    <td class="payment-method"><?php echo htmlspecialchars($purchase['payment_method']); ?></td>
                    <td class="total"><?php echo number_format($purchase['total'], 2); ?></td>
                    <td class="paid"><?php echo number_format($purchase['paid'], 2); ?></td>
                    <td class="due"><?php echo number_format($purchase['due'], 2); ?></td>
                    <td class="created-at"><?php echo htmlspecialchars($purchase['created_at']); ?></td>
                    <td class="action-buttons">
                        <a href="invoice.php?purchase_id=<?php echo htmlspecialchars($purchase['id']); ?>" target="_blank" class="btn btn-info">Invoice</a>
                        <a href="buy.php?edit_id=<?php echo htmlspecialchars($purchase['id']); ?>" class="btn btn-primary">Edit</a>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <div class="pagination">
        <?php
        $query_string = !empty($search) ? "&search=" . urlencode($search) : "";
        if ($page > 1) {
            echo "<a href='buy.php?page=" . ($page - 1) . "$query_string' class='btn btn-primary'>Previous</a>";
        }
        for ($i = 1; $i <= $total_pages; $i++) {
            $active = ($i == $page) ? ' active' : '';
            echo "<a href='buy.php?page=$i$query_string' class='btn btn-primary$active'>$i</a>";
        }
        if ($page < $total_pages) {
            echo "<a href='buy.php?page=" . ($page + 1) . "$query_string' class='btn btn-primary'>Next</a>";
        }
        ?>
    </div>
<?php } else { ?>
    <p>No purchases found.</p>
<?php } ?>

<?php if (isset($purchaseListStmt)) $purchaseListStmt->close(); ?>

<script>
let productCount = <?php echo isset($editPurchase) ? count($editPurchase['items']) : 1; ?>;

// Preload category units into a JavaScript object
const categoryUnits = {};
<?php
if ($unitResult && $unitResult->num_rows > 0) {
    $unitResult->data_seek(0);
    while ($unit = $unitResult->fetch_assoc()) {
        echo "if (!categoryUnits['{$unit['category_name']}']) categoryUnits['{$unit['category_name']}'] = [];\n";
        echo "categoryUnits['{$unit['category_name']}'].push('{$unit['unit']}');\n";
    }
}
?>

function addProductItem() {
    const container = document.getElementById('product-items');
    const newItem = document.createElement('div');
    newItem.className = 'product-item';
    newItem.innerHTML = `
        <h4>Product ${productCount + 1}</h4>
        <div class="form-group">
            <label for="product_id_${productCount}">Select Product</label>
            <select name="product_id[]" id="product_id_${productCount}" class="product-select" required onchange="updateProductDetails(${productCount}, false)">
                <option value="">Select Product</option>
                <?php
                $productResult->data_seek(0);
                while ($product = $productResult->fetch_assoc()) {
                    $displayText = htmlspecialchars("{$product['name']} ({$product['category_name']}) - {$product['brand_name']} - {$product['unit']} - Price: {$product['price']} - Stock: {$product['quantity']}");
                    $dataCategory = htmlspecialchars($product['category_name']);
                    $dataUnit = htmlspecialchars($product['unit']);
                    $dataPrice = $product['price'];
                    echo "<option value='{$product['id']}' data-category='$dataCategory' data-unit='$dataUnit' data-price='$dataPrice'>$displayText</option>";
                }
                ?>
            </select>
        </div>
        <div class="form-group">
            <label for="quantity_${productCount}">Quantity</label>
            <input type="number" name="quantity[]" id="quantity_${productCount}" step="0.01" min="0.01" required oninput="calculateTotal(${productCount})">
        </div>
        <div class="form-group">
            <label for="price_${productCount}">Price</label>
            <input type="number" name="price[]" id="price_${productCount}" step="0.01" min="0" required oninput="calculateTotal(${productCount})">
        </div>
        <div class="form-group">
            <label for="total_${productCount}">Total</label>
            <input type="number" id="total_${productCount}" step="0.01" readonly>
        </div>
        <div class="form-group">
            <label for="unit_${productCount}">Unit</label>
            <select name="unit[]" id="unit_${productCount}" required>
                <option value="">Select Unit</option>
            </select>
        </div>
        <div class="form-group type-group" style="display: none;">
            <label for="type_${productCount}">Type (Rod Only)</label>
            <select name="type[]" id="type_${productCount}">
                <option value="">Select Type</option>
                <?php
                $rodTypeResult->data_seek(0);
                while ($rodType = $rodTypeResult->fetch_assoc()) {
                    echo "<option value='{$rodType['type']}'>" . htmlspecialchars($rodType['type']) . "</option>";
                }
                ?>
            </select>
        </div>
        <button type="button" onclick="removeProductItem(this)">Remove</button>
    `;
    container.appendChild(newItem);
    productCount++;
    updateGrandTotal();
}

function removeProductItem(button) {
    button.parentElement.remove();
    productCount--;
    updateGrandTotal();
}

function updateProductDetails(index, isEditMode) {
    const select = document.getElementById(`product_id_${index}`);
    const unitSelect = document.getElementById(`unit_${index}`);
    const typeGroup = select.parentElement.parentElement.querySelector('.type-group');
    const priceInput = document.getElementById(`price_${index}`);

    unitSelect.innerHTML = '<option value="">Select Unit</option>';
    typeGroup.style.display = 'none';

    if (select.value) {
        const selectedOption = select.options[select.selectedIndex];
        const category = selectedOption.getAttribute('data-category');
        const defaultUnit = selectedOption.getAttribute('data-unit');
        const defaultPrice = parseFloat(selectedOption.getAttribute('data-price'));

        if (categoryUnits[category]) {
            categoryUnits[category].forEach(unit => {
                const option = document.createElement('option');
                option.value = unit;
                option.text = unit;
                if (unit === defaultUnit) option.selected = true;
                unitSelect.appendChild(option);
            });
        }

        if (category === 'Rod') typeGroup.style.display = 'block';

        if (!isEditMode) priceInput.value = defaultPrice.toFixed(2);
        calculateTotal(index);
    }
}

function calculateTotal(index) {
    const quantity = parseFloat(document.getElementById(`quantity_${index}`).value) || 0;
    const price = parseFloat(document.getElementById(`price_${index}`).value) || 0;
    const total = quantity * price;
    document.getElementById(`total_${index}`).value = total.toFixed(2);
    updateGrandTotal();
}

function updateGrandTotal() {
    let grandTotal = 0;
    for (let i = 0; i < productCount; i++) {
        const totalInput = document.getElementById(`total_${i}`);
        if (totalInput) grandTotal += parseFloat(totalInput.value) || 0;
    }
    document.getElementById('grand_total').value = grandTotal.toFixed(2);
    calculateDue();
}

function calculateDue() {
    const grandTotal = parseFloat(document.getElementById('grand_total').value) || 0;
    const sellerSelect = document.getElementById('seller_id');
    let currentPayment = 0, paid = 0, additionalPaid = 0;

    if (document.getElementById('current_payment')) currentPayment = parseFloat(document.getElementById('current_payment').value) || 0;
    if (document.getElementById('paid')) paid = parseFloat(document.getElementById('paid').value) || 0;
    if (document.getElementById('additional_paid')) additionalPaid = parseFloat(document.getElementById('additional_paid').value) || 0;

    let previousDue = 0;
    if (sellerSelect.value) previousDue = parseFloat(sellerSelect.options[sellerSelect.selectedIndex].getAttribute('data-previous-due')) || 0;

    let totalDue = 0;
    if (document.getElementById('current_payment')) {
        const effectiveCurrentPayment = Math.min(currentPayment, previousDue);
        const excessPayment = currentPayment - effectiveCurrentPayment;
        totalDue = (previousDue - effectiveCurrentPayment) + (grandTotal - (paid + excessPayment));
    } else {
        const currentPaid = parseFloat(document.querySelector('input[name="current_paid"]').value) || 0;
        totalDue = grandTotal - (currentPaid + additionalPaid);
    }

    totalDue = Math.max(totalDue, 0);
    document.getElementById('due').value = totalDue.toFixed(2);
}

function fetchSellerDue() {
    const select = document.getElementById('seller_id');
    const dueDisplay = document.getElementById('seller_due_display');
    if (select.value) {
        const previousDue = parseFloat(select.options[select.selectedIndex].getAttribute('data-previous-due')) || 0;
        dueDisplay.textContent = `Previous Due: ${numberFormat(previousDue)}`;
        calculateDue();
    } else {
        dueDisplay.textContent = '';
        document.getElementById('due').value = document.getElementById('grand_total').value;
    }
}

function searchPurchases() {
    const input = document.getElementById('searchInput');
    const filter = input.value.trim();
    window.location.href = `buy.php?page=1&search=${encodeURIComponent(filter)}`;
}

function numberFormat(number) {
    return number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

document.addEventListener('DOMContentLoaded', () => {
    for (let i = 0; i < productCount; i++) {
        updateProductDetails(i, <?php echo isset($editPurchase) ? 'true' : 'false'; ?>);
        calculateTotal(i);
    }
    fetchSellerDue();
    calculateDue();
});
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

.product-item {
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 4px;
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

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

table, th, td {
    border: 1px solid #ddd;
}

th, td {
    padding: 10px;
    text-align: left;
}

th {
    background-color: #f2f2f2;
    font-weight: bold;
}

th:last-child, td.action-buttons {
    width: 150px;
    text-align: center;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px 0;
}

.btn {
    display: block;
    width: 100px;
    padding: 10px;
    margin-bottom: 8px;
    text-decoration: none;
    border-radius: 4px;
    font-size: 14px;
    text-align: center;
    color: white;
    transition: background-color 0.3s ease, transform 0.1s ease;
}

.btn:last-child {
    margin-bottom: 0;
}

.btn:hover {
    transform: scale(1.05);
}

.btn-primary {
    background-color: #007bff;
}

.btn-primary:hover {
    background-color: #0056b3;
}

.btn-info {
    background-color: #17a2b8;
}

.btn-info:hover {
    background-color: #138496;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #5a6268;
}

.product-details {
    list-style: none;
    padding: 0;
    margin: 0;
}

.product-details li {
    margin-bottom: 10px;
    padding: 5px;
    background-color: #f9f9f9;
    border-radius: 4px;
}

.product-details li strong {
    color: #333;
}

.product-details ul {
    list-style: none;
    padding-left: 15px;
    margin: 5px 0 0 0;
}

.product-details ul li {
    margin-bottom: 2px;
    padding: 0;
    background-color: transparent;
    font-size: 0.95em;
}

.search-container {
    margin-bottom: 20px;
}

#searchInput {
    width: 100%;
    padding: 10px;
    font-size: 16px;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

#searchInput:focus {
    outline: none;
    border-color: #4CAF50;
    box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
}

.pagination {
    margin-top: 20px;
    text-align: center;
}

.pagination a {
    margin: 0 5px;
    padding: 8px 16px;
    text-decoration: none;
    border-radius: 4px;
    color: white;
    background-color: #007bff;
    display: inline-block;
}

.pagination a:hover {
    background-color: #0056b3;
}

.pagination a.active {
    background-color: #6c757d;
    cursor: default;
}
</style>

<?php
$conn->close();
include '../includes/footer.php';
?>