<?php
// File: auth/register.php
// User registration functionality for MediEase

// IMPORTANT: do not output any HTML before potential header() redirects
session_start();
require_once dirname(__DIR__) . '/config/database.php';

$error = '';
$success = '';
$role = isset($_GET['role']) && in_array($_GET['role'], ['patient', 'doctor']) ? $_GET['role'] : 'patient';

// Check if user is already logged in (redirect before any output)
if (isset($_SESSION['user_id'])) {
    // Fix for empty role issue
    $user_role = $_SESSION['role'] ?? 'patient';
    if (empty($user_role) || !in_array($user_role, ['patient', 'doctor', 'admin'])) {
        $user_role = 'patient'; // Default fallback
    }
    $redirect_url = '../' . $user_role . '/dashboard.php';
    header("Location: $redirect_url");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $role = $_POST['role'];
    
    // Validate role to prevent empty values
    if (!in_array($role, ['patient', 'doctor'])) {
        $role = 'patient'; // Default fallback
    }
    
    // Doctor-specific fields
    $specialization = isset($_POST['specialization']) ? trim($_POST['specialization']) : '';
    $qualifications = isset($_POST['qualifications']) ? trim($_POST['qualifications']) : '';
    $experience = isset($_POST['experience']) ? intval($_POST['experience']) : 0;
    
    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif ($role == 'doctor' && (empty($specialization) || empty($qualifications))) {
        $error = 'Please fill in all doctor-specific fields.';
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Check if email already exists
            $check_query = "SELECT email FROM users WHERE email = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$email]);
            
            if ($check_stmt->rowCount() > 0) {
                $error = 'Email already exists. Please use a different email.';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert user - ENSURE role is not empty
                $user_query = "INSERT INTO users (name, email, password, role, phone, address) VALUES (?, ?, ?, ?, ?, ?)";
                $user_stmt = $db->prepare($user_query);
                $user_stmt->execute([$name, $email, $hashed_password, $role, $phone, $address]);
                
                $user_id = $db->lastInsertId();
                
                // If doctor, insert additional doctor information
                if ($role == 'doctor') {
                    $doctor_query = "INSERT INTO doctors (user_id, specialization, qualifications, experience) VALUES (?, ?, ?, ?)";
                    $doctor_stmt = $db->prepare($doctor_query);
                    $doctor_stmt->execute([$user_id, $specialization, $qualifications, $experience]);
                }
                
                // Auto-login the user with proper role validation
                $_SESSION['user_id'] = $user_id;
                $_SESSION['name'] = $name;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role; // Ensure this is set correctly
                
                // Redirect to appropriate dashboard (still BEFORE any HTML output)
                $redirect_url = '../' . $role . '/dashboard.php';
                header("Location: $redirect_url");
                exit();
            }
        } catch (PDOException $e) {
            $error = 'Registration failed. Please try again later. Error: ' . $e->getMessage();
        }
    }
}

// Only include the header AFTER all possible redirects above
include '../includes/header.php';

// Specializations for doctors
$specializations = [
    'Cardiology', 'Dermatology', 'Neurology', 'Pediatrics', 'Orthopedics',
    'Gynecology', 'Psychiatry', 'Ophthalmology', 'ENT', 'General Medicine',
    'Surgery', 'Anesthesiology', 'Radiology', 'Pathology', 'Emergency Medicine'
];
?>

<div class="container">
    <div class="form-container">
        <div class="card">
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="mb-3">
                        <i class="fas fa-user-plus fa-3x text-success"></i>
                    </div>
                    <h2>Register for MediEase</h2>
                    <p class="text-muted">Create your account to start booking appointments</p>
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

                <!-- Role Selection -->
                <div class="mb-4">
                    <div class="d-flex justify-content-center gap-2">
                        <input type="radio" class="btn-check" name="role_selector" id="patient_role" <?php echo $role == 'patient' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary" for="patient_role" onclick="switchRole('patient')">
                            <i class="fas fa-user me-2"></i>Patient
                        </label>

                        <input type="radio" class="btn-check" name="role_selector" id="doctor_role" <?php echo $role == 'doctor' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-success" for="doctor_role" onclick="switchRole('doctor')">
                            <i class="fas fa-user-md me-2"></i>Doctor
                        </label>
                    </div>
                </div>

                <form method="POST" id="registerForm" onsubmit="return validateRegisterForm()">
                    <input type="hidden" name="role" id="selected_role" value="<?php echo $role; ?>">
                    
                    <!-- Basic Information -->
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            <i class="fas fa-user me-2"></i>Full Name
                        </label>
                        <input type="text" class="form-control" id="name" name="name" required 
                               placeholder="Enter your full name"
                               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        <div class="invalid-feedback">Please enter your full name.</div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email Address
                        </label>
                        <input type="email" class="form-control" id="email" name="email" required 
                               placeholder="Enter your email"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        <div class="invalid-feedback">Please enter a valid email address.</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock me-2"></i>Password
                                </label>
                                <input type="password" class="form-control" id="password" name="password" required 
                                       placeholder="Minimum 6 characters">
                                <div class="invalid-feedback">Password must be at least 6 characters.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">
                                    <i class="fas fa-lock me-2"></i>Confirm Password
                                </label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required 
                                       placeholder="Confirm your password">
                                <div class="invalid-feedback">Passwords must match.</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">
                            <i class="fas fa-phone me-2"></i>Phone Number
                        </label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               placeholder="Enter your phone number"
                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                        <div class="invalid-feedback">Please enter a valid phone number.</div>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">
                            <i class="fas fa-map-marker-alt me-2"></i>Address
                        </label>
                        <textarea class="form-control" id="address" name="address" rows="2" 
                                  placeholder="Enter your address"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                    </div>

                    <!-- Doctor-specific fields -->
                    <div id="doctor_fields" style="display: <?php echo $role == 'doctor' ? 'block' : 'none'; ?>;">
                        <hr class="my-4">
                        <h5 class="mb-3"><i class="fas fa-stethoscope me-2"></i>Professional Information</h5>
                        
                        <div class="mb-3">
                            <label for="specialization" class="form-label">
                                <i class="fas fa-user-md me-2"></i>Specialization
                            </label>
                            <select class="form-control" id="specialization" name="specialization">
                                <option value="">Select your specialization</option>
                                <?php foreach ($specializations as $spec): ?>
                                    <option value="<?php echo $spec; ?>" 
                                            <?php echo (isset($_POST['specialization']) && $_POST['specialization'] == $spec) ? 'selected' : ''; ?>>
                                    <?php echo $spec; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select your specialization.</div>
                        </div>

                        <div class="mb-3">
                            <label for="qualifications" class="form-label">
                                <i class="fas fa-graduation-cap me-2"></i>Qualifications
                            </label>
                            <textarea class="form-control" id="qualifications" name="qualifications" rows="3" 
                                      placeholder="Enter your medical qualifications (e.g., MBBS, MD, Fellowship)"><?php echo isset($_POST['qualifications']) ? htmlspecialchars($_POST['qualifications']) : ''; ?></textarea>
                            <div class="invalid-feedback">Please enter your qualifications.</div>
                        </div>

                        <div class="mb-3">
                            <label for="experience" class="form-label">
                                <i class="fas fa-calendar-alt me-2"></i>Years of Experience
                            </label>
                            <input type="number" class="form-control" id="experience" name="experience" min="0" max="50"
                                   placeholder="Enter years of experience"
                                   value="<?php echo isset($_POST['experience']) ? $_POST['experience'] : ''; ?>">
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-success" id="registerBtn">
                            <i class="fas fa-user-plus me-2"></i>Register
                        </button>
                    </div>
                </form>

                <div class="text-center">
                    <p class="mb-0">Already have an account?</p>
                    <a href="login.php" class="btn btn-outline-primary mt-2">
                        <i class="fas fa-sign-in-alt me-2"></i>Login Here
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchRole(newRole) {
    document.getElementById('selected_role').value = newRole;
    const doctorFields = document.getElementById('doctor_fields');
    
    if (newRole === 'doctor') {
        doctorFields.style.display = 'block';
        // Make doctor fields required
        document.getElementById('specialization').required = true;
        document.getElementById('qualifications').required = true;
    } else {
        doctorFields.style.display = 'none';
        // Remove required from doctor fields
        document.getElementById('specialization').required = false;
        document.getElementById('qualifications').required = false;
    }
    
    // Update URL without reloading
    const url = new URL(window.location);
    url.searchParams.set('role', newRole);
    window.history.pushState({}, '', url);
}

function validateRegisterForm() {
    const name = document.getElementById('name');
    const email = document.getElementById('email');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const phone = document.getElementById('phone');
    const role = document.getElementById('selected_role').value;
    
    let isValid = true;

    // Name validation
    if (!name.value.trim()) {
        name.classList.add('is-invalid');
        isValid = false;
    } else {
        name.classList.remove('is-invalid');
    }

    // Email validation
    if (!email.value.trim() || !validateEmail(email.value)) {
        email.classList.add('is-invalid');
        isValid = false;
    } else {
        email.classList.remove('is-invalid');
    }

    // Password validation
    if (!password.value.trim() || password.value.length < 6) {
        password.classList.add('is-invalid');
        isValid = false;
    } else {
        password.classList.remove('is-invalid');
    }

    // Confirm password validation
    if (password.value !== confirmPassword.value) {
        confirmPassword.classList.add('is-invalid');
        isValid = false;
    } else {
        confirmPassword.classList.remove('is-invalid');
    }

    // Phone validation (optional but if provided, must be valid)
    if (phone.value.trim() && !validatePhone(phone.value)) {
        phone.classList.add('is-invalid');
        isValid = false;
    } else {
        phone.classList.remove('is-invalid');
    }

    // Doctor-specific validation
    if (role === 'doctor') {
        const specialization = document.getElementById('specialization');
        const qualifications = document.getElementById('qualifications');
        
        if (!specialization.value.trim()) {
            specialization.classList.add('is-invalid');
            isValid = false;
        } else {
            specialization.classList.remove('is-invalid');
        }
        
        if (!qualifications.value.trim()) {
            qualifications.classList.add('is-invalid');
            isValid = false;
        } else {
            qualifications.classList.remove('is-invalid');
        }
    }

    // Set loading state if form is valid
    if (isValid) {
        setLoadingState('registerBtn', true);
    }

    return isValid;
}

function setLoadingState(buttonId, loading) {
    const button = document.getElementById(buttonId);
    if (button) {
        if (loading) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Registering...';
        } else {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-user-plus me-2"></i>Register';
        }
    }
}

// Real-time validation
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    
    // Password strength indicator
    passwordInput.addEventListener('input', function() {
        const strength = checkPasswordStrength(this.value);
        updatePasswordStrength(strength);
    });
    
    // Confirm password matching
    confirmPasswordInput.addEventListener('input', function() {
        if (this.value !== passwordInput.value) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid');
        }
    });
    
    // Phone formatting
    document.getElementById('phone').addEventListener('input', function() {
        formatPhone(this);
    });
});

function checkPasswordStrength(password) {
    let strength = 0;
    if (password.length >= 6) strength++;
    if (/[a-z]/.test(password)) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^A-Za-z0-9]/.test(password)) strength++;
    return strength;
}

function updatePasswordStrength(strength) {
    const passwordInput = document.getElementById('password');
    const colors = ['#dc3545', '#fd7e14', '#ffc107', '#28a745', '#198754'];
    const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
    
    // Remove existing feedback
    const existingFeedback = passwordInput.parentNode.querySelector('.password-strength');
    if (existingFeedback) {
        existingFeedback.remove();
    }
    
    if (passwordInput.value.length > 0) {
        const strengthDiv = document.createElement('div');
        strengthDiv.className = 'password-strength mt-1';
        strengthDiv.innerHTML = `<small style="color: ${colors[strength]};">Password Strength: ${labels[strength]}</small>`;
        passwordInput.parentNode.appendChild(strengthDiv);
    }
}

function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validatePhone(phone) {
    const re = /^[0-9+\-\s()]{10,}$/;
    return re.test(phone);
}

function formatPhone(input) {
    // Remove all non-digits except +
    let value = input.value.replace(/[^\d+]/g, '');
    input.value = value;
}
</script>

<?php include '../includes/footer.php'; ?>