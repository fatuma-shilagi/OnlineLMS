<?php
// auth.php — include this in ALL subfolder pages
// It includes session.php itself using __DIR__

require_once __DIR__ . '/session.php';

// Redirect to login if not logged in
redirectIfNotLoggedIn();

// Block wrong roles
function requireRole($required_role) {
    if (getRole() !== $required_role) {
        header("Location: " . BASE_URL . "login.php");
        exit();
    }
}
?>