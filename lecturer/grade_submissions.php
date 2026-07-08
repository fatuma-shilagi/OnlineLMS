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

// ── Handle grade submission ──────────────────────────────
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    $submission_id  = (int) $_POST['submission_id'];
    $marks_obtained = (float) $_POST['marks_obtained'];
    $feedback       = trim($_POST['feedback'] ?? '');

    // Verify submission belongs to lecturer's assignment and get student_id, assignment_id, total_marks
    $stmt = mysqli_prepare($conn,
        "SELECT s.id, s.student_id, s.assignment_id, a.total_marks
         FROM submissions s
         INNER JOIN assignments a ON s.assignment_id = a.id
         WHERE s.id = ? AND a.created_by = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $submission_id, $lecturer_id);
    mysqli_stmt_execute($stmt);
    $verify = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$verify) {
        $error_msg = 'Submission not found or access denied.';
    } elseif ($marks_obtained < 0 || $marks_obtained > $verify['total_marks']) {
        $error_msg = "Marks must be between 0 and {$verify['total_marks']}.";
    } else {
        // Check if grade exists
        $stmt = mysqli_prepare($conn, "SELECT id FROM grades WHERE submission_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $submission_id);
        mysqli_stmt_execute($stmt);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($exists) {
            // Update existing grade
            $stmt = mysqli_prepare($conn,
                "UPDATE grades
                 SET marks_obtained = ?, feedback = ?, graded_at = NOW()
                 WHERE submission_id = ?"
            );
            mysqli_stmt_bind_param($stmt, "dsi", $marks_obtained, $feedback, $submission_id);
            mysqli_stmt_execute($stmt);
        } else {
            // Insert new grade with all required fields
            $stmt = mysqli_prepare($conn,
                "INSERT INTO grades (submission_id, student_id, assignment_id, marks_obtained, total_marks, feedback, graded_by, graded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            mysqli_stmt_bind_param($stmt, "iiiddis",
                $submission_id,
                $verify['student_id'],
                $verify['assignment_id'],
                $marks_obtained,
                $verify['total_marks'],
                $feedback,
                $lecturer_id
            );
            mysqli_stmt_execute($stmt);
        }
        $success_msg = 'Grade saved successfully!';
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

// ── Filters ──────────────────────────────────────────────
$filter_status  = $_GET['status']  ?? 'all';   // all | pending | graded
$filter_course  = $_GET['course']  ?? '';
$search         = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));

// ── Single submission modal ───────────────────────────────
$view_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$view_submission = null;
if ($view_id) {
    $view_submission = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT s.*, u.name AS student_name, u.email AS student_email,
                a.title AS assignment_title, a.description AS assignment_desc,
                a.total_marks, a.due_date,
                c.course_name, c.course_code,
                g.marks_obtained, g.feedback, g.graded_at, g.id AS grade_id
         FROM submissions s
         INNER JOIN users u ON s.student_id = u.id
         INNER JOIN assignments a ON s.assignment_id = a.id
         INNER JOIN courses c ON a.course_id = c.id
         LEFT JOIN grades g ON s.id = g.submission_id
         WHERE s.id = '$view_id' AND a.created_by = '$lecturer_id'"
    ));
}

// ── My courses for filter ─────────────────────────────────
$my_courses = mysqli_query($conn,
    "SELECT id, course_name, course_code FROM courses
     WHERE lecturer_id = '$lecturer_id' AND status = 'active'
     ORDER BY course_name ASC"
);

// ── Build WHERE clause ────────────────────────────────────
$where = "a.created_by = '$lecturer_id'";

if ($filter_status === 'pending') {
    $where .= " AND g.id IS NULL";
} elseif ($filter_status === 'graded') {
    $where .= " AND g.id IS NOT NULL";
}

if ($filter_course !== '') {
    $fc = (int) $filter_course;
    $where .= " AND c.id = '$fc'";
}

if ($search !== '') {
    $where .= " AND (u.name LIKE '%$search%' OR a.title LIKE '%$search%')";
}

// ── Pagination ────────────────────────────────────────────
$per_page    = 15;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

$total_rows  = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM submissions s
     INNER JOIN users u ON s.student_id = u.id
     INNER JOIN assignments a ON s.assignment_id = a.id
     INNER JOIN courses c ON a.course_id = c.id
     LEFT JOIN grades g ON s.id = g.submission_id
     WHERE $where"
))['total'];

$total_pages = ceil($total_rows / $per_page);

// ── Submissions list ──────────────────────────────────────
$submissions = mysqli_query($conn,
    "SELECT s.*, u.name AS student_name,
            a.title AS assignment_title, a.total_marks, a.due_date,
            c.course_name, c.course_code,
            g.marks_obtained, g.feedback, g.graded_at, g.id AS grade_id
     FROM submissions s
     INNER JOIN users u ON s.student_id = u.id
     INNER JOIN assignments a ON s.assignment_id = a.id
     INNER JOIN courses c ON a.course_id = c.id
     LEFT JOIN grades g ON s.id = g.submission_id
     WHERE $where
     ORDER BY
         CASE WHEN g.id IS NULL THEN 0 ELSE 1 END ASC,
         s.submitted_at DESC
     LIMIT $per_page OFFSET $offset"
);

// ── Stats for this filtered view ──────────────────────────
$graded_count = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM submissions s
     INNER JOIN users u ON s.student_id = u.id
     INNER JOIN assignments a ON s.assignment_id = a.id
     INNER JOIN courses c ON a.course_id = c.id
     INNER JOIN grades g ON s.id = g.submission_id
     WHERE a.created_by = '$lecturer_id'"
))['total'];

$submissions_count = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM submissions s
     INNER JOIN assignments a ON s.assignment_id = a.id
     WHERE a.created_by = '$lecturer_id'"
))['total'];

// Build query string for pagination
$query_params = http_build_query(array_filter([
    'status' => $filter_status !== 'all' ? $filter_status : '',
    'course' => $filter_course,
    'search' => $search,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Submissions - OnlineLMS</title>
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
        .topbar-btn { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 8px 14px; color: var(--muted); text-decoration: none; font-size: 0.82rem; font-weight: 500; transition: all 0.2s; display: flex; align-items: center; gap: 6px; }
        .topbar-btn:hover { border-color: var(--accent); color: var(--accent); }
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
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green); }
        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); }
        .si-red    { background: rgba(255,107,107,0.15); color: var(--red); }
        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue); }
        .stat-info h3 { font-size: 1.55rem; font-weight: 800; line-height: 1; margin-bottom: 2px; }
        .stat-info p  { color: var(--muted); font-size: 0.75rem; margin: 0; }

        /* ── Filters bar ── */
        .filter-bar { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 16px 20px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .filter-bar input, .filter-bar select { background: rgba(255,255,255,0.06); border: 1px solid var(--border); border-radius: 9px; color: var(--text); padding: 8px 14px; font-size: 0.82rem; outline: none; transition: border 0.2s; }
        .filter-bar input:focus, .filter-bar select:focus { border-color: var(--accent); }
        .filter-bar input::placeholder { color: var(--muted); }
        .filter-bar select option { background: #1a1a2e; }
        .filter-btn { padding: 8px 16px; border-radius: 9px; font-size: 0.82rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .filter-btn-primary { background: var(--accent); color: #1a1a2e; }
        .filter-btn-primary:hover { background: #ffcd00; }
        .filter-btn-outline { background: transparent; border: 1px solid var(--border); color: var(--muted); }
        .filter-btn-outline:hover { border-color: var(--accent); color: var(--accent); }

        /* ── Status tabs ── */
        .status-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .status-tab { padding: 7px 16px; border-radius: 30px; font-size: 0.78rem; font-weight: 600; border: 1px solid var(--border); text-decoration: none; color: var(--muted); transition: all 0.2s; }
        .status-tab:hover { border-color: var(--accent); color: var(--accent); }
        .status-tab.active-tab { background: rgba(255,217,61,0.15); border-color: rgba(255,217,61,0.4); color: var(--accent); }
        .status-tab.tab-pending.active-tab { background: rgba(255,107,107,0.15); border-color: rgba(255,107,107,0.4); color: var(--red); }
        .status-tab.tab-graded.active-tab  { background: rgba(107,203,119,0.15); border-color: rgba(107,203,119,0.4); color: var(--green); }

        /* ── Table ── */
        .table-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
        .table-card table { width: 100%; border-collapse: collapse; }
        .table-card thead th { padding: 12px 18px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
        .table-card tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
        .table-card tbody tr:last-child { border-bottom: none; }
        .table-card tbody tr:hover { background: var(--bg-hover); }
        .table-card tbody td { padding: 13px 18px; font-size: 0.845rem; vertical-align: middle; }

        /* ── Badges ── */
        .badge-glass { padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; white-space: nowrap; }
        .bg-yellow { background: rgba(255,217,61,0.15);  color: var(--accent); border: 1px solid rgba(255,217,61,0.3); }
        .bg-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   border: 1px solid rgba(0,212,255,0.3); }
        .bg-green  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .bg-red    { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3); }
        .bg-purple { background: rgba(180,143,252,0.15); color: var(--purple); border: 1px solid rgba(180,143,252,0.3); }

        /* ── Buttons ── */
        .btn-grade { background: rgba(255,217,61,0.12); border: 1px solid rgba(255,217,61,0.35); color: var(--accent); border-radius: 8px; padding: 5px 14px; font-size: 0.78rem; font-weight: 600; text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }
        .btn-grade:hover { background: rgba(255,217,61,0.22); color: var(--accent); }
        .btn-view  { background: rgba(0,212,255,0.1); border: 1px solid rgba(0,212,255,0.3); color: var(--blue); border-radius: 8px; padding: 5px 14px; font-size: 0.78rem; font-weight: 600; text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 5px; }
        .btn-view:hover { background: rgba(0,212,255,0.18); color: var(--blue); }

        /* ── Score circle ── */
        .score-circle { display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border-radius: 50%; font-weight: 800; font-size: 0.78rem; }

        /* ── Progress bar ── */
        .progress-thin { height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 3px; }

        /* ── Pagination ── */
        .pagination-bar { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid var(--border); }
        .pagination-bar p { color: var(--muted); font-size: 0.78rem; margin: 0; }
        .page-btns { display: flex; gap: 6px; }
        .page-btn { background: var(--bg-card); border: 1px solid var(--border); color: var(--muted); border-radius: 8px; padding: 5px 12px; font-size: 0.78rem; text-decoration: none; transition: all 0.2s; }
        .page-btn:hover, .page-btn.active { border-color: var(--accent); color: var(--accent); background: rgba(255,217,61,0.08); }
        .page-btn.disabled { opacity: 0.35; pointer-events: none; }

        /* ── Empty state ── */
        .empty-state { padding: 50px 20px; text-align: center; color: var(--muted); }
        .empty-state i { font-size: 2.2rem; margin-bottom: 10px; opacity: 0.3; display: block; }
        .empty-state p { font-size: 0.85rem; margin: 0; }

        /* ── Modal ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #16162a; border: 1px solid var(--border); border-radius: 18px; width: 100%; max-width: 620px; max-height: 90vh; overflow-y: auto; animation: modalIn 0.22s ease; }
        @keyframes modalIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: #16162a; z-index: 1; }
        .modal-header h5 { font-size: 1rem; font-weight: 700; margin: 0; }
        .modal-close { background: var(--bg-card); border: 1px solid var(--border); color: var(--muted); border-radius: 8px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1rem; transition: all 0.2s; }
        .modal-close:hover { border-color: var(--red); color: var(--red); }
        .modal-body { padding: 22px 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }

        /* ── Form elements ── */
        .form-label-custom { font-size: 0.78rem; font-weight: 600; color: var(--muted); margin-bottom: 6px; display: block; text-transform: uppercase; letter-spacing: 0.6px; }
        .form-control-custom { background: rgba(255,255,255,0.06); border: 1px solid var(--border); border-radius: 10px; color: var(--text); padding: 10px 14px; font-size: 0.85rem; width: 100%; outline: none; transition: border 0.2s; }
        .form-control-custom:focus { border-color: var(--accent); background: rgba(255,255,255,0.08); }
        .form-control-custom::placeholder { color: var(--muted); }
        .btn-save { background: var(--accent); color: #1a1a2e; border: none; border-radius: 10px; padding: 10px 24px; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }
        .btn-save:hover { background: #ffcd00; transform: translateY(-1px); }
        .btn-cancel { background: var(--bg-card); border: 1px solid var(--border); color: var(--muted); border-radius: 10px; padding: 10px 20px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-cancel:hover { border-color: var(--red); color: var(--red); }

        /* ── Info row ── */
        .info-row { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
        .info-row .info-chip { background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; font-size: 0.75rem; color: var(--muted); }
        .info-row .info-chip strong { color: var(--text); }

        /* ── File link ── */
        .file-link { display: inline-flex; align-items: center; gap: 8px; background: rgba(0,212,255,0.08); border: 1px solid rgba(0,212,255,0.25); border-radius: 10px; padding: 9px 16px; text-decoration: none; color: var(--blue); font-size: 0.82rem; font-weight: 600; transition: all 0.2s; }
        .file-link:hover { background: rgba(0,212,255,0.15); color: var(--blue); }

        /* ── Alert ── */
        .alert-success { background: rgba(107,203,119,0.12); border: 1px solid rgba(107,203,119,0.3); color: var(--green); border-radius: 10px; padding: 12px 18px; font-size: 0.85rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-error   { background: rgba(255,107,107,0.12); border: 1px solid rgba(255,107,107,0.3); color: var(--red);   border-radius: 10px; padding: 12px 18px; font-size: 0.85rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .table-card thead th:nth-child(3),
            .table-card tbody td:nth-child(3),
            .table-card thead th:nth-child(4),
            .table-card tbody td:nth-child(4) { display: none; }
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
        <a href="grade_submissions.php" class="active">
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
                <h6>Grade Submissions</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="view_assignments.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-clipboard2"></i> Assignments
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

        <!-- Heading -->
        <div class="d-flex align-items-start justify-content-between mb-4">
            <div>
                <h4 class="page-title"><i class="bi bi-patch-check me-2" style="color:var(--green)"></i>Grade Submissions</h4>
                <p class="page-subtitle">Review and grade student assignment submissions.</p>
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
                    <div class="stat-icon si-blue"><i class="bi bi-inbox-fill"></i></div>
                    <div class="stat-info">
                        <h3><?= $submissions_count ?></h3>
                        <p>Total Submissions</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-red"><i class="bi bi-hourglass-split"></i></div>
                    <div class="stat-info">
                        <h3><?= $pending_grading ?></h3>
                        <p>Pending Grading</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-green"><i class="bi bi-patch-check-fill"></i></div>
                    <div class="stat-info">
                        <h3><?= $graded_count ?></h3>
                        <p>Graded</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon si-yellow"><i class="bi bi-percent"></i></div>
                    <div class="stat-info">
                        <h3><?= $submissions_count > 0 ? round(($graded_count / $submissions_count) * 100) : 0 ?>%</h3>
                        <p>Graded Rate</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress bar -->
        <?php if ($submissions_count > 0): ?>
        <div class="mb-4" style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px 20px;">
            <div class="d-flex justify-content-between mb-2">
                <span style="font-size:0.8rem;font-weight:600;">Grading Progress</span>
                <span style="font-size:0.78rem;color:var(--muted);"><?= $graded_count ?> / <?= $submissions_count ?> graded</span>
            </div>
            <div class="progress-thin">
                <?php $pct = round(($graded_count / $submissions_count) * 100); ?>
                <div class="progress-fill"
                     style="width:<?= $pct ?>%;background:<?= $pct >= 80 ? 'var(--green)' : ($pct >= 40 ? 'var(--accent)' : 'var(--red)') ?>">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <form method="GET" action="">
            <div class="filter-bar mb-4">
                <!-- Status Tabs -->
                <div class="status-tabs">
                    <?php
                    $base = '?';
                    if ($filter_course) $base .= "course=$filter_course&";
                    if ($search)        $base .= "search=" . urlencode($search) . "&";
                    ?>
                    <a href="<?= $base ?>status=all"
                       class="status-tab <?= $filter_status === 'all'     ? 'active-tab' : '' ?>">All</a>
                    <a href="<?= $base ?>status=pending"
                       class="status-tab tab-pending <?= $filter_status === 'pending' ? 'active-tab' : '' ?>">
                        Pending
                        <?php if ($pending_grading > 0): ?>
                            <span class="badge-glass bg-red ms-1"><?= $pending_grading ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= $base ?>status=graded"
                       class="status-tab tab-graded <?= $filter_status === 'graded'  ? 'active-tab' : '' ?>">Graded</a>
                </div>

                <div class="ms-auto d-flex gap-2 flex-wrap align-items-center">
                    <!-- Course filter -->
                    <select name="course" class="form-control-custom" style="min-width:160px;">
                        <option value="">All Courses</option>
                        <?php
                        mysqli_data_seek($my_courses, 0);
                        while ($c = mysqli_fetch_assoc($my_courses)):
                        ?>
                            <option value="<?= $c['id'] ?>"
                                <?= $filter_course == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['course_code']) ?> – <?= htmlspecialchars($c['course_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>

                    <!-- Search -->
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="Search student or assignment…"
                           class="form-control-custom" style="min-width:220px;">

                    <button type="submit" class="filter-btn filter-btn-primary">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                    <a href="grade_submissions.php" class="filter-btn filter-btn-outline">Reset</a>
                </div>
            </div>
        </form>

        <!-- Table -->
        <div class="table-card mb-4">
            <?php if (mysqli_num_rows($submissions) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Assignment</th>
                            <th>Course</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $row_no = $offset + 1; while ($sub = mysqli_fetch_assoc($submissions)): ?>
                        <tr>
                            <td style="color:var(--muted);font-size:0.78rem;"><?= $row_no++ ?></td>
                            <td>
                                <div style="font-weight:600;font-size:0.84rem;">
                                    <?= htmlspecialchars($sub['student_name']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="max-width:180px;" class="text-truncate" title="<?= htmlspecialchars($sub['assignment_title']) ?>">
                                    <?= htmlspecialchars($sub['assignment_title']) ?>
                                </div>
                                <div style="font-size:0.72rem;color:var(--muted);">Max: <?= $sub['total_marks'] ?> marks</div>
                            </td>
                            <td>
                                <span class="badge-glass bg-blue"><?= htmlspecialchars($sub['course_code']) ?></span>
                            </td>
                            <td>
                                <div style="font-size:0.8rem;"><?= date('d M Y', strtotime($sub['submitted_at'])) ?></div>
                                <div style="font-size:0.72rem;color:var(--muted);"><?= date('H:i', strtotime($sub['submitted_at'])) ?></div>
                            </td>
                            <td>
                                <?php if ($sub['grade_id']): ?>
                                    <span class="badge-glass bg-green"><i class="bi bi-check-circle me-1"></i>Graded</span>
                                <?php else: ?>
                                    <span class="badge-glass bg-red"><i class="bi bi-hourglass me-1"></i>Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sub['grade_id']): ?>
                                    <?php
                                        $pct_score = ($sub['marks_obtained'] / $sub['total_marks']) * 100;
                                        $score_color = $pct_score >= 70 ? 'var(--green)' : ($pct_score >= 40 ? 'var(--accent)' : 'var(--red)');
                                    ?>
                                    <span style="font-weight:700;color:<?= $score_color ?>;">
                                        <?= $sub['marks_obtained'] ?><span style="color:var(--muted);font-weight:400;">/<?= $sub['total_marks'] ?></span>
                                    </span>
                                    <div style="font-size:0.7rem;color:var(--muted);"><?= round($pct_score) ?>%</div>
                                <?php else: ?>
                                    <span style="color:var(--muted);font-size:0.78rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button class="btn-grade"
                                            onclick="openGradeModal(
                                                <?= $sub['id'] ?>,
                                                '<?= addslashes(htmlspecialchars($sub['student_name'])) ?>',
                                                '<?= addslashes(htmlspecialchars($sub['assignment_title'])) ?>',
                                                <?= $sub['total_marks'] ?>,
                                                <?= $sub['grade_id'] ? $sub['marks_obtained'] : 'null' ?>,
                                                '<?= addslashes(htmlspecialchars($sub['feedback'] ?? '')) ?>',
                                                '<?= addslashes(htmlspecialchars($sub['file_path'] ?? '')) ?>'
                                            )">
                                        <i class="bi bi-pencil"></i>
                                        <?= $sub['grade_id'] ? 'Edit' : 'Grade' ?>
                                    </button>
                                    <?php if (!empty($sub['file_path'])): ?>
                                        <a href="../uploads/submissions/<?= htmlspecialchars($sub['file_path']) ?>"
                                           target="_blank" class="btn-view">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="pagination-bar">
                <p>Showing <?= min($offset + 1, $total_rows) ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?> submissions</p>
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
                    <i class="bi bi-inbox"></i>
                    <p>No submissions found<?= $filter_status !== 'all' || $search ? ' matching your filters' : ' yet' ?>.</p>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99;"></div>

<!-- ════════════════════════════════
     GRADE MODAL
════════════════════════════════ -->
<div class="modal-overlay" id="gradeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="bi bi-patch-check me-2" style="color:var(--accent)"></i>Grade Submission</h5>
            <button class="modal-close" onclick="closeModal()"><i class="bi bi-x"></i></button>
        </div>

        <form method="POST" action="">
            <div class="modal-body">
                <!-- Submission info -->
                <div class="info-row mb-3" id="modalInfo"></div>

                <!-- File link placeholder -->
                <div id="modalFileWrap" class="mb-3"></div>

                <!-- Marks -->
                <div class="mb-3">
                    <label class="form-label-custom">
                        Marks Obtained
                        <span id="modalMaxLabel" style="color:var(--muted);text-transform:none;font-weight:400;"></span>
                    </label>
                    <input type="number" name="marks_obtained" id="modalMarks"
                           class="form-control-custom" step="0.5" min="0"
                           placeholder="Enter marks…" required>
                    <!-- Mini progress -->
                    <div class="progress-thin mt-2">
                        <div class="progress-fill" id="marksProgress" style="width:0%;background:var(--red);transition:width 0.3s,background 0.3s;"></div>
                    </div>
                    <div style="font-size:0.72rem;color:var(--muted);margin-top:4px;" id="marksPercent"></div>
                </div>

                <!-- Feedback -->
                <div class="mb-2">
                    <label class="form-label-custom">Feedback (optional)</label>
                    <textarea name="feedback" id="modalFeedback" class="form-control-custom"
                              rows="4" placeholder="Write feedback for the student…"></textarea>
                </div>

                <input type="hidden" name="submission_id" id="modalSubId">
                <input type="hidden" name="grade_submission" value="1">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-save">
                    <i class="bi bi-check-lg me-1"></i>Save Grade
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── Sidebar toggle ──
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('overlay');
    const hamburger = document.getElementById('hamburger');
    hamburger.addEventListener('click', () => { sidebar.classList.add('open'); overlay.style.display = 'block'; });
    function closeSidebar() { sidebar.classList.remove('open'); overlay.style.display = 'none'; }

    // ── Grade Modal ──
    let maxMarks = 100;

    function openGradeModal(subId, studentName, assignTitle, totalMarks, currentMarks, feedback, filePath) {
        maxMarks = totalMarks;

        document.getElementById('modalSubId').value    = subId;
        document.getElementById('modalMarks').max      = totalMarks;
        document.getElementById('modalMarks').value    = currentMarks !== null ? currentMarks : '';
        document.getElementById('modalFeedback').value = feedback;
        document.getElementById('modalMaxLabel').textContent = '(max ' + totalMarks + ')';

        // Info chips
        document.getElementById('modalInfo').innerHTML =
            `<div class="info-chip"><strong>Student:</strong> ${studentName}</div>` +
            `<div class="info-chip"><strong>Assignment:</strong> ${assignTitle}</div>` +
            `<div class="info-chip"><strong>Total Marks:</strong> ${totalMarks}</div>`;

        // File link
        const fileWrap = document.getElementById('modalFileWrap');
        if (filePath && filePath.trim() !== '') {
            fileWrap.innerHTML =
                `<a href="../uploads/submissions/${filePath}" target="_blank" class="file-link">
                    <i class="bi bi-file-earmark-arrow-down"></i> View Submitted File
                 </a>`;
        } else {
            fileWrap.innerHTML = '';
        }

        // Update progress bar
        updateProgress();

        document.getElementById('gradeModal').classList.add('open');
        document.getElementById('modalMarks').focus();
    }

    function closeModal() {
        document.getElementById('gradeModal').classList.remove('open');
    }

    // Close modal on overlay click
    document.getElementById('gradeModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Live progress
    document.getElementById('modalMarks').addEventListener('input', updateProgress);
    function updateProgress() {
        const val = parseFloat(document.getElementById('modalMarks').value) || 0;
        const pct = maxMarks > 0 ? Math.min((val / maxMarks) * 100, 100) : 0;
        const bar = document.getElementById('marksProgress');
        bar.style.width = pct + '%';
        bar.style.background = pct >= 70 ? 'var(--green)' : (pct >= 40 ? 'var(--accent)' : 'var(--red)');
        document.getElementById('marksPercent').textContent = val > 0 ? Math.round(pct) + '% score' : '';
    }

    // ── Auto-open modal if ?id= supplied ──
    <?php if ($view_submission): ?>
    openGradeModal(
        <?= $view_submission['id'] ?>,
        '<?= addslashes(htmlspecialchars($view_submission['student_name'])) ?>',
        '<?= addslashes(htmlspecialchars($view_submission['assignment_title'])) ?>',
        <?= $view_submission['total_marks'] ?>,
        <?= $view_submission['marks_obtained'] !== null ? $view_submission['marks_obtained'] : 'null' ?>,
        '<?= addslashes(htmlspecialchars($view_submission['feedback'] ?? '')) ?>',
        '<?= addslashes(htmlspecialchars($view_submission['file_path'] ?? '')) ?>'
    );
    <?php endif; ?>
</script>
</body>
</html>