<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

requireRole('admin');

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'];

$success = '';
$error   = '';

// ── Handle Add User ──────────────────────────────────────
if (isset($_POST['add_user'])) {
    $name     = trim(mysqli_real_escape_string($conn, $_POST['name']));
    $email    = trim(mysqli_real_escape_string($conn, $_POST['email']));
    $phone    = trim(mysqli_real_escape_string($conn, $_POST['phone']));
    $role     = $_POST['role'];
    $password = $_POST['password'];
    $status   = $_POST['status'];

    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $error = "Name, email, password and role are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif (!in_array($role, ['admin', 'lecturer', 'student'])) {
        $error = "Invalid role selected.";
    } else {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email'");
        if (mysqli_num_rows($check) > 0) {
            $error = "Email '$email' is already registered.";
        } else {
            $hashed   = password_hash($password, PASSWORD_DEFAULT);
            $pic      = 'profile-default.png';

            // Handle profile picture upload
            if (!empty($_FILES['profile_picture']['name'])) {
                $allowed = ['image/jpeg','image/png','image/jpg','image/gif'];
                if (in_array($_FILES['profile_picture']['type'], $allowed) &&
                    $_FILES['profile_picture']['size'] <= 2 * 1024 * 1024) {
                    $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $pic = 'profile_' . time() . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['profile_picture']['tmp_name'],
                                       '../uploads/profiles/' . $pic);
                } else {
                    $error = "Profile picture must be JPG/PNG under 2MB.";
                }
            }

            if (empty($error)) {
                $sql = "INSERT INTO users (name, email, password, role, phone, profile_picture, status)
                        VALUES ('$name','$email','$hashed','$role','$phone','$pic','$status')";
                if (mysqli_query($conn, $sql)) {
                    $new_id = mysqli_insert_id($conn);
                    $ip     = $_SERVER['REMOTE_ADDR'];
                    mysqli_query($conn, "INSERT INTO activity_logs
                                         (user_id, action, module, details, ip_address)
                                         VALUES ('$admin_id','Added user','Users',
                                                 'Added: $name ($role)','$ip')");
                    $success = "User '$name' added successfully!";
                } else {
                    $error = "Failed to add user. Please try again.";
                }
            }
        }
    }
}

// ── Handle Edit User ─────────────────────────────────────
if (isset($_POST['edit_user'])) {
    $id     = intval($_POST['user_id']);
    $name   = trim(mysqli_real_escape_string($conn, $_POST['name']));
    $email  = trim(mysqli_real_escape_string($conn, $_POST['email']));
    $phone  = trim(mysqli_real_escape_string($conn, $_POST['phone']));
    $role   = $_POST['role'];
    $status = $_POST['status'];

    if (empty($name) || empty($email)) {
        $error = "Name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } else {
        $check = mysqli_query($conn, "SELECT id FROM users
                                       WHERE email='$email' AND id != '$id'");
        if (mysqli_num_rows($check) > 0) {
            $error = "Email '$email' is used by another user.";
        } else {
            // Handle new password
            $pass_sql = '';
            if (!empty($_POST['new_password'])) {
                if (strlen($_POST['new_password']) < 6) {
                    $error = "New password must be at least 6 characters.";
                } else {
                    $hashed   = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                    $pass_sql = ", password = '$hashed'";
                }
            }

            // Handle new profile picture
            $pic_sql = '';
            if (!empty($_FILES['profile_picture']['name'])) {
                $allowed = ['image/jpeg','image/png','image/jpg','image/gif'];
                if (in_array($_FILES['profile_picture']['type'], $allowed) &&
                    $_FILES['profile_picture']['size'] <= 2 * 1024 * 1024) {
                    $ext     = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $pic     = 'profile_' . time() . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['profile_picture']['tmp_name'],
                                       '../uploads/profiles/' . $pic);
                    $pic_sql = ", profile_picture = '$pic'";
                } else {
                    $error = "Profile picture must be JPG/PNG under 2MB.";
                }
            }

            if (empty($error)) {
                $sql = "UPDATE users SET
                            name   = '$name',
                            email  = '$email',
                            phone  = '$phone',
                            role   = '$role',
                            status = '$status'
                            $pass_sql
                            $pic_sql
                        WHERE id = '$id'";
                if (mysqli_query($conn, $sql)) {
                    $ip = $_SERVER['REMOTE_ADDR'];
                    mysqli_query($conn, "INSERT INTO activity_logs
                                         (user_id, action, module, details, ip_address)
                                         VALUES ('$admin_id','Edited user','Users',
                                                 'Edited: $name','$ip')");
                    $success = "User '$name' updated successfully!";
                } else {
                    $error = "Failed to update user.";
                }
            }
        }
    }
}

// ── Handle Delete User ───────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    if ($del_id == $admin_id) {
        $error = "You cannot delete your own account.";
    } else {
        $uname = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT name FROM users WHERE id = '$del_id'")
        )['name'] ?? '';
        mysqli_query($conn, "DELETE FROM users WHERE id = '$del_id'");
        $ip = $_SERVER['REMOTE_ADDR'];
        mysqli_query($conn, "INSERT INTO activity_logs
                              (user_id, action, module, details, ip_address)
                              VALUES ('$admin_id','Deleted user','Users',
                                      'Deleted: $uname','$ip')");
        $success = "User '$uname' deleted successfully.";
    }
}

// ── Handle Toggle Status ─────────────────────────────────
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tog_id = intval($_GET['toggle']);
    if ($tog_id == $admin_id) {
        $error = "You cannot change your own status.";
    } else {
        $cur = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT status FROM users WHERE id = '$tog_id'")
        )['status'];
        $new_status = ($cur === 'active') ? 'suspended' : 'active';
        mysqli_query($conn, "UPDATE users SET status = '$new_status' WHERE id = '$tog_id'");
        $success = "User status changed to $new_status.";
    }
}

// ── Search & Filter ──────────────────────────────────────
$search        = isset($_GET['search']) ? trim(mysqli_real_escape_string($conn, $_GET['search'])) : '';
$filter_role   = isset($_GET['role'])   ? $_GET['role']   : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$page          = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$per_page      = 8;
$offset        = ($page - 1) * $per_page;

$where = "WHERE 1=1";
if ($search)        $where .= " AND (name LIKE '%$search%' OR email LIKE '%$search%' OR phone LIKE '%$search%')";
if ($filter_role)   $where .= " AND role = '$filter_role'";
if ($filter_status) $where .= " AND status = '$filter_status'";

$total_rows = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM users $where")
)['total'];

$total_pages = ceil($total_rows / $per_page);

$users = mysqli_query($conn,
    "SELECT * FROM users $where
     ORDER BY created_at DESC
     LIMIT $per_page OFFSET $offset"
);

// ── Summary counts ───────────────────────────────────────
$count_all      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM users"))['t'];
$count_students = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM users WHERE role='student'"))['t'];
$count_lecturers= mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM users WHERE role='lecturer'"))['t'];
$count_admins   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM users WHERE role='admin'"))['t'];
$count_suspended= mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM users WHERE status='suspended'"))['t'];
$count_new      = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as t FROM users
     WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"))['t'];

// ── Fetch admin info ─────────────────────────────────────
$admin = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM users WHERE id = '$admin_id'")
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - OnlineLMS</title>
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

        /* ── Sidebar ── */
        .sidebar {
            width: var(--sidebar-w);
            background: rgba(255,255,255,0.03);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0;
            height: 100vh; z-index: 100;
            transition: transform 0.3s;
            overflow-y: auto;
        }

        .sidebar-brand { padding: 22px 20px; border-bottom: 1px solid var(--border); }
        .sidebar-brand h5 { color: var(--accent); font-weight: 800; font-size: 1.15rem; margin: 0; }
        .sidebar-brand span { color: var(--muted); font-size: 0.72rem; }

        .sidebar-profile {
            padding: 18px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }

        .sidebar-profile img {
            width: 44px; height: 44px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--accent);
        }

        .sidebar-profile .name { font-weight: 600; font-size: 0.88rem; color: var(--text); }

        .role-badge {
            background: rgba(255,107,107,0.15); color: var(--accent);
            border: 1px solid rgba(255,107,107,0.3);
            border-radius: 20px; padding: 1px 10px;
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
            padding: 10px 20px; color: var(--muted);
            text-decoration: none; font-size: 0.875rem; font-weight: 500;
            border-left: 3px solid transparent; transition: all 0.2s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            color: var(--text); background: var(--bg-hover);
            border-left-color: var(--accent);
        }

        .sidebar-nav a.active { color: var(--accent); }
        .sidebar-nav a i { font-size: 1rem; width: 20px; }

        .nav-badge {
            margin-left: auto; background: var(--accent);
            color: white; border-radius: 20px;
            padding: 1px 8px; font-size: 0.68rem; font-weight: 700;
        }

        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a {
            display: flex; align-items: center; gap: 10px;
            color: var(--accent); text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            padding: 8px 0; transition: opacity 0.2s;
        }
        .sidebar-footer a:hover { opacity: 0.75; }

        /* ── Main ── */
        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1; display: flex; flex-direction: column; min-height: 100vh;
        }

        /* ── Topbar ── */
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

        .btn-add {
            background: var(--accent); border: none; border-radius: 10px;
            padding: 9px 18px; color: white; font-size: 0.82rem;
            font-weight: 600; text-decoration: none; cursor: pointer;
            display: flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-add:hover { background: #ff4444; color: white; transform: translateY(-1px); }

        .hamburger {
            display: none; background: var(--bg-card);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 8px 12px; color: var(--text); cursor: pointer;
        }

        /* ── Page Body ── */
        .page-body { padding: 26px; flex: 1; }

        /* ── Stat Cards ── */
        .stat-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 14px; padding: 16px;
            display: flex; align-items: center; gap: 12px;
            cursor: pointer; transition: all 0.2s; text-decoration: none; color: var(--text);
        }
        .stat-card:hover { background: var(--bg-hover); transform: translateY(-2px); color: var(--text); }
        .stat-card.active-filter { border-color: var(--accent); background: rgba(255,107,107,0.08); }

        .stat-icon {
            width: 44px; height: 44px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; flex-shrink: 0;
        }

        .si-blue   { background: rgba(0,212,255,0.15);   color: var(--blue);   }
        .si-green  { background: rgba(107,203,119,0.15); color: var(--green);  }
        .si-yellow { background: rgba(255,217,61,0.15);  color: var(--yellow); }
        .si-red    { background: rgba(255,107,107,0.15); color: var(--accent); }
        .si-purple { background: rgba(180,143,252,0.15); color: var(--purple); }
        .si-teal   { background: rgba(32,201,151,0.15);  color: #20c997;       }

        .stat-info h4 { font-size: 1.45rem; font-weight: 800; line-height: 1; margin-bottom: 2px; }
        .stat-info p  { color: var(--muted); font-size: 0.75rem; margin: 0; }

        /* ── Filter Bar ── */
        .filter-bar {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 14px; padding: 14px 18px;
            display: flex; align-items: center; gap: 10px;
            flex-wrap: wrap; margin-bottom: 20px;
        }

        .search-box {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 8px 13px; flex: 1; min-width: 200px;
        }
        .search-box i { color: var(--muted); font-size: 0.9rem; }
        .search-box input {
            background: none; border: none; outline: none;
            color: var(--text); font-size: 0.875rem; width: 100%;
        }
        .search-box input::placeholder { color: var(--muted); }

        .filter-select {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 8px 13px; color: var(--text); font-size: 0.875rem;
            cursor: pointer; outline: none;
        }
        .filter-select option { background: #1a1a2e; }

        .btn-filter {
            background: var(--accent); border: none; border-radius: 10px;
            padding: 9px 16px; color: white; font-size: 0.82rem;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
            display: flex; align-items: center; gap: 5px;
        }
        .btn-filter:hover { background: #ff4444; }

        .btn-clear {
            background: var(--bg-hover); border: 1px solid var(--border);
            border-radius: 10px; padding: 9px 13px; color: var(--muted);
            font-size: 0.82rem; cursor: pointer; text-decoration: none; transition: all 0.2s;
        }
        .btn-clear:hover { color: var(--text); }

        /* ── Table ── */
        .table-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden;
        }

        .table-header {
            padding: 15px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }

        .table-header h6 {
            font-weight: 700; font-size: 0.9rem; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }

        .table-responsive { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }

        thead th {
            padding: 11px 15px; text-align: left;
            font-size: 0.72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.8px;
            color: var(--muted); border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 12px 15px; font-size: 0.845rem;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--bg-hover); }

        /* ── User Avatar Cell ── */
        .user-cell { display: flex; align-items: center; gap: 10px; }

        .user-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--border);
            flex-shrink: 0;
        }

        .user-name { font-weight: 600; font-size: 0.855rem; margin-bottom: 1px; }
        .user-email { color: var(--muted); font-size: 0.75rem; }

        /* ── Role Badges ── */
        .role-pill {
            padding: 3px 11px; border-radius: 20px;
            font-size: 0.7rem; font-weight: 700;
            white-space: nowrap;
        }

        .role-admin    { background: rgba(255,107,107,0.15); color: var(--accent); border: 1px solid rgba(255,107,107,0.3); }
        .role-lecturer { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);  }
        .role-student  { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }

        /* ── Status Badges ── */
        .status-pill {
            padding: 3px 11px; border-radius: 20px;
            font-size: 0.7rem; font-weight: 600;
            white-space: nowrap;
        }

        .status-active    { background: rgba(107,203,119,0.15); color: var(--green);  border: 1px solid rgba(107,203,119,0.3); }
        .status-inactive  { background: rgba(255,217,61,0.15);  color: var(--yellow); border: 1px solid rgba(255,217,61,0.3);  }
        .status-suspended { background: rgba(255,107,107,0.15); color: var(--accent); border: 1px solid rgba(255,107,107,0.3); }

        /* ── Action Buttons ── */
        .action-btns { display: flex; gap: 5px; }

        .btn-icon {
            width: 31px; height: 31px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.82rem; cursor: pointer; border: none;
            text-decoration: none; transition: all 0.2s;
        }

        .btn-view   { background: rgba(180,143,252,0.12); color: var(--purple); }
        .btn-edit   { background: rgba(0,212,255,0.12);   color: var(--blue);   }
        .btn-toggle { background: rgba(255,217,61,0.12);  color: var(--yellow); }
        .btn-delete { background: rgba(255,107,107,0.12); color: var(--accent); }

        .btn-view:hover   { background: rgba(180,143,252,0.25); }
        .btn-edit:hover   { background: rgba(0,212,255,0.25);   }
        .btn-toggle:hover { background: rgba(255,217,61,0.25);  }
        .btn-delete:hover { background: rgba(255,107,107,0.25); }

        /* ── Pagination ── */
        .pagination-wrap {
            padding: 14px 20px; border-top: 1px solid var(--border);
            display: flex; align-items: center;
            justify-content: space-between; flex-wrap: wrap; gap: 10px;
        }

        .pagination-info { color: var(--muted); font-size: 0.78rem; }
        .pagination { display: flex; gap: 4px; }

        .page-btn {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem; text-decoration: none; transition: all 0.2s;
            border: 1px solid var(--border); color: var(--muted);
        }

        .page-btn:hover  { background: var(--bg-hover); color: var(--text); }
        .page-btn.active { background: var(--accent); border-color: var(--accent); color: white; }
        .page-btn.disabled { opacity: 0.3; pointer-events: none; }

        /* ── Alerts ── */
        .alert-success-glass {
            background: rgba(107,203,119,0.1); border: 1px solid rgba(107,203,119,0.3);
            color: var(--green); border-radius: 12px; padding: 12px 16px;
            font-size: 0.875rem; display: flex; align-items: center; gap: 8px;
            margin-bottom: 20px;
        }

        .alert-error-glass {
            background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.3);
            color: var(--accent); border-radius: 12px; padding: 12px 16px;
            font-size: 0.875rem; display: flex; align-items: center; gap: 8px;
            margin-bottom: 20px;
        }

        /* ── Modal ── */
        .modal-glass .modal-content {
            background: #1a1a2e; border: 1px solid var(--border);
            border-radius: 16px; color: var(--text);
        }

        .modal-glass .modal-header { border-bottom: 1px solid var(--border); padding: 17px 22px; }
        .modal-glass .modal-footer { border-top: 1px solid var(--border); padding: 13px 22px; }
        .modal-glass .modal-body   { padding: 22px; }
        .modal-glass .modal-title  { font-weight: 700; font-size: 0.95rem; }

        .form-label-glass {
            color: rgba(255,255,255,0.75); font-size: 0.84rem;
            font-weight: 500; margin-bottom: 5px; display: block;
        }

        .form-control-glass,
        .form-select-glass {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px; color: var(--text);
            padding: 9px 13px; font-size: 0.875rem;
            width: 100%; transition: all 0.3s; outline: none;
        }

        .form-control-glass:focus,
        .form-select-glass:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255,107,107,0.12);
            background: rgba(255,255,255,0.1); color: var(--text);
        }

        .form-control-glass::placeholder { color: rgba(255,255,255,0.25); }
        .form-select-glass option { background: #1a1a2e; }

        .btn-submit {
            background: var(--accent); border: none; border-radius: 10px;
            padding: 9px 22px; color: white; font-weight: 600;
            font-size: 0.875rem; cursor: pointer; transition: all 0.2s;
        }
        .btn-submit:hover { background: #ff4444; transform: translateY(-1px); }

        .btn-cancel {
            background: var(--bg-hover); border: 1px solid var(--border);
            border-radius: 10px; padding: 9px 18px; color: var(--muted);
            font-size: 0.875rem; cursor: pointer; transition: all 0.2s;
        }
        .btn-cancel:hover { color: var(--text); }

        /* ── Profile Upload ── */
        .profile-upload {
            border: 2px dashed rgba(255,255,255,0.15); border-radius: 12px;
            padding: 16px; text-align: center; cursor: pointer;
            transition: all 0.3s; position: relative;
        }
        .profile-upload:hover { border-color: var(--accent); }

        .profile-upload input[type="file"] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer;
        }

        .profile-upload img {
            width: 65px; height: 65px; border-radius: 50%;
            object-fit: cover; border: 3px solid var(--accent); margin-bottom: 8px;
        }

        .profile-upload p { color: var(--muted); font-size: 0.78rem; margin: 0; }

        /* ── View Modal ── */
        .view-avatar {
            width: 80px; height: 80px; border-radius: 50%;
            object-fit: cover; border: 3px solid var(--accent);
        }

        .info-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 0; border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { color: var(--muted); font-size: 0.8rem; }
        .info-row .value { font-weight: 500; color: var(--text); }

        /* ── Empty State ── */
        .empty-state {
            padding: 50px 20px; text-align: center; color: var(--muted);
        }
        .empty-state i { font-size: 2.5rem; opacity: 0.3; display: block; margin-bottom: 10px; }
        .empty-state p { font-size: 0.875rem; margin: 0; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .page-body { padding: 16px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>

<!-- ════════════════════════
     SIDEBAR
════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h5>
        <span>Admin Control Panel</span>
    </div>

    <div class="sidebar-profile">
        <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_picture']) ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=ff6b6b&color=fff&size=44'"
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
        <a href="manage_users.php" class="active"><i class="bi bi-people"></i> Manage Users
            <?php if ($count_new > 0): ?>
                <span class="nav-badge"><?= $count_new ?> new</span>
            <?php endif; ?>
        </a>
        <a href="manage_courses.php"><i class="bi bi-book"></i> Manage Courses</a>
        <a href="manage_notes.php"><i class="bi bi-file-earmark-text"></i> Manage Notes</a>
        <a href="manage_assignments.php"><i class="bi bi-clipboard2-check"></i> Manage Assignments</a>
        <a href="manage_notifications.php"><i class="bi bi-bell"></i> Notifications</a>

        <div class="nav-section">System</div>
        <a href="settings.php"><i class="bi bi-gear"></i> Settings</a>

        <div class="nav-section">Account</div>
        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" onclick="return confirm('Logout?')">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>

<!-- ════════════════════════
     MAIN CONTENT
════════════════════════ -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
            <div class="topbar-left">
                <h6>Manage Users</h6>
                <p><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-add"
                    data-bs-toggle="modal"
                    data-bs-target="#addUserModal">
                <i class="bi bi-person-plus"></i> Add User
            </button>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert-success-glass">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-error-glass">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- ── Stat Cards (clickable filters) ── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2">
                <a href="manage_users.php"
                   class="stat-card <?= !$filter_role && !$filter_status ? 'active-filter' : '' ?>">
                    <div class="stat-icon si-blue"><i class="bi bi-people"></i></div>
                    <div class="stat-info">
                        <h4><?= $count_all ?></h4>
                        <p>All Users</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-2">
                <a href="manage_users.php?role=student"
                   class="stat-card <?= $filter_role=='student' ? 'active-filter' : '' ?>">
                    <div class="stat-icon si-green"><i class="bi bi-person-check"></i></div>
                    <div class="stat-info">
                        <h4><?= $count_students ?></h4>
                        <p>Students</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-2">
                <a href="manage_users.php?role=lecturer"
                   class="stat-card <?= $filter_role=='lecturer' ? 'active-filter' : '' ?>">
                    <div class="stat-icon si-yellow"><i class="bi bi-person-video3"></i></div>
                    <div class="stat-info">
                        <h4><?= $count_lecturers ?></h4>
                        <p>Lecturers</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-2">
                <a href="manage_users.php?role=admin"
                   class="stat-card <?= $filter_role=='admin' ? 'active-filter' : '' ?>">
                    <div class="stat-icon si-red"><i class="bi bi-person-gear"></i></div>
                    <div class="stat-info">
                        <h4><?= $count_admins ?></h4>
                        <p>Admins</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-2">
                <a href="manage_users.php?status=suspended"
                   class="stat-card <?= $filter_status=='suspended' ? 'active-filter' : '' ?>">
                    <div class="stat-icon si-purple"><i class="bi bi-slash-circle"></i></div>
                    <div class="stat-info">
                        <h4><?= $count_suspended ?></h4>
                        <p>Suspended</p>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-icon si-teal"><i class="bi bi-person-plus"></i></div>
                    <div class="stat-info">
                        <h4><?= $count_new ?></h4>
                        <p>New This Month</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Search & Filter ── -->
        <form method="GET" action="">
            <div class="filter-bar">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text"
                           name="search"
                           placeholder="Search by name, email or phone..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="role" class="filter-select">
                    <option value="">All Roles</option>
                    <option value="admin"    <?= $filter_role=='admin'    ? 'selected' : '' ?>>Admin</option>
                    <option value="lecturer" <?= $filter_role=='lecturer' ? 'selected' : '' ?>>Lecturer</option>
                    <option value="student"  <?= $filter_role=='student'  ? 'selected' : '' ?>>Student</option>
                </select>
                <select name="status" class="filter-select">
                    <option value="">All Status</option>
                    <option value="active"    <?= $filter_status=='active'    ? 'selected' : '' ?>>Active</option>
                    <option value="inactive"  <?= $filter_status=='inactive'  ? 'selected' : '' ?>>Inactive</option>
                    <option value="suspended" <?= $filter_status=='suspended' ? 'selected' : '' ?>>Suspended</option>
                </select>
                <button type="submit" class="btn-filter">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <?php if ($search || $filter_role || $filter_status): ?>
                    <a href="manage_users.php" class="btn-clear">
                        <i class="bi bi-x"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- ── Users Table ── -->
        <div class="table-card">
            <div class="table-header">
                <h6>
                    <i class="bi bi-people" style="color:var(--accent)"></i>
                    All Users
                    <span style="color:var(--muted); font-weight:400;">(<?= $total_rows ?>)</span>
                </h6>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($users) > 0):
                              $sn = $offset + 1;
                              while ($user = mysqli_fetch_assoc($users)): ?>
                            <tr>
                                <td style="color:var(--muted)"><?= $sn++ ?></td>

                                <!-- User Info -->
                                <td>
                                    <div class="user-cell">
                                        <img src="../uploads/profiles/<?= htmlspecialchars($user['profile_picture']) ?>"
                                             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($user['name']) ?>&background=random&color=fff&size=38'"
                                             class="user-avatar" alt="User">
                                        <div>
                                            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                                            <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Phone -->
                                <td style="color:var(--muted); font-size:0.82rem;">
                                    <?= htmlspecialchars($user['phone'] ?? '—') ?>
                                </td>

                                <!-- Role -->
                                <td>
                                    <span class="role-pill role-<?= $user['role'] ?>">
                                        <?= ucfirst($user['role']) ?>
                                    </span>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="status-pill status-<?= $user['status'] ?>">
                                        <?= ucfirst($user['status']) ?>
                                    </span>
                                </td>

                                <!-- Joined -->
                                <td style="color:var(--muted); font-size:0.8rem;">
                                    <?= date('d M Y', strtotime($user['created_at'])) ?>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div class="action-btns">
                                        <!-- View -->
                                        <button class="btn-icon btn-view"
                                                title="View"
                                                onclick="openViewModal(
                                                    '<?= addslashes($user['name']) ?>',
                                                    '<?= addslashes($user['email']) ?>',
                                                    '<?= addslashes($user['phone'] ?? '') ?>',
                                                    '<?= $user['role'] ?>',
                                                    '<?= $user['status'] ?>',
                                                    '<?= $user['created_at'] ?>',
                                                    '<?= htmlspecialchars($user['profile_picture']) ?>'
                                                )">
                                            <i class="bi bi-eye"></i>
                                        </button>

                                        <!-- Edit -->
                                        <button class="btn-icon btn-edit"
                                                title="Edit"
                                                onclick="openEditModal(
                                                    <?= $user['id'] ?>,
                                                    '<?= addslashes($user['name']) ?>',
                                                    '<?= addslashes($user['email']) ?>',
                                                    '<?= addslashes($user['phone'] ?? '') ?>',
                                                    '<?= $user['role'] ?>',
                                                    '<?= $user['status'] ?>',
                                                    '<?= htmlspecialchars($user['profile_picture']) ?>'
                                                )">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- Toggle Status -->
                                        <?php if ($user['id'] != $admin_id): ?>
                                            <a href="manage_users.php?toggle=<?= $user['id'] ?>&search=<?= $search ?>&role=<?= $filter_role ?>&status=<?= $filter_status ?>&page=<?= $page ?>"
                                               class="btn-icon btn-toggle"
                                               title="<?= $user['status']==='active' ? 'Suspend' : 'Activate' ?>"
                                               onclick="return confirm('Change user status?')">
                                                <i class="bi bi-<?= $user['status']==='active' ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Delete -->
                                        <?php if ($user['id'] != $admin_id): ?>
                                            <a href="manage_users.php?delete=<?= $user['id'] ?>&search=<?= $search ?>&role=<?= $filter_role ?>&status=<?= $filter_status ?>&page=<?= $page ?>"
                                               class="btn-icon btn-delete"
                                               title="Delete"
                                               onclick="return confirm('Delete this user permanently?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="bi bi-people"></i>
                                        <p>No users found. <?= $search ? 'Try a different search.' : '' ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-wrap">
                    <div class="pagination-info">
                        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?>
                        of <?= $total_rows ?> users
                    </div>
                    <div class="pagination">
                        <a href="?page=<?= max(1,$page-1) ?>&search=<?= $search ?>&role=<?= $filter_role ?>&status=<?= $filter_status ?>"
                           class="page-btn <?= $page<=1 ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>&search=<?= $search ?>&role=<?= $filter_role ?>&status=<?= $filter_status ?>"
                               class="page-btn <?= $i==$page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <a href="?page=<?= min($total_pages,$page+1) ?>&search=<?= $search ?>&role=<?= $filter_role ?>&status=<?= $filter_status ?>"
                           class="page-btn <?= $page>=$total_pages ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- end page-body -->
</div><!-- end main-content -->

<!-- ══════════════════════════════
     ADD USER MODAL
══════════════════════════════ -->
<div class="modal fade modal-glass" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus me-2" style="color:var(--accent)"></i>Add New User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Profile Picture -->
                        <div class="col-12 text-center">
                            <div class="profile-upload" id="addUploadArea">
                                <input type="file" name="profile_picture"
                                       id="addProfileInput" accept="image/*">
                                <img src="../assets/images/profile-default.png"
                                     id="addProfilePreview"
                                     onerror="this.src='https://ui-avatars.com/api/?name=User&background=ff6b6b&color=fff&size=65'">
                                <p><i class="bi bi-camera me-1"></i>Click to upload photo</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-glass">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control-glass"
                                   placeholder="Enter full name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control-glass"
                                   placeholder="user@example.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Phone Number</label>
                            <input type="tel" name="phone" class="form-control-glass"
                                   placeholder="+255 7XX XXX XXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select-glass" required>
                                <option value="">-- Select Role --</option>
                                <option value="student">Student</option>
                                <option value="lecturer">Lecturer</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Password <span class="text-danger">*</span></label>
                            <div style="position:relative;">
                                <input type="password" name="password" id="addPassword"
                                       class="form-control-glass" placeholder="Min. 6 characters"
                                       required style="padding-right:40px;">
                                <i class="bi bi-eye" id="toggleAddPass"
                                   style="position:absolute; right:12px; top:50%;
                                          transform:translateY(-50%); cursor:pointer;
                                          color:rgba(255,255,255,0.35);"></i>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Status</label>
                            <select name="status" class="form-select-glass">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_user" class="btn-submit">
                        <i class="bi bi-person-plus me-1"></i> Add User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     EDIT USER MODAL
══════════════════════════════ -->
<div class="modal fade modal-glass" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2" style="color:var(--blue)"></i>Edit User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Profile Picture -->
                        <div class="col-12 text-center">
                            <div class="profile-upload" id="editUploadArea">
                                <input type="file" name="profile_picture"
                                       id="editProfileInput" accept="image/*">
                                <img src="" id="editProfilePreview"
                                     onerror="this.src='https://ui-avatars.com/api/?name=User&background=ff6b6b&color=fff&size=65'">
                                <p><i class="bi bi-camera me-1"></i>Click to change photo</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-glass">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name"
                                   class="form-control-glass" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="edit_email"
                                   class="form-control-glass" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Phone Number</label>
                            <input type="tel" name="phone" id="edit_phone"
                                   class="form-control-glass">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Role</label>
                            <select name="role" id="edit_role" class="form-select-glass">
                                <option value="student">Student</option>
                                <option value="lecturer">Lecturer</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">Status</label>
                            <select name="status" id="edit_status" class="form-select-glass">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-glass">New Password
                                <span style="color:var(--muted); font-size:0.75rem;">(leave blank to keep)</span>
                            </label>
                            <div style="position:relative;">
                                <input type="password" name="new_password" id="editPassword"
                                       class="form-control-glass" placeholder="Enter new password"
                                       style="padding-right:40px;">
                                <i class="bi bi-eye" id="toggleEditPass"
                                   style="position:absolute; right:12px; top:50%;
                                          transform:translateY(-50%); cursor:pointer;
                                          color:rgba(255,255,255,0.35);"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_user" class="btn-submit"
                            style="background:var(--blue);">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     VIEW USER MODAL
══════════════════════════════ -->
<div class="modal fade modal-glass" id="viewUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-circle me-2" style="color:var(--purple)"></i>User Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <img id="view_avatar" src="" class="view-avatar"
                         onerror="this.src='https://ui-avatars.com/api/?name=User&background=random&color=fff&size=80'"
                         alt="User">
                    <h5 id="view_name" class="mt-3 mb-1" style="font-weight:700;"></h5>
                    <span id="view_role_badge" class="role-pill"></span>
                </div>
                <div>
                    <div class="info-row">
                        <span class="label"><i class="bi bi-envelope me-1"></i>Email</span>
                        <span class="value" id="view_email"></span>
                    </div>
                    <div class="info-row">
                        <span class="label"><i class="bi bi-phone me-1"></i>Phone</span>
                        <span class="value" id="view_phone"></span>
                    </div>
                    <div class="info-row">
                        <span class="label"><i class="bi bi-shield-check me-1"></i>Status</span>
                        <span id="view_status"></span>
                    </div>
                    <div class="info-row">
                        <span class="label"><i class="bi bi-calendar me-1"></i>Joined</span>
                        <span class="value" id="view_joined"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.5); z-index:99;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── Sidebar ──────────────────────────────────────────
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

    // ── Add Profile Preview ──────────────────────────────
    document.getElementById('addProfileInput').addEventListener('change', function () {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('addProfilePreview').src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // ── Edit Profile Preview ─────────────────────────────
    document.getElementById('editProfileInput').addEventListener('change', function () {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('editProfilePreview').src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // ── Open Edit Modal ──────────────────────────────────
    function openEditModal(id, name, email, phone, role, status, pic) {
        document.getElementById('edit_user_id').value = id;
        document.getElementById('edit_name').value    = name;
        document.getElementById('edit_email').value   = email;
        document.getElementById('edit_phone').value   = phone;
        document.getElementById('edit_role').value    = role;
        document.getElementById('edit_status').value  = status;
        document.getElementById('editProfilePreview').src =
            '../uploads/profiles/' + pic;
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }

    // ── Open View Modal ──────────────────────────────────
    function openViewModal(name, email, phone, role, status, joined, pic) {
        document.getElementById('view_name').textContent   = name;
        document.getElementById('view_email').textContent  = email;
        document.getElementById('view_phone').textContent  = phone || '—';
        document.getElementById('view_joined').textContent = joined;
        document.getElementById('view_avatar').src =
            '../uploads/profiles/' + pic;

        // Role badge
        const roleColors = {
            admin:    'role-admin',
            lecturer: 'role-lecturer',
            student:  'role-student'
        };
        const rb = document.getElementById('view_role_badge');
        rb.textContent  = role.charAt(0).toUpperCase() + role.slice(1);
        rb.className    = 'role-pill ' + (roleColors[role] || '');

        // Status badge
        const statusColors = {
            active:    'status-active',
            inactive:  'status-inactive',
            suspended: 'status-suspended'
        };
        const sb = document.getElementById('view_status');
        sb.innerHTML = `<span class="status-pill ${statusColors[status] || ''}">
                            ${status.charAt(0).toUpperCase() + status.slice(1)}
                        </span>`;

        new bootstrap.Modal(document.getElementById('viewUserModal')).show();
    }

    // ── Password Toggles ─────────────────────────────────
    document.getElementById('toggleAddPass').addEventListener('click', function () {
        const inp  = document.getElementById('addPassword');
        const show = inp.type === 'text';
        inp.type   = show ? 'password' : 'text';
        this.className = `bi bi-eye${show ? '' : '-slash'}`;
    });

    document.getElementById('toggleEditPass').addEventListener('click', function () {
        const inp  = document.getElementById('editPassword');
        const show = inp.type === 'text';
        inp.type   = show ? 'password' : 'text';
        this.className = `bi bi-eye${show ? '' : '-slash'}`;
    });

    // ── Auto open modal on error ─────────────────────────
    <?php if ($error && isset($_POST['add_user'])): ?>
        window.addEventListener('load', () => {
            new bootstrap.Modal(document.getElementById('addUserModal')).show();
        });
    <?php endif; ?>

    <?php if ($error && isset($_POST['edit_user'])): ?>
        window.addEventListener('load', () => {
            new bootstrap.Modal(document.getElementById('editUserModal')).show();
        });
    <?php endif; ?>
</script>
</body>
</html>