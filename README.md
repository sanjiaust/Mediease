# MediEase - Medical Appointment Booking System

<div align="center">

![MediEase](https://img.shields.io/badge/MediEase-Medical%20Appointments-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-005C87?logo=mysql)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.0-7952B3?logo=bootstrap)

**A comprehensive medical appointment booking system connecting patients with healthcare professionals**

[Features](#features) • [Quick Start](#quick-start) • [Project Structure](#project-structure)

</div>

---

## 📋 Overview

**MediEase** is a full-featured medical appointment booking platform built with PHP and MySQL. It streamlines the process of scheduling healthcare appointments by connecting patients with qualified doctors across various medical specializations. The system operates as a user-friendly web application designed for the healthcare industry in Bangladesh.

### Key Highlights
- 🏥 **Role-based system** for Patients, Doctors, and Administrators
- 📅 **Real-time appointment scheduling** with instant confirmation
- 👨‍⚕️ **Multi-specialization support** (Cardiology, Neurology, Pediatrics, and more)
- 🔐 **Secure authentication** with password hashing
- 📱 **Responsive design** optimized for all devices

---

## ✨ Features

### 👥 For Patients
- **User Registration & Authentication** - Create accounts with secure login
- **Doctor Directory** - Browse doctors by specialization, experience, and qualifications
- **Appointment Booking** - View real-time availability and book appointments instantly
- **Appointment Management** - View, reschedule, or cancel appointments
- **Profile Management** - Update personal information and preferences
- **Dashboard** - Track all upcoming and past appointments

### 👨‍⚕️ For Doctors
- **Professional Profile** - Showcase qualifications, experience, and specializations
- **Availability Management** - Set working hours and available time slots
- **Appointment Control** - Confirm, reschedule, or cancel appointments
- **Patient List** - View all booked and completed appointments
- **Schedule Management** - Manage multiple availability slots and bulk operations
- **Dashboard** - View appointment statistics and patient history

### 🛡️ For Administrators
- **System Management** - Oversee all users, appointments, and settings
- **User Management** - Create, edit, and manage patient and doctor accounts
- **Appointment Oversight** - Monitor and manage all appointments
- **Settings Control** - Configure system parameters and specializations

### 🌐 General Features
- **Real-time Availability** - Instant slot confirmation
- **Multiple Medical Specializations** - Support for 6+ specializations
- **Responsive Interface** - Mobile-friendly design with Bootstrap 5
- **Input Validation** - Comprehensive data validation and sanitization
- **Session Management** - Secure user session handling
- **Error Handling** - Graceful error management and user feedback

---

## 🚀 Quick Start

### Prerequisites
- **PHP** 7.4 or higher
- **MySQL** 5.7 or higher
- **Apache/Nginx** web server with mod_rewrite enabled

### Installation Steps

1. **Clone the Repository**
   ```bash
   git clone https://github.com/sanjiaust/Mediease.git
   cd Mediease
   ```

2. **Database Setup**
   - Create a new MySQL database named `mediease`
   - Import the database schema
   - Update database credentials in `database.php`:
     ```php
     private $host = 'localhost';
     private $db_name = 'mediease';
     private $username = 'root';
     private $password = '';
     ```

3. **Set File Permissions**
   ```bash
   chmod -R 755 ./
   ```

4. **Access the Application**
   - Open your browser and navigate to: `http://localhost/Mediease/`
   - Register as Patient or Doctor, or login if you already have an account

---

## 📁 Project Structure

```
Mediease/
├── index.php                    # Homepage
├── database.php                 # Database configuration and utilities
├── functions.php                # Reusable utility functions
├── login.php                    # User login page
├── register.php                 # User registration page
├── logout.php                   # Logout handler
├── profile.php                  # User profile management
├── header.php                   # Navigation header
├── footer.php                   # Footer component
├── doctors.php                  # Doctor listing and directory
├── appointments.php             # Appointment management
├── appointment_actions.php      # Appointment CRUD operations
├── book_appointment.php         # Booking interface
├── availability.php             # Doctor availability management
├── check_availability.php       # Availability checking
├── schedule.php                 # Schedule management
├── dashboard.php                # User dashboard
├── get_doctor_details.php       # Doctor information retrieval
├── bulk_slot_actions.php        # Bulk operations for slots
├── user_details.php             # User information
└── README.md                    # This file
```

---

## 🔧 Core Files Description

### `database.php`
Handles all database operations and provides:
- **Database Connection** - PDO-based MySQL connection with error handling
- **DatabaseUtils Class** - Helper methods for common queries (email existence checks, user/doctor retrieval)

### `functions.php`
Contains 35+ reusable utility functions:
- **Input Validation** - Email, phone, and data sanitization
- **Authentication** - Password hashing and verification using bcrypt
- **Authorization** - Role-based access control and permission checking
- **Date/Time Management** - Formatting dates, times, and time-ago calculations
- **Appointment Logic** - Slot availability checking and validation
- **Pagination** - Generate pagination controls for large datasets
- **File Management** - Secure file upload with type and size validation

### `index.php`
Landing page featuring:
- Hero section with registration/login options
- Feature highlights and system overview
- How it Works step-by-step guide
- Available medical specializations
- System statistics for logged-in users

### `appointments.php` & `appointment_actions.php`
Comprehensive appointment management:
- View all appointments with filters
- Create new appointments
- Update appointment status
- Cancel or reschedule appointments

### `availability.php` & `schedule.php`
Doctor schedule and availability management:
- Set working hours
- Create and manage time slots
- Bulk slot operations
- View booked slots

---

## 👤 User Roles & Permissions

| Feature | Patient | Doctor | Admin |
|---------|---------|--------|-------|
| Browse Doctors | ✅ | ❌ | ✅ |
| Book Appointments | ✅ | ❌ | ✅ |
| Manage Availability | ❌ | ✅ | ✅ |
| View Own Appointments | ✅ | ✅ | ✅ |
| View All Appointments | ❌ | ❌ | ✅ |
| Manage Users | ❌ | ❌ | ✅ |
| Update Profile | ✅ | ✅ | ✅ |

---

## 💾 Database Tables

Core tables used in the system:
- **users** - Patient, doctor, and admin accounts
- **doctors** - Doctor-specific information
- **appointments** - Appointment bookings and status
- **availability_slots** - Doctor availability time slots
- **specializations** - Medical specialization categories
- **doctor_specializations** - Doctor to specialization mappings

---

## 🔐 Security Features

- **Password Security** - bcrypt hashing with `PASSWORD_DEFAULT` algorithm
- **Input Sanitization** - HTML entity encoding and XSS prevention
- **SQL Injection Prevention** - Prepared statements with parameterized queries
- **Session Management** - Secure PHP session handling with role verification
- **Authentication** - User session verification before data access
- **Email Validation** - RFC-compliant email format validation
- **Phone Validation** - International phone number support (10-15 digits)
- **File Upload Protection** - MIME type and size validation

---

## 📱 Medical Specializations

1. 🫀 **Cardiology** - Heart and cardiovascular system
2. 🧠 **Neurology** - Nervous system disorders
3. 👶 **Pediatrics** - Children's medicine
4. 👁️ **Ophthalmology** - Eye care and vision
5. 🎨 **Dermatology** - Skin disorders
6. 🦷 **Dentistry** - Dental health

---

## 🔧 Key Functions

### Authentication & Authorization
```php
require_auth($allowed_roles = [])     // Protect pages with role-based access
is_logged_in()                         // Check if user session exists
has_role($role)                        // Verify user role
can_user_perform_action($action, $role) // Check permission for action
```

### Input Validation
```php
sanitize_input($data)                  // Clean user input from XSS
validate_email($email)                 // Validate email format
validate_phone($phone)                 // Validate phone numbers
hash_password($password)               // Hash passwords securely
verify_password($password, $hash)      // Verify password against hash
```

### Appointment Management
```php
is_slot_available()                    // Check if time slot is free
validate_appointment_slot()            // Comprehensive slot validation
get_doctor_specializations()           // Get doctor's specializations
```

### Data Formatting
```php
format_date($date, $format)            // Format dates for display
format_time($time, $format)            // Format times for display
format_datetime($datetime, $format)    // Format combined date/time
time_ago($datetime)                    // Display "time ago" format
format_phone($phone)                   // Format phone for display
```

---

## 🛣️ Workflow Examples

### Patient Booking an Appointment
1. Register/Login as Patient
2. Navigate to Browse Doctors
3. Select doctor by specialization or search
4. View doctor profile and available slots
5. Select preferred date and time
6. Confirm booking details
7. Appointment appears in dashboard

### Doctor Managing Availability
1. Login as Doctor
2. Go to Availability Management
3. Set working hours for the week
4. Add individual time slots or bulk create slots
5. View upcoming appointments
6. Update appointment status after visit

---

## ⚠️ Troubleshooting

### Database Connection Error
```
Error: Connection error: SQLSTATE[HY000]
```
**Solution:** Verify database credentials in `database.php` and ensure MySQL is running.

### Login Issues
**Solution:** Clear browser cookies. Verify user exists in database with correct role assigned.

### Appointment Not Booking
**Solution:** Check that doctor has available slots created for the desired date and time. Verify slots are marked as available in database.

### Page Access Denied
**Solution:** Ensure you're logged in with appropriate role. Check `require_auth()` function requirements for that page.

---

## 📄 Configuration

### Database Setup
Edit `database.php` with your credentials:
```php
private $host = 'localhost';        // MySQL host
private $db_name = 'mediease';      // Database name
private $username = 'root';         // MySQL user
private $password = '';             // MySQL password
```

### Time Zone
Add to the beginning of files if needed:
```php
date_default_timezone_set('Asia/Dhaka');
```

---

## 🙏 Acknowledgments

- Built with PHP, MySQL, and Bootstrap 5
- Icons by [Font Awesome](https://fontawesome.com/)

---

<div align="center">

**Made for healthcare professionals and patients**

[⬆ Back to Top](#mediease---medical-appointment-booking-system)

</div>
