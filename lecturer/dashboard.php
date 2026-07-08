<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('lecturer');

$lecturer_id   = $_SESSION['user_id'];
$lecturer_name = $_SESSION['user_name'];

// ── Fetch lecturer info ──────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$lecturer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// ── Count my courses ─────────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM courses WHERE lecturer_id = ? AND status = 'active'");
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$courses_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count my notes ───────────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM notes WHERE uploaded_by = ? AND status = 'active'");
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$notes_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count my assignments ─────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM assignments WHERE created_by = ? AND status = 'active'");
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$assignments_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count total submissions across my assignments ────────
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) as total FROM submissions s
     INNER JOIN assignments a ON s.assignment_id = a.id
     WHERE a.created_by = ?"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$submissions_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count pending grading (submitted but not graded) ─────
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) as total FROM submissions s
     INNER JOIN assignments a ON s.assignment_id = a.id
     LEFT JOIN grades g ON s.id = g.submission_id
     WHERE a.created_by = ? AND g.id IS NULL"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$pending_grading = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count total enrolled students in my courses ──────────
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(DISTINCT ce.student_id) as total
     FROM course_enrollments ce
     INNER JOIN courses c ON ce.course_id = c.id
     WHERE c.lecturer_id = ? AND ce.status = 'enrolled'"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$students_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count unread notifications ───────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) as total FROM notifications n
     LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
     WHERE (n.target_role = 'lecturer' OR n.target_role = 'all') AND nr.id IS NULL"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$notifications_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── My courses with stats ────────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT c.*,
            (SELECT COUNT(*) FROM course_enrollments WHERE course_id = c.id AND status = 'enrolled') AS students,
            (SELECT COUNT(*) FROM notes WHERE course_id = c.id AND status = 'active') AS notes_count,
            (SELECT COUNT(*) FROM assignments WHERE course_id = c.id AND status = 'active') AS assign_count
     FROM courses c
     WHERE c.lecturer_id = ? AND c.status = 'active'
     ORDER BY c.created_at DESC
     LIMIT 6"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$my_courses = mysqli_stmt_get_result($stmt);

// ── Recent submissions to grade ──────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT s.*, u.name AS student_name,
            a.title AS assignment_title,
            a.total_marks,
            c.course_code,
            g.marks_obtained,
            g.id AS graded
     FROM submissions s
     INNER JOIN users u ON s.student_id = u.id
     INNER JOIN assignments a ON s.assignment_id = a.id
     INNER JOIN courses c ON a.course_id = c.id
     LEFT JOIN grades g ON s.id = g.submission_id
     WHERE a.created_by = ?
     ORDER BY s.submitted_at DESC
     LIMIT 6"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$recent_submissions = mysqli_stmt_get_result($stmt);

// ── Recent notes I uploaded ──────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT n.*, c.course_name, c.course_code
     FROM notes n
     INNER JOIN courses c ON n.course_id = c.id
     WHERE n.uploaded_by = ?
     AND n.status = 'active'
     ORDER BY n.created_at DESC
     LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$recent_notes = mysqli_stmt_get_result($stmt);

// ── Upcoming assignment deadlines ────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT a.*, c.course_name, c.course_code,
            (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) AS submission_count,
            TIMESTAMPDIFF(HOUR, NOW(), a.due_date) AS hours_left
     FROM assignments a
     INNER JOIN courses c ON a.course_id = c.id
     WHERE a.created_by = ?
     AND a.status = 'active'
     AND a.due_date >= NOW()
     ORDER BY a.due_date ASC
     LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$upcoming_assignments = mysqli_stmt_get_result($stmt);

// ── Recent notifications ─────────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT n.*, u.name AS sender_name,
            nr.id AS is_read
     FROM notifications n
     INNER JOIN users u ON n.sent_by = u.id
     LEFT JOIN notification_reads nr ON n.id = nr.notification_id
         AND nr.user_id = ?
     WHERE (n.target_role = 'lecturer' OR n.target_role = 'all')
     ORDER BY n.created_at DESC
     LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $lecturer_id);
mysqli_stmt_execute($stmt);
$recent_notifications = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard - OnlineLMS</title>
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
            background: #ffd93d;
            color: #111111;
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 0.72rem;
            font-weight: 700;
            box-shadow: 0 1px 0 rgba(255,255,255,0.12) inset;
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

        /* ── Welcome Banner ── */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(255,217,61,0.1), rgba(255,165,0,0.05));
            border: 1px solid rgba(255,217,61,0.2);
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

        .btn-yellow {
            background: var(--accent);
            color: #1a1a2e;
        }

        .btn-yellow:hover {
            background: #ffcd00;
            color: #1a1a2e;
            transform: translateY(-1px);
        }

        .btn-outline-yellow {
            background: rgba(255,217,61,0.1);
            border: 1px solid rgba(255,217,61,0.3);
            color: var(--accent);
        }

        .btn-outline-yellow:hover {
            background: rgba(255,217,61,0.2);
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
        .si-red    { background: rgba(255,107,107,0.15); color: var(--red);    }
        .si-purple { background: rgba(180,143,252,0.15); color: var(--purple); }
        .si-teal   { background: rgba(32,201,151,0.15);  color: #20c997;       }

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

        .ii-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); }
        .ii-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }
        .ii-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .ii-red    { background: rgba(255,107,107,0.15); color: var(--red);    }
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

        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); border: 1px solid rgba(255,217,61,0.3);  }
        .bg-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   border: 1px solid rgba(0,212,255,0.3);   }
        .bg-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .bg-red    { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3); }
        .bg-purple { background: rgba(180,143,252,0.15); color: var(--purple); border: 1px solid rgba(180,143,252,0.3); }

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
            background: rgba(255,217,61,0.12);
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
            margin-bottom: 10px;
            color: var(--text);
        }

        .course-stats {
            display: flex;
            gap: 12px;
            margin-bottom: 10px;
        }

        .course-stats span {
            color: var(--muted);
            font-size: 0.73rem;
        }

        .course-stats span i { margin-right: 3px; }

        .progress-thin {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
        }

        .progress-fill-yellow {
            height: 100%;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--accent), #ffa500);
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
            font-size: 0.8rem;
            font-weight: 600;
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
        <span>Learning Management System</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_picture']) ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($lecturer_name) ?>&background=ffd93d&color=1a1a2e&size=46'"
             alt="Profile">
        <div>
            <div class="name"><?= htmlspecialchars($lecturer_name) ?></div>
            <span class="role-badge">Lecturer</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php" class="active">
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
                <h6>Lecturer Dashboard</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="upload_notes.php" class="topbar-btn primary d-none d-md-flex">
                <i class="bi bi-cloud-upload"></i> Upload Notes
            </a>
            <a href="create_assignment.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-plus-circle"></i> New Assignment
            </a>
            <a href="notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($notifications_count > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" style="text-decoration:none;">
                <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_picture']) ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($lecturer_name) ?>&background=ffd93d&color=1a1a2e&size=36'"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
            </a>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div>
                <h4>Welcome, <?= htmlspecialchars(explode(' ', $lecturer_name)[0]) ?>! 👨‍🏫</h4>
                <p>
                    You have
                    <strong style="color:var(--red)"><?= $pending_grading ?> submission(s) to grade</strong> and
                    <strong style="color:var(--accent)"><?= $students_count ?> enrolled student(s)</strong>
                    across your courses.
                </p>
            </div>
            <div class="welcome-actions">
                <a href="upload_notes.php" class="btn-action btn-yellow">
                    <i class="bi bi-cloud-upload"></i> Upload Notes
                </a>
                <a href="create_assignment.php" class="btn-action btn-outline-yellow">
                    <i class="bi bi-plus-circle"></i> New Assignment
                </a>
            </div>
        </div>

        <!-- ── Stat Cards ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-2">
                <a href="courses.php" class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_count ?></h3>
                        <p>Courses</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="view_notes.php" class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $notes_count ?></h3>
                        <p>Notes</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="view_assignments.php" class="stat-card">
                    <div class="stat-icon si-purple"><i class="bi bi-clipboard2"></i></div>
                    <div class="stat-info">
                        <h3><?= $assignments_count ?></h3>
                        <p>Assignments</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="grade_submissions.php" class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-inbox"></i></div>
                    <div class="stat-info">
                        <h3><?= $submissions_count ?></h3>
                        <p>Submissions</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="grade_submissions.php" class="stat-card">
                    <div class="stat-icon si-red"><i class="bi bi-hourglass-split"></i></div>
                    <div class="stat-info">
                        <h3><?= $pending_grading ?></h3>
                        <p>To Grade</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="courses.php" class="stat-card">
                    <div class="stat-icon si-teal"><i class="bi bi-people"></i></div>
                    <div class="stat-info">
                        <h3><?= $students_count ?></h3>
                        <p>Students</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- ── Quick Actions ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <a href="upload_notes.php" class="quick-action">
                    <i class="bi bi-cloud-upload" style="color:var(--blue)"></i>
                    <span>Upload Notes</span>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="create_assignment.php" class="quick-action">
                    <i class="bi bi-plus-circle" style="color:var(--accent)"></i>
                    <span>New Assignment</span>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="grade_submissions.php" class="quick-action">
                    <i class="bi bi-patch-check" style="color:var(--green)"></i>
                    <span>Grade Submissions</span>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="notifications.php" class="quick-action">
                    <i class="bi bi-megaphone" style="color:var(--red)"></i>
                    <span>Send Notification</span>
                </a>
            </div>
        </div>

        <!-- ── Row 1: Recent Submissions + Upcoming Assignments ── -->
        <div class="row g-4 mb-4">

            <!-- Recent Submissions -->
            <div class="col-lg-7">
                <div class="section-card">
                    <div class="section-header">
                        <h6>
                            <i class="bi bi-inbox" style="color:var(--green)"></i>
                            Recent Submissions
                            <?php if ($pending_grading > 0): ?>
                                <span class="badge-glass bg-red ms-1"><?= $pending_grading ?> to grade</span>
                            <?php endif; ?>
                        </h6>
                        <a href="grade_submissions.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($recent_submissions) > 0): ?>
                        <?php while ($sub = mysqli_fetch_assoc($recent_submissions)): ?>
                            <div class="list-item">
                                <div class="item-icon ii-green">
                                    <i class="bi bi-file-earmark-check"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($sub['student_name']) ?>
                                    </div>
                                    <div class="item-sub">
                                        <span class="badge-glass bg-blue me-1"><?= htmlspecialchars($sub['course_code']) ?></span>
                                        <?= htmlspecialchars(substr($sub['assignment_title'], 0, 30)) ?>...
                                    </div>
                                </div>
                                <div class="item-right">
                                    <?php if ($sub['graded']): ?>
                                        <span class="badge-glass bg-green">
                                            <?= $sub['marks_obtained'] ?>/<?= $sub['total_marks'] ?>
                                        </span>
                                    <?php else: ?>
                                        <a href="grade_submissions.php?id=<?= $sub['id'] ?>"
                                           class="badge-glass bg-yellow"
                                           style="text-decoration:none;">
                                            <i class="bi bi-pencil me-1"></i>Grade
                                        </a>
                                    <?php endif; ?>
                                    <div class="item-sub mt-1">
                                        <?= date('d M', strtotime($sub['submitted_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>No submissions yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upcoming Deadlines -->
            <div class="col-lg-5">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-alarm" style="color:var(--red)"></i> Upcoming Deadlines</h6>
                        <a href="view_assignments.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($upcoming_assignments) > 0): ?>
                        <?php while ($assign = mysqli_fetch_assoc($upcoming_assignments)): ?>
                            <?php
                                $h = $assign['hours_left'];
                                if ($h <= 24)     { $urg = 'bg-red';    $urg_txt = 'Due Today'; }
                                elseif ($h <= 72) { $urg = 'bg-yellow'; $urg_txt = 'Due Soon';  }
                                else              { $urg = 'bg-blue';   $urg_txt = date('d M', strtotime($assign['due_date'])); }
                            ?>
                            <div class="list-item">
                                <div class="item-icon ii-yellow">
                                    <i class="bi bi-clipboard2"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($assign['title']) ?>
                                    </div>
                                    <div class="item-sub">
                                        <span class="badge-glass bg-blue me-1"><?= htmlspecialchars($assign['course_code']) ?></span>
                                        <?= $assign['submission_count'] ?> submitted
                                    </div>
                                </div>
                                <div class="item-right">
                                    <span class="badge-glass <?= $urg ?>"><?= $urg_txt ?></span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-check"></i>
                            <p>No upcoming deadlines.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Row 2: My Courses + Recent Notes + Notifications ── -->
        <div class="row g-4 mb-4">

            <!-- My Courses -->
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-book" style="color:var(--accent)"></i> My Courses</h6>
                        <a href="courses.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="p-3">
                        <?php if (mysqli_num_rows($my_courses) > 0): ?>
                            <div class="row g-3">
                                <?php while ($course = mysqli_fetch_assoc($my_courses)): ?>
                                    <div class="col-md-6">
                                        <div class="course-card">
                                            <div class="course-code-tag">
                                                <?= htmlspecialchars($course['course_code']) ?>
                                            </div>
                                            <div class="course-title">
                                                <?= htmlspecialchars($course['course_name']) ?>
                                            </div>
                                            <div class="course-stats">
                                                <span><i class="bi bi-people"></i><?= $course['students'] ?> Students</span>
                                                <span><i class="bi bi-file-earmark-text"></i><?= $course['notes_count'] ?> Notes</span>
                                                <span><i class="bi bi-clipboard2"></i><?= $course['assign_count'] ?> Tasks</span>
                                            </div>
                                            <div class="progress-thin">
                                                <div class="progress-fill-yellow"
                                                     style="width:<?= min(($course['students'] * 5), 100) ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-book"></i>
                                <p>No courses assigned yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Recent Notes + Notifications -->
            <div class="col-lg-4">

                <!-- Recent Notes -->
                <div class="section-card mb-4">
                    <div class="section-header">
                        <h6><i class="bi bi-file-earmark-text" style="color:var(--blue)"></i> Recent Notes</h6>
                        <a href="view_notes.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($recent_notes) > 0): ?>
                        <?php while ($note = mysqli_fetch_assoc($recent_notes)): ?>
                            <div class="list-item">
                                <div class="item-icon ii-blue">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($note['title']) ?>
                                    </div>
                                    <div class="item-sub">
                                        <span class="badge-glass bg-yellow"><?= htmlspecialchars($note['course_code']) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-file-earmark-text"></i>
                            <p>No notes uploaded yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Notifications -->
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-bell" style="color:var(--blue)"></i> Notifications</h6>
                        <a href="notifications.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($recent_notifications) > 0): ?>
                        <?php while ($notif = mysqli_fetch_assoc($recent_notifications)): ?>
                            <div class="list-item"
                                 style="<?= !$notif['is_read'] ? 'border-left:3px solid var(--accent)' : '' ?>">
                                <div class="item-icon ii-yellow">
                                    <?php
                                        $icons = [
                                            'note'         => 'bi-file-earmark-text',
                                            'assignment'   => 'bi-clipboard2',
                                            'grade'        => 'bi-star',
                                            'announcement' => 'bi-megaphone',
                                            'general'      => 'bi-bell'
                                        ];
                                        $icon = $icons[$notif['type']] ?? 'bi-bell';
                                    ?>
                                    <i class="bi <?= $icon ?>"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($notif['title']) ?>
                                        <?php if (!$notif['is_read']): ?>
                                            <span style="width:6px;height:6px;background:var(--accent);
                                                         border-radius:50%;display:inline-block;margin-left:4px;">
                                            </span>
                                        <?php endif; ?>
                                    </div>
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