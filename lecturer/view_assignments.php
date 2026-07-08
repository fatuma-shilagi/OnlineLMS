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

// ── Handle delete action ─────────────────────────────────
$flash_success = '';
$flash_error   = '';

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $check  = mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT id FROM assignments WHERE id = '$del_id' AND created_by = '$lecturer_id'")
    );
    if ($check) {
        mysqli_query($conn, "UPDATE assignments SET status = 'deleted' WHERE id = '$del_id'");
        $flash_success = 'Assignment deleted successfully.';
    } else {
        $flash_error = 'Assignment not found or access denied.';
    }
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tog_id = (int)$_GET['toggle'];
    $cur = mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT id, status FROM assignments WHERE id = '$tog_id' AND created_by = '$lecturer_id'")
    );
    if ($cur) {
        $new_status = ($cur['status'] === 'active') ? 'draft' : 'active';
        mysqli_query($conn, "UPDATE assignments SET status = '$new_status' WHERE id = '$tog_id'");
        $flash_success = 'Assignment status updated.';
    }
}

// ── Filters ──────────────────────────────────────────────
$filter_course = isset($_GET['course']) ? (int)$_GET['course'] : 0;
$filter_status = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$filter_search = isset($_GET['q'])      ? mysqli_real_escape_string($conn, trim($_GET['q'])) : '';
$filter_sort   = isset($_GET['sort'])   ? mysqli_real_escape_string($conn, $_GET['sort'])   : 'newest';

// Build WHERE
$where = "a.created_by = '$lecturer_id' AND a.status != 'deleted'";
if ($filter_course) $where .= " AND a.course_id = '$filter_course'";
if ($filter_status) $where .= " AND a.status = '$filter_status'";
if ($filter_search) $where .= " AND (a.title LIKE '%$filter_search%' OR c.course_name LIKE '%$filter_search%' OR c.course_code LIKE '%$filter_search%')";

// Sort
$order = match($filter_sort) {
    'oldest'      => 'a.created_at ASC',
    'due_soon'    => 'a.due_date ASC',
    'due_late'    => 'a.due_date DESC',
    'most_subs'   => 'submission_count DESC',
    default       => 'a.created_at DESC',
};

// ── Summary counts ───────────────────────────────────────
$total_all = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM assignments WHERE created_by = '$lecturer_id' AND status != 'deleted'"
))['total'];

$total_active = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM assignments WHERE created_by = '$lecturer_id' AND status = 'active'"
))['total'];

$total_draft = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM assignments WHERE created_by = '$lecturer_id' AND status = 'draft'"
))['total'];

$total_closed = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM assignments WHERE created_by = '$lecturer_id' AND status = 'closed'"
))['total'];

// ── Fetch courses for filter dropdown ────────────────────
$my_courses = mysqli_query($conn,
    "SELECT id, course_name, course_code FROM courses
     WHERE lecturer_id = '$lecturer_id' AND status = 'active'
     ORDER BY course_code ASC"
);

// ── Fetch assignments ─────────────────────────────────────
$assignments = mysqli_query($conn,
    "SELECT a.*,
            c.course_name,
            c.course_code,
            (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) AS submission_count,
            (SELECT COUNT(*) FROM submissions s2
             INNER JOIN grades g ON s2.id = g.submission_id
             WHERE s2.assignment_id = a.id) AS graded_count,
            TIMESTAMPDIFF(HOUR, NOW(), a.due_date) AS hours_left
     FROM assignments a
     INNER JOIN courses c ON a.course_id = c.id
     WHERE $where
     ORDER BY $order"
);

$result_count = mysqli_num_rows($assignments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments - OnlineLMS</title>
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
        .sidebar-brand { padding: 22px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.15rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.72rem; }
        .sidebar-profile {
            padding: 18px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }
        .sidebar-profile img {
            width: 46px; height: 46px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--accent);
        }
        .sidebar-profile .name { font-weight: 600; font-size: 0.88rem; color: var(--text); }
        .role-badge {
            background: rgba(255,217,61,0.15); color: var(--accent);
            border: 1px solid rgba(255,217,61,0.3); border-radius: 20px;
            padding: 1px 10px; font-size: 0.68rem; font-weight: 700;
        }
        .sidebar-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
        .nav-section {
            padding: 8px 20px 4px; font-size: 0.67rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted);
        }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 20px; color: var(--muted); text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            border-left: 3px solid transparent; transition: all 0.2s;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active {
            color: var(--text); background: var(--bg-hover); border-left-color: var(--accent);
        }
        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1rem; width: 20px; }
        .nav-badge {
            margin-left: auto; background: var(--red); color: white;
            border-radius: 20px; padding: 1px 8px; font-size: 0.68rem; font-weight: 700;
        }
        .nav-badge.yellow { background: rgba(255,217,61,0.2); color: var(--accent); }
        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a {
            display: flex; align-items: center; gap: 10px; color: var(--red);
            text-decoration: none; font-size: 0.875rem; font-weight: 500;
            padding: 8px 0; transition: opacity 0.2s;
        }
        .sidebar-footer a:hover { opacity: 0.75; }

        /* ── Main ── */
        .main-content {
            margin-left: var(--sidebar-w); flex: 1;
            display: flex; flex-direction: column; min-height: 100vh;
        }

        /* ── Topbar ── */
        .topbar {
            background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border);
            padding: 13px 28px; display: flex; align-items: center;
            justify-content: space-between; position: sticky; top: 0;
            z-index: 50; backdrop-filter: blur(10px);
        }
        .topbar-left h6 { font-weight: 700; font-size: 1rem; margin: 0; }
        .topbar-left p  { color: var(--muted); font-size: 0.78rem; margin: 0; }
        .topbar-right   { display: flex; align-items: center; gap: 12px; }
        .topbar-btn {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 14px; color: var(--muted);
            text-decoration: none; font-size: 0.82rem; font-weight: 500;
            transition: all 0.2s; display: flex; align-items: center; gap: 6px;
        }
        .topbar-btn:hover { border-color: var(--accent); color: var(--accent); }
        .topbar-btn.primary {
            background: rgba(255,217,61,0.12);
            border-color: rgba(255,217,61,0.3); color: var(--accent);
        }
        .notif-btn {
            position: relative; background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px; color: var(--muted);
            text-decoration: none; transition: all 0.2s;
        }
        .notif-btn:hover { border-color: var(--accent); color: var(--accent); }
        .notif-dot {
            position: absolute; top: 5px; right: 7px;
            width: 8px; height: 8px; background: var(--red);
            border-radius: 50%; border: 2px solid var(--bg-main);
        }
        .hamburger {
            display: none; background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px; color: var(--text); cursor: pointer;
        }

        /* ── Page Body ── */
        .page-body { padding: 26px; flex: 1; }

        /* ── Page Header ── */
        .page-header {
            display: flex; align-items: flex-start;
            justify-content: space-between; margin-bottom: 24px; gap: 16px;
        }
        .page-header h4 { font-weight: 800; font-size: 1.25rem; margin-bottom: 3px; }
        .page-header p  { color: var(--muted); font-size: 0.82rem; margin: 0; }

        /* ── Flash alert ── */
        .alert-glass {
            border-radius: 12px; padding: 12px 18px; font-size: 0.845rem;
            font-weight: 500; display: flex; align-items: center;
            gap: 10px; margin-bottom: 20px;
        }
        .alert-success {
            background: rgba(107,203,119,0.1); border: 1px solid rgba(107,203,119,0.3);
            color: var(--green);
        }
        .alert-danger {
            background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.3);
            color: var(--red);
        }

        /* ── Summary Stat Tabs ── */
        .stat-tabs {
            display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .stat-tab {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 11px; padding: 10px 16px; cursor: pointer;
            text-decoration: none; color: var(--text); transition: all 0.2s;
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .stat-tab:hover { background: var(--bg-hover); border-color: rgba(255,255,255,0.15); color: var(--text); }
        .stat-tab.active-tab { border-color: var(--accent); background: rgba(255,217,61,0.06); color: var(--accent); }
        .stat-tab .tab-num { font-size: 1.2rem; font-weight: 800; line-height: 1; }
        .stat-tab .tab-label { font-size: 0.73rem; color: var(--muted); }
        .stat-tab.active-tab .tab-label { color: rgba(255,217,61,0.6); }

        /* ── Filter Bar ── */
        .filter-bar {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 13px; padding: 14px 18px;
            display: flex; gap: 10px; align-items: center;
            flex-wrap: wrap; margin-bottom: 20px;
        }
        .filter-bar .search-wrap {
            position: relative; flex: 1; min-width: 180px;
        }
        .filter-bar .search-wrap i {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%); color: var(--muted); font-size: 0.9rem;
        }
        .filter-bar input[type="text"] {
            background: rgba(255,255,255,0.05); border: 1px solid var(--border);
            border-radius: 9px; color: var(--text); font-size: 0.845rem;
            padding: 8px 12px 8px 34px; width: 100%; outline: none; transition: all 0.2s;
        }
        .filter-bar input[type="text"]::placeholder { color: var(--muted); }
        .filter-bar input[type="text"]:focus {
            border-color: rgba(255,217,61,0.4); background: rgba(255,255,255,0.07);
        }
        .filter-bar select {
            background: rgba(255,255,255,0.05); border: 1px solid var(--border);
            border-radius: 9px; color: var(--text); font-size: 0.82rem;
            padding: 8px 12px; outline: none; cursor: pointer; transition: all 0.2s;
        }
        .filter-bar select:focus { border-color: rgba(255,217,61,0.4); }
        .filter-bar select option { background: #1a1a2e; color: var(--text); }
        .filter-count {
            font-size: 0.75rem; color: var(--muted); white-space: nowrap; padding: 0 4px;
        }
        .btn-clear-filter {
            background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.25);
            border-radius: 9px; color: var(--red); font-size: 0.78rem; font-weight: 600;
            padding: 8px 12px; text-decoration: none; display: flex; align-items: center;
            gap: 5px; white-space: nowrap; transition: all 0.2s;
        }
        .btn-clear-filter:hover { background: rgba(255,107,107,0.18); color: var(--red); }

        /* ── Assignment Cards Grid ── */
        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }

        .assignment-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 15px; overflow: hidden;
            transition: all 0.25s; display: flex; flex-direction: column;
        }
        .assignment-card:hover {
            background: var(--bg-hover); border-color: rgba(255,255,255,0.13);
            transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.35);
        }

        .card-top {
            padding: 18px 18px 14px; border-bottom: 1px solid var(--border); flex: 1;
        }

        .card-top-meta {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 10px;
        }

        .course-chip {
            background: rgba(255,217,61,0.1); color: var(--accent);
            border: 1px solid rgba(255,217,61,0.25); border-radius: 7px;
            padding: 2px 10px; font-size: 0.7rem; font-weight: 700;
        }

        .status-pill {
            padding: 2px 10px; border-radius: 20px;
            font-size: 0.68rem; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .sp-active { background: rgba(107,203,119,0.12); color: var(--green); border: 1px solid rgba(107,203,119,0.3); }
        .sp-draft  { background: rgba(255,255,255,0.06); color: var(--muted); border: 1px solid var(--border); }
        .sp-closed { background: rgba(255,217,61,0.1);  color: var(--accent); border: 1px solid rgba(255,217,61,0.25); }

        .card-title {
            font-size: 0.95rem; font-weight: 700; color: var(--text);
            margin-bottom: 6px; line-height: 1.35;
        }

        .card-desc {
            font-size: 0.78rem; color: var(--muted); line-height: 1.55;
            margin-bottom: 14px;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }

        /* Deadline bar */
        .deadline-bar {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.03); border: 1px solid var(--border);
            border-radius: 9px; padding: 8px 12px; margin-bottom: 12px;
        }
        .deadline-bar i { font-size: 0.9rem; flex-shrink: 0; }
        .deadline-bar .dl-info { flex: 1; min-width: 0; }
        .deadline-bar .dl-date { font-size: 0.78rem; font-weight: 600; color: var(--text); }
        .deadline-bar .dl-rel  { font-size: 0.7rem; color: var(--muted); }
        .dl-urgent { color: var(--red); }
        .dl-soon   { color: var(--accent); }
        .dl-ok     { color: var(--green); }
        .dl-past   { color: var(--muted); }

        /* Stats row */
        .card-stats {
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .card-stat {
            background: rgba(255,255,255,0.04); border: 1px solid var(--border);
            border-radius: 7px; padding: 4px 10px;
            font-size: 0.72rem; color: var(--muted);
            display: flex; align-items: center; gap: 4px;
        }
        .card-stat strong { color: var(--text); }

        /* Progress bar on grading */
        .grade-progress { margin-top: 10px; }
        .grade-progress-label {
            display: flex; justify-content: space-between;
            font-size: 0.7rem; color: var(--muted); margin-bottom: 4px;
        }
        .grade-track {
            height: 4px; background: var(--border); border-radius: 2px; overflow: hidden;
        }
        .grade-fill {
            height: 100%; border-radius: 2px;
            background: linear-gradient(90deg, var(--green), #4ade80);
            transition: width 0.4s ease;
        }

        /* ── Card Actions ── */
        .card-actions {
            padding: 12px 18px; display: flex; gap: 8px; align-items: center;
            border-top: 1px solid var(--border);
        }

        .ca-btn {
            padding: 6px 13px; border-radius: 8px; font-size: 0.76rem;
            font-weight: 600; text-decoration: none; display: inline-flex;
            align-items: center; gap: 5px; transition: all 0.2s; border: none; cursor: pointer;
        }
        .ca-grade {
            background: rgba(107,203,119,0.12); color: var(--green);
            border: 1px solid rgba(107,203,119,0.3);
        }
        .ca-grade:hover { background: rgba(107,203,119,0.22); color: var(--green); }

        .ca-edit {
            background: rgba(0,212,255,0.1); color: var(--blue);
            border: 1px solid rgba(0,212,255,0.25);
        }
        .ca-edit:hover { background: rgba(0,212,255,0.2); color: var(--blue); }

        .ca-toggle {
            background: rgba(180,143,252,0.1); color: var(--purple);
            border: 1px solid rgba(180,143,252,0.25);
        }
        .ca-toggle:hover { background: rgba(180,143,252,0.2); color: var(--purple); }

        .ca-delete {
            margin-left: auto; background: rgba(255,107,107,0.08);
            color: var(--red); border: 1px solid rgba(255,107,107,0.2);
        }
        .ca-delete:hover { background: rgba(255,107,107,0.18); color: var(--red); }

        /* pending badge inside action */
        .pending-dot {
            width: 7px; height: 7px; background: var(--red);
            border-radius: 50%; display: inline-block; margin-left: 2px;
        }

        /* ── Empty State ── */
        .empty-state {
            grid-column: 1 / -1;
            padding: 60px 20px; text-align: center;
        }
        .empty-state-icon {
            width: 72px; height: 72px; border-radius: 20px;
            background: rgba(255,217,61,0.08); border: 1px solid rgba(255,217,61,0.15);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem; color: var(--accent); margin: 0 auto 18px;
        }
        .empty-state h5 { font-size: 1rem; font-weight: 700; margin-bottom: 6px; }
        .empty-state p  { color: var(--muted); font-size: 0.82rem; margin-bottom: 18px; }

        /* ── CTA Button ── */
        .btn-action {
            padding: 9px 20px; border-radius: 10px; font-size: 0.82rem;
            font-weight: 600; text-decoration: none; display: inline-flex;
            align-items: center; gap: 6px; transition: all 0.2s;
            border: none; cursor: pointer;
        }
        .btn-yellow { background: var(--accent); color: #1a1a2e; }
        .btn-yellow:hover { background: #ffcd00; color: #1a1a2e; transform: translateY(-1px); }

        /* ── Modal overlay ── */
        .confirm-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.65); z-index: 200;
            align-items: center; justify-content: center;
        }
        .confirm-overlay.show { display: flex; }
        .confirm-box {
            background: #16162a; border: 1px solid var(--border);
            border-radius: 16px; padding: 28px 28px 22px;
            max-width: 380px; width: 90%; text-align: center;
        }
        .confirm-box .cbox-icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; color: var(--red); margin: 0 auto 14px;
        }
        .confirm-box h6 { font-weight: 700; font-size: 1rem; margin-bottom: 6px; }
        .confirm-box p  { color: var(--muted); font-size: 0.82rem; margin-bottom: 20px; }
        .confirm-box .cbox-btns { display: flex; gap: 10px; }
        .cbox-cancel {
            flex: 1; padding: 9px; border-radius: 9px; border: 1px solid var(--border);
            background: var(--bg-card); color: var(--muted); font-size: 0.82rem;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
        }
        .cbox-cancel:hover { border-color: var(--accent); color: var(--accent); }
        .cbox-confirm {
            flex: 1; padding: 9px; border-radius: 9px; border: none;
            background: var(--red); color: white; font-size: 0.82rem;
            font-weight: 700; cursor: pointer; transition: all 0.2s;
        }
        .cbox-confirm:hover { background: #e55555; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .assignments-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; }
        }
        @media (max-width: 500px) {
            .stat-tabs { gap: 7px; }
            .stat-tab { padding: 8px 12px; }
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
        <a href="view_assignments.php" class="active">
            <i class="bi bi-clipboard2-check"></i> Assignments
            <?php if ($assignments_count > 0): ?><span class="nav-badge yellow"><?= $assignments_count ?></span><?php endif; ?>
        </a>
        <a href="grade_submissions.php">
            <i class="bi bi-patch-check"></i> Grade Submissions
            <?php if ($pending_grading > 0): ?><span class="nav-badge"><?= $pending_grading ?></span><?php endif; ?>
        </a>
        <div class="nav-section">Communication</div>
        <a href="notifications.php">
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
                <h6>Assignments</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="create_assignment.php" class="topbar-btn primary d-none d-md-flex">
                <i class="bi bi-plus-circle"></i> New Assignment
            </a>
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

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="bi bi-clipboard2-check me-2" style="color:var(--accent)"></i>My Assignments</h4>
                <p>Manage, track submissions, and grade work across all your courses.</p>
            </div>
            <a href="create_assignment.php" class="btn-action btn-yellow flex-shrink-0">
                <i class="bi bi-plus-circle"></i> New Assignment
            </a>
        </div>

        <!-- Flash alerts -->
        <?php if ($flash_success): ?>
            <div class="alert-glass alert-success">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert-glass alert-danger">
                <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>

        <!-- Summary Tabs -->
        <div class="stat-tabs">
            <?php
            $tab_defs = [
                ['label'=>'All',    'val'=>'',       'count'=>$total_all,    'icon'=>'bi-grid'],
                ['label'=>'Active', 'val'=>'active', 'count'=>$total_active, 'icon'=>'bi-check-circle'],
                ['label'=>'Draft',  'val'=>'draft',  'count'=>$total_draft,  'icon'=>'bi-pencil-square'],
                ['label'=>'Closed', 'val'=>'closed', 'count'=>$total_closed, 'icon'=>'bi-lock'],
            ];
            foreach ($tab_defs as $tab):
                $is_active = ($filter_status === $tab['val']) ? 'active-tab' : '';
                $url = '?status=' . urlencode($tab['val'])
                     . ($filter_course ? '&course='.$filter_course : '')
                     . ($filter_search ? '&q='.urlencode($filter_search) : '')
                     . '&sort=' . urlencode($filter_sort);
            ?>
                <a href="<?= $url ?>" class="stat-tab <?= $is_active ?>">
                    <div>
                        <div class="tab-num"><?= $tab['count'] ?></div>
                        <div class="tab-label"><i class="bi <?= $tab['icon'] ?> me-1"></i><?= $tab['label'] ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if ($pending_grading > 0): ?>
                <a href="grade_submissions.php" class="stat-tab" style="border-color:rgba(255,107,107,0.3);background:rgba(255,107,107,0.05);">
                    <div>
                        <div class="tab-num" style="color:var(--red)"><?= $pending_grading ?></div>
                        <div class="tab-label"><i class="bi bi-hourglass-split me-1"></i>To Grade</div>
                    </div>
                </a>
            <?php endif; ?>
        </div>

        <!-- Filter Bar -->
        <form method="GET" action="" id="filterForm">
            <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
            <div class="filter-bar">
                <!-- Search -->
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" placeholder="Search assignments…"
                           value="<?= htmlspecialchars($filter_search) ?>"
                           oninput="debounceSubmit()">
                </div>
                <!-- Course filter -->
                <select name="course" onchange="this.form.submit()">
                    <option value="">All Courses</option>
                    <?php
                    mysqli_data_seek($my_courses, 0);
                    while ($c = mysqli_fetch_assoc($my_courses)):
                        $sel = ($filter_course === (int)$c['id']) ? 'selected' : '';
                    ?>
                        <option value="<?= $c['id'] ?>" <?= $sel ?>>
                            <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <!-- Sort -->
                <select name="sort" onchange="this.form.submit()">
                    <option value="newest"   <?= $filter_sort==='newest'   ? 'selected':'' ?>>Newest first</option>
                    <option value="oldest"   <?= $filter_sort==='oldest'   ? 'selected':'' ?>>Oldest first</option>
                    <option value="due_soon" <?= $filter_sort==='due_soon' ? 'selected':'' ?>>Due soonest</option>
                    <option value="due_late" <?= $filter_sort==='due_late' ? 'selected':'' ?>>Due latest</option>
                    <option value="most_subs"<?= $filter_sort==='most_subs'? 'selected':'' ?>>Most submissions</option>
                </select>

                <span class="filter-count"><?= $result_count ?> result<?= $result_count !== 1 ? 's' : '' ?></span>

                <?php if ($filter_search || $filter_course || ($filter_status && $filter_status !== '')): ?>
                    <a href="view_assignments.php" class="btn-clear-filter">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Assignments Grid -->
        <div class="assignments-grid">
            <?php if ($result_count === 0): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-clipboard2"></i></div>
                    <?php if ($filter_search || $filter_course || $filter_status): ?>
                        <h5>No assignments match your filters</h5>
                        <p>Try adjusting the search, course, or status filter.</p>
                        <a href="view_assignments.php" class="btn-action btn-yellow">Clear Filters</a>
                    <?php else: ?>
                        <h5>No assignments yet</h5>
                        <p>Create your first assignment and students will see it here.</p>
                        <a href="create_assignment.php" class="btn-action btn-yellow">
                            <i class="bi bi-plus-circle"></i> Create Assignment
                        </a>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <?php while ($a = mysqli_fetch_assoc($assignments)):
                    $hours_left = (int)$a['hours_left'];
                    $is_past    = $hours_left < 0;
                    $sub_count  = (int)$a['submission_count'];
                    $grad_count = (int)$a['graded_count'];
                    $ungraded   = $sub_count - $grad_count;
                    $grade_pct  = $sub_count > 0 ? round(($grad_count / $sub_count) * 100) : 0;

                    // Deadline styling
                    if ($is_past) {
                        $dl_class = 'dl-past'; $dl_icon = 'bi-clock-history';
                        $dl_rel   = 'Closed ' . abs($hours_left) . 'h ago';
                    } elseif ($hours_left <= 24) {
                        $dl_class = 'dl-urgent'; $dl_icon = 'bi-alarm-fill';
                        $dl_rel   = 'Due in ' . $hours_left . 'h — urgent!';
                    } elseif ($hours_left <= 72) {
                        $dl_class = 'dl-soon'; $dl_icon = 'bi-alarm';
                        $dl_rel   = 'Due in ' . round($hours_left/24, 1) . 'd';
                    } else {
                        $dl_class = 'dl-ok'; $dl_icon = 'bi-calendar-check';
                        $dl_rel   = 'Due in ' . round($hours_left/24) . ' days';
                    }

                    // Status pill
                    $status_map = [
                        'active' => ['cls'=>'sp-active','icon'=>'bi-check-circle-fill','lbl'=>'Active'],
                        'draft'  => ['cls'=>'sp-draft', 'icon'=>'bi-pencil-square',    'lbl'=>'Draft'],
                        'closed' => ['cls'=>'sp-closed','icon'=>'bi-lock-fill',         'lbl'=>'Closed'],
                    ];
                    $sp = $status_map[$a['status']] ?? $status_map['draft'];
                ?>
                <div class="assignment-card">
                    <div class="card-top">
                        <!-- Top meta row -->
                        <div class="card-top-meta">
                            <span class="course-chip"><?= htmlspecialchars($a['course_code']) ?></span>
                            <span class="status-pill <?= $sp['cls'] ?>">
                                <i class="bi <?= $sp['icon'] ?>"></i>
                                <?= $sp['lbl'] ?>
                            </span>
                        </div>

                        <!-- Title -->
                        <div class="card-title"><?= htmlspecialchars($a['title']) ?></div>

                        <!-- Description -->
                        <?php if (!empty($a['description'])): ?>
                            <div class="card-desc"><?= htmlspecialchars($a['description']) ?></div>
                        <?php endif; ?>

                        <!-- Deadline bar -->
                        <div class="deadline-bar">
                            <i class="bi <?= $dl_icon ?> <?= $dl_class ?>"></i>
                            <div class="dl-info">
                                <div class="dl-date">
                                    <?= date('d M Y · H:i', strtotime($a['due_date'])) ?>
                                </div>
                                <div class="dl-rel <?= $dl_class ?>"><?= $dl_rel ?></div>
                            </div>
                            <span style="font-size:0.72rem;color:var(--muted);"><?= $a['total_marks'] ?> pts</span>
                        </div>

                        <!-- Stats -->
                        <div class="card-stats">
                            <div class="card-stat">
                                <i class="bi bi-inbox" style="color:var(--blue)"></i>
                                <strong><?= $sub_count ?></strong> submitted
                            </div>
                            <div class="card-stat">
                                <i class="bi bi-patch-check" style="color:var(--green)"></i>
                                <strong><?= $grad_count ?></strong> graded
                            </div>
                            <?php if ($ungraded > 0): ?>
                            <div class="card-stat" style="border-color:rgba(255,107,107,0.25);color:var(--red);">
                                <i class="bi bi-hourglass-split"></i>
                                <strong><?= $ungraded ?></strong> pending
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($a['allow_late_submission']) && $a['allow_late_submission']): ?>
                            <div class="card-stat" style="color:var(--purple);border-color:rgba(180,143,252,0.2);">
                                <i class="bi bi-clock-history"></i> Late OK
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Grading progress (only if any submissions) -->
                        <?php if ($sub_count > 0): ?>
                        <div class="grade-progress">
                            <div class="grade-progress-label">
                                <span>Grading progress</span>
                                <span><?= $grade_pct ?>%</span>
                            </div>
                            <div class="grade-track">
                                <div class="grade-fill" style="width:<?= $grade_pct ?>%"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="card-actions">
                        <?php if ($sub_count > 0): ?>
                            <a href="grade_submissions.php?assignment_id=<?= $a['id'] ?>"
                               class="ca-btn ca-grade">
                                <i class="bi bi-patch-check"></i> Grade
                                <?php if ($ungraded > 0): ?>
                                    <span class="pending-dot"></span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>

                        <a href="edit_assignment.php?id=<?= $a['id'] ?>" class="ca-btn ca-edit">
                            <i class="bi bi-pencil"></i> Edit
                        </a>

                        <button class="ca-btn ca-toggle"
                                onclick="window.location='view_assignments.php?toggle=<?= $a['id'] ?>&status=<?= urlencode($filter_status) ?>&course=<?= $filter_course ?>&q=<?= urlencode($filter_search) ?>&sort=<?= urlencode($filter_sort) ?>'">
                            <?php if ($a['status'] === 'active'): ?>
                                <i class="bi bi-eye-slash"></i> Unpublish
                            <?php else: ?>
                                <i class="bi bi-eye"></i> Publish
                            <?php endif; ?>
                        </button>

                        <button class="ca-btn ca-delete"
                                onclick="confirmDelete(<?= $a['id'] ?>, '<?= addslashes(htmlspecialchars($a['title'])) ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.5); z-index:99;"></div>

<!-- Delete Confirm Modal -->
<div class="confirm-overlay" id="deleteModal">
    <div class="confirm-box">
        <div class="cbox-icon"><i class="bi bi-trash3-fill"></i></div>
        <h6>Delete Assignment?</h6>
        <p id="deleteModalMsg">This will permanently remove the assignment and hide it from students.</p>
        <div class="cbox-btns">
            <button class="cbox-cancel" onclick="closeDeleteModal()">Cancel</button>
            <a href="#" id="deleteConfirmBtn" class="cbox-confirm">Yes, Delete</a>
        </div>
    </div>
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

    /* ── Search debounce ── */
    let searchTimer;
    function debounceSubmit() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 420);
    }

    /* ── Delete confirm modal ── */
    function confirmDelete(id, title) {
        document.getElementById('deleteModalMsg').textContent =
            'Delete "' + title + '"? This cannot be undone.';
        const url = 'view_assignments.php?delete=' + id
                  + '<?= $filter_status ? "&status=".urlencode($filter_status) : "" ?>'
                  + '<?= $filter_course ? "&course=".$filter_course : "" ?>'
                  + '<?= $filter_search ? "&q=".urlencode($filter_search) : "" ?>'
                  + '&sort=<?= urlencode($filter_sort) ?>';
        document.getElementById('deleteConfirmBtn').href = url;
        document.getElementById('deleteModal').classList.add('show');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('show');
    }

    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });
</script>
</body>
</html>