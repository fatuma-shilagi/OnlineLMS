<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'];

// ── CSRF token ───────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// ── Safe query helper ────────────────────────────────────
function safe_query_total($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        error_log('DB Error: ' . mysqli_error($conn) . ' | SQL: ' . $sql);
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return $row['total'] ?? 0;
}

// ── Fetch admin info ─────────────────────────────────────
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $admin_id);
mysqli_stmt_execute($stmt);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// ── Nav badges ───────────────────────────────────────────
$new_users_month = safe_query_total($conn,
    "SELECT COUNT(*) as total FROM users
     WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
);
$total_notifications = safe_query_total($conn,
    "SELECT COUNT(*) as total FROM notifications"
);

// ── Flash messages ───────────────────────────────────────
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Handle POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expired. Please try again.';
        header('Location: manage_notifications.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Send notification ──
    if ($action === 'send_notification') {
        $title       = trim($_POST['title']       ?? '');
        $message     = trim($_POST['message']     ?? '');
        $target_role = trim($_POST['target_role'] ?? '');

        if ($title === '' || $message === '' || $target_role === '') {
            $_SESSION['flash_error'] = 'Title, message, and target audience are required.';
            header('Location: manage_notifications.php');
            exit;
        }

        $allowed_roles = ['all', 'student', 'lecturer', 'admin'];
        if (!in_array($target_role, $allowed_roles)) {
            $_SESSION['flash_error'] = 'Invalid target audience selected.';
            header('Location: manage_notifications.php');
            exit;
        }

        $stmt = mysqli_prepare($conn,
            "INSERT INTO notifications (title, message, target_role, sent_by, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );

        if ($stmt === false) {
            $_SESSION['flash_error'] = 'Prepare failed: ' . mysqli_error($conn);
            header('Location: manage_notifications.php');
            exit;
        }

        mysqli_stmt_bind_param($stmt, "sssi",
            $title, $message, $target_role, $admin_id
        );

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash_success'] = 'Notification sent successfully.';
        } else {
            $_SESSION['flash_error'] = 'Could not send notification: ' . mysqli_stmt_error($stmt);
        }
        header('Location: manage_notifications.php');
        exit;
    }

    // ── Edit notification ──
    if ($action === 'edit_notification') {
        $notif_id    = (int)($_POST['notif_id']    ?? 0);
        $title       = trim($_POST['title']        ?? '');
        $message     = trim($_POST['message']      ?? '');
        $target_role = trim($_POST['target_role']  ?? '');

        if ($notif_id <= 0 || $title === '' || $message === '' || $target_role === '') {
            $_SESSION['flash_error'] = 'All fields are required.';
            header('Location: manage_notifications.php');
            exit;
        }

        $stmt = mysqli_prepare($conn,
            "UPDATE notifications SET title = ?, message = ?, target_role = ? WHERE id = ?"
        );

        if ($stmt === false) {
            $_SESSION['flash_error'] = 'Prepare failed: ' . mysqli_error($conn);
            header('Location: manage_notifications.php');
            exit;
        }

        mysqli_stmt_bind_param($stmt, "sssi", $title, $message, $target_role, $notif_id);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash_success'] = 'Notification updated successfully.';
        } else {
            $_SESSION['flash_error'] = 'Could not update notification: ' . mysqli_stmt_error($stmt);
        }
        header('Location: manage_notifications.php');
        exit;
    }

    // ── Delete notification ──
    if ($action === 'delete_notification') {
        $notif_id = (int)($_POST['notif_id'] ?? 0);

        $stmt = mysqli_prepare($conn, "DELETE FROM notifications WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $notif_id);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash_success'] = 'Notification deleted.';
        } else {
            $_SESSION['flash_error'] = 'Could not delete notification.';
        }
        $qs = isset($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : '';
        header('Location: manage_notifications.php' . $qs);
        exit;
    }

    // ── Delete all ──
    if ($action === 'delete_all') {
        if (mysqli_query($conn, "DELETE FROM notifications")) {
            $_SESSION['flash_success'] = 'All notifications deleted.';
        } else {
            $_SESSION['flash_error'] = 'Could not delete notifications.';
        }
        header('Location: manage_notifications.php');
        exit;
    }
}

// ── Filters ──────────────────────────────────────────────
$search      = trim($_GET['search']      ?? '');
$filter_role = trim($_GET['target_role'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 10;
$offset      = ($page - 1) * $per_page;

$where  = ["1=1"];
$types  = "";
$params = [];

if ($search !== '') {
    $where[] = "(n.title LIKE ? OR n.message LIKE ?)";
    $like = "%$search%";
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
}
if (in_array($filter_role, ['all', 'student', 'lecturer', 'admin'])) {
    $where[] = "n.target_role = ?";
    $types .= "s";
    $params[] = $filter_role;
}
$where_sql = implode(' AND ', $where);

// ── Count ────────────────────────────────────────────────
$count_sql = "SELECT COUNT(*) as total FROM notifications n WHERE $where_sql";
$stmt = mysqli_prepare($conn, $count_sql);
if ($stmt === false) die('Query error: ' . mysqli_error($conn));
if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$total_rows  = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));

// ── Main list ────────────────────────────────────────────
$list_sql = "SELECT n.*, u.name AS sender_name
             FROM notifications n
             INNER JOIN users u ON n.sent_by = u.id
             WHERE $where_sql
             ORDER BY n.created_at DESC
             LIMIT ?, ?";
$stmt = mysqli_prepare($conn, $list_sql);
if ($stmt === false) die('Query error: ' . mysqli_error($conn));
$all_types  = $types . "ii";
$all_params = array_merge($params, [$offset, $per_page]);
mysqli_stmt_bind_param($stmt, $all_types, ...$all_params);
mysqli_stmt_execute($stmt);
$notif_list = [];
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $notif_list[] = $row;
}

// ── Stat cards ────────────────────────────────────────────
$notif_all      = safe_query_total($conn, "SELECT COUNT(*) as total FROM notifications");
$notif_students = safe_query_total($conn, "SELECT COUNT(*) as total FROM notifications WHERE target_role = 'student'");
$notif_lecturers= safe_query_total($conn, "SELECT COUNT(*) as total FROM notifications WHERE target_role = 'lecturer'");
$notif_everyone = safe_query_total($conn, "SELECT COUNT(*) as total FROM notifications WHERE target_role = 'all'");

// ── QS builder ───────────────────────────────────────────
function buildQS($overrides = []) {
    $base = [
        'search'      => $_GET['search']      ?? '',
        'target_role' => $_GET['target_role'] ?? '',
        'page'        => $_GET['page']        ?? 1,
    ];
    $merged = array_merge($base, $overrides);
    return http_build_query(array_filter($merged, fn($v) => $v !== '' && $v !== null));
}
$current_qs = buildQS();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Notifications - OnlineLMS</title>
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
            --accent:    #ff6b6b;
            --blue:      #00d4ff;
            --green:     #6bcb77;
            --yellow:    #ffd93d;
            --purple:    #b48ffc;
            --orange:    #ffa500;
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
            overflow-y: auto;
        }

        .sidebar-brand { padding: 22px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.15rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.72rem; }

        .sidebar-profile {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }

        .sidebar-profile img {
            width: 46px; height: 46px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid var(--accent);
        }

        .sidebar-profile .name { font-weight: 600; font-size: 0.88rem; color: var(--text); }

        .role-badge {
            background: rgba(255,107,107,0.15);
            color: var(--accent);
            border: 1px solid rgba(255,107,107,0.3);
            border-radius: 20px;
            padding: 1px 10px;
            font-size: 0.68rem; font-weight: 700;
        }

        .sidebar-nav { flex: 1; padding: 12px 0; }

        .nav-section {
            padding: 8px 20px 4px;
            font-size: 0.67rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px;
            color: var(--muted);
        }

        .sidebar-nav a {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 20px;
            color: var(--muted); text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }

        .sidebar-nav a:hover, .sidebar-nav a.active {
            color: var(--text); background: var(--bg-hover);
            border-left-color: var(--accent);
        }

        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1rem; width: 20px; }

        .nav-badge {
            margin-left: auto;
            background: var(--accent); color: white;
            border-radius: 20px; padding: 1px 8px;
            font-size: 0.68rem; font-weight: 700;
        }

        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }

        .sidebar-footer a {
            display: flex; align-items: center; gap: 10px;
            color: var(--accent); text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            padding: 8px 0; transition: opacity 0.2s;
        }

        .sidebar-footer a:hover { opacity: 0.75; }

        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1; display: flex; flex-direction: column;
            min-height: 100vh;
        }

        .topbar {
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid var(--border);
            padding: 13px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 50;
            backdrop-filter: blur(10px);
        }

        .topbar-left h6 { font-weight: 700; font-size: 1rem; margin: 0; }
        .topbar-left p  { color: var(--muted); font-size: 0.78rem; margin: 0; }
        .topbar-right   { display: flex; align-items: center; gap: 12px; }

        .notif-btn {
            position: relative;
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px;
            color: var(--muted); text-decoration: none; transition: all 0.2s;
        }

        .notif-btn:hover { border-color: var(--accent); color: var(--accent); }

        .notif-dot {
            position: absolute; top: 5px; right: 7px;
            width: 8px; height: 8px;
            background: var(--accent); border-radius: 50%;
            border: 2px solid var(--bg-main);
        }

        .hamburger {
            display: none;
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px;
            color: var(--text); cursor: pointer;
        }

        .page-body { padding: 26px; flex: 1; }

        .page-header {
            display: flex; align-items: center;
            justify-content: space-between;
            margin-bottom: 22px; flex-wrap: wrap; gap: 12px;
        }

        .page-header h4 { font-weight: 800; font-size: 1.25rem; margin-bottom: 3px; }
        .page-header p  { color: var(--muted); font-size: 0.85rem; margin: 0; }

        .page-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn-action {
            padding: 9px 18px; border-radius: 10px;
            font-size: 0.82rem; font-weight: 600;
            text-decoration: none;
            display: flex; align-items: center; gap: 6px;
            transition: all 0.2s; border: none; cursor: pointer;
        }

        .btn-red { background: var(--accent); color: white; }
        .btn-red:hover { background: #ff4444; color: white; transform: translateY(-1px); }

        .btn-ghost {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            color: var(--muted);
        }

        .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }

        .alert-glass {
            border-radius: 12px; padding: 12px 18px;
            font-size: 0.85rem; margin-bottom: 18px;
            display: flex; align-items: center; gap: 10px;
            border: 1px solid;
        }

        .alert-success-glass { background: rgba(107,203,119,0.12); border-color: rgba(107,203,119,0.3); color: var(--green); }
        .alert-error-glass   { background: rgba(255,107,107,0.12); border-color: rgba(255,107,107,0.3); color: var(--accent); }

        /* ── Stat Cards ── */
        .stat-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; padding: 20px 18px;
            display: flex; align-items: center; gap: 14px; height: 100%;
        }

        .stat-icon {
            width: 50px; height: 50px; border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }

        .si-red    { background: rgba(255,107,107,0.15); color: var(--accent); }
        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .si-purple { background: rgba(180,143,252,0.15); color: var(--purple); }

        .stat-info h3 { font-size: 1.65rem; font-weight: 800; line-height: 1; margin-bottom: 3px; }
        .stat-info p  { color: var(--muted); font-size: 0.78rem; margin: 0; }

        /* ── Filter Bar ── */
        .filter-bar {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; padding: 16px 20px; margin-bottom: 22px;
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }

        .form-control-dark, .form-select-dark {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            color: var(--text); border-radius: 10px;
            font-size: 0.85rem; padding: 9px 14px;
        }

        .form-control-dark::placeholder { color: var(--muted); }

        .form-control-dark:focus, .form-select-dark:focus {
            background: rgba(255,255,255,0.07);
            border-color: var(--accent); color: var(--text); box-shadow: none;
        }

        .form-select-dark option { background: #1a1a2e; color: var(--text); }

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden;
        }

        .section-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }

        .section-header h6 {
            font-weight: 700; font-size: 0.9rem; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }

        /* ── Notification Row ── */
        .notif-row {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: flex-start; gap: 14px;
            transition: background 0.2s; flex-wrap: wrap;
        }

        .notif-row:last-child { border-bottom: none; }
        .notif-row:hover { background: var(--bg-hover); }

        .notif-icon {
            width: 42px; height: 42px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0; margin-top: 2px;
        }

        .ni-all      { background: rgba(0,212,255,0.12);   color: var(--blue);   }
        .ni-student  { background: rgba(107,203,119,0.12); color: var(--green);  }
        .ni-lecturer { background: rgba(255,217,61,0.12);  color: var(--yellow); }
        .ni-admin    { background: rgba(255,107,107,0.12); color: var(--accent); }

        .notif-body { flex: 1; min-width: 220px; }

        .notif-title {
            font-size: 0.875rem; font-weight: 700;
            color: var(--text); margin-bottom: 4px;
        }

        .notif-message {
            font-size: 0.78rem; color: var(--muted);
            line-height: 1.5; margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-meta {
            display: flex; align-items: center; gap: 12px;
            font-size: 0.72rem; color: var(--muted); flex-wrap: wrap;
        }

        .notif-meta i { margin-right: 3px; }

        .badge-glass {
            padding: 3px 10px; border-radius: 20px;
            font-size: 0.7rem; font-weight: 600; display: inline-block;
        }

        .bg-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   border: 1px solid rgba(0,212,255,0.3);   }
        .bg-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);  }
        .bg-red    { background: rgba(255,107,107,0.15); color: var(--accent); border: 1px solid rgba(255,107,107,0.3); }
        .bg-purple { background: rgba(180,143,252,0.15); color: var(--purple); border: 1px solid rgba(180,143,252,0.3); }

        .notif-actions { display: flex; gap: 6px; flex-shrink: 0; align-self: center; }

        .icon-btn {
            width: 34px; height: 34px; border-radius: 9px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03); color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            text-decoration: none; font-size: 0.85rem;
            cursor: pointer; transition: all 0.2s;
        }

        .icon-btn:hover { color: var(--text); border-color: var(--accent); }
        .icon-btn.danger:hover { color: var(--accent); border-color: var(--accent); background: rgba(255,107,107,0.1); }

        /* ── Empty State ── */
        .empty-state { padding: 40px 20px; text-align: center; color: var(--muted); }
        .empty-state i { font-size: 1.8rem; margin-bottom: 8px; opacity: 0.35; display: block; }
        .empty-state p { font-size: 0.82rem; margin: 0; }

        /* ── Pagination ── */
        .pagination-wrap {
            display: flex; align-items: center;
            justify-content: space-between;
            padding: 16px 20px; flex-wrap: wrap; gap: 10px;
        }

        .pagination-wrap .info { font-size: 0.78rem; color: var(--muted); }

        .page-btn {
            width: 34px; height: 34px; border-radius: 9px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03); color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            text-decoration: none; font-size: 0.8rem; font-weight: 600;
        }

        .page-btn:hover { color: var(--text); border-color: var(--accent); }
        .page-btn.active { background: var(--accent); color: white; border-color: var(--accent); }
        .page-btn.disabled { opacity: 0.35; pointer-events: none; }

        /* ── Modal ── */
        .modal-content {
            background: #15151f; border: 1px solid var(--border);
            border-radius: 16px; color: var(--text);
        }

        .modal-header, .modal-footer { border-color: var(--border); }
        .modal-title { font-weight: 700; font-size: 1rem; }
        .btn-close { filter: invert(1); }
        .form-label { font-size: 0.82rem; font-weight: 600; color: var(--text); margin-bottom: 6px; }

        /* ── Audience Pills ── */
        .audience-pills { display: flex; gap: 8px; flex-wrap: wrap; }

        .audience-pill {
            padding: 7px 16px; border-radius: 20px;
            font-size: 0.8rem; font-weight: 600;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.04);
            color: var(--muted); cursor: pointer;
            transition: all 0.2s;
        }

        .audience-pill:hover { border-color: var(--accent); color: var(--accent); }

        .audience-pill.selected-all      { background: rgba(0,212,255,0.15);   color: var(--blue);   border-color: rgba(0,212,255,0.4);   }
        .audience-pill.selected-student  { background: rgba(107,203,119,0.15); color: var(--green);  border-color: rgba(107,203,119,0.4); }
        .audience-pill.selected-lecturer { background: rgba(255,217,61,0.15);  color: var(--yellow); border-color: rgba(255,217,61,0.4);  }
        .audience-pill.selected-admin    { background: rgba(255,107,107,0.15); color: var(--accent); border-color: rgba(255,107,107,0.4); }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .notif-actions { width: 100%; justify-content: flex-end; }
        }
    </style>
</head>
<body>

<!-- ════════════ SIDEBAR ════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Admin Control Panel</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture'] ?? '') ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=46'"
             alt="Admin">
        <div>
            <div class="name"><?= htmlspecialchars($admin_name) ?></div>
            <span class="role-badge">Administrator</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
        <a href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>

        <div class="nav-section">Management</div>
        <a href="manage_users.php">
            <i class="bi bi-people"></i> Manage Users
            <?php if ($new_users_month > 0): ?>
                <span class="nav-badge"><?= $new_users_month ?> new</span>
            <?php endif; ?>
        </a>
        <a href="manage_courses.php"><i class="bi bi-book"></i> Manage Courses</a>
        <a href="manage_notes.php"><i class="bi bi-file-earmark-text"></i> Manage Notes</a>
        <a href="manage_assignments.php"><i class="bi bi-clipboard2-check"></i> Manage Assignments</a>
        <a href="manage_notifications.php" class="active">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($total_notifications > 0): ?>
                <span class="nav-badge"><?= $total_notifications ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">System</div>
        <a href="settings.php"><i class="bi bi-gear"></i> Settings</a>

        <div class="nav-section">Account</div>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?')">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>

<!-- ════════════ MAIN ════════════ -->
<div class="main-content">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
            <div class="topbar-left">
                <h6>Manage Notifications</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="manage_notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($total_notifications > 0): ?><span class="notif-dot"></span><?php endif; ?>
            </a>
            <a href="profile.php" style="text-decoration:none;">
                <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture'] ?? '') ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=36'"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
            </a>
        </div>
    </div>

    <div class="page-body">

        <div class="page-header">
            <div>
                <h4>Notifications</h4>
                <p>Broadcast announcements to students, lecturers, or all users.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($notif_all > 0): ?>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete ALL notifications permanently? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action"     value="delete_all">
                        <button type="submit" class="btn-action btn-ghost">
                            <i class="bi bi-trash"></i> Clear All
                        </button>
                    </form>
                <?php endif; ?>
                <button class="btn-action btn-red" data-bs-toggle="modal" data-bs-target="#sendNotifModal">
                    <i class="bi bi-megaphone"></i> Send Notification
                </button>
            </div>
        </div>

        <?php if ($flash_success): ?>
            <div class="alert-glass alert-success-glass">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert-glass alert-error-glass">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-bell"></i></div>
                    <div class="stat-info">
                        <h3><?= $notif_all ?></h3>
                        <p>Total Sent</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-person-check"></i></div>
                    <div class="stat-info">
                        <h3><?= $notif_students ?></h3>
                        <p>To Students</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-person-video3"></i></div>
                    <div class="stat-info">
                        <h3><?= $notif_lecturers ?></h3>
                        <p>To Lecturers</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-purple"><i class="bi bi-people"></i></div>
                    <div class="stat-info">
                        <h3><?= $notif_everyone ?></h3>
                        <p>To Everyone</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <input type="text" name="search" class="form-control-dark" style="flex:1; min-width:200px;"
                   placeholder="Search by title or message..."
                   value="<?= htmlspecialchars($search) ?>">

            <select name="target_role" class="form-select-dark">
                <option value="">All Audiences</option>
                <option value="all"      <?= $filter_role === 'all'      ? 'selected' : '' ?>>Everyone</option>
                <option value="student"  <?= $filter_role === 'student'  ? 'selected' : '' ?>>Students</option>
                <option value="lecturer" <?= $filter_role === 'lecturer' ? 'selected' : '' ?>>Lecturers</option>
                <option value="admin"    <?= $filter_role === 'admin'    ? 'selected' : '' ?>>Admins</option>
            </select>

            <button type="submit" class="btn-action btn-red"><i class="bi bi-search"></i> Filter</button>
            <?php if ($search !== '' || $filter_role !== ''): ?>
                <a href="manage_notifications.php" class="btn-action btn-ghost">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Notifications List -->
        <div class="section-card">
            <div class="section-header">
                <h6><i class="bi bi-bell" style="color:var(--blue)"></i> All Notifications</h6>
                <span style="color:var(--muted); font-size:0.78rem;">
                    <?= $total_rows ?> result<?= $total_rows == 1 ? '' : 's' ?>
                </span>
            </div>

            <?php if (count($notif_list) > 0): ?>
                <?php
                $role_icon_map = [
                    'all'      => ['ni-all',      'bi-people',        'bg-blue',   'Everyone'],
                    'student'  => ['ni-student',  'bi-person-check',  'bg-green',  'Students'],
                    'lecturer' => ['ni-lecturer', 'bi-person-video3', 'bg-yellow', 'Lecturers'],
                    'admin'    => ['ni-admin',    'bi-person-gear',   'bg-red',    'Admins'],
                ];
                ?>
                <?php foreach ($notif_list as $n): ?>
                    <?php
                        $ri = $role_icon_map[$n['target_role']] ?? ['ni-all', 'bi-megaphone', 'bg-blue', ucfirst($n['target_role'])];
                    ?>
                    <div class="notif-row">
                        <div class="notif-icon <?= $ri[0] ?>">
                            <i class="bi <?= $ri[1] ?>"></i>
                        </div>

                        <div class="notif-body">
                            <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                            <div class="notif-message"><?= htmlspecialchars($n['message']) ?></div>
                            <div class="notif-meta">
                                <span class="badge-glass <?= $ri[2] ?>"><?= $ri[3] ?></span>
                                <span><i class="bi bi-person"></i><?= htmlspecialchars($n['sender_name']) ?></span>
                                <span><i class="bi bi-clock"></i><?= date('d M Y, H:i', strtotime($n['created_at'])) ?></span>
                            </div>
                        </div>

                        <div class="notif-actions">
                            <button type="button" class="icon-btn" title="Edit"
                                    onclick='openEditModal(<?= json_encode([
                                        "id"          => $n['id'],
                                        "title"       => $n['title'],
                                        "message"     => $n['message'],
                                        "target_role" => $n['target_role'],
                                    ]) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Delete this notification?');">
                                <input type="hidden" name="csrf_token"  value="<?= $csrf_token ?>">
                                <input type="hidden" name="action"      value="delete_notification">
                                <input type="hidden" name="notif_id"    value="<?= $n['id'] ?>">
                                <input type="hidden" name="redirect_qs" value="<?= htmlspecialchars($current_qs) ?>">
                                <button type="submit" class="icon-btn danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-bell-slash"></i>
                    <p>No notifications found. Send your first one!</p>
                </div>
            <?php endif; ?>

            <?php if ($total_pages > 1): ?>
                <div class="pagination-wrap">
                    <div class="info">
                        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                           href="?<?= buildQS(['page' => max(1, $page - 1)]) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a class="page-btn <?= $p == $page ? 'active' : '' ?>"
                               href="?<?= buildQS(['page' => $p]) ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <a class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"
                           href="?<?= buildQS(['page' => min($total_pages, $page + 1)]) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99;"></div>

<!-- ════════════ SEND NOTIFICATION MODAL ════════════ -->
<div class="modal fade" id="sendNotifModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="sendNotifForm">
                <input type="hidden" name="csrf_token"  value="<?= $csrf_token ?>">
                <input type="hidden" name="action"      value="send_notification">
                <input type="hidden" name="target_role" id="send_target_role" value="">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-megaphone me-2"></i>Send Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Target Audience</label>
                        <div class="audience-pills">
                            <span class="audience-pill" data-role="all"      onclick="selectAudience(this)">
                                <i class="bi bi-people me-1"></i>Everyone
                            </span>
                            <span class="audience-pill" data-role="student"  onclick="selectAudience(this)">
                                <i class="bi bi-person-check me-1"></i>Students
                            </span>
                            <span class="audience-pill" data-role="lecturer" onclick="selectAudience(this)">
                                <i class="bi bi-person-video3 me-1"></i>Lecturers
                            </span>
                            <span class="audience-pill" data-role="admin"    onclick="selectAudience(this)">
                                <i class="bi bi-person-gear me-1"></i>Admins
                            </span>
                        </div>
                        <div id="audience_error"
                             style="color:var(--accent); font-size:0.75rem; margin-top:5px; display:none;">
                            Please select a target audience.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control form-control-dark"
                               placeholder="e.g. Exam Schedule Update" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control form-control-dark" rows="4"
                                  placeholder="Write your announcement here..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-action btn-red" onclick="return validateAudience()">
                        <i class="bi bi-send"></i> Send
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════════ EDIT NOTIFICATION MODAL ════════════ -->
<div class="modal fade" id="editNotifModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editNotifForm">
                <input type="hidden" name="csrf_token"  value="<?= $csrf_token ?>">
                <input type="hidden" name="action"      value="edit_notification">
                <input type="hidden" name="notif_id"    id="edit_notif_id">
                <input type="hidden" name="target_role" id="edit_target_role" value="">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Target Audience</label>
                        <div class="audience-pills" id="edit_audience_pills">
                            <span class="audience-pill" data-role="all"      onclick="selectEditAudience(this)">
                                <i class="bi bi-people me-1"></i>Everyone
                            </span>
                            <span class="audience-pill" data-role="student"  onclick="selectEditAudience(this)">
                                <i class="bi bi-person-check me-1"></i>Students
                            </span>
                            <span class="audience-pill" data-role="lecturer" onclick="selectEditAudience(this)">
                                <i class="bi bi-person-video3 me-1"></i>Lecturers
                            </span>
                            <span class="audience-pill" data-role="admin"    onclick="selectEditAudience(this)">
                                <i class="bi bi-person-gear me-1"></i>Admins
                            </span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" id="edit_notif_title"
                               class="form-control form-control-dark" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Message</label>
                        <textarea name="message" id="edit_notif_message"
                                  class="form-control form-control-dark" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-action btn-red">
                        <i class="bi bi-check-lg"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── Sidebar ──
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

    // ── Audience pill selection (Send modal) ──
    function selectAudience(el) {
        document.querySelectorAll('#sendNotifModal .audience-pill').forEach(p => {
            p.className = 'audience-pill';
        });
        const role = el.dataset.role;
        el.classList.add('selected-' + role);
        document.getElementById('send_target_role').value = role;
        document.getElementById('audience_error').style.display = 'none';
    }

    function validateAudience() {
        const val = document.getElementById('send_target_role').value;
        if (!val) {
            document.getElementById('audience_error').style.display = 'block';
            return false;
        }
        return true;
    }

    // Reset send modal on close
    document.getElementById('sendNotifModal').addEventListener('hidden.bs.modal', () => {
        document.querySelectorAll('#sendNotifModal .audience-pill').forEach(p => {
            p.className = 'audience-pill';
        });
        document.getElementById('send_target_role').value = '';
        document.getElementById('audience_error').style.display = 'none';
    });

    // ── Audience pill selection (Edit modal) ──
    function selectEditAudience(el) {
        document.querySelectorAll('#edit_audience_pills .audience-pill').forEach(p => {
            p.className = 'audience-pill';
        });
        const role = el.dataset.role;
        el.classList.add('selected-' + role);
        document.getElementById('edit_target_role').value = role;
    }

    function openEditModal(n) {
        document.getElementById('edit_notif_id').value      = n.id;
        document.getElementById('edit_notif_title').value   = n.title;
        document.getElementById('edit_notif_message').value = n.message;

        // Pre-select the correct audience pill
        document.querySelectorAll('#edit_audience_pills .audience-pill').forEach(p => {
            p.className = 'audience-pill';
            if (p.dataset.role === n.target_role) {
                p.classList.add('selected-' + n.target_role);
            }
        });
        document.getElementById('edit_target_role').value = n.target_role;

        new bootstrap.Modal(document.getElementById('editNotifModal')).show();
    }
</script>
</body>
</html>