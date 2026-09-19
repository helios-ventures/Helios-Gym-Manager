<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Hikvision;
Auth::requirePermission('members', 'delete');
$memberId = intval($_GET['id'] ?? 0);
if ($memberId) {
    $member = Database::fetchOne("SELECT member_code, biometric_id FROM members WHERE id = ?", [$memberId]);
    if ($member) {
        // Remove from the Hikvision device first if applicable
        if ($member['biometric_id']) {
            $device = Database::fetchOne(
                "SELECT * FROM zkteco_devices WHERE device_type = 'hikvision' AND (is_default = 1 OR status = 'online') LIMIT 1"
            );
            if ($device && !empty($device['device_username']) && !empty($device['device_password'])) {
                $hik = new Hikvision($device['device_ip'], $device['device_username'], $device['device_password'], (int)($device['port'] ?: 80));
                $hik->removeUser($member['biometric_id']);
            }
        }
        Database::execute("DELETE FROM members WHERE id = ?", [$memberId]);
        \Gym\Core\Auth::logActivity('member_delete', "Deleted member {$member['member_code']}");
        Helper::redirect('/modules/members/index.php', 'success', 'Member deleted successfully');
    }
}
Helper::redirect('/modules/members/index.php', 'warning', 'Member not found');
