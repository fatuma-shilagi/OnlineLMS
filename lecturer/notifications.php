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

// ── Handle: Send Notification ────────────────────────────
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['send_notification'])) {
        $title       = mysqli_real_escape_string($conn, trim($_POST['title']));
        $message     = mysqli_real_escape_string($conn, trim($_POST['message']));
        $target_role = mysqli_real_escape_string($conn, $_POST['target_role']);
        $type        = mysqli_real_escape_string($conn, $_POST['type'] ?? 'general');

        if (empty($title) || empty($message)) {
            $error_msg = 'Title and message are required.';
        } else {
            mysqli_query($conn,
                "INSERT INTO notifications (title, message, target_role, type, sent_by, created_at)
                 VALUES ('$title', '$message', '$target_role', '$type', '$lecturer_id', NOW())"
            );
            $success_msg = 'Notification sent successfully!';
        }
    }

    if (isset($_POST['mark_all_read'])) {
        // Get all unread notification IDs for this user
        $unread = mysqli_query($conn,
            "SELECT n.id FROM notifications n
             LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$lecturer_id'
             WHERE (n.target_role = 'lecturer' OR n.target_role = 'all')
             AND nr.id IS NULL"
        );
        while ($nr = mysqli_fetch_assoc($unread)) {
            $nid = $nr['id'];
            mysqli_query($conn,
                "INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
                 VALUES ('$nid', '$lecturer_id', NOW())"
            );
        }
        $success_msg = 'All notifications marked as read.';
    }

    if (isset($_POST['mark_read']) && isset($_POST['notif_id'])) {
        $nid = (int) $_POST['notif_id'];
        mysqli_query($conn,
            "INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
             VALUES ('$nid', '$lecturer_id', NOW())"
        );
    }
}

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
                          WHERE a.created_by = '$lecturer_id' AND g.id IS NULL")
)['total'];

$notifications_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications n
                          LEFT JOIN notification_reads nr ON n.id = nr.notification_id
                              AND nr.user_id = '$lecturer_id'
                          WHERE (n.target_role = 'lecturer' OR n.target_role = 'all')
                          AND nr.id IS NULL")
)['total'];

// ── Filter ───────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';   // all | unread | sent

// ── Pagination ────────────────────────────────────────────
$per_page = 12;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// ── Received notifications ────────────────────────────────
if ($filter !== 'sent') {
    $where_read = '';
    if ($filter === 'unread') {
        $where_read = 'AND nr.id IS NULL';
    }

    $total_rows = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as total FROM notifications n
         INNER JOIN users u ON n.sent_by = u.id
         LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$lecturer_id'
         WHERE (n.target_role = 'lecturer' OR n.target_role = 'all')
         $where_read"
    ))['total'];

    $notifications = mysqli_query($conn,
        "SELECT n.*, u.name AS sender_name,
                nr.id AS is_read, nr.read_at
         FROM notifications n
         INNER JOIN users u ON n.sent_by = u.id
         LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$lecturer_id'
         WHERE (n.target_role = 'lecturer' OR n.target_role = 'all')
         $where_read
         ORDER BY n.created_at DESC
         LIMIT $per_page OFFSET $offset"
    );
} else {
    // Sent by this lecturer
    $total_rows = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as total FROM notifications WHERE sent_by = '$lecturer_id'"
    ))['total'];

    $notifications = mysqli_query($conn,
        "SELECT n.*,
                u.name AS sender_name,
                (SELECT COUNT(*) FROM notification_reads WHERE notification_id = n.id) AS read_count
         FROM notifications n
         INNER JOIN users u ON n.sent_by = u.id
         WHERE n.sent_by = '$lecturer_id'
         ORDER BY n.created_at DESC
         LIMIT $per_page OFFSET $offset"
    );
}

$total_pages = max(1, ceil($total_rows / $per_page));

// ── Unread count for stats ────────────────────────────────
$unread_count = $notifications_count; // already computed

$total_received = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM notifications n
     WHERE (n.target_role = 'lecturer' OR n.target_role = 'all')"
))['total'];

$sent_count = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM notifications WHERE sent_by = '$lecturer_id'"
))['total'];

$query_params = http_build_query(array_filter(['filter' => $filter !== 'all' ? $filter : '']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - OnlineLMS</title>
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
        body { background: var(--bg-main); color: var(--text); font-family: 'Segoe UI', sans-serif; display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        .sidebar { width: var(--sidebar-w); background: rgba(255,255,255,0.03); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; height: 100vh; z-index: 100; transition: transform 0.3s; }
        .sidebar-brand { padding: 22px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.15rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.72rem; }
        .sidebar-profile { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .sidebar-profile img { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent); }
        .sidebar-profile .name { font-weight: 600; font-size: 0.88rem; color: var(--text); }
        .role-badge { background: rgba(255,217,61,0.15); color: var(--accent); border: 1px solid rgba(255,217,61,0.3); border-radius: 20px; padding: 1px 10px; font-size: 0.68rem; font-weight: 700; }
        .sidebar-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
        .nav-section { padding: 8px 20px 4px; font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted); }
        .sidebar-nav a { display: flex; align-items: center; gap: 11px; padding: 10px 20px; color: var(--muted); text-decoration: none; font-size: 0.875rem; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar-nav a:hover, .sidebar-nav a.active { color: var(--text); background: var(--bg-hover); border-left-color: var(--accent); }
        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1rem; width: 20px; }
        .nav-badge { margin-left: auto; background: var(--red); color: white; border-radius: 20px; padding: 1px 8px; font-size: 0.68rem; font-weight: 700; }
        .nav-badge.yellow { background: rgba(255,217,61,0.2); color: var(--accent); }
        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a { display: flex; align-items: center; gap: 10px; color: var(--red); text-decoration: none; font-size: 0.875rem; font-weight: 500; padding: 8px 0; transition: opacity 0.2s; }
        .sidebar-footer a:hover { opacity: 0.75; }

        /* ── Main ── */
        .main-content { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

        /* ── Topbar ── */
        .topbar { background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border); padding: 13px 28px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; backdrop-filter: blur(10px); }
        .topbar-left h6 { font-weight: 700; font-size: 1rem; margin: 0; }
        .topbar-left p { color: var(--muted); font-size: 0.78rem; margin: 0; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-btn { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 8px 14px; color: var(--muted); text-decoration: none; font-size: 0.82rem; font-weight: 500; transition: all 0.2s; display: flex; align-items: center; gap: 6px; border: none; cursor: pointer; }
        .topbar-btn:hover { border-color: var(--accent); color: var(--accent); }
        .topbar-btn.primary { background: rgba(255,217,61,0.12); border: 1px solid rgba(255,217,61,0.3); color: var(--accent); }
        .notif-btn { position: relative; background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 8px 12px; color: var(--muted); text-decoration: none; transition: all 0.2s; }
        .notif-btn:hover { border-color: var(--accent); color: var(--accent); }
        .notif-dot { position: absolute; top: 5px; right: 7px; width: 8px; height: 8px; background: var(--red); border-radius: 50%; border: 2px solid var(--bg-main); }
        .hamburger { display: none; background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 8px 12px; color: var(--text); cursor: pointer; }

        /* ── Page ── */
        .page-body { padding: 26px; flex: 1; }
        .page-title { font-weight: 800; font-size: 1.3rem; margin-bottom: 4px; }
        .page-subtitle { color: var(--muted); font-size: 0.82rem; }

        /* ── Stat Cards ── */
        .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); }
        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue); }
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green); }
        .si-red    { background: rgba(255,107,107,0.15); color: var(--red); }
        .si-purple { background: rgba(180,143,252,0.15); color: var(--purple); }
        .stat-info h3 { font-size: 1.55rem; font-weight: 800; line-height: 1; margin-bottom: 2px; }
        .stat-info p  { color: var(--muted); font-size: 0.75rem; margin: 0; }

        /* ── Tabs ── */
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }
        .filter-tab { padding: 8px 20px; border-radius: 30px; font-size: 0.8rem; font-weight: 600; border: 1px solid var(--border); text-decoration: none; color: var(--muted); transition: all 0.2s; }
        .filter-tab:hover { border-color: var(--accent); color: var(--accent); }
        .filter-tab.active { background: rgba(255,217,61,0.15); border-color: rgba(255,217,61,0.4); color: var(--accent); }
        .filter-tab.tab-unread.active { background: rgba(255,107,107,0.15); border-color: rgba(255,107,107,0.4); color: var(--red); }
        .filter-tab.tab-sent.active   { background: rgba(180,143,252,0.15); border-color: rgba(180,143,252,0.4); color: var(--purple); }

        /* ── Notification Cards ── */
        .notif-list { display: flex; flex-direction: column; gap: 10px; }

        .notif-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            gap: 16px;
            align-items: flex-start;
            transition: all 0.2s;
            position: relative;
        }
        .notif-card:hover { background: var(--bg-hover); }
        .notif-card.unread {
            border-left: 3px solid var(--accent);
            background: rgba(255,217,61,0.03);
        }

        .notif-icon-wrap {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .ni-general      { background: rgba(255,217,61,0.15);  color: var(--accent); }
        .ni-note         { background: rgba(0,212,255,0.15);   color: var(--blue); }
        .ni-assignment   { background: rgba(180,143,252,0.15); color: var(--purple); }
        .ni-grade        { background: rgba(107,203,119,0.15); color: var(--green); }
        .ni-announcement { background: rgba(255,107,107,0.15); color: var(--red); }

        .notif-content { flex: 1; min-width: 0; }
        .notif-title { font-size: 0.9rem; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .notif-msg   { font-size: 0.82rem; color: var(--muted); line-height: 1.55; margin-bottom: 8px; }
        .notif-meta  { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .notif-meta span { font-size: 0.72rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }

        .unread-dot { width: 8px; height: 8px; background: var(--accent); border-radius: 50%; display: inline-block; flex-shrink: 0; }

        .notif-actions { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; flex-shrink: 0; }

        /* ── Badges ── */
        .badge-glass { padding: 3px 10px; border-radius: 20px; font-size: 0.68rem; font-weight: 600; display: inline-block; white-space: nowrap; }
        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); border: 1px solid rgba(255,217,61,0.3); }
        .bg-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   border: 1px solid rgba(0,212,255,0.3); }
        .bg-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .bg-red    { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3); }
        .bg-purple { background: rgba(180,143,252,0.15); color: var(--purple); border: 1px solid rgba(180,143,252,0.3); }

        /* ── Buttons ── */
        .btn-mark-read { background: transparent; border: 1px solid var(--border); color: var(--muted); border-radius: 8px; padding: 4px 12px; font-size: 0.72rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
        .btn-mark-read:hover { border-color: var(--green); color: var(--green); }

        /* ── Compose Panel ── */
        .compose-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; margin-bottom: 26px; }
        .compose-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; }
        .compose-header h6 { font-weight: 700; font-size: 0.9rem; margin: 0; display: flex; align-items: center; gap: 8px; }
        .compose-header .toggle-icon { color: var(--muted); transition: transform 0.3s; }
        .compose-header.open .toggle-icon { transform: rotate(180deg); }
        .compose-body { padding: 22px; display: none; }
        .compose-body.open { display: block; }

        .form-label-custom { font-size: 0.75rem; font-weight: 700; color: var(--muted); margin-bottom: 6px; display: block; text-transform: uppercase; letter-spacing: 0.6px; }
        .form-control-custom { background: rgba(255,255,255,0.06); border: 1px solid var(--border); border-radius: 10px; color: var(--text); padding: 10px 14px; font-size: 0.85rem; width: 100%; outline: none; transition: border 0.2s; }
        .form-control-custom:focus { border-color: var(--accent); background: rgba(255,255,255,0.08); }
        .form-control-custom::placeholder { color: var(--muted); }
        .form-control-custom option { background: #1a1a2e; }

        .btn-send { background: var(--accent); color: #1a1a2e; border: none; border-radius: 10px; padding: 10px 24px; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-send:hover { background: #ffcd00; transform: translateY(-1px); }

        /* ── Type icons map ── */
        /* handled in PHP/JS */

        /* ── Empty state ── */
        .empty-state { padding: 60px 20px; text-align: center; color: var(--muted); background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.25; display: block; }
        .empty-state p { font-size: 0.85rem; margin: 0; }

        /* ── Pagination ── */
        .pagination-wrap { display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px; }
        .pagination-wrap p { color: var(--muted); font-size: 0.78rem; margin: 0; }
        .page-btns { display: flex; gap: 6px; }
        .page-btn { background: var(--bg-card); border: 1px solid var(--border); color: var(--muted); border-radius: 8px; padding: 6px 13px; font-size: 0.78rem; text-decoration: none; transition: all 0.2s; }
        .page-btn:hover, .page-btn.active { border-color: var(--accent); color: var(--accent); background: rgba(255,217,61,0.08); }
        .page-btn.disabled { opacity: 0.35; pointer-events: none; }

        /* ── Alerts ── */
        .alert-success { background: rgba(107,203,119,0.12); border: 1px solid rgba(107,203,119,0.3); color: var(--green); border-radius: 10px; padding: 12px 18px; font-size: 0.85rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-error   { background: rgba(255,107,107,0.12); border: 1px solid rgba(255,107,107,0.3); color: var(--red);   border-radius: 10px; padding: 12px 18px; font-size: 0.85rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .notif-actions { display: none; }
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
        <a href="dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
        <a href="courses.php">
            <i class="bi bi-book"></i> My Courses
            <?php if ($courses_count > 0): ?><span class="nav-badge yellow"><?= $courses_count ?></span><?php endif; ?>
        </a>
        <div class="nav-section">Teaching</div>
        <a href="upload_notes.php"><i class="bi bi-cloud-upload"></i> Upload Notes</a>
        <a href="view_notes.php">
            <i class="bi bi-file-earmark-text"></i> View Notes
            <?php if ($notes_count > 0): ?><span class="nav-badge yellow"><?= $notes_count ?></span><?php endif; ?>
        </a>
        <a href="create_assignment.php"><i class="bi bi-plus-circle"></i> Create Assignment</a>
        <a href="view_assignments.php">
            <i class="bi bi-clipboard2-check"></i> Assignments
            <?php if ($assignments_count > 0): ?><span class="nav-badge yellow"><?= $assignments_count ?></span><?php endif; ?>
        </a>
        <a href="grade_submissions.php">
            <i class="bi bi-patch-check"></i> Grade Submissions
            <?php if ($pending_grading > 0): ?><span class="nav-badge"><?= $pending_grading ?></span><?php endif; ?>
        </a>
        <div class="nav-section">Communication</div>
        <a href="notifications.php" class="active">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($notifications_count > 0): ?><span class="nav-badge"><?= $notifications_count ?></span><?php endif; ?>
        </a>
        <div class="nav-section">Account</div>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?')">
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
            <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
            <div class="topbar-left">
                <h6>Notifications</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <?php if ($notifications_count > 0): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="mark_all_read" value="1">
                <button type="submit" class="topbar-btn primary">
                    <i class="bi bi-check2-all"></i> Mark All Read
                </button>
            </form>
            <?php endif; ?>
            <a href="notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($notifications_count > 0): ?><span class="notif-dot"></span><?php endif; ?>
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

        <!-- Heading -->
        <div class="d-flex align-items-start justify-content-between mb-4">
            <div>
                <h4 class="page-title"><i class="bi bi-bell me-2" style="color:var(--accent)"></i>Notifications</h4>
                <p class="page-subtitle">Stay updated and send announcements to students or all users.</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-bell-fill"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_received ?></h3>
                        <p>Total Received</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-red"><i class="bi bi-bell-slash"></i></div>
                    <div class="stat-info">
                        <h3><?= $unread_count ?></h3>
                        <p>Unread</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-check2-all"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_received - $unread_count ?></h3>
                        <p>Read</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-purple"><i class="bi bi-megaphone-fill"></i></div>
                    <div class="stat-info">
                        <h3><?= $sent_count ?></h3>
                        <p>Sent by Me</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Compose / Send Notification ── -->
        <div class="compose-card">
            <div class="compose-header" id="composeToggle">
                <h6>
                    <i class="bi bi-megaphone" style="color:var(--accent)"></i>
                    Send a Notification
                </h6>
                <i class="bi bi-chevron-down toggle-icon"></i>
            </div>
            <div class="compose-body" id="composeBody">
                <form method="POST" action="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Title</label>
                            <input type="text" name="title" class="form-control-custom"
                                   placeholder="Notification title…" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Target Audience</label>
                            <select name="target_role" class="form-control-custom">
                                <option value="student">Students Only</option>
                                <option value="lecturer">Lecturers Only</option>
                                <option value="all">Everyone</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Type</label>
                            <select name="type" class="form-control-custom">
                                <option value="general">General</option>
                                <option value="announcement">Announcement</option>
                                <option value="assignment">Assignment</option>
                                <option value="note">Note / Resource</option>
                                <option value="grade">Grade</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Message</label>
                            <textarea name="message" class="form-control-custom" rows="4"
                                      placeholder="Write your notification message…" required></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" name="send_notification" class="btn-send">
                                <i class="bi bi-send"></i> Send Notification
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Filter Tabs ── -->
        <div class="filter-tabs">
            <a href="?filter=all"    class="filter-tab <?= $filter === 'all'    ? 'active' : '' ?>">
                All <span class="badge-glass bg-blue ms-1"><?= $total_received ?></span>
            </a>
            <a href="?filter=unread" class="filter-tab tab-unread <?= $filter === 'unread' ? 'active' : '' ?>">
                Unread
                <?php if ($unread_count > 0): ?>
                    <span class="badge-glass bg-red ms-1"><?= $unread_count ?></span>
                <?php endif; ?>
            </a>
            <a href="?filter=sent"   class="filter-tab tab-sent <?= $filter === 'sent'   ? 'active' : '' ?>">
                Sent by Me <span class="badge-glass bg-purple ms-1"><?= $sent_count ?></span>
            </a>
        </div>

        <!-- ── Notification List ── -->
        <?php
        $type_icons = [
            'note'         => ['icon' => 'bi-file-earmark-text', 'cls' => 'ni-note'],
            'assignment'   => ['icon' => 'bi-clipboard2',        'cls' => 'ni-assignment'],
            'grade'        => ['icon' => 'bi-star-fill',         'cls' => 'ni-grade'],
            'announcement' => ['icon' => 'bi-megaphone-fill',    'cls' => 'ni-announcement'],
            'general'      => ['icon' => 'bi-bell-fill',         'cls' => 'ni-general'],
        ];
        $target_labels = [
            'student'  => ['label' => 'Students',  'cls' => 'bg-blue'],
            'lecturer' => ['label' => 'Lecturers', 'cls' => 'bg-purple'],
            'all'      => ['label' => 'Everyone',  'cls' => 'bg-green'],
        ];
        ?>

        <?php if (mysqli_num_rows($notifications) > 0): ?>
        <div class="notif-list">
            <?php while ($n = mysqli_fetch_assoc($notifications)):
                $ti    = $type_icons[$n['type']] ?? $type_icons['general'];
                $tl    = $target_labels[$n['target_role']] ?? ['label' => $n['target_role'], 'cls' => 'bg-yellow'];
                $is_sent = ($filter === 'sent');
                $is_unread = !$is_sent && !$n['is_read'];
                $time_diff = human_time_diff(strtotime($n['created_at']));
            ?>
            <div class="notif-card <?= $is_unread ? 'unread' : '' ?>">
                <div class="notif-icon-wrap <?= $ti['cls'] ?>">
                    <i class="bi <?= $ti['icon'] ?>"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">
                        <?php if ($is_unread): ?>
                            <span class="unread-dot"></span>
                        <?php endif; ?>
                        <?= htmlspecialchars($n['title']) ?>
                        <span class="badge-glass <?= $tl['cls'] ?>"><?= $tl['label'] ?></span>
                        <?php
                            $type_badge_cls = [
                                'announcement' => 'bg-red',
                                'assignment'   => 'bg-purple',
                                'grade'        => 'bg-green',
                                'note'         => 'bg-blue',
                                'general'      => 'bg-yellow',
                            ][$n['type']] ?? 'bg-yellow';
                        ?>
                        <span class="badge-glass <?= $type_badge_cls ?>"><?= ucfirst($n['type']) ?></span>
                    </div>
                    <div class="notif-msg"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
                    <div class="notif-meta">
                        <span><i class="bi bi-person"></i><?= htmlspecialchars($n['sender_name']) ?></span>
                        <span><i class="bi bi-clock"></i><?= $time_diff ?></span>
                        <span><i class="bi bi-calendar3"></i><?= date('d M Y, H:i', strtotime($n['created_at'])) ?></span>
                        <?php if ($is_sent && isset($n['read_count'])): ?>
                            <span><i class="bi bi-eye"></i><?= $n['read_count'] ?> read</span>
                        <?php endif; ?>
                        <?php if (!$is_sent && $n['is_read']): ?>
                            <span style="color:var(--green);"><i class="bi bi-check2-all"></i>Read</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($is_unread): ?>
                <div class="notif-actions">
                    <form method="POST">
                        <input type="hidden" name="mark_read" value="1">
                        <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                        <button type="submit" class="btn-mark-read">
                            <i class="bi bi-check2 me-1"></i>Mark Read
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        </div>

        <!-- Pagination -->
        <div class="pagination-wrap">
            <p>Showing <?= min($offset + 1, $total_rows) ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></p>
            <div class="page-btns">
                <a href="?<?= $query_params ?>&page=<?= $page - 1 ?>"
                   class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <a href="?<?= $query_params ?>&page=<?= $p ?>"
                       class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?<?= $query_params ?>&page=<?= $page + 1 ?>"
                   class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

        <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-bell-slash"></i>
            <p>
                <?php if ($filter === 'unread'): ?>
                    No unread notifications — you're all caught up!
                <?php elseif ($filter === 'sent'): ?>
                    You haven't sent any notifications yet.
                <?php else: ?>
                    No notifications yet.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── Sidebar ──
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('overlay');
    const hamburger = document.getElementById('hamburger');
    hamburger.addEventListener('click', () => { sidebar.classList.add('open'); overlay.style.display = 'block'; });
    function closeSidebar() { sidebar.classList.remove('open'); overlay.style.display = 'none'; }

    // ── Compose toggle ──
    const composeToggle = document.getElementById('composeToggle');
    const composeBody   = document.getElementById('composeBody');
    composeToggle.addEventListener('click', () => {
        const open = composeBody.classList.toggle('open');
        composeToggle.classList.toggle('open', open);
    });

    // Auto-open compose if there was an error or it's a fresh send
    <?php if ($error_msg || isset($_POST['send_notification'])): ?>
    composeBody.classList.add('open');
    composeToggle.classList.add('open');
    <?php endif; ?>
</script>
</body>
</html>
<?php
// ── Helper: human-readable time diff ──
function human_time_diff(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M Y', $ts);
}
?>