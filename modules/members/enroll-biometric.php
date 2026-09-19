<?php
/**
 * Prompt the Hikvision terminal to enter face/fingerprint capture mode for a
 * member, so they can walk up and self-enroll without staff visiting the
 * device directly.
 *
 * IMPORTANT: the underlying device call (Hikvision::promptFaceEnrollment() /
 * promptFingerprintEnrollment()) is marked experimental - see those methods'
 * doc comments. If this endpoint reports failure, run debug-hikvision.php
 * first before assuming the whole feature is broken.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Hikvision;

Auth::requirePermission('members', 'edit');

header('Content-Type: application/json');

$memberId = intval($_POST['member_id'] ?? 0);
$type = $_POST['type'] ?? '';

$member = $memberId ? Database::fetchOne("SELECT * FROM members WHERE id = ?", [$memberId]) : null;

if (!$member) {
    echo json_encode(['success' => false, 'message' => 'Member not found']);
    exit;
}

if (empty($member['biometric_id'])) {
    echo json_encode(['success' => false, 'message' => 'This member has no employee ID on the device yet. Save their profile again or contact support to provision one.']);
    exit;
}

if (!in_array($type, ['face', 'fingerprint'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid enrollment type']);
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
    echo json_encode(['success' => false, 'message' => 'Device is missing username/password']);
    exit;
}

$hik = new Hikvision(
    $device['device_ip'],
    $device['device_username'],
    $device['device_password'],
    (int)($device['port'] ?: 80)
);

$result = $type === 'face'
    ? $hik->promptFaceEnrollment($member['biometric_id'])
    : $hik->promptFingerprintEnrollment($member['biometric_id']);

if ($result['success']) {
    Auth::logActivity(
        'member_enroll_' . $type,
        "Requested {$type} enrollment for {$member['first_name']} {$member['last_name']} ({$member['member_code']})"
    );
}

echo json_encode(['success' => $result['success'], 'message' => $result['message']]);
