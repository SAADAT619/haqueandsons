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

// Handle seller creation and update
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_seller'])) {
        $name = sanitizeInput($_POST['name']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);

        $sql = "INSERT INTO sellers (name, phone, address) VALUES ('$name', '$phone', '$address')";
        if ($conn->query($sql) === TRUE) {
            $message = "Seller added successfully";
        } else {
            $error = "Error adding seller: " . $conn->error;
        }
    } elseif (isset($_POST['update_seller'])) {
        $id = sanitizeInput($_POST['id']);
        $name = sanitizeInput($_POST['name']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);
        $sql = "UPDATE sellers SET name='$name', phone='$phone', address='$address' WHERE id=$id";
        if ($conn->query($sql) === TRUE) {
            $message = "Seller updated successfully";
        } else {
            $error = "Error updating seller: " . $conn->error;
        }
    }
}

// Fetch sellers for display, ordered by created_at DESC to show newest first
$sellerSql = "SELECT * FROM sellers ORDER BY created_at DESC";
$sellerResult = $conn->query($sellerSql);
?>

<h2>Manage Sellers</h2>

<?php if (isset($message)) {
    echo "<p class='success'>$message</p>";
} ?>
<?php if (isset($error)) {
    echo "<p class='error'>$error</p>";
} ?>

<h3>Add Seller</h3>
<form method="post">
    <input type="text" name="name" placeholder="Seller Name" required><br>
    <input type="text" name="phone" placeholder="Phone" required><br>
    <textarea name="address" placeholder="Address" required></textarea><br>
    <button type="submit" name="add_seller">Add Seller</button>
</form>

<h3>Seller List</h3>
<table>
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
        if ($sellerResult->num_rows > 0) {
            while ($sellerRow = $sellerResult->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($sellerRow['name']) . "</td>";
                echo "<td>" . htmlspecialchars($sellerRow['phone']) . "</td>";
                echo "<td>" . htmlspecialchars($sellerRow['address']) . "</td>";
                echo "<td>";
                echo "<button onclick=\"editSeller(" . $sellerRow['id'] . ", '" . htmlspecialchars($sellerRow['name']) . "', '" . htmlspecialchars($sellerRow['phone']) . "', '" . htmlspecialchars($sellerRow['address']) . "')\">Edit</button>";
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='4'>No sellers found</td></tr>";
        }
        ?>
    </tbody>
</table>

<div id="edit_seller_form" style="display:none;">
    <h3>Edit Seller</h3>
    <form method="post">
        <input type="hidden" name="id" id="edit_id">
        <input type="text" name="name" id="edit_name" placeholder="Seller Name" required><br>
        <input type="text" name="phone" id="edit_phone" placeholder="Phone" required><br>
        <textarea name="address" id="edit_address" placeholder="Address" required></textarea><br>
        <button type="submit" name="update_seller">Update Seller</button>
        <button type="button" onclick="document.getElementById('edit_seller_form').style.display='none';">Cancel</button>
    </form>
</div>

<script>
    function editSeller(id, name, phone, address) {
        document.getElementById('edit_seller_form').style.display = 'block';
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_phone').value = phone;
        document.getElementById('edit_address').value = address;
    }
</script>

<style>
/* Form styling */
form {
    background-color: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
}

input[type="text"], textarea {
    width: 100%;
    padding: 10px;
    margin: 5px 0;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

textarea {
    height: 100px;
    resize: vertical;
}

button {
    background-color: #4CAF50;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin-right: 10px;
}

button:hover {
    background-color: #45a049;
}

/* Table styling */
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

/* Success and error messages */
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
</style>

<?php include '../includes/footer.php'; ?>