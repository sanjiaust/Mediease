# MediEase — Medical Appointment Booking System

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-005C87?logo=mysql)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.0-7952B3?logo=bootstrap)
![License](https://img.shields.io/badge/License-MIT-green)

A full-stack web application that connects patients with doctors for appointment scheduling. Built with PHP, MySQL, and Bootstrap 5 as a portfolio project demonstrating role-based system design and secure web development practices.

---

## Overview

MediEase handles the complete appointment lifecycle — from patient registration and doctor discovery to booking, scheduling, and status management. The system supports three distinct user roles, each with its own dashboard and permissions.

---

## Features

### Patient
- Register and log in securely
- Browse doctors by medical specialization
- View real-time slot availability and book appointments
- Manage (view / cancel) appointments from a personal dashboard

### Doctor
- Set up a professional profile with specializations
- Define working hours and available time slots (including bulk operations)
- Accept, reschedule, or cancel patient appointments
- View appointment history and upcoming schedule

### Admin
- Manage all user accounts (patients and doctors)
- Monitor and oversee all appointments system-wide
- Configure specializations and system settings

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 7.4+ |
| Database | MySQL 5.7+ with PDO |
| Frontend | Bootstrap 5, HTML, CSS |
| Security | bcrypt, prepared statements, session management |
| Server | Apache / Nginx |

---

## Security Highlights

- Passwords hashed with bcrypt (`PASSWORD_DEFAULT`)
- All database queries use prepared statements (PDO) — no raw SQL
- User input sanitized against XSS attacks
- Role-based access control enforced on every page via `require_auth()`
- Session verification before any data access

---

## Getting Started

### Prerequisites
- PHP 7.4+
- MySQL 5.7+
- Apache or Nginx with `mod_rewrite` enabled

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/sanjiaust/Mediease.git
cd Mediease
```

```sql
-- 2. Create the database
CREATE DATABASE mediease;
-- Note: A schema file is not included in the repository.
-- Create the tables manually based on the Database Schema section below.
```

```php
// 3. Update credentials in database.php
private $host     = 'localhost';
private $db_name  = 'mediease';
private $username = 'your_username';
private $password = 'your_password';
```

```bash
# 4. Set permissions
chmod -R 755 ./
```

Open `http://localhost/Mediease/` in your browser and register as a Patient or Doctor.

---

## Project Structure

```
Mediease/
├── index.php                 # Landing page
├── database.php              # PDO connection + DatabaseUtils class
├── functions.php             # 30+ utility functions (auth, validation, formatting)
├── login.php / register.php  # Authentication
├── logout.php
├── profile.php               # Profile management
├── header.php / footer.php   # Shared layout components
├── doctors.php               # Doctor directory
├── appointments.php          # Appointment list and filters
├── appointment_actions.php   # CRUD operations for appointments
├── book_appointment.php      # Booking interface
├── availability.php          # Doctor availability management
├── check_availability.php    # Slot availability checker
├── schedule.php              # Schedule management
├── bulk_slot_actions.php     # Bulk slot operations
├── dashboard.php             # Role-based dashboard
├── get_doctor_details.php    # Doctor info API
└── user_details.php          # User info handler
```

---

## Database Schema

| Table | Description |
|---|---|
| `users` | All user accounts (patients, doctors, admins) |
| `doctors` | Doctor-specific profiles and info |
| `appointments` | Bookings and status |
| `availability_slots` | Doctor time slots |
| `specializations` | Medical specialization categories |
| `doctor_specializations` | Doctor ↔ specialization mapping |
| `activity_logs` | User action tracking |

---

## Medical Specializations

Cardiology · Dermatology · Neurology · Pediatrics · Orthopedics · Gynecology · Psychiatry · Ophthalmology · ENT · General Medicine · Surgery · Anesthesiology · Radiology · Pathology · Emergency Medicine

Additional specializations can be added via the admin panel.

---

## Troubleshooting

**Database connection error**
Verify credentials in `database.php` and confirm MySQL is running.

**Login not working**
Clear browser cookies. Confirm the user exists in the `users` table with the correct role.

**Appointment not booking**
Ensure the doctor has created available slots for the selected date and time.

**Page access denied**
Confirm you are logged in with the correct role for that page.

---

## License

MIT License — feel free to use and modify for your own projects.

---

*Built with PHP, MySQL, and Bootstrap 5 as an academic project at Ahsanullah University of Science and Technology.*
