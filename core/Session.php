<?php
/**
 * Session Management Class
 */

namespace Gym\Core;

class Session {
    
    /**
     * Initialize session
     */
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Set a session value
     */
    public static function set(string $key, $value): void {
        self::init();
        $_SESSION[$key] = $value;
    }
    
    /**
     * Get a session value
     */
    public static function get(string $key, $default = null) {
        self::init();
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Check if session key exists
     */
    public static function has(string $key): bool {
        self::init();
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove a session value
     */
    public static function remove(string $key): void {
        self::init();
        unset($_SESSION[$key]);
    }
    
    /**
     * Flash message - available only for next request
     */
    public static function flash(string $key, $value = null) {
        self::init();
        if ($value !== null) {
            $_SESSION['flash'][$key] = $value;
            return null;
        }
        $flash = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $flash;
    }
    
    /**
     * Check if flash exists
     */
    public static function hasFlash(string $key): bool {
        self::init();
        return isset($_SESSION['flash'][$key]);
    }
    
    /**
     * Set flash message (alias)
     */
    public static function setFlash(string $type, string $message): void {
        self::init();
        $_SESSION['flash']['messages'][] = [
            'type' => $type,
            'message' => $message
        ];
    }
    
    /**
     * Get and clear all flash messages
     */
    public static function getFlashes(): array {
        self::init();
        $messages = $_SESSION['flash']['messages'] ?? [];
        unset($_SESSION['flash']['messages']);
        return $messages;
    }
    
    /**
     * Destroy session
     */
    public static function destroy(): void {
        self::init();
        session_destroy();
        $_SESSION = [];
    }
    
    /**
     * Regenerate session ID
     */
    public static function regenerate(): void {
        self::init();
        session_regenerate_id(true);
    }
    
    /**
     * Set user data in session
     */
    public static function setUser(array $user): void {
        self::init();
        $_SESSION['user'] = $user;
        $_SESSION['login_time'] = time();
    }
    
    /**
     * Get current user data
     */
    public static function getUser(): ?array {
        self::init();
        return $_SESSION['user'] ?? null;
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn(): bool {
        self::init();
        return isset($_SESSION['user']) && !empty($_SESSION['user']);
    }
    
    /**
     * Check if session has expired
     */
    public static function isExpired(int $timeout = 30): bool {
        self::init();
        if (!isset($_SESSION['login_time'])) {
            return true;
        }
        return (time() - $_SESSION['login_time']) > ($timeout * 60);
    }
    
    /**
     * Update last activity time
     */
    public static function updateActivity(): void {
        self::init();
        $_SESSION['login_time'] = time();
    }
    
    /**
     * Get CSRF token
     */
    public static function getCsrfToken(): string {
        self::init();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token): bool {
        self::init();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
