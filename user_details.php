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

$user_id = $_GET['user_id'] ?? null;

if (!$user_id || !is_numeric($user_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit();
}

try {
    // Get user basic information
    $stmt = $pdo->prepare("
        SELECT 
            user_id,
            name,
            email,
            role,
            phone,
            address,
            created_at,
            status
        FROM users 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }
    
    // Get role-specific information
    $role_specific_data = null;
    $appointment_count = 0;
    
    if ($user['role'] === 'doctor') {
        // Get doctor-specific information
        $stmt = $pdo->prepare("
            SELECT 
                doctor_id,
                specialization,
                qualifications,
                experience
            FROM doctors 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $role_specific_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get appointment count for doctor
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM appointments a
            JOIN availability_slots av ON a.slot_id = av.slot_id
            JOIN doctors d ON av.doctor_id = d.doctor_id
            WHERE d.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $appointment_count = $stmt->fetchColumn();
        
        // Get available slots count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM availability_slots av
            JOIN doctors d ON av.doctor_id = d.doctor_id
            LEFT JOIN appointments a ON av.slot_id = a.slot_id AND a.status != 'cancelled'
            WHERE d.user_id = ? 
            AND av.slot_date >= CURDATE()
            AND av.is_available = 1
            AND a.appointment_id IS NULL
        ");
        $stmt->execute([$user_id]);
        $available_slots = $stmt->fetchColumn();
        
        // Get completed appointments
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM appointments a
            JOIN availability_slots av ON a.slot_id = av.slot_id
            JOIN doctors d ON av.doctor_id = d.doctor_id
            WHERE d.user_id = ? AND a.status = 'completed'
        ");
        $stmt->execute([$user_id]);
        $completed_appointments = $stmt->fetchColumn();
        
        // Add doctor statistics
        $role_specific_data['stats'] = [
            'total_appointments' => $appointment_count,
            'completed_appointments' => $completed_appointments,
            'available_slots' => $available_slots,
            'completion_rate' => $appointment_count > 0 ? round(($completed_appointments / $appointment_count) * 100, 1) : 0
        ];
        
    } elseif ($user['role'] === 'patient') {
        // Get appointment count for patient
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM appointments 
            WHERE patient_id = ?
        ");
        $stmt->execute([$user_id]);
        $appointment_count = $stmt->fetchColumn();
        
        // Get patient statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_appointments,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_appointments,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_appointments,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_appointments,
                MAX(av.slot_date) as last_appointment_date
            FROM appointments a
            JOIN availability_slots av ON a.slot_id = av.slot_id
            WHERE a.patient_id = ?
        ");
        $stmt->execute([$user_id]);
        $patient_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $role_specific_data = [
            'stats' => $patient_stats
        ];
    }
    
    // Get recent appointments (last 5)
    if ($user['role'] === 'patient') {
        $stmt = $pdo->prepare("
            SELECT 
                a.appointment_id,
                a.status,
                av.slot_date,
                av.start_time,
                av.end_time,
                u.name as doctor_name,
                d.specialization,
                a.created_at
            FROM appointments a
            JOIN availability_slots av ON a.slot_id = av.slot_id
            JOIN doctors d ON av.doctor_id = d.doctor_id
            JOIN users u ON d.user_id = u.user_id
            WHERE a.patient_id = ?
            ORDER BY av.slot_date DESC, av.start_time DESC
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user['role'] === 'doctor') {
        $stmt = $pdo->prepare("
            SELECT 
                a.appointment_id,
                a.status,
                av.slot_date,
                av.start_time,
                av.end_time,
                u.name as patient_name,
                u.phone as patient_phone,
                a.created_at
            FROM appointments a
            JOIN availability_slots av ON a.slot_id = av.slot_id
            JOIN doctors d ON av.doctor_id = d.doctor_id
            JOIN users u ON a.patient_id = u.user_id
            WHERE d.user_id = ?
            ORDER BY av.slot_date DESC, av.start_time DESC
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $recent_appointments = [];
    }
    
    // Format recent appointments
    $formatted_appointments = [];
    foreach ($recent_appointments as $appointment) {
        $formatted_appointments[] = [
            'appointment_id' => $appointment['appointment_id'],
            'status' => $appointment['status'],
            'date' => $appointment['slot_date'],
            'date_formatted' => format_date($appointment['slot_date']),
            'time' => format_time($appointment['start_time']) . ' - ' . format_time($appointment['end_time']),
            'other_party' => $user['role'] === 'patient' ? $appointment['doctor_name'] : $appointment['patient_name'],
            'specialization' => $appointment['specialization'] ?? null,
            'phone' => $appointment['patient_phone'] ?? null,
            'created_at' => $appointment['created_at']
        ];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'user' => [
            'user_id' => $user['user_id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'phone' => $user['phone'],
            'address' => $user['address'],
            'created_at' => $user['created_at'],
            'status' => $user['status'] ?? 'active',
            'appointment_count' => $appointment_count,
            'doctor_info' => $user['role'] === 'doctor' ? $role_specific_data : null,
            'patient_info' => $user['role'] === 'patient' ? $role_specific_data : null,
            'recent_appointments' => $formatted_appointments
        ]
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error while fetching user details'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Server error while fetching user details'
    ]);
}
?>