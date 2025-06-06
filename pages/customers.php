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

// Handle customer form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_customer'])) {
        $name = sanitizeInput($_POST['name']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);

        $sql = "INSERT INTO customers (name, phone, address) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sss", $name, $phone, $address);
            if ($stmt->execute()) {
                $message = "Customer added successfully";
            } else {
                $error = "Error adding customer: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing statement: " . $conn->error;
        }
    } elseif (isset($_POST['update_customer'])) {
        $id = sanitizeInput($_POST['id']);
        $name = sanitizeInput($_POST['name']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);

        $sql = "UPDATE customers SET name=?, phone=?, address=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssi", $name, $phone, $address, $id);
            if ($stmt->execute()) {
                $message = "Customer updated successfully";
            } else {
                $error = "Error updating customer: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing statement: " . $conn->error;
        }
    }
}

// Pagination logic
$customersPerPage = 10;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$offset = ($currentPage - 1) * $customersPerPage;

// Get total number of customers
$totalCustomersSql = "SELECT COUNT(*) as total FROM customers";
$totalResult = $conn->query($totalCustomersSql);
$totalRow = $totalResult->fetch_assoc();
$totalCustomers = $totalRow['total'];
$totalPages = ceil($totalCustomers / $customersPerPage);

// Fetch customers for the current page
$customersSql = "SELECT * FROM customers LIMIT ? OFFSET ?";
$stmt = $conn->prepare($customersSql);
if ($stmt) {
    $stmt->bind_param("ii", $customersPerPage, $offset);
    $stmt->execute();
    $customersResult = $stmt->get_result();
    $stmt->close();
} else {
    die("Error preparing statement: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers</title>
    <style>
        .success {
            color: green;
            padding: 10px;
            background-color: #e0f7e0;
            margin: 10px 0;
            border-radius: 4px;
        }
        .error {
            color: red;
            padding: 10px;
            background-color: #ffe0e0;
            margin: 10px 0;
            border-radius: 4px;
        }
        form {
            margin: 20px 0;
        }
        input[type="text"], textarea {
            width: 100%;
            max-width: 400px;
            padding: 8px;
            margin: 5px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px 0;
        }
        button:hover {
            background-color: #45a049;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
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
        .search-container {
            margin: 20px 0;
        }
        #searchInput {
            width: 100%;
            max-width: 400px;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .pagination {
            margin: 20px 0;
            text-align: center;
        }
        .pagination a {
            color: #4CAF50;
            padding: 8px 16px;
            text-decoration: none;
            border: 1px solid #ddd;
            margin: 0 4px;
            border-radius: 4px;
        }
        .pagination a:hover {
            background-color: #f1f1f1;
        }
        .pagination a.active {
            background-color: #4CAF50;
            color: white;
            border: 1px solid #4CAF50;
        }
        .pagination a.disabled {
            color: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <h2>Customers</h2>

    <?php if (isset($message)) {
        echo "<p class='success'>" . htmlspecialchars($message) . "</p>";
    } ?>
    <?php if (isset($error)) {
        echo "<p class='error'>" . htmlspecialchars($error) . "</p>";
    } ?>

    <form method="post">
        <input type="text" name="name" placeholder="Customer Name" required><br>
        <input type="text" name="phone" placeholder="Phone" required><br>
        <input type="text" name="address" placeholder="Address" required><br>
        <button type="submit" name="add_customer">Add Customer</button>
    </form>

    <hr>

    <h3>Customer List</h3>
    <div class="search-container">
        <input type="text" id="searchInput" placeholder="Search customers..." onkeyup="searchCustomers()">
    </div>
    <table id="customerTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($customersResult && $customersResult->num_rows > 0) {
                while ($customerRow = $customersResult->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($customerRow['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($customerRow['phone'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($customerRow['address'] ?? 'N/A') . "</td>";
                    echo "<td>";
                    echo "<button onclick=\"editCustomer(" . $customerRow['id'] . ", '" . addslashes(htmlspecialchars($customerRow['name'])) . "', '" . addslashes(htmlspecialchars($customerRow['phone'] ?? '')) . "', '" . addslashes(htmlspecialchars($customerRow['address'] ?? '')) . "')\">Edit</button>";
                    echo "</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4'>No customers found</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <!-- Pagination Links -->
    <div class="pagination">
        <?php
        // Previous page link
        if ($currentPage > 1) {
            echo "<a href='customers.php?page=" . ($currentPage - 1) . "'>Previous</a>";
        } else {
            echo "<a href='#' class='disabled'>Previous</a>";
        }

        // Page numbers
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == $currentPage) {
                echo "<a href='#' class='active'>" . $i . "</a>";
            } else {
                echo "<a href='customers.php?page=" . $i . "'>" . $i . "</a>";
            }
        }

        // Next page link
        if ($currentPage < $totalPages) {
            echo "<a href='customers.php?page=" . ($currentPage + 1) . "'>Next</a>";
        } else {
            echo "<a href='#' class='disabled'>Next</a>";
        }
        ?>
    </div>

    <div id="edit_customer_form" style="display:none;">
        <h3>Edit Customer</h3>
        <form method="post">
            <input type="hidden" name="id" id="edit_customer_id">
            <input type="text" name="name" id="edit_name" placeholder="Customer Name" required><br>
            <input type="text" name="phone" id="edit_phone" placeholder="Phone" required><br>
            <input type="text" name="address" id="edit_address" placeholder="Address" required><br>
            <button type="submit" name="update_customer">Update Customer</button>
            <button type="button" onclick="document.getElementById('edit_customer_form').style.display='none';">Cancel</button>
        </form>
    </div>

    <script>
        function searchCustomers() {
            const input = document.getElementById('searchInput').value.toLowerCase();
            const table = document.getElementById('customerTable');
            const rows = table.getElementsByTagName('tr');

            for (let i = 1; i < rows.length; i++) { // Start from 1 to skip header
                const cells = rows[i].getElementsByTagName('td');
                let match = false;
                for (let j = 0; j < cells.length - 1; j++) { // Exclude action column
                    if (cells[j].textContent.toLowerCase().includes(input)) {
                        match = true;
                        break;
                    }
                }
                rows[i].style.display = match ? '' : 'none';
            }
        }

        function editCustomer(id, name, phone, address) {
            document.getElementById('edit_customer_form').style.display = 'block';
            document.getElementById('edit_customer_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_address').value = address;
        }
    </script>

<?php include '../includes/footer.php'; ?>
</body>
</html>