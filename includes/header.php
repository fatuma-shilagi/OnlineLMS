<?php
require_once __DIR__ . '/auth.php';
$role = getRole();
?>
<!DOCTYPE html>
<html>
<head>
    <title>LMS System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= BASE_URL ?>">LMS</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav ms-auto">
                <?php if ($role == 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>admin/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>admin/manage_users.php">Users</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>admin/manage_courses.php">Courses</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>admin/reports.php">Reports</a></li>

                <?php elseif ($role == 'lecturer'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>lecturer/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>lecturer/upload_notes.php">Upload Notes</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>lecturer/create_assignment.php">Assignments</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>lecturer/notifications.php">Notifications</a></li>

                <?php elseif ($role == 'student'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>student/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>student/notes.php">Notes</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>student/assignments.php">Assignments</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>student/notifications.php">Notifications</a></li>
                <?php endif; ?>

                <li class="nav-item"><a class="nav-link text-danger" href="<?= BASE_URL ?>logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>