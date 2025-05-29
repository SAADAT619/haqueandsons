<!DOCTYPE html>
<html>
<head>
    <title>Invoice #<?php echo $invoice_number; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f9;
            color: #333;
            line-height: 1.6;
        }
        .invoice-box {
            max-width: 900px;
            margin: 40px auto;
            padding: 30px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }
        .header img.logo {
            max-width: 120px;
            margin-bottom: 10px;
        }
        .header h1 {
            font-size: 28px;
            color: #007bff;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 14px;
            color: #666;
        }
        .details {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #eee;
        }
        .details p {
            font-size: 16px;
            margin: 8px 0;
        }
        .details p strong {
            color: #007bff;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #007bff;
            color: #fff;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 14px;
        }
        td {
            font-size: 15px;
            color: #333;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .totals {
            margin-top: 20px;
            text-align: right;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #eee;
        }
        .totals p {
            font-size: 16px;
            margin: 8px 0;
        }
        .totals p strong {
            color: #007bff;
            font-weight: 600;
        }
        .totals p:last-child strong {
            color: #e74c3c; /* Red for due amount */
        }
        @media print {
            body {
                background-color: #fff;
            }
            .invoice-box {
                box-shadow: none;
                border: none;
                margin: 0;
            }
        }
        @media (max-width: 600px) {
            .invoice-box {
                padding: 20px;
            }
            .header h1 {
                font-size: 24px;
            }
            th, td {
                font-size: 12px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-box">
        <div class="header">
            <!-- Replace with your actual logo path -->
            <img src="https://via.placeholder.com/120x60?text=Logo" alt="Company Logo" class="logo">
            <h1><?php echo getShopSetting('shop_name', $conn) ?: 'Cement Shop'; ?></h1>
            <p><?php echo getShopSetting('shop_address', $conn) ?: '123 Cement Road, City'; ?></p>
            <p>Invoice #<?php echo $invoice_number; ?></p>
        </div>
        <div class="details">
            <p><strong>Seller:</strong> <?php echo $purchase['seller_name']; ?></p>
            <p><strong>Address:</strong> <?php echo $purchase['seller_address']; ?></p>
            <p><strong>Date:</strong> <?php echo $purchase['purchase_date']; ?></p>
            <p><strong>Payment Method:</strong> <?php echo $purchase['payment_method']; ?></p>
        </div>
        <table>
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
                <tr>
                    <td><?php echo $purchase['product_name']; ?></td>
                    <td><?php echo $purchase['quantity']; ?></td>
                    <td><?php echo $purchase['unit']; ?></td>
                    <td><?php echo number_format($purchase['price'], 2); ?></td>
                    <td><?php echo number_format($purchase['total'], 2); ?></td>
                </tr>
            </tbody>
        </table>
        <div class="totals">
            <p><strong>Total:</strong> <?php echo number_format($purchase['total'], 2); ?></p>
            <p><strong>Paid:</strong> <?php echo number_format($purchase['paid'], 2); ?></p>
            <p><strong>Due:</strong> <?php echo number_format($purchase['due'], 2); ?></p>
        </div>
    </div>
</body>
</html>