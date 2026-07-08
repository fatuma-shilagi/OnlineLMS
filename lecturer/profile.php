<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('lecturer');

$lecturer_id   = $_SESSION['user_id'];
$lecturer_name = $_SESSION['user_name'];

$success_msg = '';
$error_msg   = '';

// ════════════════════════════════════════════════════════
// HANDLE: Update Profile (info + avatar)
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $bio        = trim($_POST['bio'] ?? '');

    if ($name === '' || $email === '') {
        $error_msg = 'Name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid email address.';
    } else {

        // Make sure no other account already uses this email
        $check_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ?");
        mysqli_stmt_bind_param($check_stmt, "si", $email, $lecturer_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);

        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error_msg = 'That email address is already in use by another account.';
        } else {

            $new_filename = null;

            // Handle avatar upload (optional)
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $tmp_path    = $_FILES['profile_picture']['tmp_name'];
                $orig_name   = $_FILES['profile_picture']['name'];
                $file_size   = $_FILES['profile_picture']['size'];
                $ext         = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed_ext)) {
                    $error_msg = 'Profile picture must be a JPG, PNG, GIF, or WEBP image.';
                } elseif ($file_size > 3 * 1024 * 1024) {
                    $error_msg = 'Profile picture must be smaller than 3MB.';
                } else {
                    $upload_dir = '../uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $new_filename = 'lecturer_' . $lecturer_id . '_' . time() . '.' . $ext;
                    if (!move_uploaded_file($tmp_path, $upload_dir . $new_filename)) {
                        $error_msg     = 'Failed to upload profile picture. Please try again.';
                        $new_filename  = null;
                    }
                }
            }

            if ($error_msg === '') {
                if ($new_filename) {
                    $update_stmt = mysqli_prepare($conn,
                        "UPDATE users SET name=?, email=?, phone=?, department=?, bio=?, profile_picture=? WHERE id=?");
                    mysqli_stmt_bind_param($update_stmt, "ssssssi",
                        $name, $email, $phone, $department, $bio, $new_filename, $lecturer_id);
                } else {
                    $update_stmt = mysqli_prepare($conn,
                        "UPDATE users SET name=?, email=?, phone=?, department=?, bio=? WHERE id=?");
                    mysqli_stmt_bind_param($update_stmt, "sssssi",
                        $name, $email, $phone, $department, $bio, $lecturer_id);
                }

                if (mysqli_stmt_execute($update_stmt)) {
                    $_SESSION['user_name'] = $name;
                    $lecturer_name         = $name;
                    $success_msg           = 'Profile updated successfully.';
                } else {
                    $error_msg = 'Something went wrong while updating your profile. Please try again.';
                }
            }
        }
        mysqli_stmt_close($check_stmt);
    }
}

// ════════════════════════════════════════════════════════
// HANDLE: Change Password
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $current_password = $_POST['current_password'] ?? '';
    $new_password      = $_POST['new_password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';

    $pwd_stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
    mysqli_stmt_bind_param($pwd_stmt, "i", $lecturer_id);
    mysqli_stmt_execute($pwd_stmt);
    $pwd_row = mysqli_fetch_assoc(mysqli_stmt_get_result($pwd_stmt));

    if (!$pwd_row || !password_verify($current_password, $pwd_row['password'])) {
        $error_msg = 'Your current password is incorrect.';
    } elseif (strlen($new_password) < 6) {
        $error_msg = 'New password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error_msg = 'New password and confirmation do not match.';
    } else {
        $hashed       = password_hash($new_password, PASSWORD_DEFAULT);
        $update_pwd   = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
        mysqli_stmt_bind_param($update_pwd, "si", $hashed, $lecturer_id);

        if (mysqli_stmt_execute($update_pwd)) {
            $success_msg = 'Password changed successfully.';
        } else {
            $error_msg = 'Something went wrong while changing your password. Please try again.';
        }
    }
}

// ── Fetch lecturer info (fresh, after any updates) ──────
$fetch_stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($fetch_stmt, "i", $lecturer_id);
mysqli_stmt_execute($fetch_stmt);
$lecturer = mysqli_fetch_assoc(mysqli_stmt_get_result($fetch_stmt));

// ── Sidebar badge counts (kept identical to dashboard.php for nav consistency) ──
$courses_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM courses
                          WHERE lecturer_id = '$lecturer_id' AND status = 'active'")
)['total'];

$notes_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notes
                          WHERE uploaded_by = '$lecturer_id' AND status = 'active'")
)['total'];

$assignments_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM assignments
                          WHERE created_by = '$lecturer_id' AND status = 'active'")
)['total'];

$pending_grading = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM submissions s
                          INNER JOIN assignments a ON s.assignment_id = a.id
                          LEFT JOIN grades g ON s.id = g.submission_id
                          WHERE a.created_by = '$lecturer_id'
                          AND g.id IS NULL")
)['total'];

$notifications_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications n
                          LEFT JOIN notification_reads nr ON n.id = nr.notification_id
                              AND nr.user_id = '$lecturer_id'
                          WHERE (n.target_role = 'lecturer' OR n.target_role = 'all')
                          AND nr.id IS NULL")
)['total'];

$students_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(DISTINCT ce.student_id) as total
                          FROM course_enrollments ce
                          INNER JOIN courses c ON ce.course_id = c.id
                          WHERE c.lecturer_id = '$lecturer_id'
                          AND ce.status = 'enrolled'")
)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - OnlineLMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --bg-main:   #0f0f1a;
            --bg-card:   rgba(255,255,255,0.04);
            --bg-hover:  rgba(255,255,255,0.07);
            --border:    rgba(255,255,255,0.08);
            --text:      #e8e8f0;
            --muted:     rgba(255,255,255,0.45);
            --accent:    #ffd93d;
            --blue:      #00d4ff;
            --green:     #6bcb77;
            --red:       #ff6b6b;
            --purple:    #b48ffc;
            --sidebar-w: 265px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg-main);
            color: var(--text);
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        .sidebar {
            width: var(--sidebar-w);
            background: rgba(255,255,255,0.03);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 100;
            transition: transform 0.3s;
        }

        .sidebar-brand {
            padding: 22px 20px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-brand h5 {
            color: var(--accent);
            font-weight: 800;
            font-size: 1.15rem;
            margin: 0;
        }

        .sidebar-brand span {
            color: var(--muted);
            font-size: 0.72rem;
        }

        .sidebar-profile {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-profile img {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent);
        }

        .sidebar-profile .name {
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--text);
        }

        .role-badge {
            background: rgba(255,217,61,0.15);
            color: var(--accent);
            border: 1px solid rgba(255,217,61,0.3);
            border-radius: 20px;
            padding: 1px 10px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .sidebar-nav {
            flex: 1;
            padding: 12px 0;
            overflow-y: auto;
        }

        .nav-section {
            padding: 8px 20px 4px;
            font-size: 0.67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--muted);
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 20px;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            color: var(--text);
            background: var(--bg-hover);
            border-left-color: var(--accent);
        }

        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1rem; width: 20px; }

        .nav-badge {
            margin-left: auto;
            background: var(--red);
            color: white;
            border-radius: 20px;
            padding: 1px 8px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .nav-badge.yellow {
            background: rgba(255,217,61,0.2);
            color: var(--accent);
        }

        .sidebar-footer {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
        }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--red);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 8px 0;
            transition: opacity 0.2s;
        }

        .sidebar-footer a:hover { opacity: 0.75; }

        /* ── Main ── */
        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ── Topbar ── */
        .topbar {
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid var(--border);
            padding: 13px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(10px);
        }

        .topbar-left h6 {
            font-weight: 700;
            font-size: 1rem;
            margin: 0;
        }

        .topbar-left p {
            color: var(--muted);
            font-size: 0.78rem;
            margin: 0;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-btn {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 14px;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .topbar-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .topbar-btn.primary {
            background: rgba(255,217,61,0.12);
            border-color: rgba(255,217,61,0.3);
            color: var(--accent);
        }

        .notif-btn {
            position: relative;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            color: var(--muted);
            text-decoration: none;
            transition: all 0.2s;
        }

        .notif-btn:hover { border-color: var(--accent); color: var(--accent); }

        .notif-dot {
            position: absolute;
            top: 5px; right: 7px;
            width: 8px; height: 8px;
            background: var(--red);
            border-radius: 50%;
            border: 2px solid var(--bg-main);
        }

        .hamburger {
            display: none;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            color: var(--text);
            cursor: pointer;
        }

        /* ── Page Body ── */
        .page-body { padding: 26px; flex: 1; }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-header h4 {
            font-weight: 800;
            font-size: 1.25rem;
            margin-bottom: 4px;
        }

        .page-header p {
            color: var(--muted);
            font-size: 0.85rem;
            margin: 0;
        }

        /* ── Alerts ── */
        .alert-glass {
            border-radius: 12px;
            padding: 13px 18px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success-glass {
            background: rgba(107,203,119,0.12);
            border: 1px solid rgba(107,203,119,0.3);
            color: var(--green);
        }

        .alert-danger-glass {
            background: rgba(255,107,107,0.12);
            border: 1px solid rgba(255,107,107,0.3);
            color: var(--red);
        }

        /* ── Stat Cards ── */
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.3s;
            text-decoration: none;
            color: var(--text);
            height: 100%;
        }

        .stat-card:hover {
            background: var(--bg-hover);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            color: var(--text);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); }
        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .si-purple { background: rgba(180,143,252,0.15); color: var(--purple); }

        .stat-info h3 {
            font-size: 1.65rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 3px;
        }

        .stat-info p {
            color: var(--muted);
            font-size: 0.78rem;
            margin: 0;
        }

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            height: 100%;
        }

        .section-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-header h6 {
            font-weight: 700;
            font-size: 0.9rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-body { padding: 22px; }

        /* ── Profile Overview ── */
        .profile-avatar-wrapper {
            position: relative;
            width: 110px;
            height: 110px;
            margin: 6px auto 16px;
        }

        .profile-avatar-wrapper img {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--accent);
            color: #1a1a2e;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid var(--bg-main);
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .avatar-upload-btn:hover { background: #ffcd00; transform: scale(1.05); }
        .avatar-upload-btn input[type="file"] { display: none; }

        .profile-name {
            text-align: center;
            font-weight: 800;
            font-size: 1.1rem;
            margin-bottom: 4px;
        }

        .profile-email {
            text-align: center;
            color: var(--muted);
            font-size: 0.8rem;
            margin-bottom: 6px;
        }

        .profile-role-wrap { text-align: center; margin-bottom: 18px; }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.8rem;
        }

        .info-row:last-child { border-bottom: none; }
        .info-row span:first-child { color: var(--muted); }
        .info-row span:last-child { font-weight: 600; text-align: right; }

        /* ── Forms ── */
        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-control {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.85rem;
        }

        .form-control:focus {
            background: rgba(255,255,255,0.07);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255,217,61,0.15);
            color: var(--text);
        }

        .form-control::placeholder { color: rgba(255,255,255,0.3); }
        textarea.form-control { resize: vertical; min-height: 90px; }

        .form-hint {
            font-size: 0.72rem;
            color: var(--muted);
            margin-top: 5px;
        }

        .btn-save {
            background: var(--accent);
            color: #1a1a2e;
            border: none;
            border-radius: 10px;
            padding: 10px 24px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-save:hover { background: #ffcd00; transform: translateY(-1px); }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
        }
    </style>
</head>
<body>

<!-- ════════════════════════════════
     SIDEBAR
════════════════════════════════ -->
<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Learning Management System</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_picture'] ?? '') ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($lecturer_name) ?>&background=ffd93d&color=1a1a2e&size=46'"
             alt="Profile">
        <div>
            <div class="name"><?= htmlspecialchars($lecturer_name) ?></div>
            <span class="role-badge">Lecturer</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>
        <a href="courses.php">
            <i class="bi bi-book"></i> My Courses
            <?php if ($courses_count > 0): ?>
                <span class="nav-badge yellow"><?= $courses_count ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Teaching</div>
        <a href="upload_notes.php">
            <i class="bi bi-cloud-upload"></i> Upload Notes
        </a>
        <a href="view_notes.php">
            <i class="bi bi-file-earmark-text"></i> View Notes
            <?php if ($notes_count > 0): ?>
                <span class="nav-badge yellow"><?= $notes_count ?></span>
            <?php endif; ?>
        </a>
        <a href="create_assignment.php">
            <i class="bi bi-plus-circle"></i> Create Assignment
        </a>
        <a href="view_assignments.php">
            <i class="bi bi-clipboard2-check"></i> Assignments
            <?php if ($assignments_count > 0): ?>
                <span class="nav-badge yellow"><?= $assignments_count ?></span>
            <?php endif; ?>
        </a>
        <a href="grade_submissions.php">
            <i class="bi bi-patch-check"></i> Grade Submissions
            <?php if ($pending_grading > 0): ?>
                <span class="nav-badge"><?= $pending_grading ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Communication</div>
        <a href="notifications.php">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($notifications_count > 0): ?>
                <span class="nav-badge"><?= $notifications_count ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Account</div>
        <a href="profile.php" class="active">
            <i class="bi bi-person-circle"></i> My Profile
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"
           onclick="return confirm('Are you sure you want to logout?')">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>

<!-- ════════════════════════════════
     MAIN CONTENT
════════════════════════════════ -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger" id="hamburger">
                <i class="bi bi-list"></i>
            </button>
            <div class="topbar-left">
                <h6>My Profile</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="dashboard.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-grid-1x2"></i> Dashboard
            </a>
            <a href="notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($notifications_count > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" style="text-decoration:none;">
                <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_picture'] ?? '') ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($lecturer_name) ?>&background=ffd93d&color=1a1a2e&size=36'"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
            </a>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <div class="page-header">
            <div>
                <h4>My Profile</h4>
                <p>Manage your personal information and account security.</p>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert-glass alert-success-glass">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert-glass alert-danger-glass">
                <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- ── Quick Stats ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <a href="courses.php" class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_count ?></h3>
                        <p>Courses</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="courses.php" class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-people"></i></div>
                    <div class="stat-info">
                        <h3><?= $students_count ?></h3>
                        <p>Students</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="view_notes.php" class="stat-card">
                    <div class="stat-icon si-purple"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $notes_count ?></h3>
                        <p>Notes</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="view_assignments.php" class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-clipboard2"></i></div>
                    <div class="stat-info">
                        <h3><?= $assignments_count ?></h3>
                        <p>Assignments</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- ── Profile Overview + Edit Form ── -->
        <form method="POST" action="profile.php" enctype="multipart/form-data">
            <div class="row g-4 mb-4">

                <!-- Overview Card -->
                <div class="col-lg-4">
                    <div class="section-card">
                        <div class="section-body text-center">

                            <div class="profile-avatar-wrapper">
                                <img id="avatarPreview"
                                     src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_picture'] ?? '') ?>"
                                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($lecturer_name) ?>&background=ffd93d&color=1a1a2e&size=110'"
                                     alt="Profile picture">
                                <label class="avatar-upload-btn" title="Change photo">
                                    <i class="bi bi-camera-fill"></i>
                                    <input type="file" name="profile_picture" id="avatarInput" accept="image/png,image/jpeg,image/gif,image/webp">
                                </label>
                            </div>

                            <div class="profile-name"><?= htmlspecialchars($lecturer_name) ?></div>
                            <div class="profile-email"><?= htmlspecialchars($lecturer['email'] ?? '') ?></div>
                            <div class="profile-role-wrap">
                                <span class="role-badge">Lecturer</span>
                            </div>

                            <div class="info-row">
                                <span>Department</span>
                                <span><?= htmlspecialchars($lecturer['department'] ?? '—') ?></span>
                            </div>
                            <div class="info-row">
                                <span>Phone</span>
                                <span><?= htmlspecialchars($lecturer['phone'] ?? '—') ?></span>
                            </div>
                            <div class="info-row">
                                <span>Member Since</span>
                                <span>
                                    <?= isset($lecturer['created_at'])
                                        ? date('d M Y', strtotime($lecturer['created_at']))
                                        : '—' ?>
                                </span>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Edit Form Card -->
                <div class="col-lg-8">
                    <div class="section-card">
                        <div class="section-header">
                            <h6><i class="bi bi-pencil-square" style="color:var(--accent)"></i> Edit Profile Information</h6>
                        </div>
                        <div class="section-body">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" name="name" required
                                           value="<?= htmlspecialchars($lecturer['name'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" required
                                           value="<?= htmlspecialchars($lecturer['email'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone"
                                           placeholder="e.g. +255 700 000 000"
                                           value="<?= htmlspecialchars($lecturer['phone'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <input type="text" class="form-control" name="department"
                                           placeholder="e.g. Computer Science"
                                           value="<?= htmlspecialchars($lecturer['department'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Bio</label>
                                    <textarea class="form-control" name="bio" maxlength="500"
                                              placeholder="A short description about yourself..."><?= htmlspecialchars($lecturer['bio'] ?? '') ?></textarea>
                                    <div class="form-hint">Max 500 characters.</div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" name="update_profile" value="1" class="btn-save">
                                    <i class="bi bi-check-lg"></i> Save Changes
                                </button>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </form>

        <!-- ── Change Password ── -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-shield-lock" style="color:var(--red)"></i> Change Password</h6>
                    </div>
                    <div class="section-body">
                        <form method="POST" action="profile.php">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control" name="new_password" required minlength="6">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required minlength="6">
                                </div>
                            </div>
                            <div class="form-hint mt-2">Password must be at least 6 characters long.</div>
                            <div class="mt-4">
                                <button type="submit" name="change_password" value="1" class="btn-save">
                                    <i class="bi bi-shield-check"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.5); z-index:99;">
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('overlay');
    const hamburger = document.getElementById('hamburger');

    hamburger.addEventListener('click', () => {
        sidebar.classList.add('open');
        overlay.style.display = 'block';
    });

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.style.display = 'none';
    }

    // Live avatar preview before upload
    document.getElementById('avatarInput').addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (file) {
            document.getElementById('avatarPreview').src = URL.createObjectURL(file);
        }
    });
</script>
</body>
</html>