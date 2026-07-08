<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/session.php';

// Already logged in → go to dashboard
if (isLoggedIn()) {
    redirectByRole();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email    = trim(mysqli_real_escape_string($conn, $_POST['email']));
    $password = $_POST['password'];

    $query  = "SELECT * FROM users WHERE email = '$email' AND status = 'active'";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);

        if (password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role']      = $user['role'];

            // Log activity
            $uid = $user['id'];
            $ip  = $_SERVER['REMOTE_ADDR'];
            $role = $user['role'];
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, module, details, ip_address)
                                 VALUES ('$uid', 'Logged in', 'Auth', '$role logged in', '$ip')");

            // Redirect based on role
            redirectByRole();

        } else {
            $error = "Invalid password. Please try again.";
        }
    } else {
        $error = "No account found with that email.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - OnlineLMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e, #16213e, #0f3460);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }

        .login-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 40px 35px;
            width: 100%;
            max-width: 430px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }

        .brand {
            text-align: center;
            margin-bottom: 30px;
        }

        .brand h2 {
            color: #00d4ff;
            font-weight: 800;
            font-size: 1.8rem;
        }

        .brand p {
            color: rgba(255,255,255,0.4);
            font-size: 0.85rem;
            margin: 0;
        }

        .form-label {
            color: rgba(255,255,255,0.75);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .form-control {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            color: #ffffff;
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            background: rgba(255,255,255,0.1);
            border-color: #00d4ff;
            box-shadow: 0 0 0 3px rgba(0,212,255,0.15);
            color: #ffffff;
        }

        .form-control::placeholder {
            color: rgba(255,255,255,0.25);
        }

        .input-group-text {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            border-right: none;
            color: rgba(255,255,255,0.4);
            border-radius: 10px 0 0 10px;
        }

        .input-group .form-control {
            border-radius: 0 10px 10px 0;
            border-left: none;
        }

        .input-group:focus-within .input-group-text {
            border-color: #00d4ff;
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap .form-control {
            padding-right: 42px;
        }

        .toggle-pass {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.35);
            cursor: pointer;
            z-index: 5;
            transition: color 0.2s;
        }

        .toggle-pass:hover { color: #00d4ff; }

        .btn-login {
            background: linear-gradient(90deg, #00d4ff, #0099cc);
            border: none;
            padding: 13px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 12px;
            color: white;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 5px 20px rgba(0,212,255,0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,212,255,0.5);
            color: white;
        }

        .alert-error {
            background: rgba(255,107,107,0.15);
            border: 1px solid rgba(255,107,107,0.35);
            color: #ff6b6b;
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 0.875rem;
            margin-bottom: 20px;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }

        .divider hr {
            flex: 1;
            border-color: rgba(255,255,255,0.1);
        }

        .divider span {
            color: rgba(255,255,255,0.25);
            font-size: 0.8rem;
        }

        .bottom-links {
            text-align: center;
            color: rgba(255,255,255,0.45);
            font-size: 0.875rem;
        }

        .bottom-links a {
            color: #00d4ff;
            text-decoration: none;
            font-weight: 600;
        }

        .bottom-links a:hover { text-decoration: underline; }

        /* Demo credentials box */
        .demo-box {
            background: rgba(0,212,255,0.06);
            border: 1px solid rgba(0,212,255,0.2);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 22px;
        }

        .demo-box p {
            color: rgba(255,255,255,0.5);
            font-size: 0.75rem;
            margin: 0 0 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .demo-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            cursor: pointer;
        }

        .demo-item:last-child { border-bottom: none; }

        .demo-item:hover .demo-email {
            color: #00d4ff;
        }

        .demo-role {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .role-admin    { background: rgba(255,107,107,0.15); color: #ff6b6b; }
        .role-lecturer { background: rgba(255,217,61,0.15);  color: #ffd93d; }
        .role-student  { background: rgba(107,203,119,0.15); color: #6bcb77; }

        .demo-email {
            color: rgba(255,255,255,0.6);
            font-size: 0.8rem;
            transition: color 0.2s;
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- Brand -->
    <div class="brand">
        <h2><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h2>
        <p>Online Notes, Assignment & Learning System</p>
    </div>

    <!-- Error -->
    <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    

    <!-- Login Form -->
    <form method="POST" id="loginForm">

        <!-- Email -->
        <div class="mb-3">
            <label class="form-label">
                <i class="bi bi-envelope me-1"></i>Email Address
            </label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email"
                       name="email"
                       id="emailInput"
                       class="form-control"
                       placeholder="Enter your email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       required>
            </div>
        </div>

        <!-- Password -->
        <div class="mb-4">
            <label class="form-label">
                <i class="bi bi-lock me-1"></i>Password
            </label>
            <div class="password-wrap">
                <input type="password"
                       name="password"
                       id="passwordInput"
                       class="form-control"
                       placeholder="Enter your password"
                       required>
                <i class="bi bi-eye toggle-pass" id="togglePass"></i>
            </div>
            <div class="text-end mt-1">
                <a href="forgot_password.php"
                   style="color:rgba(255,255,255,0.35); font-size:0.8rem; text-decoration:none;">
                    Forgot password?
                </a>
            </div>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn-login" id="loginBtn">
            <i class="bi bi-box-arrow-in-right me-2"></i>Login
        </button>

    </form>

    <div class="divider"><hr><span>new here?</span><hr></div>

    <div class="bottom-links">
        Don't have an account?
        <a href="register.php">Create Account</a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    

    // Toggle password visibility
    document.getElementById('togglePass').addEventListener('click', function () {
        const input  = document.getElementById('passwordInput');
        const isText = input.type === 'text';
        input.type   = isText ? 'password' : 'text';
        this.className = `bi bi-eye${isText ? '' : '-slash'} toggle-pass`;
    });

    // Prevent double submit
    document.getElementById('loginForm').addEventListener('submit', function () {
        const btn    = document.getElementById('loginBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Logging in...';
    });
</script>
</body>
</html>