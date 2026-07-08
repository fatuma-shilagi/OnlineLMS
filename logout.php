<?php
require_once 'includes/config.php';
require_once 'includes/session.php';

// Destroy session completely
session_unset();
session_destroy();

// Delete the session cookie from browser
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Go back to landing page
header("Location: " . BASE_URL . "index.php");
exit();
?>