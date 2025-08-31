<?php
// File: includes/footer.php
// Footer component for MediEase
?>
    </div>

    <footer class="bg-dark text-light py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-heartbeat me-2"></i>MediEase</h5>
                    <p class="mb-0">Your trusted medical appointment booking system</p>
                    <small class="text-muted">Connecting patients with healthcare professionals</small>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-phone me-2"></i>+880-1234-567890<br>
                        <i class="fas fa-envelope me-2"></i>support@mediease.com<br>
                        <i class="fas fa-map-marker-alt me-2"></i>Dhaka, Bangladesh
                    </p>
                </div>
            </div>
            <hr class="my-3">
            <div class="row">
                <div class="col-md-6">
                    <div class="d-flex gap-3">
                        <a href="#" class="text-light">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="text-light">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="text-light">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="#" class="text-light">
                            <i class="fab fa-instagram"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">&copy; 2025 MediEase. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => {
                            if (alert.parentNode) {
                                alert.parentNode.removeChild(alert);
                            }
                        }, 500);
                    }
                }, 5000);
            });
        });

        // Confirm delete actions
        function confirmDelete(message = 'Are you sure you want to delete this item?') {
            return confirm(message);
        }

        // Loading state for forms
        function setLoadingState(buttonId, loading = true) {
            const button = document.getElementById(buttonId);
            if (button) {
                if (loading) {
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                } else {
                    button.disabled = false;
                    button.innerHTML = button.getAttribute('data-original-text') || 'Submit';
                }
            }
        }

        // Format phone number
        function formatPhone(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length > 0) {
                if (value.startsWith('880')) {
                    value = '+' + value;
                } else if (value.startsWith('01')) {
                    value = '+880' + value.substring(2);
                }
            }
            input.value = value;
        }

        // Date validation (no past dates for appointments)
        function validateFutureDate(dateInput) {
            const selectedDate = new Date(dateInput.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (selectedDate < today) {
                dateInput.setCustomValidity('Please select a future date');
                return false;
            } else {
                dateInput.setCustomValidity('');
                return true;
            }
        }
    </script>
</body>
</html>