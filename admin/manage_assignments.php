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

// ── Courses dropdown ─────────────────────────────────────
$courses_result = mysqli_query($conn,
    "SELECT id, course_code, course_name FROM courses WHERE status = 'active' ORDER BY course_name ASC"
);
$courses_list = [];
if ($courses_result) {
    while ($c = mysqli_fetch_assoc($courses_result)) {
        $courses_list[] = $c;
    }
}

// ── Handle POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expired. Please try again.';
        header('Location: manage_assignments.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Add assignment ──
    if ($action === 'add_assignment') {
        $course_id   = (int)($_POST['course_id']   ?? 0);
        $title       = trim($_POST['title']        ?? '');
        $description = trim($_POST['description']  ?? '');
        $due_date    = trim($_POST['due_date']      ?? '');
        $total_marks = (int)($_POST['total_marks'] ?? 100);

        if ($course_id <= 0 || $title === '') {
            $_SESSION['flash_error'] = 'Course and title are required.';
            header('Location: manage_assignments.php');
            exit;
        }

        $due_date_val = $due_date !== '' ? $due_date : null;

        $stmt = mysqli_prepare($conn,
            "INSERT INTO assignments (course_id, title, description, due_date, total_marks, created_by, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())"
        );

        if ($stmt === false) {
            $_SESSION['flash_error'] = 'Prepare failed: ' . mysqli_error($conn);
            header('Location: manage_assignments.php');
            exit;
        }

        mysqli_stmt_bind_param($stmt, "isssii",
            $course_id, $title, $description, $due_date_val, $total_marks, $admin_id
        );

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash_success'] = 'Assignment created successfully.';
        } else {
            $_SESSION['flash_error'] = 'Could not create assignment: ' . mysqli_stmt_error($stmt);
        }
        header('Location: manage_assignments.php');
        exit;
    }

    // ── Edit assignment ──
    if ($action === 'edit_assignment') {
        $assign_id   = (int)($_POST['assignment_id'] ?? 0);
        $course_id   = (int)($_POST['course_id']     ?? 0);
        $title       = trim($_POST['title']          ?? '');
        $description = trim($_POST['description']    ?? '');
        $due_date    = trim($_POST['due_date']        ?? '');
        $total_marks = (int)($_POST['total_marks']   ?? 100);

        if ($assign_id <= 0 || $course_id <= 0 || $title === '') {
            $_SESSION['flash_error'] = 'Course and title are required.';
            header('Location: manage_assignments.php');
            exit;
        }

        $due_date_val = $due_date !== '' ? $due_date : null;

        $stmt = mysqli_prepare($conn,
            "UPDATE assignments SET course_id=?, title=?, description=?, due_date=?, total_marks=?, updated_at=NOW()
             WHERE id=?"
        );

        if ($stmt === false) {
            $_SESSION['flash_error'] = 'Prepare failed: ' . mysqli_error($conn);
            header('Location: manage_assignments.php');
            exit;
        }

        mysqli_stmt_bind_param($stmt, "isssii",
            $course_id, $title, $description, $due_date_val, $total_marks, $assign_id
        );

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash_success'] = 'Assignment updated successfully.';
        } else {
            $_SESSION['flash_error'] = 'Could not update assignment: ' . mysqli_stmt_error($stmt);
        }
        header('Location: manage_assignments.php');
        exit;
    }

    // ── Toggle status ──
    if ($action === 'toggle_status') {
        $assign_id = (int)($_POST['assignment_id'] ?? 0);

        $stmt = mysqli_prepare($conn, "SELECT status FROM assignments WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $assign_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($row) {
            $new_status = $row['status'] === 'active' ? 'inactive' : 'active';
            $stmt2 = mysqli_prepare($conn, "UPDATE assignments SET status = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt2, "si", $new_status, $assign_id);
            mysqli_stmt_execute($stmt2);
            $_SESSION['flash_success'] = 'Assignment status updated.';
        }
        $qs = isset($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : '';
        header('Location: manage_assignments.php' . $qs);
        exit;
    }

    // ── Delete assignment ──
    if ($action === 'delete_assignment') {
        $assign_id = (int)($_POST['assignment_id'] ?? 0);

        $stmt = mysqli_prepare($conn, "DELETE FROM assignments WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $assign_id);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash_success'] = 'Assignment deleted successfully.';
        } else {
            $_SESSION['flash_error'] = 'Could not delete assignment.';
        }
        $qs = isset($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : '';
        header('Location: manage_assignments.php' . $qs);
        exit;
    }
}

// ── Filters ──────────────────────────────────────────────
$search        = trim($_GET['search']    ?? '');
$filter_course = (int)($_GET['course_id'] ?? 0);
$filter_status = $_GET['status']          ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

$where  = ["1=1"];
$types  = "";
$params = [];

if ($search !== '') {
    $where[] = "(a.title LIKE ? OR c.course_code LIKE ? OR c.course_name LIKE ?)";
    $like = "%$search%";
    $types .= "sss";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter_course > 0) {
    $where[] = "a.course_id = ?";
    $types .= "i";
    $params[] = $filter_course;
}
if ($filter_status === 'active' || $filter_status === 'inactive') {
    $where[] = "a.status = ?";
    $types .= "s";
    $params[] = $filter_status;
}
$where_sql = implode(' AND ', $where);

// ── Count ────────────────────────────────────────────────
$count_sql = "SELECT COUNT(*) as total FROM assignments a
              INNER JOIN courses c ON a.course_id = c.id
              WHERE $where_sql";
$stmt = mysqli_prepare($conn, $count_sql);
if ($stmt === false) die('Query error: ' . mysqli_error($conn));
if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$total_rows  = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));

// ── Main list ────────────────────────────────────────────
$list_sql = "SELECT a.*, c.course_code, c.course_name, u.name AS lecturer_name,
                    (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) AS submission_count
             FROM assignments a
             INNER JOIN courses c ON a.course_id = c.id
             INNER JOIN users u ON c.lecturer_id = u.id
             WHERE $where_sql
             ORDER BY a.created_at DESC
             LIMIT ?, ?";
$stmt = mysqli_prepare($conn, $list_sql);
if ($stmt === false) die('Query error: ' . mysqli_error($conn));
$all_types  = $types . "ii";
$all_params = array_merge($params, [$offset, $per_page]);
mysqli_stmt_bind_param($stmt, $all_types, ...$all_params);
mysqli_stmt_execute($stmt);
$assignments_list = [];
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $assignments_list[] = $row;
}

// ── Stat cards ────────────────────────────────────────────
$total_active      = safe_query_total($conn, "SELECT COUNT(*) as total FROM assignments WHERE status = 'active'");
$total_inactive    = safe_query_total($conn, "SELECT COUNT(*) as total FROM assignments WHERE status = 'inactive'");
$total_submissions = safe_query_total($conn, "SELECT COUNT(*) as total FROM submissions");
$pending_grading   = safe_query_total($conn,
    "SELECT COUNT(*) as total FROM submissions s
     LEFT JOIN grades g ON s.id = g.submission_id WHERE g.id IS NULL"
);

// ── QS builder ───────────────────────────────────────────
function buildQS($overrides = []) {
    $base = [
        'search'    => $_GET['search']    ?? '',
        'course_id' => $_GET['course_id'] ?? '',
        'status'    => $_GET['status']    ?? '',
        'page'      => $_GET['page']      ?? 1,
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
    <title>Manage Assignments - OnlineLMS</title>
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
            overflow-y: auto;
        }

        .sidebar-brand { padding: 22px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.15rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.72rem; }

        .sidebar-profile {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-profile img {
            width: 46px; height: 46px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent);
        }

        .sidebar-profile .name { font-weight: 600; font-size: 0.88rem; color: var(--text); }

        .role-badge {
            background: rgba(255,107,107,0.15);
            color: var(--accent);
            border: 1px solid rgba(255,107,107,0.3);
            border-radius: 20px;
            padding: 1px 10px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .sidebar-nav { flex: 1; padding: 12px 0; }

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
            background: var(--accent);
            color: white;
            border-radius: 20px;
            padding: 1px 8px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent);
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

        .topbar-left h6 { font-weight: 700; font-size: 1rem; margin: 0; }
        .topbar-left p  { color: var(--muted); font-size: 0.78rem; margin: 0; }
        .topbar-right   { display: flex; align-items: center; gap: 12px; }

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
            background: var(--accent);
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

        .page-body { padding: 26px; flex: 1; }

        /* ── Page header ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-header h4 { font-weight: 800; font-size: 1.25rem; margin-bottom: 3px; }
        .page-header p  { color: var(--muted); font-size: 0.85rem; margin: 0; }

        .btn-action {
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-red { background: var(--accent); color: white; }
        .btn-red:hover { background: #ff4444; color: white; transform: translateY(-1px); }

        /* ── Alerts ── */
        .alert-glass {
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 0.85rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid;
        }

        .alert-success-glass { background: rgba(107,203,119,0.12); border-color: rgba(107,203,119,0.3); color: var(--green); }
        .alert-error-glass   { background: rgba(255,107,107,0.12); border-color: rgba(255,107,107,0.3); color: var(--accent); }

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
            width: 50px; height: 50px;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .si-red    { background: rgba(255,107,107,0.15); color: var(--accent); }
        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .si-orange { background: rgba(255,165,0,0.15);   color: var(--orange); }

        .stat-info h3 { font-size: 1.65rem; font-weight: 800; line-height: 1; margin-bottom: 3px; }
        .stat-info p  { color: var(--muted); font-size: 0.78rem; margin: 0; }

        /* ── Filter Bar ── */
        .filter-bar {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .form-control-dark, .form-select-dark {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 10px;
            font-size: 0.85rem;
            padding: 9px 14px;
        }

        .form-control-dark::placeholder { color: var(--muted); }

        .form-control-dark:focus, .form-select-dark:focus {
            background: rgba(255,255,255,0.07);
            border-color: var(--accent);
            color: var(--text);
            box-shadow: none;
        }

        .form-select-dark option { background: #1a1a2e; color: var(--text); }

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .section-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-header h6 { font-weight: 700; font-size: 0.9rem; margin: 0; display: flex; align-items: center; gap: 8px; }

        /* ── Assignment Row ── */
        .assign-row {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: background 0.2s;
            flex-wrap: wrap;
        }

        .assign-row:last-child { border-bottom: none; }
        .assign-row:hover { background: var(--bg-hover); }

        .assign-icon {
            width: 42px; height: 42px;
            border-radius: 11px;
            background: rgba(255,217,61,0.12);
            color: var(--yellow);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .assign-info { flex: 1; min-width: 220px; }
        .assign-title { font-size: 0.875rem; font-weight: 600; color: var(--text); margin-bottom: 3px; }
        .assign-sub { font-size: 0.74rem; color: var(--muted); display: flex; gap: 12px; flex-wrap: wrap; }
        .assign-sub span i { margin-right: 3px; }

        .assign-meta {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 0.76rem;
            color: var(--muted);
            flex-shrink: 0;
        }

        .badge-glass {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .bg-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .bg-muted  { background: rgba(255,255,255,0.08); color: var(--muted);  border: 1px solid var(--border); }
        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3); }
        .bg-red    { background: rgba(255,107,107,0.15); color: var(--accent); border: 1px solid rgba(255,107,107,0.3); }
        .bg-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   border: 1px solid rgba(0,212,255,0.3); }

        .due-overdue { color: var(--accent); }
        .due-soon    { color: var(--yellow); }
        .due-ok      { color: var(--green);  }

        .assign-actions { display: flex; gap: 6px; flex-shrink: 0; }

        .icon-btn {
            width: 34px; height: 34px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03);
            color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            text-decoration: none;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .icon-btn:hover { color: var(--text); border-color: var(--accent); }
        .icon-btn.danger:hover { color: var(--accent); border-color: var(--accent); background: rgba(255,107,107,0.1); }

        /* ── Empty State ── */
        .empty-state { padding: 40px 20px; text-align: center; color: var(--muted); }
        .empty-state i { font-size: 1.8rem; margin-bottom: 8px; opacity: 0.35; display: block; }
        .empty-state p { font-size: 0.82rem; margin: 0; }

        /* ── Pagination ── */
        .pagination-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pagination-wrap .info { font-size: 0.78rem; color: var(--muted); }

        .page-btn {
            width: 34px; height: 34px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03);
            color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .page-btn:hover { color: var(--text); border-color: var(--accent); }
        .page-btn.active { background: var(--accent); color: white; border-color: var(--accent); }
        .page-btn.disabled { opacity: 0.35; pointer-events: none; }

        /* ── Modal ── */
        .modal-content {
            background: #15151f;
            border: 1px solid var(--border);
            border-radius: 16px;
            color: var(--text);
        }

        .modal-header, .modal-footer { border-color: var(--border); }
        .modal-title { font-weight: 700; font-size: 1rem; }
        .btn-close { filter: invert(1); }
        .form-label { font-size: 0.82rem; font-weight: 600; color: var(--text); margin-bottom: 6px; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .assign-meta { width: 100%; justify-content: space-between; }
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
        <a href="manage_assignments.php" class="active">
            <i class="bi bi-clipboard2-check"></i> Manage Assignments
        </a>
        <a href="manage_notifications.php">
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
                <h6>Manage Assignments</h6>
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
                <h4>Manage Assignments</h4>
                <p>Create, edit, and monitor assignments across all courses.</p>
            </div>
            <button class="btn-action btn-red" data-bs-toggle="modal" data-bs-target="#addAssignModal">
                <i class="bi bi-plus-circle"></i> New Assignment
            </button>
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
                    <div class="stat-icon si-yellow"><i class="bi bi-clipboard2-check"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_active ?></h3>
                        <p>Active Assignments</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-red"><i class="bi bi-clipboard2-x"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_inactive ?></h3>
                        <p>Inactive</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-blue"><i class="bi bi-inbox"></i></div>
                    <div class="stat-info">
                        <h3><?= $total_submissions ?></h3>
                        <p>Total Submissions</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-orange"><i class="bi bi-hourglass-split"></i></div>
                    <div class="stat-info">
                        <h3><?= $pending_grading ?></h3>
                        <p>Pending Grading</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <input type="text" name="search" class="form-control-dark" style="flex:1; min-width:200px;"
                   placeholder="Search by title, course code or name..."
                   value="<?= htmlspecialchars($search) ?>">

            <select name="course_id" class="form-select-dark">
                <option value="">All Courses</option>
                <?php foreach ($courses_list as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_course == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="form-select-dark">
                <option value="">All Status</option>
                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>

            <button type="submit" class="btn-action btn-red"><i class="bi bi-search"></i> Filter</button>
            <?php if ($search !== '' || $filter_course > 0 || $filter_status !== ''): ?>
                <a href="manage_assignments.php" class="btn-action"
                   style="background:rgba(255,255,255,0.06); color:var(--muted);">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Assignments List -->
        <div class="section-card">
            <div class="section-header">
                <h6><i class="bi bi-clipboard2-check" style="color:var(--yellow)"></i> All Assignments</h6>
                <span style="color:var(--muted); font-size:0.78rem;">
                    <?= $total_rows ?> result<?= $total_rows == 1 ? '' : 's' ?>
                </span>
            </div>

            <?php if (count($assignments_list) > 0): ?>
                <?php foreach ($assignments_list as $a): ?>
                    <?php
                        $status_badge = $a['status'] === 'active' ? 'bg-green' : 'bg-muted';
                        $due_class    = '';
                        $due_label    = 'No due date';

                        if (!empty($a['due_date'])) {
                            $due_ts   = strtotime($a['due_date']);
                            $now_ts   = time();
                            $diff_days = ($due_ts - $now_ts) / 86400;
                            $due_label = date('d M Y', $due_ts);

                            if ($diff_days < 0) {
                                $due_class = 'due-overdue';
                                $due_label .= ' (Overdue)';
                            } elseif ($diff_days <= 3) {
                                $due_class = 'due-soon';
                                $due_label .= ' (Soon)';
                            } else {
                                $due_class = 'due-ok';
                            }
                        }
                    ?>
                    <div class="assign-row">
                        <div class="assign-icon">
                            <i class="bi bi-clipboard2-text"></i>
                        </div>

                        <div class="assign-info">
                            <div class="assign-title"><?= htmlspecialchars($a['title']) ?></div>
                            <div class="assign-sub">
                                <span><i class="bi bi-book"></i><?= htmlspecialchars($a['course_code']) ?></span>
                                <span><i class="bi bi-person"></i><?= htmlspecialchars($a['lecturer_name']) ?></span>
                                <span><i class="bi bi-trophy"></i><?= (int)$a['total_marks'] ?> marks</span>
                                <span><i class="bi bi-inbox"></i><?= (int)$a['submission_count'] ?> submission<?= $a['submission_count'] == 1 ? '' : 's' ?></span>
                            </div>
                        </div>

                        <div class="assign-meta">
                            <span class="badge-glass <?= $status_badge ?>"><?= ucfirst($a['status']) ?></span>
                            <span class="<?= $due_class ?>">
                                <i class="bi bi-calendar-event"></i> <?= $due_label ?>
                            </span>
                            <span><?= date('d M Y', strtotime($a['created_at'])) ?></span>
                        </div>

                        <div class="assign-actions">
                            <button type="button" class="icon-btn" title="Edit"
                                    onclick='openEditModal(<?= json_encode([
                                        "id"          => $a['id'],
                                        "course_id"   => $a['course_id'],
                                        "title"       => $a['title'],
                                        "description" => $a['description'] ?? '',
                                        "due_date"    => $a['due_date'] ?? '',
                                        "total_marks"   => $a['total_marks'] ?? 100,
                                    ]) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token"     value="<?= $csrf_token ?>">
                                <input type="hidden" name="action"         value="toggle_status">
                                <input type="hidden" name="assignment_id"  value="<?= $a['id'] ?>">
                                <input type="hidden" name="redirect_qs"    value="<?= htmlspecialchars($current_qs) ?>">
                                <button type="submit" class="icon-btn"
                                        title="<?= $a['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                    <i class="bi <?= $a['status'] === 'active' ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                                </button>
                            </form>

                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Delete this assignment and all its submissions? This cannot be undone.');">
                                <input type="hidden" name="csrf_token"    value="<?= $csrf_token ?>">
                                <input type="hidden" name="action"        value="delete_assignment">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="redirect_qs"   value="<?= htmlspecialchars($current_qs) ?>">
                                <button type="submit" class="icon-btn danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-clipboard2-x"></i>
                    <p>No assignments found. Try adjusting your filters or create a new one.</p>
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

<!-- ════════════ ADD ASSIGNMENT MODAL ════════════ -->
<div class="modal fade" id="addAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action"     value="add_assignment">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>New Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course</label>
                        <select name="course_id" class="form-select form-select-dark" required>
                            <option value="">Select a course</option>
                            <?php foreach ($courses_list as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control form-control-dark"
                               placeholder="e.g. Assignment 1 — Data Types" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control form-control-dark" rows="3"
                                  placeholder="Instructions, requirements, and details..."></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Due Date</label>
                            <input type="datetime-local" name="due_date" class="form-control form-control-dark">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Max Marks</label>
                            <input type="number" name="total_marks" class="form-control form-control-dark"
                                   value="100" min="1" max="1000" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action"
                            style="background:rgba(255,255,255,0.06); color:var(--muted);"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-action btn-red">
                        <i class="bi bi-plus-circle"></i> Create
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════════ EDIT ASSIGNMENT MODAL ════════════ -->
<div class="modal fade" id="editAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editAssignForm">
                <input type="hidden" name="csrf_token"    value="<?= $csrf_token ?>">
                <input type="hidden" name="action"        value="edit_assignment">
                <input type="hidden" name="assignment_id" id="edit_assign_id">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course</label>
                        <select name="course_id" id="edit_course_id" class="form-select form-select-dark" required>
                            <?php foreach ($courses_list as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" id="edit_title"
                               class="form-control form-control-dark" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description"
                                  class="form-control form-control-dark" rows="3"></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Due Date</label>
                            <input type="datetime-local" name="due_date" id="edit_due_date"
                                   class="form-control form-control-dark">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Max Marks</label>
                            <input type="number" name="total_marks" id="edit_total_marks"
                                   class="form-control form-control-dark"
                                   min="1" max="1000" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action"
                            style="background:rgba(255,255,255,0.06); color:var(--muted);"
                            data-bs-dismiss="modal">Cancel</button>
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

    function openEditModal(a) {
        document.getElementById('edit_assign_id').value   = a.id;
        document.getElementById('edit_course_id').value   = a.course_id;
        document.getElementById('edit_title').value       = a.title;
        document.getElementById('edit_description').value = a.description;
        document.getElementById('edit_total_marks').value   = a.total_marks;

        // Format due_date for datetime-local input (YYYY-MM-DDTHH:MM)
        if (a.due_date && a.due_date !== '') {
            // MySQL returns "YYYY-MM-DD HH:MM:SS" — convert to "YYYY-MM-DDTHH:MM"
            document.getElementById('edit_due_date').value = a.due_date.replace(' ', 'T').substring(0, 16);
        } else {
            document.getElementById('edit_due_date').value = '';
        }

        new bootstrap.Modal(document.getElementById('editAssignModal')).show();
    }
</script>
</body>
</html>