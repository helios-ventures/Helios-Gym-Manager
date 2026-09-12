<?php
/**
 * Authentication and Authorization Class
 */

namespace Gym\Core;

class Auth {
    
    /**
     * Login user
     */
    public static function login(string $username, string $password): array {
        $user = Database::fetchOne(
            "SELECT u.*, r.name as role_name, r.slug as role_slug, r.permissions 
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             WHERE (u.username = ? OR u.email = ?) AND u.status = 'active'",
            [$username, $username]
        );
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or password'];
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid username or password'];
        }
        
        // Update last login
        Database::execute(
            "UPDATE users SET last_login = NOW(), login_ip = ? WHERE id = ?",
            [$_SERVER['REMOTE_ADDR'] ?? null, $user['id']]
        );
        
        // Log activity
        self::logActivity('login', "User {$user['username']} logged in");
        
        // Parse permissions
        $permissions = json_decode($user['permissions'] ?? '{}', true);
        
        // Set session
        $userData = [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'full_name' => $user['first_name'] . ' ' . $user['last_name'],
            'avatar' => $user['avatar'],
            'role_id' => $user['role_id'],
            'role_name' => $user['role_name'],
            'role_slug' => $user['role_slug'],
            'permissions' => $permissions
        ];
        
        Session::setUser($userData);
        Session::regenerate();
        
        return ['success' => true, 'user' => $userData];
    }
    
    /**
     * Logout user
     */
    public static function logout(): void {
        if (Session::isLoggedIn()) {
            $user = Session::getUser();
            self::logActivity('logout', "User {$user['username']} logged out");
        }
        Session::destroy();
    }
    
    /**
     * Check if user is authenticated
     */
    public static function check(): bool {
        if (!Session::isLoggedIn()) {
            return false;
        }
        
        // Check session timeout from settings
        $timeout = (int) self::getSetting('session_timeout', 30);
        if (Session::isExpired($timeout)) {
            self::logout();
            return false;
        }
        
        Session::updateActivity();
        return true;
    }
    
    /**
     * Get current user
     */
    public static function user(): ?array {
        return Session::getUser();
    }
    
    /**
     * Get current user ID
     */
    public static function id(): ?int {
        $user = Session::getUser();
        return $user['id'] ?? null;
    }
    
    /**
     * Check if user has a specific role
     */
    public static function hasRole(string $roleSlug): bool {
        $user = Session::getUser();
        return $user && $user['role_slug'] === $roleSlug;
    }
    
    /**
     * Check if user is super admin
     */
    public static function isSuperAdmin(): bool {
        return self::hasRole('super_admin');
    }
    
    /**
     * Check if user is admin or super admin
     */
    public static function isAdmin(): bool {
        return self::hasRole('super_admin') || self::hasRole('admin');
    }
    
    /**
     * Check if user has permission
     */
    public static function can(string $module, string $action = 'view'): bool {
        // Super admin has all permissions
        if (self::isSuperAdmin()) {
            return true;
        }
        
        $user = Session::getUser();
        if (!$user || !isset($user['permissions'])) {
            return false;
        }
        
        $permissions = $user['permissions'];
        
        // Check if has all permissions
        if (isset($permissions['all']) && $permissions['all'] === true) {
            return true;
        }
        
        // Check module permissions
        if (isset($permissions[$module]) && is_array($permissions[$module])) {
            return in_array($action, $permissions[$module]) || in_array('all', $permissions[$module]);
        }
        
        return false;
    }
    
    /**
     * Require authentication - redirect if not logged in
     */
    public static function requireAuth(): void {
        if (!self::check()) {
            Session::setFlash('warning', 'Please login to continue.');
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
    
    /**
     * Require permission - redirect if not authorized
     */
    public static function requirePermission(string $module, string $action = 'view'): void {
        self::requireAuth();
        
        if (!self::can($module, $action)) {
            Session::setFlash('danger', 'You do not have permission to access this resource.');
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }
    
    /**
     * Require any of the given roles
     */
    public static function requireRole(array $roles): void {
        self::requireAuth();
        
        $user = Session::getUser();
        if (!in_array($user['role_slug'] ?? '', $roles)) {
            Session::setFlash('danger', 'Access denied. Required role not found.');
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }
    
    /**
     * Hash password
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }
    
    /**
     * Generate secure token
     */
    public static function generateToken(int $length = 32): string {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Log activity
     */
    public static function logActivity(string $action, string $description = ''): void {
        $user = Session::getUser();
        $userId = $user['id'] ?? null;
        $userType = $user ? 'user' : 'system';
        
        try {
            Database::execute(
                "INSERT INTO activity_logs (user_id, user_type, action, description, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $userType,
                    $action,
                    $description,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]
            );
        } catch (\Exception $e) {
            // Silently fail - don't break the app for logging
        }
    }
    
    /**
     * Get system setting
     */
    public static function getSetting(string $key, $default = null) {
        try {
            $setting = Database::fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?",
                [$key]
            );
            return $setting['setting_value'] ?? $default;
        } catch (\Exception $e) {
            return $default;
        }
    }
    
    /**
     * Get all system settings
     */
    public static function getSettings(): array {
        try {
            $settings = Database::fetchAll("SELECT setting_key, setting_value FROM system_settings");
            $result = [];
            foreach ($settings as $s) {
                $result[$s['setting_key']] = $s['setting_value'];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Get navigation menu based on user role
     */
    public static function getNavigation(): array {
        $nav = [
            [
                'title' => 'Dashboard',
                'icon' => 'home',
                'url' => '/modules/dashboard/index.php',
                'module' => 'dashboard',
                'children' => []
            ],
            [
                'title' => 'Members',
                'icon' => 'users',
                'url' => '#',
                'module' => 'members',
                'children' => [
                    ['title' => 'All Members', 'url' => '/modules/members/index.php', 'action' => 'view'],
                    ['title' => 'Add Member', 'url' => '/modules/members/create.php', 'action' => 'create'],
                    ['title' => 'Subscriptions', 'url' => '/modules/members/subscriptions.php', 'action' => 'view'],
                    ['title' => 'Weight Tracking', 'url' => '/modules/members/weight-tracking.php', 'action' => 'view'],
                ]
            ],
            [
                'title' => 'Attendance',
                'icon' => 'clipboard-check',
                'url' => '#',
                'module' => 'attendance',
                'children' => [
                    ['title' => 'Today\'s Attendance', 'url' => '/modules/attendance/index.php', 'action' => 'view'],
                    ['title' => 'Attendance Report', 'url' => '/modules/attendance/reports.php', 'action' => 'view'],
                    ['title' => 'Devices', 'url' => '/modules/attendance/devices.php', 'action' => 'manage'],
                ]
            ],
            [
                'title' => 'Point of Sale',
                'icon' => 'shopping-cart',
                'url' => '#',
                'module' => 'pos',
                'children' => [
                    ['title' => 'New Sale', 'url' => '/modules/pos/index.php', 'action' => 'create'],
                    ['title' => 'Sales History', 'url' => '/modules/pos/history.php', 'action' => 'view'],
                    ['title' => 'Products', 'url' => '/modules/pos/products.php', 'action' => 'view'],
                    ['title' => 'Invoices', 'url' => '/modules/pos/invoices.php', 'action' => 'view'],
                ]
            ],
            [
                'title' => 'Communication',
                'icon' => 'message-circle',
                'url' => '#',
                'module' => 'communication',
                'children' => [
                    ['title' => 'Send Message', 'url' => '/modules/communication/send.php', 'action' => 'send'],
                    ['title' => 'Templates', 'url' => '/modules/communication/templates.php', 'action' => 'view'],
                    ['title' => 'Message Logs', 'url' => '/modules/communication/logs.php', 'action' => 'view'],
                    ['title' => 'Chat', 'url' => '/modules/communication/chat.php', 'action' => 'view'],
                ]
            ],
            [
                'title' => 'Reports',
                'icon' => 'bar-chart-2',
                'url' => '#',
                'module' => 'reports',
                'children' => [
                    ['title' => 'Member Reports', 'url' => '/modules/reports/members.php', 'action' => 'view'],
                    ['title' => 'Sales Reports', 'url' => '/modules/reports/sales.php', 'action' => 'view'],
                    ['title' => 'Attendance Reports', 'url' => '/modules/reports/attendance.php', 'action' => 'view'],
                    ['title' => 'Financial Reports', 'url' => '/modules/reports/financial.php', 'action' => 'view'],
                ]
            ]
        ];
        
        // Admin-only menus
        if (self::isAdmin() || self::can('settings', 'view')) {
            $nav[] = [
                'title' => 'Settings',
                'icon' => 'settings',
                'url' => '#',
                'module' => 'settings',
                'children' => [
                    ['title' => 'General Settings', 'url' => '/modules/settings/general.php', 'action' => 'view'],
                    ['title' => 'User Management', 'url' => '/modules/settings/users.php', 'action' => 'view'],
                    ['title' => 'System Configuration', 'url' => '/modules/settings/system.php', 'action' => 'view'],
                    ['title' => 'Backup & Restore', 'url' => '/modules/settings/backup.php', 'action' => 'view'],
                ]
            ];
        }
        
        return $nav;
    }
    
    public static function userId()
    {
        return $_SESSION['user_id'] ?? null;
    }
}
