<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('lecturer');

$lecturer_id   = $_SESSION['user_id'];
$lecturer_name = $_SESSION['user_name'];

$success_msg = '';
$error_msg   = '';

// Allowed file types & size limit for note uploads
$allowed_ext  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'png', 'jpg', 'jpeg'];
$max_size_mb  = 25;
$max_size_b   = $max_size_mb * 1024 * 1024;

// ── Lecturer info (for sidebar avatar) ───────────────────
$lecturer = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM users WHERE id = '$lecturer_id'")
);

// ════════════════════════════════════════════════════════
// HANDLE: Upload Note
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_note'])) {

    $course_id   = (int) ($_POST['course_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($course_id <= 0 || $title === '') {
        $error_msg = 'Please select a course and enter a title.';
    } elseif (!isset($_FILES['note_file']) || $_FILES['note_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error_msg = 'Please choose a file to upload.';
    } else {

        // Confirm the course belongs to this lecturer
        $course_check = mysqli_prepare($conn,
            "SELECT id, course_code FROM courses WHERE id = ? AND lecturer_id = ? AND status = 'active'");
        mysqli_stmt_bind_param($course_check, "ii", $course_id, $lecturer_id);
        mysqli_stmt_execute($course_check);
        $course_row = mysqli_fetch_assoc(mysqli_stmt_get_result($course_check));

        if (!$course_row) {
            $error_msg = 'Invalid course selected.';
        } elseif ($_FILES['note_file']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = 'There was a problem uploading your file. Please try again.';
        } else {

            $tmp_path  = $_FILES['note_file']['tmp_name'];
            $orig_name = $_FILES['note_file']['name'];
            $file_size = $_FILES['note_file']['size'];
            $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_ext)) {
                $error_msg = 'File type not allowed. Allowed types: ' . strtoupper(implode(', ', $allowed_ext)) . '.';
            } elseif ($file_size > $max_size_b) {
                $error_msg = "File is too large. Maximum size is {$max_size_mb}MB.";
            } else {
                $upload_dir = '../uploads/notes/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $safe_base    = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($orig_name, PATHINFO_FILENAME));
                $stored_name  = 'note_' . $course_id . '_' . time() . '_' . $safe_base . '.' . $ext;

                if (move_uploaded_file($tmp_path, $upload_dir . $stored_name)) {
                    $insert_stmt = mysqli_prepare($conn,
                        "INSERT INTO notes
                            (course_id, uploaded_by, title, description, file_name, file_size, file_type, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                    mysqli_stmt_bind_param($insert_stmt, "iisssis",
                        $course_id, $lecturer_id, $title, $description, $stored_name, $file_size, $ext);

                    if (mysqli_stmt_execute($insert_stmt)) {
                        $success_msg = 'Note uploaded successfully to ' . htmlspecialchars($course_row['course_code']) . '.';
                    } else {
                        $error_msg = 'Upload saved the file but failed to record it. Please contact support.';
                    }
                } else {
                    $error_msg = 'Failed to save the uploaded file. Please try again.';
                }
            }
        }
    }
}

// ════════════════════════════════════════════════════════
// HANDLE: Soft-delete a note (deactivate)
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

// ── My active courses (for the dropdown) ─────────────────
$courses_stmt = mysqli_prepare($conn,
    "SELECT id, course_code, course_name FROM courses
     WHERE lecturer_id = ? AND status = 'active'
     ORDER BY course_name ASC");
mysqli_stmt_bind_param($courses_stmt, "i", $lecturer_id);
mysqli_stmt_execute($courses_stmt);
$my_courses_result = mysqli_stmt_get_result($courses_stmt);
$has_courses = mysqli_num_rows($my_courses_result) > 0;

// Store courses in an array so the dropdown can be rendered + re-checked
$my_courses_list = [];
while ($row = mysqli_fetch_assoc($my_courses_result)) {
    $my_courses_list[] = $row;
}

// Pre-select course if passed via ?course_id=
$preselected_course = (int) ($_GET['course_id'] ?? 0);

// ── My recent uploaded notes ──────────────────────────────
$recent_notes_stmt = mysqli_prepare($conn,
    "SELECT n.*, c.course_name, c.course_code
     FROM notes n
     INNER JOIN courses c ON n.course_id = c.id
     WHERE n.uploaded_by = ?
     AND n.status = 'active'
     ORDER BY n.created_at DESC
     LIMIT 10");
mysqli_stmt_bind_param($recent_notes_stmt, "i", $lecturer_id);
mysqli_stmt_execute($recent_notes_stmt);
$recent_notes_result = mysqli_stmt_get_result($recent_notes_stmt);

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
    <title>Upload Notes - OnlineLMS</title>
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
            margin-bottom: 22px;
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

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            height: 100%;
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

        .section-body { padding: 22px; }

        /* ── Forms ── */
        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.85rem;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(255,255,255,0.07);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255,217,61,0.15);
            color: var(--text);
        }

        .form-select option { background: #15152a; color: var(--text); }
        .form-control::placeholder { color: rgba(255,255,255,0.3); }
        textarea.form-control { resize: vertical; min-height: 90px; }

        .form-hint {
            font-size: 0.72rem;
            color: var(--muted);
            margin-top: 5px;
        }

        .btn-save {
            background: var(--accent);
            color: #1a1a2e;
            border: none;
            border-radius: 10px;
            padding: 10px 24px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-save:hover { background: #ffcd00; transform: translateY(-1px); }
        .btn-save:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ── Dropzone ── */
        .dropzone {
            border: 2px dashed var(--border);
            border-radius: 14px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: rgba(255,255,255,0.02);
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: var(--accent);
            background: rgba(255,217,61,0.06);
        }

        .dropzone i {
            font-size: 1.8rem;
            color: var(--accent);
            margin-bottom: 8px;
            display: block;
        }

        .dropzone p {
            font-size: 0.85rem;
            color: var(--text);
            margin-bottom: 3px;
            font-weight: 600;
        }

        .dropzone span {
            font-size: 0.74rem;
            color: var(--muted);
        }

        .dropzone input[type="file"] { display: none; }

        .file-chosen {
            display: none;
            align-items: center;
            gap: 10px;
            background: rgba(255,217,61,0.08);
            border: 1px solid rgba(255,217,61,0.25);
            border-radius: 12px;
            padding: 12px 16px;
            margin-top: 12px;
        }

        .file-chosen i { font-size: 1.4rem; color: var(--accent); }
        .file-chosen .fc-name { font-size: 0.82rem; font-weight: 600; }
        .file-chosen .fc-size { font-size: 0.72rem; color: var(--muted); }
        .file-chosen .fc-remove {
            margin-left: auto;
            cursor: pointer;
            color: var(--muted);
            font-size: 1rem;
        }
        .file-chosen .fc-remove:hover { color: var(--red); }

        /* ── List Items (recent uploads) ── */
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
            background: rgba(0,212,255,0.15);
            color: var(--blue);
        }

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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge-glass {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); border: 1px solid rgba(255,217,61,0.3);  }

        .remove-note-btn {
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: 1rem;
            transition: color 0.2s;
        }

        .remove-note-btn:hover { color: var(--red); }

        /* ── Empty State ── */
        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.35;
            display: block;
        }

        .empty-state p { font-size: 0.82rem; margin: 0; }

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
        <a href="courses.php">
            <i class="bi bi-book"></i> My Courses
            <?php if ($courses_count > 0): ?>
                <span class="nav-badge yellow"><?= $courses_count ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Teaching</div>
        <a href="upload_notes.php" class="active">
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
                <h6>Upload Notes</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="view_notes.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-file-earmark-text"></i> View Notes
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
            <h4>Upload Notes</h4>
            <p>Share lecture notes and materials with students enrolled in your courses.</p>
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

        <?php if (!$has_courses): ?>
            <div class="section-card">
                <div class="empty-state">
                    <i class="bi bi-book"></i>
                    <p>You have no active courses assigned yet, so you can't upload notes.</p>
                    <p>Once a course is assigned to you, it will appear here.</p>
                </div>
            </div>
        <?php else: ?>

            <div class="row g-4">

                <!-- Upload Form -->
                <div class="col-lg-7">
                    <div class="section-card">
                        <div class="section-header">
                            <h6><i class="bi bi-cloud-upload" style="color:var(--accent)"></i> New Note</h6>
                        </div>
                        <div class="section-body">
                            <form method="POST" action="upload_notes.php" enctype="multipart/form-data" id="uploadForm">

                                <div class="mb-3">
                                    <label class="form-label">Course</label>
                                    <select class="form-select" name="course_id" required>
                                        <option value="" disabled <?= $preselected_course === 0 ? 'selected' : '' ?>>Select a course...</option>
                                        <?php foreach ($my_courses_list as $c): ?>
                                            <option value="<?= $c['id'] ?>" <?= $preselected_course === (int)$c['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Title</label>
                                    <input type="text" class="form-control" name="title" required
                                           placeholder="e.g. Week 5 - Introduction to Algorithms">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Description <span style="color:var(--muted); font-weight:400;">(optional)</span></label>
                                    <textarea class="form-control" name="description" maxlength="500"
                                              placeholder="A short note about what this file covers..."></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">File</label>
                                    <div class="dropzone" id="dropzone">
                                        <i class="bi bi-cloud-arrow-up"></i>
                                        <p>Click to browse or drag a file here</p>
                                        <span>PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, ZIP, PNG, JPG — up to <?= $max_size_mb ?>MB</span>
                                        <input type="file" name="note_file" id="noteFile"
                                               accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.png,.jpg,.jpeg">
                                    </div>
                                    <div class="file-chosen" id="fileChosen">
                                        <i class="bi bi-file-earmark"></i>
                                        <div>
                                            <div class="fc-name" id="fcName"></div>
                                            <div class="fc-size" id="fcSize"></div>
                                        </div>
                                        <i class="bi bi-x-circle fc-remove" id="fcRemove"></i>
                                    </div>
                                </div>

                                <button type="submit" name="upload_note" value="1" class="btn-save" id="submitBtn">
                                    <i class="bi bi-cloud-upload"></i> Upload Note
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Recent Uploads -->
                <div class="col-lg-5">
                    <div class="section-card">
                        <div class="section-header">
                            <h6><i class="bi bi-clock-history" style="color:var(--blue)"></i> Recently Uploaded</h6>
                            <a href="view_notes.php" style="color:var(--accent); font-size:0.78rem; text-decoration:none;">View All <i class="bi bi-arrow-right"></i></a>
                        </div>

                        <?php if (mysqli_num_rows($recent_notes_result) > 0): ?>
                            <?php while ($note = mysqli_fetch_assoc($recent_notes_result)): ?>
                                <div class="list-item">
                                    <div class="item-icon">
                                        <i class="bi <?= noteFileIcon($note['file_type'] ?? '') ?>"></i>
                                    </div>
                                    <div style="flex:1; min-width:0;">
                                        <div class="item-title text-truncate"><?= htmlspecialchars($note['title']) ?></div>
                                        <div class="item-sub">
                                            <span class="badge-glass bg-yellow"><?= htmlspecialchars($note['course_code']) ?></span>
                                            <?= isset($note['file_size']) ? humanFileSize($note['file_size']) : '' ?>
                                            &middot; <?= date('d M', strtotime($note['created_at'])) ?>
                                        </div>
                                    </div>
                                    <div class="item-right">
                                        <form method="POST" action="upload_notes.php" onsubmit="return confirm('Remove this note? Students will no longer see it.');">
                                            <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                            <button type="submit" name="delete_note" value="1" class="remove-note-btn" title="Remove">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
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
                </div>

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

    // ── Dropzone / file picker behaviour ──
    const dropzone   = document.getElementById('dropzone');
    const fileInput  = document.getElementById('noteFile');
    const fileChosen = document.getElementById('fileChosen');
    const fcName     = document.getElementById('fcName');
    const fcSize     = document.getElementById('fcSize');
    const fcRemove   = document.getElementById('fcRemove');

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
    }

    function showChosenFile(file) {
        fcName.textContent  = file.name;
        fcSize.textContent  = formatBytes(file.size);
        fileChosen.style.display = 'flex';
        dropzone.style.display = 'none';
    }

    if (dropzone) {
        dropzone.addEventListener('click', () => fileInput.click());

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showChosenFile(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                showChosenFile(fileInput.files[0]);
            }
        });

        fcRemove.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput.value = '';
            fileChosen.style.display = 'none';
            dropzone.style.display = 'block';
        });
    }
</script>
</body>
</html>