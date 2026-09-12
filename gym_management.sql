-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 19, 2026 at 11:44 AM
-- Server version: 9.1.0
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gym_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED DEFAULT NULL,
  `user_type` enum('user','member','system') COLLATE utf8mb4_unicode_ci DEFAULT 'user',
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `user_type`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'user', 'member_create', 'Created member GYM26LWHTA: JEAN MWANGI', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-19 10:24:06');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

DROP TABLE IF EXISTS `attendance_logs`;
CREATE TABLE IF NOT EXISTS `attendance_logs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int UNSIGNED DEFAULT NULL,
  `biometric_id` int UNSIGNED DEFAULT NULL,
  `device_id` int UNSIGNED DEFAULT NULL,
  `check_in` datetime NOT NULL,
  `check_out` datetime DEFAULT NULL,
  `duration_minutes` int DEFAULT NULL,
  `status` enum('present','late','early_exit','absent') COLLATE utf8mb4_unicode_ci DEFAULT 'present',
  `check_type` enum('fingerprint','card','face','manual') COLLATE utf8mb4_unicode_ci DEFAULT 'fingerprint',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `check_in_2` (`check_in`),
  KEY `member_id` (`member_id`),
  KEY `biometric_id` (`biometric_id`),
  KEY `check_in` (`check_in`),
  KEY `device_id` (`device_id`),
  KEY `idx_member_date` (`member_id`,`check_in`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance_logs`
--

INSERT INTO `attendance_logs` (`id`, `member_id`, `biometric_id`, `device_id`, `check_in`, `check_out`, `duration_minutes`, `status`, `check_type`, `notes`, `created_at`) VALUES
(1, 1, 4762, 1, '2026-05-19 13:26:01', NULL, NULL, 'present', 'fingerprint', NULL, '2026-05-19 11:39:05');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_id` int UNSIGNED NOT NULL,
  `sender_type` enum('user','member') COLLATE utf8mb4_unicode_ci NOT NULL,
  `receiver_id` int UNSIGNED DEFAULT NULL,
  `receiver_type` enum('user','member') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  KEY `is_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fitness_assessments`
--

DROP TABLE IF EXISTS `fitness_assessments`;
CREATE TABLE IF NOT EXISTS `fitness_assessments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int UNSIGNED NOT NULL,
  `assessment_date` date NOT NULL,
  `chest_cm` decimal(5,1) DEFAULT NULL,
  `waist_cm` decimal(5,1) DEFAULT NULL,
  `hips_cm` decimal(5,1) DEFAULT NULL,
  `arms_cm` decimal(5,1) DEFAULT NULL,
  `thighs_cm` decimal(5,1) DEFAULT NULL,
  `pushups_count` int DEFAULT NULL,
  `squats_count` int DEFAULT NULL,
  `plank_seconds` int DEFAULT NULL,
  `run_time_minutes` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `assessed_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

DROP TABLE IF EXISTS `members`;
CREATE TABLE IF NOT EXISTS `members` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `first_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender` enum('male','female','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `emergency_contact_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `biometric_id` int UNSIGNED DEFAULT NULL,
  `height_cm` decimal(5,1) DEFAULT NULL,
  `current_weight_kg` decimal(5,2) DEFAULT NULL,
  `target_weight_kg` decimal(5,2) DEFAULT NULL,
  `fitness_goal` enum('weight_loss','muscle_gain','maintenance','general_fitness','rehabilitation') COLLATE utf8mb4_unicode_ci DEFAULT 'general_fitness',
  `health_notes` text COLLATE utf8mb4_unicode_ci,
  `join_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','inactive','expired','suspended') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_code` (`member_code`),
  UNIQUE KEY `biometric_id` (`biometric_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`id`, `member_code`, `user_id`, `first_name`, `last_name`, `email`, `phone`, `gender`, `date_of_birth`, `address`, `emergency_contact_name`, `emergency_contact_phone`, `photo`, `biometric_id`, `height_cm`, `current_weight_kg`, `target_weight_kg`, `fitness_goal`, `health_notes`, `join_date`, `expiry_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 'GYM26LWHTA', NULL, 'JEAN', 'MWANGI', 'jeanmwangi@gmail.com', '0727382120', 'male', '2018-10-04', 'Kisaju Along Nairobi-Namanga Road', 'Claudio Mwangi', '0716333222', NULL, 4762, 0.0, 35.00, 0.00, 'general_fitness', 'Asthmatic', '2026-05-19', '2027-05-19', 'active', '2026-05-19 10:24:06', '2026-05-19 10:24:49');

-- --------------------------------------------------------

--
-- Table structure for table `member_subscriptions`
--

DROP TABLE IF EXISTS `member_subscriptions`;
CREATE TABLE IF NOT EXISTS `member_subscriptions` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int UNSIGNED NOT NULL,
  `plan_id` int UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','mpesa','bank_transfer','mobile_money') COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `transaction_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','expired','cancelled','pending') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `plan_id` (`plan_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `member_subscriptions`
--

INSERT INTO `member_subscriptions` (`id`, `member_id`, `plan_id`, `start_date`, `end_date`, `amount_paid`, `payment_method`, `transaction_id`, `status`, `created_by`, `created_at`) VALUES
(1, 1, 7, '2026-05-19', '2027-05-19', 2400.00, 'cash', NULL, 'active', 1, '2026-05-19 10:24:06');

-- --------------------------------------------------------

--
-- Table structure for table `message_logs`
--

DROP TABLE IF EXISTS `message_logs`;
CREATE TABLE IF NOT EXISTS `message_logs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` int UNSIGNED DEFAULT NULL,
  `recipient_type` enum('single','bulk') COLLATE utf8mb4_unicode_ci DEFAULT 'single',
  `recipient_id` int UNSIGNED DEFAULT NULL,
  `recipient_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_type` enum('sms','email','whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','sent','failed','delivered') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `recipient_id` (`recipient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_templates`
--

DROP TABLE IF EXISTS `message_templates`;
CREATE TABLE IF NOT EXISTS `message_templates` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('sms','email','whatsapp') COLLATE utf8mb4_unicode_ci DEFAULT 'sms',
  `subject` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables` json DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `message_templates`
--

INSERT INTO `message_templates` (`id`, `name`, `type`, `subject`, `content`, `variables`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Welcome Member', 'sms', NULL, 'Welcome {first_name}! Your membership at {gym_name} is now active. Your member code: {member_code}. We are excited to have you!', '[\"first_name\", \"gym_name\", \"member_code\"]', 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(2, 'Subscription Expiry Reminder', 'sms', NULL, 'Hi {first_name}, your membership expires on {expiry_date}. Renew now to avoid interruption. Visit {gym_name} or call us.', '[\"first_name\", \"expiry_date\", \"gym_name\"]', 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(3, 'Payment Receipt', 'email', 'Payment Receipt - {invoice_number}', '<h2>Thank you for your payment!</h2><p>Dear {first_name},</p><p>We have received your payment of Ksh {amount} for {plan_name}.</p><p>Invoice: {invoice_number}<br>Date: {payment_date}</p><p>Best regards,<br>{gym_name}</p>', '[\"first_name\", \"amount\", \"plan_name\", \"invoice_number\", \"payment_date\", \"gym_name\"]', 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(4, 'Membership Renewal', 'whatsapp', NULL, 'Hello {first_name}! 👋 Your {gym_name} membership is due for renewal on {expiry_date}. Renew today and get 10% off! 🎉', '[\"first_name\", \"gym_name\", \"expiry_date\"]', 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(5, 'Birthday Greeting', 'sms', NULL, 'Happy Birthday {first_name}! 🎂 Enjoy a FREE personal training session on us at {gym_name}. Valid this week only!', '[\"first_name\", \"gym_name\"]', 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` int UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','mpesa','bank_transfer','mobile_money') COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `transaction_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('completed','pending','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'completed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` int UNSIGNED DEFAULT NULL,
  `sku` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `unit_price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `stock_quantity` int DEFAULT '0',
  `low_stock_threshold` int DEFAULT '10',
  `unit_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'piece',
  `is_subscription` tinyint(1) DEFAULT '0',
  `plan_id` int UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive','out_of_stock') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `category_id` (`category_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `sku`, `name`, `description`, `unit_price`, `cost_price`, `stock_quantity`, `low_stock_threshold`, `unit_type`, `is_subscription`, `plan_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'SUPP-001', 'Whey Protein 2kg', 'Premium whey protein isolate', 85.00, 60.00, 45, 10, 'piece', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(2, 1, 'SUPP-002', 'Pre-Workout 300g', 'Energy and focus formula', 45.00, 30.00, 32, 10, 'piece', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(3, 1, 'SUPP-003', 'BCAA 500g', 'Branched chain amino acids', 55.00, 38.00, 28, 10, 'piece', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(4, 3, 'EQUIP-001', 'Resistance Bands Set', '5 levels resistance bands', 35.00, 18.00, 50, 15, 'set', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(5, 3, 'EQUIP-002', 'Yoga Mat Premium', 'Non-slip exercise mat', 40.00, 22.00, 22, 10, 'piece', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(6, 4, 'BEV-001', 'Energy Drink 500ml', 'Pre-workout energy drink', 8.00, 4.50, 120, 30, 'piece', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(7, 4, 'BEV-002', 'Protein Shake RTD', 'Ready-to-drink protein shake', 15.00, 9.00, 60, 20, 'piece', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(8, 2, 'APP-001', 'Gym Towel', 'Quick-dry microfiber towel', 20.00, 10.00, 40, 15, 'piece', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(9, 2, 'APP-002', 'Lifting Gloves', 'Padded workout gloves', 25.00, 13.00, 35, 10, 'pair', 0, NULL, 'active', '2026-05-19 10:20:54', '2026-05-19 10:20:54');

-- --------------------------------------------------------

--
-- Table structure for table `product_categories`
--

DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_categories`
--

INSERT INTO `product_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES
(1, 'Supplements', 'Protein, pre-workout, vitamins', 'active', '2026-05-19 10:20:53'),
(2, 'Apparel', 'Gym wear, shoes, accessories', 'active', '2026-05-19 10:20:53'),
(3, 'Equipment', 'Personal training equipment', 'active', '2026-05-19 10:20:53'),
(4, 'Beverages', 'Energy drinks, water, protein shakes', 'active', '2026-05-19 10:20:53'),
(5, 'Membership', 'Subscription plans', 'active', '2026-05-19 10:20:53'),
(6, 'Services', 'Personal training, classes', 'active', '2026-05-19 10:20:53');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permissions` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `slug`, `description`, `permissions`, `created_at`, `updated_at`) VALUES
(1, 'Super Admin', 'super_admin', 'Full system access', '{\"all\": true}', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(2, 'Admin', 'admin', 'Administrative access', '{\"pos\": [\"view\", \"create\", \"refund\"], \"members\": [\"view\", \"create\", \"edit\", \"delete\"], \"reports\": [\"view\", \"export\"], \"settings\": [\"view\"], \"attendance\": [\"view\", \"manage\"], \"communication\": [\"view\", \"send\"]}', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(3, 'Staff', 'staff', 'Limited operational access', '{\"pos\": [\"view\", \"create\"], \"members\": [\"view\", \"create\", \"edit\"], \"reports\": [\"view\"], \"attendance\": [\"view\", \"manage\"], \"communication\": [\"view\", \"send\"]}', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(4, 'Member', 'member', 'Member portal access', '{\"profile\": [\"view\", \"edit\"], \"attendance\": [\"view\"], \"subscriptions\": [\"view\"]}', '2026-05-19 10:20:53', '2026-05-19 10:20:53');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
CREATE TABLE IF NOT EXISTS `sales` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` int UNSIGNED DEFAULT NULL,
  `customer_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `tax_amount` decimal(10,2) DEFAULT '0.00',
  `discount_amount` decimal(10,2) DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','mpesa','bank_transfer','mobile_money') COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `payment_status` enum('paid','pending','partial','refunded') COLLATE utf8mb4_unicode_ci DEFAULT 'paid',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `sold_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `member_id` (`member_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED DEFAULT NULL,
  `plan_id` int UNSIGNED DEFAULT NULL,
  `item_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

DROP TABLE IF EXISTS `subscription_plans`;
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `duration_days` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `plan_type` enum('daily','weekly','monthly','quarterly','half_yearly','yearly') COLLATE utf8mb4_unicode_ci DEFAULT 'monthly',
  `features` json DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscription_plans`
--

INSERT INTO `subscription_plans` (`id`, `name`, `description`, `duration_days`, `price`, `plan_type`, `features`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Daily Pass', 'Single day access', 1, 15.00, 'daily', '[\"gym_access\", \"locker\"]', 'active', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(2, 'Weekly Pass', '7-day full access', 7, 50.00, 'weekly', '[\"gym_access\", \"locker\", \"group_classes\"]', 'active', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(3, 'Basic Monthly', 'Monthly gym access', 30, 150.00, 'monthly', '[\"gym_access\", \"locker\"]', 'active', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(4, 'Premium Monthly', 'Monthly with trainer', 30, 300.00, 'monthly', '[\"gym_access\", \"locker\", \"trainer\", \"group_classes\", \"sauna\"]', 'active', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(5, 'Quarterly', '3 months premium', 90, 750.00, 'quarterly', '[\"gym_access\", \"locker\", \"trainer\", \"group_classes\", \"sauna\", \"nutrition_plan\"]', 'active', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(6, 'Half Yearly', '6 months premium', 180, 1350.00, 'half_yearly', '[\"gym_access\", \"locker\", \"trainer\", \"group_classes\", \"sauna\", \"nutrition_plan\", \"pool\"]', 'active', '2026-05-19 10:20:53', '2026-05-19 10:20:53'),
(7, 'Annual VIP', 'Full year VIP access', 365, 2400.00, 'yearly', '[\"gym_access\", \"locker\", \"trainer\", \"group_classes\", \"sauna\", \"nutrition_plan\", \"pool\", \"priority_booking\"]', 'active', '2026-05-19 10:20:53', '2026-05-19 10:20:53');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `setting_group` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'general',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_editable` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_group`, `description`, `is_editable`, `created_at`, `updated_at`) VALUES
(1, 'gym_name', 'Helios Fitness', 'general', 'Gym business name', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(2, 'gym_address', '123 Fitness Street, Nairobi, Kenya', 'general', 'Gym physical address', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(3, 'gym_phone', '+254 727 382 120', 'general', 'Primary contact phone', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(4, 'gym_email', 'info@gymsoftware.co.ke', 'general', 'Primary contact email', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(5, 'currency_symbol', 'KSH', 'general', 'Currency symbol', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(6, 'currency_code', 'KES', 'general', 'ISO currency code', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(7, 'tax_rate', '16', 'financial', 'VAT/Tax percentage', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(8, 'tax_enabled', '1', 'financial', 'Enable tax on sales', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(9, 'sms_api_key', '', 'communication', 'SMS gateway API key', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(10, 'sms_sender_id', 'HeliosFitness', 'communication', 'SMS sender ID', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(11, 'email_smtp_host', '', 'communication', 'SMTP server host', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(12, 'email_smtp_port', '587', 'communication', 'SMTP server port', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(13, 'email_smtp_user', '', 'communication', 'SMTP username', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(14, 'email_smtp_password', '', 'communication', 'SMTP password', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(15, 'email_from_address', 'noreply@gymsoftware.co.ke', 'communication', 'From email address', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(16, 'email_from_name', 'Helios Fitness', 'communication', 'From display name', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(17, 'whatsapp_api_token', '', 'communication', 'WhatsApp Business API token', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(18, 'whatsapp_phone_id', '', 'communication', 'WhatsApp phone number ID', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(19, 'zkteco_default_ip', '', 'device', 'Default ZKTeco device IP', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(20, 'zkteco_default_port', '4370', 'device', 'Default ZKTeco device port', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(21, 'zkteco_sync_interval', '5', 'device', 'Auto-sync interval in minutes', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(22, 'auto_checkout_enabled', '1', 'attendance', 'Auto-checkout members at midnight', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(23, 'check_in_start_time', '05:00', 'attendance', 'Earliest check-in time', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(24, 'check_in_end_time', '23:00', 'attendance', 'Latest check-in time', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(25, 'late_threshold_minutes', '15', 'attendance', 'Minutes after which check-in is marked late', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(26, 'logo_path', 'assets/images/logo.png', 'branding', 'Gym logo image path', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(27, 'favicon_path', 'assets/images/favicon.ico', 'branding', 'Favicon path', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(28, 'receipt_header', 'Helios Fitness\r\n123 Fitness Street\r\nNairobi, Kenya\r\nTel: +254 700 123 456', 'pos', 'Receipt header text', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(29, 'receipt_footer', 'Thank you for your business! Follow us @heliosfitness', 'pos', 'Receipt footer text', 1, '2026-05-19 10:20:54', '2026-05-19 11:43:06'),
(30, 'low_stock_alert', '1', 'inventory', 'Enable low stock alerts', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(31, 'session_timeout', '30', 'security', 'Session timeout in minutes', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(32, 'password_min_length', '6', 'security', 'Minimum password length', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(33, 'backup_auto', '1', 'backup', 'Enable automatic backups', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54'),
(34, 'backup_frequency', 'daily', 'backup', 'Backup frequency', 1, '2026-05-19 10:20:54', '2026-05-19 10:20:54');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` int UNSIGNED NOT NULL DEFAULT '4',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `login_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role_id`, `username`, `password_hash`, `email`, `phone`, `first_name`, `last_name`, `avatar`, `status`, `last_login`, `login_ip`, `created_at`, `updated_at`) VALUES
(1, 1, 'superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin@gym.local', '0700000000', 'Super', 'Admin', NULL, 'active', NULL, NULL, '2026-05-19 10:20:53', '2026-05-19 10:20:53');

-- --------------------------------------------------------

--
-- Table structure for table `weight_logs`
--

DROP TABLE IF EXISTS `weight_logs`;
CREATE TABLE IF NOT EXISTS `weight_logs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int UNSIGNED NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL,
  `body_fat_percent` decimal(4,1) DEFAULT NULL,
  `muscle_kg` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,1) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `logged_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `weight_logs`
--

INSERT INTO `weight_logs` (`id`, `member_id`, `weight_kg`, `body_fat_percent`, `muscle_kg`, `bmi`, `notes`, `logged_by`, `created_at`) VALUES
(1, 1, 35.00, NULL, NULL, 0.0, NULL, 1, '2026-05-19 10:24:06');

-- --------------------------------------------------------

--
-- Table structure for table `zkteco_devices`
--

DROP TABLE IF EXISTS `zkteco_devices`;
CREATE TABLE IF NOT EXISTS `zkteco_devices` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `port` int DEFAULT '4370',
  `serial_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('online','offline','error') COLLATE utf8mb4_unicode_ci DEFAULT 'offline',
  `last_sync` datetime DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_ip` (`device_ip`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `zkteco_devices`
--

INSERT INTO `zkteco_devices` (`id`, `device_name`, `device_ip`, `port`, `serial_number`, `location`, `status`, `last_sync`, `is_default`, `created_at`, `updated_at`) VALUES
(1, 'MAINBIO', '10.5.50.62', 4370, NULL, 'HELIOS', 'online', '2026-05-19 14:16:39', 1, '2026-05-19 10:23:15', '2026-05-19 11:16:39');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD CONSTRAINT `fk_attendance_device` FOREIGN KEY (`device_id`) REFERENCES `zkteco_devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attendance_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fitness_assessments`
--
ALTER TABLE `fitness_assessments`
  ADD CONSTRAINT `fk_assessments_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `members`
--
ALTER TABLE `members`
  ADD CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `member_subscriptions`
--
ALTER TABLE `member_subscriptions`
  ADD CONSTRAINT `fk_subscriptions_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subscriptions_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_sale_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `weight_logs`
--
ALTER TABLE `weight_logs`
  ADD CONSTRAINT `fk_weight_logs_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
