<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('members', 'delete');
$memberId = intval($_GET['id'] ?? 0);
if ($memberId) {
    $member = Database::fetchOne("SELECT member_code FROM members WHERE id = ?", [$memberId]);
    if ($member) {
        // Remove from ZKTeco device first if applicable
        if ($member['biometric_id']) {
            $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE is_default = 1 OR status = 'online' LIMIT 1");
            if ($device) {
                $zk = new \Gym\Core\ZKTeco($device['device_ip'], $device['port']);
                if ($zk->connect()) {
                    $zk->deleteUser($member['biometric_id']);
                    $zk->disconnect();
                }
            }
        }
        Database::execute("DELETE FROM members WHERE id = ?", [$memberId]);
        \Gym\Core\Auth::logActivity('member_delete', "Deleted member {$member['member_code']}");
        Helper::redirect('/modules/members/index.php', 'success', 'Member deleted successfully');
    }
}
Helper::redirect('/modules/members/index.php', 'warning', 'Member not found');
