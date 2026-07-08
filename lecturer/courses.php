<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('lecturer');

$lecturer_id   = $_SESSION['user_id'];
$lecturer_name = $_SESSION['user_name'];

// ── Lecturer info (for sidebar avatar) ───────────────────
$lecturer = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM users WHERE id = '$lecturer_id'")
);

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

// ── All of my courses with stats ─────────────────────────
$courses_result = mysqli_query($conn,
    "SELECT c.*,
            (SELECT COUNT(*) FROM course_enrollments WHERE course_id = c.id AND status = 'enrolled') AS students,
            (SELECT COUNT(*) FROM notes WHERE course_id = c.id AND status = 'active') AS notes_count,
            (SELECT COUNT(*) FROM assignments WHERE course_id = c.id AND status = 'active') AS assign_count
     FROM courses c
     WHERE c.lecturer_id = '$lecturer_id'
     AND c.status = 'active'
     ORDER BY c.created_at DESC"
);

$total_courses_found = mysqli_num_rows($courses_result);
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

        /* ── Search Bar ── */
        .search-bar-wrap {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .search-bar-wrap i { color: var(--muted); font-size: 1rem; }

        .search-bar-wrap input {
            flex: 1;
            background: transparent;
            border: none;
            color: var(--text);
            font-size: 0.88rem;
            outline: none;
        }

        .search-bar-wrap input::placeholder { color: rgba(255,255,255,0.3); }

        .search-count {
            color: var(--muted);
            font-size: 0.78rem;
            flex-shrink: 0;
        }

        /* ── Course Cards ── */
        .course-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .course-card:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }

        .course-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .course-code-tag {
            background: rgba(255,217,61,0.12);
            color: var(--accent);
            border-radius: 7px;
            padding: 3px 10px;
            font-size: 0.74rem;
            font-weight: 700;
            display: inline-block;
        }

        .badge-glass {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 600;
            display: inline-block;
        }

        .bg-green { background: rgba(107,203,119,0.15); color: var(--green); border: 1px solid rgba(107,203,119,0.3); }
        .bg-red   { background: rgba(255,107,107,0.15); color: var(--red);   border: 1px solid rgba(255,107,107,0.3); }

        .course-title {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 8px;
            color: var(--text);
        }

        .course-desc {
            color: var(--muted);
            font-size: 0.78rem;
            line-height: 1.5;
            margin-bottom: 14px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .course-mini-stats {
            display: flex;
            gap: 14px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .course-mini-stats span {
            color: var(--muted);
            font-size: 0.74rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .course-mini-stats i { font-size: 0.85rem; }

        .course-card-footer {
            margin-top: auto;
            display: flex;
            gap: 8px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }

        .course-action-btn {
            flex: 1;
            text-align: center;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 8px 6px;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.74rem;
            font-weight: 600;
            transition: all 0.2s;
            cursor: pointer;
        }

        .course-action-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(255,217,61,0.08);
        }

        .course-added-date {
            color: var(--muted);
            font-size: 0.7rem;
            margin-top: 10px;
            text-align: right;
        }

        /* ── Empty State ── */
        .empty-state-big {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 60px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state-big i {
            font-size: 2.8rem;
            margin-bottom: 14px;
            opacity: 0.3;
            display: block;
        }

        .empty-state-big h6 {
            color: var(--text);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .empty-state-big p { font-size: 0.85rem; margin: 0; }

        /* ── Modal (student roster) ── */
        .modal-content {
            background: #15152a;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 16px;
        }

        .modal-header { border-bottom: 1px solid var(--border); }
        .modal-footer { border-top: 1px solid var(--border); }
        .btn-close { filter: invert(1) grayscale(100%) brightness(2); }

        .student-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .student-row:last-child { border-bottom: none; }

        .student-row img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
        }

        .student-row .s-name { font-weight: 600; font-size: 0.85rem; }
        .student-row .s-email { color: var(--muted); font-size: 0.75rem; }

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
        <a href="courses.php" class="active">
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
                <h6>My Courses</h6>
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
                <h4>My Courses</h4>
                <p>All courses currently assigned to you.</p>
            </div>
        </div>

        <!-- ── Quick Stats ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_count ?></h3>
                        <p>Total Courses</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-people"></i></div>
                    <div class="stat-info">
                        <h3><?= $students_count ?></h3>
                        <p>Total Students</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon si-purple"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $notes_count ?></h3>
                        <p>Notes Uploaded</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-clipboard2"></i></div>
                    <div class="stat-info">
                        <h3><?= $assignments_count ?></h3>
                        <p>Assignments</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Search ── -->
        <div class="search-bar-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="courseSearch" placeholder="Search by course name or code...">
            <span class="search-count" id="searchCount"><?= $total_courses_found ?> course<?= $total_courses_found !== 1 ? 's' : '' ?></span>
        </div>

        <!-- ── Course Grid ── -->
        <?php if ($total_courses_found > 0): ?>
            <div class="row g-4" id="courseGrid">
                <?php while ($course = mysqli_fetch_assoc($courses_result)): ?>
                    <?php
                        $course_id   = $course['id'];
                        $search_key  = strtolower($course['course_code'] . ' ' . $course['course_name']);

                        // Fetch enrolled students for this course's roster modal
                        $students_res = mysqli_query($conn,
                            "SELECT u.id, u.name, u.email, u.profile_picture, ce.enrolled_at
                             FROM course_enrollments ce
                             INNER JOIN users u ON ce.student_id = u.id
                             WHERE ce.course_id = '$course_id'
                             AND ce.status = 'enrolled'
                             ORDER BY u.name ASC"
                        );
                    ?>
                    <div class="col-md-6 col-lg-4 course-card-wrap" data-search="<?= htmlspecialchars($search_key) ?>">
                        <div class="course-card">
                            <div class="course-card-top">
                                <span class="course-code-tag"><?= htmlspecialchars($course['course_code']) ?></span>
                                <span class="badge-glass bg-green">Active</span>
                            </div>

                            <div class="course-title"><?= htmlspecialchars($course['course_name']) ?></div>

                            <?php if (!empty($course['description'])): ?>
                                <div class="course-desc"><?= htmlspecialchars($course['description']) ?></div>
                            <?php endif; ?>

                            <div class="course-mini-stats">
                                <span><i class="bi bi-people"></i> <?= $course['students'] ?> Students</span>
                                <span><i class="bi bi-file-earmark-text"></i> <?= $course['notes_count'] ?> Notes</span>
                                <span><i class="bi bi-clipboard2"></i> <?= $course['assign_count'] ?> Tasks</span>
                            </div>

                            <div class="course-card-footer">
                                <a href="#" class="course-action-btn" data-bs-toggle="modal" data-bs-target="#studentsModal<?= $course_id ?>">
                                    <i class="bi bi-people d-block mb-1"></i> Students
                                </a>
                                <a href="view_notes.php?course_id=<?= $course_id ?>" class="course-action-btn">
                                    <i class="bi bi-file-earmark-text d-block mb-1"></i> Notes
                                </a>
                                <a href="view_assignments.php?course_id=<?= $course_id ?>" class="course-action-btn">
                                    <i class="bi bi-clipboard2 d-block mb-1"></i> Tasks
                                </a>
                            </div>

                            <div class="course-added-date">
                                Added <?= date('d M Y', strtotime($course['created_at'])) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Students Roster Modal -->
                    <div class="modal fade" id="studentsModal<?= $course_id ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h6 class="modal-title">
                                        <i class="bi bi-people me-2" style="color:var(--accent)"></i>
                                        Students — <?= htmlspecialchars($course['course_code']) ?>
                                    </h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <?php if (mysqli_num_rows($students_res) > 0): ?>
                                        <?php while ($stu = mysqli_fetch_assoc($students_res)): ?>
                                            <div class="student-row">
                                                <img src="../uploads/profiles/<?= htmlspecialchars($stu['profile_picture'] ?? '') ?>"
                                                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($stu['name']) ?>&background=ffd93d&color=1a1a2e&size=38'"
                                                     alt="">
                                                <div style="flex:1; min-width:0;">
                                                    <div class="s-name text-truncate"><?= htmlspecialchars($stu['name']) ?></div>
                                                    <div class="s-email text-truncate"><?= htmlspecialchars($stu['email']) ?></div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <div class="text-center" style="color:var(--muted); padding:20px 0;">
                                            <i class="bi bi-person-x" style="font-size:1.6rem; opacity:0.4; display:block; margin-bottom:8px;"></i>
                                            No students enrolled yet.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

            <div id="noResults" class="empty-state-big" style="display:none;">
                <i class="bi bi-search"></i>
                <h6>No matching courses</h6>
                <p>Try a different course name or code.</p>
            </div>
        <?php else: ?>
            <div class="empty-state-big">
                <i class="bi bi-book"></i>
                <h6>No courses assigned yet</h6>
                <p>Once an administrator assigns courses to you, they'll appear here.</p>
            </div>
        <?php endif; ?>

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

    // Live course search/filter
    const searchInput = document.getElementById('courseSearch');
    const searchCount = document.getElementById('searchCount');
    const noResults    = document.getElementById('noResults');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const term  = this.value.trim().toLowerCase();
            const cards = document.querySelectorAll('.course-card-wrap');
            let visible = 0;

            cards.forEach(card => {
                const match = card.dataset.search.includes(term);
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            searchCount.textContent = visible + ' course' + (visible !== 1 ? 's' : '');
            noResults.style.display = visible === 0 ? 'block' : 'none';
        });
    }
</script>
</body>
</html>