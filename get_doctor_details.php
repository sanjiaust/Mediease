<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$doctor_id = $_GET['doctor_id'] ?? null;

if (!$doctor_id || !is_numeric($doctor_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid doctor ID']);
    exit();
}

try {
    // Get doctor information with user details
    $stmt = $pdo->prepare("
        SELECT 
            d.doctor_id,
            d.user_id,
            d.specialization,
            d.qualifications,
            d.experience,
            u.name,
            u.email,
            u.phone,
            u.address,
            u.created_at,
            u.status
        FROM doctors d
        JOIN users u ON d.user_id = u.user_id
        WHERE d.doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Doctor not found']);
        exit();
    }
    
    // Get appointment statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_appointments,
            COUNT(CASE WHEN a.status = 'pending' THEN 1 END) as pending_appointments,
            COUNT(CASE WHEN a.status = 'confirmed' THEN 1 END) as confirmed_appointments,
            COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed_appointments,
            COUNT(CASE WHEN a.status = 'cancelled' THEN 1 END) as cancelled_appointments
        FROM appointments a
        JOIN availability_slots av ON a.slot_id = av.slot_id
        WHERE av.doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $appointment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get availability statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_slots,
            COUNT(CASE WHEN av.is_available = 1 THEN 1 END) as available_slots,
            COUNT(CASE WHEN av.slot_date >= CURDATE() THEN 1 END) as future_slots,
            COUNT(CASE WHEN av.slot_date >= CURDATE() AND av.is_available = 1 
                  AND a.appointment_id IS NULL THEN 1 END) as bookable_slots
        FROM availability_slots av
        LEFT JOIN appointments a ON av.slot_id = a.slot_id AND a.status != 'cancelled'
        WHERE av.doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $availability_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent appointments (last 10)
    $stmt = $pdo->prepare("
        SELECT 
            a.appointment_id,
            a.status,
            a.symptoms,
            a.notes,
            a.created_at,
            av.slot_date,
            av.start_time,
            av.end_time,
            u.name as patient_name,
            u.email as patient_email,
            u.phone as patient_phone
        FROM appointments a
        JOIN availability_slots av ON a.slot_id = av.slot_id
        JOIN users u ON a.patient_id = u.user_id
        WHERE av.doctor_id = ?
        ORDER BY av.slot_date DESC, av.start_time DESC
        LIMIT 10
    ");
    $stmt->execute([$doctor_id]);
    $recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming appointments (next 10)
    $stmt = $pdo->prepare("
        SELECT 
            a.appointment_id,
            a.status,
            a.symptoms,
            a.notes,
            a.created_at,
            av.slot_date,
            av.start_time,
            av.end_time,
            u.name as patient_name,
            u.email as patient_email,
            u.phone as patient_phone
        FROM appointments a
        JOIN availability_slots av ON a.slot_id = av.slot_id
        JOIN users u ON a.patient_id = u.user_id
        WHERE av.doctor_id = ?
        AND av.slot_date >= CURDATE()
        AND a.status NOT IN ('cancelled', 'completed')
        ORDER BY av.slot_date ASC, av.start_time ASC
        LIMIT 10
    ");
    $stmt->execute([$doctor_id]);
    $upcoming_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get weekly schedule summary (next 7 days)
    $stmt = $pdo->prepare("
        SELECT 
            av.slot_date,
            COUNT(*) as total_slots,
            COUNT(CASE WHEN a.appointment_id IS NOT NULL AND a.status != 'cancelled' THEN 1 END) as booked_slots,
            COUNT(CASE WHEN av.is_available = 1 AND a.appointment_id IS NULL THEN 1 END) as available_slots
        FROM availability_slots av
        LEFT JOIN appointments a ON av.slot_id = a.slot_id
        WHERE av.doctor_id = ?
        AND av.slot_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        GROUP BY av.slot_date
        ORDER BY av.slot_date
    ");
    $stmt->execute([$doctor_id]);
    $weekly_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get patient feedback/ratings if available (placeholder for future feature)
    $ratings = [
        'average_rating' => 0,
        'total_reviews' => 0,
        'rating_breakdown' => [
            '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0
        ]
    ];
    
    // Calculate performance metrics
    $total_appointments = $appointment_stats['total_appointments'];
    $completion_rate = $total_appointments > 0 ? 
        round(($appointment_stats['completed_appointments'] / $total_appointments) * 100, 1) : 0;
    
    $no_show_rate = $total_appointments > 0 ? 
        round(($appointment_stats['cancelled_appointments'] / $total_appointments) * 100, 1) : 0;
    
    $utilization_rate = $availability_stats['total_slots'] > 0 ? 
        round((($availability_stats['total_slots'] - $availability_stats['bookable_slots']) / $availability_stats['total_slots']) * 100, 1) : 0;
    
    // Format appointments
    $formatted_recent = [];
    foreach ($recent_appointments as $appointment) {
        $formatted_recent[] = [
            'appointment_id' => $appointment['appointment_id'],
            'patient_name' => $appointment['patient_name'],
            'patient_email' => $appointment['patient_email'],
            'patient_phone' => $appointment['patient_phone'],
            'date' => $appointment['slot_date'],
            'date_formatted' => format_date($appointment['slot_date']),
            'time' => format_time($appointment['start_time']) . ' - ' . format_time($appointment['end_time']),
            'status' => $appointment['status'],
            'symptoms' => $appointment['symptoms'],
            'notes' => $appointment['notes'],
            'created_at' => $appointment['created_at']
        ];
    }
    
    $formatted_upcoming = [];
    foreach ($upcoming_appointments as $appointment) {
        $formatted_upcoming[] = [
            'appointment_id' => $appointment['appointment_id'],
            'patient_name' => $appointment['patient_name'],
            'patient_email' => $appointment['patient_email'],
            'patient_phone' => $appointment['patient_phone'],
            'date' => $appointment['slot_date'],
            'date_formatted' => format_date($appointment['slot_date']),
            'time' => format_time($appointment['start_time']) . ' - ' . format_time($appointment['end_time']),
            'status' => $appointment['status'],
            'symptoms' => $appointment['symptoms'],
            'notes' => $appointment['notes'],
            'created_at' => $appointment['created_at']
        ];
    }
    
    // Format weekly schedule
    $formatted_schedule = [];
    foreach ($weekly_schedule as $day) {
        $formatted_schedule[] = [
            'date' => $day['slot_date'],
            'date_formatted' => format_date($day['slot_date']),
            'day_name' => date('l', strtotime($day['slot_date'])),
            'total_slots' => $day['total_slots'],
            'booked_slots' => $day['booked_slots'],
            'available_slots' => $day['available_slots'],
            'utilization' => $day['total_slots'] > 0 ? 
                round(($day['booked_slots'] / $day['total_slots']) * 100, 1) : 0
        ];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'doctor' => [
            'doctor_id' => $doctor['doctor_id'],
            'user_id' => $doctor['user_id'],
            'name' => $doctor['name'],
            'email' => $doctor['email'],
            'phone' => $doctor['phone'],
            'address' => $doctor['address'],
            'specialization' => $doctor['specialization'],
            'qualifications' => $doctor['qualifications'],
            'experience' => $doctor['experience'],
            'status' => $doctor['status'] ?? 'active',
            'created_at' => $doctor['created_at'],
            'member_since' => format_date($doctor['created_at']),
            
            // Statistics
            'statistics' => [
                'appointments' => [
                    'total' => $appointment_stats['total_appointments'],
                    'pending' => $appointment_stats['pending_appointments'],
                    'confirmed' => $appointment_stats['confirmed_appointments'],
                    'completed' => $appointment_stats['completed_appointments'],
                    'cancelled' => $appointment_stats['cancelled_appointments']
                ],
                'availability' => [
                    'total_slots' => $availability_stats['total_slots'],
                    'available_slots' => $availability_stats['available_slots'],
                    'future_slots' => $availability_stats['future_slots'],
                    'bookable_slots' => $availability_stats['bookable_slots']
                ],
                'performance' => [
                    'completion_rate' => $completion_rate,
                    'no_show_rate' => $no_show_rate,
                    'utilization_rate' => $utilization_rate
                ]
            ],
            
            // Recent activity
            'recent_appointments' => $formatted_recent,
            'upcoming_appointments' => $formatted_upcoming,
            'weekly_schedule' => $formatted_schedule,
            
            // Ratings (placeholder for future feature)
            'ratings' => $ratings
        ]
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error while fetching doctor details'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Server error while fetching doctor details'
    ]);
}
?>