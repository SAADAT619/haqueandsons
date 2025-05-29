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

// Handle expense form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_expense'])) {
    $expense_name = sanitizeInput($_POST['expense_name']);
    $amount = sanitizeInput($_POST['amount']);
    $expense_date = sanitizeInput($_POST['expense_date']);

    // Validate inputs
    $errors = [];
    if (empty($expense_name)) {
        $errors[] = "Expense name is required.";
    }
    if (empty($amount) || !is_numeric($amount) || $amount < 0) {
        $errors[] = "Valid amount is required.";
    }
    if (empty($expense_date)) {
        $errors[] = "Expense date is required.";
    }

    if (empty($errors)) {
        $sql = "INSERT INTO expenses (expense_name, amount, expense_date) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sds", $expense_name, $amount, $expense_date);
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
$whereClause = "";
$dateFilterParams = [];
$fromDate = isset($_GET['from_date']) ? sanitizeInput($_GET['from_date']) : '';
$toDate = isset($_GET['to_date']) ? sanitizeInput($_GET['to_date']) : '';

if ($fromDate && $toDate) {
    $whereClause = "WHERE expense_date BETWEEN ? AND ?";
    $dateFilterParams = [$fromDate, $toDate];
} elseif ($fromDate) {
    $whereClause = "WHERE expense_date >= ?";
    $dateFilterParams = [$fromDate];
} elseif ($toDate) {
    $whereClause = "WHERE expense_date <= ?";
    $dateFilterParams = [$toDate];
}

// Fetch expenses for listing
$expensesSql = "SELECT * FROM expenses $whereClause ORDER BY expense_date DESC";
$stmt = $conn->prepare($expensesSql);
if (!empty($dateFilterParams)) {
    $types = str_repeat('s', count($dateFilterParams));
    $stmt->bind_param($types, ...$dateFilterParams);
}
$stmt->execute();
$expensesResult = $stmt->get_result();

// Fetch total expenses for the date range
$totalSql = "SELECT SUM(amount) as total_amount FROM expenses $whereClause";
$totalStmt = $conn->prepare($totalSql);
if (!empty($dateFilterParams)) {
    $totalStmt->bind_param($types, ...$dateFilterParams);
}
$totalStmt->execute();
$totalResult = $totalStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalExpenses = $totalRow['total_amount'] ?? 0.00;
$totalStmt->close();

// Fetch per day expenses
$dailySql = "SELECT expense_date, SUM(amount) as daily_total FROM expenses $whereClause GROUP BY expense_date ORDER BY expense_date DESC";
$dailyStmt = $conn->prepare($dailySql);
if (!empty($dateFilterParams)) {
    $dailyStmt->bind_param($types, ...$dateFilterParams);
}
$dailyStmt->execute();
$dailyResult = $dailyStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses</title>
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
            margin: 0; /* Reset default margins */
            padding: 0; /* Reset default padding */
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
            padding: 20px;
            width: calc(100% - 100px); /* Use remaining space */
            background-color: #f9f9f9;
            flex: 1;
            display: block; /* Ensure it behaves as a block element */
        }
        .content-inner {
            max-width: 100%; /* Use full width of .content */
            margin-left: 0; /* Start immediately after margin-left of .content */
            padding: 0; /* Remove any extra padding causing shift */
        }
        h2 {
            color: #2e7d32;
            font-size: 24px;
            margin-bottom: 20px;
        }
        /* Total Expenses Styling */
        .total-expenses {
            background-color: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        /* Form Styling */
        .expense-form {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
            width: 100%;
        }
        .expense-form div {
            flex: 1;
            min-width: 200px;
        }
        .expense-form label {
            display: block;
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }
        .expense-form input[type="text"],
        .expense-form input[type="number"],
        .expense-form input[type="date"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Calibri', Arial, sans-serif;
            transition: border-color 0.2s;
        }
        .expense-form input:focus {
            border-color: #4CAF50;
            outline: none;
        }
        .expense-form button {
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
        .expense-form button:hover {
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
            width: 100%;
        }
        .date-filter div {
            flex: 1;
            min-width: 150px;
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
        }
        .error {
            color: red;
            padding: 10px;
            background-color: #ffe0e0;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        /* Table Styling */
        .table-container {
            overflow-x: auto;
            width: 100%;
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
        /* Daily Totals Table Styling */
        .daily-totals-container {
            overflow-x: auto;
            width: 100%;
        }
        .daily-totals-table {
            width: 50%;
            border-collapse: collapse;
            margin: 20px 0;
            border: 1px solid #b0b0b0;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .daily-totals-table th,
        .daily-totals-table td {
            padding: 12px 15px;
            border: 1px solid #b0b0b0;
            text-align: left;
            font-size: 14px;
            white-space: nowrap;
        }
        .daily-totals-table th {
            background-color: #d3d3d3;
            color: #333;
            font-weight: bold;
            text-align: center;
        }
        .daily-totals-table td {
            background-color: #fff;
        }
        .daily-totals-table tr:nth-child(even) td {
            background-color: #f0f0f0;
        }
        .daily-totals-table tr:hover td {
            background-color: #e6f0fa;
        }
        /* Export Buttons Styling */
        .export-buttons {
            margin: 20px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-start;
            width: 100%;
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
            }
            .content-inner {
                margin-left: 0;
                padding: 0 10px;
            }
            .expense-form div,
            .date-filter div {
                min-width: 100%;
            }
            .export-buttons {
                flex-direction: column;
                gap: 10px;
            }
            .export-buttons button {
                width: 100%;
            }
            .daily-totals-table {
                width: 100%;
            }
        }
        /* Print Styling */
        @media print {
            .sidebar, .expense-form, .date-filter, .export-buttons, footer {
                display: none;
            }
            .content {
                margin-left: 0;
                width: 100%;
                padding: 0;
            }
            .content-inner {
                margin-left: 0;
            }
            .total-expenses {
                margin-bottom: 10px;
            }
            table, .daily-totals-table {
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
            <h2>Expenses</h2>

            <!-- Add Expense Form -->
            <div class="expense-form">
                <div>
                    <label for="expense_name">Expense Name</label>
                    <input type="text" name="expense_name" id="expense_name" placeholder="Enter expense name" form="addExpenseForm" required>
                </div>
                <div>
                    <label for="amount">Amount</label>
                    <input type="number" name="amount" id="amount" placeholder="Enter amount" step="0.01" min="0" form="addExpenseForm" required>
                </div>
                <div>
                    <label for="expense_date">Date</label>
                    <input type="date" name="expense_date" id="expense_date" value="2025-05-28" form="addExpenseForm" required>
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

            <!-- Total Expenses for Date Range -->
            <div class="total-expenses">
                Total Expenses<?php echo ($fromDate || $toDate) ? " for Selected Range" : ""; ?>: <?php echo number_format($totalExpenses, 2); ?>
            </div>

            <!-- Export Buttons -->
            <div class="export-buttons">
                <button onclick="exportToExcel()">Export to Excel</button>
                <button onclick="exportToPDF()">Export to PDF</button>
                <button onclick="window.print()">Print</button>
            </div>

            <!-- Expenses Table -->
            <div class="table-container">
                <table id="expenseTable">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Date</th>
                            <th style="width: 300px;">Expense Name</th>
                            <th style="width: 150px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($expensesResult && $expensesResult->num_rows > 0) {
                            while ($expenseRow = $expensesResult->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($expenseRow['expense_date']) . "</td>";
                                echo "<td>" . htmlspecialchars($expenseRow['expense_name']) . "</td>";
                                echo "<td>" . number_format($expenseRow['amount'], 2) . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='3'>No expenses found</td></tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Daily Totals Table -->
            <h2>Daily Expense Totals</h2>
            <div class="daily-totals-container">
                <table class="daily-totals-table" id="dailyTotalsTable">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Date</th>
                            <th style="width: 150px;">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($dailyResult && $dailyResult->num_rows > 0) {
                            while ($dailyRow = $dailyResult->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($dailyRow['expense_date']) . "</td>";
                                echo "<td>" . number_format($dailyRow['daily_total'], 2) . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='2'>No daily totals available</td></tr>";
                        }
                        $dailyStmt->close();
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
            const expenseTable = document.getElementById('expenseTable');
            const dailyTotalsTable = document.getElementById('dailyTotalsTable');
            const wb = XLSX.utils.book_new();
            
            // Export Expenses Table
            const expenseWs = XLSX.utils.table_to_sheet(expenseTable);
            XLSX.utils.book_append_sheet(wb, expenseWs, "Expenses");
            
            // Export Daily Totals Table
            const dailyWs = XLSX.utils.table_to_sheet(dailyTotalsTable);
            XLSX.utils.book_append_sheet(wb, dailyWs, "Daily Totals");
            
            XLSX.writeFile(wb, 'Expenses_Report.xlsx');
        }

        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            
            // Add Total Expenses
            doc.text("Expenses Report", 20, 10);
            doc.text(`Total Expenses: ${<?php echo json_encode(number_format($totalExpenses, 2)); ?>}`, 20, 20);
            
            // Export Expenses Table
            doc.autoTable({
                html: '#expenseTable',
                startY: 30,
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

            // Add Daily Totals Table
            let finalY = doc.lastAutoTable.finalY + 10;
            doc.text("Daily Expense Totals", 20, finalY);
            doc.autoTable({
                html: '#dailyTotalsTable',
                startY: finalY + 10,
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

            doc.save('Expenses_Report.pdf');
        }
    </script>
</body>
</html>