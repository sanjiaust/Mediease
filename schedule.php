<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/mediease/config/database.php';

// Initialize the database connection
$database = new Database();
$db = $database->getConnection();

// Check if user is logged in and is a doctor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get doctor ID
$doctor_stmt = $db->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
$doctor_stmt->execute([$user_id]);
$doctor = $doctor_stmt->fetch();

if (!$doctor) {
    header("Location: profile.php");
    exit();
}

$doctor_id = $doctor['doctor_id'];

// Get current week dates
$week_start = isset($_GET['week']) ? $_GET['week'] : date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));

// Generate week days
$week_days = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($week_start . ' +' . $i . ' days'));
    $week_days[] = [
        'date' => $date,
        'day_name' => date('l', strtotime($date)),
        'day_short' => date('D', strtotime($date)),
        'day_number' => date('j', strtotime($date)),
        'month' => date('M', strtotime($date))
    ];
}

// Get schedule data for the week
$schedule_query = "
    SELECT 
        av.slot_date,
        av.start_time,
        av.end_time,
        av.is_available,
        a.appointment_id,
        a.status as appointment_status,
        u.name as patient_name,
        u.phone as patient_phone
    FROM availability_slots av
    LEFT JOIN appointments a ON av.slot_id = a.slot_id
    LEFT JOIN users u ON a.patient_id = u.user_id
    WHERE av.doctor_id = ? 
    AND av.slot_date BETWEEN ? AND ?
    ORDER BY av.slot_date ASC, av.start_time ASC
";

$schedule_stmt = $db->prepare($schedule_query);
$schedule_stmt->execute([$doctor_id, $week_start, $week_end]);
$schedule_data = $schedule_stmt->fetchAll();

// Organize schedule by date
$schedule_by_date = [];
foreach ($schedule_data as $slot) {
    $date = $slot['slot_date'];
    if (!isset($schedule_by_date[$date])) {
        $schedule_by_date[$date] = [];
    }
    $schedule_by_date[$date][] = $slot;
}

// Get navigation dates
$prev_week = date('Y-m-d', strtotime($week_start . ' -7 days'));
$next_week = date('Y-m-d', strtotime($week_start . ' +7 days'));

// Get week statistics
$week_stats = [];

// Total slots this week
$total_slots_stmt = $db->prepare("SELECT COUNT(*) FROM availability_slots WHERE doctor_id = ? AND slot_date BETWEEN ? AND ?");
$total_slots_stmt->execute([$doctor_id, $week_start, $week_end]);
$week_stats['total_slots'] = $total_slots_stmt->fetchColumn();

// Booked appointments this week
$booked_appointments_stmt = $db->prepare("SELECT COUNT(*) FROM appointments a JOIN availability_slots av ON a.slot_id = av.slot_id WHERE av.doctor_id = ? AND av.slot_date BETWEEN ? AND ?");
$booked_appointments_stmt->execute([$doctor_id, $week_start, $week_end]);
$week_stats['booked'] = $booked_appointments_stmt->fetchColumn();

// Available slots this week
$available_slots_stmt = $db->prepare("SELECT COUNT(*) FROM availability_slots WHERE doctor_id = ? AND slot_date BETWEEN ? AND ? AND is_available = 1");
$available_slots_stmt->execute([$doctor_id, $week_start, $week_end]);
$week_stats['available'] = $available_slots_stmt->fetchColumn();

// Calculate utilization rate
$week_stats['utilization'] = $week_stats['total_slots'] > 0 ? 
    round(($week_stats['booked'] / $week_stats['total_slots']) * 100, 1) : 0;

include '../includes/header.php';
?>

<style>
.schedule-grid {
    overflow-x: auto;
}

.schedule-day {
    min-height: 400px;
    border-right: 1px solid #e3e6f0;
}

.schedule-day:last-child {
    border-right: none;
}

.schedule-day.today {
    background-color: #f8f9fc;
}

.day-header {
    background-color: #4e73df;
    color: white;
    padding: 15px;
    text-align: center;
    position: sticky;
    top: 0;
    z-index: 10;
}

.day-header.today {
    background-color: #e74a3b;
}

.time-slot {
    padding: 8px 12px;
    margin: 5px;
    border-radius: 5px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s;
}

.slot-available {
    background-color: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
}

.slot-booked {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.slot-unavailable {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.time-slot:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.border-left-primary {
    border-left: 4px solid #4e73df !important;
}

.border-left-success {
    border-left: 4px solid #1cc88a !important;
}

.border-left-info {
    border-left: 4px solid #36b9cc !important;
}

.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}

.patient-info {
    font-size: 10px;
    margin-top: 3px;
    font-weight: bold;
}

.quick-actions {
    position: fixed;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    z-index: 1000;
}

.quick-action-btn {
    display: block;
    margin-bottom: 10px;
    border-radius: 50px;
    padding: 12px;
    width: 50px;
    height: 50px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

@media (max-width: 768px) {
    .quick-actions {
        display: none;
    }

    .schedule-day {
        min-width: 200px;
    }
}
</style>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-calendar-week text-primary me-2"></i>Weekly Schedule</h2>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Dashboard
                    </a>
                    <a href="availability.php" class="btn btn-outline-primary">
                        <i class="fas fa-calendar-plus"></i> Manage Availability
                    </a>
                    <a href="appointments.php" class="btn btn-outline-success">
                        <i class="fas fa-list"></i> All Appointments
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Week Navigation -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="?week=<?php echo $prev_week; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-chevron-left"></i> Previous Week
                        </a>

                        <div class="text-center">
                            <h4 class="mb-0">
                                <?php echo date('F j', strtotime($week_start)); ?> -
                                <?php echo date('F j, Y', strtotime($week_end)); ?>
                            </h4>
                            <small class="text-muted">
                                <?php
                                if ($week_start <= date('Y-m-d') && $week_end >= date('Y-m-d')) {
                                    echo 'Current Week';
                                } elseif ($week_start > date('Y-m-d')) {
                                    $days_until = ceil((strtotime($week_start) - strtotime(date('Y-m-d'))) / (60*60*24));
                                    echo "In $days_until days";
                                } else {
                                    $days_ago = ceil((strtotime(date('Y-m-d')) - strtotime($week_end)) / (60*60*24));
                                    echo "$days_ago days ago";
                                }
                                ?>
                            </small>
                        </div>

                        <a href="?week=<?php echo $next_week; ?>" class="btn btn-outline-primary">
                            Next Week <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Week Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-left-primary">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Slots
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $week_stats['total_slots']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar fa-2x text-primary"></i>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Booked
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $week_stats['booked']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-check fa-2x text-success"></i>
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
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Available
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $week_stats['available']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-check fa-2x text-info"></i>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Utilization
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $week_stats['utilization']; ?>%</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-pie fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Schedule Grid -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-calendar-week me-2"></i>Weekly Schedule View
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="schedule-grid">
                        <div class="row g-0">
                            <?php foreach ($week_days as $day): ?>
                                <div class="col-lg schedule-day <?php echo $day['date'] === date('Y-m-d') ? 'today' : ''; ?>">
                                    <!-- Day Header -->
                                    <div class="day-header <?php echo $day['date'] === date('Y-m-d') ? 'today' : ''; ?>">
                                        <div class="h6 mb-0"><?php echo $day['day_name']; ?></div>
                                        <div class="small"><?php echo $day['month'] . ' ' . $day['day_number']; ?></div>
                                    </div>
                                  
                                    <!-- Day Slots -->
                                    <div class="p-2">
                                        <?php 
                                        $current_date = $day['date'];
                                        if (isset($schedule_by_date[$current_date]) && count($schedule_by_date[$current_date]) > 0): 
                                        ?>
                                            <?php foreach ($schedule_by_date[$current_date] as $slot): ?>
                                                <?php
                                                $slot_class = 'slot-available';
                                                $slot_text = 'Available';
                                                $icon = 'fas fa-clock';
                                                
                                                if ($slot['appointment_id']) {
                                                    $slot_class = 'slot-booked';
                                                    $slot_text = 'Booked';
                                                    $icon = 'fas fa-user-check';
                                                } elseif (!$slot['is_available']) {
                                                    $slot_class = 'slot-unavailable';
                                                    $slot_text = 'Unavailable';
                                                    $icon = 'fas fa-times-circle';
                                                }
                                                ?>
                                                <div class="time-slot <?php echo $slot_class; ?>" 
                                                     onclick="showSlotDetails('<?php echo $slot['start_time']; ?>', '<?php echo $slot['end_time']; ?>', '<?php echo $slot_text; ?>', '<?php echo $slot['patient_name']; ?>', '<?php echo $slot['patient_phone']; ?>')">
                                                    <div class="d-flex align-items-center">
                                                        <i class="<?php echo $icon; ?> me-2"></i>
                                                        <div class="flex-grow-1">
                                                            <div class="fw-bold">
                                                                <?php echo date('g:i A', strtotime($slot['start_time'])); ?> - 
                                                                <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                                                            </div>
                                                            <?php if ($slot['patient_name']): ?>
                                                                <div class="patient-info">
                                                                    <?php echo htmlspecialchars($slot['patient_name']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-center text-muted p-4">
                                                <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                                <div>No slots scheduled</div>
                                                <a href="availability.php" class="btn btn-sm btn-outline-primary mt-2">
                                                    Add Slots
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Legend
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title mb-3">Legend</h6>
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <span class="badge badge-info me-2">
                                <i class="fas fa-clock"></i>
                            </span>
                            Available Slots
                        </div>
                        <div class="col-md-3 mb-2">
                            <span class="badge badge-success me-2">
                                <i class="fas fa-user-check"></i>
                            </span>
                            Booked Appointments
                        </div>
                        <div class="col-md-3 mb-2">
                            <span class="badge badge-danger me-2">
                                <i class="fas fa-times-circle"></i>
                            </span>
                            Unavailable
                        </div>
                        <div class="col-md-3 mb-2">
                            <span class="badge badge-warning me-2">
                                <i class="fas fa-star"></i>
                            </span>
                            Today
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
     -->
</div>

<!-- Quick Actions -->
<div class="quick-actions d-none d-lg-block">
    <a href="availability.php" class="btn btn-primary quick-action-btn" title="Add Availability">
        <i class="fas fa-plus"></i>
    </a>
    <a href="appointments.php" class="btn btn-success quick-action-btn" title="View Appointments">
        <i class="fas fa-list"></i>
    </a>
    <a href="dashboard.php" class="btn btn-info quick-action-btn" title="Dashboard">
        <i class="fas fa-home"></i>
    </a>
</div>

<!-- Slot Details Modal -->
<div class="modal fade" id="slotDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Slot Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="slotDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function showSlotDetails(startTime, endTime, status, patientName, patientPhone) {
    let content = `
        <div class="mb-3">
            <strong>Time:</strong> ${formatTime(startTime)} - ${formatTime(endTime)}
        </div>
        <div class="mb-3">
            <strong>Status:</strong> 
            <span class="badge badge-${getStatusBadgeClass(status)}">${status}</span>
        </div>
    `;
    
    if (patientName && patientName !== '') {
        content += `
            <div class="mb-3">
                <strong>Patient:</strong> ${patientName}
            </div>
        `;
        
        if (patientPhone && patientPhone !== '') {
            content += `
                <div class="mb-3">
                    <strong>Phone:</strong> ${patientPhone}
                </div>
            `;
        }
    }
    
    document.getElementById('slotDetailsContent').innerHTML = content;
    
    // Show modal (using Bootstrap 5 syntax)
    var modal = new bootstrap.Modal(document.getElementById('slotDetailsModal'));
    modal.show();
}

function formatTime(time) {
    return new Date('1970-01-01T' + time + 'Z').toLocaleTimeString('en-US', {
        timeZone: 'UTC',
        hour12: true,
        hour: 'numeric',
        minute: '2-digit'
    });
}

function getStatusBadgeClass(status) {
    switch(status.toLowerCase()) {
        case 'available': return 'info';
        case 'booked': return 'success';
        case 'unavailable': return 'danger';
        default: return 'secondary';
    }
}

// Auto-refresh every 5 minutes to keep schedule up to date
setInterval(function() {
    location.reload();
}, 300000);
</script>

<?php include '../includes/footer.php'; ?>
