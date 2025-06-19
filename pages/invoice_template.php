<?php
// Validate required variables
if (!isset($purchase)) {
    die("Error: Purchase data is missing.");
}
if (!isset($items)) {
    die("Error: Items data is missing.");
}
if (!isset($settings)) {
    die("Error: Settings data is missing.");
}

// Initialize shop details with defaults
$shopDetails = array_merge([
    'shop_name' => 'Demo Cement Shop',
    'address' => '123 Demo Street, Sample City, SC 12345',
    'phone' => 'N/A',
    'email' => 'N/A'
], $settings);

$invoiceDate = $purchase['purchase_date'] ?? date('Y-m-d');
?>

<!DOCTYPE html>
<html>
<head>
    <title>Invoice - <?php echo htmlspecialchars($purchase['invoice_number'] ?? 'Unknown'); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .invoice {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 20px;
            box-sizing: border-box;
        }
        .invoice-header {
            background: #4CAF50;
            color: white;
            padding: 10px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .invoice-header h1 {
            margin: 0;
            font-size: 24px;
        }
        .invoice-header h2 {
            margin: 5px 0;
            font-size: 20px;
        }
        .invoice-header p {
            margin: 2px 0;
            font-size: 14px;
        }
        .invoice-details {
            padding: 20px;
            background: #f9f9f9;
        }
        .invoice-details p {
            margin: 5px 0;
            font-size: 14px;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .invoice-table th, .invoice-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .invoice-table th {
            background: #f2f2f2;
        }
        .total-row {
            font-weight: bold;
            background: #e0e0e0;
        }
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            padding-top: 5px;
            font-style: italic;
            font-size: 12px;
            text-align: center;
        }
        .buttons {
            text-align: center;
            margin-top: 20px;
        }
        .buttons button {
            padding: 10px 20px;
            margin: 0 10px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .buttons button:hover {
            background-color: #45a049;
        }
        @media print {
            body { margin: 0; padding: 0; }
            .invoice { width: 210mm; height: 297mm; overflow: hidden; border: none; }
            .invoice-header { background: #4CAF50; color: white; }
            .invoice-table { page-break-inside: avoid; }
            .buttons { display: none; }
            .signature-section { position: absolute; bottom: 20px; width: 100%; margin-top: 0; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="invoice-header">
            <h1><?php echo htmlspecialchars($shopDetails['shop_name']); ?></h1>
            <p><?php echo htmlspecialchars($shopDetails['address']); ?></p>
            <p>Phone: <?php echo htmlspecialchars($shopDetails['phone']); ?></p>
            <p>Email: <?php echo htmlspecialchars($shopDetails['email']); ?></p>
            <h2>Buy Invoice</h2>
            <p>Invoice No: <?php echo htmlspecialchars($purchase['invoice_number'] ?? 'N/A'); ?></p>
            <p>Date: <?php echo htmlspecialchars($invoiceDate); ?></p>
        </div>

        <div class="invoice-details">
            <p><strong>Seller Name:</strong> <?php echo htmlspecialchars($purchase['seller_name'] ?? 'N/A'); ?></p>
            <p><strong>Seller Address:</strong> <?php echo htmlspecialchars($purchase['seller_address'] ?? 'N/A'); ?></p>
            <p><strong>Seller Phone:</strong> <?php echo htmlspecialchars($purchase['seller_phone'] ?? 'N/A'); ?></p>
            <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($purchase['payment_method'] ?? 'N/A'); ?></p>
        </div>

        <table class="invoice-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Unit</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5">No items found for this invoice.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown Product'); ?></td>
                            <td><?php echo htmlspecialchars($item['quantity'] ?? '0'); ?></td>
                            <td><?php echo htmlspecialchars($item['unit'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($item['price'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($item['total'] ?? 0, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="4"><strong>Grand Total</strong></td>
                    <td><?php echo number_format($purchase['total'] ?? 0, 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="4"><strong>Paid Amount</strong></td>
                    <td><?php echo number_format($purchase['paid'] ?? 0, 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="4"><strong>Due Amount</strong></td>
                    <td><?php echo number_format($purchase['due'] ?? 0, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="signature-section">
            <div class="signature-line">Buyer Signature</div>
            <div class="signature-line">Shop Representative Signature</div>
        </div>

        <div class="buttons">
            <button onclick="printInvoice()">Print</button>
        </div>
    </div>

    <script>
        function printInvoice() {
            window.print();
        }
    </script>
</body>
</html>