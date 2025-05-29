<div class="sidebar">
    <ul>
        <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <li><a href="category_products.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'category_products.php' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i> Category & Products</a></li>
        <li><a href="manage_sellers.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_sellers.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Manage Sellers</a></li>
        <li><a href="buy.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'buy.php' ? 'active' : ''; ?>"><i class="fas fa-shopping-cart"></i> Buy</a></li>
        <li><a href="sell.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'sell.php' ? 'active' : ''; ?>"><i class="fas fa-cash-register"></i> Sell</a></li>
        <li><a href="customers.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
        <li><a href="expenses.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'expenses.php' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> Expenses</a></li>
        <li><a href="logout.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'logout.php' ? 'active' : ''; ?>"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
</div>
<div class="content">