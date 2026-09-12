<?php
/**
 * ZKTeco User Management API
 * Handles add/remove/enable/disable via AJAX from devices.php
 */

require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\ZKTeco;

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $memberId = intval($_POST['member_id'] ?? 0);
    $biometricId = intval($_POST['biometric_id'] ?? 0);

    if (empty($action)) {
        echo json_encode(['success' => false, 'message' => 'No action specified']);
        exit;
    }

    // Find the default or any online device
    $device = Database::fetchOne(
        "SELECT * FROM zkteco_devices WHERE is_default = 1 OR status = 'online' LIMIT 1"
    );
    
    if (!$device) {
        echo json_encode(['success' => false, 'message' => 'No device configured or online']);
        exit;
    }

    $zk = new ZKTeco($device['device_ip'], (int) $device['port']);
    
    if (!$zk->connect()) {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to connect to device at ' . $device['device_ip'] . ':' . $device['port']
        ]);
        exit;
    }

    $result = ['success' => false, 'message' => 'Unknown action'];

    switch ($action) {
        case 'add':
            if (empty($biometricId)) {
                $result = ['success' => false, 'message' => 'Biometric ID is required'];
                break;
            }
            
            // Get member name from DB
            $member = Database::fetchOne(
                "SELECT first_name, last_name FROM members WHERE id = ?", 
                [$memberId]
            );
            
            $name = 'User ' . $biometricId;
            if ($member) {
                $fullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                if (!empty($fullName)) {
                    $name = $fullName;
                }
            }
            
            if ($zk->addUser($biometricId, $name)) {
                $result = ['success' => true, 'message' => 'User "' . $name . '" added to device'];
            } else {
                $result = ['success' => false, 'message' => 'Device rejected the add command'];
            }
            break;

        case 'remove':
            if (empty($biometricId)) {
                $result = ['success' => false, 'message' => 'Biometric ID is required'];
                break;
            }
            if ($zk->removeUser($biometricId)) {
                $result = ['success' => true, 'message' => 'User removed from device'];
            } else {
                $result = ['success' => false, 'message' => 'Failed to remove user'];
            }
            break;

        case 'enable':
            if ($zk->enableUser($biometricId)) {
                $result = ['success' => true, 'message' => 'User enabled on device'];
            } else {
                $result = ['success' => false, 'message' => 'Failed to enable user'];
            }
            break;

        case 'disable':
            if ($zk->disableUser($biometricId)) {
                $result = ['success' => true, 'message' => 'User disabled on device'];
            } else {
                $result = ['success' => false, 'message' => 'Failed to disable user'];
            }
            break;
            
        default:
            $result = ['success' => false, 'message' => 'Invalid action: ' . $action];
    }

    $zk->disconnect();
    echo json_encode($result);

} catch (\Exception $e) {
    error_log('ZKTeco API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}