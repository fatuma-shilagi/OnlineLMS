<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('student');

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];

// ── Fetch student info ───────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// ── Count enrolled courses ───────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM course_enrollments WHERE student_id = ? AND status = 'enrolled'");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$courses_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count available notes (from enrolled courses) ────────
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) as total FROM notes n
     INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
     WHERE ce.student_id = ? AND ce.status = 'enrolled' AND n.status = 'active'"
);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$notes_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count pending assignments ────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) as total FROM assignments a
     INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
     LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = ?
     WHERE ce.student_id = ? AND ce.status = 'enrolled'
     AND a.status = 'active' AND a.due_date >= NOW() AND s.id IS NULL"
);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $student_id);
mysqli_stmt_execute($stmt);
$assignments_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count unread notifications ───────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) as total FROM notifications n
     LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
     WHERE (n.target_role = 'student' OR n.target_role = 'all') AND nr.id IS NULL"
);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$notifications_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count submitted assignments ──────────────────────────
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM submissions WHERE student_id = ?");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$submitted_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Count graded assignments ─────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM grades WHERE student_id = ?");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$graded_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

// ── Recent notes (last 5) ────────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT n.*, c.course_name, c.course_code, u.name AS lecturer_name
     FROM notes n
     INNER JOIN courses c ON n.course_id = c.id
     INNER JOIN users u ON n.uploaded_by = u.id
     INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
     WHERE ce.student_id = ? AND ce.status = 'enrolled' AND n.status = 'active'
     ORDER BY n.created_at DESC
     LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$recent_notes = mysqli_stmt_get_result($stmt);

// ── Upcoming assignments (next 5) ────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT a.*, c.course_name, c.course_code,
            s.id AS submitted,
            TIMESTAMPDIFF(HOUR, NOW(), a.due_date) AS hours_left
     FROM assignments a
     INNER JOIN courses c ON a.course_id = c.id
     INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
     LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = ?
     WHERE ce.student_id = ? AND ce.status = 'enrolled'
     AND a.status = 'active' AND a.due_date >= NOW()
     ORDER BY a.due_date ASC
     LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $student_id);
mysqli_stmt_execute($stmt);
$upcoming_assignments = mysqli_stmt_get_result($stmt);

// ── Recent notifications (last 5) ────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT n.*, u.name AS sender_name,
            nr.id AS is_read
     FROM notifications n
     INNER JOIN users u ON n.sent_by = u.id
     LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
     WHERE (n.target_role = 'student' OR n.target_role = 'all')
     ORDER BY n.created_at DESC
     LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$recent_notifications = mysqli_stmt_get_result($stmt);

// ── My enrolled courses ──────────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT c.*, u.name AS lecturer_name,
            (SELECT COUNT(*) FROM notes WHERE course_id = c.id AND status = 'active') AS notes_count,
            (SELECT COUNT(*) FROM assignments WHERE course_id = c.id AND status = 'active') AS assignments_count
     FROM courses c
     INNER JOIN course_enrollments ce ON c.id = ce.course_id
     INNER JOIN users u ON c.lecturer_id = u.id
     WHERE ce.student_id = ? AND ce.status = 'enrolled'
     ORDER BY c.course_name ASC
     LIMIT 6"
);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$my_courses = mysqli_stmt_get_result($stmt);

// ── Recent grades ────────────────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT g.*, a.title AS assignment_title, a.total_marks,
            c.course_code, s.submitted_at
     FROM grades g
     INNER JOIN assignments a ON g.assignment_id = a.id
     INNER JOIN courses c ON a.course_id = c.id
     INNER JOIN submissions s ON g.submission_id = s.id
     WHERE g.student_id = ?
     ORDER BY g.graded_at DESC
     LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$recent_grades = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - OnlineLMS</title>
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

        /* Sidebar */
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

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Topbar */
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

        /* Page Body */
        .page-body { padding: 28px; flex: 1; }

        /* Stat Cards */
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

        /* Section Card */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 24px;
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

        .item-row {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: background 0.2s;
        }

        .item-row:last-child { border-bottom: none; }
        .item-row:hover { background: var(--bg-hover); }

        .item-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .item-info { flex: 1; min-width: 200px; }
        .item-title { font-size: 0.875rem; font-weight: 600; color: var(--text); margin-bottom: 3px; }
        .item-sub { font-size: 0.74rem; color: var(--muted); display: flex; gap: 10px; flex-wrap: wrap; }

        .badge-glass {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .badge-blue   { background: rgba(0,212,255,0.15);  color: var(--accent); border: 1px solid rgba(0,212,255,0.3); }
        .badge-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .badge-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3); }
        .badge-red    { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3); }

        .empty-state { padding: 40px 20px; text-align: center; color: var(--muted); }
        .empty-state i { font-size: 1.8rem; margin-bottom: 8px; opacity: 0.35; display: block; }
        .empty-state p { font-size: 0.82rem; margin: 0; }

        /* Responsive */
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

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Learning Management System</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture'] ?? '') ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=46'"
             alt="Profile">
        <div>
            <div class="name"><?= htmlspecialchars($student_name) ?></div>
            <span class="role-badge">Student</span>
        </div>
    </div>

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

    <div class="sidebar-footer">
        <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?')">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>

<!-- Main Content -->
<div class="main-content">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
            <div class="topbar-left">
                <h6>Dashboard</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($notifications_count > 0): ?><span class="notif-dot"></span><?php endif; ?>
            </a>
            <a href="profile.php" style="text-decoration:none;">
                <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture'] ?? '') ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=36'"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
            </a>
        </div>
    </div>

    <div class="page-body">
        <!-- Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_count ?></h3>
                        <p>Enrolled Courses</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon yellow"><i class="bi bi-clipboard2-check"></i></div>
                    <div class="stat-info">
                        <h3><?= $assignments_count ?></h3>
                        <p>Pending Assignments</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
                    <div class="stat-info">
                        <h3><?= $submitted_count ?></h3>
                        <p>Submitted</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-star"></i></div>
                    <div class="stat-info">
                        <h3><?= $graded_count ?></h3>
                        <p>Graded</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Upcoming Assignments -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-calendar-event" style="color:var(--yellow)"></i> Upcoming Assignments</h6>
                    </div>
                    <?php if (mysqli_num_rows($upcoming_assignments) > 0): ?>
                        <?php while ($a = mysqli_fetch_assoc($upcoming_assignments)): ?>
                            <div class="item-row">
                                <div class="item-icon" style="background:rgba(255,217,61,0.15);color:var(--yellow)">
                                    <i class="bi bi-clipboard2"></i>
                                </div>
                                <div class="item-info">
                                    <div class="item-title"><?= htmlspecialchars($a['title']) ?></div>
                                    <div class="item-sub">
                                        <span class="badge-glass badge-blue"><?= htmlspecialchars($a['course_code']) ?></span>
                                        <span><i class="bi bi-calendar"></i> <?= date('d M Y', strtotime($a['due_date'])) ?></span>
                                    </div>
                                </div>
                                <?php if (!$a['submitted']): ?>
                                    <a href="submit_assignment.php?id=<?= $a['id'] ?>" class="badge-glass badge-yellow">Submit</a>
                                <?php else: ?>
                                    <span class="badge-glass badge-green">Submitted</span>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-check"></i>
                            <p>No upcoming assignments.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Notes -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-file-earmark-text" style="color:var(--accent)"></i> Recent Notes</h6>
                    </div>
                    <?php if (mysqli_num_rows($recent_notes) > 0): ?>
                        <?php while ($n = mysqli_fetch_assoc($recent_notes)): ?>
                            <div class="item-row">
                                <div class="item-icon" style="background:rgba(0,212,255,0.15);color:var(--accent)">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                                <div class="item-info">
                                    <div class="item-title"><?= htmlspecialchars($n['title']) ?></div>
                                    <div class="item-sub">
                                        <span class="badge-glass badge-blue"><?= htmlspecialchars($n['course_code']) ?></span>
                                        <span><i class="bi bi-person"></i> <?= htmlspecialchars($n['lecturer_name']) ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($n['file_name'])): ?>
                                    <a href="notes.php?download=<?= $n['id'] ?>" class="badge-glass badge-green">
                                        <i class="bi bi-download"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-file-earmark-x"></i>
                            <p>No notes available yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Courses -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-book" style="color:var(--green)"></i> My Courses</h6>
                    </div>
                    <?php if (mysqli_num_rows($my_courses) > 0): ?>
                        <?php while ($c = mysqli_fetch_assoc($my_courses)): ?>
                            <div class="item-row">
                                <div class="item-icon" style="background:rgba(107,203,119,0.15);color:var(--green)">
                                    <i class="bi bi-book"></i>
                                </div>
                                <div class="item-info">
                                    <div class="item-title"><?= htmlspecialchars($c['course_name']) ?></div>
                                    <div class="item-sub">
                                        <span class="badge-glass badge-blue"><?= htmlspecialchars($c['course_code']) ?></span>
                                        <span><i class="bi bi-person"></i> <?= htmlspecialchars($c['lecturer_name']) ?></span>
                                        <span><i class="bi bi-file-text"></i> <?= $c['notes_count'] ?> notes</span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-book"></i>
                            <p>You are not enrolled in any courses yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Grades -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-star" style="color:var(--purple)"></i> Recent Grades</h6>
                    </div>
                    <?php if (mysqli_num_rows($recent_grades) > 0): ?>
                        <?php while ($g = mysqli_fetch_assoc($recent_grades)): ?>
                            <?php $pct = round(($g['marks_obtained'] / $g['total_marks']) * 100, 1); ?>
                            <div class="item-row">
                                <div class="item-icon" style="background:rgba(180,143,252,0.15);color:var(--purple)">
                                    <i class="bi bi-star-fill"></i>
                                </div>
                                <div class="item-info">
                                    <div class="item-title"><?= htmlspecialchars($g['assignment_title']) ?></div>
                                    <div class="item-sub">
                                        <span class="badge-glass badge-blue"><?= htmlspecialchars($g['course_code']) ?></span>
                                        <span>Score: <?= $g['marks_obtained'] ?>/<?= $g['total_marks'] ?></span>
                                    </div>
                                </div>
                                <span class="badge-glass <?= $pct >= 70 ? 'badge-green' : ($pct >= 50 ? 'badge-yellow' : 'badge-red') ?>">
                                    <?= $pct ?>%
                                </span>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-star"></i>
                            <p>No graded assignments yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar Overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
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
