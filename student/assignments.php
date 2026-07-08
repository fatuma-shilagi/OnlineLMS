<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('student');

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];

// ── Fetch student info ───────────────────────────────────
$student_query = mysqli_query($conn, "SELECT * FROM users WHERE id = '$student_id'");
$student       = mysqli_fetch_assoc($student_query);

// ── Filters: search + course + status ─────────────────────
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_safe   = mysqli_real_escape_string($conn, $search);
$search_clause = '';
if ($search !== '') {
    $search_clause = "AND (a.title LIKE '%$search_safe%'
                       OR c.course_name LIKE '%$search_safe%'
                       OR c.course_code LIKE '%$search_safe%')";
}

$filter_course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$course_clause     = '';
if ($filter_course_id > 0) {
    $course_clause = "AND a.course_id = '$filter_course_id'";
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$valid_statuses = ['all', 'pending', 'overdue', 'submitted', 'graded'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

// ── Count totals (unfiltered, for stat cards & sidebar) ──
$courses_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM course_enrollments
                          WHERE student_id = '$student_id' AND status = 'enrolled'")
)['total'];

$notes_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notes n
                          INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'
                          AND n.status = 'active'")
)['total'];

$pending_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM assignments a
                          INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
                          LEFT JOIN submissions s ON a.id = s.assignment_id
                              AND s.student_id = '$student_id'
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'
                          AND a.status = 'active'
                          AND a.due_date >= NOW()
                          AND s.id IS NULL")
)['total'];

$overdue_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM assignments a
                          INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
                          LEFT JOIN submissions s ON a.id = s.assignment_id
                              AND s.student_id = '$student_id'
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'
                          AND a.status = 'active'
                          AND a.due_date < NOW()
                          AND s.id IS NULL")
)['total'];

$submitted_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM submissions
                          WHERE student_id = '$student_id'")
)['total'];

$graded_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM grades
                          WHERE student_id = '$student_id'")
)['total'];

$assignments_count = $pending_count; // used by sidebar badge, mirrors dashboard.php

$avg_grade = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT AVG((marks_obtained / total_marks) * 100) as avg
                          FROM grades WHERE student_id = '$student_id'")
)['avg'];
$avg_grade = $avg_grade ? round($avg_grade, 1) : 0;

$notifications_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications n
                          LEFT JOIN notification_reads nr ON n.id = nr.notification_id
                              AND nr.user_id = '$student_id'
                          WHERE (n.target_role = 'student' OR n.target_role = 'all')
                          AND nr.id IS NULL")
)['total'];

// ── Courses dropdown (for filter) ─────────────────────────
$course_options = mysqli_query($conn,
    "SELECT DISTINCT c.id, c.course_code, c.course_name
     FROM courses c
     INNER JOIN course_enrollments ce ON c.id = ce.course_id
     WHERE ce.student_id = '$student_id'
     AND ce.status = 'enrolled'
     ORDER BY c.course_name ASC"
);

$filter_course = null;
if ($filter_course_id > 0) {
    $filter_course = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT course_name, course_code FROM courses WHERE id = '$filter_course_id'"
    ));
}

// ── Assignments list (course/search filtered, status computed in PHP) ──
// NOTE: assumes `grades` has an `assignment_id` column linking back to the
// assignment. Adjust the JOIN below if your schema stores grades differently.
$assignments_raw = mysqli_query($conn,
    "SELECT a.*, c.course_name, c.course_code,
            s.id AS submission_id, s.created_at AS submission_date,
            g.marks_obtained, g.total_marks AS grade_total_marks, g.feedback,
            TIMESTAMPDIFF(HOUR, NOW(), a.due_date) AS hours_left
     FROM assignments a
     INNER JOIN courses c ON a.course_id = c.id
     INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
     LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = '$student_id'
     LEFT JOIN grades g ON a.id = g.assignment_id AND g.student_id = '$student_id'
     WHERE ce.student_id = '$student_id'
     AND ce.status = 'enrolled'
     AND a.status = 'active'
     $course_clause
     $search_clause
     ORDER BY a.due_date ASC"
);

$assignments = [];
while ($row = mysqli_fetch_assoc($assignments_raw)) {
    if ($row['submission_id']) {
        $row['_status'] = ($row['marks_obtained'] !== null) ? 'graded' : 'submitted';
    } else {
        $row['_status'] = ($row['hours_left'] < 0) ? 'overdue' : 'pending';
    }
    if ($status_filter !== 'all' && $row['_status'] !== $status_filter) {
        continue;
    }
    $assignments[] = $row;
}
$total_results = count($assignments);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments - OnlineLMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --bg-main:    #0f0f1a;
            --bg-card:    rgba(255,255,255,0.04);
            --bg-hover:   rgba(255,255,255,0.07);
            --border:     rgba(255,255,255,0.08);
            --text:       #e8e8f0;
            --muted:      rgba(255,255,255,0.45);
            --accent:     #00d4ff;
            --green:      #6bcb77;
            --yellow:     #ffd93d;
            --red:        #ff6b6b;
            --purple:     #b48ffc;
            --sidebar-w:  260px;
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
            padding: 24px 20px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-brand h5 {
            color: var(--accent);
            font-weight: 800;
            font-size: 1.2rem;
            margin: 0;
        }

        .sidebar-brand span {
            color: var(--muted);
            font-size: 0.75rem;
        }

        .sidebar-profile {
            padding: 20px;
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
            font-size: 0.9rem;
            color: var(--text);
        }

        .sidebar-profile .role-badge {
            background: rgba(0,212,255,0.15);
            color: var(--accent);
            border: 1px solid rgba(0,212,255,0.3);
            border-radius: 20px;
            padding: 1px 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .sidebar-nav {
            flex: 1;
            padding: 15px 0;
            overflow-y: auto;
        }

        .nav-section {
            padding: 8px 20px 4px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--muted);
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 20px;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.9rem;
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

        .sidebar-nav a.active {
            color: var(--accent);
        }

        .sidebar-nav a i {
            font-size: 1.05rem;
            width: 20px;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--red);
            color: white;
            border-radius: 20px;
            padding: 1px 8px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .sidebar-footer {
            padding: 15px 20px;
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

        /* ── Main Content ── */
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
            padding: 14px 28px;
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
            font-size: 1.05rem;
            color: var(--text);
            margin: 0;
        }

        .topbar-left p {
            color: var(--muted);
            font-size: 0.8rem;
            margin: 0;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .notif-btn {
            position: relative;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
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
        .page-body {
            padding: 28px;
            flex: 1;
        }

        /* ── Stat Cards ── */
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s;
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .stat-icon.blue   { background: rgba(0,212,255,0.15);  color: var(--accent); }
        .stat-icon.green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .stat-icon.yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .stat-icon.red    { background: rgba(255,107,107,0.15); color: var(--red);    }
        .stat-icon.purple { background: rgba(180,143,252,0.15); color: var(--purple); }

        .stat-info h3 {
            font-size: 1.7rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-info p {
            color: var(--muted);
            font-size: 0.8rem;
            margin: 0;
        }

        /* ── Page Header ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }

        .page-header h4 {
            font-weight: 800;
            font-size: 1.3rem;
            margin-bottom: 4px;
        }

        .page-header p {
            color: var(--muted);
            font-size: 0.875rem;
            margin: 0;
        }

        .filter-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ── Search / Select Inputs ── */
        .search-bar {
            position: relative;
            min-width: 220px;
        }

        .search-bar input {
            width: 100%;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 16px 10px 40px;
            color: var(--text);
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-bar input::placeholder { color: var(--muted); }
        .search-bar input:focus { border-color: var(--accent); }

        .search-bar i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 0.95rem;
        }

        .search-bar select {
            width: 100%;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 14px;
            color: var(--text);
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
            min-width: 180px;
        }

        .search-bar select:focus { border-color: var(--accent); }

        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            text-decoration: none;
            font-size: 0.85rem;
        }

        .search-clear:hover { color: var(--red); }

        /* ── Status Tabs ── */
        .status-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .status-tab {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--muted);
            transition: all 0.2s;
        }

        .status-tab:hover { color: var(--text); border-color: rgba(255,255,255,0.2); }

        .status-tab.active {
            background: rgba(0,212,255,0.15);
            color: var(--accent);
            border-color: rgba(0,212,255,0.35);
        }

        /* ── Filter Banner ── */
        .filter-banner {
            background: rgba(0,212,255,0.08);
            border: 1px solid rgba(0,212,255,0.25);
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 0.85rem;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-banner a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .filter-banner a:hover { text-decoration: underline; }

        /* ── Assignment Rows ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .assign-row {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: background 0.2s;
            flex-wrap: wrap;
        }

        .assign-row:last-child { border-bottom: none; }
        .assign-row:hover { background: var(--bg-hover); }

        .item-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .item-icon.pending   { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .item-icon.overdue   { background: rgba(255,107,107,0.15); color: var(--red);    }
        .item-icon.submitted { background: rgba(0,212,255,0.15);   color: var(--accent); }
        .item-icon.graded    { background: rgba(107,203,119,0.15); color: var(--green);  }

        .assign-info {
            flex: 1;
            min-width: 220px;
        }

        .assign-title {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
        }

        .assign-desc {
            font-size: 0.78rem;
            color: var(--muted);
            margin-bottom: 6px;
            line-height: 1.4;
        }

        .assign-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.78rem;
            color: var(--muted);
        }

        .assign-meta i { margin-right: 3px; }

        .badge-glass {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .badge-blue   { background: rgba(0,212,255,0.15);  color: var(--accent); border: 1px solid rgba(0,212,255,0.3);  }
        .badge-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .badge-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);  }
        .badge-red    { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3);  }

        .assign-right {
            margin-left: auto;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }

        .grade-pill {
            font-size: 1rem;
            font-weight: 800;
        }

        .btn-submit {
            background: rgba(255,217,61,0.12);
            color: var(--yellow);
            border: 1px solid rgba(255,217,61,0.3);
            border-radius: 10px;
            padding: 8px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-submit:hover { background: rgba(255,217,61,0.22); color: var(--yellow); }

        .btn-submit.resubmit {
            background: rgba(0,212,255,0.12);
            color: var(--accent);
            border-color: rgba(0,212,255,0.3);
        }

        .btn-submit.resubmit:hover { background: rgba(0,212,255,0.22); color: var(--accent); }

        /* ── Empty State ── */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 2.6rem;
            margin-bottom: 12px;
            opacity: 0.4;
            display: block;
        }

        .empty-state p { font-size: 0.9rem; margin: 0; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .hamburger {
                display: block;
            }
            .page-body {
                padding: 16px;
            }
            .filter-controls {
                width: 100%;
            }
            .search-bar {
                min-width: 100%;
                width: 100%;
            }
            .assign-right {
                margin-left: 0;
                width: 100%;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Learning Management System</span>
    </div>

    <!-- Profile -->
    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture']) ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=46'"
             alt="Profile">
        <div>
            <div class="name"><?= htmlspecialchars($student_name) ?></div>
            <span class="role-badge">Student</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <div class="nav-section">Main Menu</div>
        <a href="dashboard.php">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>
        <a href="courses.php">
            <i class="bi bi-book"></i> My Courses
            <?php if ($courses_count > 0): ?>
                <span class="nav-badge"><?= $courses_count ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Learning</div>
        <a href="notes.php">
            <i class="bi bi-file-earmark-text"></i> Notes & Resources
            <?php if ($notes_count > 0): ?>
                <span class="nav-badge" style="background:var(--accent)"><?= $notes_count ?></span>
            <?php endif; ?>
        </a>
        <a href="assignments.php" class="active">
            <i class="bi bi-clipboard2-check"></i> Assignments
            <?php if ($assignments_count > 0): ?>
                <span class="nav-badge"><?= $assignments_count ?></span>
            <?php endif; ?>
        </a>
        <a href="submit_assignment.php">
            <i class="bi bi-upload"></i> Submit Assignment
        </a>

        <div class="nav-section">Communication</div>
        <a href="notifications.php">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($notifications_count > 0): ?>
                <span class="nav-badge"><?= $notifications_count ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Account</div>
        <a href="profile.php">
            <i class="bi bi-person-circle"></i> My Profile
        </a>
    </nav>

    <!-- Logout -->
    <div class="sidebar-footer">
    <a href="../logout.php"
       onclick="return confirm('Are you sure you want to logout?')"
       style="display:flex; align-items:center; gap:10px;
              color:var(--red); text-decoration:none;
              font-size:0.875rem; font-weight:500; padding:8px 0;">
        <i class="bi bi-box-arrow-left"></i> Logout
    </a>
</div>
</aside>

<!-- ══════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════ -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger" id="hamburger">
                <i class="bi bi-list"></i>
            </button>
            <div class="topbar-left">
                <h6>Assignments</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($notifications_count > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" style="text-decoration:none;">
                <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture']) ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=36'"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
            </a>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- ── Stat Cards Row ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon yellow"><i class="bi bi-clipboard2"></i></div>
                    <div class="stat-info">
                        <h3><?= $pending_count ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon red"><i class="bi bi-exclamation-circle"></i></div>
                    <div class="stat-info">
                        <h3><?= $overdue_count ?></h3>
                        <p>Overdue</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-check2-circle"></i></div>
                    <div class="stat-info">
                        <h3><?= $submitted_count ?></h3>
                        <p>Submitted</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-star"></i></div>
                    <div class="stat-info">
                        <h3><?= $avg_grade ?>%</h3>
                        <p>Average Grade</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Page Header / Filters ── -->
        <div class="page-header">
            <div>
                <h4>Assignments</h4>
                <p>
                    <?= $total_results ?> assignment<?= $total_results == 1 ? '' : 's' ?>
                    <?= ($search !== '' || $filter_course_id > 0 || $status_filter !== 'all') ? 'matching your filters' : 'across your courses' ?>
                </p>
            </div>
            <form action="assignments.php" method="GET" class="filter-controls">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                <div class="search-bar">
                    <select name="course_id" onchange="this.form.submit()">
                        <option value="0">All Courses</option>
                        <?php while ($opt = mysqli_fetch_assoc($course_options)): ?>
                            <option value="<?= $opt['id'] ?>" <?= $filter_course_id == $opt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['course_code']) ?> — <?= htmlspecialchars($opt['course_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="search-bar">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search assignments..."
                           value="<?= htmlspecialchars($search) ?>">
                    <?php if ($search !== ''): ?>
                        <a href="assignments.php?status=<?= htmlspecialchars($status_filter) ?><?= $filter_course_id > 0 ? '&course_id=' . $filter_course_id : '' ?>"
                           class="search-clear">
                            <i class="bi bi-x-circle-fill"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── Status Tabs ── -->
        <?php
            $qs_base = [];
            if ($search !== '') $qs_base['search'] = $search;
            if ($filter_course_id > 0) $qs_base['course_id'] = $filter_course_id;
            function status_tab_url($status, $qs_base) {
                $qs = $qs_base;
                $qs['status'] = $status;
                return 'assignments.php?' . http_build_query($qs);
            }
        ?>
        <div class="status-tabs">
            <a href="<?= status_tab_url('all', $qs_base) ?>" class="status-tab <?= $status_filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="<?= status_tab_url('pending', $qs_base) ?>" class="status-tab <?= $status_filter === 'pending' ? 'active' : '' ?>">Pending</a>
            <a href="<?= status_tab_url('overdue', $qs_base) ?>" class="status-tab <?= $status_filter === 'overdue' ? 'active' : '' ?>">Overdue</a>
            <a href="<?= status_tab_url('submitted', $qs_base) ?>" class="status-tab <?= $status_filter === 'submitted' ? 'active' : '' ?>">Submitted</a>
            <a href="<?= status_tab_url('graded', $qs_base) ?>" class="status-tab <?= $status_filter === 'graded' ? 'active' : '' ?>">Graded</a>
        </div>

        <!-- ── Active Course Filter Banner ── -->
        <?php if ($filter_course_id > 0 && $filter_course): ?>
            <div class="filter-banner">
                <span>
                    <i class="bi bi-funnel-fill me-2"></i>
                    Showing assignments for
                    <strong><?= htmlspecialchars($filter_course['course_code']) ?> — <?= htmlspecialchars($filter_course['course_name']) ?></strong>
                </span>
                <a href="<?= status_tab_url($status_filter, ['search' => $search]) ?>">
                    Clear course filter <i class="bi bi-x"></i>
                </a>
            </div>
        <?php endif; ?>

        <!-- ── Assignments List ── -->
        <?php if ($total_results > 0): ?>
            <div class="section-card">
                <?php foreach ($assignments as $assign): ?>
                    <?php
                        $status = $assign['_status'];
                        $status_labels = [
                            'pending'   => ['Pending', 'badge-yellow'],
                            'overdue'   => ['Overdue', 'badge-red'],
                            'submitted' => ['Submitted', 'badge-blue'],
                            'graded'    => ['Graded', 'badge-green'],
                        ];
                        [$status_label, $status_badge] = $status_labels[$status];

                        $icon_map = [
                            'pending'   => 'bi-clipboard2',
                            'overdue'   => 'bi-exclamation-circle',
                            'submitted' => 'bi-check2-circle',
                            'graded'    => 'bi-star-fill',
                        ];

                        $pct = null;
                        $pct_badge = 'badge-green';
                        if ($status === 'graded' && $assign['grade_total_marks'] > 0) {
                            $pct = round(($assign['marks_obtained'] / $assign['grade_total_marks']) * 100, 1);
                            if ($pct < 50) $pct_badge = 'badge-red';
                            elseif ($pct < 70) $pct_badge = 'badge-yellow';
                        }
                    ?>
                    <div class="assign-row">
                        <div class="item-icon <?= $status ?>">
                            <i class="bi <?= $icon_map[$status] ?>"></i>
                        </div>
                        <div class="assign-info">
                            <div class="assign-title"><?= htmlspecialchars($assign['title']) ?></div>
                            <?php if (!empty($assign['description'])): ?>
                                <div class="assign-desc">
                                    <?= htmlspecialchars(substr($assign['description'], 0, 100)) ?><?= strlen($assign['description']) > 100 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                            <div class="assign-meta">
                                <span class="badge-glass badge-blue"><?= htmlspecialchars($assign['course_code']) ?></span>
                                <span><i class="bi bi-bullseye"></i><?= $assign['total_marks'] ?> marks</span>
                                <span><i class="bi bi-calendar-event"></i>Due <?= date('d M Y, h:i A', strtotime($assign['due_date'])) ?></span>
                                <?php if ($assign['submission_date']): ?>
                                    <span><i class="bi bi-upload"></i>Submitted <?= date('d M Y', strtotime($assign['submission_date'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="assign-right">
                            <span class="badge-glass <?= $status_badge ?>"><?= $status_label ?></span>

                            <?php if ($status === 'graded' && $pct !== null): ?>
                                <span class="badge-glass <?= $pct_badge ?>">
                                    <?= $assign['marks_obtained'] ?>/<?= $assign['grade_total_marks'] ?> (<?= $pct ?>%)
                                </span>
                            <?php endif; ?>

                            <?php if ($status === 'pending' || $status === 'overdue'): ?>
                                <a href="submit_assignment.php?id=<?= $assign['id'] ?>" class="btn-submit">
                                    <i class="bi bi-upload me-1"></i>Submit
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="section-card">
                <div class="empty-state">
                    <i class="bi bi-clipboard2-check"></i>
                    <p>
                        <?php if ($search !== '' || $filter_course_id > 0 || $status_filter !== 'all'): ?>
                            No assignments found matching your filters.
                        <?php else: ?>
                            No assignments have been posted for your courses yet.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Sidebar overlay for mobile -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── Mobile sidebar toggle ─────────────────────────────
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