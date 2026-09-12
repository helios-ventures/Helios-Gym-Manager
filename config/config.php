<?php
/**
 * Gym Management System - Main Configuration File
 */

// Prevent direct access
if (!defined('GYM_ACCESS')) {
    define('GYM_ACCESS', true);
}

// Error reporting - set to 0 in production
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));

// Time zone
date_default_timezone_set('Africa/Nairobi');

// Load database config
require_once __DIR__ . '/database.php';

// Define constants
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', __DIR__);
}
if (!defined('CORE_PATH')) {
    define('CORE_PATH', BASE_PATH . '/core');
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', BASE_PATH . '/includes');
}
if (!defined('MODULES_PATH')) {
    define('MODULES_PATH', BASE_PATH . '/modules');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', BASE_PATH . '/assets');
}
if (!defined('API_PATH')) {
    define('API_PATH', BASE_PATH . '/api');
}
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', BASE_PATH . '/vendor');
}

// URL constants
// URL constants - FIXED to always point to project root
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Calculate project root URL from filesystem path vs document root
$docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$basePath = rtrim(str_replace('\\', '/', BASE_PATH), '/');
$projectPath = substr($basePath, strlen($docRoot)); // e.g. /gym-management

$baseUrl = $protocol . '://' . $host . $projectPath;

if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl);
}
if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', BASE_URL . '/assets');
}
if (!defined('API_URL')) {
    define('API_URL', BASE_URL . '/api');
}

// App version
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Helios Gym Manager');
}

// Include core classes
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Session.php';
require_once CORE_PATH . '/Auth.php';
require_once CORE_PATH . '/Helper.php';
require_once CORE_PATH . '/Validator.php';
require_once CORE_PATH . '/Response.php';
require_once CORE_PATH . '/ZKTeco.php';
