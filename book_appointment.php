<?php
// File: patient/book_appointment.php
// Start session and handle redirects BEFORE including header

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Auth check BEFORE any output
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header('Location: ../auth/login.php');
    exit();
}

require_once dirname(__DIR__) . '/config/database.php';

$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$success_message = '';
$error_message = '';

// Doctor ID validation BEFORE any output
if (!$doctor_id) {
    header('Location: doctors.php');
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get doctor details
    $doctor_stmt = $db->prepare("
        SELECT d.doctor_id, d.specialization, d.qualifications, d.experience,
               u.name, u.email, u.phone, u.address
        FROM doctors d
        JOIN users u ON d.user_id = u.user_id
        WHERE d.doctor_id = ? AND u.role = 'doctor'
    ");
    $doctor_stmt->execute([$doctor_id]);
    $doctor = $doctor_stmt->fetch();
    
    // Doctor validation BEFORE any output
    if (!$doctor) {
        header('Location: doctors.php');
        exit();
    }
    
    // Handle appointment booking
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $slot_id = (int)($_POST['slot_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($slot_id) {
            // Check if slot is still available
            $slot_check = $db->prepare("
                SELECT slot_id FROM availability_slots 
                WHERE slot_id = ? AND doctor_id = ? AND is_available = 1 AND slot_date >= CURDATE()
            ");
            $slot_check->execute([$slot_id, $doctor_id]);
            
            if ($slot_check->fetch()) {
                // Book the appointment
                $db->beginTransaction();
                
                try {
                    // Insert appointment
                    $book_stmt = $db->prepare("
                        INSERT INTO appointments (patient_id, doctor_id, slot_id, notes, status, booked_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                    ");
                    $book_stmt->execute([$_SESSION['user_id'], $doctor_id, $slot_id, $notes]);
                    
                    // Mark slot as unavailable
                    $update_slot = $db->prepare("
                        UPDATE availability_slots SET is_available = 0 WHERE slot_id = ?
                    ");
                    $update_slot->execute([$slot_id]);
                    
                    $db->commit();
                    $success_message = 'Appointment booked successfully! You will receive a confirmation shortly.';
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $error_message = 'Failed to book appointment. Please try again.';
                }
            } else {
                $error_message = 'Sorry, this time slot is no longer available.';
            }
        } else {
            $error_message = 'Please select a time slot.';
        }
    }
    
    // Get available slots for this doctor (next 30 days)
    $slots_stmt = $db->prepare("
        SELECT slot_id, slot_date, start_time, end_time
        FROM availability_slots
        WHERE doctor_id = ? AND is_available = 1 
          AND slot_date >= CURDATE() 
          AND slot_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY slot_date, start_time
    ");
    $slots_stmt->execute([$doctor_id]);
    $available_slots = $slots_stmt->fetchAll();
    
} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $doctor = null;
    $available_slots = [];
}

// NOW include header after all potential redirects are handled
include '../includes/header.php';
?>

<div class="container my-4">
    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="card dashboard-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h2 class="mb-2">
                            <i class="fas fa-calendar-plus me-2"></i>Book Appointment
                        </h2>
                        <p class="mb-0">Schedule your appointment with Dr. <?php echo htmlspecialchars($doctor['name'] ?? 'Unknown'); ?></p>
                    </div>
                    <a href="doctors.php" class="btn btn-light mt-2 mt-md-0">
                        <i class="fas fa-arrow-left me-2"></i>Back to Doctors
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show mt-4" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mt-4">
        <!-- Doctor Information -->
        <div class="col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="doctor-avatar mx-auto mb-3">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <h4 class="card-title"><?php echo htmlspecialchars($doctor['name'] ?? 'Unknown Doctor'); ?></h4>
                    <p class="text-primary fw-bold mb-3">
                        <i class="fas fa-stethoscope me-1"></i>
                        <?php echo htmlspecialchars($doctor['specialization'] ?? 'General Medicine'); ?>
                    </p>
                    
                    <div class="text-start">
                        <?php if (!empty($doctor['qualifications'])): ?>
                        <div class="mb-3">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-graduation-cap me-2"></i>Qualifications
                            </h6>
                            <p class="small mb-0"><?php echo htmlspecialchars($doctor['qualifications']); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-calendar-alt me-2"></i>Experience
                            </h6>
                            <p class="small mb-0"><?php echo (int)($doctor['experience'] ?? 0); ?> years</p>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-envelope me-2"></i>Contact
                            </h6>
                            <p class="small mb-1"><?php echo htmlspecialchars($doctor['email'] ?? ''); ?></p>
                            <p class="small mb-0"><?php echo htmlspecialchars($doctor['phone'] ?? ''); ?></p>
                        </div>
                        
                        <?php if (!empty($doctor['address'])): ?>
                        <div class="mb-0">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-map-marker-alt me-2"></i>Location
                            </h6>
                            <p class="small mb-0"><?php echo htmlspecialchars($doctor['address']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking Form -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar me-2"></i>Select Appointment Time
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($available_slots)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times fa-4x text-muted mb-4"></i>
                            <h4 class="text-muted mb-3">No Available Slots</h4>
                            <p class="text-muted mb-4">
                                Dr. <?php echo htmlspecialchars($doctor['name'] ?? 'This doctor'); ?> has no available appointment slots in the next 30 days.
                            </p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="doctors.php" class="btn btn-primary">
                                    <i class="fas fa-search me-2"></i>Find Other Doctors
                                </a>
                                <a href="dashboard.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-home me-2"></i>Dashboard
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="bookingForm" novalidate>
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-clock me-2"></i>Available Time Slots
                                    <span class="text-muted">(Select one)</span>
                                </label>
                                
                                <div id="slots-container">
                                    <?php 
                                    $current_date = '';
                                    $slot_count = 0;
                                    
                                    foreach ($available_slots as $slot):
                                        $slot_date_formatted = date('l, F j, Y', strtotime($slot['slot_date']));
                                        $time_formatted = date('g:i A', strtotime($slot['start_time'])) . ' - ' . 
                                                        date('g:i A', strtotime($slot['end_time']));
                                        
                                        // Check if this is today or tomorrow for special labels
                                        $today = date('Y-m-d');
                                        $tomorrow = date('Y-m-d', strtotime('+1 day'));
                                        $day_label = '';
                                        
                                        if ($slot['slot_date'] === $today) {
                                            $day_label = ' <span class="badge bg-success">Today</span>';
                                        } elseif ($slot['slot_date'] === $tomorrow) {
                                            $day_label = ' <span class="badge bg-info">Tomorrow</span>';
                                        }
                                        
                                        if ($current_date !== $slot['slot_date']) {
                                            if ($current_date !== '') echo '</div></div>';
                                            $current_date = $slot['slot_date'];
                                            echo '<div class="date-group mb-4">';
                                            echo '<h6 class="text-primary border-bottom pb-2 mb-3">' . $slot_date_formatted . $day_label . '</h6>';
                                            echo '<div class="row g-2">';
                                        }
                                        $slot_count++;
                                    ?>
                                        <div class="col-md-6 col-xl-4">
                                            <div class="slot-option">
                                                <input class="form-check-input slot-radio" 
                                                       type="radio" 
                                                       name="slot_id" 
                                                       id="slot<?php echo $slot['slot_id']; ?>" 
                                                       value="<?php echo $slot['slot_id']; ?>" 
                                                       required>
                                                <label class="slot-label" for="slot<?php echo $slot['slot_id']; ?>">
                                                    <div class="slot-time">
                                                        <i class="fas fa-clock me-2"></i>
                                                        <?php echo $time_formatted; ?>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($current_date !== '') echo '</div></div>'; ?>
                                </div>
                                
                                <div class="slot-selection-summary mt-3 d-none" id="selectionSummary">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Selected:</strong> <span id="selectedSlotText"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="notes" class="form-label fw-bold">
                                    <i class="fas fa-sticky-note me-2"></i>Additional Notes 
                                    <span class="text-muted fw-normal">(Optional)</span>
                                </label>
                                <textarea class="form-control" 
                                          id="notes" 
                                          name="notes" 
                                          rows="4" 
                                          maxlength="500"
                                          placeholder="Describe your symptoms, reason for visit, or any special requirements..."></textarea>
                                <div class="form-text">
                                    <span id="notesCount">0</span>/500 characters
                                </div>
                            </div>

                            <div class="booking-summary card bg-light mb-4 d-none" id="bookingSummary">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-clipboard-check me-2"></i>Booking Summary
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-1"><strong>Doctor:</strong> Dr. <?php echo htmlspecialchars($doctor['name']); ?></p>
                                            <p class="mb-1"><strong>Specialization:</strong> <?php echo htmlspecialchars($doctor['specialization']); ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1"><strong>Date & Time:</strong> <span id="summaryDateTime"></span></p>
                                            <p class="mb-1"><strong>Status:</strong> <span class="badge bg-warning">Pending Confirmation</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="doctors.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary btn-lg" id="bookBtn" disabled>
                                    <i class="fas fa-calendar-plus me-2"></i>Book Appointment
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Confirmation Modal -->
    <div class="modal fade" id="confirmBookingModal" tabindex="-1" aria-labelledby="confirmBookingModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="confirmBookingModalLabel">
                        <i class="fas fa-calendar-check me-2"></i>Confirm Appointment Booking
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="booking-details" id="confirmationDetails">
                        <!-- Details will be populated by JavaScript -->
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Please confirm:</strong> Once booked, you'll need to wait for the doctor's confirmation before the appointment is finalized.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" id="finalConfirmBtn">
                        <i class="fas fa-check me-2"></i>Confirm Booking
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 15px;
    padding: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.doctor-avatar {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: white;
    margin-bottom: 1rem;
}

.date-group {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 1.5rem;
    background: #f8f9fa;
}

.slot-option {
    position: relative;
    margin-bottom: 0.5rem;
}

.slot-radio {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.slot-label {
    display: block;
    padding: 12px 16px;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
    font-weight: 500;
    position: relative;
    overflow: hidden;
}

.slot-label:hover {
    background: #e3f2fd;
    border-color: #2196f3;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.slot-radio:checked + .slot-label {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(102, 126, 234, 0.3);
}

.slot-radio:checked + .slot-label::after {
    content: '\f00c';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    position: absolute;
    top: 8px;
    right: 12px;
    font-size: 0.8rem;
}

.slot-time {
    font-size: 0.95rem;
}

.form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    border-radius: 25px;
    padding: 12px 30px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
}

.btn-primary:disabled {
    background: #6c757d;
    transform: none;
    box-shadow: none;
    cursor: not-allowed;
}

.alert {
    border-radius: 10px;
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.modal-content {
    border-radius: 15px;
    border: none;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

.booking-details {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 10px;
    margin-bottom: 1rem;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .dashboard-card {
        padding: 1.5rem;
        text-align: center;
    }
    
    .doctor-avatar {
        width: 80px;
        height: 80px;
        font-size: 2rem;
    }
    
    .slot-label {
        padding: 10px 12px;
        font-size: 0.9rem;
    }
    
    .date-group {
        padding: 1rem;
    }
}

@media (max-width: 576px) {
    .col-md-6.col-xl-4 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .d-grid.gap-2.d-md-flex {
        flex-direction: column;
    }
}

/* Loading animation */
.loading-spinner {
    display: inline-block;
    width: 1rem;
    height: 1rem;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Form validation styles */
.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.invalid-feedback {
    display: block;
    color: #dc3545;
    font-size: 0.875rem;
    margin-top: 0.25rem;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('bookingForm');
    const bookBtn = document.getElementById('bookBtn');
    const slotRadios = document.querySelectorAll('input[name="slot_id"]');
    const notesTextarea = document.getElementById('notes');
    const notesCount = document.getElementById('notesCount');
    const selectionSummary = document.getElementById('selectionSummary');
    const selectedSlotText = document.getElementById('selectedSlotText');
    const bookingSummary = document.getElementById('bookingSummary');
    const summaryDateTime = document.getElementById('summaryDateTime');

    // Character counter for notes
    if (notesTextarea && notesCount) {
        notesTextarea.addEventListener('input', function() {
            const count = this.value.length;
            notesCount.textContent = count;
            
            if (count > 450) {
                notesCount.style.color = '#dc3545';
            } else if (count > 400) {
                notesCount.style.color = '#ffc107';
            } else {
                notesCount.style.color = '#6c757d';
            }
        });
    }

    // Slot selection handling
    slotRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                // Enable booking button
                bookBtn.disabled = false;
                
                // Get slot details
                const label = document.querySelector(`label[for="${this.id}"]`);
                const timeText = label.querySelector('.slot-time').textContent.trim();
                const dateGroup = this.closest('.date-group');
                const dateText = dateGroup.querySelector('h6').textContent.replace(/Today|Tomorrow/g, '').trim();
                
                // Update selection summary
                selectedSlotText.textContent = `${dateText} at ${timeText}`;
                selectionSummary.classList.remove('d-none');
                
                // Update booking summary
                summaryDateTime.textContent = `${dateText} at ${timeText}`;
                bookingSummary.classList.remove('d-none');
                
                // Smooth scroll to summary
                setTimeout(() => {
                    bookingSummary.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 100);
            }
        });
    });

    // Form submission with confirmation
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const selectedSlot = document.querySelector('input[name="slot_id"]:checked');
        if (!selectedSlot) {
            showAlert('Please select a time slot.', 'warning');
            return;
        }
        
        // Show confirmation modal
        showConfirmationModal();
    });

    function showConfirmationModal() {
        const selectedSlot = document.querySelector('input[name="slot_id"]:checked');
        const label = document.querySelector(`label[for="${selectedSlot.id}"]`);
        const timeText = label.querySelector('.slot-time').textContent.trim();
        const dateGroup = selectedSlot.closest('.date-group');
        const dateText = dateGroup.querySelector('h6').textContent.replace(/Today|Tomorrow/g, '').trim();
        const notes = notesTextarea.value.trim();
        
        const confirmationDetails = document.getElementById('confirmationDetails');
        confirmationDetails.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-primary">Doctor Details</h6>
                    <p class="mb-1"><strong>Name:</strong> Dr. <?php echo htmlspecialchars($doctor['name']); ?></p>
                    <p class="mb-1"><strong>Specialization:</strong> <?php echo htmlspecialchars($doctor['specialization']); ?></p>
                    <p class="mb-0"><strong>Experience:</strong> <?php echo (int)$doctor['experience']; ?> years</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-primary">Appointment Details</h6>
                    <p class="mb-1"><strong>Date:</strong> ${dateText}</p>
                    <p class="mb-1"><strong>Time:</strong> ${timeText}</p>
                    <p class="mb-0"><strong>Status:</strong> <span class="badge bg-warning">Pending Confirmation</span></p>
                </div>
            </div>
            ${notes ? `
                <div class="mt-3">
                    <h6 class="text-primary">Your Notes</h6>
                    <div class="border rounded p-2 bg-white">
                        ${escapeHtml(notes)}
                    </div>
                </div>
            ` : ''}
        `;
        
        const modal = new bootstrap.Modal(document.getElementById('confirmBookingModal'));
        modal.show();
    }

    // Final confirmation and submission
    document.getElementById('finalConfirmBtn').addEventListener('click', function() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('confirmBookingModal'));
        modal.hide();
        
        // Show loading state
        const originalText = bookBtn.innerHTML;
        bookBtn.innerHTML = '<span class="loading-spinner me-2"></span>Booking...';
        bookBtn.disabled = true;
        
        // Submit form
        setTimeout(() => {
            form.submit();
        }, 500);
    });

    function showAlert(message, type = 'info') {
        const alertClass = type === 'warning' ? 'alert-warning' : 
                          type === 'success' ? 'alert-success' : 
                          type === 'error' ? 'alert-danger' : 'alert-info';
        
        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        const container = document.querySelector('.container');
        container.insertAdjacentHTML('afterbegin', alertHtml);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            const alert = container.querySelector('.alert');
            if (alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Add visual feedback for slot selection
    slotRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            // Remove previous selections visual feedback
            document.querySelectorAll('.slot-label').forEach(label => {
                label.classList.remove('selected-slot');
            });
            
            // Add visual feedback to selected slot
            if (this.checked) {
                const label = document.querySelector(`label[for="${this.id}"]`);
                label.classList.add('selected-slot');
            }
        });
    });
});

// Auto-refresh available slots every 2 minutes to prevent booking conflicts
setInterval(function() {
    // Only refresh if no slot is selected to avoid disrupting user selection
    const selectedSlot = document.querySelector('input[name="slot_id"]:checked');
    if (!selectedSlot) {
        const currentUrl = new URL(window.location);
        const doctorId = currentUrl.searchParams.get('doctor_id') || <?php echo $doctor_id; ?>;
        
        fetch(`../api/get_available_slots.php?doctor_id=${doctorId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.slots) {
                    updateAvailableSlots(data.slots);
                }
            })
            .catch(error => {
                console.log('Background refresh failed:', error);
            });
    }
}, 120000); // 2 minutes

function updateAvailableSlots(slots) {
    const container = document.getElementById('slots-container');
    if (!container || slots.length === 0) return;
    
    // Build new slots HTML
    let slotsHtml = '';
    let currentDate = '';
    
    slots.forEach(slot => {
        const slotDate = new Date(slot.slot_date);
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(today.getDate() + 1);
        
        const slotDateFormatted = slotDate.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        
        const timeFormatted = formatTime(slot.start_time) + ' - ' + formatTime(slot.end_time);
        
        let dayLabel = '';
        if (slot.slot_date === today.toISOString().split('T')[0]) {
            dayLabel = ' <span class="badge bg-success">Today</span>';
        } else if (slot.slot_date === tomorrow.toISOString().split('T')[0]) {
            dayLabel = ' <span class="badge bg-info">Tomorrow</span>';
        }
        
        if (currentDate !== slot.slot_date) {
            if (currentDate !== '') slotsHtml += '</div></div>';
            currentDate = slot.slot_date;
            slotsHtml += `
                <div class="date-group mb-4">
                    <h6 class="text-primary border-bottom pb-2 mb-3">${slotDateFormatted}${dayLabel}</h6>
                    <div class="row g-2">
            `;
        }
        
        slotsHtml += `
            <div class="col-md-6 col-xl-4">
                <div class="slot-option">
                    <input class="form-check-input slot-radio" 
                           type="radio" 
                           name="slot_id" 
                           id="slot${slot.slot_id}" 
                           value="${slot.slot_id}" 
                           required>
                    <label class="slot-label" for="slot${slot.slot_id}">
                        <div class="slot-time">
                            <i class="fas fa-clock me-2"></i>
                            ${timeFormatted}
                        </div>
                    </label>
                </div>
            </div>
        `;
    });
    
    if (currentDate !== '') slotsHtml += '</div></div>';
    
    container.innerHTML = slotsHtml;
    
    // Re-attach event listeners
    attachSlotEventListeners();
}

function attachSlotEventListeners() {
    const slotRadios = document.querySelectorAll('input[name="slot_id"]');
    const bookBtn = document.getElementById('bookBtn');
    const selectionSummary = document.getElementById('selectionSummary');
    const selectedSlotText = document.getElementById('selectedSlotText');
    const bookingSummary = document.getElementById('bookingSummary');
    const summaryDateTime = document.getElementById('summaryDateTime');

    slotRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                // Enable booking button
                bookBtn.disabled = false;
                
                // Get slot details
                const label = document.querySelector(`label[for="${this.id}"]`);
                const timeText = label.querySelector('.slot-time').textContent.trim();
                const dateGroup = this.closest('.date-group');
                const dateText = dateGroup.querySelector('h6').textContent.replace(/Today|Tomorrow/g, '').trim();
                
                // Update selection summary
                selectedSlotText.textContent = `${dateText} at ${timeText}`;
                selectionSummary.classList.remove('d-none');
                
                // Update booking summary
                summaryDateTime.textContent = `${dateText} at ${timeText}`;
                bookingSummary.classList.remove('d-none');
                
                // Remove previous selections visual feedback
                document.querySelectorAll('.slot-label').forEach(label => {
                    label.classList.remove('selected-slot');
                });
                
                // Add visual feedback to selected slot
                const selectedLabel = document.querySelector(`label[for="${this.id}"]`);
                selectedLabel.classList.add('selected-slot');
                
                // Smooth scroll to summary
                setTimeout(() => {
                    bookingSummary.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 100);
            }
        });
    });
}

function formatTime(timeStr) {
    const date = new Date('2000-01-01 ' + timeStr);
    return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

// Initial attachment of event listeners
attachSlotEventListeners();

// Form validation and submission
document.getElementById('bookingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const selectedSlot = document.querySelector('input[name="slot_id"]:checked');
    if (!selectedSlot) {
        showAlert('Please select a time slot.', 'warning');
        return;
    }
    
    // Show confirmation modal
    showConfirmationModal();
});

function showConfirmationModal() {
    const selectedSlot = document.querySelector('input[name="slot_id"]:checked');
    const label = document.querySelector(`label[for="${selectedSlot.id}"]`);
    const timeText = label.querySelector('.slot-time').textContent.trim();
    const dateGroup = selectedSlot.closest('.date-group');
    const dateText = dateGroup.querySelector('h6').textContent.replace(/Today|Tomorrow/g, '').trim();
    const notes = document.getElementById('notes').value.trim();
    
    const confirmationDetails = document.getElementById('confirmationDetails');
    confirmationDetails.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary">Doctor Details</h6>
                <p class="mb-1"><strong>Name:</strong> Dr. <?php echo htmlspecialchars($doctor['name']); ?></p>
                <p class="mb-1"><strong>Specialization:</strong> <?php echo htmlspecialchars($doctor['specialization']); ?></p>
                <p class="mb-0"><strong>Experience:</strong> <?php echo (int)$doctor['experience']; ?> years</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary">Appointment Details</h6>
                <p class="mb-1"><strong>Date:</strong> ${dateText}</p>
                <p class="mb-1"><strong>Time:</strong> ${timeText}</p>
                <p class="mb-0"><strong>Status:</strong> <span class="badge bg-warning">Pending Confirmation</span></p>
            </div>
        </div>
        ${notes ? `
            <div class="mt-3">
                <h6 class="text-primary">Your Notes</h6>
                <div class="border rounded p-2 bg-white">
                    ${escapeHtml(notes)}
                </div>
            </div>
        ` : ''}
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('confirmBookingModal'));
    modal.show();
}

// Final confirmation and submission
document.getElementById('finalConfirmBtn').addEventListener('click', function() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmBookingModal'));
    modal.hide();
    
    // Show loading state
    const bookBtn = document.getElementById('bookBtn');
    const originalText = bookBtn.innerHTML;
    bookBtn.innerHTML = '<span class="loading-spinner me-2"></span>Booking...';
    bookBtn.disabled = true;
    
    // Submit form after brief delay for UX
    setTimeout(() => {
        document.getElementById('bookingForm').submit();
    }, 800);
});

function showAlert(message, type = 'info') {
    const alertClass = type === 'warning' ? 'alert-warning' : 
                      type === 'success' ? 'alert-success' : 
                      type === 'error' ? 'alert-danger' : 'alert-info';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    const container = document.querySelector('.container');
    container.insertAdjacentHTML('afterbegin', alertHtml);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        const alert = container.querySelector('.alert');
        if (alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }
    }, 5000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Prevent double submission
let isSubmitting = false;
document.getElementById('bookingForm').addEventListener('submit', function(e) {
    if (isSubmitting) {
        e.preventDefault();
        return false;
    }
    isSubmitting = true;
});
</script>

<?php include '../includes/footer.php'; ?>