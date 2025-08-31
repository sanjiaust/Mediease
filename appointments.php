<?php
// File: doctor/appointments.php

// Start session & bootstrap DB BEFORE any output
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

// DB connection via project standard Database class
$database = new Database();
$db = $database->getConnection();

try {
    // Ensure doctor record exists for this user; create a default if missing
    $doctor_stmt = $db->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
    $doctor_stmt->execute([$user_id]);
    $doctor = $doctor_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doctor) {
        $insert_stmt = $db->prepare("
            INSERT INTO doctors (user_id, specialization, qualifications, experience)
            VALUES (?, 'General Medicine', '', 0)
        ");
        $insert_stmt->execute([$user_id]);

        $doctor_stmt->execute([$user_id]);
        $doctor = $doctor_stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$doctor) {
        throw new Exception('Unable to create or fetch doctor record.');
    }

    $doctor_id = (int)$doctor['doctor_id'];

    // Handle appointment status updates (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_status') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            $new_status     = $_POST['status'] ?? '';
            $notes          = trim($_POST['notes'] ?? '');

            $allowed_statuses = ['pending', 'confirmed', 'completed', 'canceled'];

            if ($appointment_id > 0 && in_array($new_status, $allowed_statuses, true)) {
                try {
                    $update_stmt = $db->prepare("
                        UPDATE appointments
                           SET status = ?, notes = ?
                         WHERE appointment_id = ? AND doctor_id = ?
                    ");
                    if ($update_stmt->execute([$new_status, $notes, $appointment_id, $doctor_id])) {
                        $success_message = "Appointment status updated to " . ucfirst($new_status);

                        // If canceled, free up the slot
                        if ($new_status === 'canceled') {
                            $slot_update = $db->prepare("
                                UPDATE availability_slots
                                   SET is_available = 1
                                 WHERE slot_id = (SELECT slot_id FROM appointments WHERE appointment_id = ?)
                            ");
                            $slot_update->execute([$appointment_id]);
                        }
                    } else {
                        $error_message = "Error updating appointment status";
                    }
                } catch (Throwable $e) {
                    $error_message = "Error: " . $e->getMessage();
                }
            } else {
                $error_message = "Invalid status or appointment id";
            }
        }
    }

    // Filters
    $status_filter = $_GET['status'] ?? 'all';
    $date_filter   = $_GET['date']   ?? 'all';
    $search        = $_GET['search'] ?? '';

    // Build WHERE conditions
    $where = ["a.doctor_id = ?"];
    $params = [$doctor_id];

    if ($status_filter !== 'all') {
        $where[] = "a.status = ?";
        $params[] = $status_filter;
    }

    if ($date_filter !== 'all') {
        switch ($date_filter) {
            case 'today':
                $where[] = "av.slot_date = CURDATE()";
                break;
            case 'week':
                $where[] = "av.slot_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $where[] = "av.slot_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
                break;
            case 'past':
                $where[] = "av.slot_date < CURDATE()";
                break;
        }
    }

    if (strlen($search) > 0) {
        $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $search_term = '%' . $search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    // Appointments query (shared by page & CSV export)
    $appointments_query = "
        SELECT a.*,
               u.name  AS patient_name,
               u.email AS patient_email,
               u.phone AS patient_phone,
               u.address AS patient_address,
               av.slot_date, av.start_time, av.end_time
          FROM appointments a
          JOIN users u ON a.patient_id = u.user_id
          JOIN availability_slots av ON a.slot_id = av.slot_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY av.slot_date DESC, av.start_time DESC
    ";

    $appointments_stmt = $db->prepare($appointments_query);
    $appointments_stmt->execute($params);
    $appointments = $appointments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // CSV export BEFORE any HTML output
    if ((isset($_GET['export']) && $_GET['export'] === 'csv')) {
        $filename = 'appointments_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        // CSV header
        fputcsv($out, [
            'Appointment ID', 'Status', 'Booked At',
            'Slot Date', 'Start Time', 'End Time',
            'Patient ID', 'Patient Name', 'Patient Email', 'Patient Phone',
            'Notes'
        ]);

        foreach ($appointments as $row) {
            fputcsv($out, [
                $row['appointment_id'] ?? '',
                $row['status'] ?? '',
                isset($row['booked_at']) ? date('Y-m-d H:i:s', strtotime($row['booked_at'])) : '',
                $row['slot_date'] ?? '',
                isset($row['start_time']) ? date('H:i', strtotime($row['start_time'])) : '',
                isset($row['end_time'])   ? date('H:i', strtotime($row['end_time']))   : '',
                $row['patient_id'] ?? '',
                $row['patient_name'] ?? '',
                $row['patient_email'] ?? '',
                $row['patient_phone'] ?? '',
                $row['notes'] ?? '',
            ]);
        }

        fclose($out);
        exit();
    }

    // Stats
    $stats = [];

    // Total appointments
    $total_stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ?");
    $total_stmt->execute([$doctor_id]);
    $stats['total'] = (int)$total_stmt->fetchColumn();

    // Today's appointments
    $today_stmt = $db->prepare("
        SELECT COUNT(*) FROM appointments a
        JOIN availability_slots av ON a.slot_id = av.slot_id
        WHERE a.doctor_id = ? AND av.slot_date = CURDATE()
    ");
    $today_stmt->execute([$doctor_id]);
    $stats['today'] = (int)$today_stmt->fetchColumn();

    // Pending appointments
    $pending_stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'pending'");
    $pending_stmt->execute([$doctor_id]);
    $stats['pending'] = (int)$pending_stmt->fetchColumn();

    // Completed this month
    $completed_stmt = $db->prepare("
        SELECT COUNT(*) FROM appointments a
        JOIN availability_slots av ON a.slot_id = av.slot_id
        WHERE a.doctor_id = ? AND a.status = 'completed'
          AND MONTH(av.slot_date) = MONTH(CURDATE())
          AND YEAR(av.slot_date)  = YEAR(CURDATE())
    ");
    $completed_stmt->execute([$doctor_id]);
    $stats['completed_month'] = (int)$completed_stmt->fetchColumn();

} catch (Throwable $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $appointments = [];
    $stats = ['total' => 0, 'today' => 0, 'pending' => 0, 'completed_month' => 0];
}

// Include header only AFTER all redirects/headers
include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-calendar-check text-primary me-2"></i>Manage Appointments</h2>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Dashboard
                    </a>
                    <a href="availability.php" class="btn btn-outline-primary">
                        <i class="fas fa-calendar-plus"></i> Manage Availability
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerts -->
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
            <div class="card border-left-info">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Today's Appointments</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['today']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-day fa-2x text-info"></i>
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
            <div class="card border-left-success">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Completed This Month</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['completed_month']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Filter by Status</label>
                            <select name="status" class="form-select">
                                <option value="all"      <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="pending"  <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed"<?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed"<?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="canceled" <?php echo $status_filter === 'canceled' ? 'selected' : ''; ?>>Canceled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Filter by Date</label>
                            <select name="date" class="form-select">
                                <option value="all"   <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Dates</option>
                                <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week"  <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>Next 7 Days</option>
                                <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>Next 30 Days</option>
                                <option value="past"  <?php echo $date_filter === 'past' ? 'selected' : ''; ?>>Past Appointments</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Search Patient</label>
                            <input type="text" name="search" class="form-control"
                                   placeholder="Name, email, or phone..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Appointments List -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-list me-2"></i>Appointments List
                        <span class="badge bg-secondary ms-2"><?php echo count($appointments); ?> results</span>
                    </h6>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="exportAppointments()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <a href="?status=all&date=all&search=" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($appointments)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No appointments found</h5>
                            <p class="text-muted">
                                <?php if (!empty($search) || $status_filter !== 'all' || $date_filter !== 'all'): ?>
                                    Try adjusting your filters or <a href="?">view all appointments</a>
                                <?php else: ?>
                                    Patients haven't booked any appointments yet.
                                    Make sure you have <a href="availability.php">availability slots</a> set up.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Patient</th>
                                        <th>Contact</th>
                                        <th>Status</th>
                                        <th>Booked On</th>
                                        <th width="180">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $appointment): ?>
                                    <tr data-appointment-id="<?php echo (int)$appointment['appointment_id']; ?>">
                                        <td>
                                            <div class="d-flex flex-column">
                                                <strong><?php echo date('M j, Y', strtotime($appointment['slot_date'])); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo date('g:i A', strtotime($appointment['start_time'])); ?> -
                                                    <?php echo date('g:i A', strtotime($appointment['end_time'])); ?>
                                                </small>
                                                <?php if ($appointment['slot_date'] === date('Y-m-d')): ?>
                                                    <span class="badge bg-info mt-1">Today</span>
                                                <?php elseif ($appointment['slot_date'] === date('Y-m-d', strtotime('+1 day'))): ?>
                                                    <span class="badge bg-warning mt-1">Tomorrow</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle me-2">
                                                    <?php echo strtoupper(substr($appointment['patient_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">ID: #<?php echo (int)$appointment['patient_id']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <i class="fas fa-envelope text-muted me-1"></i>
                                                <small><?php echo htmlspecialchars($appointment['patient_email']); ?></small>
                                            </div>
                                            <div>
                                                <i class="fas fa-phone text-muted me-1"></i>
                                                <small><?php echo htmlspecialchars($appointment['patient_phone']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_class = 'secondary';
                                            $icon = 'fas fa-question';
                                            switch($appointment['status']) {
                                                case 'confirmed':
                                                    $badge_class = 'success'; $icon = 'fas fa-check'; break;
                                                case 'pending':
                                                    $badge_class = 'warning'; $icon = 'fas fa-clock'; break;
                                                case 'completed':
                                                    $badge_class = 'info';    $icon = 'fas fa-check-double'; break;
                                                case 'canceled':
                                                    $badge_class = 'danger';  $icon = 'fas fa-times'; break;
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class; ?>">
                                                <i class="<?php echo $icon; ?> me-1"></i>
                                                <?php echo ucfirst($appointment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo isset($appointment['booked_at']) ? date('M j, Y', strtotime($appointment['booked_at'])) : ''; ?>
                                                <br>
                                                <?php echo isset($appointment['booked_at']) ? date('g:i A', strtotime($appointment['booked_at'])) : ''; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons d-flex gap-1">
                                                <!-- View Details Button -->
                                                <button class="btn btn-sm btn-outline-primary"
                                                        onclick="viewAppointmentDetails(<?php echo (int)$appointment['appointment_id']; ?>)"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip">
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <!-- Status-specific action buttons -->
                                                <?php if ($appointment['status'] === 'pending'): ?>
                                                    <button class="btn btn-sm btn-success"
                                                            onclick="quickUpdateStatus(<?php echo (int)$appointment['appointment_id']; ?>, 'confirmed')"
                                                            title="Confirm Appointment"
                                                            data-bs-toggle="tooltip">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                <?php elseif ($appointment['status'] === 'confirmed'): ?>
                                                    <button class="btn btn-sm btn-info"
                                                            onclick="quickUpdateStatus(<?php echo (int)$appointment['appointment_id']; ?>, 'completed')"
                                                            title="Mark as Completed"
                                                            data-bs-toggle="tooltip">
                                                        <i class="fas fa-check-double"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger"
                                                            onclick="quickUpdateStatus(<?php echo (int)$appointment['appointment_id']; ?>, 'canceled')"
                                                            title="Cancel Appointment"
                                                            data-bs-toggle="tooltip">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <!-- Always show edit button -->
                                                <button class="btn btn-sm btn-outline-secondary"
                                                        onclick="editAppointment(<?php echo (int)$appointment['appointment_id']; ?>)"
                                                        title="Edit Appointment"
                                                        data-bs-toggle="tooltip">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
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
    </div>
</div>

<!-- Appointment Details Modal -->
<div class="modal fade" id="appointmentDetailsModal" tabindex="-1" aria-labelledby="appointmentDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="appointmentDetailsModalLabel">
                    <i class="fas fa-calendar-check me-2"></i>Appointment Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="appointmentDetailsContent">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Appointment Modal -->
<div class="modal fade" id="editAppointmentModal" tabindex="-1" aria-labelledby="editAppointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editAppointmentForm">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title" id="editAppointmentModalLabel">
                        <i class="fas fa-edit me-2"></i>Update Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="appointment_id" id="edit_appointment_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" id="edit_status" class="form-select" required>
                            <option value="pending">Pending</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="canceled">Canceled</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Doctor Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="4"
                                  placeholder="Add any notes about this appointment..."></textarea>
                        <div class="form-text">
                            Add any medical notes, observations, or comments about this appointment.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize tooltips and page functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// View appointment details
function viewAppointmentDetails(appointmentId) {
    const modal = new bootstrap.Modal(document.getElementById('appointmentDetailsModal'));
    const content = document.getElementById('appointmentDetailsContent');
    
    // Show loading spinner
    content.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading appointment details...</p>
        </div>
    `;
    
    modal.show();

    // Create appointment details from existing data
    const row = document.querySelector(`tr[data-appointment-id="${appointmentId}"]`);
    if (row) {
        const cells = row.querySelectorAll('td');
        
        // Extract data from table row
        const dateTime = cells[0].querySelector('strong').textContent + ' ' + 
                        cells[0].querySelector('small').textContent;
        const patientName = cells[1].querySelector('strong').textContent;
        const patientId = cells[1].querySelector('small').textContent.replace('ID: #', '');
        const patientEmail = cells[2].querySelectorAll('small')[0].textContent;
        const patientPhone = cells[2].querySelectorAll('small')[1].textContent;
        const status = cells[3].querySelector('.badge').textContent.trim();
        const bookedOn = cells[4].textContent.trim();
        
        // Find the appointment data from PHP array (we'll use a simple approach)
        const appointmentData = <?php echo json_encode($appointments); ?>;
        const appointment = appointmentData.find(appt => appt.appointment_id == appointmentId);
        
        if (appointment) {
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-user me-2"></i>Patient Information
                        </h6>
                        <div class="info-group">
                            <p><strong>Name:</strong> ${escapeHtml(appointment.patient_name)}</p>
                            <p><strong>Email:</strong> ${escapeHtml(appointment.patient_email)}</p>
                            <p><strong>Phone:</strong> ${escapeHtml(appointment.patient_phone)}</p>
                            <p><strong>Address:</strong> ${escapeHtml(appointment.patient_address || 'Not provided')}</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-calendar me-2"></i>Appointment Information
                        </h6>
                        <div class="info-group">
                            <p><strong>Date:</strong> ${formatDate(appointment.slot_date)}</p>
                            <p><strong>Time:</strong> ${formatTime(appointment.start_time)} - ${formatTime(appointment.end_time)}</p>
                            <p><strong>Status:</strong> <span class="badge bg-${getStatusBadgeClass(appointment.status)}">${appointment.status.charAt(0).toUpperCase() + appointment.status.slice(1)}</span></p>
                            <p><strong>Booked On:</strong> ${formatDateTime(appointment.booked_at)}</p>
                        </div>
                    </div>
                </div>
                ${appointment.notes ? `
                    <div class="mt-4">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-sticky-note me-2"></i>Patient Notes
                        </h6>
                        <div class="notes-display">
                            ${escapeHtml(appointment.notes).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                ` : ''}
                <div class="mt-4 d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" onclick="editAppointment(${appointmentId})">
                        <i class="fas fa-edit me-2"></i>Edit Appointment
                    </button>
                </div>
            `;
        } else {
            content.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Error loading appointment details.
                </div>
            `;
        }
    } else {
        content.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Appointment not found.
            </div>
        `;
    }
}

// Quick status update with improved UX
function quickUpdateStatus(appointmentId, status) {
    const statusText = status.charAt(0).toUpperCase() + status.slice(1);
    const confirmMessage = `Are you sure you want to ${status === 'canceled' ? 'cancel' : status === 'completed' ? 'mark as completed' : 'confirm'} this appointment?`;
    
    if (confirm(confirmMessage)) {
        // Add visual feedback
        const row = document.querySelector(`tr[data-appointment-id="${appointmentId}"]`);
        if (row) {
            row.style.opacity = '0.6';
            row.style.pointerEvents = 'none';
        }

        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('appointment_id', appointmentId);
        formData.append('status', status);
        formData.append('notes', '');

        fetch('', { 
            method: 'POST', 
            body: formData 
        })
        .then(response => {
            if (response.ok) {
                // Show success message before reload
                showToast(`Appointment ${statusText.toLowerCase()} successfully!`, 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                throw new Error('Network response was not ok');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (row) {
                row.style.opacity = '1';
                row.style.pointerEvents = 'auto';
            }
            showToast('Error updating appointment status. Please try again.', 'error');
        });
    }
}

// Edit appointment (prefill modal with existing data)
function editAppointment(appointmentId) {
    // Find appointment data from the PHP array
    const appointmentData = <?php echo json_encode($appointments); ?>;
    const appointment = appointmentData.find(appt => appt.appointment_id == appointmentId);
    
    if (appointment) {
        document.getElementById('edit_appointment_id').value = appointmentId;
        document.getElementById('edit_status').value = appointment.status;
        document.getElementById('edit_notes').value = appointment.notes || '';

        const modal = new bootstrap.Modal(document.getElementById('editAppointmentModal'));
        modal.show();
    } else {
        showToast('Error loading appointment data', 'error');
    }
}

// Export appointments to CSV
function exportAppointments() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.open('?' + params.toString(), '_blank');
}

// Toast notification system
function showToast(message, type = 'info') {
    const toastContainer = getOrCreateToastContainer();
    const toastId = 'toast-' + Date.now();
    
    const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
    const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
    
    const toastHtml = `
        <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header ${bgClass} text-white">
                <i class="fas ${icon} me-2"></i>
                <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${escapeHtml(message)}
            </div>
        </div>
    `;
    
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement);
    toast.show();
    
    // Remove from DOM after hiding
    toastElement.addEventListener('hidden.bs.toast', function() {
        toastElement.remove();
    });
}

function getOrCreateToastContainer() {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'position-fixed top-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    return container;
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'long'
    });
}

function formatTime(timeStr) {
    return new Date('2000-01-01 ' + timeStr).toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

function formatDateTime(dateTimeStr) {
    return new Date(dateTimeStr).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

function getStatusBadgeClass(status) {
    switch(status) {
        case 'confirmed': return 'success';
        case 'pending': return 'warning';
        case 'completed': return 'info';
        case 'canceled': return 'danger';
        default: return 'secondary';
    }
}

// Auto-refresh for today's view
if (new URLSearchParams(window.location.search).get('date') === 'today') {
    setTimeout(() => { location.reload(); }, 300000); // 5 minutes
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey) {
        const form = document.querySelector('form[method="GET"]');
        if (!form) return;
        const statusSel = form.querySelector('select[name="status"]');
        switch(e.key) {
            case '1':
                e.preventDefault(); statusSel.value = 'pending'; form.submit(); break;
            case '2':
                e.preventDefault(); statusSel.value = 'confirmed'; form.submit(); break;
            case '3':
                e.preventDefault(); statusSel.value = 'completed'; form.submit(); break;
        }
    }
});

// Handle edit form submission
document.getElementById('editAppointmentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    // Show loading state
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
    submitBtn.disabled = true;
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('editAppointmentModal'));
            modal.hide();
            showToast('Appointment updated successfully!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            throw new Error('Network response was not ok');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error updating appointment. Please try again.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});
</script>

<style>
.border-left-primary { border-left: 4px solid #4e73df !important; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
.border-left-info    { border-left: 4px solid #36b9cc !important; }
.border-left-warning { border-left: 4px solid #f6c23e !important; }

.text-xs { font-size: 0.7rem; }

.avatar-circle {
    width: 40px; height: 40px; border-radius: 50%;
    background: linear-gradient(45deg, #4e73df, #224abe);
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: bold; font-size: 18px;
    flex-shrink: 0;
}

.table-hover tbody tr:hover { 
    background-color: rgba(78, 115, 223, 0.1); 
    transition: background-color 0.2s ease;
}

.action-buttons {
    min-width: 160px;
}

.action-buttons .btn {
    border-radius: 0.375rem !important;
    margin-bottom: 2px;
    transition: all 0.2s ease;
}

.action-buttons .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.badge { 
    font-size: 0.75em;
    font-weight: 500;
    padding: 0.4em 0.6em;
}

.card { 
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    border: 1px solid rgba(0,0,0,0.1);
}

/* Status-based row highlighting */
tr:has(.badge.bg-warning) { background-color: rgba(255, 193, 7, 0.05); }
tr:has(.badge.bg-success) { background-color: rgba(25, 135, 84, 0.05); }
tr:has(.badge.bg-danger)  { background-color: rgba(220, 53, 69, 0.05); }
tr:has(.badge.bg-info)    { background-color: rgba(13, 202, 240, 0.05); }

.alert { 
    border: none; 
    border-radius: 0.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 15px 35px rgba(0,0,0,0.2);
}

.modal-header {
    border-bottom: 1px solid rgba(0,0,0,0.1);
    border-radius: 12px 12px 0 0;
}

.modal-footer {
    border-top: 1px solid rgba(0,0,0,0.1);
    border-radius: 0 0 12px 12px;
}

.info-group p {
    margin-bottom: 0.75rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f1f3f4;
}

.info-group p:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.notes-display {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 1rem;
    font-size: 0.95rem;
    line-height: 1.5;
}

/* Toast styling */
.toast {
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border: none;
}

.toast-header.bg-success,
.toast-header.bg-danger,
.toast-header.bg-info {
    border: none;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
        min-width: auto;
    }
    
    .action-buttons .btn {
        margin-bottom: 4px;
        margin-right: 0 !important;
        width: 100%;
    }
    
    .table-responsive { 
        font-size: 0.875rem;
    }
    
    .card-header .d-flex {
        flex-direction: column;
        gap: 10px;
    }
    
    .avatar-circle {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
}

@media (max-width: 576px) {
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .table th, .table td {
        padding: 0.5rem;
        font-size: 0.8rem;
    }
    
    .d-flex.gap-2 {
        flex-direction: column;
        gap: 0.5rem !important;
    }
}

/* Form enhancements */
.form-select:focus,
.form-control:focus {
    border-color: #4e73df;
    box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
}

/* Button loading states */
.btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

/* Smooth transitions */
.btn, .card, .badge {
    transition: all 0.2s ease;
}
</style>

<?php include '../includes/footer.php'; ?>