<?php
/**
 * Hikvision User Management API
 *
 * Actions (same shape as api/zkteco-user.php):
 * - add:              Add user with access enabled
 * - remove:           Delete user from device
 * - disable:          Flip Valid.enable to false (expired, cannot access)
 * - enable:           Flip Valid.enable to true (renewed, can access)
 * - sync_membership:  Batch sync all members' expiry states
 */

require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\Hikvision;

header('Content-Type: application/json');

try {
    $action     = $_POST['action'] ?? '';
    $memberId   = intval($_POST['member_id'] ?? 0);
    $employeeNo = trim((string)($_POST['biometric_id'] ?? ''));

    if ($employeeNo === '' && $action !== 'sync_membership') {
        echo json_encode(['success' => false, 'message' => 'Biometric/employee ID is required']);
        exit;
    }

    $device = Database::fetchOne(
        "SELECT * FROM zkteco_devices WHERE device_type = 'hikvision' AND (is_default = 1 OR status = 'online') LIMIT 1"
    );

    if (!$device) {
        echo json_encode(['success' => false, 'message' => 'No Hikvision device configured']);
        exit;
    }

    if (empty($device['device_username']) || empty($device['device_password'])) {
        echo json_encode(['success' => false, 'message' => 'Hikvision device is missing username/password']);
        exit;
    }

    $hik = new Hikvision(
        $device['device_ip'],
        $device['device_username'],
        $device['device_password'],
        (int)($device['port'] ?: 80)
    );

    $result = ['success' => false, 'message' => 'Unknown action'];

    switch ($action) {

        case 'add':
            $member = $memberId ? Database::fetchOne(
                "SELECT first_name, last_name FROM members WHERE id = ?",
                [$memberId]
            ) : null;

            $name = $member
                ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))
                : '';
            if ($name === '') {
                $name = 'Member ' . $employeeNo;
            }

            $r = $hik->addUser($employeeNo, $name);

            if ($r['success'] && $memberId) {
                Database::execute("UPDATE members SET biometric_id = ? WHERE id = ?", [$employeeNo, $memberId]);
            }

            $result = [
                'success' => $r['success'],
                'message' => $r['success']
                    ? "User '{$name}' added to Hikvision device"
                    : ('Failed to add user: ' . ($r['message'] ?? ('HTTP ' . $r['http_code']))),
            ];
            break;

        case 'remove':
            $r = $hik->removeUser($employeeNo);

            if ($r['success'] && $memberId) {
                Database::execute("UPDATE members SET biometric_id = NULL WHERE id = ?", [$memberId]);
            }

            $result = [
                'success' => $r['success'],
                'message' => $r['success']
                    ? 'User removed from device'
                    : ('Failed to remove user: ' . ($r['message'] ?? ('HTTP ' . $r['http_code']))),
            ];
            break;

        case 'disable':
            $member = $memberId ? Database::fetchOne(
                "SELECT first_name, last_name FROM members WHERE id = ?",
                [$memberId]
            ) : null;
            $name = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : '';

            $r = $hik->disableUser($employeeNo, $name);

            $result = [
                'success' => $r['success'],
                'message' => $r['success']
                    ? 'User disabled (membership expired, access blocked)'
                    : ('Failed to disable user: ' . ($r['message'] ?? ('HTTP ' . $r['http_code']))),
            ];
            break;

        case 'enable':
            $member = $memberId ? Database::fetchOne(
                "SELECT first_name, last_name FROM members WHERE id = ?",
                [$memberId]
            ) : null;
            $name = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : '';

            $r = $hik->enableUser($employeeNo, $name);

            $result = [
                'success' => $r['success'],
                'message' => $r['success']
                    ? 'User enabled (membership renewed, access restored)'
                    : ('Failed to enable user: ' . ($r['message'] ?? ('HTTP ' . $r['http_code']))),
            ];
            break;

        case 'sync_membership':
            $members = Database::fetchAll(
                "SELECT id, biometric_id, first_name, last_name, status, expiry_date
                 FROM members WHERE biometric_id IS NOT NULL AND biometric_id != ''"
            );

            if (empty($members)) {
                $result = [
                    'success' => true,
                    'message' => 'No members to sync',
                    'active' => 0,
                    'expired' => 0,
                    'failed' => 0,
                ];
                break;
            }

            $sync = $hik->syncMembershipState($members);

            $result = [
                'success' => true,
                'message' => "Synced {$sync['active']} active, {$sync['expired']} expired members",
                'active'  => $sync['active'],
                'expired' => $sync['expired'],
                'failed'  => $sync['failed'],
            ];
            break;

        default:
            $result = ['success' => false, 'message' => 'Invalid action'];
    }

    echo json_encode($result);

} catch (\Exception $e) {
    error_log('Hikvision API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
