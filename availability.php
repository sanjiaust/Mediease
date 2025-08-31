<?php
// File: doctor/availability.php

// Start session & DB BEFORE any output
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'] . '/mediease/config/database.php';


// Auth guard (redirect BEFORE output)
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'doctor')) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$success_message = '';
$error_message   = '';

// DB connection (project standard)
$database = new Database();
$db = $database->getConnection();

try {
    // Get doctor id for this user; create default row if missing
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

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_slots') {
            $date       = $_POST['date']       ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time   = $_POST['end_time']   ?? '';
            $duration   = (int)($_POST['duration'] ?? 0);

            // Validation
            $errors = [];
            if (empty($date) || $date < date('Y-m-d'))                           { $errors[] = "Please select a valid future date"; }
            if (empty($start_time) || empty($end_time))                           { $errors[] = "Please select start and end times"; }
            if ($start_time && $end_time && $start_time >= $end_time)            { $errors[] = "End time must be after start time"; }
            if ($duration < 15 || $duration > 120)                                { $errors[] = "Duration must be between 15 and 120 minutes"; }

            if (empty($errors)) {
                try {
                    $current_time = new DateTime("$date $start_time");
                    $end_datetime = new DateTime("$date $end_time");
                    $interval     = new DateInterval('PT' . $duration . 'M');

                    $slots_created = 0;

                    while ($current_time < $end_datetime) {
                        $slot_start = $current_time->format('H:i:s');
                        $current_time->add($interval);

                        if ($current_time > $end_datetime) {
                            break;
                        }

                        $slot_end = $current_time->format('H:i:s');

                        // Check if slot already exists
                        $check_stmt = $db->prepare("
                            SELECT slot_id FROM availability_slots
                            WHERE doctor_id = ? AND slot_date = ? AND start_time = ? AND end_time = ?
                            LIMIT 1
                        ");
                        $check_stmt->execute([$doctor_id, $date, $slot_start, $slot_end]);

                        if (!$check_stmt->fetch(PDO::FETCH_ASSOC)) {
                            $insert_slot = $db->prepare("
                                INSERT INTO availability_slots (doctor_id, slot_date, start_time, end_time, is_available)
                                VALUES (?, ?, ?, ?, 1)
                            ");
                            $insert_slot->execute([$doctor_id, $date, $slot_start, $slot_end]);
                            $slots_created++;
                        }
                    }

                    if ($slots_created > 0) {
                        $success_message = "Created {$slots_created} availability slots successfully!";
                    } else {
                        $error_message = "No new slots were created. They may already exist.";
                    }
                } catch (Throwable $e) {
                    $error_message = "Error creating slots: " . $e->getMessage();
                }
            } else {
                $error_message = implode(", ", $errors);
            }
        }

        elseif ($action === 'delete_slot') {
            $slot_id = (int)($_POST['slot_id'] ?? 0);

            // Check if slot has appointments
            $appointment_check = $db->prepare("SELECT COUNT(*) FROM appointments WHERE slot_id = ?");
            $appointment_check->execute([$slot_id]);
            $appointment_count = (int)$appointment_check->fetchColumn();

            if ($appointment_count > 0) {
                $error_message = "Cannot delete slot with existing appointments";
            } else {
                $delete_stmt = $db->prepare("DELETE FROM availability_slots WHERE slot_id = ? AND doctor_id = ?");
                if ($delete_stmt->execute([$slot_id, $doctor_id])) {
                    $success_message = "Slot deleted successfully!";
                } else {
                    $error_message = "Error deleting slot";
                }
            }
        }

        elseif ($action === 'toggle_availability') {
            $slot_id      = (int)($_POST['slot_id'] ?? 0);
            $is_available = (int)($_POST['is_available'] ?? 0);

            $update_stmt = $db->prepare("
                UPDATE availability_slots
                SET is_available = ?
                WHERE slot_id = ? AND doctor_id = ?
            ");

            if ($update_stmt->execute([$is_available, $slot_id, $doctor_id])) {
                $status = $is_available ? 'enabled' : 'disabled';
                $success_message = "Slot {$status} successfully!";
            } else {
                $error_message = "Error updating slot availability";
            }
        }
    }

    // Get current availability slots (next 30 days)
    $slots_query = "
        SELECT s.*,
               COUNT(a.appointment_id)               AS appointment_count,
               GROUP_CONCAT(u.name SEPARATOR ', ')   AS patient_names
        FROM availability_slots s
        LEFT JOIN appointments a ON s.slot_id = a.slot_id
        LEFT JOIN users u        ON a.patient_id = u.user_id
        WHERE s.doctor_id = ?
          AND s.slot_date >= CURDATE()
          AND s.slot_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        GROUP BY s.slot_id
        ORDER BY s.slot_date ASC, s.start_time ASC
    ";

    $slots_stmt = $db->prepare($slots_query);
    $slots_stmt->execute([$doctor_id]);
    $slots = $slots_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group slots by date
    $slots_by_date = [];
    foreach ($slots as $slot) {
        $d = $slot['slot_date'];
        $slots_by_date[$d][] = $slot;
    }

} catch (Throwable $e) {
    $error_message = $error_message ?: ('Database error: ' . $e->getMessage());
    $slots_by_date = [];
}

// Include header only AFTER all redirects and data prep (it outputs HTML)
include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-calendar-plus text-primary me-2"></i>Manage Availability</h2>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="schedule.php" class="btn btn-outline-primary">
                        <i class="fas fa-calendar-week"></i> View Schedule
                    </a>
                </div>
            </div>
        </div>
    </div>

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

    <div class="row">
        <!-- Add New Slots Form -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-plus-circle me-2"></i>Add New Availability Slots
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="addSlotsForm">
                        <input type="hidden" name="action" value="add_slots">

                        <div class="mb-3">
                            <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="date" name="date"
                                   min="<?php echo date('Y-m-d'); ?>"
                                   max="<?php echo date('Y-m-d', strtotime('+3 months')); ?>" required>
                        </div>

                        <div class="row mb-3">
                            <div class="col-6">
                                <label for="start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="start_time" name="start_time" required>
                            </div>
                            <div class="col-6">
                                <label for="end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="end_time" name="end_time" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="duration" class="form-label">Slot Duration (minutes)</label>
                            <select class="form-select" id="duration" name="duration" required>
                                <option value="15">15 minutes</option>
                                <option value="30" selected>30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60">1 hour</option>
                                <option value="90">1.5 hours</option>
                                <option value="120">2 hours</option>
                            </select>
                            <small class="form-text text-muted">
                                Time slots will be created automatically based on this duration
                            </small>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Slots
                            </button>
                        </div>
                    </form>

                    <!-- Quick Add Buttons -->
                    <hr class="my-4">
                    <h6 class="text-muted">Quick Add Templates</h6>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-secondary btn-sm" onclick="quickFill('09:00', '17:00', 30)">
                            <i class="fas fa-business-time"></i> Business Hours (9 AM - 5 PM)
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="quickFill('08:00', '12:00', 30)">
                            <i class="fas fa-sun"></i> Morning Shift (8 AM - 12 PM)
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="quickFill('14:00', '18:00', 30)">
                            <i class="fas fa-moon"></i> Afternoon Shift (2 PM - 6 PM)
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Availability -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-calendar-check me-2"></i>Current Availability (Next 30 Days)
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($slots_by_date)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No availability slots found</h5>
                            <p class="text-muted">Create your first availability slots using the form on the left.</p>
                        </div>
                    <?php else: ?>
                        <div class="accordion" id="availabilityAccordion">
                            <?php foreach ($slots_by_date as $date => $date_slots): ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading<?php echo str_replace('-', '', $date); ?>">
                                        <button class="accordion-button <?php echo $date === date('Y-m-d') ? '' : 'collapsed'; ?>"
                                                type="button" data-bs-toggle="collapse"
                                                data-bs-target="#collapse<?php echo str_replace('-', '', $date); ?>">
                                            <div class="d-flex w-100 justify-content-between align-items-center me-3">
                                                <span>
                                                    <i class="fas fa-calendar-day me-2"></i>
                                                    <?php echo date('l, F j, Y', strtotime($date)); ?>
                                                    <?php if ($date === date('Y-m-d')): ?>
                                                        <span class="badge bg-primary ms-2">Today</span>
                                                    <?php endif; ?>
                                                </span>
                                                <small class="text-muted">
                                                    <?php echo count($date_slots); ?> slots
                                                </small>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse<?php echo str_replace('-', '', $date); ?>"
                                         class="accordion-collapse collapse <?php echo $date === date('Y-m-d') ? 'show' : ''; ?>"
                                         data-bs-parent="#availabilityAccordion">
                                        <div class="accordion-body">
                                            <div class="row">
                                                <?php foreach ($date_slots as $slot): ?>
                                                    <div class="col-md-6 col-lg-4 mb-3">
                                                        <div class="card border-left-<?php echo $slot['is_available'] ? 'success' : 'secondary'; ?>">
                                                            <div class="card-body p-3">
                                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                                    <div>
                                                                        <h6 class="card-title mb-1">
                                                                            <?php echo date('g:i A', strtotime($slot['start_time'])); ?> -
                                                                            <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                                                                        </h6>
                                                                        <small class="text-muted">
                                                                            <?php
                                                                            $start = new DateTime($slot['start_time']);
                                                                            $end   = new DateTime($slot['end_time']);
                                                                            $duration = $start->diff($end);
                                                                            echo ($duration->h * 60 + $duration->i) . ' min';
                                                                            ?>
                                                                        </small>
                                                                    </div>
                                                                    <div class="dropdown">
                                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                                                type="button" data-bs-toggle="dropdown">
                                                                            <i class="fas fa-ellipsis-v"></i>
                                                                        </button>
                                                                        <ul class="dropdown-menu">
                                                                            <li>
                                                                                <form method="POST" class="dropdown-item-form">
                                                                                    <input type="hidden" name="action" value="toggle_availability">
                                                                                    <input type="hidden" name="slot_id" value="<?php echo (int)$slot['slot_id']; ?>">
                                                                                    <input type="hidden" name="is_available" value="<?php echo $slot['is_available'] ? 0 : 1; ?>">
                                                                                    <button type="submit" class="dropdown-item">
                                                                                        <i class="fas fa-<?php echo $slot['is_available'] ? 'eye-slash' : 'eye'; ?> me-2"></i>
                                                                                        <?php echo $slot['is_available'] ? 'Disable' : 'Enable'; ?>
                                                                                    </button>
                                                                                </form>
                                                                            </li>
                                                                            <?php if ((int)$slot['appointment_count'] === 0): ?>
                                                                            <li>
                                                                                <form method="POST" class="dropdown-item-form"
                                                                                      onsubmit="return confirm('Are you sure you want to delete this slot?')">
                                                                                    <input type="hidden" name="action" value="delete_slot">
                                                                                    <input type="hidden" name="slot_id" value="<?php echo (int)$slot['slot_id']; ?>">
                                                                                    <button type="submit" class="dropdown-item text-danger">
                                                                                        <i class="fas fa-trash me-2"></i>Delete
                                                                                    </button>
                                                                                </form>
                                                                            </li>
                                                                            <?php endif; ?>
                                                                        </ul>
                                                                    </div>
                                                                </div>

                                                                <div class="mb-2">
                                                                    <?php if ($slot['is_available']): ?>
                                                                        <span class="badge bg-success">Available</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-secondary">Unavailable</span>
                                                                    <?php endif; ?>

                                                                    <?php if ((int)$slot['appointment_count'] > 0): ?>
                                                                        <span class="badge bg-info"><?php echo (int)$slot['appointment_count']; ?> booked</span>
                                                                    <?php endif; ?>
                                                                </div>

                                                                <?php if (!empty($slot['patient_names'])): ?>
                                                                    <div class="mt-2">
                                                                        <small class="text-muted">
                                                                            <i class="fas fa-user me-1"></i>
                                                                            <?php echo htmlspecialchars($slot['patient_names']); ?>
                                                                        </small>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
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

    <!-- Bulk Actions -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-tasks me-2"></i>Bulk Actions
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-primary w-100" onclick="showWeeklyTemplate()">
                                <i class="fas fa-calendar-week"></i><br>
                                <small>Weekly Template</small>
                            </button>
                        </div>
                    <!--
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-success w-100" onclick="enableAllSlots()">
                                <i class="fas fa-check-circle"></i><br>
                                <small>Enable All</small>
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-warning w-100" onclick="disableAllSlots()">
                                <i class="fas fa-pause-circle"></i><br>
                                <small>Disable All</small>
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-danger w-100" onclick="deleteEmptySlots()">
                                <i class="fas fa-trash-alt"></i><br>
                                <small>Delete Empty</small>
                            </button>
                        </div>
                                                                -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Weekly Template Modal -->
<div class="modal fade" id="weeklyTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-week me-2"></i>Create Weekly Template
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="weeklyTemplateForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="template_start_date"
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Number of Weeks</label>
                            <select class="form-select" id="template_weeks" required>
                                <option value="1">1 week</option>
                                <option value="2">2 weeks</option>
                                <option value="4" selected>4 weeks</option>
                                <option value="8">8 weeks</option>
                                <option value="12">12 weeks</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Working Days</label>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="monday" checked>
                                    <label class="form-check-label" for="monday">Monday</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="2" id="tuesday" checked>
                                    <label class="form-check-label" for="tuesday">Tuesday</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="3" id="wednesday" checked>
                                    <label class="form-check-label" for="wednesday">Wednesday</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="4" id="thursday" checked>
                                    <label class="form-check-label" for="thursday">Thursday</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="5" id="friday" checked>
                                    <label class="form-check-label" for="friday">Friday</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="6" id="saturday">
                                    <label class="form-check-label" for="saturday">Saturday</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control" id="template_start_time" value="09:00" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-control" id="template_end_time" value="17:00" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Slot Duration</label>
                            <select class="form-select" id="template_duration" required>
                                <option value="15">15 minutes</option>
                                <option value="30" selected>30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60">1 hour</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createWeeklyTemplate()">
                    <i class="fas fa-magic"></i> Create Template
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Quick fill function
function quickFill(startTime, endTime, duration) {
    document.getElementById('start_time').value = startTime;
    document.getElementById('end_time').value = endTime;
    document.getElementById('duration').value = duration;
}

// Show weekly template modal
function showWeeklyTemplate() {
    const modal = new bootstrap.Modal(document.getElementById('weeklyTemplateModal'));
    modal.show();
}

// Create weekly template
function createWeeklyTemplate() {
    const startDate = document.getElementById('template_start_date').value;
    const weeks     = document.getElementById('template_weeks').value;
    const startTime = document.getElementById('template_start_time').value;
    const endTime   = document.getElementById('template_end_time').value;
    const duration  = document.getElementById('template_duration').value;

    const workingDays = [];
    ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'].forEach((day, index) => {
        const checkbox = document.getElementById(day);
        if (checkbox && checkbox.checked) { workingDays.push(index + 1); }
    });

    if (!startDate || !startTime || !endTime || workingDays.length === 0) {
        alert('Please fill in all required fields');
        return;
    }

    // Create slots for each week
    const promises = [];

    for (let week = 0; week < weeks; week++) {
        const weekStart = new Date(startDate);
        weekStart.setDate(weekStart.getDate() + (week * 7));

        workingDays.forEach(dayOfWeek => {
            const slotDate = new Date(weekStart);
            const dayDiff  = dayOfWeek - slotDate.getDay();
            slotDate.setDate(slotDate.getDate() + dayDiff);

            if (slotDate >= new Date()) {
                const formData = new FormData();
                formData.append('action', 'add_slots');
                formData.append('date', slotDate.toISOString().split('T')[0]);
                formData.append('start_time', startTime);
                formData.append('end_time', endTime);
                formData.append('duration', duration);

                promises.push(fetch('', { method: 'POST', body: formData }));
            }
        });
    }

    Promise.all(promises).then(() => { location.reload(); });
}
function bulkUpdateSlots(action) {
    fetch('../api/bulk_slot_actions.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: action,
            doctor_id: <?php echo $doctor_id; ?>  // This PHP variable must be valid and properly embedded
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();  // Reload to reflect changes
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}



// Form validation
document.getElementById('addSlotsForm').addEventListener('submit', function(e) {
    const startTime = document.getElementById('start_time').value;
    const endTime   = document.getElementById('end_time').value;

    if (startTime >= endTime) {
        e.preventDefault();
        alert('End time must be after start time');
        return;
    }

    const start = new Date('2000-01-01 ' + startTime);
    const end   = new Date('2000-01-01 ' + endTime);
    const diffMinutes = (end - start) / (1000 * 60);

    if (diffMinutes > 480) { // 8 hours
        if (!confirm('You are creating slots for more than 8 hours. Are you sure?')) {
            e.preventDefault();
            return;
        }
    }
});

// Auto-set date to tomorrow if after 6 PM
document.addEventListener('DOMContentLoaded', function() {
    const dateInput  = document.getElementById('date');
    const currentHour = new Date().getHours();

    if (currentHour >= 18) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        dateInput.value = tomorrow.toISOString().split('T')[0];
    } else {
        dateInput.value = new Date().toISOString().split('T')[0];
    }
});
</script>

<style>
.border-left-success   { border-left: 4px solid #1cc88a !important; }
.border-left-secondary { border-left: 4px solid #6c757d !important; }

.dropdown-item-form { margin: 0; }
.dropdown-item-form button {
    background: none; border: none; width: 100%; text-align: left; padding: 0.25rem 1rem;
}
.dropdown-item-form button:hover { background-color: var(--bs-gray-100); }

.accordion-button:not(.collapsed) { background-color: rgba(78, 115, 223, 0.1); color: #4e73df; }

.card { transition: transform 0.2s ease-in-out; }
.card:hover { transform: translateY(-2px); }

.badge { font-size: 0.7em; }
</style>

<?php include '../includes/footer.php'; ?>
