<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'];

// ── Fetch admin info ─────────────────────────────────────
$admin = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM users WHERE id = '$admin_id'")
);

// ── Fetch system settings from DB ────────────────────────
$settings_result = mysqli_query($conn, "SELECT * FROM system_settings LIMIT 1");
$settings = $settings_result ? mysqli_fetch_assoc($settings_result) : [];

// ── Count helpers for sidebar badges ─────────────────────
$new_users_month = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users
                          WHERE MONTH(created_at) = MONTH(NOW())
                          AND YEAR(created_at) = YEAR(NOW())")
)['total'];

$total_notifications = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications")
)['total'];

// ════════════════════════════════════════════════════════
// POST HANDLER
// ════════════════════════════════════════════════════════
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 1. General Site Settings ─────────────────────────
    if ($action === 'save_general') {
        $site_name        = mysqli_real_escape_string($conn, trim($_POST['site_name'] ?? ''));
        $site_tagline     = mysqli_real_escape_string($conn, trim($_POST['site_tagline'] ?? ''));
        $contact_email    = mysqli_real_escape_string($conn, trim($_POST['contact_email'] ?? ''));
        $contact_phone    = mysqli_real_escape_string($conn, trim($_POST['contact_phone'] ?? ''));
        $timezone         = mysqli_real_escape_string($conn, trim($_POST['timezone'] ?? 'UTC'));
        $date_format      = mysqli_real_escape_string($conn, trim($_POST['date_format'] ?? 'd M Y'));
        $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;

        // Check if settings row exists
        $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM system_settings LIMIT 1"));
        if ($check) {
            $sql = "UPDATE system_settings SET
                        site_name = '$site_name',
                        site_tagline = '$site_tagline',
                        contact_email = '$contact_email',
                        contact_phone = '$contact_phone',
                        timezone = '$timezone',
                        date_format = '$date_format',
                        maintenance_mode = $maintenance_mode,
                        updated_at = NOW()
                    WHERE id = {$check['id']}";
        } else {
            $sql = "INSERT INTO system_settings
                        (site_name, site_tagline, contact_email, contact_phone,
                         timezone, date_format, maintenance_mode, updated_at)
                    VALUES
                        ('$site_name','$site_tagline','$contact_email','$contact_phone',
                         '$timezone','$date_format',$maintenance_mode, NOW())";
        }

        if (mysqli_query($conn, $sql)) {
            // Log activity
            $log_action = mysqli_real_escape_string($conn, "Updated general site settings");
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                  VALUES ('$admin_id', '$log_action', NOW())");
            $success_msg = 'General settings saved successfully.';
        } else {
            $error_msg = 'Failed to save settings: ' . mysqli_error($conn);
        }
    }

    // ── 2. Change Admin Password ──────────────────────────
    elseif ($action === 'change_password') {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        $user_row = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT password FROM users WHERE id = '$admin_id'")
        );

        if (!password_verify($current_pass, $user_row['password'])) {
            $error_msg = 'Current password is incorrect.';
        } elseif (strlen($new_pass) < 8) {
            $error_msg = 'New password must be at least 8 characters.';
        } elseif ($new_pass !== $confirm_pass) {
            $error_msg = 'New password and confirmation do not match.';
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $hashed = mysqli_real_escape_string($conn, $hashed);
            mysqli_query($conn, "UPDATE users SET password = '$hashed', updated_at = NOW()
                                  WHERE id = '$admin_id'");
            $log_action = mysqli_real_escape_string($conn, "Changed account password");
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                  VALUES ('$admin_id', '$log_action', NOW())");
            $success_msg = 'Password changed successfully.';
        }
    }

    // ── 3. Registration / Enrollment Settings ────────────
    elseif ($action === 'save_registration') {
        $allow_registration    = isset($_POST['allow_registration'])    ? 1 : 0;
        $email_verification    = isset($_POST['email_verification'])    ? 1 : 0;
        $auto_enroll           = isset($_POST['auto_enroll'])           ? 1 : 0;
        $default_role          = mysqli_real_escape_string($conn, $_POST['default_role'] ?? 'student');
        $max_courses_per_student = (int)($_POST['max_courses_per_student'] ?? 10);

        $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM system_settings LIMIT 1"));
        if ($check) {
            $sql = "UPDATE system_settings SET
                        allow_registration = $allow_registration,
                        email_verification = $email_verification,
                        auto_enroll = $auto_enroll,
                        default_role = '$default_role',
                        max_courses_per_student = $max_courses_per_student,
                        updated_at = NOW()
                    WHERE id = {$check['id']}";
        } else {
            $sql = "INSERT INTO system_settings
                        (allow_registration, email_verification, auto_enroll,
                         default_role, max_courses_per_student, updated_at)
                    VALUES ($allow_registration, $email_verification, $auto_enroll,
                            '$default_role', $max_courses_per_student, NOW())";
        }
        if (mysqli_query($conn, $sql)) {
            $log_action = mysqli_real_escape_string($conn, "Updated registration settings");
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                  VALUES ('$admin_id', '$log_action', NOW())");
            $success_msg = 'Registration settings saved.';
        } else {
            $error_msg = 'Error: ' . mysqli_error($conn);
        }
    }

    // ── 4. Notification Settings ──────────────────────────
    elseif ($action === 'save_notifications') {
        $notify_new_user       = isset($_POST['notify_new_user'])       ? 1 : 0;
        $notify_submission     = isset($_POST['notify_submission'])     ? 1 : 0;
        $notify_grade          = isset($_POST['notify_grade'])          ? 1 : 0;
        $notify_enrollment     = isset($_POST['notify_enrollment'])     ? 1 : 0;
        $admin_notify_email    = mysqli_real_escape_string($conn, trim($_POST['admin_notify_email'] ?? ''));

        $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM system_settings LIMIT 1"));
        if ($check) {
            $sql = "UPDATE system_settings SET
                        notify_new_user = $notify_new_user,
                        notify_submission = $notify_submission,
                        notify_grade = $notify_grade,
                        notify_enrollment = $notify_enrollment,
                        admin_notify_email = '$admin_notify_email',
                        updated_at = NOW()
                    WHERE id = {$check['id']}";
        } else {
            $sql = "INSERT INTO system_settings
                        (notify_new_user, notify_submission, notify_grade,
                         notify_enrollment, admin_notify_email, updated_at)
                    VALUES ($notify_new_user, $notify_submission, $notify_grade,
                            $notify_enrollment, '$admin_notify_email', NOW())";
        }
        if (mysqli_query($conn, $sql)) {
            $log_action = mysqli_real_escape_string($conn, "Updated notification settings");
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                  VALUES ('$admin_id', '$log_action', NOW())");
            $success_msg = 'Notification settings saved.';
        } else {
            $error_msg = 'Error: ' . mysqli_error($conn);
        }
    }

    // ── 5. Upload/File Settings ───────────────────────────
    elseif ($action === 'save_uploads') {
        $max_file_size_mb  = (int)($_POST['max_file_size_mb'] ?? 20);
        $allowed_note_types = mysqli_real_escape_string($conn, trim($_POST['allowed_note_types'] ?? 'pdf,docx,pptx'));
        $allowed_sub_types  = mysqli_real_escape_string($conn, trim($_POST['allowed_sub_types']  ?? 'pdf,docx,zip'));
        $max_profile_size_mb = (int)($_POST['max_profile_size_mb'] ?? 2);

        $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM system_settings LIMIT 1"));
        if ($check) {
            $sql = "UPDATE system_settings SET
                        max_file_size_mb = $max_file_size_mb,
                        allowed_note_types = '$allowed_note_types',
                        allowed_sub_types = '$allowed_sub_types',
                        max_profile_size_mb = $max_profile_size_mb,
                        updated_at = NOW()
                    WHERE id = {$check['id']}";
        } else {
            $sql = "INSERT INTO system_settings
                        (max_file_size_mb, allowed_note_types, allowed_sub_types,
                         max_profile_size_mb, updated_at)
                    VALUES ($max_file_size_mb, '$allowed_note_types', '$allowed_sub_types',
                            $max_profile_size_mb, NOW())";
        }
        if (mysqli_query($conn, $sql)) {
            $log_action = mysqli_real_escape_string($conn, "Updated file upload settings");
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                  VALUES ('$admin_id', '$log_action', NOW())");
            $success_msg = 'Upload settings saved.';
        } else {
            $error_msg = 'Error: ' . mysqli_error($conn);
        }
    }

    // ── 6. Clear Activity Logs ────────────────────────────
    elseif ($action === 'clear_logs') {
        if (mysqli_query($conn, "DELETE FROM activity_logs")) {
            $log_action = mysqli_real_escape_string($conn, "Cleared all activity logs");
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                  VALUES ('$admin_id', '$log_action', NOW())");
            $success_msg = 'Activity logs cleared.';
        } else {
            $error_msg = 'Failed to clear logs.';
        }
    }

    // Refresh settings after save
    $settings_result = mysqli_query($conn, "SELECT * FROM system_settings LIMIT 1");
    $settings = $settings_result ? mysqli_fetch_assoc($settings_result) : [];
}

// ── Helper: get setting value with fallback ───────────────
function s($key, $default = '') {
    global $settings;
    return isset($settings[$key]) ? htmlspecialchars($settings[$key]) : $default;
}
function sb($key, $default = 0) {
    global $settings;
    return isset($settings[$key]) ? (int)$settings[$key] : $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - OnlineLMS</title>
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
            overflow-y: auto;
        }
        .sidebar-brand { padding: 22px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.15rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.72rem; }
        .sidebar-profile { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .sidebar-profile img { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent); }
        .sidebar-profile .name { font-weight: 600; font-size: 0.88rem; color: var(--text); }
        .role-badge { background: rgba(255,107,107,0.15); color: var(--accent); border: 1px solid rgba(255,107,107,0.3); border-radius: 20px; padding: 1px 10px; font-size: 0.68rem; font-weight: 700; }
        .sidebar-nav { flex: 1; padding: 12px 0; }
        .nav-section { padding: 8px 20px 4px; font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted); }
        .sidebar-nav a { display: flex; align-items: center; gap: 11px; padding: 10px 20px; color: var(--muted); text-decoration: none; font-size: 0.875rem; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar-nav a:hover, .sidebar-nav a.active { color: var(--text); background: var(--bg-hover); border-left-color: var(--accent); }
        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1rem; width: 20px; }
        .nav-badge { margin-left: auto; background: var(--accent); color: white; border-radius: 20px; padding: 1px 8px; font-size: 0.68rem; font-weight: 700; }
        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a { display: flex; align-items: center; gap: 10px; color: var(--accent); text-decoration: none; font-size: 0.875rem; font-weight: 500; padding: 8px 0; transition: opacity 0.2s; }
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
        .notif-dot { position: absolute; top: 5px; right: 7px; width: 8px; height: 8px; background: var(--accent); border-radius: 50%; border: 2px solid var(--bg-main); }
        .hamburger { display: none; background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 8px 12px; color: var(--text); cursor: pointer; }

        /* ── Page Body ── */
        .page-body { padding: 26px; flex: 1; }

        /* ── Page Header ── */
        .page-header { margin-bottom: 26px; }
        .page-header h4 { font-weight: 800; font-size: 1.3rem; margin-bottom: 4px; }
        .page-header p { color: var(--muted); font-size: 0.84rem; margin: 0; }

        /* ── Settings Nav Pills ── */
        .settings-nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding: 6px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
        }

        .settings-nav-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--muted);
            background: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .settings-nav-btn:hover {
            background: var(--bg-hover);
            color: var(--text);
        }

        .settings-nav-btn.active {
            background: rgba(255,107,107,0.12);
            color: var(--accent);
            border: 1px solid rgba(255,107,107,0.25);
        }

        .settings-nav-btn i { font-size: 0.95rem; }

        /* ── Section Card ── */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 22px;
        }

        .section-header {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-header h6 {
            font-weight: 700;
            font-size: 0.92rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .section-header p {
            color: var(--muted);
            font-size: 0.76rem;
            margin: 0;
        }

        .section-body { padding: 22px; }

        /* ── Form Controls ── */
        .form-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-hint {
            font-size: 0.72rem;
            color: var(--muted);
            margin-top: 4px;
        }

        .form-control, .form-select {
            background: rgba(255,255,255,0.06) !important;
            border: 1px solid var(--border) !important;
            border-radius: 10px !important;
            color: var(--text) !important;
            font-size: 0.855rem;
            padding: 10px 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent) !important;
            box-shadow: 0 0 0 3px rgba(255,107,107,0.1) !important;
            outline: none;
        }

        .form-control::placeholder { color: var(--muted); }
        .form-select option { background: #1a1a2e; color: var(--text); }

        /* ── Toggle Switch ── */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
        }

        .toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
        .toggle-row:first-child { padding-top: 0; }

        .toggle-info { flex: 1; padding-right: 16px; }
        .toggle-info .title { font-size: 0.855rem; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .toggle-info .desc  { font-size: 0.75rem; color: var(--muted); }

        .form-switch .form-check-input {
            width: 44px;
            height: 24px;
            background-color: rgba(255,255,255,0.1);
            border-color: var(--border);
            cursor: pointer;
        }

        .form-switch .form-check-input:checked {
            background-color: var(--accent);
            border-color: var(--accent);
        }

        .form-switch .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(255,107,107,0.15);
        }

        /* ── Buttons ── */
        .btn-save {
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 22px;
            font-size: 0.845rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-save:hover { background: #ff4444; transform: translateY(-1px); color: white; }

        .btn-danger-outline {
            background: rgba(255,107,107,0.08);
            color: var(--accent);
            border: 1px solid rgba(255,107,107,0.3);
            border-radius: 10px;
            padding: 10px 22px;
            font-size: 0.845rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-danger-outline:hover { background: rgba(255,107,107,0.18); }

        .btn-secondary-outline {
            background: var(--bg-card);
            color: var(--muted);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 22px;
            font-size: 0.845rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
        }

        .btn-secondary-outline:hover { border-color: var(--muted); color: var(--text); }

        /* ── Password Strength ── */
        .strength-bar {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s, background 0.3s;
            width: 0%;
        }

        .strength-label {
            font-size: 0.7rem;
            color: var(--muted);
            margin-top: 4px;
        }

        /* ── Alert ── */
        .alert-success-custom {
            background: rgba(107,203,119,0.1);
            border: 1px solid rgba(107,203,119,0.3);
            border-radius: 12px;
            padding: 13px 18px;
            color: var(--green);
            font-size: 0.845rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .alert-error-custom {
            background: rgba(255,107,107,0.1);
            border: 1px solid rgba(255,107,107,0.3);
            border-radius: 12px;
            padding: 13px 18px;
            color: var(--accent);
            font-size: 0.845rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        /* ── Info Box ── */
        .info-box {
            background: rgba(0,212,255,0.06);
            border: 1px solid rgba(0,212,255,0.2);
            border-radius: 12px;
            padding: 14px 18px;
            color: var(--blue);
            font-size: 0.8rem;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .info-box i { flex-shrink: 0; margin-top: 1px; }

        /* ── Danger Zone ── */
        .danger-zone {
            background: rgba(255,107,107,0.04);
            border: 1px solid rgba(255,107,107,0.2);
            border-radius: 16px;
            overflow: hidden;
        }

        .danger-zone .section-header {
            background: rgba(255,107,107,0.06);
            border-bottom-color: rgba(255,107,107,0.15);
        }

        .danger-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 22px;
            border-bottom: 1px solid rgba(255,107,107,0.1);
        }

        .danger-action:last-child { border-bottom: none; }

        .danger-action .title { font-size: 0.855rem; font-weight: 600; color: var(--text); margin-bottom: 3px; }
        .danger-action .desc  { font-size: 0.75rem; color: var(--muted); }

        /* ── Settings Tab Panels ── */
        .settings-panel { display: none; }
        .settings-panel.active { display: block; }

        /* ── Divider ── */
        .form-divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 20px 0;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .settings-nav { gap: 4px; }
            .settings-nav-btn span { display: none; }
            .settings-nav-btn { padding: 9px 12px; }
        }
    </style>
</head>
<body>

<!-- ════════════════════ SIDEBAR ════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Admin Control Panel</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=46'"
             alt="Admin">
        <div>
            <div class="name"><?= htmlspecialchars($admin_name) ?></div>
            <span class="role-badge">Administrator</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>
        <a href="reports.php">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>

        <div class="nav-section">Management</div>
        <a href="manage_users.php">
            <i class="bi bi-people"></i> Manage Users
            <?php if ($new_users_month > 0): ?>
                <span class="nav-badge"><?= $new_users_month ?> new</span>
            <?php endif; ?>
        </a>
        <a href="manage_courses.php">
            <i class="bi bi-book"></i> Manage Courses
        </a>
        <a href="manage_notes.php">
            <i class="bi bi-file-earmark-text"></i> Manage Notes
        </a>
        <a href="manage_assignments.php">
            <i class="bi bi-clipboard2-check"></i> Manage Assignments
        </a>
        <a href="manage_notifications.php">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($total_notifications > 0): ?>
                <span class="nav-badge"><?= $total_notifications ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">System</div>
        <a href="settings.php" class="active">
            <i class="bi bi-gear"></i> Settings
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

<!-- ════════════════════ MAIN CONTENT ════════════════════ -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
            <div class="topbar-left">
                <h6>System Settings</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="dashboard.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-grid-1x2"></i> Dashboard
            </a>
            <a href="manage_notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" style="text-decoration:none;">
                <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=36'"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
            </a>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Page Header -->
        <div class="page-header">
            <h4><i class="bi bi-gear-wide-connected me-2" style="color:var(--accent)"></i>System Settings</h4>
            <p>Configure your LMS platform preferences, security, and system behaviour.</p>
        </div>

        <!-- Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert-success-custom">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert-error-custom">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Settings Navigation Pills -->
        <div class="settings-nav">
            <button class="settings-nav-btn active" onclick="switchTab('general', this)">
                <i class="bi bi-sliders"></i> <span>General</span>
            </button>
            <button class="settings-nav-btn" onclick="switchTab('security', this)">
                <i class="bi bi-shield-lock"></i> <span>Security</span>
            </button>
            <button class="settings-nav-btn" onclick="switchTab('registration', this)">
                <i class="bi bi-person-plus"></i> <span>Registration</span>
            </button>
            <button class="settings-nav-btn" onclick="switchTab('notifications', this)">
                <i class="bi bi-bell"></i> <span>Notifications</span>
            </button>
            <button class="settings-nav-btn" onclick="switchTab('uploads', this)">
                <i class="bi bi-cloud-upload"></i> <span>Uploads</span>
            </button>
            <button class="settings-nav-btn" onclick="switchTab('danger', this)">
                <i class="bi bi-exclamation-triangle"></i> <span>Danger Zone</span>
            </button>
        </div>

        <!-- ═══════════════════════════════════════
             TAB 1: GENERAL
        ═══════════════════════════════════════ -->
        <div class="settings-panel active" id="tab-general">
            <form method="POST">
                <input type="hidden" name="action" value="save_general">

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-globe" style="color:var(--blue)"></i> Site Information</h6>
                            <p class="mt-1">Basic details displayed across the platform.</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Site Name</label>
                                <input type="text" class="form-control" name="site_name"
                                       value="<?= s('site_name', 'OnlineLMS') ?>"
                                       placeholder="e.g. OnlineLMS" required>
                                <div class="form-hint">Appears in the browser tab and email headers.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Site Tagline</label>
                                <input type="text" class="form-control" name="site_tagline"
                                       value="<?= s('site_tagline', 'Learn. Grow. Succeed.') ?>"
                                       placeholder="A short description of your platform">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Email</label>
                                <input type="email" class="form-control" name="contact_email"
                                       value="<?= s('contact_email') ?>"
                                       placeholder="admin@example.com">
                                <div class="form-hint">Used for system-generated emails and support links.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Phone</label>
                                <input type="text" class="form-control" name="contact_phone"
                                       value="<?= s('contact_phone') ?>"
                                       placeholder="+255 700 000 000">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-clock" style="color:var(--yellow)"></i> Locale & Display</h6>
                            <p class="mt-1">Control date formats and timezone.</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Timezone</label>
                                <select class="form-select" name="timezone">
                                    <?php
                                    $timezones = [
                                        'UTC'                => 'UTC',
                                        'Africa/Dar_es_Salaam' => 'Africa/Dar es Salaam (EAT)',
                                        'Africa/Nairobi'     => 'Africa/Nairobi (EAT)',
                                        'Africa/Kampala'     => 'Africa/Kampala (EAT)',
                                        'Asia/Kolkata'       => 'Asia/Kolkata (IST)',
                                        'America/New_York'   => 'America/New York (EST)',
                                        'America/Los_Angeles'=> 'America/Los Angeles (PST)',
                                        'Europe/London'      => 'Europe/London (GMT)',
                                    ];
                                    foreach ($timezones as $val => $label):
                                        $sel = (s('timezone', 'UTC') === $val) ? 'selected' : '';
                                    ?>
                                        <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date Format</label>
                                <select class="form-select" name="date_format">
                                    <?php
                                    $formats = [
                                        'd M Y'  => date('d M Y') . ' (Day Mon Year)',
                                        'd/m/Y'  => date('d/m/Y') . ' (DD/MM/YYYY)',
                                        'm/d/Y'  => date('m/d/Y') . ' (MM/DD/YYYY)',
                                        'Y-m-d'  => date('Y-m-d') . ' (YYYY-MM-DD)',
                                        'D, d M Y' => date('D, d M Y') . ' (Full)',
                                    ];
                                    foreach ($formats as $val => $label):
                                        $sel = (s('date_format', 'd M Y') === $val) ? 'selected' : '';
                                    ?>
                                        <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <h6><i class="bi bi-tools" style="color:var(--accent)"></i> Maintenance Mode</h6>
                    </div>
                    <div class="section-body">
                        <div class="toggle-row" style="border:none; padding:0;">
                            <div class="toggle-info">
                                <div class="title">Enable Maintenance Mode</div>
                                <div class="desc">
                                    When active, only admins can log in. Students and lecturers
                                    will see a maintenance notice.
                                </div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="maintenance_mode" id="maintenanceToggle"
                                       <?= sb('maintenance_mode') ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <?php if (sb('maintenance_mode')): ?>
                            <div class="info-box mt-3">
                                <i class="bi bi-info-circle-fill"></i>
                                <span>Maintenance mode is currently <strong>ON</strong>. Only admin accounts can access the system.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-floppy"></i> Save General Settings
                    </button>
                    <a href="dashboard.php" class="btn-secondary-outline">
                        <i class="bi bi-x"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- ═══════════════════════════════════════
             TAB 2: SECURITY
        ═══════════════════════════════════════ -->
        <div class="settings-panel" id="tab-security">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-key" style="color:var(--yellow)"></i> Change Admin Password</h6>
                            <p class="mt-1">Update your administrator account password.</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Current Password</label>
                                <div class="position-relative">
                                    <input type="password" class="form-control" name="current_password"
                                           id="currentPass" placeholder="Enter current password" required>
                                    <button type="button" class="password-toggle"
                                            onclick="togglePass('currentPass', this)"
                                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                                   background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6"></div>
                            <div class="col-md-6">
                                <label class="form-label">New Password</label>
                                <div class="position-relative">
                                    <input type="password" class="form-control" name="new_password"
                                           id="newPass" placeholder="Min. 8 characters"
                                           oninput="checkStrength(this.value)" required>
                                    <button type="button" class="password-toggle"
                                            onclick="togglePass('newPass', this)"
                                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                                   background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                                <div class="strength-label" id="strengthLabel">Enter a password to check strength</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirm New Password</label>
                                <div class="position-relative">
                                    <input type="password" class="form-control" name="confirm_password"
                                           id="confirmPass" placeholder="Repeat new password"
                                           oninput="checkMatch()" required>
                                    <button type="button" class="password-toggle"
                                            onclick="togglePass('confirmPass', this)"
                                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                                   background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-hint" id="matchHint"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="info-box">
                    <i class="bi bi-shield-check"></i>
                    <div>
                        Use a strong password with at least 8 characters, mixing uppercase, lowercase,
                        numbers and symbols. Avoid reusing passwords from other accounts.
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-key"></i> Update Password
                    </button>
                </div>
            </form>
        </div>

        <!-- ═══════════════════════════════════════
             TAB 3: REGISTRATION
        ═══════════════════════════════════════ -->
        <div class="settings-panel" id="tab-registration">
            <form method="POST">
                <input type="hidden" name="action" value="save_registration">

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-person-plus" style="color:var(--green)"></i> Registration Controls</h6>
                            <p class="mt-1">Decide who can register and how new accounts are handled.</p>
                        </div>
                    </div>
                    <div class="section-body">

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="title">Allow Public Registration</div>
                                <div class="desc">When off, only admins can create new user accounts.</div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="allow_registration" id="regToggle"
                                       <?= sb('allow_registration', 1) ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="title">Require Email Verification</div>
                                <div class="desc">New users must verify their email before accessing the system.</div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="email_verification" id="emailVerToggle"
                                       <?= sb('email_verification') ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="title">Auto-Enroll in Default Course</div>
                                <div class="desc">Automatically enrol new students in a starter/orientation course.</div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="auto_enroll" id="autoEnrollToggle"
                                       <?= sb('auto_enroll') ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <hr class="form-divider">

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Default Role for New Users</label>
                                <select class="form-select" name="default_role">
                                    <option value="student"  <?= s('default_role','student') === 'student'  ? 'selected':'' ?>>Student</option>
                                    <option value="lecturer" <?= s('default_role','student') === 'lecturer' ? 'selected':'' ?>>Lecturer</option>
                                </select>
                                <div class="form-hint">Role automatically assigned on registration.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Max Courses Per Student</label>
                                <input type="number" class="form-control" name="max_courses_per_student"
                                       value="<?= s('max_courses_per_student', '10') ?>"
                                       min="1" max="50" placeholder="10">
                                <div class="form-hint">Maximum course enrolments a student can hold simultaneously.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-floppy"></i> Save Registration Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- ═══════════════════════════════════════
             TAB 4: NOTIFICATIONS
        ═══════════════════════════════════════ -->
        <div class="settings-panel" id="tab-notifications">
            <form method="POST">
                <input type="hidden" name="action" value="save_notifications">

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-bell" style="color:var(--blue)"></i> System Notification Events</h6>
                            <p class="mt-1">Choose which events generate in-platform notifications.</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="title">New User Registered</div>
                                <div class="desc">Notify admin when a new account is created.</div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="notify_new_user" id="notifUserToggle"
                                       <?= sb('notify_new_user', 1) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="title">Assignment Submitted</div>
                                <div class="desc">Notify lecturers when a student submits an assignment.</div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="notify_submission" id="notifSubToggle"
                                       <?= sb('notify_submission', 1) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="title">Assignment Graded</div>
                                <div class="desc">Notify students when a submission has been graded.</div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="notify_grade" id="notifGradeToggle"
                                       <?= sb('notify_grade', 1) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="title">Course Enrolment</div>
                                <div class="desc">Notify lecturers when a student enrols in their course.</div>
                            </div>
                            <div class="form-check form-switch ms-3">
                                <input class="form-check-input" type="checkbox"
                                       name="notify_enrollment" id="notifEnrolToggle"
                                       <?= sb('notify_enrollment', 1) ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <hr class="form-divider">

                        <div class="col-md-7">
                            <label class="form-label">Admin Notification Email</label>
                            <input type="email" class="form-control" name="admin_notify_email"
                                   value="<?= s('admin_notify_email') ?>"
                                   placeholder="admin@example.com">
                            <div class="form-hint">Critical alerts (e.g. login failures) are sent to this address.</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-floppy"></i> Save Notification Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- ═══════════════════════════════════════
             TAB 5: UPLOADS
        ═══════════════════════════════════════ -->
        <div class="settings-panel" id="tab-uploads">
            <form method="POST">
                <input type="hidden" name="action" value="save_uploads">

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-cloud-upload" style="color:var(--purple)"></i> File Upload Settings</h6>
                            <p class="mt-1">Control what files users can upload and how large they can be.</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Max Note/Material File Size (MB)</label>
                                <input type="number" class="form-control" name="max_file_size_mb"
                                       value="<?= s('max_file_size_mb', '20') ?>"
                                       min="1" max="500" placeholder="20">
                                <div class="form-hint">Applied to note and resource uploads by lecturers.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Max Profile Picture Size (MB)</label>
                                <input type="number" class="form-control" name="max_profile_size_mb"
                                       value="<?= s('max_profile_size_mb', '2') ?>"
                                       min="1" max="10" placeholder="2">
                                <div class="form-hint">Applies to all users updating their profile photo.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Allowed Note File Types</label>
                                <input type="text" class="form-control" name="allowed_note_types"
                                       value="<?= s('allowed_note_types', 'pdf,docx,pptx,xlsx,mp4,zip') ?>"
                                       placeholder="pdf,docx,pptx,xlsx,mp4,zip">
                                <div class="form-hint">Comma-separated extensions for lecture notes and materials.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Allowed Assignment Submission Types</label>
                                <input type="text" class="form-control" name="allowed_sub_types"
                                       value="<?= s('allowed_sub_types', 'pdf,docx,zip,txt') ?>"
                                       placeholder="pdf,docx,zip,txt">
                                <div class="form-hint">Comma-separated extensions students may submit.</div>
                            </div>
                        </div>

                        <hr class="form-divider">

                        <div class="info-box">
                            <i class="bi bi-info-circle-fill"></i>
                            <div>
                                These settings enforce client-side and server-side validation.
                                Ensure your PHP <code>upload_max_filesize</code> and
                                <code>post_max_size</code> in <code>php.ini</code> match or
                                exceed the values you set here.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-floppy"></i> Save Upload Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- ═══════════════════════════════════════
             TAB 6: DANGER ZONE
        ═══════════════════════════════════════ -->
        <div class="settings-panel" id="tab-danger">

            <div class="info-box mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div>
                    Actions in this section are <strong>irreversible</strong>. They permanently
                    delete or reset data. Proceed only if you are certain.
                </div>
            </div>

            <div class="danger-zone">
                <div class="section-header">
                    <h6 style="color:var(--accent)">
                        <i class="bi bi-exclamation-octagon"></i> Danger Zone
                    </h6>
                </div>

                <!-- Clear Activity Logs -->
                <div class="danger-action">
                    <div>
                        <div class="title">Clear All Activity Logs</div>
                        <div class="desc">Permanently deletes every record in the activity log table. Cannot be undone.</div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Clear ALL activity logs? This cannot be undone.')">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn-danger-outline">
                            <i class="bi bi-trash3"></i> Clear Logs
                        </button>
                    </form>
                </div>

                <!-- Reset Notification Counter -->
                <div class="danger-action">
                    <div>
                        <div class="title">Delete All Notifications</div>
                        <div class="desc">Removes every notification from the system for all users.</div>
                    </div>
                    <form method="POST"
                          onsubmit="return confirm('Delete ALL notifications? This cannot be undone.')">
                        <input type="hidden" name="action" value="clear_notifications">
                        <button type="submit" class="btn-danger-outline">
                            <i class="bi bi-bell-slash"></i> Delete Notifications
                        </button>
                    </form>
                </div>

                <!-- Deactivate All Students -->
                <div class="danger-action">
                    <div>
                        <div class="title">Deactivate All Student Accounts</div>
                        <div class="desc">Sets all student accounts to <em>inactive</em>. Students will not be deleted but cannot log in.</div>
                    </div>
                    <button type="button" class="btn-danger-outline"
                            onclick="confirmDeactivate()">
                        <i class="bi bi-person-x"></i> Deactivate Students
                    </button>
                </div>

            </div><!-- end danger-zone -->
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile Overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.5); z-index:99;">
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar toggle ─────────────────────────────────────────
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

// ── Tab switching ──────────────────────────────────────────
function switchTab(name, btn) {
    // Hide all panels
    document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
    // Deactivate all nav buttons
    document.querySelectorAll('.settings-nav-btn').forEach(b => b.classList.remove('active'));
    // Show target
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    // Scroll to top of page body smoothly
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Password visibility toggle ─────────────────────────────
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

// ── Password strength ──────────────────────────────────────
function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score   = 0;
    if (val.length >= 8)           score++;
    if (val.length >= 12)          score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '0%',   color: 'transparent',     text: 'Enter a password to check strength' },
        { pct: '20%',  color: '#ff6b6b',          text: 'Very weak' },
        { pct: '40%',  color: '#ffa500',          text: 'Weak' },
        { pct: '60%',  color: '#ffd93d',          text: 'Fair' },
        { pct: '80%',  color: '#6bcb77',          text: 'Strong' },
        { pct: '100%', color: '#20c997',          text: 'Very strong' },
    ];

    const lvl = levels[Math.min(score, 5)];
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent     = lvl.text;
    label.style.color     = lvl.color === 'transparent' ? 'var(--muted)' : lvl.color;
}

// ── Password match check ───────────────────────────────────
function checkMatch() {
    const np   = document.getElementById('newPass').value;
    const cp   = document.getElementById('confirmPass').value;
    const hint = document.getElementById('matchHint');
    if (cp.length === 0) { hint.textContent = ''; return; }
    if (np === cp) {
        hint.textContent = '✓ Passwords match';
        hint.style.color = 'var(--green)';
    } else {
        hint.textContent = '✗ Passwords do not match';
        hint.style.color = 'var(--accent)';
    }
}

// ── Danger: deactivate students confirmation ───────────────
function confirmDeactivate() {
    if (!confirm('This will deactivate ALL student accounts. They cannot log in until reactivated. Continue?')) return;
    // Build a hidden form and submit
    const f = document.createElement('form');
    f.method = 'POST';
    const a  = document.createElement('input');
    a.type   = 'hidden'; a.name = 'action'; a.value = 'deactivate_students';
    f.appendChild(a);
    document.body.appendChild(f);
    f.submit();
}

// ── Auto-dismiss alerts after 5 s ────────────────────────
document.querySelectorAll('.alert-success-custom, .alert-error-custom').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    }, 5000);
});

// ── Restore active tab from URL hash ──────────────────────
const hash = window.location.hash.replace('#', '');
const validTabs = ['general','security','registration','notifications','uploads','danger'];
if (validTabs.includes(hash)) {
    const btn = [...document.querySelectorAll('.settings-nav-btn')]
        .find(b => b.getAttribute('onclick').includes("'" + hash + "'"));
    if (btn) switchTab(hash, btn);
}
</script>
</body>
</html>