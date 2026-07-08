<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('student');

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];

// ══════════════════════════════════════════════════════════
//  FILE DOWNLOAD HANDLER (must run before any HTML output)
// ══════════════════════════════════════════════════════════
// NOTE: assumes the `notes` table stores the uploaded filename in a
// `file_name` column and the physical file lives in ../uploads/notes/.
// Adjust the column name / path below if your schema differs.
if (isset($_GET['download'])) {
    $download_id = (int) $_GET['download'];

    $note_check = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT n.* FROM notes n
         INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
         WHERE n.id = '$download_id'
         AND ce.student_id = '$student_id'
         AND ce.status = 'enrolled'
         AND n.status = 'active'
         LIMIT 1"
    ));

    if ($note_check && !empty($note_check['file_name'])) {
        $file_path = '../uploads/notes/' . $note_check['file_name'];

        if (file_exists($file_path)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($note_check['file_name']) . '"');
            header('Content-Length: ' . filesize($file_path));
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            flush();
            readfile($file_path);
            exit;
        }
    }

    // Not found / not authorized — bounce back with an error flag
    header('Location: notes.php?error=download_failed');
    exit;
}

// ── Fetch student info ───────────────────────────────────
$student_query = mysqli_query($conn, "SELECT * FROM users WHERE id = '$student_id'");
$student       = mysqli_fetch_assoc($student_query);

// ── Filters: search + course ──────────────────────────────
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_safe   = mysqli_real_escape_string($conn, $search);
$search_clause = '';
if ($search !== '') {
    $search_clause = "AND (n.title LIKE '%$search_safe%'
                       OR c.course_name LIKE '%$search_safe%'
                       OR c.course_code LIKE '%$search_safe%')";
}

$filter_course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$course_clause     = '';
if ($filter_course_id > 0) {
    $course_clause = "AND n.course_id = '$filter_course_id'";
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

$courses_with_notes = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(DISTINCT n.course_id) as total FROM notes n
                          INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'
                          AND n.status = 'active'")
)['total'];

$new_this_week = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notes n
                          INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
                          WHERE ce.student_id = '$student_id'
                          AND ce.status = 'enrolled'
                          AND n.status = 'active'
                          AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
)['total'];

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

// If filtering by a course, fetch its details for the banner
$filter_course = null;
if ($filter_course_id > 0) {
    $filter_course = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT course_name, course_code FROM courses WHERE id = '$filter_course_id'"
    ));
}

// ── Notes list (filtered) ─────────────────────────────────
$notes_list = mysqli_query($conn,
    "SELECT n.*, c.course_name, c.course_code, u.name AS lecturer_name
     FROM notes n
     INNER JOIN courses c ON n.course_id = c.id
     INNER JOIN users u ON n.uploaded_by = u.id
     INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
     WHERE ce.student_id = '$student_id'
     AND ce.status = 'enrolled'
     AND n.status = 'active'
     $course_clause
     $search_clause
     ORDER BY n.created_at DESC"
);
$total_results = mysqli_num_rows($notes_list);

// ── Helpers: file icon + size formatting ──────────────────
function note_file_icon($filename) {
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf':  return ['bi-file-earmark-pdf-fill', 'pdf'];
        case 'doc':
        case 'docx': return ['bi-file-earmark-word-fill', 'word'];
        case 'ppt':
        case 'pptx': return ['bi-file-earmark-ppt-fill', 'ppt'];
        case 'xls':
        case 'xlsx': return ['bi-file-earmark-excel-fill', 'excel'];
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':  return ['bi-file-earmark-image-fill', 'image'];
        case 'zip':
        case 'rar':  return ['bi-file-earmark-zip-fill', 'zip'];
        default:     return ['bi-file-earmark-fill', 'default'];
    }
}

function note_file_size($bytes) {
    if (!is_numeric($bytes) || $bytes <= 0) return null;
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes & Resources - OnlineLMS</title>
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

        /* ── Search Bar ── */
        .search-bar {
            position: relative;
            min-width: 260px;
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
            min-width: 200px;
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
        }

        .filter-banner a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .filter-banner a:hover { text-decoration: underline; }

        /* ── Section Card / List ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .note-row {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: background 0.2s;
            flex-wrap: wrap;
        }

        .note-row:last-child { border-bottom: none; }
        .note-row:hover { background: var(--bg-hover); }

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

        .item-icon.pdf     { background: rgba(255,107,107,0.15); color: var(--red);    }
        .item-icon.word    { background: rgba(0,212,255,0.15);   color: var(--accent); }
        .item-icon.ppt     { background: rgba(255,107,107,0.15); color: #ff9472;       }
        .item-icon.excel   { background: rgba(107,203,119,0.15); color: var(--green);  }
        .item-icon.image   { background: rgba(180,143,252,0.15); color: var(--purple); }
        .item-icon.zip     { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .item-icon.default { background: rgba(255,255,255,0.08); color: var(--muted);  }

        .note-info {
            flex: 1;
            min-width: 200px;
        }

        .note-title {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
        }

        .note-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.78rem;
            color: var(--muted);
        }

        .note-meta i { margin-right: 3px; }

        .badge-glass {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .badge-blue   { background: rgba(0,212,255,0.15);  color: var(--accent); border: 1px solid rgba(0,212,255,0.3);  }
        .badge-new    { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }

        .note-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .note-date {
            font-size: 0.75rem;
            color: var(--muted);
            text-align: right;
            min-width: 60px;
        }

        .btn-download {
            background: rgba(0,212,255,0.12);
            color: var(--accent);
            border: 1px solid rgba(0,212,255,0.3);
            border-radius: 10px;
            padding: 8px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-download:hover {
            background: rgba(0,212,255,0.22);
            color: var(--accent);
        }

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

        /* ── Alert ── */
        .alert-glass {
            background: rgba(255,107,107,0.1);
            border: 1px solid rgba(255,107,107,0.3);
            color: var(--red);
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            .note-right {
                margin-left: 0;
                width: 100%;
                justify-content: space-between;
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
        <a href="notes.php" class="active">
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
                <h6>Notes & Resources</h6>
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

        <?php if (isset($_GET['error']) && $_GET['error'] === 'download_failed'): ?>
            <div class="alert-glass">
                <i class="bi bi-exclamation-triangle"></i>
                That file couldn't be found or you don't have access to it.
            </div>
        <?php endif; ?>

        <!-- ── Stat Cards Row ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon red"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $notes_count ?></h3>
                        <p>Total Notes</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_with_notes ?></h3>
                        <p>Courses with Notes</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-stars"></i></div>
                    <div class="stat-info">
                        <h3><?= $new_this_week ?></h3>
                        <p>New This Week</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-mortarboard"></i></div>
                    <div class="stat-info">
                        <h3><?= $courses_count ?></h3>
                        <p>Enrolled Courses</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Page Header / Filters ── -->
        <div class="page-header">
            <div>
                <h4>Notes & Resources</h4>
                <p>
                    <?= $total_results ?> note<?= $total_results == 1 ? '' : 's' ?>
                    <?= ($search !== '' || $filter_course_id > 0) ? 'matching your filters' : 'available' ?>
                </p>
            </div>
            <form action="notes.php" method="GET" class="filter-controls">
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
                    <input type="text" name="search" placeholder="Search notes by title or course..."
                           value="<?= htmlspecialchars($search) ?>">
                    <?php if ($search !== ''): ?>
                        <a href="notes.php<?= $filter_course_id > 0 ? '?course_id=' . $filter_course_id : '' ?>" class="search-clear">
                            <i class="bi bi-x-circle-fill"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── Active Course Filter Banner ── -->
        <?php if ($filter_course_id > 0 && $filter_course): ?>
            <div class="filter-banner">
                <span>
                    <i class="bi bi-funnel-fill me-2"></i>
                    Showing notes for
                    <strong><?= htmlspecialchars($filter_course['course_code']) ?> — <?= htmlspecialchars($filter_course['course_name']) ?></strong>
                </span>
                <a href="notes.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>">
                    Clear filter <i class="bi bi-x"></i>
                </a>
            </div>
        <?php endif; ?>

        <!-- ── Notes List ── -->
        <?php if ($total_results > 0): ?>
            <div class="section-card">
                <?php while ($note = mysqli_fetch_assoc($notes_list)): ?>
                    <?php
                        [$icon_class, $icon_type] = note_file_icon($note['file_name'] ?? $note['title']);
                        $size_label = isset($note['file_size']) ? note_file_size($note['file_size']) : null;
                        $is_new = strtotime($note['created_at']) >= strtotime('-7 days');
                    ?>
                    <div class="note-row">
                        <div class="item-icon <?= $icon_type ?>">
                            <i class="bi <?= $icon_class ?>"></i>
                        </div>
                        <div class="note-info">
                            <div class="note-title">
                                <?= htmlspecialchars($note['title']) ?>
                                <?php if ($is_new): ?>
                                    <span class="badge-glass badge-new ms-1">New</span>
                                <?php endif; ?>
                            </div>
                            <div class="note-meta">
                                <span class="badge-glass badge-blue"><?= htmlspecialchars($note['course_code']) ?></span>
                                <span><i class="bi bi-person"></i><?= htmlspecialchars($note['lecturer_name']) ?></span>
                                <?php if ($size_label): ?>
                                    <span><i class="bi bi-hdd"></i><?= $size_label ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="note-right">
                            <div class="note-date">
                                <?= date('d M Y', strtotime($note['created_at'])) ?>
                            </div>
                            <a href="notes.php?download=<?= $note['id'] ?>" class="btn-download">
                                <i class="bi bi-download me-1"></i>Download
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="section-card">
                <div class="empty-state">
                    <i class="bi bi-file-earmark-text"></i>
                    <p>
                        <?php if ($search !== '' || $filter_course_id > 0): ?>
                            No notes found matching your filters.
                        <?php else: ?>
                            No notes have been uploaded for your courses yet.
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