<?php
$host = 'localhost';
$username = 'root';
$password = ''; // Replace with your DB password
$database = 'cement_shop';

// Define log path
$log_dir = __DIR__ . '/../logs';
$log_file = $log_dir . '/database.log';

// Create logs directory if it doesn't exist
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

try {
    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        file_put_contents($log_file, "Connection failed: " . $conn->connect_error . "\n", FILE_APPEND);
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    file_put_contents($log_file, "Database connected successfully: $host, $database\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($log_file, "Database connection error: " . $e->getMessage() . "\n", FILE_APPEND);
    $conn = null;
}
?>