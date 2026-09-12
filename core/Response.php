<?php
/**
 * HTTP Response Helper Class
 */

namespace Gym\Core;

class Response {
    
    /**
     * Send JSON response
     */
    public static function json(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Send success response
     */
    public static function success(string $message = '', $data = null): void {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        self::json($response);
    }
    
    /**
     * Send error response
     */
    public static function error(string $message = 'An error occurred', int $code = 400, $errors = null): void {
        $response = ['success' => false, 'message' => $message];
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        self::json($response, $code);
    }
    
    /**
     * Send not found response
     */
    public static function notFound(string $message = 'Resource not found'): void {
        self::error($message, 404);
    }
    
    /**
     * Send unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): void {
        self::error($message, 401);
    }
    
    /**
     * Send forbidden response
     */
    public static function forbidden(string $message = 'Access denied'): void {
        self::error($message, 403);
    }
    
    /**
     * Redirect with flash message
     */
    public static function redirect(string $url, string $type = '', string $message = ''): void {
        if (!empty($message)) {
            Session::setFlash($type, $message);
        }
        header('Location: ' . BASE_URL . $url);
        exit;
    }
    
    /**
     * Send file download
     */
    public static function download(string $filepath, string $filename = ''): void {
        if (!file_exists($filepath)) {
            self::error('File not found', 404);
        }
        
        if (empty($filename)) {
            $filename = basename($filepath);
        }
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache');
        
        readfile($filepath);
        exit;
    }
    
    /**
     * Send CSV download
     */
    public static function csv(array $data, string $filename = 'export.csv'): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel
        fprintf($output, "\xEF\xBB\xBF");
        
        if (!empty($data)) {
            // Headers
            fputcsv($output, array_keys(reset($data)));
            
            // Rows
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Send HTML table for print
     */
    public static function printTable(array $data, string $title = 'Report'): void {
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo htmlspecialchars($title); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { text-align: center; color: #333; }
                .meta { text-align: center; color: #666; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f4f4f4; font-weight: bold; }
                tr:nth-child(even) { background-color: #f9f9f9; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #999; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="text-align: center; margin-bottom: 20px;">
                <button onclick="window.print()">Print</button>
                <button onclick="window.close()">Close</button>
            </div>
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <div class="meta">Generated on: <?php echo date('F d, Y H:i:s'); ?></div>
            <table>
                <thead>
                    <tr>
                        <?php if (!empty($data)): ?>
                            <?php foreach (array_keys(reset($data)) as $header): ?>
                                <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $header))); ?></th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?php echo htmlspecialchars((string)$cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="footer">
                <?php echo htmlspecialchars(Auth::getSetting('gym_name', 'Gym Management System')); ?> 
                | Generated by Gym Management System v<?php echo APP_VERSION; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
