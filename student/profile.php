<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('student');

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];

$success_msg = '';
$error_msg   = '';

// ── Handle profile update ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name  = trim(mysqli_real_escape_string($conn, $_POST['name']));
    $email = trim(mysqli_real_escape_string($conn, $_POST['email']));
    $phone = trim(mysqli_real_escape_string($conn, $_POST['phone']));
    $bio   = trim(mysqli_real_escape_string($conn, $_POST['bio']));

    // Validate email uniqueness
    $email_check = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM users WHERE email = '$email' AND id != '$student_id'"
    ));

    if (empty($name)) {
        $error_msg = 'Full name is required.';
    } elseif ($email_check) {
        $error_msg = 'That email address is already in use by another account.';
    } else {
        // Handle avatar upload
        $profile_pic_sql = '';
        if (!empty($_FILES['profile_picture']['name'])) {
            $file     = $_FILES['profile_picture'];
            $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
            $max_size = 2 * 1024 * 1024; // 2 MB

            if (!in_array($file['type'], $allowed)) {
                $error_msg = 'Avatar must be a JPG, PNG, GIF, or WebP image.';
            } elseif ($file['size'] > $max_size) {
                $error_msg = 'Avatar file size must be under 2 MB.';
            } else {
                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'student_' . $student_id . '_' . time() . '.' . $ext;
                $dest     = '../uploads/profiles/' . $filename;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $profile_pic_sql = ", profile_picture = '$filename'";
                } else {
                    $error_msg = 'Failed to save the uploaded image. Check folder permissions.';
                }
            }
        }

        if (empty($error_msg)) {
            mysqli_query($conn,
                "UPDATE users SET name='$name', email='$email', phone='$phone', bio='$bio'
                 $profile_pic_sql WHERE id='$student_id'"
            );
            $_SESSION['user_name'] = $name;
            $student_name = $name;
            $success_msg  = 'Profile updated successfully.';
        }
    }
}

// ── Handle password change ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm  = $_POST['confirm_password'];

    $user_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT password FROM users WHERE id = '$student_id'"
    ));

    if (!password_verify($current, $user_row['password'])) {
        $error_msg = 'Current password is incorrect.';
    } elseif (strlen($new_pass) < 8) {
        $error_msg = 'New password must be at least 8 characters.';
    } elseif ($new_pass !== $confirm) {
        $error_msg = 'New passwords do not match.';
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE id='$student_id'");
        $success_msg = 'Password changed successfully.';
    }
}

// ── Re-fetch student (after possible update) ─────────────
$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM users WHERE id = '$student_id'"
));

// ── Sidebar counts ───────────────────────────────────────
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
$notifications_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications n
                          LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = '$student_id'
                          WHERE (n.target_role = 'student' OR n.target_role = 'all') AND nr.id IS NULL")
)['total'];

// ── Activity stats ───────────────────────────────────────
$submitted_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM submissions WHERE student_id = '$student_id'")
)['total'];
$graded_count = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM grades WHERE student_id = '$student_id'")
)['total'];
$avg_grade = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT AVG((marks_obtained/total_marks)*100) as avg FROM grades WHERE student_id = '$student_id'")
)['avg'];
$avg_grade = $avg_grade ? round($avg_grade, 1) : 0;

// ── Enrolled courses (for profile display) ───────────────
$enrolled_courses = mysqli_query($conn,
    "SELECT c.course_name, c.course_code, u.name AS lecturer_name,
            ce.enrolled_at
     FROM course_enrollments ce
     INNER JOIN courses c ON ce.course_id = c.id
     INNER JOIN users u ON c.lecturer_id = u.id
     WHERE ce.student_id = '$student_id' AND ce.status = 'enrolled'
     ORDER BY ce.enrolled_at DESC"
);

// ── Recent submissions ───────────────────────────────────
$recent_submissions = mysqli_query($conn,
    "SELECT s.*, a.title AS assignment_title, c.course_code,
            g.marks_obtained, g.total_marks, g.feedback
     FROM submissions s
     INNER JOIN assignments a ON s.assignment_id = a.id
     INNER JOIN courses c ON a.course_id = c.id
     LEFT JOIN grades g ON g.student_id = s.student_id AND g.assignment_id = s.assignment_id
     WHERE s.student_id = '$student_id'
     ORDER BY s.submitted_at DESC
     LIMIT 5"
);

$member_since = date('F Y', strtotime($student['created_at'] ?? 'now'));
$active_tab   = $_GET['tab'] ?? 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - OnlineLMS</title>
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

        /* ── Sidebar ── */
        .sidebar {
            width: var(--sidebar-w);
            background: rgba(255,255,255,0.03);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; height: 100vh;
            z-index: 100; transition: transform 0.3s;
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
        .sidebar-profile .name { font-weight: 600; font-size: 0.9rem; }
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
            padding: 11px 20px; color: var(--muted); text-decoration: none;
            font-size: 0.9rem; font-weight: 500;
            border-left: 3px solid transparent; transition: all 0.2s;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active {
            color: var(--text); background: var(--bg-hover); border-left-color: var(--accent);
        }
        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1.05rem; width: 20px; }
        .nav-badge {
            margin-left: auto; background: var(--red); color: #fff;
            border-radius: 20px; padding: 1px 8px; font-size: 0.7rem; font-weight: 700;
        }
        .sidebar-footer { padding: 15px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a {
            display: flex; align-items: center; gap: 10px; color: var(--red);
            text-decoration: none; font-size: 0.875rem; font-weight: 500;
            padding: 8px 0; transition: opacity 0.2s;
        }
        .sidebar-footer a:hover { opacity: 0.75; }

        /* ── Main ── */
        .main-content { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

        /* ── Topbar ── */
        .topbar {
            background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border);
            padding: 14px 28px; display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 50; backdrop-filter: blur(10px);
        }
        .topbar-left h6 { font-weight: 700; font-size: 1.05rem; margin: 0; }
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

        /* ── Page ── */
        .page-body { padding: 28px; flex: 1; }

        /* ── Profile Hero ── */
        .profile-hero {
            background: linear-gradient(135deg, rgba(0,212,255,0.1), rgba(180,143,252,0.06));
            border: 1px solid rgba(0,212,255,0.15);
            border-radius: 20px;
            padding: 32px;
            display: flex;
            align-items: center;
            gap: 28px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .profile-hero::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(0,212,255,0.08), transparent 70%);
            pointer-events: none;
        }
        .hero-avatar-wrap { position: relative; flex-shrink: 0; }
        .hero-avatar {
            width: 96px; height: 96px; border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
            box-shadow: 0 0 0 6px rgba(0,212,255,0.1);
        }
        .avatar-edit-btn {
            position: absolute; bottom: 2px; right: 2px;
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--accent); color: #0f0f1a;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; cursor: pointer;
            border: 2px solid var(--bg-main); transition: transform 0.2s;
        }
        .avatar-edit-btn:hover { transform: scale(1.1); }
        .hero-info { flex: 1; min-width: 0; }
        .hero-name { font-size: 1.6rem; font-weight: 800; margin-bottom: 4px; }
        .hero-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .hero-meta span { color: var(--muted); font-size: 0.82rem; display: flex; align-items: center; gap: 5px; }
        .hero-bio { color: var(--muted); font-size: 0.875rem; line-height: 1.5; margin-bottom: 14px; max-width: 540px; }
        .hero-stats { display: flex; gap: 20px; flex-wrap: wrap; }
        .hero-stat { text-align: center; }
        .hero-stat-num { font-size: 1.4rem; font-weight: 800; color: var(--accent); line-height: 1; }
        .hero-stat-lbl { font-size: 0.7rem; color: var(--muted); margin-top: 2px; }

        /* ── Tabs ── */
        .profile-tabs {
            display: flex; gap: 4px; margin-bottom: 24px;
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 12px; padding: 5px;
        }
        .profile-tab {
            padding: 9px 20px; border-radius: 9px; font-size: 0.85rem; font-weight: 600;
            text-decoration: none; color: var(--muted); transition: all 0.2s;
            display: flex; align-items: center; gap: 7px;
        }
        .profile-tab:hover { color: var(--text); }
        .profile-tab.active {
            background: rgba(0,212,255,0.15); color: var(--accent);
            border: 1px solid rgba(0,212,255,0.25);
        }

        /* ── Cards ── */
        .profile-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden; margin-bottom: 20px;
        }
        .card-header-bar {
            padding: 18px 24px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-header-bar h6 {
            font-weight: 700; font-size: 0.95rem; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .card-body-pad { padding: 24px; }

        /* ── Form Elements ── */
        .form-label-custom {
            display: block; font-size: 0.78rem; font-weight: 600;
            color: var(--muted); text-transform: uppercase;
            letter-spacing: 0.7px; margin-bottom: 7px;
        }
        .form-input {
            width: 100%; background: rgba(255,255,255,0.05);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 11px 14px; color: var(--text); font-size: 0.875rem;
            transition: border-color 0.2s; outline: none;
            font-family: inherit;
        }
        .form-input:focus { border-color: var(--accent); background: rgba(0,212,255,0.04); }
        .form-input::placeholder { color: var(--muted); }
        textarea.form-input { resize: vertical; min-height: 90px; }

        .form-hint { font-size: 0.72rem; color: var(--muted); margin-top: 5px; }

        /* ── Password strength ── */
        .strength-bar {
            height: 4px; border-radius: 2px;
            background: var(--border); margin-top: 6px; overflow: hidden;
        }
        .strength-fill { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; width: 0; }

        /* ── Buttons ── */
        .btn-save {
            background: linear-gradient(135deg, var(--accent), #0099cc);
            color: #0f0f1a; border: none; border-radius: 10px;
            padding: 10px 24px; font-size: 0.875rem; font-weight: 700;
            cursor: pointer; transition: opacity 0.2s; display: inline-flex;
            align-items: center; gap: 7px;
        }
        .btn-save:hover { opacity: 0.88; }
        .btn-secondary {
            background: var(--bg-hover); color: var(--muted);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 10px 20px; font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s; display: inline-flex;
            align-items: center; gap: 7px; text-decoration: none;
        }
        .btn-secondary:hover { border-color: rgba(255,255,255,0.2); color: var(--text); }

        /* ── Alert ── */
        .alert-custom {
            border-radius: 12px; padding: 13px 18px; margin-bottom: 20px;
            font-size: 0.875rem; font-weight: 500;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: rgba(107,203,119,0.12); border: 1px solid rgba(107,203,119,0.3); color: var(--green); }
        .alert-error   { background: rgba(255,107,107,0.12); border: 1px solid rgba(255,107,107,0.3); color: var(--red); }

        /* ── Avatar preview ── */
        .avatar-preview-wrap { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; }
        .avatar-preview {
            width: 64px; height: 64px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--accent);
        }
        .avatar-upload-btn {
            background: var(--bg-hover); border: 1px dashed rgba(0,212,255,0.4);
            border-radius: 10px; padding: 10px 18px; color: var(--accent);
            font-size: 0.82rem; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;
        }
        .avatar-upload-btn:hover { background: rgba(0,212,255,0.08); }
        #profile_picture { display: none; }

        /* ── Course list ── */
        .course-row {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 24px; border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }
        .course-row:last-child { border-bottom: none; }
        .course-row:hover { background: var(--bg-hover); }
        .course-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(180,143,252,0.15); color: var(--purple);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .course-code-pill {
            background: rgba(0,212,255,0.12); color: var(--accent);
            border-radius: 6px; padding: 2px 9px;
            font-size: 0.72rem; font-weight: 700; display: inline-block;
        }
        .course-title { font-weight: 600; font-size: 0.875rem; }
        .course-lecturer { font-size: 0.75rem; color: var(--muted); }

        /* ── Submissions ── */
        .submission-row {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 24px; border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }
        .submission-row:last-child { border-bottom: none; }
        .submission-row:hover { background: var(--bg-hover); }
        .sub-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(255,217,61,0.12); color: var(--yellow);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .sub-title { font-weight: 600; font-size: 0.875rem; }
        .sub-meta  { font-size: 0.75rem; color: var(--muted); }
        .grade-chip {
            margin-left: auto; padding: 4px 12px; border-radius: 20px;
            font-size: 0.75rem; font-weight: 700; white-space: nowrap;
        }
        .grade-high   { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .grade-mid    { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);  }
        .grade-low    { background: rgba(255,107,107,0.15); color: var(--red);    border: 1px solid rgba(255,107,107,0.3); }
        .grade-none   { background: rgba(255,255,255,0.06); color: var(--muted);  border: 1px solid var(--border); }

        /* ── Empty ── */
        .empty-state { padding: 48px 20px; text-align: center; color: var(--muted); }
        .empty-state i { font-size: 2.2rem; opacity: 0.3; display: block; margin-bottom: 10px; }
        .empty-state p { font-size: 0.85rem; margin: 0; }

        /* ── Divider ── */
        .form-divider { border: none; border-top: 1px solid var(--border); margin: 22px 0; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .profile-hero { flex-direction: column; align-items: flex-start; padding: 22px; }
            .hero-name { font-size: 1.3rem; }
            .profile-tabs { flex-wrap: wrap; }
            .profile-tab { flex: 1; justify-content: center; }
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
        <a href="dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
        <a href="courses.php">
            <i class="bi bi-book"></i> My Courses
            <?php if ($courses_count > 0): ?><span class="nav-badge"><?= $courses_count ?></span><?php endif; ?>
        </a>
        <div class="nav-section">Learning</div>
        <a href="notes.php">
            <i class="bi bi-file-earmark-text"></i> Notes & Resources
            <?php if ($notes_count > 0): ?><span class="nav-badge" style="background:var(--accent)"><?= $notes_count ?></span><?php endif; ?>
        </a>
        <a href="assignments.php">
            <i class="bi bi-clipboard2-check"></i> Assignments
            <?php if ($assignments_count > 0): ?><span class="nav-badge"><?= $assignments_count ?></span><?php endif; ?>
        </a>
        <a href="submit_assignment.php"><i class="bi bi-upload"></i> Submit Assignment</a>
        <div class="nav-section">Communication</div>
        <a href="notifications.php">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($notifications_count > 0): ?><span class="nav-badge"><?= $notifications_count ?></span><?php endif; ?>
        </a>
        <div class="nav-section">Account</div>
        <a href="profile.php" class="active"><i class="bi bi-person-circle"></i> My Profile</a>
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
            <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
            <div class="topbar-left">
                <h6>My Profile</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($notifications_count > 0): ?><span class="notif-dot"></span><?php endif; ?>
            </a>
            <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture']) ?>"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=36'"
                 style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Flash Messages -->
        <?php if ($success_msg): ?>
            <div class="alert-custom alert-success">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert-custom alert-error">
                <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Profile Hero -->
        <div class="profile-hero">
            <div class="hero-avatar-wrap">
                <img class="hero-avatar" id="heroAvatar"
                     src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture']) ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=96'"
                     alt="Avatar">
                <label for="profile_picture" class="avatar-edit-btn" title="Change photo">
                    <i class="bi bi-camera-fill"></i>
                </label>
            </div>
            <div class="hero-info">
                <div class="hero-name"><?= htmlspecialchars($student['name']) ?></div>
                <div class="hero-meta">
                    <span><i class="bi bi-envelope"></i><?= htmlspecialchars($student['email']) ?></span>
                    <?php if (!empty($student['phone'])): ?>
                        <span><i class="bi bi-telephone"></i><?= htmlspecialchars($student['phone']) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-calendar3"></i>Member since <?= $member_since ?></span>
                </div>
                <?php if (!empty($student['bio'])): ?>
                    <div class="hero-bio"><?= nl2br(htmlspecialchars($student['bio'])) ?></div>
                <?php else: ?>
                    <div class="hero-bio" style="font-style:italic;">No bio yet — add one below.</div>
                <?php endif; ?>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <div class="hero-stat-num"><?= $courses_count ?></div>
                        <div class="hero-stat-lbl">Courses</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-num"><?= $submitted_count ?></div>
                        <div class="hero-stat-lbl">Submitted</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-num"><?= $graded_count ?></div>
                        <div class="hero-stat-lbl">Graded</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-num" style="color:var(--purple)"><?= $avg_grade ?>%</div>
                        <div class="hero-stat-lbl">Avg Grade</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="profile-tabs">
            <a href="profile.php?tab=profile"   class="profile-tab <?= $active_tab === 'profile'   ? 'active' : '' ?>">
                <i class="bi bi-person"></i> Profile Info
            </a>
            <a href="profile.php?tab=password"  class="profile-tab <?= $active_tab === 'password'  ? 'active' : '' ?>">
                <i class="bi bi-shield-lock"></i> Password
            </a>
            <a href="profile.php?tab=courses"   class="profile-tab <?= $active_tab === 'courses'   ? 'active' : '' ?>">
                <i class="bi bi-book"></i> Courses
            </a>
            <a href="profile.php?tab=activity"  class="profile-tab <?= $active_tab === 'activity'  ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i> Activity
            </a>
        </div>

        <!-- ════════ TAB: Profile Info ════════ -->
        <?php if ($active_tab === 'profile'): ?>
        <form method="POST" enctype="multipart/form-data">
            <div class="profile-card">
                <div class="card-header-bar">
                    <h6><i class="bi bi-person-lines-fill" style="color:var(--accent)"></i> Personal Information</h6>
                </div>
                <div class="card-body-pad">

                    <!-- Avatar -->
                    <div class="avatar-preview-wrap">
                        <img class="avatar-preview" id="avatarPreview"
                             src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture']) ?>"
                             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student_name) ?>&background=00d4ff&color=fff&size=64'"
                             alt="Preview">
                        <div>
                            <label for="profile_picture" class="avatar-upload-btn">
                                <i class="bi bi-cloud-arrow-up"></i> Upload new photo
                            </label>
                            <div class="form-hint">JPG, PNG, GIF or WebP · max 2 MB</div>
                        </div>
                    </div>
                    <input type="file" name="profile_picture" id="profile_picture" accept="image/*">

                    <hr class="form-divider">

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label-custom">Full Name *</label>
                            <input type="text" name="name" class="form-input"
                                   value="<?= htmlspecialchars($student['name']) ?>"
                                   placeholder="Your full name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Email Address *</label>
                            <input type="email" name="email" class="form-input"
                                   value="<?= htmlspecialchars($student['email']) ?>"
                                   placeholder="you@example.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Phone Number</label>
                            <input type="text" name="phone" class="form-input"
                                   value="<?= htmlspecialchars($student['phone'] ?? '') ?>"
                                   placeholder="+255 712 345 678">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Member Since</label>
                            <input type="text" class="form-input"
                                   value="<?= $member_since ?>" disabled style="opacity:.5; cursor:not-allowed;">
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Bio</label>
                            <textarea name="bio" class="form-input"
                                      placeholder="Tell your lecturers a little about yourself…"><?= htmlspecialchars($student['bio'] ?? '') ?></textarea>
                            <div class="form-hint">Keep it brief — a sentence or two is plenty.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-3 align-items-center">
                <button type="submit" name="update_profile" class="btn-save">
                    <i class="bi bi-check-lg"></i> Save Changes
                </button>
                <a href="profile.php?tab=profile" class="btn-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i> Discard
                </a>
            </div>
        </form>

        <!-- ════════ TAB: Password ════════ -->
        <?php elseif ($active_tab === 'password'): ?>
        <form method="POST">
            <div class="profile-card">
                <div class="card-header-bar">
                    <h6><i class="bi bi-shield-lock-fill" style="color:var(--yellow)"></i> Change Password</h6>
                </div>
                <div class="card-body-pad">
                    <div class="row g-4" style="max-width:520px;">
                        <div class="col-12">
                            <label class="form-label-custom">Current Password</label>
                            <div style="position:relative;">
                                <input type="password" name="current_password" id="currentPass"
                                       class="form-input" placeholder="Enter current password" required
                                       style="padding-right:42px;">
                                <button type="button" onclick="togglePass('currentPass',this)"
                                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                               background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">New Password</label>
                            <div style="position:relative;">
                                <input type="password" name="new_password" id="newPass"
                                       class="form-input" placeholder="Min. 8 characters" required
                                       style="padding-right:42px;" oninput="checkStrength(this.value)">
                                <button type="button" onclick="togglePass('newPass',this)"
                                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                               background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                            <div class="form-hint" id="strengthLabel">Password strength will appear here.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Confirm New Password</label>
                            <div style="position:relative;">
                                <input type="password" name="confirm_password" id="confirmPass"
                                       class="form-input" placeholder="Repeat new password" required
                                       style="padding-right:42px;" oninput="checkMatch()">
                                <button type="button" onclick="togglePass('confirmPass',this)"
                                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                               background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-hint" id="matchLabel"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <button type="submit" name="change_password" class="btn-save">
                    <i class="bi bi-lock-fill"></i> Update Password
                </button>
                <a href="profile.php?tab=password" class="btn-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i> Discard
                </a>
            </div>
        </form>

        <!-- ════════ TAB: Courses ════════ -->
        <?php elseif ($active_tab === 'courses'): ?>
        <div class="profile-card">
            <div class="card-header-bar">
                <h6><i class="bi bi-book-fill" style="color:var(--purple)"></i> Enrolled Courses</h6>
                <span style="color:var(--muted);font-size:0.8rem;"><?= $courses_count ?> course(s)</span>
            </div>
            <?php if (mysqli_num_rows($enrolled_courses) > 0): ?>
                <?php while ($c = mysqli_fetch_assoc($enrolled_courses)): ?>
                <div class="course-row">
                    <div class="course-icon"><i class="bi bi-book"></i></div>
                    <div style="flex:1;min-width:0;">
                        <span class="course-code-pill"><?= htmlspecialchars($c['course_code']) ?></span>
                        <div class="course-title"><?= htmlspecialchars($c['course_name']) ?></div>
                        <div class="course-lecturer"><i class="bi bi-person me-1"></i><?= htmlspecialchars($c['lecturer_name']) ?></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:0.72rem;color:var(--muted);">Enrolled</div>
                        <div style="font-size:0.78rem;color:var(--text);"><?= date('d M Y', strtotime($c['enrolled_at'])) ?></div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-book"></i>
                    <p>You are not enrolled in any courses yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ════════ TAB: Activity ════════ -->
        <?php elseif ($active_tab === 'activity'): ?>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="profile-card">
                    <div class="card-header-bar">
                        <h6><i class="bi bi-clock-history" style="color:var(--yellow)"></i> Recent Submissions</h6>
                        <a href="assignments.php" style="color:var(--accent);font-size:0.8rem;text-decoration:none;">
                            View All <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <?php if (mysqli_num_rows($recent_submissions) > 0): ?>
                        <?php while ($sub = mysqli_fetch_assoc($recent_submissions)):
                            $pct = null;
                            if ($sub['marks_obtained'] !== null && $sub['total_marks'] > 0) {
                                $pct = round(($sub['marks_obtained'] / $sub['total_marks']) * 100, 1);
                            }
                            if ($pct === null)        { $gclass = 'grade-none';  $glabel = 'Pending'; }
                            elseif ($pct >= 70)       { $gclass = 'grade-high';  $glabel = $pct . '%'; }
                            elseif ($pct >= 50)       { $gclass = 'grade-mid';   $glabel = $pct . '%'; }
                            else                      { $gclass = 'grade-low';   $glabel = $pct . '%'; }
                        ?>
                        <div class="submission-row">
                            <div class="sub-icon"><i class="bi bi-file-earmark-check"></i></div>
                            <div style="flex:1;min-width:0;">
                                <div class="sub-title text-truncate"><?= htmlspecialchars($sub['assignment_title']) ?></div>
                                <div class="sub-meta">
                                    <span class="course-code-pill me-1"><?= htmlspecialchars($sub['course_code']) ?></span>
                                    Submitted <?= date('d M Y, H:i', strtotime($sub['submitted_at'])) ?>
                                </div>
                                <?php if (!empty($sub['feedback'])): ?>
                                    <div style="font-size:0.75rem;color:var(--muted);margin-top:3px;font-style:italic;">
                                        "<?= htmlspecialchars(substr($sub['feedback'], 0, 60)) ?>..."
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="grade-chip <?= $gclass ?>"><?= $glabel ?></span>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-file-earmark-x"></i>
                            <p>No submissions yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="profile-card">
                    <div class="card-header-bar">
                        <h6><i class="bi bi-bar-chart-fill" style="color:var(--green)"></i> Overview</h6>
                    </div>
                    <div class="card-body-pad">
                        <?php
                            $stats = [
                                ['label'=>'Courses Enrolled', 'val'=>$courses_count,  'color'=>'var(--accent)',  'icon'=>'bi-book'],
                                ['label'=>'Assignments Pending', 'val'=>$assignments_count, 'color'=>'var(--red)', 'icon'=>'bi-clipboard2'],
                                ['label'=>'Submitted',        'val'=>$submitted_count,'color'=>'var(--green)',  'icon'=>'bi-check2-circle'],
                                ['label'=>'Graded',           'val'=>$graded_count,   'color'=>'var(--purple)', 'icon'=>'bi-star'],
                                ['label'=>'Average Grade',    'val'=>$avg_grade.'%',  'color'=>'var(--yellow)', 'icon'=>'bi-graph-up'],
                            ];
                            foreach ($stats as $s):
                        ?>
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);">
                            <div style="width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,0.06);
                                        display:flex;align-items:center;justify-content:center;
                                        color:<?= $s['color'] ?>;font-size:1rem;flex-shrink:0;">
                                <i class="bi <?= $s['icon'] ?>"></i>
                            </div>
                            <div style="flex:1;">
                                <div style="font-size:0.75rem;color:var(--muted);"><?= $s['label'] ?></div>
                            </div>
                            <div style="font-size:1.15rem;font-weight:800;color:<?= $s['color'] ?>;">
                                <?= $s['val'] ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<div id="overlay" onclick="closeSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Mobile sidebar ────────────────────────────────────────
const sidebar   = document.getElementById('sidebar');
const overlay   = document.getElementById('overlay');
const hamburger = document.getElementById('hamburger');
hamburger.addEventListener('click', () => { sidebar.classList.add('open'); overlay.style.display = 'block'; });
function closeSidebar() { sidebar.classList.remove('open'); overlay.style.display = 'none'; }

// ── Avatar live preview ───────────────────────────────────
document.getElementById('profile_picture').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    document.getElementById('avatarPreview').src = url;
    document.getElementById('heroAvatar').src    = url;
});

// ── Password show/hide ────────────────────────────────────
function togglePass(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// ── Password strength ─────────────────────────────────────
function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8)                              score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val))      score++;
    if (/[0-9]/.test(val))                            score++;
    if (/[^A-Za-z0-9]/.test(val))                    score++;

    const levels = [
        { pct: '0%',   color: 'transparent',       text: '' },
        { pct: '30%',  color: 'var(--red)',         text: 'Weak' },
        { pct: '55%',  color: 'var(--yellow)',      text: 'Fair' },
        { pct: '78%',  color: 'var(--accent)',      text: 'Good' },
        { pct: '100%', color: 'var(--green)',       text: 'Strong' },
    ];
    const l = levels[score] || levels[0];
    fill.style.width      = l.pct;
    fill.style.background = l.color;
    label.textContent     = l.text ? 'Strength: ' + l.text : 'Password strength will appear here.';
    label.style.color     = l.color || 'var(--muted)';
    checkMatch();
}

// ── Password match ────────────────────────────────────────
function checkMatch() {
    const np = document.getElementById('newPass');
    const cp = document.getElementById('confirmPass');
    const lbl = document.getElementById('matchLabel');
    if (!np || !cp || !lbl) return;
    if (cp.value.length === 0) { lbl.textContent = ''; return; }
    if (np.value === cp.value) {
        lbl.textContent = '✓ Passwords match.';
        lbl.style.color = 'var(--green)';
    } else {
        lbl.textContent = '✗ Passwords do not match.';
        lbl.style.color = 'var(--red)';
    }
}
</script>
</body>
</html>