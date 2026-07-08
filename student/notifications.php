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

// ── Mark notification as read (if action triggered) ──────
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notif_id = (int)$_GET['mark_read'];
    $check = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM notification_reads
         WHERE notification_id = '$notif_id' AND user_id = '$student_id'"
    ));
    if (!$check) {
        mysqli_query($conn,
            "INSERT INTO notification_reads (notification_id, user_id, read_at)
             VALUES ('$notif_id', '$student_id', NOW())"
        );
    }
    header("Location: notifications.php");
    exit;
}

// ── Mark ALL as read ─────────────────────────────────────
if (isset($_GET['mark_all_read'])) {
    $unread = mysqli_query($conn,
        "SELECT n.id FROM notifications n
         LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$student_id'
         WHERE (n.target_role = 'student' OR n.target_role = 'all')
         AND nr.id IS NULL"
    );
    while ($row = mysqli_fetch_assoc($unread)) {
        mysqli_query($conn,
            "INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
             VALUES ('{$row['id']}', '$student_id', NOW())"
        );
    }
    header("Location: notifications.php");
    exit;
}

// ── Filter ───────────────────────────────────────────────
$filter      = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type']   ?? 'all';

$where_filter = "";
if ($filter === 'unread') {
    $where_filter .= " AND nr.id IS NULL";
} elseif ($filter === 'read') {
    $where_filter .= " AND nr.id IS NOT NULL";
}
if ($type_filter !== 'all' && in_array($type_filter, ['note','assignment','grade','announcement','general'])) {
    $where_filter .= " AND n.type = '" . mysqli_real_escape_string($conn, $type_filter) . "'";
}

// ── Pagination ───────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$total_result = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total
     FROM notifications n
     LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$student_id'
     INNER JOIN users u ON n.sent_by = u.id
     WHERE (n.target_role = 'student' OR n.target_role = 'all')
     $where_filter"
));
$total_pages = ceil($total_result['total'] / $per_page);

// ── Fetch notifications ──────────────────────────────────
$notifications = mysqli_query($conn,
    "SELECT n.*, u.name AS sender_name,
            nr.id AS is_read, nr.read_at
     FROM notifications n
     INNER JOIN users u ON n.sent_by = u.id
     LEFT JOIN notification_reads nr ON n.id = nr.notification_id
         AND nr.user_id = '$student_id'
     WHERE (n.target_role = 'student' OR n.target_role = 'all')
     $where_filter
     ORDER BY n.created_at DESC
     LIMIT $per_page OFFSET $offset"
);

// ── Counts for filters ───────────────────────────────────
$counts = [];
foreach (['all','unread','read'] as $f) {
    $w = $f === 'unread' ? ' AND nr.id IS NULL' : ($f === 'read' ? ' AND nr.id IS NOT NULL' : '');
    $counts[$f] = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as c FROM notifications n
         LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$student_id'
         WHERE (n.target_role = 'student' OR n.target_role = 'all') $w"
    ))['c'];
}

// ── Type counts ──────────────────────────────────────────
$type_counts = [];
foreach (['note','assignment','grade','announcement','general'] as $t) {
    $type_counts[$t] = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as c FROM notifications n
         LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$student_id'
         WHERE (n.target_role = 'student' OR n.target_role = 'all') AND n.type = '$t'"
    ))['c'];
}

// ── Sidebar counts (reuse from dashboard) ────────────────
$courses_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM course_enrollments
                          WHERE student_id = '$student_id' AND status = 'enrolled'")
)['total'];
$notes_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notes n
                          INNER JOIN course_enrollments ce ON n.course_id = ce.course_id
                          WHERE ce.student_id = '$student_id' AND ce.status = 'enrolled' AND n.status = 'active'")
)['total'];
$assignments_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM assignments a
                          INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
                          LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = '$student_id'
                          WHERE ce.student_id = '$student_id' AND ce.status = 'enrolled'
                          AND a.status = 'active' AND a.due_date >= NOW() AND s.id IS NULL")
)['total'];
$notifications_count = $counts['unread'];
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
            --accent:    #00d4ff;
            --green:     #6bcb77;
            --yellow:    #ffd93d;
            --red:       #ff6b6b;
            --purple:    #b48ffc;
            --sidebar-w: 260px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg-main);
            color: var(--text);
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar (identical to dashboard) ── */
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
        .sidebar-brand { padding: 24px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.2rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.75rem; }
        .sidebar-profile {
            padding: 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }
        .sidebar-profile img {
            width: 46px; height: 46px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--accent);
        }
        .sidebar-profile .name { font-weight: 600; font-size: 0.9rem; color: var(--text); }
        .sidebar-profile .role-badge {
            background: rgba(0,212,255,0.15); color: var(--accent);
            border: 1px solid rgba(0,212,255,0.3); border-radius: 20px;
            padding: 1px 10px; font-size: 0.7rem; font-weight: 600;
        }
        .sidebar-nav { flex: 1; padding: 15px 0; overflow-y: auto; }
        .nav-section {
            padding: 8px 20px 4px; font-size: 0.68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted);
        }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 20px; color: var(--muted);
            text-decoration: none; font-size: 0.9rem; font-weight: 500;
            border-left: 3px solid transparent; transition: all 0.2s;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active {
            color: var(--text); background: var(--bg-hover); border-left-color: var(--accent);
        }
        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1.05rem; width: 20px; }
        .nav-badge {
            margin-left: auto; background: var(--red); color: white;
            border-radius: 20px; padding: 1px 8px; font-size: 0.7rem; font-weight: 700;
        }
        .sidebar-footer { padding: 15px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a {
            display: flex; align-items: center; gap: 10px; color: var(--red);
            text-decoration: none; font-size: 0.875rem; font-weight: 500;
            padding: 8px 0; transition: opacity 0.2s;
        }
        .sidebar-footer a:hover { opacity: 0.75; }

        /* ── Main Content ── */
        .main-content { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

        /* ── Topbar ── */
        .topbar {
            background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border);
            padding: 14px 28px; display: flex; align-items: center;
            justify-content: space-between; position: sticky; top: 0; z-index: 50;
            backdrop-filter: blur(10px);
        }
        .topbar-left h6 { font-weight: 700; font-size: 1.05rem; color: var(--text); margin: 0; }
        .topbar-left p { color: var(--muted); font-size: 0.8rem; margin: 0; }
        .topbar-right { display: flex; align-items: center; gap: 15px; }
        .notif-btn {
            position: relative; background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px; color: var(--muted);
            cursor: pointer; transition: all 0.2s; text-decoration: none;
        }
        .notif-btn:hover { border-color: var(--accent); color: var(--accent); }
        .notif-dot {
            position: absolute; top: 5px; right: 7px; width: 8px; height: 8px;
            background: var(--red); border-radius: 50%; border: 2px solid var(--bg-main);
        }
        .hamburger {
            display: none; background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px; color: var(--text); cursor: pointer;
        }

        /* ── Page Body ── */
        .page-body { padding: 28px; flex: 1; }

        /* ── Page Header ── */
        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
        }
        .page-header-left h4 { font-weight: 800; font-size: 1.4rem; margin-bottom: 3px; }
        .page-header-left p { color: var(--muted); font-size: 0.82rem; margin: 0; }
        .btn-mark-all {
            background: rgba(0,212,255,0.12); color: var(--accent);
            border: 1px solid rgba(0,212,255,0.3); border-radius: 10px;
            padding: 8px 18px; font-size: 0.82rem; font-weight: 600;
            text-decoration: none; transition: all 0.2s; display: inline-flex;
            align-items: center; gap: 6px;
        }
        .btn-mark-all:hover { background: rgba(0,212,255,0.2); color: var(--accent); }

        /* ── Summary Stats ── */
        .notif-stats {
            display: flex; gap: 12px; margin-bottom: 22px; flex-wrap: wrap;
        }
        .notif-stat {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 12px; padding: 14px 20px;
            display: flex; align-items: center; gap: 12px;
            text-decoration: none; color: var(--text); transition: all 0.2s;
            flex: 1; min-width: 130px;
        }
        .notif-stat:hover, .notif-stat.active {
            background: var(--bg-hover); border-color: var(--accent); color: var(--text);
        }
        .notif-stat.active { border-color: var(--accent); }
        .notif-stat-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 1rem;
        }
        .notif-stat-icon.all    { background: rgba(255,255,255,0.08);   color: var(--text);   }
        .notif-stat-icon.unread { background: rgba(255,107,107,0.15);   color: var(--red);    }
        .notif-stat-icon.read   { background: rgba(107,203,119,0.15);   color: var(--green);  }
        .notif-stat-num { font-size: 1.3rem; font-weight: 800; line-height: 1; }
        .notif-stat-lbl { font-size: 0.73rem; color: var(--muted); }

        /* ── Filter Bar ── */
        .filter-bar {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 12px; padding: 14px 18px;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px; flex-wrap: wrap;
        }
        .filter-bar-label {
            color: var(--muted); font-size: 0.78rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.8px; white-space: nowrap;
        }
        .filter-chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-chip {
            padding: 5px 14px; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
            text-decoration: none; border: 1px solid var(--border);
            color: var(--muted); transition: all 0.2s; display: inline-flex;
            align-items: center; gap: 5px;
        }
        .filter-chip:hover { border-color: rgba(255,255,255,0.2); color: var(--text); }
        .filter-chip.active { background: rgba(0,212,255,0.15); border-color: rgba(0,212,255,0.4); color: var(--accent); }
        .filter-chip .chip-dot {
            width: 6px; height: 6px; border-radius: 50%;
        }
        .filter-chip.chip-note .chip-dot     { background: var(--red);    }
        .filter-chip.chip-assign .chip-dot   { background: var(--yellow); }
        .filter-chip.chip-grade .chip-dot    { background: var(--purple); }
        .filter-chip.chip-announce .chip-dot { background: var(--accent); }
        .filter-chip.chip-general .chip-dot  { background: var(--green);  }

        /* ── Notification List ── */
        .notif-list {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden;
        }
        .notif-list-header {
            padding: 16px 22px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .notif-list-header h6 {
            font-weight: 700; font-size: 0.92rem; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .notif-list-header span { color: var(--muted); font-size: 0.78rem; }

        /* ── Notification Item ── */
        .notif-item {
            display: flex; align-items: flex-start; gap: 16px;
            padding: 18px 22px; border-bottom: 1px solid var(--border);
            transition: background 0.2s; position: relative;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--bg-hover); }
        .notif-item.unread { border-left: 3px solid var(--accent); }
        .notif-item.unread .notif-item-inner { padding-left: 0; }

        .notif-avatar {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .notif-avatar.type-note        { background: rgba(255,107,107,0.15); color: var(--red);    }
        .notif-avatar.type-assignment  { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .notif-avatar.type-grade       { background: rgba(180,143,252,0.15); color: var(--purple); }
        .notif-avatar.type-announcement{ background: rgba(0,212,255,0.15);   color: var(--accent); }
        .notif-avatar.type-general     { background: rgba(107,203,119,0.15); color: var(--green);  }

        .notif-body { flex: 1; min-width: 0; }
        .notif-title-row {
            display: flex; align-items: center; gap: 8px;
            flex-wrap: wrap; margin-bottom: 4px;
        }
        .notif-title {
            font-weight: 700; font-size: 0.9rem; color: var(--text);
        }
        .notif-unread-dot {
            width: 8px; height: 8px; background: var(--accent);
            border-radius: 50%; flex-shrink: 0;
        }
        .notif-message {
            color: var(--muted); font-size: 0.82rem; line-height: 1.5;
            margin-bottom: 8px;
        }
        .notif-meta {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .notif-meta span { font-size: 0.73rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }
        .notif-type-badge {
            padding: 2px 10px; border-radius: 20px; font-size: 0.7rem;
            font-weight: 600; text-transform: capitalize;
        }
        .badge-note        { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3);  }
        .badge-assignment  { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);   }
        .badge-grade       { background: rgba(180,143,252,0.15); color: var(--purple); border: 1px solid rgba(180,143,252,0.3);  }
        .badge-announcement{ background: rgba(0,212,255,0.15);   color: var(--accent); border: 1px solid rgba(0,212,255,0.3);   }
        .badge-general     { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .badge-read-tag    { background: rgba(107,203,119,0.1);  color: var(--green);  border: 1px solid rgba(107,203,119,0.2); padding: 2px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }

        .notif-actions { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
        .notif-time { font-size: 0.72rem; color: var(--muted); white-space: nowrap; }
        .btn-read {
            background: rgba(0,212,255,0.1); border: 1px solid rgba(0,212,255,0.25);
            color: var(--accent); border-radius: 8px; padding: 4px 12px;
            font-size: 0.72rem; font-weight: 600; text-decoration: none;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px;
            white-space: nowrap;
        }
        .btn-read:hover { background: rgba(0,212,255,0.2); color: var(--accent); }

        /* ── Empty State ── */
        .empty-state {
            padding: 60px 20px; text-align: center; color: var(--muted);
        }
        .empty-state i { font-size: 2.8rem; opacity: 0.3; display: block; margin-bottom: 12px; }
        .empty-state h6 { font-weight: 700; color: var(--text); margin-bottom: 6px; }
        .empty-state p { font-size: 0.85rem; margin: 0; }

        /* ── Pagination ── */
        .pagination-bar {
            padding: 16px 22px; border-top: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
        }
        .pagination-bar span { color: var(--muted); font-size: 0.8rem; }
        .pagination-links { display: flex; gap: 6px; }
        .page-btn {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            background: var(--bg-card); border: 1px solid var(--border);
            color: var(--muted); text-decoration: none; font-size: 0.82rem;
            font-weight: 600; transition: all 0.2s;
        }
        .page-btn:hover { border-color: var(--accent); color: var(--accent); }
        .page-btn.active { background: rgba(0,212,255,0.15); border-color: var(--accent); color: var(--accent); }
        .page-btn.disabled { opacity: 0.3; pointer-events: none; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .notif-item { padding: 14px 16px; gap: 12px; }
            .notif-stats { gap: 8px; }
            .notif-stat { min-width: 100px; padding: 12px 14px; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Learning Management System</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture']) ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=46'"
             alt="Profile">
        <div>
            <div class="name"><?= htmlspecialchars($student_name) ?></div>
            <span class="role-badge">Student</span>
        </div>
    </div>

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
        <a href="notifications.php" class="active">
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
                <h6>Notifications</h6>
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

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h4><i class="bi bi-bell-fill me-2" style="color:var(--accent)"></i>Notifications</h4>
                <p>Stay up to date with announcements, notes, and assignment updates.</p>
            </div>
            <?php if ($counts['unread'] > 0): ?>
                <a href="notifications.php?mark_all_read=1" class="btn-mark-all"
                   onclick="return confirm('Mark all <?= $counts['unread'] ?> notification(s) as read?')">
                    <i class="bi bi-check2-all"></i> Mark All as Read
                </a>
            <?php endif; ?>
        </div>

        <!-- Summary Stats -->
        <div class="notif-stats">
            <a href="notifications.php?filter=all&type=<?= $type_filter ?>"
               class="notif-stat <?= $filter === 'all' ? 'active' : '' ?>">
                <div class="notif-stat-icon all"><i class="bi bi-bell"></i></div>
                <div>
                    <div class="notif-stat-num"><?= $counts['all'] ?></div>
                    <div class="notif-stat-lbl">All</div>
                </div>
            </a>
            <a href="notifications.php?filter=unread&type=<?= $type_filter ?>"
               class="notif-stat <?= $filter === 'unread' ? 'active' : '' ?>">
                <div class="notif-stat-icon unread"><i class="bi bi-bell-fill"></i></div>
                <div>
                    <div class="notif-stat-num"><?= $counts['unread'] ?></div>
                    <div class="notif-stat-lbl">Unread</div>
                </div>
            </a>
            <a href="notifications.php?filter=read&type=<?= $type_filter ?>"
               class="notif-stat <?= $filter === 'read' ? 'active' : '' ?>">
                <div class="notif-stat-icon read"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="notif-stat-num"><?= $counts['read'] ?></div>
                    <div class="notif-stat-lbl">Read</div>
                </div>
            </a>
        </div>

        <!-- Type Filter Bar -->
        <div class="filter-bar">
            <span class="filter-bar-label">Filter by type:</span>
            <div class="filter-chips">
                <a href="notifications.php?filter=<?= $filter ?>&type=all"
                   class="filter-chip <?= $type_filter === 'all' ? 'active' : '' ?>">
                    All Types
                </a>
                <?php
                    $type_defs = [
                        'note'         => ['label' => 'Notes',         'class' => 'chip-note',     'icon' => 'bi-file-earmark-text'],
                        'assignment'   => ['label' => 'Assignments',   'class' => 'chip-assign',   'icon' => 'bi-clipboard2'],
                        'grade'        => ['label' => 'Grades',        'class' => 'chip-grade',    'icon' => 'bi-star'],
                        'announcement' => ['label' => 'Announcements', 'class' => 'chip-announce', 'icon' => 'bi-megaphone'],
                        'general'      => ['label' => 'General',       'class' => 'chip-general',  'icon' => 'bi-bell'],
                    ];
                    foreach ($type_defs as $t => $td):
                        if ($type_counts[$t] < 1) continue;
                ?>
                <a href="notifications.php?filter=<?= $filter ?>&type=<?= $t ?>"
                   class="filter-chip <?= $td['class'] ?> <?= $type_filter === $t ? 'active' : '' ?>">
                    <span class="chip-dot"></span>
                    <?= $td['label'] ?>
                    <span style="opacity:.6">(<?= $type_counts[$t] ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="notif-list">
            <div class="notif-list-header">
                <h6>
                    <i class="bi bi-bell" style="color:var(--accent)"></i>
                    <?php
                        $label_map = ['all' => 'All Notifications', 'unread' => 'Unread', 'read' => 'Read'];
                        echo $label_map[$filter] ?? 'Notifications';
                        if ($type_filter !== 'all') echo ' &rsaquo; ' . ucfirst($type_filter);
                    ?>
                </h6>
                <span><?= $total_result['total'] ?> notification(s)</span>
            </div>

            <?php if (mysqli_num_rows($notifications) > 0): ?>
                <?php while ($notif = mysqli_fetch_assoc($notifications)):
                    $is_unread = !$notif['is_read'];
                    $type      = $notif['type'] ?? 'general';

                    $type_icons = [
                        'note'         => 'bi-file-earmark-text',
                        'assignment'   => 'bi-clipboard2',
                        'grade'        => 'bi-star-fill',
                        'announcement' => 'bi-megaphone-fill',
                        'general'      => 'bi-bell-fill',
                    ];
                    $icon = $type_icons[$type] ?? 'bi-bell-fill';

                    // Human-readable time
                    $created = strtotime($notif['created_at']);
                    $diff    = time() - $created;
                    if      ($diff < 60)        $time_ago = 'Just now';
                    elseif  ($diff < 3600)      $time_ago = floor($diff/60) . 'm ago';
                    elseif  ($diff < 86400)     $time_ago = floor($diff/3600) . 'h ago';
                    elseif  ($diff < 604800)    $time_ago = floor($diff/86400) . 'd ago';
                    else                        $time_ago = date('d M Y', $created);
                ?>
                <div class="notif-item <?= $is_unread ? 'unread' : '' ?>">
                    <div class="notif-avatar type-<?= htmlspecialchars($type) ?>">
                        <i class="bi <?= $icon ?>"></i>
                    </div>
                    <div class="notif-body">
                        <div class="notif-title-row">
                            <span class="notif-title"><?= htmlspecialchars($notif['title']) ?></span>
                            <?php if ($is_unread): ?>
                                <span class="notif-unread-dot" title="Unread"></span>
                            <?php endif; ?>
                        </div>
                        <div class="notif-message">
                            <?= nl2br(htmlspecialchars($notif['message'])) ?>
                        </div>
                        <div class="notif-meta">
                            <span class="notif-type-badge badge-<?= htmlspecialchars($type) ?>">
                                <?= ucfirst($type) ?>
                            </span>
                            <span><i class="bi bi-person"></i><?= htmlspecialchars($notif['sender_name']) ?></span>
                            <span><i class="bi bi-clock"></i><?= $time_ago ?></span>
                            <?php if (!$is_unread && $notif['read_at']): ?>
                                <span class="badge-read-tag">
                                    <i class="bi bi-check2"></i>
                                    Read <?= date('d M, H:i', strtotime($notif['read_at'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="notif-actions">
                        <span class="notif-time"><?= date('d M', $created) ?></span>
                        <?php if ($is_unread): ?>
                            <a href="notifications.php?mark_read=<?= $notif['id'] ?>&filter=<?= $filter ?>&type=<?= $type_filter ?>"
                               class="btn-read">
                                <i class="bi bi-check2"></i> Mark read
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <span>Page <?= $page ?> of <?= $total_pages ?></span>
                    <div class="pagination-links">
                        <a href="notifications.php?filter=<?= $filter ?>&type=<?= $type_filter ?>&page=<?= max(1,$page-1) ?>"
                           class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                            <a href="notifications.php?filter=<?= $filter ?>&type=<?= $type_filter ?>&page=<?= $p ?>"
                               class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <a href="notifications.php?filter=<?= $filter ?>&type=<?= $type_filter ?>&page=<?= min($total_pages,$page+1) ?>"
                           class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-bell-slash"></i>
                    <h6>No notifications here</h6>
                    <p>
                        <?php if ($filter === 'unread'): ?>
                            You're all caught up — no unread notifications.
                        <?php elseif ($filter === 'read'): ?>
                            You haven't read any notifications yet.
                        <?php else: ?>
                            No notifications have been sent to you yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99;"></div>

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