<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'];

$success = '';
$error   = '';

// ── Handle Add Course ────────────────────────────────────
if (isset($_POST['add_course'])) {
    $course_code = trim(mysqli_real_escape_string($conn, $_POST['course_code']));
    $course_name = trim(mysqli_real_escape_string($conn, $_POST['course_name']));
    $description = trim(mysqli_real_escape_string($conn, $_POST['description']));
    $lecturer_id = intval($_POST['lecturer_id']);

    if (empty($course_code) || empty($course_name) || empty($lecturer_id)) {
        $error = "Course code, name and lecturer are required.";
    } else {
        // Check duplicate course code
        $check = mysqli_query($conn, "SELECT id FROM courses WHERE course_code = '$course_code'");
        if (mysqli_num_rows($check) > 0) {
            $error = "Course code '$course_code' already exists.";
        } else {
            $sql = "INSERT INTO courses (course_code, course_name, description, lecturer_id, status)
                    VALUES ('$course_code', '$course_name', '$description', '$lecturer_id', 'active')";
            if (mysqli_query($conn, $sql)) {
                $new_id = mysqli_insert_id($conn);
                // Log activity
                $ip = $_SERVER['REMOTE_ADDR'];
                mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, module, details, ip_address)
                                     VALUES ('$admin_id', 'Added course', 'Courses', 'Added: $course_name', '$ip')");
                $success = "Course '$course_name' added successfully!";
            } else {
                $error = "Failed to add course. Please try again.";
            }
        }
    }
}

// ── Handle Edit Course ───────────────────────────────────
if (isset($_POST['edit_course'])) {
    $id          = intval($_POST['course_id']);
    $course_code = trim(mysqli_real_escape_string($conn, $_POST['course_code']));
    $course_name = trim(mysqli_real_escape_string($conn, $_POST['course_name']));
    $description = trim(mysqli_real_escape_string($conn, $_POST['description']));
    $lecturer_id = intval($_POST['lecturer_id']);
    $status      = $_POST['status'] === 'active' ? 'active' : 'inactive';

    if (empty($course_code) || empty($course_name) || empty($lecturer_id)) {
        $error = "Course code, name and lecturer are required.";
    } else {
        // Check duplicate code excluding this course
        $check = mysqli_query($conn, "SELECT id FROM courses
                                       WHERE course_code = '$course_code' AND id != '$id'");
        if (mysqli_num_rows($check) > 0) {
            $error = "Course code '$course_code' already used by another course.";
        } else {
            $sql = "UPDATE courses SET
                        course_code  = '$course_code',
                        course_name  = '$course_name',
                        description  = '$description',
                        lecturer_id  = '$lecturer_id',
                        status       = '$status'
                    WHERE id = '$id'";
            if (mysqli_query($conn, $sql)) {
                $ip = $_SERVER['REMOTE_ADDR'];
                mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, module, details, ip_address)
                                     VALUES ('$admin_id', 'Edited course', 'Courses', 'Edited: $course_name', '$ip')");
                $success = "Course updated successfully!";
            } else {
                $error = "Failed to update course.";
            }
        }
    }
}

// ── Handle Delete Course ─────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $cname  = mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT course_name FROM courses WHERE id = '$del_id'")
    )['course_name'] ?? '';

    // Delete enrollments, notes, assignments first
    mysqli_query($conn, "DELETE FROM course_enrollments WHERE course_id = '$del_id'");
    mysqli_query($conn, "DELETE FROM notes WHERE course_id = '$del_id'");
    mysqli_query($conn, "DELETE FROM assignments WHERE course_id = '$del_id'");
    mysqli_query($conn, "DELETE FROM courses WHERE id = '$del_id'");

    $ip = $_SERVER['REMOTE_ADDR'];
    mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, module, details, ip_address)
                         VALUES ('$admin_id', 'Deleted course', 'Courses', 'Deleted: $cname', '$ip')");
    $success = "Course deleted successfully.";
}

// ── Handle Toggle Status ─────────────────────────────────
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tog_id = intval($_GET['toggle']);
    $cur    = mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT status FROM courses WHERE id = '$tog_id'")
    )['status'];
    $new_status = ($cur === 'active') ? 'inactive' : 'active';
    mysqli_query($conn, "UPDATE courses SET status = '$new_status' WHERE id = '$tog_id'");
    $success = "Course status changed to $new_status.";
}

// ── Search & Filter ──────────────────────────────────────
$search     = isset($_GET['search']) ? trim(mysqli_real_escape_string($conn, $_GET['search'])) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$page       = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$per_page   = 8;
$offset     = ($page - 1) * $per_page;

$where = "WHERE 1=1";
if ($search)       $where .= " AND (c.course_name LIKE '%$search%' OR c.course_code LIKE '%$search%' OR u.name LIKE '%$search%')";
if ($filter_status) $where .= " AND c.status = '$filter_status'";

$total_rows = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM courses c
                          INNER JOIN users u ON c.lecturer_id = u.id $where")
)['total'];

$total_pages = ceil($total_rows / $per_page);

$courses = mysqli_query($conn,
    "SELECT c.*, u.name AS lecturer_name,
            (SELECT COUNT(*) FROM course_enrollments WHERE course_id = c.id AND status = 'enrolled') AS students,
            (SELECT COUNT(*) FROM notes WHERE course_id = c.id AND status = 'active') AS notes_count,
            (SELECT COUNT(*) FROM assignments WHERE course_id = c.id AND status = 'active') AS assign_count
     FROM courses c
     INNER JOIN users u ON c.lecturer_id = u.id
     $where
     ORDER BY c.created_at DESC
     LIMIT $per_page OFFSET $offset"
);

// ── Fetch lecturers for dropdowns ────────────────────────
$lecturers = mysqli_query($conn,
    "SELECT id, name FROM users WHERE role = 'lecturer' AND status = 'active' ORDER BY name ASC"
);
$lecturers_arr = [];
while ($l = mysqli_fetch_assoc($lecturers)) {
    $lecturers_arr[] = $l;
}

// ── Summary counts ───────────────────────────────────────
$total_courses  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM courses"))['t'];
$active_courses = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM courses WHERE status='active'"))['t'];
$inactive_courses = $total_courses - $active_courses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - OnlineLMS</title>
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
            --accent:    #ff6b6b;
            --blue:      #00d4ff;
            --green:     #6bcb77;
            --yellow:    #ffd93d;
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
            overflow-y: auto;
        }

        .sidebar-brand { padding: 22px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.15rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.72rem; }

        .sidebar-profile {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }

        .sidebar-profile img {
            width: 44px; height: 44px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--accent);
        }

        .sidebar-profile .name { font-weight: 600; font-size: 0.88rem; color: var(--text); }

        .role-badge {
            background: rgba(255,107,107,0.15); color: var(--accent);
            border: 1px solid rgba(255,107,107,0.3);
            border-radius: 20px; padding: 1px 10px;
            font-size: 0.68rem; font-weight: 700;
        }

        .sidebar-nav { flex: 1; padding: 12px 0; }

        .nav-section {
            padding: 8px 20px 4px;
            font-size: 0.67rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px;
            color: var(--muted);
        }

        .sidebar-nav a {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 20px; color: var(--muted);
            text-decoration: none; font-size: 0.875rem; font-weight: 500;
            border-left: 3px solid transparent; transition: all 0.2s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            color: var(--text); background: var(--bg-hover);
            border-left-color: var(--accent);
        }

        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1rem; width: 20px; }

        .nav-badge {
            margin-left: auto; background: var(--accent);
            color: white; border-radius: 20px;
            padding: 1px 8px; font-size: 0.68rem; font-weight: 700;
        }

        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a {
            display: flex; align-items: center; gap: 10px;
            color: var(--accent); text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            padding: 8px 0; transition: opacity 0.2s;
        }
        .sidebar-footer a:hover { opacity: 0.75; }

        /* ── Main ── */
        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1; display: flex; flex-direction: column; min-height: 100vh;
        }

        /* ── Topbar ── */
        .topbar {
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid var(--border);
            padding: 13px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 50;
            backdrop-filter: blur(10px);
        }

        .topbar-left h6 { font-weight: 700; font-size: 1rem; margin: 0; }
        .topbar-left p  { color: var(--muted); font-size: 0.78rem; margin: 0; }

        .topbar-right { display: flex; align-items: center; gap: 12px; }

        .btn-add {
            background: var(--accent);
            border: none; border-radius: 10px;
            padding: 9px 18px; color: white;
            font-size: 0.82rem; font-weight: 600;
            text-decoration: none; cursor: pointer;
            display: flex; align-items: center; gap: 6px;
            transition: all 0.2s;
        }

        .btn-add:hover { background: #ff4444; color: white; transform: translateY(-1px); }

        .hamburger {
            display: none; background: var(--bg-card);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 8px 12px; color: var(--text); cursor: pointer;
        }

        /* ── Page Body ── */
        .page-body { padding: 26px; flex: 1; }

        /* ── Stat Cards ── */
        .stat-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 14px; padding: 18px 16px;
            display: flex; align-items: center; gap: 13px;
        }

        .stat-icon {
            width: 46px; height: 46px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; flex-shrink: 0;
        }

        .si-red    { background: rgba(255,107,107,0.15); color: var(--accent); }
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }

        .stat-info h4 { font-size: 1.5rem; font-weight: 800; line-height: 1; margin-bottom: 2px; }
        .stat-info p  { color: var(--muted); font-size: 0.76rem; margin: 0; }

        /* ── Search & Filter Bar ── */
        .filter-bar {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 14px; padding: 16px 20px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .search-box {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 8px 14px; flex: 1; min-width: 200px;
        }

        .search-box i { color: var(--muted); font-size: 0.9rem; }

        .search-box input {
            background: none; border: none; outline: none;
            color: var(--text); font-size: 0.875rem; width: 100%;
        }

        .search-box input::placeholder { color: var(--muted); }

        .filter-select {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 8px 14px; color: var(--text); font-size: 0.875rem;
            cursor: pointer; outline: none;
        }

        .filter-select option { background: #1a1a2e; color: var(--text); }

        .btn-filter {
            background: var(--accent); border: none; border-radius: 10px;
            padding: 9px 18px; color: white; font-size: 0.82rem;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
            display: flex; align-items: center; gap: 6px;
        }

        .btn-filter:hover { background: #ff4444; }

        .btn-clear {
            background: var(--bg-hover); border: 1px solid var(--border);
            border-radius: 10px; padding: 9px 14px; color: var(--muted);
            font-size: 0.82rem; cursor: pointer; text-decoration: none;
            transition: all 0.2s;
        }

        .btn-clear:hover { color: var(--text); }

        /* ── Table ── */
        .table-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden;
        }

        .table-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }

        .table-header h6 {
            font-weight: 700; font-size: 0.9rem; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }

        .table-responsive { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }

        thead th {
            padding: 12px 16px; text-align: left;
            font-size: 0.75rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.8px;
            color: var(--muted); border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 13px 16px; font-size: 0.85rem;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--bg-hover); }

        .course-code-badge {
            background: rgba(255,107,107,0.12); color: var(--accent);
            border: 1px solid rgba(255,107,107,0.25);
            border-radius: 7px; padding: 3px 10px;
            font-size: 0.75rem; font-weight: 700;
            white-space: nowrap;
        }

        .status-badge {
            padding: 3px 12px; border-radius: 20px;
            font-size: 0.72rem; font-weight: 600;
            white-space: nowrap;
        }

        .status-active {
            background: rgba(107,203,119,0.15); color: var(--green);
            border: 1px solid rgba(107,203,119,0.3);
        }

        .status-inactive {
            background: rgba(255,107,107,0.15); color: var(--accent);
            border: 1px solid rgba(255,107,107,0.3);
        }

        /* ── Action Buttons ── */
        .action-btns { display: flex; gap: 6px; }

        .btn-icon {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; cursor: pointer; border: none;
            text-decoration: none; transition: all 0.2s;
        }

        .btn-edit   { background: rgba(0,212,255,0.12);   color: var(--blue);   }
        .btn-toggle { background: rgba(255,217,61,0.12);  color: var(--yellow); }
        .btn-delete { background: rgba(255,107,107,0.12); color: var(--accent); }

        .btn-edit:hover   { background: rgba(0,212,255,0.25);   color: var(--blue);   }
        .btn-toggle:hover { background: rgba(255,217,61,0.25);  color: var(--yellow); }
        .btn-delete:hover { background: rgba(255,107,107,0.25); color: var(--accent); }

        /* ── Mini Stats in Table ── */
        .mini-stat {
            display: inline-flex; align-items: center; gap: 3px;
            color: var(--muted); font-size: 0.75rem;
            margin-right: 8px;
        }

        /* ── Pagination ── */
        .pagination-wrap {
            padding: 16px 20px; border-top: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
        }

        .pagination-info { color: var(--muted); font-size: 0.8rem; }

        .pagination { display: flex; gap: 5px; }

        .page-btn {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.82rem; text-decoration: none;
            transition: all 0.2s; border: 1px solid var(--border);
            color: var(--muted);
        }

        .page-btn:hover { background: var(--bg-hover); color: var(--text); }
        .page-btn.active { background: var(--accent); border-color: var(--accent); color: white; }
        .page-btn.disabled { opacity: 0.3; pointer-events: none; }

        /* ── Alerts ── */
        .alert-success-glass {
            background: rgba(107,203,119,0.12);
            border: 1px solid rgba(107,203,119,0.3);
            color: var(--green); border-radius: 12px;
            padding: 12px 16px; font-size: 0.875rem;
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 20px;
        }

        .alert-error-glass {
            background: rgba(255,107,107,0.12);
            border: 1px solid rgba(255,107,107,0.3);
            color: var(--accent); border-radius: 12px;
            padding: 12px 16px; font-size: 0.875rem;
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 20px;
        }

        /* ── Modal ── */
        .modal-glass .modal-content {
            background: #1a1a2e;
            border: 1px solid var(--border);
            border-radius: 16px; color: var(--text);
        }

        .modal-glass .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 18px 22px;
        }

        .modal-glass .modal-title { font-weight: 700; font-size: 1rem; }

        .modal-glass .modal-footer {
            border-top: 1px solid var(--border);
            padding: 14px 22px;
        }

        .modal-glass .modal-body { padding: 22px; }

        .form-label-glass {
            color: rgba(255,255,255,0.75);
            font-size: 0.85rem; font-weight: 500;
            margin-bottom: 5px; display: block;
        }

        .form-control-glass,
        .form-select-glass {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px; color: var(--text);
            padding: 10px 14px; font-size: 0.9rem;
            width: 100%; transition: all 0.3s; outline: none;
        }

        .form-control-glass:focus,
        .form-select-glass:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255,107,107,0.15);
            background: rgba(255,255,255,0.1);
            color: var(--text);
        }

        .form-control-glass::placeholder { color: rgba(255,255,255,0.25); }
        .form-select-glass option { background: #1a1a2e; color: var(--text); }

        .btn-submit {
            background: var(--accent); border: none;
            border-radius: 10px; padding: 10px 24px;
            color: white; font-weight: 600; font-size: 0.9rem;
            cursor: pointer; transition: all 0.2s;
        }

        .btn-submit:hover { background: #ff4444; transform: translateY(-1px); }

        .btn-cancel {
            background: var(--bg-hover); border: 1px solid var(--border);
            border-radius: 10px; padding: 10px 20px;
            color: var(--muted); font-size: 0.9rem;
            cursor: pointer; transition: all 0.2s;
        }

        .btn-cancel:hover { color: var(--text); }

        /* ── Empty State ── */
        .empty-state {
            padding: 50px 20px; text-align: center; color: var(--muted);
        }

        .empty-state i { font-size: 2.5rem; opacity: 0.3; display: block; margin-bottom: 10px; }
        .empty-state p { font-size: 0.875rem; margin: 0; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>

<!-- ════════════════════════════
     SIDEBAR
════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Admin Control Panel</span>
    </div>

    <div class="sidebar-profile">
        <?php $admin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id = '$admin_id'")); ?>
        <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=44'"
             alt="Admin">
        <div>
            <div class="name"><?= htmlspecialchars($admin_name) ?></div>
            <span class="role-badge">Administrator</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
        <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>

        <div class="nav-section">Management</div>
        <a href="manage_users.php"><i class="bi bi-people"></i> Manage Users</a>
        <a href="manage_courses.php" class="active"><i class="bi bi-book"></i> Manage Courses</a>
        <a href="manage_notes.php"><i class="bi bi-file-earmark-text"></i> Manage Notes</a>
        <a href="manage_assignments.php"><i class="bi bi-clipboard2-check"></i> Manage Assignments</a>
        <a href="manage_notifications.php"><i class="bi bi-bell"></i> Notifications</a>

        <div class="nav-section">System</div>
        <a href="settings.php"><i class="bi bi-gear"></i> Settings</a>

        <div class="nav-section">Account</div>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" onclick="return confirm('Logout?')">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>

<!-- ════════════════════════════
     MAIN CONTENT
════════════════════════════ -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
            <div class="topbar-left">
                <h6>Manage Courses</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                <i class="bi bi-plus-circle"></i> Add Course
            </button>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert-success-glass">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-error-glass">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- ── Stat Cards ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h4><?= $total_courses ?></h4>
                        <p>Total Courses</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-check-circle"></i></div>
                    <div class="stat-info">
                        <h4><?= $active_courses ?></h4>
                        <p>Active</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-red"><i class="bi bi-x-circle"></i></div>
                    <div class="stat-info">
                        <h4><?= $inactive_courses ?></h4>
                        <p>Inactive</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-person-video3"></i></div>
                    <div class="stat-info">
                        <h4><?= count($lecturers_arr) ?></h4>
                        <p>Lecturers</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Search & Filter ── -->
        <form method="GET" action="">
            <div class="filter-bar">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text"
                           name="search"
                           placeholder="Search by course name, code or lecturer..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="status" class="filter-select">
                    <option value="">All Status</option>
                    <option value="active"   <?= $filter_status == 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filter_status == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit" class="btn-filter">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <?php if ($search || $filter_status): ?>
                    <a href="manage_courses.php" class="btn-clear">
                        <i class="bi bi-x"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- ── Courses Table ── -->
        <div class="table-card">
            <div class="table-header">
                <h6>
                    <i class="bi bi-book" style="color:var(--accent)"></i>
                    All Courses
                    <span style="color:var(--muted); font-weight:400;">(<?= $total_rows ?>)</span>
                </h6>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Course</th>
                            <th>Lecturer</th>
                            <th>Stats</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($courses) > 0): ?>
                            <?php $sn = $offset + 1; while ($course = mysqli_fetch_assoc($courses)): ?>
                                <tr>
                                    <td style="color:var(--muted)"><?= $sn++ ?></td>

                                    <!-- Course Info -->
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div style="width:38px; height:38px; border-radius:10px;
                                                        background:rgba(255,107,107,0.12);
                                                        display:flex; align-items:center; justify-content:center;
                                                        color:var(--accent); font-size:1rem; flex-shrink:0;">
                                                <i class="bi bi-book"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight:600; font-size:0.875rem;">
                                                    <?= htmlspecialchars($course['course_name']) ?>
                                                </div>
                                                <span class="course-code-badge">
                                                    <?= htmlspecialchars($course['course_code']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Lecturer -->
                                    <td>
                                        <div style="font-size:0.845rem; font-weight:500;">
                                            <?= htmlspecialchars($course['lecturer_name']) ?>
                                        </div>
                                    </td>

                                    <!-- Stats -->
                                    <td>
                                        <span class="mini-stat">
                                            <i class="bi bi-people"></i><?= $course['students'] ?>
                                        </span>
                                        <span class="mini-stat">
                                            <i class="bi bi-file-earmark-text"></i><?= $course['notes_count'] ?>
                                        </span>
                                        <span class="mini-stat">
                                            <i class="bi bi-clipboard2"></i><?= $course['assign_count'] ?>
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <span class="status-badge <?= $course['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                            <?= ucfirst($course['status']) ?>
                                        </span>
                                    </td>

                                    <!-- Date -->
                                    <td style="color:var(--muted); font-size:0.8rem;">
                                        <?= date('d M Y', strtotime($course['created_at'])) ?>
                                    </td>

                                    <!-- Actions -->
                                    <td>
                                        <div class="action-btns">
                                            <!-- Edit -->
                                            <button class="btn-icon btn-edit"
                                                    title="Edit"
                                                    onclick="openEditModal(
                                                        <?= $course['id'] ?>,
                                                        '<?= addslashes($course['course_code']) ?>',
                                                        '<?= addslashes($course['course_name']) ?>',
                                                        '<?= addslashes($course['description']) ?>',
                                                        <?= $course['lecturer_id'] ?>,
                                                        '<?= $course['status'] ?>'
                                                    )">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <!-- Toggle Status -->
                                            <a href="manage_courses.php?toggle=<?= $course['id'] ?><?= $search ? '&search='.$search : '' ?>"
                                               class="btn-icon btn-toggle"
                                               title="<?= $course['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"
                                               onclick="return confirm('Change course status?')">
                                                <i class="bi bi-<?= $course['status'] === 'active' ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </a>

                                            <!-- Delete -->
                                            <a href="manage_courses.php?delete=<?= $course['id'] ?><?= $search ? '&search='.$search : '' ?>"
                                               class="btn-icon btn-delete"
                                               title="Delete"
                                               onclick="return confirm('Delete this course? This will also remove all enrollments, notes and assignments.')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="bi bi-book"></i>
                                        <p>No courses found.
                                            <?= $search ? 'Try a different search.' : '' ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-wrap">
                    <div class="pagination-info">
                        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?>
                        of <?= $total_rows ?> courses
                    </div>
                    <div class="pagination">
                        <a href="?page=<?= max(1, $page-1) ?>&search=<?= $search ?>&status=<?= $filter_status ?>"
                           class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>&search=<?= $search ?>&status=<?= $filter_status ?>"
                               class="page-btn <?= $i == $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <a href="?page=<?= min($total_pages, $page+1) ?>&search=<?= $search ?>&status=<?= $filter_status ?>"
                           class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div><!-- end table-card -->
    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- ════════════════════════════
     ADD COURSE MODAL
════════════════════════════ -->
<div class="modal fade modal-glass" id="addCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2" style="color:var(--accent)"></i>Add New Course
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label-glass">Course Code <span class="text-danger">*</span></label>
                            <input type="text"
                                   name="course_code"
                                   class="form-control-glass"
                                   placeholder="e.g. CS101"
                                   required
                                   style="text-transform:uppercase;">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label-glass">Course Name <span class="text-danger">*</span></label>
                            <input type="text"
                                   name="course_name"
                                   class="form-control-glass"
                                   placeholder="e.g. Introduction to Programming"
                                   required>
                        </div>
                        <div class="col-12">
                            <label class="form-label-glass">Assign Lecturer <span class="text-danger">*</span></label>
                            <select name="lecturer_id" class="form-select-glass" required>
                                <option value="">-- Select Lecturer --</option>
                                <?php foreach ($lecturers_arr as $lect): ?>
                                    <option value="<?= $lect['id'] ?>">
                                        <?= htmlspecialchars($lect['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label-glass">Description</label>
                            <textarea name="description"
                                      class="form-control-glass"
                                      rows="3"
                                      placeholder="Brief course description..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_course" class="btn-submit">
                        <i class="bi bi-plus-circle me-1"></i> Add Course
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════════════════════════
     EDIT COURSE MODAL
════════════════════════════ -->
<div class="modal fade modal-glass" id="editCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2" style="color:var(--blue)"></i>Edit Course
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="course_id" id="edit_course_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label-glass">Course Code <span class="text-danger">*</span></label>
                            <input type="text"
                                   name="course_code"
                                   id="edit_course_code"
                                   class="form-control-glass"
                                   required
                                   style="text-transform:uppercase;">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label-glass">Course Name <span class="text-danger">*</span></label>
                            <input type="text"
                                   name="course_name"
                                   id="edit_course_name"
                                   class="form-control-glass"
                                   required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Assign Lecturer <span class="text-danger">*</span></label>
                            <select name="lecturer_id" id="edit_lecturer_id" class="form-select-glass" required>
                                <option value="">-- Select Lecturer --</option>
                                <?php foreach ($lecturers_arr as $lect): ?>
                                    <option value="<?= $lect['id'] ?>">
                                        <?= htmlspecialchars($lect['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Status</label>
                            <select name="status" id="edit_status" class="form-select-glass">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label-glass">Description</label>
                            <textarea name="description"
                                      id="edit_description"
                                      class="form-control-glass"
                                      rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_course" class="btn-submit"
                            style="background:var(--blue);">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.5); z-index:99;">
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── Sidebar ─────────────────────────────────────────
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

    // ── Open Edit Modal ──────────────────────────────────
    function openEditModal(id, code, name, description, lecturerId, status) {
        document.getElementById('edit_course_id').value   = id;
        document.getElementById('edit_course_code').value = code;
        document.getElementById('edit_course_name').value = name;
        document.getElementById('edit_description').value = description;
        document.getElementById('edit_status').value      = status;

        // Set lecturer dropdown
        const sel = document.getElementById('edit_lecturer_id');
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value == lecturerId) {
                sel.selectedIndex = i;
                break;
            }
        }

        // Show modal
        new bootstrap.Modal(document.getElementById('editCourseModal')).show();
    }

    // ── Auto uppercase course code ───────────────────────
    document.querySelectorAll('[name="course_code"]').forEach(el => {
        el.addEventListener('input', function () {
            this.value = this.value.toUpperCase();
        });
    });

    // ── Auto open add modal on error ─────────────────────
    <?php if ($error && isset($_POST['add_course'])): ?>
        window.addEventListener('load', () => {
            new bootstrap.Modal(document.getElementById('addCourseModal')).show();
        });
    <?php endif; ?>

    <?php if ($error && isset($_POST['edit_course'])): ?>
        window.addEventListener('load', () => {
            new bootstrap.Modal(document.getElementById('editCourseModal')).show();
        });
    <?php endif; ?>
</script>
</body>
</html>