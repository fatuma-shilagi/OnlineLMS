<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('lecturer');

$lecturer_id   = $_SESSION['user_id'];
$lecturer_name = $_SESSION['user_name'];

// ── Fetch lecturer info ──────────────────────────────────
$lecturer = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM users WHERE id = '$lecturer_id'")
);

// ── Fetch lecturer's active courses ─────────────────────
$courses = mysqli_query($conn,
    "SELECT id, course_name, course_code FROM courses
     WHERE lecturer_id = '$lecturer_id' AND status = 'active'
     ORDER BY course_name ASC"
);

// ── Sidebar counts ───────────────────────────────────────
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

// ── Handle form submission ───────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id    = (int)($_POST['course_id'] ?? 0);
    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $due_date     = $_POST['due_date'] ?? '';
    $total_marks  = (int)($_POST['total_marks'] ?? 0);
    $instructions = trim($_POST['instructions'] ?? '');
    $allow_late   = isset($_POST['allow_late']) ? 1 : 0;
    $status       = $_POST['status'] ?? 'active';

    // Validate status
    if (!in_array($status, ['active', 'draft', 'closed'])) {
        $status = 'active';
    }

    if ($course_id <= 0 || empty($title) || empty($due_date) || $total_marks <= 0) {
        $error = 'Please fill in all required fields.';
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO assignments
             (course_id, title, description, instructions, due_date, total_marks, allow_late_submission, created_by, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        if ($stmt === false) {
            $error = 'Database error. Please try again.';
        } else {
            mysqli_stmt_bind_param($stmt, "issssiiis",
                $course_id, $title, $description, $instructions, $due_date, $total_marks, $allow_late, $lecturer_id, $status
            );

            if (mysqli_stmt_execute($stmt)) {
                $success = 'Assignment created successfully!';
            } else {
                $error = 'Failed to create assignment. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Assignment - OnlineLMS</title>
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

        /* ── Page Header ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
        }

        .page-header-left h4 {
            font-weight: 800;
            font-size: 1.25rem;
            margin-bottom: 3px;
        }

        .page-header-left p {
            color: var(--muted);
            font-size: 0.82rem;
            margin: 0;
        }

        .btn-action {
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
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

        .btn-outline {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--muted);
        }

        .btn-outline:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        /* ── Form Card ── */
        .form-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .form-card-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-card-header h6 {
            font-weight: 700;
            font-size: 0.9rem;
            margin: 0;
        }

        .form-card-header .header-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        .form-card-body { padding: 24px; }

        /* ── Form Controls ── */
        .form-group { margin-bottom: 20px; }

        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 8px;
        }

        .form-label .required { color: var(--red); margin-left: 2px; }

        .form-control,
        .form-select {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.875rem;
            padding: 10px 14px;
            width: 100%;
            transition: all 0.2s;
            outline: none;
        }

        .form-control::placeholder { color: var(--muted); }

        .form-control:focus,
        .form-select:focus {
            border-color: rgba(255,217,61,0.5);
            background: rgba(255,255,255,0.07);
            box-shadow: 0 0 0 3px rgba(255,217,61,0.08);
            color: var(--text);
        }

        .form-select option {
            background: #1a1a2e;
            color: var(--text);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-hint {
            margin-top: 5px;
            font-size: 0.73rem;
            color: var(--muted);
        }

        /* ── Toggle Switch ── */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 14px 16px;
        }

        .toggle-info h6 {
            font-size: 0.875rem;
            font-weight: 600;
            margin: 0 0 2px;
        }

        .toggle-info p {
            font-size: 0.75rem;
            color: var(--muted);
            margin: 0;
        }

        .form-check-input {
            width: 40px;
            height: 22px;
            background-color: rgba(255,255,255,0.1);
            border: 1px solid var(--border);
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .form-check-input:checked {
            background-color: var(--accent);
            border-color: var(--accent);
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(255,217,61,0.15);
            border-color: var(--accent);
        }

        /* ── Marks Input ── */
        .marks-input-wrap {
            position: relative;
        }

        .marks-input-wrap .marks-suffix {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.78rem;
            color: var(--muted);
            pointer-events: none;
        }

        .marks-input-wrap .form-control {
            padding-right: 60px;
        }

        /* ── Status Select with colour indicator ── */
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 7px;
        }

        /* ── Alert / Feedback ── */
        .alert-glass {
            border-radius: 12px;
            padding: 13px 18px;
            font-size: 0.845rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
        }

        .alert-success {
            background: rgba(107,203,119,0.12);
            border: 1px solid rgba(107,203,119,0.3);
            color: var(--green);
        }

        .alert-danger {
            background: rgba(255,107,107,0.1);
            border: 1px solid rgba(255,107,107,0.3);
            color: var(--red);
        }

        /* ── Preview Panel ── */
        .preview-panel {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 18px;
            height: 100%;
        }

        .preview-panel h6 {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--muted);
            margin-bottom: 14px;
        }

        .preview-course-tag {
            background: rgba(255,217,61,0.12);
            color: var(--accent);
            border-radius: 7px;
            padding: 3px 10px;
            font-size: 0.72rem;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 10px;
        }

        .preview-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
            min-height: 28px;
        }

        .preview-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .preview-meta-item {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 4px 10px;
            font-size: 0.73rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .preview-meta-item i { color: var(--accent); }

        .preview-description {
            font-size: 0.82rem;
            color: var(--muted);
            line-height: 1.6;
            min-height: 40px;
        }

        .preview-divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 14px 0;
        }

        .preview-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .pb-green {
            background: rgba(107,203,119,0.15);
            color: var(--green);
            border: 1px solid rgba(107,203,119,0.3);
        }

        .pb-yellow {
            background: rgba(255,217,61,0.15);
            color: var(--accent);
            border: 1px solid rgba(255,217,61,0.3);
        }

        .pb-muted {
            background: rgba(255,255,255,0.06);
            color: var(--muted);
            border: 1px solid var(--border);
        }

        /* ── Submit Bar ── */
        .submit-bar {
            background: rgba(255,255,255,0.03);
            border-top: 1px solid var(--border);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .submit-bar p {
            font-size: 0.78rem;
            color: var(--muted);
            margin: 0;
        }

        .submit-actions { display: flex; gap: 10px; }

        .btn-save-draft {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 600;
            padding: 9px 18px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-save-draft:hover {
            border-color: var(--purple);
            color: var(--purple);
        }

        .btn-publish {
            background: var(--accent);
            border: none;
            border-radius: 10px;
            color: #1a1a2e;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 9px 22px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-publish:hover {
            background: #ffcd00;
            transform: translateY(-1px);
        }

        /* ── Tips Card ── */
        .tips-card {
            background: rgba(255,217,61,0.04);
            border: 1px solid rgba(255,217,61,0.15);
            border-radius: 13px;
            padding: 16px;
        }

        .tips-card h6 {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tip-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 10px;
            font-size: 0.78rem;
            color: var(--muted);
            line-height: 1.5;
        }

        .tip-item:last-child { margin-bottom: 0; }

        .tip-item i {
            color: var(--accent);
            margin-top: 1px;
            flex-shrink: 0;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .submit-bar { flex-direction: column; align-items: stretch; }
            .submit-actions { width: 100%; }
            .btn-save-draft, .btn-publish { flex: 1; justify-content: center; }
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
        <a href="create_assignment.php" class="active">
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
                <h6>Create Assignment</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="view_assignments.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-clipboard2-check"></i> View Assignments
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

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h4><i class="bi bi-plus-circle me-2" style="color:var(--accent)"></i>Create New Assignment</h4>
                <p>Set a task, deadline, and marks for your students</p>
            </div>
            <div class="d-flex gap-2">
                <a href="view_assignments.php" class="btn-action btn-outline d-none d-md-inline-flex">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert-glass alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($success) ?>
                <a href="view_assignments.php" style="color:var(--green);margin-left:auto;font-size:0.78rem;">
                    View Assignments <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-glass alert-danger">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="assignmentForm">
            <div class="row g-4">

                <!-- ── Left: Form Fields ── -->
                <div class="col-lg-8">

                    <!-- Basic Info -->
                    <div class="form-card mb-4">
                        <div class="form-card-header">
                            <div class="header-icon" style="background:rgba(255,217,61,0.12);color:var(--accent);">
                                <i class="bi bi-info-circle"></i>
                            </div>
                            <h6>Basic Information</h6>
                        </div>
                        <div class="form-card-body">

                            <!-- Course -->
                            <div class="form-group">
                                <label class="form-label">Course <span class="required">*</span></label>
                                <select name="course_id" class="form-select" id="courseSelect" required
                                        onchange="updatePreviewCourse(this)">
                                    <option value="">— Select a course —</option>
                                    <?php
                                    // Reset pointer
                                    mysqli_data_seek($courses, 0);
                                    while ($c = mysqli_fetch_assoc($courses)):
                                        $sel = (isset($_POST['course_id']) && $_POST['course_id'] == $c['id']) ? 'selected' : '';
                                    ?>
                                        <option value="<?= $c['id'] ?>"
                                                data-code="<?= htmlspecialchars($c['course_code']) ?>"
                                                data-name="<?= htmlspecialchars($c['course_name']) ?>"
                                                <?= $sel ?>>
                                            <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <p class="form-hint">Only your active courses are listed.</p>
                            </div>

                            <!-- Title -->
                            <div class="form-group">
                                <label class="form-label">Assignment Title <span class="required">*</span></label>
                                <input type="text" name="title" class="form-control"
                                       placeholder="e.g. Week 3 Lab Report — Data Structures"
                                       maxlength="200"
                                       value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                                       oninput="updatePreviewTitle(this.value)"
                                       required>
                                <p class="form-hint">Keep it descriptive so students know exactly what's expected.</p>
                            </div>

                            <!-- Description -->
                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"
                                          placeholder="Brief overview of this assignment…"
                                          oninput="updatePreviewDesc(this.value)"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                <p class="form-hint">Shown to students on the assignment listing page.</p>
                            </div>

                        </div>
                    </div>

                    <!-- Instructions & Marks -->
                    <div class="form-card mb-4">
                        <div class="form-card-header">
                            <div class="header-icon" style="background:rgba(0,212,255,0.12);color:var(--blue);">
                                <i class="bi bi-list-check"></i>
                            </div>
                            <h6>Instructions & Grading</h6>
                        </div>
                        <div class="form-card-body">

                            <!-- Instructions -->
                            <div class="form-group">
                                <label class="form-label">Detailed Instructions</label>
                                <textarea name="instructions" class="form-control" rows="5"
                                          placeholder="Step-by-step requirements, submission format, referencing guidelines…"><?= htmlspecialchars($_POST['instructions'] ?? '') ?></textarea>
                                <p class="form-hint">Students will see this when they open the assignment. Be specific.</p>
                            </div>

                            <div class="row g-3">
                                <!-- Total Marks -->
                                <div class="col-sm-6">
                                    <div class="form-group mb-0">
                                        <label class="form-label">Total Marks <span class="required">*</span></label>
                                        <div class="marks-input-wrap">
                                            <input type="number" name="total_marks" class="form-control"
                                                   placeholder="100" min="1" max="1000"
                                                   value="<?= htmlspecialchars($_POST['total_marks'] ?? '') ?>"
                                                   oninput="updatePreviewMarks(this.value)"
                                                   required>
                                            <span class="marks-suffix">pts</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Due Date -->
                                <div class="col-sm-6">
                                    <div class="form-group mb-0">
                                        <label class="form-label">Due Date & Time <span class="required">*</span></label>
                                        <input type="datetime-local" name="due_date" class="form-control"
                                               value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>"
                                               min="<?= date('Y-m-d\TH:i') ?>"
                                               oninput="updatePreviewDue(this.value)"
                                               required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Settings -->
                    <div class="form-card mb-4">
                        <div class="form-card-header">
                            <div class="header-icon" style="background:rgba(180,143,252,0.12);color:var(--purple);">
                                <i class="bi bi-gear"></i>
                            </div>
                            <h6>Settings</h6>
                        </div>
                        <div class="form-card-body">

                            <!-- Allow Late Submissions -->
                            <div class="toggle-row mb-3">
                                <div class="toggle-info">
                                    <h6>Allow Late Submissions</h6>
                                    <p>Students can still submit after the deadline passes.</p>
                                </div>
                                <input class="form-check-input" type="checkbox" name="allow_late"
                                       id="allowLate" role="switch"
                                       <?= isset($_POST['allow_late']) ? 'checked' : '' ?>
                                       onchange="updatePreviewLate(this.checked)">
                            </div>

                            <!-- Publish Status -->
                            <div class="form-group mb-0">
                                <label class="form-label">Publish Status <span class="required">*</span></label>
                                <select name="status" class="form-select" id="statusSelect"
                                        onchange="updatePreviewStatus(this.value)">
                                    <option value="active"  <?= (($_POST['status'] ?? 'active') === 'active')  ? 'selected' : '' ?>>
                                        Active — Visible to students now
                                    </option>
                                    <option value="draft"   <?= (($_POST['status'] ?? '') === 'draft')   ? 'selected' : '' ?>>
                                        Draft — Hidden from students
                                    </option>
                                    <option value="closed"  <?= (($_POST['status'] ?? '') === 'closed')  ? 'selected' : '' ?>>
                                        Closed — No new submissions
                                    </option>
                                </select>
                                <p class="form-hint">You can change this later from Assignments.</p>
                            </div>

                        </div>

                        <!-- Submit Bar -->
                        <div class="submit-bar">
                            <p><i class="bi bi-shield-check me-1" style="color:var(--green)"></i>Fields marked <span style="color:var(--red)">*</span> are required.</p>
                            <div class="submit-actions">
                                <button type="button" class="btn-save-draft"
                                        onclick="saveDraft()">
                                    <i class="bi bi-floppy"></i> Save Draft
                                </button>
                                <button type="submit" name="status" value="active" class="btn-publish">
                                    <i class="bi bi-send"></i> Publish Assignment
                                </button>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── Right: Preview + Tips ── -->
                <div class="col-lg-4">

                    <!-- Live Preview -->
                    <div class="form-card mb-4">
                        <div class="form-card-header">
                            <div class="header-icon" style="background:rgba(107,203,119,0.12);color:var(--green);">
                                <i class="bi bi-eye"></i>
                            </div>
                            <h6>Student Preview</h6>
                        </div>
                        <div class="form-card-body" style="padding:16px;">
                            <div class="preview-panel" style="border:none;padding:0;background:none;">
                                <div id="previewCourseTag" class="preview-course-tag" style="display:none;"></div>
                                <div id="previewTitle" class="preview-title" style="color:var(--muted);font-size:0.85rem;font-weight:400;">
                                    Assignment title will appear here…
                                </div>
                                <div class="preview-meta" id="previewMeta" style="display:none;">
                                    <div class="preview-meta-item" id="previewDue" style="display:none;">
                                        <i class="bi bi-calendar-event"></i>
                                        <span id="previewDueText">—</span>
                                    </div>
                                    <div class="preview-meta-item" id="previewMarksBox" style="display:none;">
                                        <i class="bi bi-award"></i>
                                        <span id="previewMarksText">—</span>
                                    </div>
                                </div>
                                <p id="previewDesc" class="preview-description" style="display:none;"></p>
                                <hr class="preview-divider" id="previewDivider" style="display:none;">
                                <div id="previewBadges" style="display:none;gap:6px;flex-wrap:wrap;" class="d-flex">
                                    <span class="preview-badge pb-green" id="previewStatusBadge">
                                        <i class="bi bi-check-circle"></i> Active
                                    </span>
                                    <span class="preview-badge pb-muted" id="previewLateBadge" style="display:none;">
                                        <i class="bi bi-clock-history"></i> Late OK
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tips -->
                    <div class="tips-card">
                        <h6><i class="bi bi-lightbulb-fill"></i> Tips for a good assignment</h6>
                        <div class="tip-item">
                            <i class="bi bi-check2-circle"></i>
                            <span>State the <strong style="color:var(--text)">learning outcome</strong> at the top of your instructions.</span>
                        </div>
                        <div class="tip-item">
                            <i class="bi bi-check2-circle"></i>
                            <span>Mention the <strong style="color:var(--text)">submission format</strong> — PDF, Word, ZIP, etc.</span>
                        </div>
                        <div class="tip-item">
                            <i class="bi bi-check2-circle"></i>
                            <span>Set the deadline at least <strong style="color:var(--text)">24 hours ahead</strong> so students have time to ask questions.</span>
                        </div>
                        <div class="tip-item">
                            <i class="bi bi-check2-circle"></i>
                            <span>Break the total marks into a <strong style="color:var(--text)">marking rubric</strong> inside instructions.</span>
                        </div>
                        <div class="tip-item">
                            <i class="bi bi-check2-circle"></i>
                            <span>Save as <strong style="color:var(--text)">Draft</strong> first to review before publishing to students.</span>
                        </div>
                    </div>

                </div>
            </div>
        </form>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.5); z-index:99;">
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* ── Sidebar ── */
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

    /* ── Live Preview ── */
    function showPreviewMeta() {
        document.getElementById('previewMeta').style.display = 'flex';
        document.getElementById('previewDivider').style.display = 'block';
        document.getElementById('previewBadges').style.display = 'flex';
    }

    function updatePreviewCourse(select) {
        const opt  = select.options[select.selectedIndex];
        const tag  = document.getElementById('previewCourseTag');
        const code = opt.dataset.code || '';
        if (code) {
            tag.textContent = code;
            tag.style.display = 'inline-block';
        } else {
            tag.style.display = 'none';
        }
    }

    function updatePreviewTitle(val) {
        const el = document.getElementById('previewTitle');
        if (val.trim()) {
            el.textContent = val;
            el.style.color  = 'var(--text)';
            el.style.fontSize = '1.05rem';
            el.style.fontWeight = '700';
            showPreviewMeta();
        } else {
            el.textContent = 'Assignment title will appear here…';
            el.style.color = 'var(--muted)';
            el.style.fontSize = '0.85rem';
            el.style.fontWeight = '400';
        }
    }

    function updatePreviewDesc(val) {
        const el = document.getElementById('previewDesc');
        if (val.trim()) {
            el.textContent = val;
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    }

    function updatePreviewDue(val) {
        const box  = document.getElementById('previewDue');
        const text = document.getElementById('previewDueText');
        if (val) {
            const d = new Date(val);
            text.textContent = d.toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' })
                             + ' · ' + d.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' });
            box.style.display = 'flex';
            showPreviewMeta();
        } else {
            box.style.display = 'none';
        }
    }

    function updatePreviewMarks(val) {
        const box  = document.getElementById('previewMarksBox');
        const text = document.getElementById('previewMarksText');
        if (val && parseInt(val) > 0) {
            text.textContent = val + ' marks';
            box.style.display = 'flex';
            showPreviewMeta();
        } else {
            box.style.display = 'none';
        }
    }

    function updatePreviewLate(checked) {
        const el = document.getElementById('previewLateBadge');
        el.style.display = checked ? 'inline-flex' : 'none';
    }

    function updatePreviewStatus(val) {
        const badge = document.getElementById('previewStatusBadge');
        const map = {
            active:  { cls: 'pb-green',  icon: 'bi-check-circle',  text: 'Active'  },
            draft:   { cls: 'pb-muted',  icon: 'bi-pencil-square', text: 'Draft'   },
            closed:  { cls: 'pb-yellow', icon: 'bi-lock',          text: 'Closed'  }
        };
        const cfg = map[val] || map.active;
        badge.className = `preview-badge ${cfg.cls}`;
        badge.innerHTML = `<i class="bi ${cfg.icon}"></i> ${cfg.text}`;
        showPreviewMeta();
    }

    /* ── Save Draft ── */
    function saveDraft() {
        document.getElementById('statusSelect').value = 'draft';
        document.getElementById('assignmentForm').submit();
    }

    /* ── Init preview from repopulated form (on validation error) ── */
    window.addEventListener('DOMContentLoaded', () => {
        const titleInput = document.querySelector('[name="title"]');
        if (titleInput && titleInput.value) updatePreviewTitle(titleInput.value);

        const descInput = document.querySelector('[name="description"]');
        if (descInput && descInput.value) updatePreviewDesc(descInput.value);

        const marksInput = document.querySelector('[name="total_marks"]');
        if (marksInput && marksInput.value) updatePreviewMarks(marksInput.value);

        const dueInput = document.querySelector('[name="due_date"]');
        if (dueInput && dueInput.value) updatePreviewDue(dueInput.value);

        const courseSelect = document.getElementById('courseSelect');
        if (courseSelect && courseSelect.value) updatePreviewCourse(courseSelect);

        const statusSelect = document.getElementById('statusSelect');
        if (statusSelect) updatePreviewStatus(statusSelect.value);

        const allowLate = document.getElementById('allowLate');
        if (allowLate) updatePreviewLate(allowLate.checked);
    });
</script>
</body>
</html>