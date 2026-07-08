<?php
// ── Start session safely ─────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Check if user is logged in ───────────────────────────
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// ── Get current user role ────────────────────────────────
function getRole() {
    return $_SESSION['role'] ?? null;
}

// ── Redirect if NOT logged in ────────────────────────────
function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header("Location: " . BASE_URL . "login.php");
        exit();
    }
}

// ── Redirect to correct dashboard by role ────────────────
function redirectByRole() {
    $role = getRole();
    if ($role == 'admin') {
        header("Location: " . BASE_URL . "admin/dashboard.php");
        exit();
    } elseif ($role == 'lecturer') {
        header("Location: " . BASE_URL . "lecturer/dashboard.php");
        exit();
    } elseif ($role == 'student') {
        header("Location: " . BASE_URL . "student/dashboard.php");
        exit();
    } else {
        header("Location: " . BASE_URL . "login.php");
        exit();
    }
}
?>