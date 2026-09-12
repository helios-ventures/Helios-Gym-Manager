<?php
/**
 * ZKTeco User Management API - VERIFIED PRODUCTION VERSION
 * 
 * Actions:
 * - add: Add user with DEFAULT timezone (active, can access)
 * - remove: Delete user from device
 * - disable: Move user to NO_ACCESS timezone (expired, cannot access)
 * - enable: Move user back to DEFAULT timezone (renewed, can access)
 * - sync_membership: Batch sync all members' expiry states
 */

require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\ZKTeco;

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $memberId = intval($_POST['member_id'] ?? 0);
    $biometricId = intval($_POST['biometric_id'] ?? 0);

    error_log("ZKTeco API called: action=$action, memberId=$memberId, biometricId=$biometricId");

    if (empty($biometricId) && !in_array($action, ['sync_membership'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Biometric ID is required'
        ]);
        exit;
    }

    // Get device
    $device = Database::fetchOne(
        "SELECT * FROM zkteco_devices WHERE is_default = 1 LIMIT 1"
    );
    
    if (!$device) {
        echo json_encode([
            'success' => false,
            'message' => 'No default device configured'
        ]);
        exit;
    }

    error_log("ZKTeco API: Using device {$device['device_name']} at {$device['device_ip']}");

    // Connect to device
    $zk = new ZKTeco($device['device_ip'], (int)$device['port']);
    
    if (!$zk->connect()) {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to connect to device'
        ]);
        exit;
    }

    $result = ['success' => false, 'message' => 'Unknown action'];

    switch ($action) {
        
        case 'add':
            // Get member info
            $member = Database::fetchOne(
                "SELECT first_name, last_name FROM members WHERE id = ?",
                [$memberId]
            );

            $fullName = '';
            if ($member) {
                $fullName = trim(
                    ($member['first_name'] ?? '') . ' ' . 
                    ($member['last_name'] ?? '')
                );
            }

            if (empty($fullName)) {
                $fullName = "Member $biometricId";
            }

            error_log("ZKTeco API: Adding user - biometric=$biometricId, name='$fullName'");

            if ($zk->addUser($biometricId, $fullName, '', 0, (string)$biometricId)) {
                // Update database
                if ($memberId > 0) {
                    Database::execute(
                        "UPDATE members SET biometric_id = ? WHERE id = ?",
                        [$biometricId, $memberId]
                    );
                }
                
                $result = [
                    'success' => true,
                    'message' => "User added successfully (active membership)"
                ];
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Failed to add user to device'
                ];
            }
            break;

        case 'remove':
            error_log("ZKTeco API: Removing user - biometric=$biometricId");
            
            if ($zk->removeUser($biometricId)) {
                if ($memberId > 0) {
                    Database::execute(
                        "UPDATE members SET biometric_id = NULL WHERE id = ?",
                        [$memberId]
                    );
                }
                
                $result = [
                    'success' => true,
                    'message' => 'User removed from device'
                ];
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Failed to remove user'
                ];
            }
            break;

        case 'disable':
            // Expire user - move to NO_ACCESS timezone
            error_log("ZKTeco API: Disabling user - biometric=$biometricId");
            
            if ($zk->disableUser($biometricId)) {
                $result = [
                    'success' => true,
                    'message' => 'User disabled (membership expired, cannot access)'
                ];
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Failed to disable user'
                ];
            }
            break;

        case 'enable':
            // Re-activate user - move back to DEFAULT timezone
            error_log("ZKTeco API: Enabling user - biometric=$biometricId");
            
            if ($zk->enableUser($biometricId)) {
                $result = [
                    'success' => true,
                    'message' => 'User enabled (membership renewed, can access)'
                ];
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Failed to enable user'
                ];
            }
            break;

        case 'sync_membership':
            // Batch sync all members' expiry states
            error_log("ZKTeco API: Syncing membership states for all members");
            
            $members = Database::fetchAll(
                "SELECT id, biometric_id, first_name, last_name, status, expiry_date 
                 FROM members WHERE biometric_id IS NOT NULL AND biometric_id > 0"
            );
            
            if (empty($members)) {
                $result = [
                    'success' => true,
                    'message' => 'No members to sync',
                    'active' => 0,
                    'expired' => 0,
                    'failed' => 0
                ];
            } else {
                $syncResult = $zk->syncMembershipState($members);
                $result = [
                    'success' => true,
                    'message' => "Synced {$syncResult['active']} active, {$syncResult['expired']} expired members",
                    'active' => $syncResult['active'],
                    'expired' => $syncResult['expired'],
                    'failed' => $syncResult['failed']
                ];
            }
            break;
            
        default:
            error_log("ZKTeco API: Invalid action '$action'");
            $result = [
                'success' => false,
                'message' => 'Invalid action'
            ];
    }

    $zk->disconnect();
    error_log("ZKTeco API: Result - " . json_encode($result));
    echo json_encode($result);

} catch (\Exception $e) {
    error_log('ZKTeco API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
?>