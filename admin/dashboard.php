<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'];

// ── Fetch admin info ─────────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $admin_id);
mysqli_stmt_execute($stmt);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// ── Total users ──────────────────────────────────────────
$total_users = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE status = 'active'")
)['total'];

// ── Total students ───────────────────────────────────────
$total_students = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users
                          WHERE role = 'student' AND status = 'active'")
)['total'];

// ── Total lecturers ──────────────────────────────────────
$total_lecturers = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users
                          WHERE role = 'lecturer' AND status = 'active'")
)['total'];

// ── Total courses ────────────────────────────────────────
$total_courses = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM courses WHERE status = 'active'")
)['total'];

// ── Total notes ──────────────────────────────────────────
$total_notes = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notes WHERE status = 'active'")
)['total'];

// ── Total assignments ────────────────────────────────────
$total_assignments = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM assignments WHERE status = 'active'")
)['total'];

// ── Total submissions ────────────────────────────────────
$total_submissions = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM submissions")
)['total'];

// ── Total notifications ──────────────────────────────────
$total_notifications = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications")
)['total'];

// ── Pending grading ──────────────────────────────────────
$pending_grading = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM submissions s
                          LEFT JOIN grades g ON s.id = g.submission_id
                          WHERE g.id IS NULL")
)['total'];

// ── New users this month ─────────────────────────────────
$new_users_month = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users
                          WHERE MONTH(created_at) = MONTH(NOW())
                          AND YEAR(created_at) = YEAR(NOW())")
)['total'];

// ── Recent users (last 6) ────────────────────────────────
$recent_users = mysqli_query($conn,
    "SELECT * FROM users
     ORDER BY created_at DESC
     LIMIT 6"
);

// ── Recent activity logs (last 8) ────────────────────────
$recent_activity = mysqli_query($conn,
    "SELECT al.*, u.name AS user_name, u.role
     FROM activity_logs al
     INNER JOIN users u ON al.user_id = u.id
     ORDER BY al.created_at DESC
     LIMIT 8"
);

// ── All courses with stats ───────────────────────────────
$all_courses = mysqli_query($conn,
    "SELECT c.*, u.name AS lecturer_name,
            (SELECT COUNT(*) FROM course_enrollments WHERE course_id = c.id AND status = 'enrolled') AS students,
            (SELECT COUNT(*) FROM notes WHERE course_id = c.id AND status = 'active') AS notes_count,
            (SELECT COUNT(*) FROM assignments WHERE course_id = c.id AND status = 'active') AS assign_count
     FROM courses c
     INNER JOIN users u ON c.lecturer_id = u.id
     WHERE c.status = 'active'
     ORDER BY c.created_at DESC
     LIMIT 6"
);
// ── Recent notifications ─────────────────────────────────
$recent_notifications = mysqli_query($conn,
    "SELECT n.*, u.name AS sender_name
     FROM notifications n
     INNER JOIN users u ON n.sent_by = u.id
     ORDER BY n.created_at DESC
     LIMIT 5"
);

// ── Users by role for chart ──────────────────────────────
$admins_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role = 'admin'")
)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - OnlineLMS</title>
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
            background: #ff6b6b;
            color: #ffffff;
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 0.72rem;
            font-weight: 700;
            box-shadow: 0 1px 0 rgba(0,0,0,0.04) inset;
        }

        .sidebar-nav {
            flex: 1;
            padding: 12px 0;
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
            background: var(--accent);
            color: white;
            border-radius: 20px;
            padding: 1px 8px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .sidebar-footer {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
        }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent);
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

        .topbar-btn:hover { border-color: var(--accent); color: var(--accent); }

        .topbar-btn.red {
            background: rgba(255,107,107,0.1);
            border-color: rgba(255,107,107,0.3);
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
            background: var(--accent);
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

        /* ── Welcome Banner ── */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(255,107,107,0.1), rgba(255,50,50,0.04));
            border: 1px solid rgba(255,107,107,0.2);
            border-radius: 16px;
            padding: 22px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
        }

        .welcome-banner h4 {
            font-weight: 800;
            font-size: 1.25rem;
            margin-bottom: 4px;
        }

        .welcome-banner p {
            color: var(--muted);
            font-size: 0.85rem;
            margin: 0;
        }

        .welcome-actions {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }

        .btn-action {
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-red {
            background: var(--accent);
            color: white;
        }

        .btn-red:hover {
            background: #ff4444;
            color: white;
            transform: translateY(-1px);
        }

        .btn-outline-red {
            background: rgba(255,107,107,0.1);
            border: 1px solid rgba(255,107,107,0.3);
            color: var(--accent);
        }

        .btn-outline-red:hover {
            background: rgba(255,107,107,0.2);
            color: var(--accent);
            transform: translateY(-1px);
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

        .si-red    { background: rgba(255,107,107,0.15); color: var(--accent); }
        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .si-purple { background: rgba(180,143,252,0.15); color: var(--purple); }
        .si-teal   { background: rgba(32,201,151,0.15);  color: #20c997;       }
        .si-orange { background: rgba(255,165,0,0.15);   color: #ffa500;       }
        .si-pink   { background: rgba(255,105,180,0.15); color: #ff69b4;       }

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

        .stat-trend {
            margin-top: 3px;
            font-size: 0.7rem;
            color: var(--green);
        }

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
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

        .section-header a {
            color: var(--accent);
            font-size: 0.78rem;
            text-decoration: none;
        }

        .section-header a:hover { text-decoration: underline; }

        /* ── List Items ── */
        .list-item {
            padding: 13px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 13px;
            transition: background 0.2s;
        }

        .list-item:last-child { border-bottom: none; }
        .list-item:hover { background: var(--bg-hover); }

        .item-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        .ii-red    { background: rgba(255,107,107,0.15); color: var(--accent); }
        .ii-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }
        .ii-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .ii-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .ii-purple { background: rgba(180,143,252,0.15); color: var(--purple); }

        .item-title {
            font-size: 0.845rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 2px;
        }

        .item-sub {
            font-size: 0.73rem;
            color: var(--muted);
        }

        .item-right {
            margin-left: auto;
            text-align: right;
            flex-shrink: 0;
        }

        /* ── Badges ── */
        .badge-glass {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .bg-red    { background: rgba(255,107,107,0.15); color: var(--accent); border: 1px solid rgba(255,107,107,0.3); }
        .bg-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   border: 1px solid rgba(0,212,255,0.3);   }
        .bg-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);  }
        .bg-purple { background: rgba(180,143,252,0.15); color: var(--purple); border: 1px solid rgba(180,143,252,0.3); }

        /* ── Quick Actions ── */
        .quick-action {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 18px;
            text-align: center;
            text-decoration: none;
            color: var(--text);
            transition: all 0.3s;
            display: block;
        }

        .quick-action:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            transform: translateY(-3px);
            color: var(--accent);
        }

        .quick-action i {
            font-size: 1.6rem;
            margin-bottom: 8px;
            display: block;
        }

        .quick-action span {
            font-size: 0.78rem;
            font-weight: 600;
        }

        /* ── Course Cards ── */
        .course-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 16px;
            transition: all 0.3s;
            height: 100%;
        }

        .course-card:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .course-code-tag {
            background: rgba(255,107,107,0.12);
            color: var(--accent);
            border-radius: 7px;
            padding: 2px 9px;
            font-size: 0.72rem;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 7px;
        }

        .course-title {
            font-weight: 700;
            font-size: 0.875rem;
            margin-bottom: 5px;
            color: var(--text);
        }

        .course-lecturer-name {
            color: var(--muted);
            font-size: 0.75rem;
            margin-bottom: 10px;
        }

        .course-stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .course-stats span {
            color: var(--muted);
            font-size: 0.72rem;
        }

        .course-stats span i { margin-right: 3px; }

        /* ── User Avatar ── */
        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
            flex-shrink: 0;
        }

        /* ── Chart Bar ── */
        .chart-bar-wrap {
            padding: 20px;
        }

        .chart-row {
            margin-bottom: 16px;
        }

        .chart-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.8rem;
        }

        .chart-label span:first-child { color: var(--text); font-weight: 500; }
        .chart-label span:last-child  { color: var(--muted); }

        .chart-bar {
            height: 8px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
        }

        .chart-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }

        /* ── Empty State ── */
        .empty-state {
            padding: 30px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 1.8rem;
            margin-bottom: 8px;
            opacity: 0.35;
            display: block;
        }

        .empty-state p { font-size: 0.82rem; margin: 0; }

        /* ── Progress Bar ── */
        .progress-thin {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill-red {
            height: 100%;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--accent), #ff4444);
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .welcome-actions { display: none; }
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
        <span>Admin Control Panel</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=46'"
             alt="Admin">
        <div>
            <div class="name"><?= htmlspecialchars($admin_name) ?></div>
            <span class="role-badge">Administrator</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php" class="active">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>
        <a href="reports.php">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>

        <div class="nav-section">Management</div>
        <a href="manage_users.php">
            <i class="bi bi-people"></i> Manage Users
            <?php if ($new_users_month > 0): ?>
                <span class="nav-badge"><?= $new_users_month ?> new</span>
            <?php endif; ?>
        </a>
        <a href="manage_courses.php">
            <i class="bi bi-book"></i> Manage Courses
        </a>
        <a href="manage_notes.php">
            <i class="bi bi-file-earmark-text"></i> Manage Notes
        </a>
        <a href="manage_assignments.php">
            <i class="bi bi-clipboard2-check"></i> Manage Assignments
        </a>
        <a href="manage_notifications.php">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($total_notifications > 0): ?>
                <span class="nav-badge"><?= $total_notifications ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">System</div>
        <a href="settings.php">
            <i class="bi bi-gear"></i> Settings
        </a>

        <div class="nav-section">Account</div>
        <a href="profile.php">
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
                <h6>Admin Dashboard</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="manage_users.php" class="topbar-btn red d-none d-md-flex">
                <i class="bi bi-person-plus"></i> Add User
            </a>
            <a href="manage_courses.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-plus-circle"></i> Add Course
            </a>
            <a href="manage_notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" style="text-decoration:none;">
                <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=36'"
                     style="width:36px;height:36px;border-radius:50%;
                            object-fit:cover;border:2px solid var(--accent);">
            </a>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div>
                <h4>Welcome, <?= htmlspecialchars(explode(' ', $admin_name)[0]) ?>! 🛡️</h4>
                <p>
                    System has
                    <strong style="color:var(--blue)"><?= $total_users ?> active users</strong>,
                    <strong style="color:var(--yellow)"><?= $total_courses ?> courses</strong> and
                    <strong style="color:var(--accent)"><?= $pending_grading ?> submission(s) pending grading</strong>.
                </p>
            </div>
            <div class="welcome-actions">
                <a href="manage_users.php" class="btn-action btn-red">
                    <i class="bi bi-person-plus"></i> Add User
                </a>
                <a href="manage_courses.php" class="btn-action btn-outline-red">
                    <i class="bi bi-plus-circle"></i> Add Course
                </a>
            </div>
        </div>

        <!-- ── Stat Cards Row 1 ── -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_users.php" class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-people"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_users ?></h3>
                        <p>Total Users</p>
                        <div class="stat-trend">
                            <i class="bi bi-arrow-up-short"></i><?= $new_users_month ?> this month
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_users.php?role=student" class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-person-check"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_students ?></h3>
                        <p>Students</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_users.php?role=lecturer" class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-person-video3"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_lecturers ?></h3>
                        <p>Lecturers</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_courses.php" class="stat-card">
                    <div class="stat-icon si-purple"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_courses ?></h3>
                        <p>Courses</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- ── Stat Cards Row 2 ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_notes.php" class="stat-card">
                    <div class="stat-icon si-red"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_notes ?></h3>
                        <p>Notes</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_assignments.php" class="stat-card">
                    <div class="stat-icon si-orange"><i class="bi bi-clipboard2"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_assignments ?></h3>
                        <p>Assignments</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_assignments.php" class="stat-card">
                    <div class="stat-icon si-teal"><i class="bi bi-inbox"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_submissions ?></h3>
                        <p>Submissions</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <a href="manage_notifications.php" class="stat-card">
                    <div class="stat-icon si-pink"><i class="bi bi-bell"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_notifications ?></h3>
                        <p>Notifications</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- ── Quick Actions ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <a href="manage_users.php" class="quick-action">
                    <i class="bi bi-person-plus" style="color:var(--blue)"></i>
                    <span>Add User</span>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="manage_courses.php" class="quick-action">
                    <i class="bi bi-book" style="color:var(--yellow)"></i>
                    <span>Add Course</span>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="manage_notifications.php" class="quick-action">
                    <i class="bi bi-megaphone" style="color:var(--accent)"></i>
                    <span>Send Notification</span>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="reports.php" class="quick-action">
                    <i class="bi bi-bar-chart-line" style="color:var(--green)"></i>
                    <span>View Reports</span>
                </a>
            </div>
        </div>

        <!-- ── Row 1: Recent Users + System Overview ── -->
        <div class="row g-4 mb-4">

            <!-- Recent Users -->
            <div class="col-lg-7">
                <div class="section-card">
                    <div class="section-header">
                        <h6>
                            <i class="bi bi-people" style="color:var(--blue)"></i>
                            Recent Users
                        </h6>
                        <a href="manage_users.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($recent_users) > 0): ?>
                        <?php while ($user = mysqli_fetch_assoc($recent_users)): ?>
                            <?php
                                $role_colors = [
                                    'admin'    => 'bg-red',
                                    'lecturer' => 'bg-yellow',
                                    'student'  => 'bg-green'
                                ];
                                $role_color = $role_colors[$user['role']] ?? 'bg-blue';
                            ?>
                            <div class="list-item">
                                <img src="../uploads/profiles/<?= htmlspecialchars($user['profile_picture']) ?>"
                                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($user['name']) ?>&background=random&color=fff&size=38'"
                                     class="user-avatar" alt="User">
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($user['name']) ?>
                                    </div>
                                    <div class="item-sub text-truncate">
                                        <?= htmlspecialchars($user['email']) ?>
                                    </div>
                                </div>
                                <div class="item-right">
                                    <span class="badge-glass <?= $role_color ?>">
                                        <?= ucfirst($user['role']) ?>
                                    </span>
                                    <div class="item-sub mt-1">
                                        <?= date('d M Y', strtotime($user['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p>No users found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Overview Chart -->
            <div class="col-lg-5">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h6><i class="bi bi-bar-chart-line" style="color:var(--accent)"></i> System Overview</h6>
                    </div>
                    <div class="chart-bar-wrap">

                        <!-- Students -->
                        <div class="chart-row">
                            <div class="chart-label">
                                <span>Students</span>
                                <span><?= $total_students ?></span>
                            </div>
                            <div class="chart-bar">
                                <div class="chart-fill"
                                     style="width:<?= $total_users > 0 ? round(($total_students/$total_users)*100) : 0 ?>%;
                                            background:var(--green);">
                                </div>
                            </div>
                        </div>

                        <!-- Lecturers -->
                        <div class="chart-row">
                            <div class="chart-label">
                                <span>Lecturers</span>
                                <span><?= $total_lecturers ?></span>
                            </div>
                            <div class="chart-bar">
                                <div class="chart-fill"
                                     style="width:<?= $total_users > 0 ? round(($total_lecturers/$total_users)*100) : 0 ?>%;
                                            background:var(--yellow);">
                                </div>
                            </div>
                        </div>

                        <!-- Courses -->
                        <div class="chart-row">
                            <div class="chart-label">
                                <span>Active Courses</span>
                                <span><?= $total_courses ?></span>
                            </div>
                            <div class="chart-bar">
                                <div class="chart-fill"
                                     style="width:<?= min($total_courses * 10, 100) ?>%;
                                            background:var(--purple);">
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="chart-row">
                            <div class="chart-label">
                                <span>Notes Uploaded</span>
                                <span><?= $total_notes ?></span>
                            </div>
                            <div class="chart-bar">
                                <div class="chart-fill"
                                     style="width:<?= min($total_notes * 8, 100) ?>%;
                                            background:var(--blue);">
                                </div>
                            </div>
                        </div>

                        <!-- Assignments -->
                        <div class="chart-row">
                            <div class="chart-label">
                                <span>Assignments</span>
                                <span><?= $total_assignments ?></span>
                            </div>
                            <div class="chart-bar">
                                <div class="chart-fill"
                                     style="width:<?= min($total_assignments * 8, 100) ?>%;
                                            background:var(--yellow);">
                                </div>
                            </div>
                        </div>

                        <!-- Submissions -->
                        <div class="chart-row">
                            <div class="chart-label">
                                <span>Submissions</span>
                                <span><?= $total_submissions ?></span>
                            </div>
                            <div class="chart-bar">
                                <div class="chart-fill"
                                     style="width:<?= min($total_submissions * 5, 100) ?>%;
                                            background:var(--green);">
                                </div>
                            </div>
                        </div>

                        <!-- Pending Grading -->
                        <div class="chart-row">
                            <div class="chart-label">
                                <span>Pending Grading</span>
                                <span style="color:var(--accent)"><?= $pending_grading ?></span>
                            </div>
                            <div class="chart-bar">
                                <div class="chart-fill"
                                     style="width:<?= $total_submissions > 0 ? round(($pending_grading/$total_submissions)*100) : 0 ?>%;
                                            background:var(--accent);">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- ── Row 2: Courses + Activity Log ── -->
        <div class="row g-4 mb-4">

            <!-- All Courses -->
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-book" style="color:var(--yellow)"></i> Active Courses</h6>
                        <a href="manage_courses.php">Manage All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="p-3">
                        <?php if (mysqli_num_rows($all_courses) > 0): ?>
                            <div class="row g-3">
                                <?php while ($course = mysqli_fetch_assoc($all_courses)): ?>
                                    <div class="col-md-6">
                                        <div class="course-card">
                                            <div class="course-code-tag">
                                                <?= htmlspecialchars($course['course_code']) ?>
                                            </div>
                                            <div class="course-title">
                                                <?= htmlspecialchars($course['course_name']) ?>
                                            </div>
                                            <div class="course-lecturer-name">
                                                <i class="bi bi-person me-1"></i>
                                                <?= htmlspecialchars($course['lecturer_name']) ?>
                                            </div>
                                            <div class="course-stats">
                                                <span><i class="bi bi-people"></i><?= $course['students'] ?></span>
                                                <span><i class="bi bi-file-earmark-text"></i><?= $course['notes_count'] ?></span>
                                                <span><i class="bi bi-clipboard2"></i><?= $course['assign_count'] ?></span>
                                            </div>
                                            <div class="progress-thin">
                                                <div class="progress-fill-red"
                                                     style="width:<?= min($course['students'] * 8, 100) ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-book"></i>
                                <p>No courses yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Activity Log + Notifications -->
            <div class="col-lg-4">

                <!-- Activity Log -->
                <div class="section-card mb-4">
                    <div class="section-header">
                        <h6><i class="bi bi-clock-history" style="color:var(--purple)"></i> Activity Log</h6>
                    </div>
                    <?php if (mysqli_num_rows($recent_activity) > 0): ?>
                        <?php while ($log = mysqli_fetch_assoc($recent_activity)): ?>
                            <?php
                                $role_icon = [
                                    'admin'    => ['ii-red',    'bi-person-gear'],
                                    'lecturer' => ['ii-yellow', 'bi-person-video3'],
                                    'student'  => ['ii-green',  'bi-person-check'],
                                ];
                                $ri = $role_icon[$log['role']] ?? ['ii-blue', 'bi-person'];
                            ?>
                            <div class="list-item">
                                <div class="item-icon <?= $ri[0] ?>">
                                    <i class="bi <?= $ri[1] ?>"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($log['user_name']) ?>
                                    </div>
                                    <div class="item-sub text-truncate">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </div>
                                </div>
                                <div class="item-right">
                                    <div class="item-sub">
                                        <?= date('d M', strtotime($log['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-clock-history"></i>
                            <p>No activity yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Notifications -->
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-bell" style="color:var(--blue)"></i> Notifications</h6>
                        <a href="manage_notifications.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($recent_notifications) > 0): ?>
                        <?php while ($notif = mysqli_fetch_assoc($recent_notifications)): ?>
                            <div class="list-item">
                                <div class="item-icon ii-blue">
                                    <i class="bi bi-megaphone"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($notif['title']) ?>
                                    </div>
                                    <div class="item-sub">
                                        <span class="badge-glass bg-blue"><?= $notif['target_role'] ?></span>
                                    </div>
                                </div>
                                <div class="item-right">
                                    <div class="item-sub">
                                        <?= date('d M', strtotime($notif['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-bell-slash"></i>
                            <p>No notifications yet.</p>
                        </div>
                    <?php endif; ?>
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
</script>
</body>
</html>