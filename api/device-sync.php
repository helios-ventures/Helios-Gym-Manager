<?php
/**
 * AJAX endpoint for device sync operations
 * Called from UI for real-time feedback
 */

// ---------- Production error settings ----------
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\DeviceSyncManager;
use Gym\Core\Helper;
use Gym\Core\Session;


Auth::requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? null;
$deviceId = intval($_POST['device_id'] ?? 0);

$db = new Database();
$device = $db->fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);

if (!$device) {
    echo json_encode(['success' => false, 'message' => 'Device not found']);
    exit;
}

try {
    $manager = new DeviceSyncManager($db, $device);
    
    switch ($action) {
        case 'sync_members':
            $result = $manager->smartSync(Auth::userId());
            break;
            
        case 'sync_attendance':
            $result = $manager->syncAttendance(Auth::userId());
            break;
            
        default:
            $result = ['success' => false, 'message' => 'Unknown action'];
    }
    
    echo json_encode($result);
    
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>