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

// ── Search term (for filtering course grid) ──────────────
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_safe   = mysqli_real_escape_string($conn, $search);
$search_clause = '';
if ($search !== '') {
    $search_clause = "AND (c.course_name LIKE '%$search_safe%'
                       OR c.course_code LIKE '%$search_safe%'
                       OR u.name LIKE '%$search_safe%')";
}

// ── Count totals (unfiltered, for stat cards) ────────────
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

$assignments_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM assignments a
                          INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'
                          AND a.status = 'active'")
)['total'];

$lecturers_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(DISTINCT c.lecturer_id) as total
                          FROM courses c
                          INNER JOIN course_enrollments ce ON c.id = ce.course_id
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'")
)['total'];

$notifications_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications n
                          LEFT JOIN notification_reads nr ON n.id = nr.notification_id
                              AND nr.user_id = '$student_id'
                          WHERE (n.target_role = 'student' OR n.target_role = 'all')
                          AND nr.id IS NULL")
)['total'];

// ── My enrolled courses (filtered by search, no limit) ───
$my_courses = mysqli_query($conn,
    "SELECT c.*, u.name AS lecturer_name, u.email AS lecturer_email,
            (SELECT COUNT(*) FROM notes WHERE course_id = c.id AND status = 'active') AS notes_count,
            (SELECT COUNT(*) FROM assignments WHERE course_id = c.id AND status = 'active') AS assignments_count,
            (SELECT COUNT(*) FROM assignments a2
                INNER JOIN submissions s2 ON a2.id = s2.assignment_id AND s2.student_id = '$student_id'
             WHERE a2.course_id = c.id AND a2.status = 'active') AS submitted_count
     FROM courses c
     INNER JOIN course_enrollments ce ON c.id = ce.course_id
     INNER JOIN users u ON c.lecturer_id = u.id
     WHERE ce.student_id = '$student_id'
     AND ce.status = 'enrolled'
     $search_clause
     ORDER BY c.course_name ASC"
);
$total_results = mysqli_num_rows($my_courses);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - OnlineLMS</title>
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

        /* ── Page Header ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
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

        /* ── Search Bar ── */
        .search-bar {
            position: relative;
            min-width: 280px;
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

        /* ── Course Cards (full) ── */
        .course-card-full {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            transition: all 0.3s;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .course-card-full:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }

        .course-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .course-code {
            background: rgba(0,212,255,0.12);
            color: var(--accent);
            border-radius: 8px;
            padding: 3px 10px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }

        .course-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(180,143,252,0.25), rgba(0,212,255,0.15));
            color: var(--purple);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .course-name {
            font-weight: 700;
            font-size: 1.02rem;
            margin-bottom: 6px;
            line-height: 1.3;
        }

        .course-desc {
            color: var(--muted);
            font-size: 0.8rem;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .course-lecturer {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 0.8rem;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .course-lecturer .avatar-sm {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: rgba(0,212,255,0.15);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .course-stats-row {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
        }

        .course-stat-pill {
            flex: 1;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 8px;
            text-align: center;
        }

        .course-stat-pill .num {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
        }

        .course-stat-pill .lbl {
            font-size: 0.68rem;
            color: var(--muted);
            margin-top: 3px;
        }

        .course-card-actions {
            display: flex;
            gap: 8px;
            margin-top: auto;
        }

        .btn-course {
            flex: 1;
            text-align: center;
            padding: 9px 0;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid var(--border);
        }

        .btn-course.notes {
            background: rgba(255,107,107,0.1);
            color: var(--red);
            border-color: rgba(255,107,107,0.25);
        }

        .btn-course.notes:hover { background: rgba(255,107,107,0.2); }

        .btn-course.assign {
            background: rgba(255,217,61,0.1);
            color: var(--yellow);
            border-color: rgba(255,217,61,0.25);
        }

        .btn-course.assign:hover { background: rgba(255,217,61,0.2); }

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
            .search-bar {
                min-width: 100%;
                width: 100%;
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
        <a href="courses.php" class="active">
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
                <h6>My Courses</h6>
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
                    <div class="stat-icon blue"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_count ?></h3>
                        <p>Enrolled Courses</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon red"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $notes_count ?></h3>
                        <p>Notes Available</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon yellow"><i class="bi bi-clipboard2"></i></div>
                    <div class="stat-info">
                        <h3><?= $assignments_count ?></h3>
                        <p>Active Assignments</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-people"></i></div>
                    <div class="stat-info">
                        <h3><?= $lecturers_count ?></h3>
                        <p>Lecturers</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Page Header / Search ── -->
        <div class="page-header">
            <div>
                <h4>My Enrolled Courses</h4>
                <p>
                    <?= $total_results ?> course<?= $total_results == 1 ? '' : 's' ?>
                    <?= $search !== '' ? 'matching "' . htmlspecialchars($search) . '"' : 'enrolled' ?>
                </p>
            </div>
            <form action="courses.php" method="GET" class="search-bar">
                <i class="bi bi-search"></i>
                <input type="text" name="search" placeholder="Search by course name, code or lecturer..."
                       value="<?= htmlspecialchars($search) ?>">
                <?php if ($search !== ''): ?>
                    <a href="courses.php" class="search-clear"><i class="bi bi-x-circle-fill"></i></a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ── Course Grid ── -->
        <?php if ($total_results > 0): ?>
            <div class="row g-4">
                <?php while ($course = mysqli_fetch_assoc($my_courses)): ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="course-card-full">

                            <div class="course-card-top">
                                <span class="course-code"><?= htmlspecialchars($course['course_code']) ?></span>
                                <div class="course-avatar"><i class="bi bi-journal-bookmark-fill"></i></div>
                            </div>

                            <div class="course-name"><?= htmlspecialchars($course['course_name']) ?></div>

                            <?php if (!empty($course['description'])): ?>
                                <div class="course-desc">
                                    <?= htmlspecialchars(substr($course['description'], 0, 90)) ?><?= strlen($course['description']) > 90 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>

                            <div class="course-lecturer">
                                <div class="avatar-sm">
                                    <?= htmlspecialchars(strtoupper(substr($course['lecturer_name'], 0, 1))) ?>
                                </div>
                                <span><?= htmlspecialchars($course['lecturer_name']) ?></span>
                            </div>

                            <div class="course-stats-row">
                                <div class="course-stat-pill">
                                    <div class="num"><?= $course['notes_count'] ?></div>
                                    <div class="lbl">Notes</div>
                                </div>
                                <div class="course-stat-pill">
                                    <div class="num"><?= $course['assignments_count'] ?></div>
                                    <div class="lbl">Assignments</div>
                                </div>
                                <div class="course-stat-pill">
                                    <div class="num"><?= $course['submitted_count'] ?></div>
                                    <div class="lbl">Submitted</div>
                                </div>
                            </div>

                            <div class="course-card-actions">
                                <a href="notes.php?course_id=<?= $course['id'] ?>" class="btn-course notes">
                                    <i class="bi bi-file-earmark-text me-1"></i>Notes
                                </a>
                                <a href="assignments.php?course_id=<?= $course['id'] ?>" class="btn-course assign">
                                    <i class="bi bi-clipboard2-check me-1"></i>Assignments
                                </a>
                            </div>

                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="section-card" style="background:var(--bg-card); border:1px solid var(--border); border-radius:16px;">
                <div class="empty-state">
                    <i class="bi bi-book"></i>
                    <p>
                        <?= $search !== ''
                            ? 'No courses found matching "' . htmlspecialchars($search) . '".'
                            : 'You are not enrolled in any courses yet.' ?>
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