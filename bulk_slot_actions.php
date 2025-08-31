<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/mediease/includes/config.php';

// Check if the user is logged in and is a doctor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Decode JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    $doctor_id = $input['doctor_id'] ?? null;
    $action = $input['action'] ?? '';

    if (!$doctor_id || !$action) {
        echo json_encode(['error' => 'Missing doctor ID or action']);
        exit();
    }

    try {
        switch ($action) {
            case 'enable':
                $update_stmt = $pdo->prepare("UPDATE availability_slots SET is_available = 1 WHERE doctor_id = ?");
                $update_stmt->execute([$doctor_id]);
                echo json_encode(['success' => true, 'message' => 'All slots enabled']);
                break;

            case 'disable':
                $update_stmt = $pdo->prepare("UPDATE availability_slots SET is_available = 0 WHERE doctor_id = ?");
                $update_stmt->execute([$doctor_id]);
                echo json_encode(['success' => true, 'message' => 'All slots disabled']);
                break;

            case 'delete_empty':
                $delete_stmt = $pdo->prepare("DELETE FROM availability_slots WHERE doctor_id = ? AND appointment_count = 0");
                $delete_stmt->execute([$doctor_id]);
                echo json_encode(['success' => true, 'message' => 'Empty slots deleted']);
                break;

            default:
                echo json_encode(['error' => 'Unknown action']);
                break;
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
