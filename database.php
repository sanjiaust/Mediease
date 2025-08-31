<?php
// File: config/database.php
// Database configuration and connection class for MediEase

class Database {
    // Database credentials
    private $host = 'localhost';
    private $db_name = 'mediease';
    private $username = 'root';
    private $password = '';
    public $conn;

    // Get database connection
    public function getConnection() {
        $this->conn = null;
        
        try {
            // Create PDO connection
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                $this->username, 
                $this->password
            );
            
            // Set PDO attributes
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}

// Utility functions for common database operations
class DatabaseUtils {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    // Check if email exists
    public function emailExists($email, $excludeUserId = null) {
        $query = "SELECT user_id FROM users WHERE email = ?";
        if ($excludeUserId) {
            $query .= " AND user_id != ?";
        }
        
        $stmt = $this->db->prepare($query);
        if ($excludeUserId) {
            $stmt->execute([$email, $excludeUserId]);
        } else {
            $stmt->execute([$email]);
        }
        
        return $stmt->rowCount() > 0;
    }
    
    // Get user by ID
    public function getUserById($userId) {
        $query = "SELECT * FROM users WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    // Get doctor info by user ID
    public function getDoctorByUserId($userId) {
        $query = "SELECT d.*, u.name, u.email, u.phone, u.address 
                  FROM doctors d 
                  JOIN users u ON d.user_id = u.user_id 
                  WHERE d.user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
}
?>