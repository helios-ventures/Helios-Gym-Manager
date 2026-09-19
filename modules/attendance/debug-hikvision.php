<?php
/**
 * Hikvision Capability Debug
 *
 * Mirrors the existing modules/attendance/debug-zklib.php pattern, but for the
 * Hikvision device. Dumps the device's own ISAPI capability documents so we
 * can confirm the exact endpoint names/paths this specific unit/firmware
 * supports - most importantly for face/fingerprint remote enrollment, which
 * Hikvision does not standardize consistently across firmware versions.
 *
 * Run this once after connecting a device, then search the output for
 * anything mentioning "face", "fingerPrint", "capture", or "collect".
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\Hikvision;

// Get default Hikvision device
$device = Database::fetchOne(
    "SELECT * FROM zkteco_devices WHERE device_type = 'hikvision' AND is_default = 1 LIMIT 1"
);

if (!$device) {
    die('No default Hikvision device configured. Add one first via Attendance > Devices.');
}

if (empty($device['device_username']) || empty($device['device_password'])) {
    die('Device is missing a username/password. Edit it via Attendance > Devices first.');
}

echo "<h1>Hikvision Capability Debug</h1>";
echo "<p>Device: " . htmlspecialchars($device['device_name']) . " (" . htmlspecialchars($device['device_ip']) . ":" . htmlspecialchars($device['port']) . ")</p>";

$hik = new Hikvision(
    $device['device_ip'],
    $device['device_username'],
    $device['device_password'],
    (int)($device['port'] ?: 80)
);

$test = $hik->testConnection();
echo "<p><strong>Connection:</strong> " . ($test['success'] ? '&#9989; OK' : '&#10060; ' . htmlspecialchars($test['message'])) . "</p>";

if (!$test['success']) {
    echo "<p>Fix the connection before checking capabilities - the calls below will just fail the same way.</p>";
    exit;
}

$caps = $hik->getCapabilities();

echo '<h2>System Capabilities</h2><pre style="background:#f4f4f4;padding:12px;overflow:auto;">'
    . htmlspecialchars(json_encode($caps['system'], JSON_PRETTY_PRINT))
    . '</pre>';

echo '<h2>Access Control Capabilities</h2><pre style="background:#f4f4f4;padding:12px;overflow:auto;">'
    . htmlspecialchars(json_encode($caps['access_control'], JSON_PRETTY_PRINT))
    . '</pre>';

echo '<p>Look through the Access Control capabilities above for anything mentioning '
    . '<code>face</code>, <code>fingerPrint</code>, <code>capture</code>, or <code>collect</code> - '
    . "that's the real endpoint name/path this firmware supports for enrollment. "
    . "Share this page's output (or the two JSON blocks) and the exact face/fingerprint "
    . "enrollment calls in core/Hikvision.php can be corrected to match.</p>";
