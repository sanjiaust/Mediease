<?php
// File: doctor/dashboard.php

// Start session and load DB BEFORE any output
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/config/database.php';

// Check if user is logged in and is a doctor (redirect BEFORE output)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'doctor') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$name    = $_SESSION['name'] ?? 'Doctor';

// DB connection (project-wide pattern)
$database = new Database();
$db = $database->getConnection();

try {
    // Get doctor information (joined with users for contact info)
    $stmt = $db->prepare("
        SELECT d.*, u.email, u.phone, u.address
        FROM doctors d
        JOIN users u ON d.user_id = u.user_id
        WHERE d.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doctor) {
        // If doctor record doesn't exist, create a default one
        $insert_stmt = $db->prepare("
            INSERT INTO doctors (user_id, specialization, qualifications, experience)
            VALUES (?, 'General Medicine', '', 0)
        ");
        $insert_stmt->execute([$user_id]);

        // Re-fetch the doctor record
        $stmt->execute([$user_id]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Safeguard if still not found
    if (!$doctor) {
        throw new Exception('Doctor record could not be created or fetched.');
    }

    // Today's date
    $today = date('Y-m-d');

    // Today's appointments
    $today_appointments = $db->prepare("
        SELECT a.*, u.name AS patient_name, u.phone AS patient_phone,
               av.slot_date, av.start_time, av.end_time
        FROM appointments a
        JOIN users u ON a.patient_id = u.user_id
        JOIN availability_slots av ON a.slot_id = av.slot_id
        WHERE a.doctor_id = ? AND av.slot_date = ?
        ORDER BY av.start_time ASC
    ");
    $today_appointments->execute([$doctor['doctor_id'], $today]);
    $todays_appointments = $today_appointments->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming appointments (next 7 days)
    $upcoming_appointments = $db->prepare("
        SELECT a.*, u.name AS patient_name, u.phone AS patient_phone,
               av.slot_date, av.start_time, av.end_time
        FROM appointments a
        JOIN users u ON a.patient_id = u.user_id
        JOIN availability_slots av ON a.slot_id = av.slot_id
        WHERE a.doctor_id = ?
          AND av.slot_date > ?
          AND av.slot_date <= DATE_ADD(?, INTERVAL 7 DAY)
        ORDER BY av.slot_date ASC, av.start_time ASC
        LIMIT 10
    ");
    $upcoming_appointments->execute([$doctor['doctor_id'], $today, $today]);
    $upcoming = $upcoming_appointments->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = [];

    // Total appointments
    $total_stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ?");
    $total_stmt->execute([$doctor['doctor_id']]);
    $stats['total'] = (int)$total_stmt->fetchColumn();

    // This month's appointments
    $month_stmt = $db->prepare("
        SELECT COUNT(*)
        FROM appointments a
        JOIN availability_slots av ON a.slot_id = av.slot_id
        WHERE a.doctor_id = ?
          AND MONTH(av.slot_date) = MONTH(CURDATE())
          AND YEAR(av.slot_date)  = YEAR(CURDATE())
    ");
    $month_stmt->execute([$doctor['doctor_id']]);
    $stats['this_month'] = (int)$month_stmt->fetchColumn();

    // Pending appointments
    $pending_stmt = $db->prepare("
        SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'pending'
    ");
    $pending_stmt->execute([$doctor['doctor_id']]);
    $stats['pending'] = (int)$pending_stmt->fetchColumn();

    // Available slots today
    $available_stmt = $db->prepare("
        SELECT COUNT(*)
        FROM availability_slots
        WHERE doctor_id = ? AND slot_date = ? AND is_available = 1
    ");
    $available_stmt->execute([$doctor['doctor_id'], $today]);
    $stats['available_today'] = (int)$available_stmt->fetchColumn();

} catch (Throwable $e) {
    // Minimal fallback so the page still loads
    $doctor = $doctor ?? ['specialization' => 'General Medicine', 'experience' => 0];
    $todays_appointments = $todays_appointments ?? [];
    $upcoming = $upcoming ?? [];
    $stats = $stats ?? ['total' => 0, 'this_month' => 0, 'pending' => 0, 'available_today' => 0];
    // Optionally log $e->getMessage()
}

// Include header only AFTER all redirects and data prep (it outputs HTML)
include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-user-md text-primary me-2"></i>Doctor Dashboard</h2>
                <div class="d-flex gap-2">
                    <a href="profile.php" class="btn btn-outline-primary">
                        <i class="fas fa-user-edit"></i> Edit Profile
                    </a>
                    <a href="availability.php" class="btn btn-primary">
                        <i class="fas fa-calendar-plus"></i> Manage Availability
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Welcome Card -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-primary text-white">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="card-title mb-2">Welcome back,  <?php echo htmlspecialchars($name); ?>!</h4>
                            <p class="card-text mb-0">
                                <strong>Specialization:</strong> <?php echo htmlspecialchars($doctor['specialization'] ?? ''); ?><br>
                                <strong>Experience:</strong> <?php echo (int)($doctor['experience'] ?? 0); ?> years
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="h1 mb-0">
                                <i class="fas fa-stethoscope"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-left-primary">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Appointments</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-check fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-left-success">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">This Month</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['this_month']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-left-warning">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Approval</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['pending']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-left-info">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Available Today</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['available_today']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-day fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Today's Appointments -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-calendar-day me-2"></i>Today's Appointments
                    </h6>
                    <a href="appointments.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($todays_appointments)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No appointments scheduled for today</p>
                            <a href="availability.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Add Availability
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Patient</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($todays_appointments as $appointment): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('g:i A', strtotime($appointment['start_time'])); ?></strong><br>
                                            <small class="text-muted"><?php echo date('g:i A', strtotime($appointment['end_time'])); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($appointment['patient_name']); ?><br>
                                            <small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($appointment['patient_phone']); ?></small>
                                        </td>
                                        <td>
                                            <?php
                                                $badge_class = 'secondary';
                                                switch ($appointment['status']) {
                                                    case 'confirmed': $badge_class = 'success'; break;
                                                    case 'pending':   $badge_class = 'warning'; break;
                                                    case 'completed': $badge_class = 'info';    break;
                                                    case 'canceled':  $badge_class = 'danger';  break;
                                                }
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class; ?>">
                                                <?php echo ucfirst($appointment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($appointment['status'] == 'pending'): ?>
                                                <button class="btn btn-sm btn-success me-1"
                                                        onclick="updateAppointmentStatus(<?php echo (int)$appointment['appointment_id']; ?>, 'confirmed')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger"
                                                        onclick="updateAppointmentStatus(<?php echo (int)$appointment['appointment_id']; ?>, 'canceled')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php elseif ($appointment['status'] == 'confirmed'): ?>
                                                <button class="btn btn-sm btn-info"
                                                        onclick="updateAppointmentStatus(<?php echo (int)$appointment['appointment_id']; ?>, 'completed')">
                                                    <i class="fas fa-check-double"></i> Complete
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Appointments -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-calendar-week me-2"></i>Upcoming Appointments
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($upcoming)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-plus fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No upcoming appointments in the next 7 days</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming as $appointment): ?>
                            <div class="list-group-item border-0 px-0">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($appointment['patient_name']); ?></h6>
                                        <p class="mb-1">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($appointment['slot_date'])); ?> at
                                            <?php echo date('g:i A', strtotime($appointment['start_time'])); ?>
                                        </p>
                                        <small class="text-muted">Status: <?php echo ucfirst($appointment['status']); ?></small>
                                    </div>
                                    <div>
                                        <?php
                                            $badge_class = 'secondary';
                                            switch ($appointment['status']) {
                                                case 'confirmed': $badge_class = 'success'; break;
                                                case 'pending':   $badge_class = 'warning'; break;
                                                case 'completed': $badge_class = 'info';    break;
                                                case 'canceled':  $badge_class = 'danger';  break;
                                            }
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Update Appointment Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Appointment Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to update this appointment status?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmStatusUpdate()">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentAppointmentId = null;
let currentStatus = null;

function updateAppointmentStatus(appointmentId, status) {
    currentAppointmentId = appointmentId;
    currentStatus = status;

    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}

function confirmStatusUpdate() {
    if (!currentAppointmentId || !currentStatus) return;

    fetch('../api/appointment_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_status',
            appointment_id: currentAppointmentId,
            status: currentStatus
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error updating appointment status: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('An error occurred while updating the appointment status.');
    });
}

// Auto refresh every 5 minutes
setTimeout(() => { location.reload(); }, 300000);
</script>

<style>
.border-left-primary  { border-left: 4px solid #4e73df !important; }
.border-left-success  { border-left: 4px solid #1cc88a !important; }
.border-left-info     { border-left: 4px solid #36b9cc !important; }
.border-left-warning  { border-left: 4px solid #f6c23e !important; }
.bg-gradient-primary  { background: linear-gradient(45deg, #4e73df 0%, #224abe 100%); }
.card { transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
.card:hover { transform: translateY(-5px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important; }
.text-xs { font-size: 0.7rem; }
</style>

<?php include '../includes/footer.php'; ?>
