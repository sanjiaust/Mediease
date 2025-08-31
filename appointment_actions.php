<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'book_appointment':
            bookAppointment($pdo, $input);
            break;
            
        case 'cancel_appointment':
            cancelAppointment($pdo, $input);
            break;
            
        case 'update_appointment_status':
            updateAppointmentStatus($pdo, $input);
            break;
            
        case 'reschedule_appointment':
            rescheduleAppointment($pdo, $input);
            break;
            
        case 'get_appointment_details':
            getAppointmentDetails($pdo, $input);
            break;
            
        case 'add_appointment_notes':
            addAppointmentNotes($pdo, $input);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            exit();
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    exit();
}

/**
 * Book a new appointment
 */
function bookAppointment($pdo, $input) {
    $patient_id = $_SESSION['user_id'];
    $slot_id = $input['slot_id'] ?? null;
    $symptoms = $input['symptoms'] ?? null;
    $notes = $input['notes'] ?? null;
    
    if (!$slot_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing slot_id parameter']);
        return;
    }
    
    // Check if user is a patient
    if ($_SESSION['role'] !== 'patient') {
        http_response_code(403);
        echo json_encode(['error' => 'Only patients can book appointments']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if slot is still available
        $slot_check = $pdo->prepare("SELECT av.*, d.user_id as doctor_user_id, u.name as doctor_name
                                     FROM availability_slots av
                                     JOIN doctors d ON av.doctor_id = d.doctor_id
                                     JOIN users u ON d.user_id = u.user_id
                                     WHERE av.slot_id = ? AND av.is_available = 1");
        $slot_check->execute([$slot_id]);
        $slot = $slot_check->fetch();
        
        if (!$slot) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Slot not available']);
            return;
        }
        
        // Check if slot is not already booked
        $booking_check = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE slot_id = ? AND status != 'cancelled'");
        $booking_check->execute([$slot_id]);
        
        if ($booking_check->fetchColumn() > 0) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Slot already booked']);
            return;
        }
        
        // Check if slot is not in the past
        if ($slot['slot_date'] < date('Y-m-d')) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Cannot book appointments in the past']);
            return;
        }
        
        // Insert appointment
        $appointment_stmt = $pdo->prepare("INSERT INTO appointments (patient_id, slot_id, symptoms, notes, status, created_at) 
                                           VALUES (?, ?, ?, ?, 'pending', NOW())");
        $appointment_stmt->execute([$patient_id, $slot_id, $symptoms, $notes]);
        
        $appointment_id = $pdo->lastInsertId();
        
        // Log activity
        log_activity($pdo, $patient_id, 'appointment_booked', "Appointment ID: $appointment_id");
        
        $pdo->commit();
        
        // Prepare response with appointment details
        $response = [
            'success' => true,
            'appointment_id' => $appointment_id,
            'message' => 'Appointment booked successfully',
            'appointment_details' => [
                'date' => format_date($slot['slot_date']),
                'time' => format_time($slot['start_time']) . ' - ' . format_time($slot['end_time']),
                'doctor_name' => $slot['doctor_name']
            ]
        ];
        
        echo json_encode($response);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error while booking appointment']);
    }
}

/**
 * Cancel an appointment
 */
function cancelAppointment($pdo, $input) {
    $appointment_id = $input['appointment_id'] ?? null;
    $cancellation_reason = $input['reason'] ?? null;
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    if (!$appointment_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing appointment_id parameter']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get appointment details and verify ownership/permission
        $appointment_stmt = $pdo->prepare("SELECT a.*, av.doctor_id, d.user_id as doctor_user_id
                                          FROM appointments a
                                          JOIN availability_slots av ON a.slot_id = av.slot_id
                                          JOIN doctors d ON av.doctor_id = d.doctor_id
                                          WHERE a.appointment_id = ?");
        $appointment_stmt->execute([$appointment_id]);
        $appointment = $appointment_stmt->fetch();
        
        if (!$appointment) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Appointment not found']);
            return;
        }
        
        // Check permissions
        $can_cancel = false;
        if ($user_role === 'patient' && $appointment['patient_id'] == $user_id) {
            $can_cancel = true;
        } elseif ($user_role === 'doctor' && $appointment['doctor_user_id'] == $user_id) {
            $can_cancel = true;
        } elseif ($user_role === 'admin') {
            $can_cancel = true;
        }
        
        if (!$can_cancel) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Not authorized to cancel this appointment']);
            return;
        }
        
        // Check if appointment can be cancelled
        if ($appointment['status'] === 'cancelled') {
            $pdo->rollBack();
            echo json_encode(['error' => 'Appointment is already cancelled']);
            return;
        }
        
        if ($appointment['status'] === 'completed') {
            $pdo->rollBack();
            echo json_encode(['error' => 'Cannot cancel completed appointment']);
            return;
        }
        
        // Update appointment status
        $cancel_stmt = $pdo->prepare("UPDATE appointments 
                                      SET status = 'cancelled', 
                                          cancellation_reason = ?,
                                          cancelled_at = NOW(),
                                          cancelled_by = ?
                                      WHERE appointment_id = ?");
        $cancel_stmt->execute([$cancellation_reason, $user_id, $appointment_id]);
        
        // Log activity
        log_activity($pdo, $user_id, 'appointment_cancelled', "Appointment ID: $appointment_id");
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Appointment cancelled successfully'
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error while cancelling appointment']);
    }
}

/**
 * Update appointment status (for doctors/admin)
 */
function updateAppointmentStatus($pdo, $input) {
    $appointment_id = $input['appointment_id'] ?? null;
    $new_status = $input['status'] ?? null;
    $notes = $input['notes'] ?? null;
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    if (!$appointment_id || !$new_status) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }
    
    // Validate status
    $valid_statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
    if (!in_array($new_status, $valid_statuses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status']);
        return;
    }
    
    // Check permissions
    if (!in_array($user_role, ['doctor', 'admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized to update appointment status']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verify appointment exists and user has permission
        $appointment_stmt = $pdo->prepare("SELECT a.*, av.doctor_id, d.user_id as doctor_user_id
                                          FROM appointments a
                                          JOIN availability_slots av ON a.slot_id = av.slot_id
                                          JOIN doctors d ON av.doctor_id = d.doctor_id
                                          WHERE a.appointment_id = ?");
        $appointment_stmt->execute([$appointment_id]);
        $appointment = $appointment_stmt->fetch();
        
        if (!$appointment) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Appointment not found']);
            return;
        }
        
        // Check if doctor owns this appointment
        if ($user_role === 'doctor' && $appointment['doctor_user_id'] != $user_id) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Not authorized to update this appointment']);
            return;
        }
        
        // Update appointment
        $update_stmt = $pdo->prepare("UPDATE appointments 
                                      SET status = ?, 
                                          notes = COALESCE(?, notes),
                                          updated_at = NOW()
                                      WHERE appointment_id = ?");
        $update_stmt->execute([$new_status, $notes, $appointment_id]);
        
        // Log activity
        log_activity($pdo, $user_id, 'appointment_status_updated', "Appointment ID: $appointment_id, New status: $new_status");
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Appointment status updated successfully',
            'new_status' => $new_status
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error while updating appointment']);
    }
}

/**
 * Reschedule an appointment
 */
function rescheduleAppointment($pdo, $input) {
    $appointment_id = $input['appointment_id'] ?? null;
    $new_slot_id = $input['new_slot_id'] ?? null;
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    if (!$appointment_id || !$new_slot_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current appointment details
        $appointment_stmt = $pdo->prepare("SELECT a.*, av.doctor_id, d.user_id as doctor_user_id
                                          FROM appointments a
                                          JOIN availability_slots av ON a.slot_id = av.slot_id
                                          JOIN doctors d ON av.doctor_id = d.doctor_id
                                          WHERE a.appointment_id = ?");
        $appointment_stmt->execute([$appointment_id]);
        $appointment = $appointment_stmt->fetch();
        
        if (!$appointment) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Appointment not found']);
            return;
        }
        
        // Check permissions
        $can_reschedule = false;
        if ($user_role === 'patient' && $appointment['patient_id'] == $user_id) {
            $can_reschedule = true;
        } elseif ($user_role === 'doctor' && $appointment['doctor_user_id'] == $user_id) {
            $can_reschedule = true;
        } elseif ($user_role === 'admin') {
            $can_reschedule = true;
        }
        
        if (!$can_reschedule) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Not authorized to reschedule this appointment']);
            return;
        }
        
        // Check if appointment can be rescheduled
        if ($appointment['status'] === 'completed') {
            $pdo->rollBack();
            echo json_encode(['error' => 'Cannot reschedule completed appointment']);
            return;
        }
        
        // Verify new slot is available
        $new_slot_stmt = $pdo->prepare("SELECT av.*, d.user_id as doctor_user_id
                                        FROM availability_slots av
                                        JOIN doctors d ON av.doctor_id = d.doctor_id
                                        WHERE av.slot_id = ? AND av.is_available = 1");
        $new_slot_stmt->execute([$new_slot_id]);
        $new_slot = $new_slot_stmt->fetch();
        
        if (!$new_slot) {
            $pdo->rollBack();
            echo json_encode(['error' => 'New slot not available']);
            return;
        }
        
        // Check if new slot is not already booked
        $booking_check = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE slot_id = ? AND status != 'cancelled'");
        $booking_check->execute([$new_slot_id]);
        
        if ($booking_check->fetchColumn() > 0) {
            $pdo->rollBack();
            echo json_encode(['error' => 'New slot already booked']);
            return;
        }
        
        // Check if new slot is not in the past
        if ($new_slot['slot_date'] < date('Y-m-d')) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Cannot reschedule to past date']);
            return;
        }
        
        // Update appointment with new slot
        $reschedule_stmt = $pdo->prepare("UPDATE appointments 
                                          SET slot_id = ?, 
                                              status = 'pending',
                                              updated_at = NOW()
                                          WHERE appointment_id = ?");
        $reschedule_stmt->execute([$new_slot_id, $appointment_id]);
        
        // Log activity
        log_activity($pdo, $user_id, 'appointment_rescheduled', "Appointment ID: $appointment_id");
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Appointment rescheduled successfully',
            'new_date' => format_date($new_slot['slot_date']),
            'new_time' => format_time($new_slot['start_time']) . ' - ' . format_time($new_slot['end_time'])
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error while rescheduling appointment']);
    }
}

/**
 * Get detailed appointment information
 */
function getAppointmentDetails($pdo, $input) {
    $appointment_id = $input['appointment_id'] ?? null;
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    if (!$appointment_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing appointment_id parameter']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT 
                                  a.*,
                                  av.slot_date,
                                  av.start_time,
                                  av.end_time,
                                  av.doctor_id,
                                  d.user_id as doctor_user_id,
                                  du.name as doctor_name,
                                  du.email as doctor_email,
                                  pu.name as patient_name,
                                  pu.email as patient_email,
                                  pu.phone as patient_phone,
                                  GROUP_CONCAT(s.name) as specializations
                                FROM appointments a
                                JOIN availability_slots av ON a.slot_id = av.slot_id
                                JOIN doctors d ON av.doctor_id = d.doctor_id
                                JOIN users du ON d.user_id = du.user_id
                                JOIN users pu ON a.patient_id = pu.user_id
                                LEFT JOIN doctor_specializations ds ON d.doctor_id = ds.doctor_id
                                LEFT JOIN specializations s ON ds.specialization_id = s.specialization_id
                                WHERE a.appointment_id = ?
                                GROUP BY a.appointment_id");
        $stmt->execute([$appointment_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            http_response_code(404);
            echo json_encode(['error' => 'Appointment not found']);
            return;
        }
        
        // Check permissions
        $can_view = false;
        if ($user_role === 'patient' && $appointment['patient_id'] == $user_id) {
            $can_view = true;
        } elseif ($user_role === 'doctor' && $appointment['doctor_user_id'] == $user_id) {
            $can_view = true;
        } elseif ($user_role === 'admin') {
            $can_view = true;
        }
        
        if (!$can_view) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to view this appointment']);
            return;
        }
        
        // Format response
        $response = [
            'success' => true,
            'appointment' => [
                'appointment_id' => $appointment['appointment_id'],
                'date' => $appointment['slot_date'],
                'date_formatted' => format_date($appointment['slot_date']),
                'start_time' => $appointment['start_time'],
                'end_time' => $appointment['end_time'],
                'time_formatted' => format_time($appointment['start_time']) . ' - ' . format_time($appointment['end_time']),
                'status' => $appointment['status'],
                'symptoms' => $appointment['symptoms'],
                'notes' => $appointment['notes'],
                'created_at' => $appointment['created_at'],
                'created_at_formatted' => format_datetime($appointment['created_at']),
                'doctor' => [
                    'name' => $appointment['doctor_name'],
                    'email' => $appointment['doctor_email'],
                    'specializations' => $appointment['specializations'] ? explode(',', $appointment['specializations']) : []
                ],
                'patient' => [
                    'name' => $appointment['patient_name'],
                    'email' => $appointment['patient_email'],
                    'phone' => $appointment['patient_phone']
                ]
            ]
        ];
        
        echo json_encode($response);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error while fetching appointment details']);
    }
}

/**
 * Add notes to appointment (for doctors)
 */
function addAppointmentNotes($pdo, $input) {
    $appointment_id = $input['appointment_id'] ?? null;
    $notes = $input['notes'] ?? null;
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    if (!$appointment_id || !$notes) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }
    
    // Only doctors and admins can add notes
    if (!in_array($user_role, ['doctor', 'admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized to add notes']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verify appointment exists and user has permission
        $appointment_stmt = $pdo->prepare("SELECT a.*, av.doctor_id, d.user_id as doctor_user_id
                                          FROM appointments a
                                          JOIN availability_slots av ON a.slot_id = av.slot_id
                                          JOIN doctors d ON av.doctor_id = d.doctor_id
                                          WHERE a.appointment_id = ?");
        $appointment_stmt->execute([$appointment_id]);
        $appointment = $appointment_stmt->fetch();
        
        if (!$appointment) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Appointment not found']);
            return;
        }
        
        // Check if doctor owns this appointment
        if ($user_role === 'doctor' && $appointment['doctor_user_id'] != $user_id) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Not authorized to add notes to this appointment']);
            return;
        }
        
        // Update appointment notes
        $notes_stmt = $pdo->prepare("UPDATE appointments 
                                     SET notes = ?, updated_at = NOW()
                                     WHERE appointment_id = ?");
        $notes_stmt->execute([$notes, $appointment_id]);
        
        // Log activity
        log_activity($pdo, $user_id, 'appointment_notes_added', "Appointment ID: $appointment_id");
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Notes added successfully'
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error while adding notes']);
    }
}
?>
