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

// Handle deposit form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_deposit'])) {
    $name = sanitizeInput($_POST['name']);
    $amount = sanitizeInput($_POST['amount']);
    $transaction_date = sanitizeInput($_POST['transaction_date']);

    $errors = [];
    if (empty($name)) {
        $errors[] = "Deposit name is required.";
    }
    if (empty($amount) || !is_numeric($amount) || $amount < 0) {
        $errors[] = "Valid amount is required.";
    }
    if (empty($transaction_date)) {
        $errors[] = "Deposit date is required.";
    }

    if (empty($errors)) {
        $sql = "INSERT INTO expenses (name, amount, type, transaction_date) VALUES (?, ?, 'Deposit', ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sds", $name, $amount, $transaction_date);
            if ($stmt->execute()) {
                $message = "Deposit added successfully";
            } else {
                $error = "Error adding deposit: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing statement: " . $conn->error;
        }
    } else {
        $error = "Validation errors: " . implode("; ", $errors);
    }
}

// Handle expense form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_expense'])) {
    $name = sanitizeInput($_POST['name']);
    $amount = sanitizeInput($_POST['amount']);
    $transaction_date = sanitizeInput($_POST['transaction_date']);

    $errors = [];
    if (empty($name)) {
        $errors[] = "Expense name is required.";
    }
    if (empty($amount) || !is_numeric($amount) || $amount < 0) {
        $errors[] = "Valid amount is required.";
    }
    if (empty($transaction_date)) {
        $errors[] = "Expense date is required.";
    }

    if (empty($errors)) {
        $sql = "INSERT INTO expenses (name, amount, type, transaction_date) VALUES (?, ?, 'Expense', ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sds", $name, $amount, $transaction_date);
            if ($stmt->execute()) {
                $message = "Expense added successfully";
            } else {
                $error = "Error adding expense: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing statement: " . $conn->error;
        }
    } else {
        $error = "Validation errors: " . implode("; ", $errors);
    }
}

// Handle date range filter
$dateCondition = "";
$dateFilterParams = [];
$fromDate = isset($_GET['from_date']) ? sanitizeInput($_GET['from_date']) : '';
$toDate = isset($_GET['to_date']) ? sanitizeInput($_GET['to_date']) : '';
$today = date('Y-m-d'); // 2025-06-06

if ($fromDate && $toDate) {
    $dateCondition = "transaction_date BETWEEN ? AND ?";
    $dateFilterParams = [$fromDate, $toDate];
} elseif ($fromDate) {
    $dateCondition = "transaction_date >= ?";
    $dateFilterParams = [$fromDate];
} elseif ($toDate) {
    $dateCondition = "transaction_date <= ?";
    $dateFilterParams = [$toDate];
} else {
    // Default to today
    $dateCondition = "transaction_date = ?";
    $dateFilterParams = [$today];
    $fromDate = $today;
    $toDate = $today;
}

// Fetch transactions
$transactionsSql = "SELECT id, name, amount, type, transaction_date 
                    FROM expenses 
                    WHERE $dateCondition 
                    ORDER BY transaction_date DESC, type ASC";
$stmt = $conn->prepare($transactionsSql);
if ($stmt === false) {
    $error = "Error preparing transactions query: " . $conn->error;
    $transactionsResult = false;
} else {
    if (!empty($dateFilterParams)) {
        $types = str_repeat('s', count($dateFilterParams));
        $stmt->bind_param($types, ...$dateFilterParams);
    }
    if ($stmt->execute()) {
        $transactionsResult = $stmt->get_result();
    } else {
        $error = "Error executing transactions query: " . $stmt->error;
        $transactionsResult = false;
    }
    $stmt->close();
}

// Fetch totals for the date range
$totalDepositSql = "SELECT COALESCE(SUM(amount), 0) as total 
                    FROM expenses 
                    WHERE type = 'Deposit' AND $dateCondition";
$totalDepositStmt = $conn->prepare($totalDepositSql);
if ($totalDepositStmt === false) {
    $error = "Error preparing total deposits query: " . $conn->error;
    $totalDeposit = 0.00;
} else {
    if (!empty($dateFilterParams)) {
        $types = str_repeat('s', count($dateFilterParams));
        $totalDepositStmt->bind_param($types, ...$dateFilterParams);
    }
    if ($totalDepositStmt->execute()) {
        $totalDepositResult = $totalDepositStmt->get_result();
        $totalDepositRow = $totalDepositResult->fetch_assoc();
        $totalDeposit = $totalDepositRow['total'] ?? 0.00;
    } else {
        $error = "Error executing total deposits query: " . $totalDepositStmt->error;
        $totalDeposit = 0.00;
    }
    $totalDepositStmt->close();
}

$totalExpenseSql = "SELECT COALESCE(SUM(amount), 0) as total 
                    FROM expenses 
                    WHERE type = 'Expense' AND $dateCondition";
$totalExpenseStmt = $conn->prepare($totalExpenseSql);
if ($totalExpenseStmt === false) {
    $error = "Error preparing total expenses query: " . $conn->error;
    $totalExpenses = 0.00;
} else {
    if (!empty($dateFilterParams)) {
        $types = str_repeat('s', count($dateFilterParams));
        $totalExpenseStmt->bind_param($types, ...$dateFilterParams);
    }
    if ($totalExpenseStmt->execute()) {
        $totalExpenseResult = $totalExpenseStmt->get_result();
        $totalExpenseRow = $totalExpenseResult->fetch_assoc();
        $totalExpenses = $totalExpenseRow['total'] ?? 0.00;
    } else {
        $error = "Error executing total expenses query: " . $totalExpenseStmt->error;
        $totalExpenses = 0.00;
    }
    $totalExpenseStmt->close();
}

// Calculate Net Income
$netIncome = $totalDeposit - $totalExpenses;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit & Expense</title>
    <!-- Include SheetJS for Excel export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- Include jsPDF and jsPDF-AutoTable for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Calibri', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
            padding: 0;
        }
        .sidebar {
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            background-color: #2c3e50;
            overflow-y: auto;
        }
        .content {
            margin-left: 100px; /* Match sidebar width */
            padding: 0px 0 0px 0; /* No left padding */
            width: width: 100%; /* Adjust width to exclude sidebar */
            background-color: #f9f9f9;
            flex: 1;
            display: block;
        }
        .content-inner {
            width: 100%; /* Full width of the content area */
            margin: 0; /* No margins */
            padding: 0; /* No padding */
        }
        .content-inner > * {
            margin-left: 0 !important; /* Override any inherited or default margins for all direct children */
        }
        h2 {
            color: #2e7d32;
            font-size: 24px;
            margin-bottom: 20px;
            width: 100%; /* Ensure full width */
        }
        h3 {
            color: #333;
            font-size: 18px;
            margin-bottom: 10px;
            width: 100%; /* Ensure full width */
        }
        /* Totals Styling */
        .totals-container {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            justify-content: flex-start;
            width: 100%; /* Ensure full width */
        }
        .total-box {
            background-color: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            flex: 1;
            min-width: 200px;
            text-align: center;
        }
        .total-box h4 {
            margin: 0 0 10px;
            color: #333;
            font-size: 16px;
        }
        .total-box p {
            margin: 0;
            font-size: 1.2em;
            color: #333;
        }
        .total-box.deposit h4 { color: #4CAF50; }
        .total-box.expense h4 { color: #d32f2f; }
        .total-box.net-income h4 { color: #1976D2; }
        /* Form Styling */
        .transaction-form {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
            width: 100%; /* Full width */
            justify-content: flex-start;
        }
        .transaction-form div {
            flex: 1;
            min-width: 0; /* Remove min-width constraint */
            max-width: none; /* Remove max-width constraint */
        }
        .transaction-form label {
            display: block;
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }
        .transaction-form input[type="text"],
        .transaction-form input[type="number"],
        .transaction-form input[type="date"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Calibri', Arial, sans-serif;
            transition: border-color 0.2s;
        }
        .transaction-form input:focus {
            border-color: #4CAF50;
            outline: none;
        }
        .transaction-form button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-family: 'Calibri', Arial, sans-serif;
            transition: background-color 0.2s;
        }
        .transaction-form button:hover {
            background-color: #45a049;
        }
        /* Date Filter Styling */
        .date-filter {
            background-color: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
            width: 100%; /* Full width */
            justify-content: flex-start;
        }
        .date-filter div {
            flex: 1;
            min-width: 0; /* Remove min-width constraint */
            max-width: none; /* Remove max-width constraint */
        }
        .date-filter label {
            display: block;
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }
        .date-filter input[type="date"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        .date-filter button {
            background-color: #1976D2;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .date-filter button:hover {
            background-color: #1565C0;
        }
        /* Messages Styling */
        .success {
            color: green;
            padding: 10px;
            background-color: #e0f7e0;
            border-radius: 4px;
            margin-bottom: 20px;
            width: 100%;
        }
        .error {
            color: red;
            padding: 10px;
            background-color: #ffe0e0;
            border-radius: 4px;
            margin-bottom: 20px;
            width: 100%;
        }
        /* Table Styling */
        .table-container {
            overflow-x: auto;
            width: 100%; /* Full width */
            margin: 0; /* No margins */
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            border: 1px solid #b0b0b0;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        th, td {
            padding: 12px 15px;
            border: 1px solid #b0b0b0;
            text-align: left;
            font-size: 14px;
            white-space: nowrap;
        }
        th {
            background-color: #d3d3d3;
            color: #333;
            font-weight: bold;
            text-align: center;
        }
        td {
            background-color: #fff;
        }
        tr:nth-child(even) td {
            background-color: #f0f0f0;
        }
        tr:hover td {
            background-color: #e6f0fa;
        }
        /* Export Buttons Styling */
        .export-buttons {
            margin: 20px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-start;
            width: 100%; /* Full width */
        }
        .export-buttons button {
            background-color: #1976D2;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: 'Calibri', Arial, sans-serif;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        .export-buttons button:hover {
            background-color: #1565C0;
        }
        /* Footer Styling */
        footer {
            width: 100%;
            background-color: #f4f4f4;
            padding: 15px;
            text-align: center;
            font-size: 14px;
            color: #666;
            border-top: 1px solid #ddd;
        }
        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .content {
                margin-left: 0;
                width: 100%;
                padding: 20px; /* Restore padding for mobile view */
            }
            .content-inner {
                margin: 0;
                padding: 0;
            }
            .content-inner > * {
                margin-left: 0 !important; /* Ensure no left margin in mobile view */
            }
            .transaction-form div,
            .date-filter div {
                min-width: 100%;
                max-width: none;
            }
            .export-buttons {
                flex-direction: column;
                gap: 10px;
            }
            .export-buttons button {
                width: 100%;
            }
            .totals-container {
                flex-direction: column;
            }
            .total-box {
                max-width: none;
            }
        }
        /* Print Styling */
        @media print {
            .sidebar, .transaction-form, .date-filter, .export-buttons, footer {
                display: none;
            }
            .content {
                margin-left: 0;
                width: 100%;
                padding: 0;
            }
            .content-inner {
                margin: 0;
            }
            .totals-container {
                margin-bottom: 10px;
            }
            table {
                border: 1px solid #000;
            }
            th, td {
                border: 1px solid #000;
            }
            th {
                background-color: #d3d3d3;
            }
            tr:nth-child(even) td {
                background-color: #f0f0f0;
            }
        }
    </style>
</head>
<body>
    <div class="content">
        <div class="content-inner">
            <h2>Deposit & Expense</h2>

            <!-- Add Deposit Form -->
            <h3>Add Deposit</h3>
            <div class="transaction-form">
                <div>
                    <label for="deposit_name">Deposit Name</label>
                    <input type="text" name="name" id="deposit_name" placeholder="Enter deposit name" form="addDepositForm" required>
                </div>
                <div>
                    <label for="deposit_amount">Amount</label>
                    <input type="number" name="amount" id="deposit_amount" placeholder="Enter amount" step="0.01" min="0" form="addDepositForm" required>
                </div>
                <div>
                    <label for="deposit_date">Date</label>
                    <input type="date" name="transaction_date" id="deposit_date" value="2025-06-06" form="addDepositForm" required>
                </div>
                <div>
                    <button type="submit" form="addDepositForm">Add Deposit</button>
                </div>
            </div>
            <form id="addDepositForm" method="post" style="display: none;">
                <input type="hidden" name="add_deposit" value="1">
            </form>

            <!-- Add Expense Form -->
            <h3>Add Expense</h3>
            <div class="transaction-form">
                <div>
                    <label for="expense_name">Expense Name</label>
                    <input type="text" name="name" id="expense_name" placeholder="Enter expense name" form="addExpenseForm" required>
                </div>
                <div>
                    <label for="expense_amount">Amount</label>
                    <input type="number" name="amount" id="expense_amount" placeholder="Enter amount" step="0.01" min="0" form="addExpenseForm" required>
                </div>
                <div>
                    <label for="expense_date">Date</label>
                    <input type="date" name="transaction_date" id="expense_date" value="2025-06-06" form="addExpenseForm" required>
                </div>
                <div>
                    <button type="submit" form="addExpenseForm">Add Expense</button>
                </div>
            </div>
            <form id="addExpenseForm" method="post" style="display: none;">
                <input type="hidden" name="add_expense" value="1">
            </form>

            <!-- Messages -->
            <?php if (isset($message)) {
                echo "<p class='success'>" . htmlspecialchars($message) . "</p>";
            } ?>
            <?php if (isset($error)) {
                echo "<p class='error'>" . htmlspecialchars($error) . "</p>";
            } ?>

            <!-- Date Range Filter -->
            <div class="date-filter">
                <div>
                    <label for="from_date">From Date</label>
                    <input type="date" id="from_date" name="from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div>
                    <label for="to_date">To Date</label>
                    <input type="date" id="to_date" name="to_date" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <div>
                    <button onclick="applyDateFilter()">Apply Filter</button>
                </div>
            </div>

            <!-- Totals -->
            <div class="totals-container">
                <div class="total-box deposit">
                    <h4>Total Deposits<?php echo ($fromDate || $toDate) ? " for Selected Range" : " for Today"; ?></h4>
                    <p><?php echo number_format($totalDeposit, 2); ?></p>
                </div>
                <div class="total-box expense">
                    <h4>Total Expenses<?php echo ($fromDate || $toDate) ? " for Selected Range" : " for Today"; ?></h4>
                    <p><?php echo number_format($totalExpenses, 2); ?></p>
                </div>
                <div class="total-box net-income">
                    <h4>Net Income<?php echo ($fromDate || $toDate) ? " for Selected Range" : " for Today"; ?></h4>
                    <p><?php echo number_format($netIncome, 2); ?></p>
                </div>
            </div>

            <!-- Export Buttons -->
            <div class="export-buttons">
                <button onclick="exportToExcel()">Export to Excel</button>
                <button onclick="exportToPDF()">Export to PDF</button>
                <button onclick="window.print()">Print</button>
            </div>

            <!-- Transactions Table -->
            <div class="table-container">
                <table id="transactionTable">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Date</th>
                            <th style="width: 300px;">Name</th>
                            <th style="width: 150px;">Type</th>
                            <th style="width: 150px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($transactionsResult && $transactionsResult->num_rows > 0) {
                            while ($row = $transactionsResult->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['transaction_date']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['type']) . "</td>";
                                echo "<td>" . number_format($row['amount'], 2) . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='4'>No transactions found</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <?php include '../includes/footer.php'; ?>
    </footer>

    <script>
        function applyDateFilter() {
            const fromDate = document.getElementById('from_date').value;
            const toDate = document.getElementById('to_date').value;
            const url = new URL(window.location.href);
            if (fromDate) url.searchParams.set('from_date', fromDate);
            else url.searchParams.delete('from_date');
            if (toDate) url.searchParams.set('to_date', toDate);
            else url.searchParams.delete('to_date');
            window.location.href = url.toString();
        }

        function exportToExcel() {
            const transactionTable = document.getElementById('transactionTable');
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.table_to_sheet(transactionTable);
            XLSX.utils.book_append_sheet(wb, ws, "Transactions");
            XLSX.writeFile(wb, 'Deposit_Expense_Report.xlsx');
        }

        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            
            // Add Title and Totals
            doc.text("Deposit & Expense Report", 20, 10);
            doc.text(`Total Deposits: ${<?php echo json_encode(number_format($totalDeposit, 2)); ?>}`, 20, 20);
            doc.text(`Total Expenses: ${<?php echo json_encode(number_format($totalExpenses, 2)); ?>}`, 20, 30);
            doc.text(`Net Income: ${<?php echo json_encode(number_format($netIncome, 2)); ?>}`, 20, 40);
            
            // Export Transactions Table
            doc.autoTable({
                html: '#transactionTable',
                startY: 50,
                styles: {
                    lineColor: [0, 0, 0],
                    lineWidth: 0.1,
                    fillColor: [255, 255, 255],
                    textColor: [0, 0, 0],
                    font: "helvetica"
                },
                headStyles: {
                    fillColor: [211, 211, 211],
                    textColor: [0, 0, 0]
                },
                alternateRowStyles: {
                    fillColor: [240, 240, 240]
                }
            });

            doc.save('Deposit_Expense_Report.pdf');
        }
    </script>
</body>
</html>