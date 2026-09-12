-- Gym Management System Database Schema
-- Comprehensive schema for membership, attendance, POS, communication, reports, and settings

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- CORE TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `slug` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `permissions` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`, `slug`, `description`, `permissions`) VALUES
(1, 'Super Admin', 'super_admin', 'Full system access', '{"all": true}'),
(2, 'Admin', 'admin', 'Administrative access', '{"members": ["view","create","edit","delete"], "attendance": ["view","manage"], "pos": ["view","create","refund"], "communication": ["view","send"], "reports": ["view","export"], "settings": ["view"]}'),
(3, 'Staff', 'staff', 'Limited operational access', '{"members": ["view","create","edit"], "attendance": ["view","manage"], "pos": ["view","create"], "communication": ["view","send"], "reports": ["view"]}'),
(4, 'Member', 'member', 'Member portal access', '{"profile": ["view","edit"], "attendance": ["view"], "subscriptions": ["view"]}');

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) UNSIGNED NOT NULL DEFAULT 4,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','inactive','suspended') DEFAULT 'active',
  `last_login` DATETIME DEFAULT NULL,
  `login_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `role_id`, `username`, `password_hash`, `email`, `phone`, `first_name`, `last_name`, `status`) VALUES
(1, 1, 'superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin@gym.local', '0700000000', 'Super', 'Admin', 'active');

-- ============================================
-- MEMBERSHIP MODULE
-- ============================================

CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `duration_days` INT(11) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `plan_type` ENUM('daily','weekly','monthly','quarterly','half_yearly','yearly') DEFAULT 'monthly',
  `features` JSON DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subscription_plans` (`name`, `description`, `duration_days`, `price`, `plan_type`, `features`) VALUES
('Daily Pass', 'Single day access', 1, 15.00, 'daily', '["gym_access","locker"]'),
('Weekly Pass', '7-day full access', 7, 50.00, 'weekly', '["gym_access","locker","group_classes"]'),
('Basic Monthly', 'Monthly gym access', 30, 150.00, 'monthly', '["gym_access","locker"]'),
('Premium Monthly', 'Monthly with trainer', 30, 300.00, 'monthly', '["gym_access","locker","trainer","group_classes","sauna"]'),
('Quarterly', '3 months premium', 90, 750.00, 'quarterly', '["gym_access","locker","trainer","group_classes","sauna","nutrition_plan"]'),
('Half Yearly', '6 months premium', 180, 1350.00, 'half_yearly', '["gym_access","locker","trainer","group_classes","sauna","nutrition_plan","pool"]'),
('Annual VIP', 'Full year VIP access', 365, 2400.00, 'yearly', '["gym_access","locker","trainer","group_classes","sauna","nutrition_plan","pool","priority_booking"]');

CREATE TABLE IF NOT EXISTS `members` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_code` VARCHAR(20) NOT NULL,
  `user_id` INT(11) UNSIGNED DEFAULT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `gender` ENUM('male','female','other') DEFAULT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `emergency_contact_name` VARCHAR(100) DEFAULT NULL,
  `emergency_contact_phone` VARCHAR(20) DEFAULT NULL,
  `photo` VARCHAR(255) DEFAULT NULL,
  `biometric_id` INT(11) UNSIGNED DEFAULT NULL,
  `height_cm` DECIMAL(5,1) DEFAULT NULL,
  `current_weight_kg` DECIMAL(5,2) DEFAULT NULL,
  `target_weight_kg` DECIMAL(5,2) DEFAULT NULL,
  `fitness_goal` ENUM('weight_loss','muscle_gain','maintenance','general_fitness','rehabilitation') DEFAULT 'general_fitness',
  `health_notes` TEXT DEFAULT NULL,
  `join_date` DATE NOT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `status` ENUM('active','inactive','expired','suspended') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_code` (`member_code`),
  UNIQUE KEY `biometric_id` (`biometric_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_subscriptions` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT(11) UNSIGNED NOT NULL,
  `plan_id` INT(11) UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `amount_paid` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('cash','card','mpesa','bank_transfer','mobile_money') DEFAULT 'cash',
  `transaction_id` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('active','expired','cancelled','pending') DEFAULT 'pending',
  `created_by` INT(11) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `plan_id` (`plan_id`),
  KEY `status` (`status`),
  CONSTRAINT `fk_subscriptions_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subscriptions_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weight_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT(11) UNSIGNED NOT NULL,
  `weight_kg` DECIMAL(5,2) NOT NULL,
  `body_fat_percent` DECIMAL(4,1) DEFAULT NULL,
  `muscle_kg` DECIMAL(5,2) DEFAULT NULL,
  `bmi` DECIMAL(4,1) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `logged_by` INT(11) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_weight_logs_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fitness_assessments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT(11) UNSIGNED NOT NULL,
  `assessment_date` DATE NOT NULL,
  `chest_cm` DECIMAL(5,1) DEFAULT NULL,
  `waist_cm` DECIMAL(5,1) DEFAULT NULL,
  `hips_cm` DECIMAL(5,1) DEFAULT NULL,
  `arms_cm` DECIMAL(5,1) DEFAULT NULL,
  `thighs_cm` DECIMAL(5,1) DEFAULT NULL,
  `pushups_count` INT(11) DEFAULT NULL,
  `squats_count` INT(11) DEFAULT NULL,
  `plank_seconds` INT(11) DEFAULT NULL,
  `run_time_minutes` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `assessed_by` INT(11) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `fk_assessments_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ATTENDANCE MODULE
-- ============================================

CREATE TABLE IF NOT EXISTS `zkteco_devices` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_name` VARCHAR(100) NOT NULL,
  `device_ip` VARCHAR(45) NOT NULL,
  `port` INT(11) DEFAULT 4370,
  `serial_number` VARCHAR(100) DEFAULT NULL,
  `location` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('online','offline','error') DEFAULT 'offline',
  `last_sync` DATETIME DEFAULT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_ip` (`device_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT(11) UNSIGNED DEFAULT NULL,
  `biometric_id` INT(11) UNSIGNED DEFAULT NULL,
  `device_id` INT(11) UNSIGNED DEFAULT NULL,
  `check_in` DATETIME NOT NULL,
  `check_out` DATETIME DEFAULT NULL,
  `duration_minutes` INT(11) DEFAULT NULL,
  `status` ENUM('present','late','early_exit','absent') DEFAULT 'present',
  `check_type` ENUM('fingerprint','card','face','manual') DEFAULT 'fingerprint',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `biometric_id` (`biometric_id`),
  KEY `check_in` (`check_in`),
  KEY `device_id` (`device_id`),
  CONSTRAINT `fk_attendance_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_device` FOREIGN KEY (`device_id`) REFERENCES `zkteco_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- POINT OF SALE MODULE
-- ============================================

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product_categories` (`name`, `description`) VALUES
('Supplements', 'Protein, pre-workout, vitamins'),
('Apparel', 'Gym wear, shoes, accessories'),
('Equipment', 'Personal training equipment'),
('Beverages', 'Energy drinks, water, protein shakes'),
('Membership', 'Subscription plans'),
('Services', 'Personal training, classes');

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT(11) UNSIGNED DEFAULT NULL,
  `sku` VARCHAR(50) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `cost_price` DECIMAL(10,2) DEFAULT NULL,
  `stock_quantity` INT(11) DEFAULT 0,
  `low_stock_threshold` INT(11) DEFAULT 10,
  `unit_type` VARCHAR(20) DEFAULT 'piece',
  `is_subscription` TINYINT(1) DEFAULT 0,
  `plan_id` INT(11) UNSIGNED DEFAULT NULL,
  `status` ENUM('active','inactive','out_of_stock') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `category_id` (`category_id`),
  KEY `status` (`status`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products` (`category_id`, `sku`, `name`, `description`, `unit_price`, `cost_price`, `stock_quantity`, `low_stock_threshold`, `unit_type`) VALUES
(1, 'SUPP-001', 'Whey Protein 2kg', 'Premium whey protein isolate', 85.00, 60.00, 45, 10, 'piece'),
(1, 'SUPP-002', 'Pre-Workout 300g', 'Energy and focus formula', 45.00, 30.00, 32, 10, 'piece'),
(1, 'SUPP-003', 'BCAA 500g', 'Branched chain amino acids', 55.00, 38.00, 28, 10, 'piece'),
(3, 'EQUIP-001', 'Resistance Bands Set', '5 levels resistance bands', 35.00, 18.00, 50, 15, 'set'),
(3, 'EQUIP-002', 'Yoga Mat Premium', 'Non-slip exercise mat', 40.00, 22.00, 22, 10, 'piece'),
(4, 'BEV-001', 'Energy Drink 500ml', 'Pre-workout energy drink', 8.00, 4.50, 120, 30, 'piece'),
(4, 'BEV-002', 'Protein Shake RTD', 'Ready-to-drink protein shake', 15.00, 9.00, 60, 20, 'piece'),
(2, 'APP-001', 'Gym Towel', 'Quick-dry microfiber towel', 20.00, 10.00, 40, 15, 'piece'),
(2, 'APP-002', 'Lifting Gloves', 'Padded workout gloves', 25.00, 13.00, 35, 10, 'pair');

CREATE TABLE IF NOT EXISTS `sales` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(20) NOT NULL,
  `member_id` INT(11) UNSIGNED DEFAULT NULL,
  `customer_name` VARCHAR(100) DEFAULT NULL,
  `customer_phone` VARCHAR(20) DEFAULT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `tax_amount` DECIMAL(10,2) DEFAULT 0.00,
  `discount_amount` DECIMAL(10,2) DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('cash','card','mpesa','bank_transfer','mobile_money') DEFAULT 'cash',
  `payment_status` ENUM('paid','pending','partial','refunded') DEFAULT 'paid',
  `notes` TEXT DEFAULT NULL,
  `sold_by` INT(11) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `member_id` (`member_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` INT(11) UNSIGNED NOT NULL,
  `product_id` INT(11) UNSIGNED DEFAULT NULL,
  `plan_id` INT(11) UNSIGNED DEFAULT NULL,
  `item_name` VARCHAR(200) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `total_price` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sale_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` INT(11) UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('cash','card','mpesa','bank_transfer','mobile_money') DEFAULT 'cash',
  `transaction_reference` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('completed','pending','failed') DEFAULT 'completed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `fk_payments_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- COMMUNICATION MODULE
-- ============================================

CREATE TABLE IF NOT EXISTS `message_templates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('sms','email','whatsapp') DEFAULT 'sms',
  `subject` VARCHAR(200) DEFAULT NULL,
  `content` TEXT NOT NULL,
  `variables` JSON DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `message_templates` (`name`, `type`, `subject`, `content`, `variables`) VALUES
('Welcome Member', 'sms', NULL, 'Welcome {first_name}! Your membership at {gym_name} is now active. Your member code: {member_code}. We are excited to have you!', '["first_name","gym_name","member_code"]'),
('Subscription Expiry Reminder', 'sms', NULL, 'Hi {first_name}, your membership expires on {expiry_date}. Renew now to avoid interruption. Visit {gym_name} or call us.', '["first_name","expiry_date","gym_name"]'),
('Payment Receipt', 'email', 'Payment Receipt - {invoice_number}', '<h2>Thank you for your payment!</h2><p>Dear {first_name},</p><p>We have received your payment of Ksh {amount} for {plan_name}.</p><p>Invoice: {invoice_number}<br>Date: {payment_date}</p><p>Best regards,<br>{gym_name}</p>', '["first_name","amount","plan_name","invoice_number","payment_date","gym_name"]'),
('Membership Renewal', 'whatsapp', NULL, 'Hello {first_name}! 👋 Your {gym_name} membership is due for renewal on {expiry_date}. Renew today and get 10% off! 🎉', '["first_name","gym_name","expiry_date"]'),
('Birthday Greeting', 'sms', NULL, 'Happy Birthday {first_name}! 🎂 Enjoy a FREE personal training session on us at {gym_name}. Valid this week only!', '["first_name","gym_name"]');

CREATE TABLE IF NOT EXISTS `message_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT(11) UNSIGNED DEFAULT NULL,
  `recipient_type` ENUM('single','bulk') DEFAULT 'single',
  `recipient_id` INT(11) UNSIGNED DEFAULT NULL,
  `recipient_phone` VARCHAR(20) DEFAULT NULL,
  `recipient_email` VARCHAR(100) DEFAULT NULL,
  `message_type` ENUM('sms','email','whatsapp') NOT NULL,
  `subject` VARCHAR(200) DEFAULT NULL,
  `content` TEXT NOT NULL,
  `status` ENUM('pending','sent','failed','delivered') DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `sent_by` INT(11) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `recipient_id` (`recipient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_id` INT(11) UNSIGNED NOT NULL,
  `sender_type` ENUM('user','member') NOT NULL,
  `receiver_id` INT(11) UNSIGNED DEFAULT NULL,
  `receiver_type` ENUM('user','member') DEFAULT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  KEY `is_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SYSTEM SETTINGS
-- ============================================

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `setting_group` VARCHAR(50) DEFAULT 'general',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_editable` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('gym_name', 'FitLife Gym', 'general', 'Gym business name'),
('gym_address', '123 Fitness Street, Nairobi, Kenya', 'general', 'Gym physical address'),
('gym_phone', '+254 700 123 456', 'general', 'Primary contact phone'),
('gym_email', 'info@fitlifegym.co.ke', 'general', 'Primary contact email'),
('currency_symbol', 'Ksh', 'general', 'Currency symbol'),
('currency_code', 'KES', 'general', 'ISO currency code'),
('tax_rate', '16', 'financial', 'VAT/Tax percentage'),
('tax_enabled', '1', 'financial', 'Enable tax on sales'),
('sms_api_key', '', 'communication', 'SMS gateway API key'),
('sms_sender_id', 'FitLifeGym', 'communication', 'SMS sender ID'),
('email_smtp_host', '', 'communication', 'SMTP server host'),
('email_smtp_port', '587', 'communication', 'SMTP server port'),
('email_smtp_user', '', 'communication', 'SMTP username'),
('email_smtp_password', '', 'communication', 'SMTP password'),
('email_from_address', 'noreply@fitlifegym.co.ke', 'communication', 'From email address'),
('email_from_name', 'FitLife Gym', 'communication', 'From display name'),
('whatsapp_api_token', '', 'communication', 'WhatsApp Business API token'),
('whatsapp_phone_id', '', 'communication', 'WhatsApp phone number ID'),
('zkteco_default_ip', '', 'device', 'Default ZKTeco device IP'),
('zkteco_default_port', '4370', 'device', 'Default ZKTeco device port'),
('zkteco_sync_interval', '5', 'device', 'Auto-sync interval in minutes'),
('auto_checkout_enabled', '1', 'attendance', 'Auto-checkout members at midnight'),
('check_in_start_time', '05:00', 'attendance', 'Earliest check-in time'),
('check_in_end_time', '23:00', 'attendance', 'Latest check-in time'),
('late_threshold_minutes', '15', 'attendance', 'Minutes after which check-in is marked late'),
('logo_path', 'assets/images/logo.png', 'branding', 'Gym logo image path'),
('favicon_path', 'assets/images/favicon.ico', 'branding', 'Favicon path'),
('receipt_header', 'FitLife Gym\n123 Fitness Street\nNairobi, Kenya\nTel: +254 700 123 456', 'pos', 'Receipt header text'),
('receipt_footer', 'Thank you for your business!\nFollow us @fitlifegym', 'pos', 'Receipt footer text'),
('low_stock_alert', '1', 'inventory', 'Enable low stock alerts'),
('session_timeout', '30', 'security', 'Session timeout in minutes'),
('password_min_length', '6', 'security', 'Minimum password length'),
('backup_auto', '1', 'backup', 'Enable automatic backups'),
('backup_frequency', 'daily', 'backup', 'Backup frequency');

-- ============================================
-- ACTIVITY LOGS
-- ============================================

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED DEFAULT NULL,
  `user_type` ENUM('user','member','system') DEFAULT 'user',
  `action` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
