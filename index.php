<?php
// File: index.php
// Homepage for MediEase Medical Appointment System

include 'includes/header.php';
?>

<div class="hero-section">
    <div class="container">
        <h1 class="hero-title">
            <i class="fas fa-heartbeat me-3"></i>MediEase
        </h1>
        <p class="hero-subtitle">
            Your trusted medical appointment booking system<br>
            Connecting patients with healthcare professionals across Bangladesh
        </p>
        
        <?php if (!isset($_SESSION['user_id'])): ?>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="auth/register.php?role=patient" class="btn btn-primary btn-lg">
                <i class="fas fa-user me-2"></i>Register as Patient
            </a>
            <a href="auth/register.php?role=doctor" class="btn btn-success btn-lg">
                <i class="fas fa-user-md me-2"></i>Register as Doctor
            </a>
        </div>
        <div class="mt-3">
            <a href="auth/login.php" class="btn btn-outline-light">
                <i class="fas fa-sign-in-alt me-2"></i>Already have an account? Login
            </a>
        </div>
        <?php else: ?>
        <div class="d-flex justify-content-center">
           <?php
$role = $_SESSION['role'] ?? null;
if ($role === 'patient'): ?>
    <a href="patient/dashboard.php" class="btn btn-primary btn-lg">
        <i class="fas fa-tachometer-alt me-2"></i>Go to Dashboard
    </a>
<?php elseif ($role === 'doctor'): ?>
    <a href="doctor/dashboard.php" class="btn btn-success btn-lg">
        <i class="fas fa-stethoscope me-2"></i>Doctor Dashboard
    </a>
<?php elseif ($role === 'admin'): ?>
    <a href="admin/dashboard.php" class="btn btn-warning btn-lg">
        <i class="fas fa-cogs me-2"></i>Admin Panel
    </a>
<?php else: ?>
    <!-- Fallback if role isn’t set yet -->
    <a href="auth/login.php" class="btn btn-outline-light">
        <i class="fas fa-sign-in-alt me-2"></i>Login
    </a>
<?php endif; ?>

        </div>
        <?php endif; ?>
    </div>
</div>

<div class="container my-5">
    <!-- Features Section -->
    <div class="row">
        <div class="col-md-4">
            <div class="card feature-card">
                <div class="feature-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h4>Easy Booking</h4>
                <p>Book appointments with your preferred doctors in just a few clicks. View available time slots and choose what works best for you.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card feature-card">
                <div class="feature-icon">
                    <i class="fas fa-user-md"></i>
                </div>
                <h4>Qualified Doctors</h4>
                <p>Connect with experienced healthcare professionals across various specializations. Read their qualifications and experience.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card feature-card">
                <div class="feature-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h4>Real-time Availability</h4>
                <p>See real-time doctor availability and book appointments instantly. Get immediate confirmation of your booking.</p>
            </div>
        </div>
    </div>

    <!-- How it Works Section -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="card-title mb-4">
                        <i class="fas fa-cogs me-2"></i>How MediEase Works
                    </h3>
                    <div class="row mt-4">
                        <div class="col-md-3 mb-4">
                            <div class="mb-3">
                                <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                    <span class="fs-4 fw-bold">1</span>
                                </div>
                            </div>
                            <h5><i class="fas fa-user-plus me-2"></i>Register</h5>
                            <p>Create your account as a patient or doctor with your basic information and credentials.</p>
                        </div>
                        <div class="col-md-3 mb-4">
                            <div class="mb-3">
                                <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                    <span class="fs-4 fw-bold">2</span>
                                </div>
                            </div>
                            <h5><i class="fas fa-search me-2"></i>Find Doctor</h5>
                            <p>Browse doctors by specialization, experience, and location to find the right healthcare provider.</p>
                        </div>
                        <div class="col-md-3 mb-4">
                            <div class="mb-3">
                                <div class="bg-warning text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                    <span class="fs-4 fw-bold">3</span>
                                </div>
                            </div>
                            <h5><i class="fas fa-calendar-alt me-2"></i>Book Slot</h5>
                            <p>Choose from available time slots and book your appointment with instant confirmation.</p>
                        </div>
                        <div class="col-md-3 mb-4">
                            <div class="mb-3">
                                <div class="bg-info text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                    <span class="fs-4 fw-bold">4</span>
                                </div>
                            </div>
                            <h5><i class="fas fa-hospital me-2"></i>Meet Doctor</h5>
                            <p>Attend your appointment and receive the quality healthcare you deserve.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Section -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <div class="row mt-5">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h3 class="text-center mb-4">
                        <i class="fas fa-chart-bar me-2"></i>MediEase at a Glance
                    </h3>
                    <div class="row">
                        <?php
                        try {
                            $database = new Database();
                            $db = $database->getConnection();
                            
                            // Get statistics
                            $stats = [];
                            
                            // Total patients
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'patient'");
                            $stmt->execute();
                            $stats['patients'] = $stmt->fetch()['count'];
                            
                            // Total doctors
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'doctor'");
                            $stmt->execute();
                            $stats['doctors'] = $stmt->fetch()['count'];
                            
                            // Total appointments
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM appointments");
                            $stmt->execute();
                            $stats['appointments'] = $stmt->fetch()['count'];
                            
                            // Available slots today
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM availability_slots WHERE slot_date = CURDATE() AND is_available = 1");
                            $stmt->execute();
                            $stats['available_today'] = $stmt->fetch()['count'];
                            
                        } catch (PDOException $e) {
                            $stats = ['patients' => 0, 'doctors' => 0, 'appointments' => 0, 'available_today' => 0];
                        }
                        ?>
                        
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card bg-primary">
                                <div class="stat-number"><?php echo $stats['patients']; ?></div>
                                <div><i class="fas fa-users me-2"></i>Registered Patients</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card bg-success">
                                <div class="stat-number"><?php echo $stats['doctors']; ?></div>
                                <div><i class="fas fa-user-md me-2"></i>Available Doctors</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card bg-info">
                                <div class="stat-number"><?php echo $stats['appointments']; ?></div>
                                <div><i class="fas fa-calendar-check me-2"></i>Total Appointments</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card bg-warning">
                                <div class="stat-number"><?php echo $stats['available_today']; ?></div>
                                <div><i class="fas fa-clock me-2"></i>Slots Available Today</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Specializations Section -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h3 class="text-center mb-4">
                        <i class="fas fa-stethoscope me-2"></i>Medical Specializations Available
                    </h3>
                    <div class="row">
                        <div class="col-md-2 col-sm-4 col-6 text-center mb-3">
                            <div class="p-3">
                                <i class="fas fa-heart fs-2 text-danger mb-2"></i>
                                <h6>Cardiology</h6>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6 text-center mb-3">
                            <div class="p-3">
                                <i class="fas fa-brain fs-2 text-primary mb-2"></i>
                                <h6>Neurology</h6>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6 text-center mb-3">
                            <div class="p-3">
                                <i class="fas fa-child fs-2 text-success mb-2"></i>
                                <h6>Pediatrics</h6>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6 text-center mb-3">
                            <div class="p-3">
                                <i class="fas fa-eye fs-2 text-info mb-2"></i>
                                <h6>Ophthalmology</h6>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6 text-center mb-3">
                            <div class="p-3">
                                <i class="fas fa-hand-paper fs-2 text-warning mb-2"></i>
                                <h6>Dermatology</h6>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6 text-center mb-3">
                            <div class="p-3">
                                <i class="fas fa-tooth fs-2 text-secondary mb-2"></i>
                                <h6>Dentistry</h6>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>