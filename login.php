<?php
// File: auth/login.php
// User login functionality for MediEase

// Start session and include database BEFORE any output
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
require_once dirname(__DIR__) . '/config/database.php';

$error = '';
$success = '';

// Check if user is already logged in BEFORE any HTML output
if (isset($_SESSION['user_id'])) {
    $redirect_url = '../' . $_SESSION['role'] . '/dashboard.php';
    header("Location: $redirect_url");
    exit();
}

// Process form submission BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    
    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($password)) {
        $error = 'Please enter your password.';
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Check user credentials
            $query = "SELECT user_id, name, email, password, role FROM users WHERE email = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    
                    // Redirect based on user role
                    switch ($user['role']) {
                        case 'patient':
                            header('Location: ../patient/dashboard.php');
                            break;
                        case 'doctor':
                            header('Location: ../doctor/dashboard.php');
                            break;
                        case 'admin':
                            header('Location: ../admin/dashboard.php');
                            break;
                        default:
                            header('Location: ../index.php');
                    }
                    exit();
                } else {
                    $error = 'Invalid email or password.';
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database error. Please try again later.';
        }
    }
}

// NOW include the header - after all possible redirects
include '../includes/header.php';
?>

<div class="container">
    <div class="form-container">
        <div class="card">
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="mb-3">
                        <i class="fas fa-sign-in-alt fa-3x text-primary"></i>
                    </div>
                    <h2>Login to MediEase</h2>
                    <p class="text-muted">Access your medical appointment dashboard</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm" onsubmit="return validateLoginForm()">
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email Address
                        </label>
                        <input type="email" class="form-control" id="email" name="email" required 
                               placeholder="Enter your email"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        <div class="invalid-feedback">Please enter a valid email address.</div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-2"></i>Password
                        </label>
                        <input type="password" class="form-control" id="password" name="password" required 
                               placeholder="Enter your password">
                        <div class="invalid-feedback">Please enter your password.</div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary" id="loginBtn">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </button>
                    </div>
                </form>

                <div class="text-center">
                    <p class="mb-0">Don't have an account?</p>
                    <div class="d-flex gap-2 justify-content-center mt-2">
                        <a href="register.php?role=patient" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-user me-1"></i>Patient
                        </a>
                        <a href="register.php?role=doctor" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-user-md me-1"></i>Doctor
                        </a>
                    </div>
                </div>

                
            </div>
        </div>
    </div>
</div>

<script>
function validateLoginForm() {
    const email = document.getElementById('email');
    const password = document.getElementById('password');
    let isValid = true;

    // Email validation
    if (!email.value.trim() || !validateEmail(email.value)) {
        email.classList.add('is-invalid');
        isValid = false;
    } else {
        email.classList.remove('is-invalid');
    }

    // Password validation
    if (!password.value.trim()) {
        password.classList.add('is-invalid');
        isValid = false;
    } else {
        password.classList.remove('is-invalid');
    }

    // Set loading state if form is valid
    if (isValid) {
        setLoadingState('loginBtn', true);
    }

    return isValid;
}

function setLoadingState(buttonId, loading) {
    const button = document.getElementById(buttonId);
    if (button) {
        if (loading) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Logging in...';
        } else {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Login';
        }
    }
}

// Real-time validation
document.addEventListener('DOMContentLoaded', function() {
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    
    emailInput.addEventListener('blur', function() {
        if (this.value.trim() && !validateEmail(this.value)) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid');
        }
    });
    
    passwordInput.addEventListener('input', function() {
        if (this.value.trim()) {
            this.classList.remove('is-invalid');
        }
    });
});

// Email validation function
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}
</script>

<?php include '../includes/footer.php'; ?>