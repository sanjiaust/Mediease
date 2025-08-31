<?php
// File: patient/doctors.php
// Browse and search doctors for patients

include '../includes/header.php';

// Check if user is logged in and is a patient
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header('Location: ../auth/login.php');
    exit();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$specialization_filter = isset($_GET['specialization']) ? $_GET['specialization'] : '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Build query based on filters
    $query = "
        SELECT d.doctor_id, d.specialization, d.qualifications, d.experience, 
               u.name, u.email, u.phone, u.address,
               COUNT(DISTINCT a.appointment_id) as total_appointments,
               COUNT(DISTINCT CASE WHEN s.slot_date >= CURDATE() AND s.is_available = 1 THEN s.slot_id END) as available_slots
        FROM doctors d
        JOIN users u ON d.user_id = u.user_id
        LEFT JOIN appointments a ON d.doctor_id = a.doctor_id
        LEFT JOIN availability_slots s ON d.doctor_id = s.doctor_id
        WHERE u.role = 'doctor'
    ";
    
    $params = [];
    
    // Add search filter
    if (!empty($search)) {
        $query .= " AND (u.name LIKE ? OR d.specialization LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Add specialization filter
    if (!empty($specialization_filter)) {
        $query .= " AND d.specialization = ?";
        $params[] = $specialization_filter;
    }
    
    $query .= " GROUP BY d.doctor_id ORDER BY u.name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $doctors = $stmt->fetchAll();
    
    // Get all specializations for filter dropdown
    $spec_stmt = $db->prepare("SELECT DISTINCT specialization FROM doctors ORDER BY specialization");
    $spec_stmt->execute();
    $specializations = $spec_stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

// Helper function to add Dr. prefix if not already present
function formatDoctorName($name) {
    $name = trim($name);
    if (stripos($name, 'dr.') !== 0 && stripos($name, 'doctor') !== 0) {
        return 'Dr. ' . $name;
    }
    return $name;
}
?>

<div class="container my-4">
    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="card dashboard-card">
                <h2 class="mb-3">
                    <i class="fas fa-user-md me-2 text-primary"></i>Find Doctors
                </h2>
                <p class="text-muted mb-0">Browse our qualified healthcare professionals and book appointments</p>
            </div>
        </div>
    </div>

    <!-- Search and Filter Section -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label for="search" class="form-label">
                                <i class="fas fa-search me-2"></i>Search Doctors
                            </label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   placeholder="Search by name or specialization"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="specialization" class="form-label">
                                <i class="fas fa-filter me-2"></i>Specialization
                            </label>
                            <select class="form-control" id="specialization" name="specialization">
                                <option value="">All Specializations</option>
                                <?php foreach ($specializations as $spec): ?>
                                    <option value="<?php echo htmlspecialchars($spec['specialization']); ?>"
                                            <?php echo $specialization_filter == $spec['specialization'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($spec['specialization']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i>Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Doctors Grid -->
    <div class="row mt-4">
        <?php if (empty($doctors)): ?>
            <div class="col-12">
                <div class="card text-center py-5">
                    <div class="card-body">
                        <i class="fas fa-user-md fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No doctors found</h5>
                        <p class="text-muted">Try adjusting your search criteria.</p>
                        <a href="doctors.php" class="btn btn-primary">
                            <i class="fas fa-refresh me-2"></i>Show All Doctors
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($doctors as $doctor): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card doctor-card h-100">
                    <div class="card-body">
                        <div class="doctor-avatar">
                            <i class="fas fa-user-md"></i>
                        </div>
                        
                        <h5 class="card-title text-center mb-2">
                            <?php echo htmlspecialchars(formatDoctorName($doctor['name'])); ?>
                        </h5>
                        
                        <p class="text-center text-primary fw-bold mb-3">
                            <i class="fas fa-stethoscope me-1"></i>
                            <?php echo htmlspecialchars($doctor['specialization']); ?>
                        </p>
                        
                        <div class="mb-3">
                            <small class="text-muted d-block">
                                <i class="fas fa-graduation-cap me-2"></i>
                                <strong>Qualifications:</strong>
                            </small>
                            <small><?php echo htmlspecialchars($doctor['qualifications']); ?></small>
                        </div>
                        
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar-alt me-2"></i>
                                <strong>Experience:</strong> <?php echo $doctor['experience']; ?> years
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="fas fa-map-marker-alt me-2"></i>
                                <?php echo htmlspecialchars($doctor['address']); ?>
                            </small>
                        </div>
                        
                        <div class="row text-center mb-3">
                            <div class="col-6">
                                <small class="text-muted d-block">Total Patients</small>
                                <strong class="text-primary"><?php echo $doctor['total_appointments']; ?></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Available Slots</small>
                                <strong class="text-success"><?php echo $doctor['available_slots']; ?></strong>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <a href="book_appointment.php?doctor_id=<?php echo $doctor['doctor_id']; ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-calendar-plus me-2"></i>Book Appointment
                            </a>
                            <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" 
                                    data-bs-target="#doctorModal<?php echo $doctor['doctor_id']; ?>">
                                <i class="fas fa-info-circle me-1"></i>View Details
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Doctor Details Modal -->
            <div class="modal fade" id="doctorModal<?php echo $doctor['doctor_id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-user-md me-2"></i>
                                <?php echo htmlspecialchars(formatDoctorName($doctor['name'])); ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <div class="doctor-avatar mx-auto mb-3" style="width: 120px; height: 120px;">
                                        <i class="fas fa-user-md"></i>
                                    </div>
                                    <h5><?php echo htmlspecialchars(formatDoctorName($doctor['name'])); ?></h5>
                                    <p class="text-primary fw-bold">
                                        <?php echo htmlspecialchars($doctor['specialization']); ?>
                                    </p>
                                </div>
                                <div class="col-md-8">
                                    <div class="row mb-3">
                                        <div class="col-sm-4"><strong>Experience:</strong></div>
                                        <div class="col-sm-8"><?php echo $doctor['experience']; ?> years</div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-4"><strong>Qualifications:</strong></div>
                                        <div class="col-sm-8"><?php echo htmlspecialchars($doctor['qualifications']); ?></div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-4"><strong>Email:</strong></div>
                                        <div class="col-sm-8"><?php echo htmlspecialchars($doctor['email']); ?></div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-4"><strong>Phone:</strong></div>
                                        <div class="col-sm-8"><?php echo htmlspecialchars($doctor['phone']); ?></div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-4"><strong>Address:</strong></div>
                                        <div class="col-sm-8"><?php echo htmlspecialchars($doctor['address']); ?></div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-4"><strong>Total Patients:</strong></div>
                                        <div class="col-sm-8">
                                            <span class="badge bg-primary"><?php echo $doctor['total_appointments']; ?></span>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-4"><strong>Available Slots:</strong></div>
                                        <div class="col-sm-8">
                                            <span class="badge bg-success"><?php echo $doctor['available_slots']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <a href="book_appointment.php?doctor_id=<?php echo $doctor['doctor_id']; ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-calendar-plus me-2"></i>Book Appointment
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.doctor-card {
    transition: transform 0.2s, box-shadow 0.2s;
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.doctor-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.doctor-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
}

.doctor-avatar i {
    font-size: 2rem;
    color: white;
}

.dashboard-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 15px;
    padding: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
</style>

<?php include '../includes/footer.php'; ?>