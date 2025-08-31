<?php
/**
 * MediEase - Common Utility Functions
 * This file contains reusable functions used throughout the application
 */

/**
 * Sanitize input data
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Validate email format
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate phone number (basic validation)
 */
function validate_phone($phone) {
    // Remove all non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    // Check if it's between 10-15 digits
    return strlen($phone) >= 10 && strlen($phone) <= 15;
}

/**
 * Generate secure password hash
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password against hash
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user has specific role
 */
function has_role($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Redirect to login if not authenticated
 */
function require_auth($allowed_roles = []) {
    if (!is_logged_in()) {
        header("Location: ../auth/login.php");
        exit();
    }
    
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        header("Location: ../auth/login.php?error=unauthorized");
        exit();
    }
}

/**
 * Get user's full name
 */
function get_user_name($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT name FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    return $user ? $user['name'] : 'Unknown';
}

/**
 * Format date for display
 */
function format_date($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

/**
 * Format time for display
 */
function format_time($time, $format = 'g:i A') {
    return date($format, strtotime($time));
}

/**
 * Format datetime for display
 */
function format_datetime($datetime, $format = 'F j, Y g:i A') {
    return date($format, strtotime($datetime));
}

/**
 * Get time ago format (e.g., "2 hours ago")
 */
function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) {
        return 'just now';
    }
    
    $time_units = [
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute'
    ];
    
    foreach ($time_units as $unit => $name) {
        if ($time >= $unit) {
            $time_value = floor($time / $unit);
            return $time_value . ' ' . $name . ($time_value > 1 ? 's' : '') . ' ago';
        }
    }
    
    return 'just now';
}

/**
 * Generate random token
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Send email notification (basic implementation)
 */
function send_email($to, $subject, $body, $headers = '') {
    // Basic email sending - in production, use a proper email service
    $default_headers = "From: noreply@mediease.com\r\n";
    $default_headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers = $headers ? $headers . "\r\n" . $default_headers : $default_headers;
    
    return mail($to, $subject, $body, $headers);
}

/**
 * Get appointment status badge class
 */
function get_status_badge_class($status) {
    switch (strtolower($status)) {
        case 'pending':
            return 'warning';
        case 'confirmed':
            return 'info';
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        case 'no-show':
            return 'dark';
        default:
            return 'secondary';
    }
}

/**
 * Get appointment status icon
 */
function get_status_icon($status) {
    switch (strtolower($status)) {
        case 'pending':
            return 'fas fa-clock';
        case 'confirmed':
            return 'fas fa-check-circle';
        case 'completed':
            return 'fas fa-user-check';
        case 'cancelled':
            return 'fas fa-times-circle';
        case 'no-show':
            return 'fas fa-user-times';
        default:
            return 'fas fa-question-circle';
    }
}

/**
 * Calculate age from birthdate
 */
function calculate_age($birthdate) {
    $birth = new DateTime($birthdate);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

/**
 * Generate pagination HTML
 */
function generate_pagination($current_page, $total_pages, $base_url) {
    $pagination = '<nav><ul class="pagination justify-content-center">';
    
    // Previous button
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $pagination .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{$base_url}?page={$prev_page}\">Previous</a></li>";
    } else {
        $pagination .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $pagination .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{$base_url}?page=1\">1</a></li>";
        if ($start_page > 2) {
            $pagination .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $pagination .= "<li class=\"page-item active\"><span class=\"page-link\">{$i}</span></li>";
        } else {
            $pagination .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{$base_url}?page={$i}\">{$i}</a></li>";
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $pagination .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $pagination .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{$base_url}?page={$total_pages}\">{$total_pages}</a></li>";
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $pagination .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{$base_url}?page={$next_page}\">Next</a></li>";
    } else {
        $pagination .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    $pagination .= '</ul></nav>';
    return $pagination;
}

/**
 * Check if time slot is available
 */
function is_slot_available($pdo, $doctor_id, $date, $start_time, $end_time) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM availability_slots av
        LEFT JOIN appointments a ON av.slot_id = a.slot_id
        WHERE av.doctor_id = ? 
        AND av.slot_date = ? 
        AND av.start_time = ? 
        AND av.end_time = ?
        AND (a.appointment_id IS NULL OR a.status = 'cancelled')
        AND av.is_available = 1
    ");
    $stmt->execute([$doctor_id, $date, $start_time, $end_time]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Get doctor specializations
 */
function get_doctor_specializations($pdo, $doctor_id) {
    $stmt = $pdo->prepare("
        SELECT s.name 
        FROM specializations s
        JOIN doctor_specializations ds ON s.specialization_id = ds.specialization_id
        WHERE ds.doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Log activity
 */
function log_activity($pdo, $user_id, $action, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, details, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $action, $details]);
    } catch (Exception $e) {
        // Log error but don't break the application
        error_log("Activity logging failed: " . $e->getMessage());
    }
}

/**
 * Get system statistics
 */
function get_system_stats($pdo) {
    $stats = [];
    
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $stats['total_users'] = $stmt->fetchColumn();
    
    // Total doctors
    $stmt = $pdo->query("SELECT COUNT(*) FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE u.status = 'active'");
    $stats['total_doctors'] = $stmt->fetchColumn();
    
    // Total patients
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'patient' AND status = 'active'");
    $stats['total_patients'] = $stmt->fetchColumn();
    
    // Total appointments this month
    $stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
    $stats['appointments_this_month'] = $stmt->fetchColumn();
    
    // Completed appointments this month
    $stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
    $stats['completed_this_month'] = $stmt->fetchColumn();
    
    return $stats;
}

/**
 * Upload file with validation
 */
function upload_file($file, $upload_dir, $allowed_types = [], $max_size = 5242880) { // 5MB default
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'File size too large'];
    }
    
    // Check file type
    $file_info = pathinfo($file['name']);
    $extension = strtolower($file_info['extension']);
    
    if (!empty($allowed_types) && !in_array($extension, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type'];
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . '/' . $filename;
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
    } else {
        return ['success' => false, 'error' => 'Failed to upload file'];
    }
}

/**
 * Generate appointment reminder message
 */
function generate_reminder_message($appointment_data) {
    $doctor_name = $appointment_data['doctor_name'];
    $date = format_date($appointment_data['appointment_date']);
    $time = format_time($appointment_data['start_time']);
    
    return "Reminder: You have an appointment with Dr. {$doctor_name} on {$date} at {$time}. Please arrive 15 minutes early.";
}

/**
 * Calculate business days between dates
 */
function calculate_business_days($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $days = 0;
    
    while ($start <= $end) {
        if ($start->format('N') < 6) { // Monday = 1, Sunday = 7
            $days++;
        }
        $start->add(new DateInterval('P1D'));
    }
    
    return $days;
}

/**
 * Check if date is a business day
 */
function is_business_day($date) {
    $day_of_week = date('N', strtotime($date));
    return $day_of_week < 6; // Monday to Friday
}

/**
 * Get next available business day
 */
function get_next_business_day($date = null) {
    $date = $date ? $date : date('Y-m-d');
    $next_day = new DateTime($date);
    
    do {
        $next_day->add(new DateInterval('P1D'));
    } while ($next_day->format('N') >= 6);
    
    return $next_day->format('Y-m-d');
}

/**
 * Validate appointment time slot
 */
function validate_appointment_slot($pdo, $doctor_id, $date, $start_time, $end_time) {
    $errors = [];
    
    // Check if date is in the past
    if ($date < date('Y-m-d')) {
        $errors[] = "Cannot book appointments in the past";
    }
    
    // Check if it's a business day (optional - depends on business rules)
    if (!is_business_day($date)) {
        $errors[] = "Appointments can only be booked on business days";
    }
    
    // Check if slot exists and is available
    if (!is_slot_available($pdo, $doctor_id, $date, $start_time, $end_time)) {
        $errors[] = "Selected time slot is not available";
    }
    
    return $errors;
}
/**
 * Format currency
 */
function format_currency($amount, $currency_symbol = '$') {
    return $currency_symbol . number_format($amount, 2);
}

/**
 * Clean phone number for storage
 */
function clean_phone($phone) {
    return preg_replace('/[^0-9+]/', '', $phone);
}

/**
 * Format phone number for display
 */
function format_phone($phone) {
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($cleaned) == 10) {
        return sprintf('(%s) %s-%s', 
            substr($cleaned, 0, 3),
            substr($cleaned, 3, 3),
            substr($cleaned, 6)
        );
    }
    
    return $phone; // Return original if not standard format
}


/**
 * Check if user can perform action
 */
function can_user_perform_action($action, $user_role, $resource_owner_id = null) {
    $permissions = [
        'patient' => ['view_own_appointments', 'book_appointments', 'cancel_own_appointments'],
        'doctor' => ['view_own_appointments', 'manage_availability', 'view_patients', 'update_appointment_status'],
        'admin' => ['manage_users', 'view_all_appointments', 'system_settings', 'view_analytics']
    ];
    
    if (!isset($permissions[$user_role])) {
        return false;
    }
    
    // Check if user has permission for this action
    if (!in_array($action, $permissions[$user_role])) {
        return false;
    }
    
    // Additional checks for resource ownership
    if ($resource_owner_id && isset($_SESSION['user_id'])) {
        if (strpos($action, 'own') !== false && $_SESSION['user_id'] != $resource_owner_id) {
            return false;
        }
    }
    
    return true;
}
?>