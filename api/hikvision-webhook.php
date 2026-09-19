<?php
/**
 * Hikvision Event Webhook
 *
 * The device pushes access-control events here in real time once registered
 * via the "Enable Real-Time Events" button in Attendance > Devices (calls
 * Hikvision::registerEventListener()).
 *
 * No staff session exists here - the device authenticates with the
 * per-device webhook token in the URL instead of a login.
 */

require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\AttendanceSync;

$token = $_GET['token'] ?? '';

$device = $token
    ? Database::fetchOne("SELECT * FROM zkteco_devices WHERE webhook_secret = ?", [$token])
    : null;

if (!$device) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');

// Hikvision firmwares vary: some POST plain JSON, others multipart/form-data
// with an "event_log" part. Handle both; log unrecognized shapes so parsing
// can be corrected rather than silently dropping events.
$data = null;

if (!empty($_POST) && isset($_POST['event_log'])) {
    $data = json_decode($_POST['event_log'], true);
} elseif ($raw) {
    $data = json_decode($raw, true);
}

if (!is_array($data)) {
    error_log('Hikvision webhook: unrecognized payload - ' . substr($raw, 0, 500));
    http_response_code(200); // acknowledge anyway so the device doesn't retry forever
    exit;
}

$event = $data['AccessControllerEvent'] ?? $data;
$employeeNo = $event['employeeNoString'] ?? ($event['employeeNo'] ?? null);
$time = $data['dateTime'] ?? ($event['time'] ?? null);
$verifyMode = $event['currentVerifyMode'] ?? '';

if ($employeeNo && $time) {
    AttendanceSync::recordEvent((int)$employeeNo, $time, $verifyMode, $device['id']);
}

http_response_code(200);
echo 'OK';
