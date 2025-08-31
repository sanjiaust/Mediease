<?php
// File: includes/header.php
// Header component with navigation and styling

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/config/database.php';

/**
 * --- Safe session reads ---
 */
$loggedIn = isset($_SESSION['user_id']);
$role     = $_SESSION['role'] ?? null;     // 'patient' | 'doctor' | 'admin' | null
$name     = $_SESSION['name'] ?? 'User';

/**
 * --- Robust relative base for links ---
 * We only need to know if the current PHP file lives inside a subfolder
 * like /auth, /patient, /doctor, /admin relative to the project root.
 * If yes, use '../' to get back to the project root for building links.
 */
$path     = str_replace('\\', '/', $_SERVER['PHP_SELF']); // normalize on Windows
$isInSubdir = (strpos($path, '/auth/') !== false)
           || (strpos($path, '/patient/') !== false)
           || (strpos($path, '/doctor/') !== false)
           || (strpos($path, '/admin/') !== false);

$rootRel = $isInSubdir ? '../' : '';
// after session_start / $role / $rootRel ...
$patientBase = $rootRel . 'patient/';
$doctorBase  = $rootRel . 'doctor/';
$adminBase   = $rootRel . 'admin/';
$authBase    = $rootRel . 'auth/';

$profileHref = $role === 'patient' ? $patientBase . 'profile.php'
             : ($role === 'doctor' ? $doctorBase . 'profile.php'
             : ($role === 'admin'  ? $adminBase  . 'dashboard.php'
             : $rootRel . 'index.php'));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediEase - Medical Appointment System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #2c7be5;
            --secondary-color: #00d4aa;
            --accent-color: #1e88e5;
            --light-bg: #f8fafb;
            --dark-text: #2d3436;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)) !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: bold;
            font-size: 1.5rem;
            color: white !important;
        }

        .navbar-nav .nav-link {
            color: white !important;
            font-weight: 500;
            margin: 0 10px;
            transition: all 0.3s ease;
        }

        .navbar-nav .nav-link:hover {
            color: #f8f9fa !important;
            transform: translateY(-2px);
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
            background: rgba(255,255,255,0.95);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }

        .btn-primary {
            background: linear-gradient(45deg, var(--primary-color), var(--accent-color));
            border: none;
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(44, 123, 229, 0.4);
        }

        .btn-success {
            background: linear-gradient(45deg, var(--success-color), var(--secondary-color));
            border: none;
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
        }

        .hero-section {
            padding: 80px 0;
            text-align: center;
            color: white;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: bold;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .hero-subtitle {
            font-size: 1.3rem;
            margin-bottom: 40px;
            opacity: 0.9;
        }

        .feature-card { text-align: center; padding: 30px; margin: 20px 0; }
        .feature-icon { font-size: 3rem; color: var(--primary-color); margin-bottom: 20px; }

        .form-container { max-width: 500px; margin: 50px auto; padding: 40px; }

        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(44, 123, 229, 0.25);
        }

        .dashboard-card {
            background: white; border-radius: 15px; padding: 25px; margin: 20px 0;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white; text-align: center; padding: 30px; border-radius: 15px; margin: 15px 0;
        }
        .stat-number { font-size: 2.5rem; font-weight: bold; }

        .appointment-card { border-left: 4px solid var(--primary-color); margin: 15px 0; transition: all 0.3s ease; }
        .appointment-card:hover { border-left-color: var(--secondary-color); }

        .doctor-card { text-align: center; transition: all 0.3s ease; }
        .doctor-avatar {
            width: 80px; height: 80px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;
            color: white; font-size: 2rem;
        }

        .alert { border-radius: 10px; border: none; }
        .table { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        .badge { padding: 8px 12px; border-radius: 20px; font-weight: 500; }

        .current-time { font-size: 0.9rem; color: rgba(255,255,255,0.8); }

        @media (max-width: 768px) {
            .hero-title { font-size: 2.5rem; }
            .hero-subtitle { font-size: 1.1rem; }
            .form-container { margin: 20px auto; padding: 20px; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $rootRel; ?>index.php">
                <i class="fas fa-heartbeat me-2"></i>MediEase
            </a>
            
            <div class="current-time d-none d-md-block">
                <i class="fas fa-clock me-1"></i>
                <span id="currentDateTime"></span>
            </div>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if ($loggedIn): ?>
                        <?php if ($role === 'patient'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $patientBase; ?>dashboard.php">
                                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $patientBase; ?>doctors.php">
                                    <i class="fas fa-user-md me-1"></i>Find Doctors
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $patientBase; ?>appointments.php">
                                    <i class="fas fa-calendar-check me-1"></i>My Appointments
                                </a>
                            </li>

                        <?php elseif ($role === 'doctor'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $doctorBase; ?>dashboard.php">
                                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $doctorBase; ?>availability.php">
                                    <i class="fas fa-calendar-alt me-1"></i>Availability
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $doctorBase; ?>appointments.php">
                                    <i class="fas fa-calendar-check me-1"></i>Appointments
                                </a>
                            </li>

                        <?php elseif ($role === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo $adminBase; ?>dashboard.php">
                                    <i class="fas fa-cogs me-1"></i>Admin Panel
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($name); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="<?php echo $profileHref; ?>">
                                        <i class="fas fa-user-edit me-2"></i>Profile
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $authBase; ?>logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $authBase; ?>login.php">
                                <i class="fas fa-sign-in-alt me-1"></i>Login
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $authBase; ?>register.php">
                                <i class="fas fa-user-plus me-1"></i>Register
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Push page content below fixed navbar -->
    <div style="margin-top: 76px;">

    <script>
        // Update current date and time
        function updateDateTime() {
            const now = new Date();
            const options = {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                timeZone: 'Asia/Dhaka'
            };
            const element = document.getElementById('currentDateTime');
            if (element) {
                element.textContent = now.toLocaleDateString('en-US', options);
            }
        }

        // Update time every second
        document.addEventListener('DOMContentLoaded', function() {
            updateDateTime();
            setInterval(updateDateTime, 1000);
        });

        // Basic form helpers
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        function validatePhone(phone) {
            const re = /^[0-9+\-\s()]{10,}$/;
            return re.test(phone);
        }

        function validateForm(formId) {
            const form = document.getElementById(formId);
            const inputs = form.querySelectorAll('input[required], select[required]');
            let isValid = true;

            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            return isValid;
        }
    </script>
