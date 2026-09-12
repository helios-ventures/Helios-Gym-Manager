<?php
/**
 * Helper Functions Class
 */

namespace Gym\Core;

class Helper {
    
    /**
     * Format money amount
     */
    public static function money(float $amount, string $symbol = null): string {
        if ($symbol === null) {
            $symbol = Auth::getSetting('currency_symbol', 'Ksh');
        }
        return $symbol . ' ' . number_format($amount, 2);
    }
    
    /**
     * Format date
     */
    public static function date(string $date, string $format = 'M d, Y'): string {
        if (empty($date)) return 'N/A';
        return date($format, strtotime($date));
    }
    
    /**
     * Format datetime
     */
    public static function datetime(string $datetime, string $format = 'M d, Y h:i A'): string {
        if (empty($datetime)) return 'N/A';
        return date($format, strtotime($datetime));
    }
    
    /**
     * Format time
     */
    public static function time(string $time): string {
        if (empty($time)) return 'N/A';
        return date('h:i A', strtotime($time));
    }
    
    /**
     * Relative time (e.g. "2 hours ago")
     */
    public static function relativeTime(string $datetime): string {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        return self::date($datetime);
    }
    
    /**
    /**
     * Generate unique code
     */
    public static function generateCode(string $prefix = '', int $length = 6): string {
        return $prefix . strtoupper(substr(uniqid(), -$length));
    }
    
    /**
     * Generate member code
     */
    public static function generateMemberCode(): string {
        $prefix = 'GYM';
        $year = date('y');
        $random = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
        return $prefix . $year . $random;
    }
    
    /**
     * Generate invoice number
     */
    public static function generateInvoiceNumber(): string {
        $prefix = 'INV';
        $date = date('Ymd');
        $random = mt_rand(1000, 9999);
        return $prefix . '-' . $date . '-' . $random;
    }
    
    /**
     * Sanitize input
     */
    public static function sanitize(string $input): string {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Clean input array
     */
    public static function cleanArray(array $data): array {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $clean[$key] = self::sanitize($value);
            } elseif (is_array($value)) {
                $clean[$key] = self::cleanArray($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
    
    /**
     * Redirect with message
     */
    public static function redirect(string $url, string $type = '', string $message = ''): void {
        if (!empty($message)) {
            Session::setFlash($type, $message);
        }
        header('Location: ' . BASE_URL . $url);
        exit;
    }
    
    /**
     * Get current URL
     */
    public static function currentUrl(): string {
        return $_SERVER['REQUEST_URI'];
    }
    
    /**
     * Check if current page
     */
    public static function isActive(string $path): string {
        return strpos($_SERVER['REQUEST_URI'], $path) !== false ? 'active' : '';
    }
    
    /**
     * Truncate text
     */
    public static function truncate(string $text, int $length = 100): string {
        if (strlen($text) <= $length) return $text;
        return substr($text, 0, $length) . '...';
    }
    
    /**
     * Format phone number
     */
    public static function formatPhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 9) {
            $phone = '0' . $phone;
        }
        if (strlen($phone) === 10 && $phone[0] === '0') {
            $phone = '+254' . substr($phone, 1);
        }
        return $phone;
    }
    
    /**
     * Calculate BMI
     */
    public static function calculateBmi(float $weightKg, float $heightCm): float {
        $heightM = $heightCm / 100;
        if ($heightM <= 0) return 0;
        return round($weightKg / ($heightM * $heightM), 1);
    }
    
    /**
     * Get BMI category
     */
    public static function bmiCategory(float $bmi): string {
        if ($bmi < 18.5) return 'Underweight';
        if ($bmi < 25) return 'Normal weight';
        if ($bmi < 30) return 'Overweight';
        return 'Obese';
    }
    
    /**
     * Get BMI color class
     */
    public static function bmiColor(float $bmi): string {
        if ($bmi < 18.5) return 'warning';
        if ($bmi < 25) return 'success';
        if ($bmi < 30) return 'warning';
        return 'danger';
    }
    
    /**
     * Days until expiry
     */
    public static function daysUntil(string $date): int {
        $now = strtotime(date('Y-m-d'));
        $target = strtotime($date);
        $diff = $target - $now;
        return (int) floor($diff / 86400);
    }
    
    /**
     * Get status badge class
     */
    public static function statusBadge(string $status): string {
        $map = [
            'active' => 'success',
            'inactive' => 'secondary',
            'expired' => 'danger',
            'suspended' => 'warning',
            'pending' => 'info',
            'paid' => 'success',
            'refunded' => 'warning',
            'cancelled' => 'danger',
            'present' => 'success',
            'late' => 'warning',
            'absent' => 'danger',
            'online' => 'success',
            'offline' => 'secondary',
            'error' => 'danger',
            'sent' => 'success',
            'delivered' => 'success',
            'failed' => 'danger'
        ];
        return $map[$status] ?? 'secondary';
    }
    
    /**
     * Upload file
     */
    public static function uploadFile(array $file, string $directory = 'uploads', array $allowed = ['jpg','jpeg','png']): array {
        $result = ['success' => false, 'path' => '', 'message' => ''];
        
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $result['message'] = 'No file uploaded';
            return $result;
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $result['message'] = 'Invalid file type. Allowed: ' . implode(', ', $allowed);
            return $result;
        }
        
        $uploadDir = BASE_PATH . '/assets/' . $directory;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filepath = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $result['success'] = true;
            $result['path'] = 'assets/' . $directory . '/' . $filename;
        } else {
            $result['message'] = 'Failed to upload file';
        }
        
        return $result;
    }
    
    /**
     * Send HTTP response
     */
    public static function jsonResponse(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Pagination helper
     */
    public static function paginate(int $total, int $page = 1, int $perPage = 20): array {
        $totalPages = (int) ceil($total / $perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages
        ];
    }
    
    /**
     * Get page title from navigation
     */
    public static function getPageTitle(): string {
        $nav = Auth::getNavigation();
        $currentUrl = $_SERVER['REQUEST_URI'];
        
        foreach ($nav as $item) {
            if ($item['url'] !== '#' && strpos($currentUrl, $item['url']) !== false) {
                return $item['title'];
            }
            foreach ($item['children'] ?? [] as $child) {
                if (strpos($currentUrl, $child['url']) !== false) {
                    return $child['title'];
                }
            }
        }
        return 'Dashboard';
    }
}
