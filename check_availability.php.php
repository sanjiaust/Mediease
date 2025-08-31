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
        case 'check_doctor_availability':
            checkDoctorAvailability($pdo, $input);
            break;
            
        case 'get_available_slots':
            getAvailableSlots($pdo, $input);
            break;
            
        case 'check_slot_status':
            checkSlotStatus($pdo, $input);
            break;
            
        case 'get_doctor_schedule':
            getDoctorSchedule($pdo, $input);
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
 * Check if doctor has any availability on a specific date
 */
function checkDoctorAvailability($pdo, $input) {
    $doctor_id = $input['doctor_id'] ?? null;
    $date = $input['date'] ?? null;
    
    if (!$doctor_id || !$date) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }
    
    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        return;
    }
    
    // Check if date is not in the past
    if ($date < date('Y-m-d')) {
        echo json_encode([
            'available' => false,
            'message' => 'Cannot book appointments in the past'
        ]);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as available_slots
            FROM availability_slots av
            LEFT JOIN appointments a ON av.slot_id = a.slot_id AND a.status != 'cancelled'
            WHERE av.doctor_id = ? 
            AND av.slot_date = ? 
            AND av.is_available = 1
            AND a.appointment_id IS NULL
        ");
        
        $stmt->execute([$doctor_id, $date]);
        $result = $stmt->fetch();
        
        $available = $result['available_slots'] > 0;
        
        echo json_encode([
            'available' => $available,
            'available_slots' => (int)$result['available_slots'],
            'date' => $date,
            'message' => $available ? 'Slots available' : 'No available slots'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
}

/**
 * Get all available slots for a doctor on a specific date
 */
function getAvailableSlots($pdo, $input) {
    $doctor_id = $input['doctor_id'] ?? null;
    $date = $input['date'] ?? null;
    
    if (!$doctor_id || !$date) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }
    
    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                av.slot_id,
                av.start_time,
                av.end_time,
                av.is_available,
                CASE 
                    WHEN a.appointment_id IS NOT NULL AND a.status != 'cancelled' THEN 'booked'
                    WHEN av.is_available = 0 THEN 'unavailable'
                    ELSE 'available'
                END as slot_status
            FROM availability_slots av
            LEFT JOIN appointments a ON av.slot_id = a.slot_id AND a.status != 'cancelled'
            WHERE av.doctor_id = ? 
            AND av.slot_date = ?
            ORDER BY av.start_time ASC
        ");
        
        $stmt->execute([$doctor_id, $date]);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format slots for better frontend consumption
        $formatted_slots = [];
        foreach ($slots as $slot) {
            $formatted_slots[] = [
                'slot_id' => $slot['slot_id'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'start_time_formatted' => format_time($slot['start_time']),
                'end_time_formatted' => format_time($slot['end_time']),
                'time_range' => format_time($slot['start_time']) . ' - ' . format_time($slot['end_time']),
                'status' => $slot['slot_status'],
                'is_bookable' => $slot['slot_status'] === 'available'
            ];
        }
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'slots' => $formatted_slots,
            'total_slots' => count($slots),
            'available_slots' => count(array_filter($formatted_slots, function($slot) {
                return $slot['is_bookable'];
            }))
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
}

/**
 * Check status of a specific slot
 */
function checkSlotStatus($pdo, $input) {
    $slot_id = $input['slot_id'] ?? null;
    
    if (!$slot_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing slot_id parameter']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                av.slot_id,
                av.slot_date,
                av.start_time,
                av.end_time,
                av.is_available,
                a.appointment_id,
                a.status as appointment_status,
                a.patient_id,
                u.name as patient_name,
                CASE 
                    WHEN a.appointment_id IS NOT NULL AND a.status != 'cancelled' THEN 'booked'
                    WHEN av.is_available = 0 THEN 'unavailable'
                    ELSE 'available'
                END as slot_status
            FROM availability_slots av
            LEFT JOIN appointments a ON av.slot_id = a.slot_id AND a.status != 'cancelled'
            LEFT JOIN users u ON a.patient_id = u.user_id
            WHERE av.slot_id = ?
        ");
        
        $stmt->execute([$slot_id]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$slot) {
            http_response_code(404);
            echo json_encode(['error' => 'Slot not found']);
            return;
        }
        
        // Format response
        $response = [
            'slot_id' => $slot['slot_id'],
            'date' => $slot['slot_date'],
            'date_formatted' => format_date($slot['slot_date']),
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'time_range' => format_time($slot['start_time']) . ' - ' . format_time($slot['end_time']),
            'status' => $slot['slot_status'],
            'is_bookable' => $slot['slot_status'] === 'available',
            'is_past' => $slot['slot_date'] < date('Y-m-d')
        ];
        
        // Add appointment info if booked
        if ($slot['appointment_id']) {
            $response['appointment'] = [
                'appointment_id' => $slot['appointment_id'],
                'status' => $slot['appointment_status'],
                'patient_name' => $slot['patient_name']
            ];
        }
        
        echo json_encode($response);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
}

/**
 * Get doctor's schedule for a date range
 */
function getDoctorSchedule($pdo, $input) {
    $doctor_id = $input['doctor_id'] ?? null;
    $start_date = $input['start_date'] ?? date('Y-m-d');
    $end_date = $input['end_date'] ?? date('Y-m-d', strtotime($start_date . ' +6 days'));
    
    if (!$doctor_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing doctor_id parameter']);
        return;
    }
    
    // Validate date formats
    if (!DateTime::createFromFormat('Y-m-d', $start_date) || !DateTime::createFromFormat('Y-m-d', $end_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                av.slot_date,
                av.start_time,
                av.end_time,
                av.is_available,
                a.appointment_id,
                a.status as appointment_status,
                u.name as patient_name,
                u.phone as patient_phone,
                CASE 
                    WHEN a.appointment_id IS NOT NULL AND a.status != 'cancelled' THEN 'booked'
                    WHEN av.is_available = 0 THEN 'unavailable'
                    ELSE 'available'
                END as slot_status
            FROM availability_slots av
            LEFT JOIN appointments a ON av.slot_id = a.slot_id AND a.status != 'cancelled'
            LEFT JOIN users u ON a.patient_id = u.user_id
            WHERE av.doctor_id = ? 
            AND av.slot_date BETWEEN ? AND ?
            ORDER BY av.slot_date ASC, av.start_time ASC
        ");
        
        $stmt->execute([$doctor_id, $start_date, $end_date]);
        $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize by date
        $schedule_by_date = [];
        $stats = [
            'total_slots' => 0,
            'available_slots' => 0,
            'booked_slots' => 0,
            'unavailable_slots' => 0
        ];
        
        foreach ($schedule as $slot) {
            $date = $slot['slot_date'];
            
            if (!isset($schedule_by_date[$date])) {
                $schedule_by_date[$date] = [
                    'date' => $date,
                    'date_formatted' => format_date($date),
                    'day_name' => date('l', strtotime($date)),
                    'slots' => []
                ];
            }
            
            $formatted_slot = [
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'time_range' => format_time($slot['start_time']) . ' - ' . format_time($slot['end_time']),
                'status' => $slot['slot_status']
            ];
            
            // Add patient info if booked
            if ($slot['appointment_id']) {
                $formatted_slot['patient'] = [
                    'name' => $slot['patient_name'],
                    'phone' => $slot['patient_phone']
                ];
            }
            
            $schedule_by_date[$date]['slots'][] = $formatted_slot;
            
            // Update stats
            $stats['total_slots']++;
            switch ($slot['slot_status']) {
                case 'available':
                    $stats['available_slots']++;
                    break;
                case 'booked':
                    $stats['booked_slots']++;
                    break;
                case 'unavailable':
                    $stats['unavailable_slots']++;
                    break;
            }
        }
        
        // Calculate utilization
        $stats['utilization_rate'] = $stats['total_slots'] > 0 ? 
            round(($stats['booked_slots'] / $stats['total_slots']) * 100, 1) : 0;
        
        echo json_encode([
            'success' => true,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'schedule' => array_values($schedule_by_date),
            'stats' => $stats
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
}
?>