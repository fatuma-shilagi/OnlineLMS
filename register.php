<?php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/session.php';

// If already logged in, redirect
if (isLoggedIn()) {
    redirectByRole();
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Sanitize inputs
    $name     = trim(mysqli_real_escape_string($conn, $_POST['name']));
    $email    = trim(mysqli_real_escape_string($conn, $_POST['email']));
    $phone    = trim(mysqli_real_escape_string($conn, $_POST['phone']));
    $role     = trim(mysqli_real_escape_string($conn, $_POST['role']));
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    // Validation
    if (empty($name)) {
        $errors[] = "Full name is required.";
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    }

    if (empty($phone)) {
        $errors[] = "Phone number is required.";
    }

    if (!in_array($role, ['student', 'lecturer'])) {
        $errors[] = "Please select a valid role.";
    }

    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }

    // Check if email already exists
    if (empty($errors)) {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email'");
        if (mysqli_num_rows($check) > 0) {
            $errors[] = "This email is already registered. Please login.";
        }
    }

    // Handle profile picture upload
    $profile_picture = 'profile-default.png';
    if (!empty($_FILES['profile_picture']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        $file_type     = $_FILES['profile_picture']['type'];
        $file_size     = $_FILES['profile_picture']['size'];
        $file_tmp      = $_FILES['profile_picture']['tmp_name'];

        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Profile picture must be JPG, PNG, or GIF.";
        } elseif ($file_size > 2 * 1024 * 1024) {
            $errors[] = "Profile picture must not exceed 2MB.";
        } else {
            $ext             = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $profile_picture = 'profile_' . time() . '_' . uniqid() . '.' . $ext;
            $upload_path     = 'uploads/profiles/' . $profile_picture;
            move_uploaded_file($file_tmp, $upload_path);
        }
    }

    // Insert into database
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $insert = "INSERT INTO users (name, email, password, role, phone, profile_picture, status)
                   VALUES ('$name', '$email', '$hashed_password', '$role', '$phone', '$profile_picture', 'active')";

        if (mysqli_query($conn, $insert)) {
            $new_user_id = mysqli_insert_id($conn);

            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'];
            mysqli_query($conn, "INSERT INTO activity_logs (user_id, action, module, details, ip_address)
                                 VALUES ('$new_user_id', 'Registered', 'Auth', 'New $role account created', '$ip')");

            $success = "Registration successful! You can now login.";
        } else {
            $errors[] = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - OnlineLMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e, #16213e, #0f3460);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 15px;
        }

        .register-wrapper {
            width: 100%;
            max-width: 650px;
        }

        .brand-logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .brand-logo h2 {
            color: #00d4ff;
            font-weight: 800;
            font-size: 1.8rem;
        }

        .brand-logo p {
            color: rgba(255,255,255,0.5);
            font-size: 0.9rem;
            margin: 0;
        }

        .register-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px 35px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }

        .register-card h4 {
            color: #ffffff;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .register-card p.subtitle {
            color: rgba(255,255,255,0.5);
            font-size: 0.9rem;
            margin-bottom: 25px;
        }

        .form-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 5px;
        }

        .form-control, .form-select {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            color: #ffffff;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(255,255,255,0.1);
            border-color: #00d4ff;
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.15);
            color: #ffffff;
            outline: none;
        }

        .form-control::placeholder {
            color: rgba(255,255,255,0.3);
        }

        .form-select option {
            background: #1a1a2e;
            color: #ffffff;
        }

        .input-group-text {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.5);
            border-radius: 10px 0 0 10px;
        }

        .input-group .form-control {
            border-radius: 0 10px 10px 0;
        }

        .input-group:focus-within .input-group-text {
            border-color: #00d4ff;
        }

        /* Role selection cards */
        .role-selector {
            display: flex;
            gap: 12px;
            margin-top: 5px;
        }

        .role-option {
            flex: 1;
            position: relative;
        }

        .role-option input[type="radio"] {
            display: none;
        }

        .role-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 15px 10px;
            border: 2px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.6);
            text-align: center;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .role-option label i {
            font-size: 1.6rem;
            margin-bottom: 6px;
        }

        .role-option input[type="radio"]:checked + label {
            border-color: #00d4ff;
            background: rgba(0, 212, 255, 0.12);
            color: #00d4ff;
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.2);
        }

        .role-option label:hover {
            border-color: rgba(0, 212, 255, 0.5);
            color: #ffffff;
        }

        /* Profile picture preview */
        .profile-upload-area {
            border: 2px dashed rgba(255,255,255,0.2);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .profile-upload-area:hover {
            border-color: #00d4ff;
            background: rgba(0, 212, 255, 0.05);
        }

        .profile-upload-area img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #00d4ff;
            margin-bottom: 10px;
        }

        .profile-upload-area p {
            color: rgba(255,255,255,0.4);
            font-size: 0.85rem;
            margin: 0;
        }

        .profile-upload-area input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        /* Password strength */
        .strength-bar {
            height: 4px;
            border-radius: 2px;
            margin-top: 6px;
            transition: all 0.3s;
            background: rgba(255,255,255,0.1);
        }

        .strength-text {
            font-size: 0.75rem;
            margin-top: 3px;
        }

        /* Password toggle */
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: rgba(255,255,255,0.4);
            z-index: 5;
            transition: color 0.2s;
        }

        .toggle-password:hover {
            color: #00d4ff;
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap .form-control {
            padding-right: 40px;
        }

        /* Submit button */
        .btn-register {
            background: linear-gradient(90deg, #00d4ff, #0099cc);
            border: none;
            padding: 13px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 12px;
            color: white;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 5px 20px rgba(0, 212, 255, 0.3);
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.5);
            color: white;
        }

        .btn-register:disabled {
            opacity: 0.6;
            transform: none;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
        }

        .divider hr {
            flex: 1;
            border-color: rgba(255,255,255,0.1);
        }

        .divider span {
            color: rgba(255,255,255,0.3);
            font-size: 0.8rem;
        }

        .login-link {
            text-align: center;
            color: rgba(255,255,255,0.5);
            font-size: 0.9rem;
        }

        .login-link a {
            color: #00d4ff;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        /* Alerts */
        .alert-glass-danger {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.4);
            color: #ff6b6b;
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 0.875rem;
        }

        .alert-glass-success {
            background: rgba(107, 203, 119, 0.15);
            border: 1px solid rgba(107, 203, 119, 0.4);
            color: #6bcb77;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            font-size: 0.95rem;
        }

        .section-divider {
            border-top: 1px solid rgba(255,255,255,0.07);
            margin: 25px 0;
        }

        .section-title {
            color: rgba(255,255,255,0.4);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

<div class="register-wrapper">

    <!-- Brand -->
    <div class="brand-logo">
        <h2><i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS</h2>
        <p>Online Notes, Assignment & Learning Management System</p>
    </div>

    <div class="register-card">

        <h4>Create Account</h4>
        <p class="subtitle">Fill in the details below to register your account.</p>

        <!-- Success Message -->
        <?php if ($success): ?>
            <div class="alert-glass-success mb-4">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars($success) ?>
                <div class="mt-2">
                    <a href="login.php" class="btn btn-sm" style="background:#6bcb77; color:white; border-radius:20px;">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Go to Login
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert-glass-danger mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Please fix the following:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST" enctype="multipart/form-data" id="registerForm">

            <!-- Profile Picture -->
            <div class="section-title">Profile Picture (Optional)</div>
            <div class="profile-upload-area mb-4" id="uploadArea">
                <input type="file" name="profile_picture" id="profileInput" accept="image/*">
                <img src="assets/images/profile-default.png"
                     id="profilePreview"
                     onerror="this.src='https://ui-avatars.com/api/?name=User&background=00d4ff&color=fff&size=80'">
                <p><i class="bi bi-camera me-1"></i>Click to upload photo (Max 2MB)</p>
            </div>

            <!-- Personal Info -->
            <div class="section-title">Personal Information</div>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <label class="form-label">
                        <i class="bi bi-person me-1"></i>Full Name <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text"
                               name="name"
                               class="form-control"
                               placeholder="Enter your full name"
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                               required>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        <i class="bi bi-envelope me-1"></i>Email Address <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email"
                               name="email"
                               class="form-control"
                               placeholder="you@example.com"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               required>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        <i class="bi bi-phone me-1"></i>Phone Number <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                        <input type="tel"
                               name="phone"
                               class="form-control"
                               placeholder="+255 7XX XXX XXX"
                               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                               required>
                    </div>
                </div>
            </div>

            <!-- Role Selection -->
            <div class="section-divider"></div>
            <div class="section-title">Select Your Role <span class="text-danger">*</span></div>
            <div class="role-selector mb-4">
                <div class="role-option">
                    <input type="radio" name="role" id="role_student" value="student"
                        <?= (($_POST['role'] ?? 'student') == 'student') ? 'checked' : '' ?>>
                    <label for="role_student">
                        <i class="bi bi-person-check"></i>
                        Student
                    </label>
                </div>
                <div class="role-option">
                    <input type="radio" name="role" id="role_lecturer" value="lecturer"
                        <?= (($_POST['role'] ?? '') == 'lecturer') ? 'checked' : '' ?>>
                    <label for="role_lecturer">
                        <i class="bi bi-person-video3"></i>
                        Lecturer
                    </label>
                </div>
            </div>

            <!-- Password -->
            <div class="section-divider"></div>
            <div class="section-title">Set Password</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">
                        Password <span class="text-danger">*</span>
                    </label>
                    <div class="password-wrap">
                        <input type="password"
                               name="password"
                               id="password"
                               class="form-control"
                               placeholder="Min. 6 characters"
                               required>
                        <i class="bi bi-eye toggle-password" id="togglePassword"></i>
                    </div>
                    <div class="strength-bar" id="strengthBar"></div>
                    <div class="strength-text" id="strengthText"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        Confirm Password <span class="text-danger">*</span>
                    </label>
                    <div class="password-wrap">
                        <input type="password"
                               name="confirm_password"
                               id="confirmPassword"
                               class="form-control"
                               placeholder="Repeat your password"
                               required>
                        <i class="bi bi-eye toggle-password" id="toggleConfirm"></i>
                    </div>
                    <div class="strength-text" id="matchText"></div>
                </div>
            </div>

            <!-- Terms -->
            <div class="mt-4 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="terms" required>
                    <label class="form-check-label" for="terms" style="color:rgba(255,255,255,0.6); font-size:0.875rem;">
                        I agree to the <a href="#" style="color:#00d4ff;">Terms of Service</a>
                        and <a href="#" style="color:#00d4ff;">Privacy Policy</a>
                    </label>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-register" id="submitBtn">
                <i class="bi bi-person-plus me-2"></i>Create Account
            </button>

        </form>
        <?php endif; ?>

        <div class="divider">
            <hr><span>already have an account?</span><hr>
        </div>

        <div class="login-link">
            <a href="login.php">
                <i class="bi bi-box-arrow-in-right me-1"></i>Login to your account
            </a>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── Profile picture preview ──────────────────────────
    document.getElementById('profileInput').addEventListener('change', function () {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('profilePreview').src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // ── Password strength checker ────────────────────────
    document.getElementById('password').addEventListener('input', function () {
        const val = this.value;
        const bar = document.getElementById('strengthBar');
        const txt = document.getElementById('strengthText');

        let strength = 0;
        if (val.length >= 6)  strength++;
        if (val.length >= 10) strength++;
        if (/[A-Z]/.test(val)) strength++;
        if (/[0-9]/.test(val)) strength++;
        if (/[^A-Za-z0-9]/.test(val)) strength++;

        const levels = [
            { color: '#ff6b6b', label: 'Very Weak',  width: '20%' },
            { color: '#ffa94d', label: 'Weak',        width: '40%' },
            { color: '#ffd93d', label: 'Fair',         width: '60%' },
            { color: '#6bcb77', label: 'Strong',       width: '80%' },
            { color: '#00d4ff', label: 'Very Strong',  width: '100%' }
        ];

        if (val.length === 0) {
            bar.style.width = '0';
            txt.textContent = '';
            return;
        }

        const lvl = levels[Math.min(strength - 1, 4)];
        bar.style.background = lvl.color;
        bar.style.width      = lvl.width;
        txt.style.color      = lvl.color;
        txt.textContent      = lvl.label;

        checkMatch();
    });

    // ── Password match checker ───────────────────────────
    function checkMatch() {
        const p1  = document.getElementById('password').value;
        const p2  = document.getElementById('confirmPassword').value;
        const txt = document.getElementById('matchText');

        if (p2.length === 0) { txt.textContent = ''; return; }

        if (p1 === p2) {
            txt.style.color   = '#6bcb77';
            txt.textContent   = '✓ Passwords match';
        } else {
            txt.style.color   = '#ff6b6b';
            txt.textContent   = '✗ Passwords do not match';
        }
    }

    document.getElementById('confirmPassword').addEventListener('input', checkMatch);

    // ── Toggle password visibility ───────────────────────
    document.getElementById('togglePassword').addEventListener('click', function () {
        const input = document.getElementById('password');
        const isText = input.type === 'text';
        input.type    = isText ? 'password' : 'text';
        this.className = `bi bi-eye${isText ? '' : '-slash'} toggle-password`;
    });

    document.getElementById('toggleConfirm').addEventListener('click', function () {
        const input = document.getElementById('confirmPassword');
        const isText = input.type === 'text';
        input.type    = isText ? 'password' : 'text';
        this.className = `bi bi-eye${isText ? '' : '-slash'} toggle-password`;
    });

    // ── Prevent double submit ────────────────────────────
    document.getElementById('registerForm')?.addEventListener('submit', function () {
        const btn = document.getElementById('submitBtn');
        btn.disabled     = true;
        btn.innerHTML    = '<span class="spinner-border spinner-border-sm me-2"></span>Creating Account...';
    });
</script>
</body>
</html>