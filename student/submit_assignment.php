<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('student');

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];

// ── Upload configuration ──────────────────────────────────
// NOTE: assumes the `submissions` table stores the uploaded filename in a
// `file_name` column and files live in ../uploads/submissions/. Adjust below
// if your schema differs.
$upload_dir   = '../uploads/submissions/';
$allowed_ext  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip', 'rar', 'txt'];
$max_size     = 10 * 1024 * 1024; // 10 MB

if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

// ══════════════════════════════════════════════════════════
//  VIEW MY OWN SUBMITTED FILE (must run before any HTML output)
// ══════════════════════════════════════════════════════════
if (isset($_GET['view_submission'])) {
    $sub_id = (int) $_GET['view_submission'];

    $sub_check = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM submissions WHERE id = '$sub_id' AND student_id = '$student_id' LIMIT 1"
    ));

    if ($sub_check && !empty($sub_check['file_name'])) {
        $file_path = $upload_dir . $sub_check['file_name'];
        if (file_exists($file_path)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($sub_check['file_name']) . '"');
            header('Content-Length: ' . filesize($file_path));
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            flush();
            readfile($file_path);
            exit;
        }
    }

    header('Location: submit_assignment.php?error=file_missing');
    exit;
}

// ── Detect whether submissions has an optional comments column ──
$has_comments_col = mysqli_num_rows(
    mysqli_query($conn, "SHOW COLUMNS FROM submissions LIKE 'comments'")
) > 0;

// ── Determine mode: single-assignment (id given) vs picker list ──
$assignment_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$assignment    = null;
$existing_submission = null;
$existing_grade      = null;
$errors  = [];
$success = isset($_GET['success']) && $_GET['success'] == '1';

if ($assignment_id > 0) {
    $assignment = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT a.*, c.course_name, c.course_code
         FROM assignments a
         INNER JOIN courses c ON a.course_id = c.id
         INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
         WHERE a.id = '$assignment_id'
         AND ce.student_id = '$student_id'
         AND ce.status = 'enrolled'
         AND a.status = 'active'
         LIMIT 1"
    ));

    if ($assignment) {
        $existing_submission = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM submissions
             WHERE assignment_id = '$assignment_id' AND student_id = '$student_id'
             LIMIT 1"
        ));

        $existing_grade = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM grades
             WHERE assignment_id = '$assignment_id' AND student_id = '$student_id'
             LIMIT 1"
        ));

        // ── Handle the upload (only if not already graded) ──────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit' && !$existing_grade) {

            if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Please choose a file to upload.';
            } elseif ($_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'There was a problem uploading your file. Please try again.';
            } else {
                $orig_name = $_FILES['submission_file']['name'];
                $tmp_path  = $_FILES['submission_file']['tmp_name'];
                $size      = $_FILES['submission_file']['size'];
                $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed_ext)) {
                    $errors[] = 'File type not allowed. Accepted formats: ' . strtoupper(implode(', ', $allowed_ext)) . '.';
                } elseif ($size > $max_size) {
                    $errors[] = 'File is too large. Maximum size is 10 MB.';
                }
            }

            if (empty($errors)) {
                $safe_filename = 'sub_' . $student_id . '_' . $assignment_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest_path     = $upload_dir . $safe_filename;

                if (move_uploaded_file($tmp_path, $dest_path)) {
                    $comments       = $has_comments_col ? trim($_POST['comments'] ?? '') : '';
                    $comments_safe  = mysqli_real_escape_string($conn, $comments);
                    $filename_safe  = mysqli_real_escape_string($conn, $safe_filename);

                    if ($existing_submission) {
                        $old_file = $upload_dir . $existing_submission['file_name'];
                        $sql = "UPDATE submissions
                                SET file_name = '$filename_safe', created_at = NOW()"
                             . ($has_comments_col ? ", comments = '$comments_safe'" : '')
                             . " WHERE id = '" . $existing_submission['id'] . "'";
                        mysqli_query($conn, $sql);

                        if ($old_file !== $dest_path && file_exists($old_file)) {
                            @unlink($old_file);
                        }
                    } else {
                        $cols = "assignment_id, student_id, file_name, created_at" . ($has_comments_col ? ", comments" : '');
                        $vals = "'$assignment_id', '$student_id', '$filename_safe', NOW()" . ($has_comments_col ? ", '$comments_safe'" : '');
                        mysqli_query($conn, "INSERT INTO submissions ($cols) VALUES ($vals)");
                    }

                    header("Location: submit_assignment.php?id=$assignment_id&success=1");
                    exit;
                } else {
                    $errors[] = 'Could not save the uploaded file. Please try again.';
                }
            }
        }
    }
}

// ── Sidebar counts (shared across pages) ──────────────────
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

// ── Fetch student info ───────────────────────────────────
$student_query = mysqli_query($conn, "SELECT * FROM users WHERE id = '$student_id'");
$student       = mysqli_fetch_assoc($student_query);

// ── List mode data (no id selected) ───────────────────────
$ready_to_submit = null;
$awaiting_grade  = null;
if ($assignment_id === 0) {
    $ready_to_submit = mysqli_query($conn,
        "SELECT a.*, c.course_name, c.course_code,
                TIMESTAMPDIFF(HOUR, NOW(), a.due_date) AS hours_left
         FROM assignments a
         INNER JOIN courses c ON a.course_id = c.id
         INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
         LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = '$student_id'
         WHERE ce.student_id = '$student_id'
         AND ce.status = 'enrolled'
         AND a.status = 'active'
         AND s.id IS NULL
         ORDER BY a.due_date ASC"
    );

    $awaiting_grade = mysqli_query($conn,
        "SELECT a.*, c.course_name, c.course_code, s.id AS submission_id,
                s.file_name, s.created_at AS submission_date
         FROM assignments a
         INNER JOIN courses c ON a.course_id = c.id
         INNER JOIN course_enrollments ce ON a.course_id = ce.course_id
         INNER JOIN submissions s ON a.id = s.assignment_id AND s.student_id = '$student_id'
         LEFT JOIN grades g ON a.id = g.assignment_id AND g.student_id = '$student_id'
         WHERE ce.student_id = '$student_id'
         AND ce.status = 'enrolled'
         AND a.status = 'active'
         AND g.id IS NULL
         ORDER BY s.created_at DESC"
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Assignment - OnlineLMS</title>
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

        .page-header {
            margin-bottom: 22px;
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

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.85rem;
            margin-bottom: 16px;
            transition: color 0.2s;
        }

        .back-link:hover { color: var(--accent); }

        /* ── Alerts ── */
        .alert-glass {
            border-radius: 12px;
            padding: 14px 18px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-glass.success {
            background: rgba(107,203,119,0.1);
            border: 1px solid rgba(107,203,119,0.3);
            color: var(--green);
        }

        .alert-glass.error {
            background: rgba(255,107,107,0.1);
            border: 1px solid rgba(255,107,107,0.3);
            color: var(--red);
        }

        .alert-glass.warning {
            background: rgba(255,217,61,0.1);
            border: 1px solid rgba(255,217,61,0.3);
            color: var(--yellow);
        }

        .alert-glass.info {
            background: rgba(0,212,255,0.1);
            border: 1px solid rgba(0,212,255,0.3);
            color: var(--accent);
        }

        .alert-glass ul { margin: 0; padding-left: 18px; }

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .section-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-header h6 {
            font-weight: 700;
            font-size: 0.95rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Picker Rows ── */
        .pick-row {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: background 0.2s;
            flex-wrap: wrap;
        }

        .pick-row:last-child { border-bottom: none; }
        .pick-row:hover { background: var(--bg-hover); }

        .item-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .item-icon.pending   { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .item-icon.overdue   { background: rgba(255,107,107,0.15); color: var(--red);    }
        .item-icon.submitted { background: rgba(0,212,255,0.15);   color: var(--accent); }

        .pick-info {
            flex: 1;
            min-width: 200px;
        }

        .pick-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .pick-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.78rem;
            color: var(--muted);
        }

        .pick-meta i { margin-right: 3px; }

        .badge-glass {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .badge-blue   { background: rgba(0,212,255,0.15);  color: var(--accent); border: 1px solid rgba(0,212,255,0.3);  }
        .badge-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);  }
        .badge-red    { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3);  }

        .btn-pick {
            margin-left: auto;
            background: rgba(255,217,61,0.12);
            color: var(--yellow);
            border: 1px solid rgba(255,217,61,0.3);
            border-radius: 10px;
            padding: 8px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .btn-pick:hover { background: rgba(255,217,61,0.22); color: var(--yellow); }

        .btn-pick.view {
            background: rgba(0,212,255,0.12);
            color: var(--accent);
            border-color: rgba(0,212,255,0.3);
        }

        .btn-pick.view:hover { background: rgba(0,212,255,0.22); color: var(--accent); }

        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 2.2rem;
            margin-bottom: 10px;
            opacity: 0.4;
            display: block;
        }

        .empty-state p { font-size: 0.85rem; margin: 0; }

        /* ── Submission Card (single assignment) ── */
        .submission-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 28px;
            max-width: 700px;
        }

        .sub-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .sub-title {
            font-weight: 800;
            font-size: 1.2rem;
            margin-bottom: 6px;
        }

        .sub-meta {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 0.82rem;
            color: var(--muted);
            margin-bottom: 16px;
        }

        .sub-meta i { margin-right: 4px; }

        .sub-desc {
            font-size: 0.875rem;
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .info-box {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .info-box .item-icon { flex-shrink: 0; }

        .info-box .info-title {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .info-box .info-sub {
            font-size: 0.78rem;
            color: var(--muted);
        }

        .info-box a.download-link {
            margin-left: auto;
            color: var(--accent);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .info-box a.download-link:hover { text-decoration: underline; }

        .grade-box {
            background: rgba(107,203,119,0.08);
            border: 1px solid rgba(107,203,119,0.25);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        .grade-box .score {
            font-size: 2rem;
            font-weight: 800;
            color: var(--green);
            line-height: 1;
            margin-bottom: 4px;
        }

        .grade-box .label {
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 14px;
        }

        .grade-box .feedback {
            background: rgba(255,255,255,0.04);
            border-radius: 10px;
            padding: 14px 16px;
            text-align: left;
            font-size: 0.85rem;
            color: var(--text);
            margin-top: 10px;
        }

        .grade-box .feedback strong {
            display: block;
            font-size: 0.75rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
        }

        /* ── Dropzone ── */
        .dropzone {
            border: 2px dashed var(--border);
            border-radius: 14px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 16px;
        }

        .dropzone:hover,
        .dropzone.has-file {
            border-color: var(--accent);
            background: rgba(0,212,255,0.05);
        }

        .dropzone i {
            font-size: 2rem;
            color: var(--accent);
            margin-bottom: 8px;
            display: block;
        }

        .dropzone .dz-text {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .dropzone .dz-sub {
            font-size: 0.75rem;
            color: var(--muted);
        }

        .dropzone input[type="file"] { display: none; }

        .form-label-glass {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 8px;
            display: block;
        }

        .form-control-glass {
            width: 100%;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            color: var(--text);
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
            resize: vertical;
            font-family: inherit;
        }

        .form-control-glass:focus { border-color: var(--accent); }

        .btn-submit-form {
            background: linear-gradient(135deg, var(--accent), #0099cc);
            color: #06131a;
            border: none;
            border-radius: 10px;
            padding: 12px 24px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s;
            margin-top: 6px;
        }

        .btn-submit-form:hover { opacity: 0.88; }

        .btn-back-flat {
            background: rgba(255,255,255,0.05);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .submission-card { padding: 20px; }
            .btn-pick { margin-left: 0; width: 100%; text-align: center; }
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
        <a href="submit_assignment.php" class="active">
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
                <h6>Submit Assignment</h6>
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

        <?php if (isset($_GET['error']) && $_GET['error'] === 'file_missing'): ?>
            <div class="alert-glass error">
                <i class="bi bi-exclamation-triangle"></i>
                That file couldn't be found.
            </div>
        <?php endif; ?>

        <?php if ($assignment_id === 0): ?>
            <!-- ══════════════════════════════════════
                 PICKER MODE — choose an assignment
            ══════════════════════════════════════ -->
            <div class="page-header">
                <h4>Submit Assignment</h4>
                <p>Choose an assignment below to upload your work.</p>
            </div>

            <div class="section-card mb-4">
                <div class="section-header">
                    <h6><i class="bi bi-clipboard2" style="color:var(--yellow)"></i> Ready to Submit</h6>
                </div>
                <?php if (mysqli_num_rows($ready_to_submit) > 0): ?>
                    <?php while ($a = mysqli_fetch_assoc($ready_to_submit)): ?>
                        <?php $is_overdue = $a['hours_left'] < 0; ?>
                        <div class="pick-row">
                            <div class="item-icon <?= $is_overdue ? 'overdue' : 'pending' ?>">
                                <i class="bi <?= $is_overdue ? 'bi-exclamation-circle' : 'bi-clipboard2' ?>"></i>
                            </div>
                            <div class="pick-info">
                                <div class="pick-title"><?= htmlspecialchars($a['title']) ?></div>
                                <div class="pick-meta">
                                    <span class="badge-glass badge-blue"><?= htmlspecialchars($a['course_code']) ?></span>
                                    <span><i class="bi bi-bullseye"></i><?= $a['total_marks'] ?> marks</span>
                                    <span><i class="bi bi-calendar-event"></i>Due <?= date('d M Y, h:i A', strtotime($a['due_date'])) ?></span>
                                    <?php if ($is_overdue): ?>
                                        <span class="badge-glass badge-red">Overdue</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="submit_assignment.php?id=<?= $a['id'] ?>" class="btn-pick">
                                <i class="bi bi-upload me-1"></i>Submit
                            </a>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-check2-circle"></i>
                        <p>You're all caught up — nothing pending submission.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="section-card">
                <div class="section-header">
                    <h6><i class="bi bi-hourglass-split" style="color:var(--accent)"></i> Awaiting Grade</h6>
                </div>
                <?php if (mysqli_num_rows($awaiting_grade) > 0): ?>
                    <?php while ($a = mysqli_fetch_assoc($awaiting_grade)): ?>
                        <div class="pick-row">
                            <div class="item-icon submitted">
                                <i class="bi bi-check2-circle"></i>
                            </div>
                            <div class="pick-info">
                                <div class="pick-title"><?= htmlspecialchars($a['title']) ?></div>
                                <div class="pick-meta">
                                    <span class="badge-glass badge-blue"><?= htmlspecialchars($a['course_code']) ?></span>
                                    <span><i class="bi bi-upload"></i>Submitted <?= date('d M Y', strtotime($a['submission_date'])) ?></span>
                                </div>
                            </div>
                            <a href="submit_assignment.php?id=<?= $a['id'] ?>" class="btn-pick view">
                                <i class="bi bi-arrow-repeat me-1"></i>Resubmit
                            </a>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-hourglass"></i>
                        <p>Nothing awaiting grading right now.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif (!$assignment): ?>
            <!-- ══════════════════════════════════════
                 INVALID / INACCESSIBLE ASSIGNMENT
            ══════════════════════════════════════ -->
            <a href="assignments.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Assignments</a>
            <div class="alert-glass error">
                <i class="bi bi-exclamation-triangle"></i>
                This assignment doesn't exist or you don't have access to it.
            </div>

        <?php else: ?>
            <!-- ══════════════════════════════════════
                 SINGLE ASSIGNMENT — submission form
            ══════════════════════════════════════ -->
            <a href="submit_assignment.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Submit Assignment</a>

            <?php if ($success): ?>
                <div class="alert-glass success">
                    <i class="bi bi-check-circle"></i>
                    Your assignment was submitted successfully.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert-glass error">
                    <i class="bi bi-exclamation-triangle"></i>
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="submission-card">
                <div class="sub-top">
                    <div>
                        <span class="badge-glass badge-blue mb-2 d-inline-block"><?= htmlspecialchars($assignment['course_code']) ?></span>
                        <div class="sub-title"><?= htmlspecialchars($assignment['title']) ?></div>
                    </div>
                </div>

                <div class="sub-meta">
                    <span><i class="bi bi-bullseye"></i><?= $assignment['total_marks'] ?> marks</span>
                    <span><i class="bi bi-calendar-event"></i>Due <?= date('d M Y, h:i A', strtotime($assignment['due_date'])) ?></span>
                    <span><i class="bi bi-book"></i><?= htmlspecialchars($assignment['course_name']) ?></span>
                </div>

                <?php if (!empty($assignment['description'])): ?>
                    <div class="sub-desc"><?= nl2br(htmlspecialchars($assignment['description'])) ?></div>
                <?php endif; ?>

                <?php if ($existing_grade): ?>
                    <!-- Already graded — read only -->
                    <div class="grade-box">
                        <?php $pct = $existing_grade['total_marks'] > 0 ? round(($existing_grade['marks_obtained'] / $existing_grade['total_marks']) * 100, 1) : null; ?>
                        <div class="score"><?= $existing_grade['marks_obtained'] ?>/<?= $existing_grade['total_marks'] ?></div>
                        <div class="label"><?= $pct !== null ? $pct . '% — ' : '' ?>This assignment has been graded</div>
                        <?php if (!empty($existing_grade['feedback'])): ?>
                            <div class="feedback">
                                <strong>Lecturer Feedback</strong>
                                <?= nl2br(htmlspecialchars($existing_grade['feedback'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <?php if (strtotime($assignment['due_date']) < time()): ?>
                        <div class="alert-glass warning">
                            <i class="bi bi-clock-history"></i>
                            This assignment is past its due date. You can still submit, but it may be marked as late.
                        </div>
                    <?php endif; ?>

                    <?php if ($existing_submission): ?>
                        <div class="info-box">
                            <div class="item-icon submitted"><i class="bi bi-file-earmark-check"></i></div>
                            <div>
                                <div class="info-title">Current submission</div>
                                <div class="info-sub">Submitted <?= date('d M Y, h:i A', strtotime($existing_submission['created_at'])) ?></div>
                            </div>
                            <a href="submit_assignment.php?view_submission=<?= $existing_submission['id'] ?>" class="download-link">
                                <i class="bi bi-download me-1"></i>Download
                            </a>
                        </div>
                        <p class="mb-3" style="font-size:0.8rem; color:var(--muted);">
                            Uploading a new file below will replace your current submission.
                        </p>
                    <?php endif; ?>

                    <form action="submit_assignment.php?id=<?= $assignment['id'] ?>" method="POST" enctype="multipart/form-data" id="submitForm">
                        <input type="hidden" name="action" value="submit">

                        <label class="dropzone" id="dropzone">
                            <i class="bi bi-cloud-arrow-up"></i>
                            <div class="dz-text" id="dzText">Click to choose a file, or drag it here</div>
                            <div class="dz-sub">PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, JPG, PNG, ZIP, RAR, TXT — max 10MB</div>
                            <input type="file" name="submission_file" id="fileInput" required>
                        </label>

                        <?php if ($has_comments_col): ?>
                            <label class="form-label-glass">Comments (optional)</label>
                            <textarea name="comments" class="form-control-glass mb-3" rows="3"
                                      placeholder="Add any notes for your lecturer..."></textarea>
                        <?php endif; ?>

                        <button type="submit" class="btn-submit-form">
                            <i class="bi bi-upload me-1"></i>
                            <?= $existing_submission ? 'Resubmit Assignment' : 'Submit Assignment' ?>
                        </button>
                    </form>

                <?php endif; ?>
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

    // ── Dropzone file picker feedback ──────────────────────
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    const dzText = document.getElementById('dzText');

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                dzText.textContent = fileInput.files[0].name;
                dropzone.classList.add('has-file');
            } else {
                dzText.textContent = 'Click to choose a file, or drag it here';
                dropzone.classList.remove('has-file');
            }
        });

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('has-file');
        });

        dropzone.addEventListener('dragleave', () => {
            if (fileInput.files.length === 0) dropzone.classList.remove('has-file');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                dzText.textContent = e.dataTransfer.files[0].name;
                dropzone.classList.add('has-file');
            }
        });
    }
</script>
</body>
</html>