# FitLife Gym Management System

A comprehensive PHP-based gym management system with membership management, ZKTeco biometric attendance tracking, point of sale, communication tools, reporting, and role-based access control.

## Features

### 1. Membership Management
- Member registration with unique member codes
- Subscription plans (Daily, Weekly, Monthly, Quarterly, Half-yearly, Annual)
- Subscription renewals and upgrades
- Weight loss tracking with BMI calculation
- Fitness assessments and progress monitoring
- Emergency contact and health notes

### 2. Attendance Tracking with ZKTeco Biometric Integration
- Real-time attendance monitoring
- ZKTeco fingerprint/face recognition device support
- **Full CRUD operations on ZKTeco device users** (create, modify, enable/disable, delete)
- Device connection management (test, sync, restart)
- Manual check-in/check-out support
- Auto-checkout and late arrival detection

### 3. Point of Sale (POS)
- Product sales with inventory tracking
- Subscription sales and renewals
- Shopping cart with tax and discount support
- Multiple payment methods (Cash, Card, M-Pesa, Mobile Money, Bank Transfer)
- Invoice generation and receipt printing
- Low stock alerts

### 4. Communication Module
- Bulk SMS messaging
- Bulk email with templates
- WhatsApp Business API integration
- Message templates with variables
- Message delivery tracking
- Chat functionality

### 5. Reports Module
- Member reports with filters
- Sales reports with charts
- Attendance analytics
- Financial summaries
- Export to CSV, print view

### 6. System Administration
- General settings (gym info, financial, communication, attendance, POS, security)
- User management with roles
- Role-based access control
- Activity logging
- ZKTeco device configuration

### 7. Role-Based Access Control
- **Super Admin**: Full system access
- **Admin**: Manage members, attendance, POS, communication, view reports
- **Staff**: Limited operational access
- **Member**: Portal access only

### 8. Mobile-Friendly Design
- Responsive sidebar navigation
- Collapsible menu for desktop
- Touch-friendly interface
- Optimized for tablets and phones

## System Requirements

- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ or MariaDB 10.2+
- Apache with mod_rewrite
- ZKTeco biometric device (optional, for attendance)

## Installation

1. **Clone or download** the project to your web server document root.

2. **Create database** and import the schema:
   ```bash
   mysql -u root -p
   CREATE DATABASE gym_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   USE gym_management;
   SOURCE database.sql;
   ```

3. **Configure database** in `config/database.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'gym_management');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

4. **Set permissions**:
   ```bash
   chmod -R 755 /path/to/gym-management
   chmod -R 777 /path/to/gym-management/assets/uploads  # For file uploads
   ```

5. **Access the application**:
   - URL: `http://your-domain/gym-management/`
   - Default login: `superadmin` / `password`

## ZKTeco Biometric Device Setup

1. Connect your ZKTeco device to the same network as your server
2. Note the device's IP address
3. Go to **Attendance > Devices** in the system
4. Add the device with its IP address and port (default: 4370)
5. Click "Test Connection" to verify connectivity
6. Use "Sync Users" to push member data to the device
7. Use "Pull attendance data" to fetch attendance logs

### Managing Device Users
From the Device Management page, you can:
- **Add** users to the device (assigns biometric ID)
- **Remove** users from the device
- **Enable/Disable** users on the device
- Bulk sync all active members to the device

## File Structure

```
gym-management/
├── api/                    # AJAX API endpoints
│   ├── keepalive.php
│   └── zkteco-user.php    # ZKTeco CRUD operations
├── assets/                 # CSS, JS, images
├── config/
│   ├── config.php         # Main configuration
│   └── database.php       # Database credentials
├── core/                   # Core PHP classes
│   ├── Auth.php           # Authentication & RBAC
│   ├── Database.php       # PDO database wrapper
│   ├── Helper.php         # Utility functions
│   ├── Response.php       # HTTP responses
│   ├── Session.php        # Session management
│   ├── Validator.php      # Input validation
│   └── ZKTeco.php         # ZKTeco device integration
├── includes/               # Shared templates
│   ├── header.php
│   ├── sidebar.php        # Navigation menu
│   └── footer.php
├── modules/                # Feature modules
│   ├── attendance/        # Attendance + ZKTeco devices
│   ├── communication/     # SMS, email, WhatsApp
│   ├── dashboard/
│   ├── members/           # Membership + weight tracking
│   ├── pos/               # Point of Sale
│   ├── reports/           # Analytics & exports
│   └── settings/          # System configuration
├── database.sql           # Database schema
├── index.php              # Main dashboard
├── login.php              # Authentication
├── logout.php
└── .htaccess              # URL rewriting & security
```

## Security Features

- Password hashing with bcrypt
- CSRF token protection
- Session timeout management
- Role-based access control
- SQL injection prevention (parameterized queries)
- XSS protection (output escaping)
- Secure file upload handling

## Default Credentials

| Username     | Password  | Role        |
|-------------|-----------|-------------|
| superadmin  | password  | Super Admin |

**Important**: Change the default password after first login!

## License

This project is for educational and commercial use.
