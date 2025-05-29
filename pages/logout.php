<?php
session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Start a new session to ensure a fresh session ID
session_start();
session_regenerate_id(true);

// Redirect to login.php after a slight delay to ensure session is cleared
header("Refresh: 1; url=login.php");
echo "Logging out... You will be redirected to the login page shortly.";
exit();
?>