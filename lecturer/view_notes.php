<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('lecturer');

$lecturer_id   = $_SESSION['user_id'];
$lecturer_name = $_SESSION['user_name'];

$success_msg = '';
$error_msg   = '';

// ── Lecturer info (for sidebar avatar) ───────────────────
$lecturer = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM users WHERE id = '$lecturer_id'")
);

// ════════════════════════════════════════════════════════
// HANDLE: Edit Note (title / description)
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_note'])) {
    $note_id     = (int) ($_POST['note_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === '') {
        $error_msg = 'Title cannot be empty.';
    } else {
        $edit_stmt = mysqli_prepare($conn,
            "UPDATE notes SET title = ?, description = ? WHERE id = ? AND uploaded_by = ?");
        mysqli_stmt_bind_param($edit_stmt, "ssii", $title, $description, $note_id, $lecturer_id);

        if (mysqli_stmt_execute($edit_stmt) && mysqli_stmt_affected_rows($edit_stmt) >= 0) {
            $success_msg = 'Note updated successfully.';
        } else {
            $error_msg = 'Could not update that note.';
        }
    }
}

// ════════════════════════════════════════════════════════
// HANDLE: Delete (deactivate) Note
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'])) {
    $note_id = (int) ($_POST['note_id'] ?? 0);

    $del_stmt = mysqli_prepare($conn,
        "UPDATE notes SET status = 'inactive' WHERE id = ? AND uploaded_by = ?");
    mysqli_stmt_bind_param($del_stmt, "ii", $note_id, $lecturer_id);

    if (mysqli_stmt_execute($del_stmt) && mysqli_stmt_affected_rows($del_stmt) > 0) {
        $success_msg = 'Note removed.';
    } else {
        $error_msg = 'Could not remove that note.';
    }
}

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

// ── Storage used by this lecturer's notes ─────────────────
$storage_used = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT SUM(file_size) as total FROM notes
                          WHERE uploaded_by = '$lecturer_id' AND status = 'active'")
)['total'] ?? 0;

// ── All notes uploaded by this lecturer ───────────────────
$notes_stmt = mysqli_prepare($conn,
    "SELECT n.*, c.course_name, c.course_code
     FROM notes n
     INNER JOIN courses c ON n.course_id = c.id
     WHERE n.uploaded_by = ?
     AND n.status = 'active'
     ORDER BY n.created_at DESC");
mysqli_stmt_bind_param($notes_stmt, "i", $lecturer_id);
mysqli_stmt_execute($notes_stmt);
$notes_result = mysqli_stmt_get_result($notes_stmt);

$all_notes      = [];
$course_filters = []; // unique courses for filter chips, keyed by course_id

while ($row = mysqli_fetch_assoc($notes_result)) {
    $all_notes[] = $row;
    if (!isset($course_filters[$row['course_id']])) {
        $course_filters[$row['course_id']] = $row['course_code'];
    }
}

$total_notes_found   = count($all_notes);
$preselected_course  = (int) ($_GET['course_id'] ?? 0);

// File icon helper
function noteFileIcon($ext) {
    $ext = strtolower($ext);
    $map = [
        'pdf'  => 'bi-file-earmark-pdf',
        'doc'  => 'bi-file-earmark-word', 'docx' => 'bi-file-earmark-word',
        'ppt'  => 'bi-file-earmark-ppt',  'pptx' => 'bi-file-earmark-ppt',
        'xls'  => 'bi-file-earmark-excel','xlsx' => 'bi-file-earmark-excel',
        'txt'  => 'bi-file-earmark-text',
        'zip'  => 'bi-file-earmark-zip',
        'png'  => 'bi-file-earmark-image', 'jpg' => 'bi-file-earmark-image', 'jpeg' => 'bi-file-earmark-image',
    ];
    return $map[$ext] ?? 'bi-file-earmark';
}

// Human readable file size
function humanFileSize($bytes) {
    if (!$bytes) return '—';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notes - OnlineLMS</title>
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

        /* ── Alerts ── */
        .alert-glass {
            border-radius: 12px;
            padding: 13px 18px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success-glass {
            background: rgba(107,203,119,0.12);
            border: 1px solid rgba(107,203,119,0.3);
            color: var(--green);
        }

        .alert-danger-glass {
            background: rgba(255,107,107,0.12);
            border: 1px solid rgba(255,107,107,0.3);
            color: var(--red);
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
            height: 100%;
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

        /* ── Filter Bar ── */
        .filter-bar {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 22px;
        }

        .search-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .search-row i { color: var(--muted); font-size: 1rem; }

        .search-row input {
            flex: 1;
            background: transparent;
            border: none;
            color: var(--text);
            font-size: 0.88rem;
            outline: none;
        }

        .search-row input::placeholder { color: rgba(255,255,255,0.3); }

        .search-count {
            color: var(--muted);
            font-size: 0.78rem;
            flex-shrink: 0;
        }

        .chip-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-chip {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.76rem;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-chip:hover { border-color: var(--accent); color: var(--accent); }

        .filter-chip.active {
            background: rgba(255,217,61,0.15);
            border-color: var(--accent);
            color: var(--accent);
        }

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        /* ── Note Row ── */
        .note-row {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: background 0.2s;
        }

        .note-row:last-child { border-bottom: none; }
        .note-row:hover { background: var(--bg-hover); }

        .note-icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            background: rgba(0,212,255,0.15);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .note-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 3px;
        }

        .note-desc {
            font-size: 0.76rem;
            color: var(--muted);
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .note-meta {
            font-size: 0.72rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .badge-glass {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 600;
            display: inline-block;
        }

        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); border: 1px solid rgba(255,217,61,0.3);  }

        .note-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .icon-btn {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .icon-btn:hover { border-color: var(--accent); color: var(--accent); background: rgba(255,217,61,0.08); }
        .icon-btn.danger:hover { border-color: var(--red); color: var(--red); background: rgba(255,107,107,0.08); }

        /* ── Empty State ── */
        .empty-state-big {
            padding: 60px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state-big i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.3;
            display: block;
        }

        .empty-state-big h6 {
            color: var(--text);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .empty-state-big p { font-size: 0.85rem; margin: 0; }

        /* ── Modal ── */
        .modal-content {
            background: #15152a;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 16px;
        }

        .modal-header { border-bottom: 1px solid var(--border); }
        .modal-footer { border-top: 1px solid var(--border); }
        .btn-close { filter: invert(1) grayscale(100%) brightness(2); }

        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-control {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.85rem;
        }

        .form-control:focus {
            background: rgba(255,255,255,0.07);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255,217,61,0.15);
            color: var(--text);
        }

        textarea.form-control { resize: vertical; min-height: 80px; }

        .btn-save {
            background: var(--accent);
            color: #1a1a2e;
            border: none;
            border-radius: 10px;
            padding: 9px 20px;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-save:hover { background: #ffcd00; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .note-row { flex-wrap: wrap; }
            .note-actions { margin-left: 0; width: 100%; justify-content: flex-end; }
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
        <a href="view_notes.php" class="active">
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
                <h6>My Notes</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="upload_notes.php" class="topbar-btn primary d-none d-md-flex">
                <i class="bi bi-cloud-upload"></i> Upload Notes
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
                <h4>My Notes</h4>
                <p>All lecture materials you've shared with your students.</p>
            </div>
            <a href="upload_notes.php" class="topbar-btn primary">
                <i class="bi bi-cloud-upload"></i> Upload New Note
            </a>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert-glass alert-success-glass">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert-glass alert-danger-glass">
                <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- ── Quick Stats ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-4">
                <div class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="stat-info">
                        <h3><?= $notes_count ?></h3>
                        <p>Total Notes</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-book"></i></div>
                    <div class="stat-info">
                        <h3><?= count($course_filters) ?></h3>
                        <p>Courses Covered</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="stat-card">
                    <div class="stat-icon si-purple"><i class="bi bi-hdd"></i></div>
                    <div class="stat-info">
                        <h3><?= humanFileSize($storage_used) ?></h3>
                        <p>Storage Used</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Filter Bar ── -->
        <div class="filter-bar">
            <div class="search-row">
                <i class="bi bi-search"></i>
                <input type="text" id="noteSearch" placeholder="Search notes by title...">
                <span class="search-count" id="searchCount"><?= $total_notes_found ?> note<?= $total_notes_found !== 1 ? 's' : '' ?></span>
            </div>
            <div class="chip-row" id="chipRow">
                <span class="filter-chip active" data-course="all">All Courses</span>
                <?php foreach ($course_filters as $cid => $ccode): ?>
                    <span class="filter-chip" data-course="<?= $cid ?>"><?= htmlspecialchars($ccode) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Notes List ── -->
        <div class="section-card">
            <?php if ($total_notes_found > 0): ?>
                <div id="notesList">
                    <?php foreach ($all_notes as $note): ?>
                        <div class="note-row"
                             data-course="<?= $note['course_id'] ?>"
                             data-search="<?= htmlspecialchars(strtolower($note['title'])) ?>">

                            <div class="note-icon">
                                <i class="bi <?= noteFileIcon($note['file_type'] ?? '') ?>"></i>
                            </div>

                            <div style="flex:1; min-width:0;">
                                <div class="note-title text-truncate"><?= htmlspecialchars($note['title']) ?></div>
                                <?php if (!empty($note['description'])): ?>
                                    <div class="note-desc"><?= htmlspecialchars($note['description']) ?></div>
                                <?php endif; ?>
                                <div class="note-meta">
                                    <span class="badge-glass bg-yellow"><?= htmlspecialchars($note['course_code']) ?></span>
                                    <span><?= isset($note['file_size']) ? humanFileSize($note['file_size']) : '' ?></span>
                                    <span>&middot;</span>
                                    <span><?= date('d M Y', strtotime($note['created_at'])) ?></span>
                                </div>
                            </div>

                            <div class="note-actions">
                                <?php if (!empty($note['file_name'])): ?>
                                    <a href="../uploads/notes/<?= htmlspecialchars($note['file_name']) ?>"
                                       download="<?= htmlspecialchars($note['original_filename'] ?? $note['file_name']) ?>"
                                       class="icon-btn" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                <?php endif; ?>
                                <span class="icon-btn" title="Edit"
                                      data-bs-toggle="modal" data-bs-target="#editModal<?= $note['id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </span>
                                <form method="POST" action="view_notes.php" onsubmit="return confirm('Remove this note? Students will no longer see it.');">
                                    <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                    <button type="submit" name="delete_note" value="1" class="icon-btn danger" title="Delete">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal<?= $note['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="POST" action="view_notes.php">
                                        <div class="modal-header">
                                            <h6 class="modal-title"><i class="bi bi-pencil-square me-2" style="color:var(--accent)"></i>Edit Note</h6>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Title</label>
                                                <input type="text" class="form-control" name="title" required
                                                       value="<?= htmlspecialchars($note['title']) ?>">
                                            </div>
                                            <div class="mb-1">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="description" maxlength="500"><?= htmlspecialchars($note['description'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="submit" name="edit_note" value="1" class="btn-save">
                                                <i class="bi bi-check-lg"></i> Save Changes
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="noResults" class="empty-state-big" style="display:none;">
                    <i class="bi bi-search"></i>
                    <h6>No matching notes</h6>
                    <p>Try a different search term or course filter.</p>
                </div>
            <?php else: ?>
                <div class="empty-state-big">
                    <i class="bi bi-file-earmark-text"></i>
                    <h6>No notes uploaded yet</h6>
                    <p>Notes you upload for your courses will appear here.</p>
                </div>
            <?php endif; ?>
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

    // ── Search + course filter (combined) ──
    const searchInput = document.getElementById('noteSearch');
    const searchCount  = document.getElementById('searchCount');
    const noResults    = document.getElementById('noResults');
    const chips        = document.querySelectorAll('.filter-chip');
    let activeCourse   = 'all';

    function applyFilters() {
        const term  = (searchInput?.value || '').trim().toLowerCase();
        const rows  = document.querySelectorAll('.note-row');
        let visible = 0;

        rows.forEach(row => {
            const matchesSearch = row.dataset.search.includes(term);
            const matchesCourse = activeCourse === 'all' || row.dataset.course === activeCourse;
            const show = matchesSearch && matchesCourse;
            row.style.display = show ? 'flex' : 'none';
            if (show) visible++;
        });

        if (searchCount) {
            searchCount.textContent = visible + ' note' + (visible !== 1 ? 's' : '');
        }
        if (noResults) {
            noResults.style.display = visible === 0 ? 'block' : 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            activeCourse = chip.dataset.course;
            applyFilters();
        });
    });

    // Pre-filter from ?course_id= in the URL
    <?php if ($preselected_course > 0): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const target = document.querySelector('.filter-chip[data-course="<?= $preselected_course ?>"]');
            if (target) target.click();
        });
    <?php endif; ?>
</script>
</body>
</html>