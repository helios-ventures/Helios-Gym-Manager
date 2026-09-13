<?php
/**
 * Toggle a member's biometric door access on/off.
 * Called via fetch() from the "Disable Access" / "Enable Access" button on view.php.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\MemberAccess;

Auth::requirePermission('members', 'edit');

header('Content-Type: application/json');

$memberId = intval($_POST['member_id'] ?? 0);
$action = $_POST['action'] ?? '';

$member = $memberId ? Database::fetchOne("SELECT * FROM members WHERE id = ?", [$memberId]) : null;

if (!$member) {
    echo json_encode(['success' => false, 'message' => 'Member not found']);
    exit;
}

if (empty($member['biometric_id'])) {
    echo json_encode(['success' => false, 'message' => 'This member has no biometric/employee ID assigned yet']);
    exit;
}

if ($action === 'disable') {
    $result = MemberAccess::disable($member, 'manual');
    $label = 'disabled';
} elseif ($action === 'enable') {
    $result = MemberAccess::enable($member);
    $label = 'enabled';
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

if ($result['success']) {
    Auth::logActivity(
        'member_access_' . $action,
        "Access {$label} for {$member['first_name']} {$member['last_name']} ({$member['member_code']})"
    );

    echo json_encode(['success' => true, 'message' => "Access {$label} and synced to the device."]);
} else {
    // The intended state IS saved locally (see MemberAccess), but the device
    // call failed, so the door won't reflect it yet.
    echo json_encode([
        'success' => true,
        'message' => "Access marked {$label} locally, but the device update failed ({$result['message']}). "
                   . "It won't take effect at the door until this is retried - check the device connection.",
    ]);
}
