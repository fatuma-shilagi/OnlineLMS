<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/session.php';

// ONLY redirect if already logged in
// Otherwise STOP and show the landing page HTML below
if (isLoggedIn()) {
    redirectByRole();
}

// ── If we reach here = NOT logged in = show landing page ──
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Online Notes LMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e, #16213e, #0f3460);
            min-height: 100vh;
            color: white;
        }

        .navbar {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: #00d4ff !important;
        }

        .hero-section {
            padding: 100px 0 60px;
            text-align: center;
        }

        .hero-section h1 {
            font-size: 3rem;
            font-weight: 800;
            color: #ffffff;
            text-shadow: 0 0 20px rgba(0, 212, 255, 0.5);
        }

        .hero-section h1 span {
            color: #00d4ff;
        }

        .hero-section p {
            font-size: 1.2rem;
            color: rgba(255,255,255,0.7);
            max-width: 600px;
            margin: 20px auto;
        }

        .btn-get-started {
            background: linear-gradient(90deg, #00d4ff, #0099cc);
            border: none;
            padding: 14px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 5px 20px rgba(0, 212, 255, 0.4);
            display: inline-block;
        }

        .btn-get-started:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.6);
            color: white;
        }

        .btn-register {
            background: transparent;
            border: 2px solid #00d4ff;
            padding: 12px 35px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            color: #00d4ff;
            text-decoration: none;
            margin-left: 15px;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-register:hover {
            background: #00d4ff;
            color: white;
            transform: translateY(-3px);
        }

        .features-section {
            padding: 60px 0;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 35px 25px;
            text-align: center;
            transition: all 0.3s;
            height: 100%;
        }

        .feature-card:hover {
            background: rgba(0, 212, 255, 0.1);
            border-color: #00d4ff;
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.2);
        }

        .feature-icon {
            font-size: 2.5rem;
            color: #00d4ff;
            margin-bottom: 15px;
        }

        .feature-card h5 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #ffffff;
        }

        .feature-card p {
            color: rgba(255,255,255,0.6);
            font-size: 0.95rem;
        }

        .roles-section {
            padding: 60px 0;
        }

        .roles-section h2 {
            text-align: center;
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 40px;
            color: #ffffff;
        }

        .role-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
        }

        .role-card.admin:hover    { border-color: #ff6b6b; background: rgba(255,107,107,0.1); }
        .role-card.lecturer:hover { border-color: #ffd93d; background: rgba(255,217,61,0.1);  }
        .role-card.student:hover  { border-color: #6bcb77; background: rgba(107,203,119,0.1); }

        .role-icon { font-size: 3rem; margin-bottom: 15px; }

        .role-card.admin .role-icon    { color: #ff6b6b; }
        .role-card.lecturer .role-icon { color: #ffd93d; }
        .role-card.student .role-icon  { color: #6bcb77; }

        .role-card h5 {
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .role-card ul {
            list-style: none;
            padding: 0;
            color: rgba(255,255,255,0.65);
            font-size: 0.9rem;
            text-align: left;
        }

        .role-card ul li::before {
            content: "✓ ";
            color: #00d4ff;
            font-weight: bold;
        }

        .stats-section {
            padding: 50px 0;
            background: rgba(255,255,255,0.03);
            border-top: 1px solid rgba(255,255,255,0.07);
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }

        .stat-item { text-align: center; }

        .stat-item h3 {
            font-size: 2.5rem;
            font-weight: 800;
            color: #00d4ff;
        }

        .stat-item p {
            color: rgba(255,255,255,0.6);
            margin: 0;
        }

        footer {
            background: rgba(0,0,0,0.3);
            border-top: 1px solid rgba(255,255,255,0.07);
            padding: 25px 0;
            text-align: center;
            color: rgba(255,255,255,0.4);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-mortarboard-fill me-2"></i>OnlineLMS
        </a>
        <div class="ms-auto">
            <a href="login.php" class="btn btn-outline-light btn-sm me-2">
                <i class="bi bi-box-arrow-in-right me-1"></i>Login
            </a>
            <a href="register.php" class="btn btn-sm"
               style="background:#00d4ff; color:white; border-radius:20px;">
                <i class="bi bi-person-plus me-1"></i>Register
            </a>
        </div>
    </div>
</nav>

<!-- ── Hero Section ── -->
<section class="hero-section">
    <div class="container">
        <div class="mb-3">
            <span class="badge"
                  style="background:rgba(0,212,255,0.15); color:#00d4ff;
                         border:1px solid #00d4ff; padding:8px 20px;
                         border-radius:50px; font-size:0.9rem;">
                <i class="bi bi-stars me-1"></i> Smart Learning Platform
            </span>
        </div>
        <h1>Online <span>Notes, Assignments</span><br>&amp; Learning System</h1>
        <p>A complete Learning Management System for students, lecturers,
           and administrators. Access notes, submit assignments, and stay
           updated with notifications — all in one place.</p>
        <div class="mt-4">
            <!-- ✅ This button goes to login.php -->
            <a href="login.php" class="btn-get-started">
                <i class="bi bi-box-arrow-in-right me-2"></i>Get Started
            </a>
            <!-- ✅ This button goes to register.php -->
            <a href="register.php" class="btn-register">
                <i class="bi bi-person-plus me-2"></i>Register
            </a>
        </div>
    </div>
</section>

<!-- ── Features Section ── -->
<section class="features-section">
    <div class="container">
        <h2 class="text-center fw-bold mb-5">
            Why Use <span style="color:#00d4ff;">OnlineLMS?</span>
        </h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-file-earmark-text"></i></div>
                    <h5>Notes Management</h5>
                    <p>Lecturers upload course notes. Students download and study them anytime, from anywhere.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-clipboard2-check"></i></div>
                    <h5>Assignment System</h5>
                    <p>Create, submit, and grade assignments online with deadline tracking and file uploads.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-bell"></i></div>
                    <h5>Notifications</h5>
                    <p>Stay informed with real-time notifications for new notes, assignments, and announcements.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-bar-chart-line"></i></div>
                    <h5>Reports &amp; Analytics</h5>
                    <p>Admins and lecturers can generate detailed reports on student performance and activity.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-shield-lock"></i></div>
                    <h5>Secure Access</h5>
                    <p>Role-based authentication ensures each user only sees what they are allowed to access.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-phone"></i></div>
                    <h5>Responsive Design</h5>
                    <p>Fully mobile-friendly interface built with Bootstrap for access on any device.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Roles Section ── -->
<section class="roles-section">
    <div class="container">
        <h2>Who Uses <span style="color:#00d4ff;">OnlineLMS?</span></h2>
        <div class="row g-4">

            <div class="col-md-4">
                <div class="role-card admin">
                    <div class="role-icon"><i class="bi bi-person-gear"></i></div>
                    <h5>Administrator</h5>
                    <ul>
                        <li>Manage all users</li>
                        <li>Manage courses</li>
                        <li>View system reports</li>
                        <li>Configure settings</li>
                        <li>Send notifications</li>
                    </ul>
                    
                </div>
            </div>

            <div class="col-md-4">
                <div class="role-card lecturer">
                    <div class="role-icon"><i class="bi bi-person-video3"></i></div>
                    <h5>Lecturer</h5>
                    <ul>
                        <li>Upload course notes</li>
                        <li>Create assignments</li>
                        <li>Grade submissions</li>
                        <li>Send notifications</li>
                        <li>Manage courses</li>
                    </ul>
                    
                </div>
            </div>

            <div class="col-md-4">
                <div class="role-card student">
                    <div class="role-icon"><i class="bi bi-person-check"></i></div>
                    <h5>Student</h5>
                    <ul>
                        <li>Download notes</li>
                        <li>Submit assignments</li>
                        <li>View grades</li>
                        <li>Receive notifications</li>
                        <li>Enroll in courses</li>
                    </ul>
                    
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ── Footer ── -->
<footer>
    <div class="container">
        <p class="mb-1">
            <i class="bi bi-mortarboard-fill me-1" style="color:#00d4ff;"></i>
            <strong style="color:#00d4ff;">OnlineLMS</strong>
            — Online Notes, Assignment &amp; Learning Management System
        </p>
        <p>&copy; <?= date('Y') ?> All rights reserved.</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>