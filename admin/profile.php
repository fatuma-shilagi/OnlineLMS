<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'];

// ── Fetch full admin info ─────────────────────────────────
$admin = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM users WHERE id = '$admin_id'")
);

// ── Sidebar badge helpers ─────────────────────────────────
$new_users_month = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users
                          WHERE MONTH(created_at) = MONTH(NOW())
                          AND YEAR(created_at) = YEAR(NOW())")
)['total'];

$total_notifications = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications")
)['total'];

// ── Activity log for this admin (last 10) ─────────────────
$activity_logs = mysqli_query($conn,
    "SELECT * FROM activity_logs
     WHERE user_id = '$admin_id'
     ORDER BY created_at DESC
     LIMIT 10"
);

// ── Total system stats managed by admin ───────────────────
$stat_users = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE status='active'")
)['total'];

$stat_courses = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM courses WHERE status='active'")
)['total'];

$stat_notifications_sent = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications WHERE sent_by = '$admin_id'")
)['total'];

$stat_logs_total = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM activity_logs WHERE user_id = '$admin_id'")
)['total'];

// ════════════════════════════════════════════════════════
// POST HANDLER
// ════════════════════════════════════════════════════════
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 1. Update Profile Info ────────────────────────────
    if ($action === 'update_profile') {
        $name     = mysqli_real_escape_string($conn, trim($_POST['name']  ?? ''));
        $email    = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
        $phone    = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
        $bio      = mysqli_real_escape_string($conn, trim($_POST['bio']   ?? ''));
        $address  = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));

        if (empty($name) || empty($email)) {
            $error_msg = 'Name and email are required.';
        } else {
            // Check email uniqueness (exclude self)
            $email_check = mysqli_fetch_assoc(
                mysqli_query($conn, "SELECT id FROM users WHERE email='$email' AND id != '$admin_id'")
            );
            if ($email_check) {
                $error_msg = 'That email address is already in use by another account.';
            } else {
                mysqli_query($conn,
                    "UPDATE users SET
                        name    = '$name',
                        email   = '$email',
                        phone   = '$phone',
                        bio     = '$bio',
                        address = '$address',
                        updated_at = NOW()
                     WHERE id = '$admin_id'"
                );
                // Update session name
                $_SESSION['user_name'] = $name;
                $admin_name = $name;

                $log = mysqli_real_escape_string($conn, "Updated profile information");
                mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                      VALUES ('$admin_id', '$log', NOW())");
                $success_msg = 'Profile updated successfully.';
                // Refresh
                $admin = mysqli_fetch_assoc(
                    mysqli_query($conn, "SELECT * FROM users WHERE id = '$admin_id'")
                );
            }
        }
    }

    // ── 2. Upload Profile Picture ─────────────────────────
    elseif ($action === 'upload_picture') {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file      = $_FILES['profile_picture'];
            $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed   = ['jpg','jpeg','png','gif','webp'];
            $max_size  = 2 * 1024 * 1024; // 2 MB

            if (!in_array($ext, $allowed)) {
                $error_msg = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
            } elseif ($file['size'] > $max_size) {
                $error_msg = 'Profile picture must be under 2 MB.';
            } else {
                $filename    = 'admin_' . $admin_id . '_' . time() . '.' . $ext;
                $upload_dir  = '../uploads/profiles/';
                $upload_path = $upload_dir . $filename;

                // Remove old picture if not default
                $old_pic = $admin['profile_picture'] ?? '';
                if ($old_pic && $old_pic !== 'default.png' && file_exists($upload_dir . $old_pic)) {
                    unlink($upload_dir . $old_pic);
                }

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $filename_esc = mysqli_real_escape_string($conn, $filename);
                    mysqli_query($conn,
                        "UPDATE users SET profile_picture = '$filename_esc', updated_at = NOW()
                         WHERE id = '$admin_id'"
                    );
                    $log = mysqli_real_escape_string($conn, "Updated profile picture");
                    mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                          VALUES ('$admin_id', '$log', NOW())");
                    $success_msg = 'Profile picture updated.';
                    $admin = mysqli_fetch_assoc(
                        mysqli_query($conn, "SELECT * FROM users WHERE id = '$admin_id'")
                    );
                } else {
                    $error_msg = 'Failed to upload image. Check folder permissions.';
                }
            }
        } else {
            $error_msg = 'No file selected or upload error occurred.';
        }
    }

    // ── 3. Remove Profile Picture ─────────────────────────
    elseif ($action === 'remove_picture') {
        $old_pic   = $admin['profile_picture'] ?? '';
        $upload_dir = '../uploads/profiles/';
        if ($old_pic && $old_pic !== 'default.png' && file_exists($upload_dir . $old_pic)) {
            unlink($upload_dir . $old_pic);
        }
        mysqli_query($conn,
            "UPDATE users SET profile_picture = 'default.png', updated_at = NOW()
             WHERE id = '$admin_id'"
        );
        $log = mysqli_real_escape_string($conn, "Removed profile picture");
        mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                              VALUES ('$admin_id', '$log', NOW())");
        $success_msg = 'Profile picture removed.';
        $admin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id = '$admin_id'"));
    }

    // ── 4. Change Password ────────────────────────────────
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $user_row = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT password FROM users WHERE id = '$admin_id'")
        );

        if (!password_verify($current, $user_row['password'])) {
            $error_msg = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error_msg = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error_msg = 'New password and confirmation do not match.';
        } else {
            $hashed = mysqli_real_escape_string($conn, password_hash($new, PASSWORD_DEFAULT));
            mysqli_query($conn, "UPDATE users SET password='$hashed', updated_at=NOW() WHERE id='$admin_id'");
            $log = mysqli_real_escape_string($conn, "Changed account password");
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, created_at)
                                  VALUES ('$admin_id', '$log', NOW())");
            $success_msg = 'Password changed successfully.';
        }
    }

    // Refresh logs after any POST
    $activity_logs = mysqli_query($conn,
        "SELECT * FROM activity_logs WHERE user_id = '$admin_id'
         ORDER BY created_at DESC LIMIT 10"
    );
    $stat_logs_total = mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT COUNT(*) as total FROM activity_logs WHERE user_id = '$admin_id'")
    )['total'];
}
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

        /* ════ Sidebar ════ */
        .sidebar { width: var(--sidebar-w); background: rgba(255,255,255,0.03); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; height: 100vh; z-index: 100; transition: transform 0.3s; overflow-y: auto; }
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

        /* ════ Main ════ */
        .main-content { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

        /* ════ Topbar ════ */
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

        /* ════ Page Body ════ */
        .page-body { padding: 26px; flex: 1; }

        /* ════ Profile Hero ════ */
        .profile-hero {
            background: linear-gradient(135deg, rgba(255,107,107,0.08), rgba(180,143,252,0.05));
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px 28px;
            display: flex;
            align-items: center;
            gap: 28px;
            margin-bottom: 26px;
            position: relative;
            overflow: hidden;
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(255,107,107,0.06) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-avatar-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .hero-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
            display: block;
        }

        .avatar-edit-btn {
            position: absolute;
            bottom: 2px; right: 2px;
            width: 30px; height: 30px;
            background: var(--accent);
            border: 2px solid var(--bg-main);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.75rem;
            color: white;
            transition: transform 0.2s;
        }

        .avatar-edit-btn:hover { transform: scale(1.15); }

        .hero-info { flex: 1; min-width: 0; }
        .hero-info h3 { font-weight: 800; font-size: 1.4rem; margin-bottom: 4px; }
        .hero-info .hero-email { color: var(--muted); font-size: 0.84rem; margin-bottom: 10px; }

        .hero-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.73rem;
            font-weight: 700;
        }

        .hb-red    { background: rgba(255,107,107,0.12); color: var(--accent); border: 1px solid rgba(255,107,107,0.25); }
        .hb-green  { background: rgba(107,203,119,0.12); color: var(--green);  border: 1px solid rgba(107,203,119,0.25); }
        .hb-blue   { background: rgba(0,212,255,0.12);   color: var(--blue);   border: 1px solid rgba(0,212,255,0.25); }

        .hero-meta { display: flex; gap: 18px; flex-wrap: wrap; }
        .hero-meta span { font-size: 0.78rem; color: var(--muted); display: flex; align-items: center; gap: 5px; }

        .hero-stats {
            display: flex;
            gap: 22px;
            flex-shrink: 0;
        }

        .hero-stat { text-align: center; }
        .hero-stat .val { font-size: 1.6rem; font-weight: 800; line-height: 1; }
        .hero-stat .lbl { font-size: 0.72rem; color: var(--muted); margin-top: 3px; }

        /* ════ Profile Nav Tabs ════ */
        .profile-nav {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0;
        }

        .profile-nav-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 10px 18px;
            border-radius: 10px 10px 0 0;
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--muted);
            background: transparent;
            border: 1px solid transparent;
            border-bottom: none;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: -1px;
        }

        .profile-nav-btn:hover { color: var(--text); background: var(--bg-hover); }

        .profile-nav-btn.active {
            color: var(--accent);
            background: var(--bg-card);
            border-color: var(--border);
            border-bottom-color: var(--bg-main);
        }

        /* ════ Section Card ════ */
        .section-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; margin-bottom: 22px; }
        .section-header { padding: 16px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .section-header h6 { font-weight: 700; font-size: 0.92rem; margin: 0; display: flex; align-items: center; gap: 9px; }
        .section-header p { color: var(--muted); font-size: 0.76rem; margin: 0; }
        .section-body { padding: 22px; }

        /* ════ Form Controls ════ */
        .form-label { font-size: 0.82rem; font-weight: 600; color: var(--text); margin-bottom: 6px; }
        .form-hint  { font-size: 0.72rem; color: var(--muted); margin-top: 4px; }

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
        textarea.form-control { resize: vertical; min-height: 90px; }

        /* ════ Buttons ════ */
        .btn-save {
            background: var(--accent); color: white; border: none; border-radius: 10px;
            padding: 10px 22px; font-size: 0.845rem; font-weight: 600; cursor: pointer;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 7px;
        }
        .btn-save:hover { background: #ff4444; transform: translateY(-1px); color: white; }

        .btn-outline {
            background: var(--bg-card); color: var(--muted); border: 1px solid var(--border);
            border-radius: 10px; padding: 10px 22px; font-size: 0.845rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center;
            gap: 7px; text-decoration: none;
        }
        .btn-outline:hover { border-color: var(--muted); color: var(--text); }

        .btn-danger-sm {
            background: rgba(255,107,107,0.08); color: var(--accent);
            border: 1px solid rgba(255,107,107,0.3); border-radius: 8px;
            padding: 7px 14px; font-size: 0.78rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-danger-sm:hover { background: rgba(255,107,107,0.18); }

        /* ════ Avatar Upload Modal ════ */
        .avatar-modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }

        .avatar-modal-overlay.open { display: flex; }

        .avatar-modal {
            background: #1a1a2e;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            width: 420px;
            max-width: 95vw;
        }

        .avatar-modal h5 { font-weight: 700; font-size: 1rem; margin-bottom: 4px; }
        .avatar-modal p  { color: var(--muted); font-size: 0.78rem; margin-bottom: 20px; }

        .avatar-drop-zone {
            border: 2px dashed var(--border);
            border-radius: 14px;
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            margin-bottom: 18px;
        }

        .avatar-drop-zone:hover,
        .avatar-drop-zone.dragover {
            border-color: var(--accent);
            background: rgba(255,107,107,0.04);
        }

        .avatar-drop-zone i { font-size: 2rem; color: var(--muted); margin-bottom: 10px; display: block; }
        .avatar-drop-zone p { color: var(--muted); font-size: 0.82rem; margin: 0; }
        .avatar-drop-zone strong { color: var(--accent); }

        #avatarPreview {
            width: 90px; height: 90px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid var(--accent);
            display: none;
            margin: 0 auto 12px;
        }

        /* ════ Alerts ════ */
        .alert-success-custom {
            background: rgba(107,203,119,0.1); border: 1px solid rgba(107,203,119,0.3);
            border-radius: 12px; padding: 13px 18px; color: var(--green);
            font-size: 0.845rem; font-weight: 500; display: flex; align-items: center;
            gap: 10px; margin-bottom: 20px;
        }

        .alert-error-custom {
            background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.3);
            border-radius: 12px; padding: 13px 18px; color: var(--accent);
            font-size: 0.845rem; font-weight: 500; display: flex; align-items: center;
            gap: 10px; margin-bottom: 20px;
        }

        /* ════ Info Box ════ */
        .info-box {
            background: rgba(0,212,255,0.06); border: 1px solid rgba(0,212,255,0.2);
            border-radius: 12px; padding: 14px 18px; color: var(--blue);
            font-size: 0.8rem; display: flex; gap: 10px; align-items: flex-start;
            margin-bottom: 20px;
        }
        .info-box i { flex-shrink: 0; margin-top: 1px; }

        /* ════ Password Strength ════ */
        .strength-bar { height: 4px; background: var(--border); border-radius: 2px; margin-top: 8px; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; width: 0%; }
        .strength-label { font-size: 0.7rem; color: var(--muted); margin-top: 4px; }

        /* ════ Activity Log ════ */
        .log-item {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 13px 22px;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }
        .log-item:last-child { border-bottom: none; }
        .log-item:hover { background: var(--bg-hover); }

        .log-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--accent);
            flex-shrink: 0;
            margin-top: 6px;
        }

        .log-action { font-size: 0.845rem; font-weight: 500; color: var(--text); margin-bottom: 2px; }
        .log-time   { font-size: 0.73rem; color: var(--muted); }

        /* ════ Profile Tab Panels ════ */
        .profile-panel { display: none; }
        .profile-panel.active { display: block; }

        /* ════ Read-only Info Row ════ */
        .info-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { font-size: 0.78rem; color: var(--muted); width: 130px; flex-shrink: 0; }
        .info-row .val { font-size: 0.855rem; font-weight: 500; color: var(--text); }

        /* ════ Divider ════ */
        .form-divider { border: none; border-top: 1px solid var(--border); margin: 20px 0; }

        /* ════ Responsive ════ */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .profile-hero { flex-direction: column; text-align: center; gap: 18px; }
            .hero-meta { justify-content: center; }
            .hero-stats { justify-content: center; }
            .hero-badges { justify-content: center; }
        }

        @media (max-width: 480px) {
            .hero-stats { gap: 14px; }
            .profile-nav-btn span { display: none; }
            .profile-nav-btn { padding: 10px 13px; }
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
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin['name']) ?>&background=ff6b6b&color=fff&size=46'"
             alt="Admin">
        <div>
            <div class="name"><?= htmlspecialchars($admin['name']) ?></div>
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
        <a href="manage_notifications.php">
            <i class="bi bi-bell"></i> Notifications
            <?php if ($total_notifications > 0): ?>
                <span class="nav-badge"><?= $total_notifications ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">System</div>
        <a href="settings.php"><i class="bi bi-gear"></i> Settings</a>

        <div class="nav-section">Account</div>
        <a href="profile.php" class="active"><i class="bi bi-person-circle"></i> My Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?')">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>

<!-- ════════════════════ MAIN ════════════════════ -->
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
            <a href="dashboard.php" class="topbar-btn d-none d-md-flex">
                <i class="bi bi-grid-1x2"></i> Dashboard
            </a>
            <a href="manage_notifications.php" class="notif-btn">
                <i class="bi bi-bell"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert-success-custom" id="alertBox">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert-error-custom" id="alertBox">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- ════ Profile Hero ════ -->
        <div class="profile-hero">
            <div class="hero-avatar-wrap">
                <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin['name']) ?>&background=ff6b6b&color=fff&size=100'"
                     class="hero-avatar" id="heroAvatar" alt="Profile">
                <div class="avatar-edit-btn" onclick="openAvatarModal()" title="Change photo">
                    <i class="bi bi-camera-fill"></i>
                </div>
            </div>

            <div class="hero-info">
                <h3><?= htmlspecialchars($admin['name']) ?></h3>
                <div class="hero-email"><?= htmlspecialchars($admin['email']) ?></div>
                <div class="hero-badges">
                    <span class="hero-badge hb-red"><i class="bi bi-shield-fill"></i> Administrator</span>
                    <span class="hero-badge hb-green"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Active</span>
                    <?php if (!empty($admin['phone'])): ?>
                        <span class="hero-badge hb-blue"><i class="bi bi-phone"></i> <?= htmlspecialchars($admin['phone']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="hero-meta">
                    <span><i class="bi bi-calendar3"></i> Joined <?= date('d M Y', strtotime($admin['created_at'])) ?></span>
                    <?php if (!empty($admin['address'])): ?>
                        <span><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($admin['address']) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-clock-history"></i> Last updated <?= $admin['updated_at'] ? date('d M Y', strtotime($admin['updated_at'])) : 'Never' ?></span>
                </div>
            </div>

            <div class="hero-stats d-none d-lg-flex">
                <div class="hero-stat">
                    <div class="val" style="color:var(--blue)"><?= $stat_users ?></div>
                    <div class="lbl">Users</div>
                </div>
                <div class="hero-stat">
                    <div class="val" style="color:var(--yellow)"><?= $stat_courses ?></div>
                    <div class="lbl">Courses</div>
                </div>
                <div class="hero-stat">
                    <div class="val" style="color:var(--purple)"><?= $stat_notifications_sent ?></div>
                    <div class="lbl">Sent</div>
                </div>
                <div class="hero-stat">
                    <div class="val" style="color:var(--green)"><?= $stat_logs_total ?></div>
                    <div class="lbl">Actions</div>
                </div>
            </div>
        </div>

        <!-- ════ Profile Nav ════ -->
        <div class="profile-nav">
            <button class="profile-nav-btn active" onclick="switchTab('info', this)">
                <i class="bi bi-person"></i> <span>Profile Info</span>
            </button>
            <button class="profile-nav-btn" onclick="switchTab('edit', this)">
                <i class="bi bi-pencil"></i> <span>Edit Profile</span>
            </button>
            <button class="profile-nav-btn" onclick="switchTab('password', this)">
                <i class="bi bi-key"></i> <span>Password</span>
            </button>
            <button class="profile-nav-btn" onclick="switchTab('activity', this)">
                <i class="bi bi-clock-history"></i> <span>Activity</span>
            </button>
        </div>

        <!-- ════════════════════════════════════════
             TAB 1: PROFILE INFO (read-only)
        ════════════════════════════════════════ -->
        <div class="profile-panel active" id="tab-info">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="section-card">
                        <div class="section-header">
                            <h6><i class="bi bi-person-vcard" style="color:var(--blue)"></i> Account Details</h6>
                            <button class="btn-danger-sm" onclick="switchTabByName('edit')" style="border-color:rgba(0,212,255,0.3);color:var(--blue);background:rgba(0,212,255,0.07);">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </div>
                        <div class="section-body">
                            <div class="info-row">
                                <span class="lbl">Full Name</span>
                                <span class="val"><?= htmlspecialchars($admin['name']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="lbl">Email Address</span>
                                <span class="val"><?= htmlspecialchars($admin['email']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="lbl">Phone Number</span>
                                <span class="val"><?= !empty($admin['phone']) ? htmlspecialchars($admin['phone']) : '<span style="color:var(--muted)">Not set</span>' ?></span>
                            </div>
                            <div class="info-row">
                                <span class="lbl">Address</span>
                                <span class="val"><?= !empty($admin['address']) ? htmlspecialchars($admin['address']) : '<span style="color:var(--muted)">Not set</span>' ?></span>
                            </div>
                            <div class="info-row">
                                <span class="lbl">Role</span>
                                <span class="val"><span class="hero-badge hb-red">Administrator</span></span>
                            </div>
                            <div class="info-row">
                                <span class="lbl">Account Status</span>
                                <span class="val"><span class="hero-badge hb-green">Active</span></span>
                            </div>
                            <div class="info-row">
                                <span class="lbl">Member Since</span>
                                <span class="val"><?= date('d F Y', strtotime($admin['created_at'])) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="lbl">Last Updated</span>
                                <span class="val"><?= $admin['updated_at'] ? date('d F Y, H:i', strtotime($admin['updated_at'])) : 'Never' ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($admin['bio'])): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <h6><i class="bi bi-chat-left-text" style="color:var(--purple)"></i> Bio</h6>
                        </div>
                        <div class="section-body">
                            <p style="font-size:0.875rem; color:var(--text); line-height:1.7; margin:0;">
                                <?= nl2br(htmlspecialchars($admin['bio'])) ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-5">
                    <!-- Profile Photo Card -->
                    <div class="section-card mb-4">
                        <div class="section-header">
                            <h6><i class="bi bi-image" style="color:var(--yellow)"></i> Profile Photo</h6>
                        </div>
                        <div class="section-body text-center">
                            <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin['name']) ?>&background=ff6b6b&color=fff&size=120'"
                                 style="width:120px;height:120px;border-radius:50%;object-fit:cover;
                                        border:3px solid var(--accent);margin-bottom:16px;"
                                 alt="Profile">
                            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                                <button class="btn-save" style="font-size:0.78rem;padding:8px 14px;"
                                        onclick="openAvatarModal()">
                                    <i class="bi bi-upload"></i> Change Photo
                                </button>
                                <?php if (!empty($admin['profile_picture']) && $admin['profile_picture'] !== 'default.png'): ?>
                                <form method="POST" onsubmit="return confirm('Remove profile picture?')" style="display:inline;">
                                    <input type="hidden" name="action" value="remove_picture">
                                    <button type="submit" class="btn-danger-sm">
                                        <i class="bi bi-trash3"></i> Remove
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:0.72rem;color:var(--muted);margin-top:10px;">
                                JPG, PNG, WEBP — max 2 MB
                            </p>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="section-card">
                        <div class="section-header">
                            <h6><i class="bi bi-bar-chart" style="color:var(--green)"></i> My Overview</h6>
                        </div>
                        <div class="section-body" style="padding:16px 22px;">
                            <?php
                            $quick_stats = [
                                ['lbl' => 'Active Users',         'val' => $stat_users,              'color' => 'var(--blue)',   'icon' => 'bi-people'],
                                ['lbl' => 'Active Courses',       'val' => $stat_courses,            'color' => 'var(--yellow)', 'icon' => 'bi-book'],
                                ['lbl' => 'Notifications Sent',   'val' => $stat_notifications_sent, 'color' => 'var(--purple)', 'icon' => 'bi-megaphone'],
                                ['lbl' => 'Total Actions Logged', 'val' => $stat_logs_total,         'color' => 'var(--green)',  'icon' => 'bi-clock-history'],
                            ];
                            foreach ($quick_stats as $qs): ?>
                                <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
                                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.05);
                                                display:flex;align-items:center;justify-content:center;
                                                color:<?= $qs['color'] ?>;font-size:0.9rem;flex-shrink:0;">
                                        <i class="bi <?= $qs['icon'] ?>"></i>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-size:0.78rem;color:var(--muted);"><?= $qs['lbl'] ?></div>
                                    </div>
                                    <div style="font-size:1.1rem;font-weight:800;color:<?= $qs['color'] ?>;">
                                        <?= $qs['val'] ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════
             TAB 2: EDIT PROFILE
        ════════════════════════════════════════ -->
        <div class="profile-panel" id="tab-edit">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-person-gear" style="color:var(--blue)"></i> Personal Information</h6>
                            <p class="mt-1">Update your name, contact details, and bio.</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Full Name <span style="color:var(--accent)">*</span></label>
                                <input type="text" class="form-control" name="name"
                                       value="<?= htmlspecialchars($admin['name']) ?>"
                                       placeholder="Your full name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address <span style="color:var(--accent)">*</span></label>
                                <input type="email" class="form-control" name="email"
                                       value="<?= htmlspecialchars($admin['email']) ?>"
                                       placeholder="your@email.com" required>
                                <div class="form-hint">Used to log in. Must be unique.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone"
                                       value="<?= htmlspecialchars($admin['phone'] ?? '') ?>"
                                       placeholder="+255 700 000 000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Address / Location</label>
                                <input type="text" class="form-control" name="address"
                                       value="<?= htmlspecialchars($admin['address'] ?? '') ?>"
                                       placeholder="City, Country">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Bio</label>
                                <textarea class="form-control" name="bio" rows="4"
                                          placeholder="A short description about yourself..."><?= htmlspecialchars($admin['bio'] ?? '') ?></textarea>
                                <div class="form-hint">Displayed on your profile card. Optional.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-floppy"></i> Save Changes
                    </button>
                    <a href="profile.php" class="btn-outline">
                        <i class="bi bi-x"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- ════════════════════════════════════════
             TAB 3: CHANGE PASSWORD
        ════════════════════════════════════════ -->
        <div class="profile-panel" id="tab-password">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">

                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <h6><i class="bi bi-key" style="color:var(--yellow)"></i> Change Password</h6>
                            <p class="mt-1">Keep your account secure with a strong, unique password.</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Current Password</label>
                                <div class="position-relative">
                                    <input type="password" class="form-control" name="current_password"
                                           id="currentPass" placeholder="Enter current password" required>
                                    <button type="button" onclick="togglePass('currentPass', this)"
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
                                    <button type="button" onclick="togglePass('newPass', this)"
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
                                    <button type="button" onclick="togglePass('confirmPass', this)"
                                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                                   background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-hint" id="matchHint" style="margin-top:8px;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="info-box">
                    <i class="bi bi-shield-check"></i>
                    <div>Use at least 8 characters mixing uppercase, lowercase, numbers and symbols.
                    Avoid passwords used on other sites.</div>
                </div>

                <button type="submit" class="btn-save">
                    <i class="bi bi-key"></i> Update Password
                </button>
            </form>
        </div>

        <!-- ════════════════════════════════════════
             TAB 4: ACTIVITY LOG
        ════════════════════════════════════════ -->
        <div class="profile-panel" id="tab-activity">
            <div class="section-card">
                <div class="section-header">
                    <h6><i class="bi bi-clock-history" style="color:var(--purple)"></i>
                        Recent Activity
                        <span style="background:rgba(180,143,252,0.12);color:var(--purple);
                                     border:1px solid rgba(180,143,252,0.25);border-radius:20px;
                                     padding:2px 10px;font-size:0.7rem;font-weight:700;">
                            <?= $stat_logs_total ?> total
                        </span>
                    </h6>
                    <span style="font-size:0.78rem;color:var(--muted);">Last 10 actions</span>
                </div>

                <?php if (mysqli_num_rows($activity_logs) > 0): ?>
                    <?php while ($log = mysqli_fetch_assoc($activity_logs)): ?>
                        <div class="log-item">
                            <div class="log-dot"></div>
                            <div style="flex:1; min-width:0;">
                                <div class="log-action"><?= htmlspecialchars($log['action']) ?></div>
                                <div class="log-time">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= date('d M Y, H:i', strtotime($log['created_at'])) ?>
                                </div>
                            </div>
                            <div style="font-size:0.72rem;color:var(--muted);flex-shrink:0;">
                                <?= date('d M', strtotime($log['created_at'])) ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding:40px;text-align:center;color:var(--muted);">
                        <i class="bi bi-clock-history" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                        <p style="font-size:0.84rem;margin:0;">No activity recorded yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- Mobile Overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99;">
</div>

<!-- ════ Avatar Upload Modal ════ -->
<div class="avatar-modal-overlay" id="avatarModal">
    <div class="avatar-modal">
        <h5><i class="bi bi-camera me-2" style="color:var(--accent)"></i>Change Profile Photo</h5>
        <p>Upload a clear photo. JPG, PNG, WEBP — max 2 MB.</p>

        <img id="avatarPreview" src="" alt="Preview">

        <form method="POST" enctype="multipart/form-data" id="avatarForm">
            <input type="hidden" name="action" value="upload_picture">

            <div class="avatar-drop-zone" id="dropZone" onclick="document.getElementById('avatarFile').click()">
                <i class="bi bi-cloud-arrow-up"></i>
                <p>Drag & drop an image here, or <strong>click to browse</strong></p>
            </div>

            <input type="file" name="profile_picture" id="avatarFile"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   style="display:none" onchange="previewAvatar(this)">

            <div style="display:flex;gap:10px;margin-top:6px;">
                <button type="submit" class="btn-save" style="flex:1;justify-content:center;">
                    <i class="bi bi-upload"></i> Upload Photo
                </button>
                <button type="button" class="btn-outline" onclick="closeAvatarModal()" style="flex:1;justify-content:center;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar ───────────────────────────────────────────────
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

// ── Profile tab switching ─────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.profile-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.profile-nav-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (btn) btn.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function switchTabByName(name) {
    const btn = [...document.querySelectorAll('.profile-nav-btn')]
        .find(b => b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + name + "'"));
    switchTab(name, btn);
}

// ── Password visibility ───────────────────────────────────
function togglePass(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
}

// ── Password strength ─────────────────────────────────────
function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8)           score++;
    if (val.length >= 12)          score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '0%',   color: 'transparent', text: 'Enter a password to check strength' },
        { pct: '20%',  color: '#ff6b6b',     text: 'Very weak' },
        { pct: '40%',  color: '#ffa500',     text: 'Weak' },
        { pct: '60%',  color: '#ffd93d',     text: 'Fair' },
        { pct: '80%',  color: '#6bcb77',     text: 'Strong' },
        { pct: '100%', color: '#20c997',     text: 'Very strong' },
    ];

    const lvl = levels[Math.min(score, 5)];
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent     = lvl.text;
    label.style.color     = lvl.color === 'transparent' ? 'var(--muted)' : lvl.color;
}

// ── Password match ────────────────────────────────────────
function checkMatch() {
    const np   = document.getElementById('newPass').value;
    const cp   = document.getElementById('confirmPass').value;
    const hint = document.getElementById('matchHint');
    if (!cp.length) { hint.textContent = ''; return; }
    if (np === cp) {
        hint.textContent = '✓ Passwords match';
        hint.style.color = 'var(--green)';
    } else {
        hint.textContent = '✗ Passwords do not match';
        hint.style.color = 'var(--accent)';
    }
}

// ── Avatar modal ──────────────────────────────────────────
function openAvatarModal() {
    document.getElementById('avatarModal').classList.add('open');
}

function closeAvatarModal() {
    document.getElementById('avatarModal').classList.remove('open');
    document.getElementById('avatarPreview').style.display = 'none';
    document.getElementById('avatarFile').value = '';
}

document.getElementById('avatarModal').addEventListener('click', function(e) {
    if (e.target === this) closeAvatarModal();
});

function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 2 * 1024 * 1024) {
        alert('File is too large. Maximum size is 2 MB.');
        input.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('avatarPreview');
        prev.src   = e.target.result;
        prev.style.display = 'block';
    };
    reader.readAsDataURL(file);
}

// Drag & drop
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', ()  => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    const fileInput  = document.getElementById('avatarFile');
    const dt = e.dataTransfer;
    if (dt.files.length) {
        const dtTrans = new DataTransfer();
        dtTrans.items.add(dt.files[0]);
        fileInput.files = dtTrans.files;
        previewAvatar(fileInput);
    }
});

// ── Alert auto-dismiss ────────────────────────────────────
const alertBox = document.getElementById('alertBox');
if (alertBox) {
    setTimeout(() => {
        alertBox.style.transition = 'opacity 0.5s';
        alertBox.style.opacity    = '0';
        setTimeout(() => alertBox.remove(), 500);
    }, 5000);
}

// ── Restore tab from URL hash ─────────────────────────────
const hash      = window.location.hash.replace('#', '');
const validTabs = ['info','edit','password','activity'];
if (validTabs.includes(hash)) switchTabByName(hash);

// ── Auto-switch to correct tab after form POST ────────────
<?php if ($success_msg || $error_msg): ?>
    <?php
    $active_tab = 'info';
    if ($_POST['action'] ?? '' === 'update_profile')   $active_tab = 'edit';
    if ($_POST['action'] ?? '' === 'change_password')  $active_tab = 'password';
    ?>
    switchTabByName('<?= $active_tab ?>');
<?php endif; ?>
</script>
</body>
</html>