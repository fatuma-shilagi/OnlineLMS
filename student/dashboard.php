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

// ── Count enrolled courses ───────────────────────────────
$courses_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM course_enrollments
                          WHERE student_id = '$student_id' AND status = 'enrolled'")
)['total'];

// ── Count available notes (from enrolled courses) ────────
$notes_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notes n
                          INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'
                          AND n.status = 'active'")
)['total'];

// ── Count pending assignments ────────────────────────────
$assignments_count = mysqli_fetch_assoc(
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

// ── Count unread notifications ───────────────────────────
$notifications_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications n
                          LEFT JOIN notification_reads nr ON n.id = nr.notification_id
                              AND nr.user_id = '$student_id'
                          WHERE (n.target_role = 'student' OR n.target_role = 'all')
                          AND nr.id IS NULL")
)['total'];

// ── Count submitted assignments ──────────────────────────
$submitted_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM submissions
                          WHERE student_id = '$student_id'")
)['total'];

// ── Count graded assignments ─────────────────────────────
$graded_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM grades
                          WHERE student_id = '$student_id'")
)['total'];

// ── Recent notes (last 5) ────────────────────────────────
$recent_notes = mysqli_query($conn,
    "SELECT n.*, c.course_name, c.course_code, u.name AS lecturer_name
     FROM notes n
     INNER JOIN courses c ON n.course_id = c.id
     INNER JOIN users u ON n.uploaded_by = u.id
     INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
     WHERE ce.student_id = '$student_id'
     AND ce.status = 'enrolled'
     AND n.status = 'active'
     ORDER BY n.created_at DESC
     LIMIT 5"
);

// ── Upcoming assignments (next 5) ────────────────────────
$upcoming_assignments = mysqli_query($conn,
    "SELECT a.*, c.course_name, c.course_code,
            s.id AS submitted,
            TIMESTAMPDIFF(HOUR, NOW(), a.due_date) AS hours_left
     FROM assignments a
     INNER JOIN courses c ON a.course_id = c.id
     INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
     LEFT JOIN submissions s ON a.id = s.assignment_id
         AND s.student_id = '$student_id'
     WHERE ce.student_id = '$student_id'
     AND ce.status = 'enrolled'
     AND a.status = 'active'
     AND a.due_date >= NOW()
     ORDER BY a.due_date ASC
     LIMIT 5"
);

// ── Recent notifications (last 5) ────────────────────────
$recent_notifications = mysqli_query($conn,
    "SELECT n.*, u.name AS sender_name,
            nr.id AS is_read
     FROM notifications n
     INNER JOIN users u ON n.sent_by = u.id
     LEFT JOIN notification_reads nr ON n.id = nr.notification_id
         AND nr.user_id = '$student_id'
     WHERE (n.target_role = 'student' OR n.target_role = 'all')
     ORDER BY n.created_at DESC
     LIMIT 5"
);

// ── My enrolled courses ──────────────────────────────────
$my_courses = mysqli_query($conn,
    "SELECT c.*, u.name AS lecturer_name,
            (SELECT COUNT(*) FROM notes WHERE course_id = c.id AND status = 'active') AS notes_count,
            (SELECT COUNT(*) FROM assignments WHERE course_id = c.id AND status = 'active') AS assignments_count
     FROM courses c
     INNER JOIN course_enrollments ce ON c.id = ce.course_id
     INNER JOIN users u ON c.lecturer_id = u.id
     WHERE ce.student_id = '$student_id'
     AND ce.status = 'enrolled'
     ORDER BY c.course_name ASC
     LIMIT 6"
);

// ── Average grade ────────────────────────────────────────
$avg_grade = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT AVG((marks_obtained / total_marks) * 100) as avg
                          FROM grades WHERE student_id = '$student_id'")
)['avg'];
$avg_grade = $avg_grade ? round($avg_grade, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - OnlineLMS</title>
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
            background: #00d4ff;
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
            text-decoration: none;
            color: var(--text);
        }

        .stat-card:hover {
            background: var(--bg-hover);
            border-color: rgba(255,255,255,0.15);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            color: var(--text);
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
        .stat-icon.teal   { background: rgba(32,201,151,0.15);  color: #20c997;       }

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

        /* ── Section Cards ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .section-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-header h6 {
            font-weight: 700;
            font-size: 0.95rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-header a {
            color: var(--accent);
            font-size: 0.8rem;
            text-decoration: none;
        }

        .section-header a:hover { text-decoration: underline; }

        /* ── List Items ── */
        .list-item {
            padding: 14px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: background 0.2s;
        }

        .list-item:last-child { border-bottom: none; }
        .list-item:hover { background: var(--bg-hover); }

        .item-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .item-icon.pdf    { background: rgba(255,107,107,0.15); color: var(--red);    }
        .item-icon.assign { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .item-icon.notif  { background: rgba(0,212,255,0.15);   color: var(--accent); }

        .item-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 2px;
        }

        .item-sub {
            font-size: 0.75rem;
            color: var(--muted);
        }

        .item-right {
            margin-left: auto;
            text-align: right;
        }

        /* ── Badges ── */
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
        .badge-purple { background: rgba(180,143,252,0.15); color: var(--purple); border: 1px solid rgba(180,143,252,0.3); }

        /* ── Course Cards ── */
        .course-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            transition: all 0.3s;
            height: 100%;
        }

        .course-card:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .course-code {
            background: rgba(0,212,255,0.12);
            color: var(--accent);
            border-radius: 8px;
            padding: 3px 10px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 8px;
        }

        .course-name {
            font-weight: 700;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .course-lecturer {
            color: var(--muted);
            font-size: 0.78rem;
            margin-bottom: 12px;
        }

        .course-meta {
            display: flex;
            gap: 12px;
        }

        .course-meta span {
            color: var(--muted);
            font-size: 0.75rem;
        }

        .course-meta span i { margin-right: 3px; }

        /* ── Grade Arc ── */
        .grade-arc {
            text-align: center;
            padding: 20px;
        }

        .arc-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 6px solid var(--border);
            border-top-color: var(--accent);
            border-right-color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
        }

        .arc-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--accent);
        }

        /* ── Empty State ── */
        .empty-state {
            padding: 35px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 8px;
            opacity: 0.4;
        }

        .empty-state p { font-size: 0.85rem; margin: 0; }

        /* ── Progress Bar ── */
        .progress-thin {
            height: 5px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--accent), #0099cc);
        }

        /* ── Welcome Banner ── */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(0,212,255,0.12), rgba(0,153,204,0.06));
            border: 1px solid rgba(0,212,255,0.2);
            border-radius: 16px;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .welcome-banner h4 {
            font-weight: 800;
            font-size: 1.3rem;
            margin-bottom: 4px;
        }

        .welcome-banner p {
            color: var(--muted);
            font-size: 0.875rem;
            margin: 0;
        }

        .welcome-banner .emoji {
            font-size: 3rem;
        }

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
            .welcome-banner .emoji {
                display: none;
            }
            .page-body {
                padding: 16px;
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
        <a href="dashboard.php" class="active">
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
        <a href="assignments.php">
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
                <h6>Student Dashboard</h6>
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

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div>
                <h4>Welcome back, <?= htmlspecialchars(explode(' ', $student_name)[0]) ?>! 👋</h4>
                <p>You have
                    <strong style="color:var(--red)"><?= $assignments_count ?> pending assignment(s)</strong> and
                    <strong style="color:var(--accent)"><?= $notifications_count ?> unread notification(s)</strong>.
                </p>
            </div>
            <div class="emoji">🎓</div>
        </div>

        <!-- ── Stat Cards Row ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-2">
                <a href="courses.php" class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_count ?></h3>
                        <p>Courses</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="notes.php" class="stat-card">
                    <div class="stat-icon red"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $notes_count ?></h3>
                        <p>Notes</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="assignments.php" class="stat-card">
                    <div class="stat-icon yellow"><i class="bi bi-clipboard2"></i></div>
                    <div class="stat-info">
                        <h3><?= $assignments_count ?></h3>
                        <p>Pending</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="assignments.php" class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
                    <div class="stat-info">
                        <h3><?= $submitted_count ?></h3>
                        <p>Submitted</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="assignments.php" class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-star"></i></div>
                    <div class="stat-info">
                        <h3><?= $avg_grade ?>%</h3>
                        <p>Avg Grade</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-lg-2">
                <a href="notifications.php" class="stat-card">
                    <div class="stat-icon teal"><i class="bi bi-bell"></i></div>
                    <div class="stat-info">
                        <h3><?= $notifications_count ?></h3>
                        <p>Unread</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- ── Main Grid ── -->
        <div class="row g-4 mb-4">

            <!-- Recent Notes -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-file-earmark-text" style="color:var(--red)"></i> Recent Notes</h6>
                        <a href="notes.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($recent_notes) > 0): ?>
                        <?php while ($note = mysqli_fetch_assoc($recent_notes)): ?>
                            <div class="list-item">
                                <div class="item-icon pdf">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($note['title']) ?>
                                    </div>
                                    <div class="item-sub">
                                        <span class="badge-glass badge-blue me-1"><?= htmlspecialchars($note['course_code']) ?></span>
                                        <?= htmlspecialchars($note['lecturer_name']) ?>
                                    </div>
                                </div>
                                <div class="item-right">
                                    <a href="notes.php?download=<?= $note['id'] ?>"
                                       class="badge-glass badge-green"
                                       style="text-decoration:none;">
                                        <i class="bi bi-download me-1"></i>PDF
                                    </a>
                                    <div class="item-sub mt-1">
                                        <?= date('d M', strtotime($note['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-file-earmark-text d-block"></i>
                            <p>No notes available yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upcoming Assignments -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-clipboard2-check" style="color:var(--yellow)"></i> Upcoming Assignments</h6>
                        <a href="assignments.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($upcoming_assignments) > 0): ?>
                        <?php while ($assign = mysqli_fetch_assoc($upcoming_assignments)): ?>
                            <?php
                                $hours = $assign['hours_left'];
                                if ($hours <= 24)       { $urgency = 'badge-red';    $urgency_txt = 'Due Today'; }
                                elseif ($hours <= 72)   { $urgency = 'badge-yellow'; $urgency_txt = 'Due Soon'; }
                                else                    { $urgency = 'badge-blue';   $urgency_txt = date('d M', strtotime($assign['due_date'])); }
                            ?>
                            <div class="list-item">
                                <div class="item-icon assign">
                                    <i class="bi bi-clipboard2"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="item-title text-truncate">
                                        <?= htmlspecialchars($assign['title']) ?>
                                    </div>
                                    <div class="item-sub">
                                        <span class="badge-glass badge-blue me-1"><?= htmlspecialchars($assign['course_code']) ?></span>
                                        <?= $assign['total_marks'] ?> marks
                                    </div>
                                </div>
                                <div class="item-right">
                                    <?php if ($assign['submitted']): ?>
                                        <span class="badge-glass badge-green">Submitted</span>
                                    <?php else: ?>
                                        <span class="badge-glass <?= $urgency ?>"><?= $urgency_txt ?></span>
                                        <div class="mt-1">
                                            <a href="submit_assignment.php?id=<?= $assign['id'] ?>"
                                               class="badge-glass badge-yellow"
                                               style="text-decoration:none; font-size:0.7rem;">
                                                <i class="bi bi-upload me-1"></i>Submit
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-clipboard2-check d-block"></i>
                            <p>No upcoming assignments.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Second Row ── -->
        <div class="row g-4 mb-4">

            <!-- Recent Notifications -->
            <div class="col-lg-4">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h6><i class="bi bi-bell" style="color:var(--accent)"></i> Notifications</h6>
                        <a href="notifications.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (mysqli_num_rows($recent_notifications) > 0): ?>
                        <?php while ($notif = mysqli_fetch_assoc($recent_notifications)): ?>
                            <div class="list-item" style="<?= !$notif['is_read'] ? 'border-left:3px solid var(--accent);' : '' ?>">
                                <div class="item-icon notif">
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
                                            <span style="width:7px;height:7px;background:var(--accent);border-radius:50%;display:inline-block;margin-left:4px;"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-sub text-truncate">
                                        <?= htmlspecialchars(substr($notif['message'], 0, 45)) ?>...
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-bell-slash d-block"></i>
                            <p>No notifications yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Courses -->
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-book" style="color:var(--purple)"></i> My Enrolled Courses</h6>
                        <a href="courses.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="p-3">
                        <?php if (mysqli_num_rows($my_courses) > 0): ?>
                            <div class="row g-3">
                                <?php while ($course = mysqli_fetch_assoc($my_courses)): ?>
                                    <div class="col-md-6">
                                        <div class="course-card">
                                            <div class="course-code"><?= htmlspecialchars($course['course_code']) ?></div>
                                            <div class="course-name"><?= htmlspecialchars($course['course_name']) ?></div>
                                            <div class="course-lecturer">
                                                <i class="bi bi-person me-1"></i>
                                                <?= htmlspecialchars($course['lecturer_name']) ?>
                                            </div>
                                            <div class="course-meta">
                                                <span><i class="bi bi-file-earmark-text"></i><?= $course['notes_count'] ?> Notes</span>
                                                <span><i class="bi bi-clipboard2"></i><?= $course['assignments_count'] ?> Tasks</span>
                                            </div>
                                            <div class="progress-thin">
                                                <div class="progress-fill" style="width:<?= min(($course['notes_count'] * 10), 100) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-book d-block"></i>
                                <p>You are not enrolled in any courses yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

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