<?php
// File: doctor/profile.php

// Start session and load DB BEFORE any output
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/config/database.php';

// Auth guard (redirect BEFORE output)
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'doctor')) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$success_message = '';
$error_message   = '';

// DB connection
$database = new Database();
$db = $database->getConnection();

// Ensure a doctor row exists for this user
try {
    $docCheck = $db->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
    $docCheck->execute([$user_id]);
    $docRow = $docCheck->fetch(PDO::FETCH_ASSOC);

    if (!$docRow) {
        $ins = $db->prepare("INSERT INTO doctors (user_id, specialization, qualifications, experience) VALUES (?, 'General Medicine', '', 0)");
        $ins->execute([$user_id]);
    }
} catch (Throwable $e) {
    // If this fails, let the rest of the page still try to render (form will still allow creation on save)
}

// Fetch combined user + doctor view
try {
    $stmt = $db->prepare("
        SELECT u.user_id, u.name, u.email, u.phone, u.address, u.password, u.created_at,
               d.specialization, d.qualifications, d.experience
          FROM users u
     LEFT JOIN doctors d ON u.user_id = d.user_id
         WHERE u.user_id = ?
         LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: ../auth/login.php");
        exit();
    }
} catch (Throwable $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    // Provide safe defaults so the page can render
    $user = [
        'name' => '',
        'email' => '',
        'phone' => '',
        'address' => '',
        'password' => '',
        'created_at' => date('Y-m-d H:i:s'),
        'specialization' => 'General Medicine',
        'qualifications' => '',
        'experience' => 0,
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name            = trim($_POST['name'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $specialization  = trim($_POST['specialization'] ?? '');
    $qualifications  = trim($_POST['qualifications'] ?? '');
    $experience      = (int)($_POST['experience'] ?? 0);
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    // Validation
    if ($name === '') { $errors[] = "Name is required"; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Valid email is required"; }
    if ($phone === '') { $errors[] = "Phone number is required"; }
    if ($specialization === '') { $errors[] = "Specialization is required"; }
    if ($experience < 0) { $errors[] = "Experience cannot be negative"; }

    // Unique email check (excluding this user)
    try {
        $email_check = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $email_check->execute([$email, $user_id]);
        if ($email_check->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = "Email already exists";
        }
    } catch (Throwable $e) {
        $errors[] = "Error checking email uniqueness";
    }

    // Password change validation (if any password fields provided)
    if ($current_password !== '' || $new_password !== '' || $confirm_password !== '') {
        if ($current_password === '') {
            $errors[] = "Current password is required to change password";
        } elseif (!password_verify($current_password, $user['password'])) {
            $errors[] = "Current password is incorrect";
        }

        if ($new_password === '') {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters";
        }

        if ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match";
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Update users table
            if ($new_password !== '') {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $u = $db->prepare("
                    UPDATE users
                       SET name = ?, email = ?, phone = ?, address = ?, password = ?
                     WHERE user_id = ?
                ");
                $u->execute([$name, $email, $phone, $address, $hashed, $user_id]);
            } else {
                $u = $db->prepare("
                    UPDATE users
                       SET name = ?, email = ?, phone = ?, address = ?
                     WHERE user_id = ?
                ");
                $u->execute([$name, $email, $phone, $address, $user_id]);
            }

            // Upsert into doctors table
            $dCheck = $db->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
            $dCheck->execute([$user_id]);
            $exists = $dCheck->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $d = $db->prepare("
                    UPDATE doctors
                       SET specialization = ?, qualifications = ?, experience = ?
                     WHERE user_id = ?
                ");
                $d->execute([$specialization, $qualifications, $experience, $user_id]);
            } else {
                $d = $db->prepare("
                    INSERT INTO doctors (user_id, specialization, qualifications, experience)
                    VALUES (?, ?, ?, ?)
                ");
                $d->execute([$user_id, $specialization, $qualifications, $experience]);
            }

            $db->commit();

            // Update session name if changed
            if (!empty($_SESSION['name']) && $_SESSION['name'] !== $name) {
                $_SESSION['name'] = $name;
            } elseif (empty($_SESSION['name'])) {
                $_SESSION['name'] = $name;
            }

            $success_message = "Profile updated successfully!";

            // Refresh combined user data
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {
            $db->rollBack();
            $error_message = "Error updating profile: " . $e->getMessage();
        }
    } else {
        $error_message = implode(", ", $errors);
    }
}

// List of specializations
$specializations = [
    'General Medicine', 'Cardiology', 'Dermatology', 'Endocrinology', 'Gastroenterology',
    'Gynecology', 'Neurology', 'Oncology', 'Ophthalmology', 'Orthopedics',
    'Pediatrics', 'Psychiatry', 'Radiology', 'Surgery', 'Urology', 'Other'
];

// Include header only AFTER we handled redirects/headers
include '../includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card shadow">
                <div class="card-header py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-user-md me-2"></i>Doctor Profile
                        </h5>
                        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_message)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo htmlspecialchars($success_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="profileForm">
                        <!-- Personal Information -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-primary border-bottom pb-2">
                                    <i class="fas fa-user me-2"></i>Personal Information
                                </h6>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="experience" class="form-label">Years of Experience</label>
                                <input type="number" class="form-control" id="experience" name="experience"
                                       value="<?php echo (int)($user['experience'] ?? 0); ?>" min="0" max="60">
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-12">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- Professional Information -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-primary border-bottom pb-2">
                                    <i class="fas fa-stethoscope me-2"></i>Professional Information
                                </h6>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-12">
                                <label for="specialization" class="form-label">Specialization <span class="text-danger">*</span></label>
                                <select class="form-select" id="specialization" name="specialization" required>
                                    <option value="">Select Specialization</option>
                                    <?php foreach ($specializations as $spec): ?>
                                        <option value="<?php echo htmlspecialchars($spec); ?>"
                                            <?php echo (($user['specialization'] ?? '') === $spec) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($spec); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-12">
                                <label for="qualifications" class="form-label">Qualifications & Certifications</label>
                                <textarea class="form-control" id="qualifications" name="qualifications" rows="4"
                                          placeholder="Enter your medical qualifications, degrees, and certifications..."><?php echo htmlspecialchars($user['qualifications'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">
                                    Include your medical degree, board certifications, and any other relevant qualifications
                                </small>
                            </div>
                        </div>

                        <!-- Password Change Section -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-primary border-bottom pb-2">
                                    <i class="fas fa-lock me-2"></i>Change Password
                                    <small class="text-muted">(Optional)</small>
                                </h6>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-12">
                                <label for="current_password" class="form-label">Current Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="current_password" name="current_password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="new_password" class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="form-text text-muted">Minimum 6 characters</small>
                            </div>
                            <div class="col-md-6">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="row">
                            <div class="col-12">
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <a href="dashboard.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Profile
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Profile Statistics Card -->
            <div class="card shadow mt-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-bar me-2"></i>Profile Statistics
                    </h6>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        // Get doctor id
                        $doctor_check = $db->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
                        $doctor_check->execute([$user_id]);
                        $doctor_data = $doctor_check->fetch(PDO::FETCH_ASSOC);

                        if ($doctor_data) {
                            $doctor_id = (int)$doctor_data['doctor_id'];

                            // Total appointments
                            $total_appointments = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ?");
                            $total_appointments->execute([$doctor_id]);
                            $total_count = (int)$total_appointments->fetchColumn();

                            // Completed appointments
                            $completed_appointments = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'completed'");
                            $completed_appointments->execute([$doctor_id]);
                            $completed_count = (int)$completed_appointments->fetchColumn();

                            // Available future slots
                            $available_slots = $db->prepare("
                                SELECT COUNT(*) FROM availability_slots
                                 WHERE doctor_id = ? AND is_available = 1 AND slot_date >= CURDATE()
                            ");
                            $available_slots->execute([$doctor_id]);
                            $available_count = (int)$available_slots->fetchColumn();

                            // Member since
                            $member_since = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : '';
                    ?>
                    <div class="row text-center">
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="border-left-primary p-3">
                                <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo $total_count; ?></div>
                                <div class="text-xs font-weight-bold text-primary text-uppercase">Total Appointments</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="border-left-success p-3">
                                <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo $completed_count; ?></div>
                                <div class="text-xs font-weight-bold text-success text-uppercase">Completed</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="border-left-info p-3">
                                <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo $available_count; ?></div>
                                <div class="text-xs font-weight-bold text-info text-uppercase">Available Slots</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="border-left-warning p-3">
                                <div class="h4 mb-0 font-weight-bold text-gray-800"><?php echo (int)($user['experience'] ?? 0); ?></div>
                                <div class="text-xs font-weight-bold text-warning text-uppercase">Years Experience</div>
                            </div>
                        </div>
                    </div>
                    <div class="text-center mt-3">
                        <p class="text-muted">
                            <i class="fas fa-calendar-alt"></i>
                            Member since <?php echo htmlspecialchars($member_since); ?>
                        </p>
                    </div>
                    <?php
                        } else {
                    ?>
                    <div class="text-center py-4">
                        <i class="fas fa-info-circle fa-2x text-muted mb-3"></i>
                        <p class="text-muted">Complete your profile to see statistics</p>
                    </div>
                    <?php
                        }
                    } catch (Throwable $e) {
                    ?>
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-circle fa-2x text-danger mb-3"></i>
                        <p class="text-danger">Error loading statistics</p>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Password visibility toggle
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon  = field.nextElementSibling.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Form validation for password changes
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const currentPassword = document.getElementById('current_password').value.trim();
    const newPassword     = document.getElementById('new_password').value.trim();
    const confirmPassword = document.getElementById('confirm_password').value.trim();

    if (currentPassword !== '' || newPassword !== '' || confirmPassword !== '') {
        if (currentPassword === '') {
            e.preventDefault(); alert('Please enter your current password to change it.'); return;
        }
        if (newPassword === '') {
            e.preventDefault(); alert('Please enter a new password.'); return;
        }
        if (newPassword.length < 6) {
            e.preventDefault(); alert('New password must be at least 6 characters long.'); return;
        }
        if (newPassword !== confirmPassword) {
            e.preventDefault(); alert('New passwords do not match.'); return;
        }
    }
});

// Auto-resize & counter for qualifications
const qualificationsField = document.getElementById('qualifications');
if (qualificationsField) {
    const charCounter = document.createElement('small');
    charCounter.className = 'form-text text-muted float-end';
    qualificationsField.parentNode.appendChild(charCounter);

    function updateCharCount() {
        const count = qualificationsField.value.length;
        charCounter.textContent = `${count}/1000 characters`;
        if (count > 800) {
            charCounter.classList.add('text-warning');
        } else {
            charCounter.classList.remove('text-warning');
        }
        qualificationsField.style.height = 'auto';
        qualificationsField.style.height = qualificationsField.scrollHeight + 'px';
    }

    qualificationsField.addEventListener('input', updateCharCount);
    updateCharCount();
}
</script>

<style>
.border-left-primary { border-left: 4px solid #4e73df !important; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
.border-left-info    { border-left: 4px solid #36b9cc !important; }
.border-left-warning { border-left: 4px solid #f6c23e !important; }

.text-xs { font-size: 0.7rem; }

.card { transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }

.form-control:focus { border-color: #4e73df; box-shadow: 0 0 0 0.2rem rgba(78,115,223,0.25); }

.btn-outline-secondary:hover { background-color: #6c757d; border-color: #6c757d; }

.input-group .btn { border-left: 0; }
.input-group .form-control:focus { z-index: 2; }

.alert { border: none; border-radius: 0.5rem; }
</style>

<?php include '../includes/footer.php'; ?>
