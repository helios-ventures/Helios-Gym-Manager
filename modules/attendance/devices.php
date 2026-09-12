<?php
/**
 * Hikvision Device Management - CRUD operations for access-control terminals
 * (DS-K1T343MFWX / DS-K1T671 series)
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;
use Gym\Core\Hikvision;

Auth::requirePermission('attendance', 'manage');

$pageTitle = 'Hikvision Device Management';
$pageDescription = 'Manage access-control terminals and users';

/**
 * Build a Hikvision client from a device row, or null (with a flash message)
 * if the row is missing credentials.
 */
function hikvisionFromDevice(array $device): ?Hikvision
{
    if (empty($device['device_username']) || empty($device['device_password'])) {
        Session::setFlash('danger', "Device '{$device['device_name']}' is missing a username/password - edit it first.");
        return null;
    }

    return new Hikvision(
        $device['device_ip'],
        $device['device_username'],
        $device['device_password'],
        (int)($device['port'] ?: 80)
    );
}

// Test connection
if (isset($_GET['test']) && $deviceId = intval($_GET['test'])) {
    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);

    if ($device) {
        $hik = hikvisionFromDevice($device);

        if ($hik) {
            $result = $hik->testConnection();

            if ($result['success']) {
                Database::execute("UPDATE zkteco_devices SET status = 'online', last_sync = NOW() WHERE id = ?", [$deviceId]);
                Session::setFlash('success', 'Connection successful! Device is online.');
            } else {
                Database::execute("UPDATE zkteco_devices SET status = 'offline' WHERE id = ?", [$deviceId]);
                Session::setFlash('danger', 'Connection failed: ' . $result['message']);
            }
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Sync device users from DB (push active members, disable expired/inactive/suspended ones)
if (isset($_GET['sync_users']) && $deviceId = intval($_GET['sync_users'])) {

    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);

    if ($device) {
        $hik = hikvisionFromDevice($device);

        if ($hik) {

            // Fetch ALL members that have a biometric ID assigned
            $members = Database::fetchAll("
                SELECT 
                    id,
                    biometric_id,
                    first_name,
                    last_name,
                    expiry_date,
                    status
                FROM members
                WHERE biometric_id IS NOT NULL
            ");

            $activeMembers = [];
            $disabledCount = 0;
            $disableFailures = 0;

            foreach ($members as $member) {

                $isExpired = !empty($member['expiry_date'])
                    && strtotime($member['expiry_date']) < strtotime(date('Y-m-d'));

                if ($isExpired || in_array($member['status'], ['expired', 'inactive', 'suspended'], true)) {
                    $name = trim($member['first_name'] . ' ' . $member['last_name']);
                    $result = $hik->disableUser($member['biometric_id'], $name);

                    $result['success'] ? $disabledCount++ : $disableFailures++;
                    continue;
                }

                $activeMembers[] = $member;
            }

            // Push/update active members as enabled users
            $result = $hik->syncUsersFromDb($activeMembers);

            Database::execute("UPDATE zkteco_devices SET last_sync = NOW() WHERE id = ?", [$deviceId]);

            $message = $result['message'] . " Disabled {$disabledCount} expired/inactive user(s).";
            if ($disableFailures > 0) {
                $message .= " ({$disableFailures} disable call(s) failed - check error log.)";
            }

            Session::setFlash($result['success'] ? 'success' : 'warning', $message);
        }
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Restart device
if (isset($_GET['restart']) && $deviceId = intval($_GET['restart'])) {
    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);

    if ($device) {
        $hik = hikvisionFromDevice($device);

        if ($hik) {
            $result = $hik->restart();
            Session::setFlash($result['success'] ? 'success' : 'danger', $result['message']);
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Pull access events (door punches) from device
if (isset($_GET['sync']) && $_GET['sync'] === 'device') {

    $device = Database::fetchOne(
        "SELECT * FROM zkteco_devices WHERE is_default = 1 OR status = 'online' LIMIT 1"
    );

    if (!$device) {
        Session::setFlash('danger', 'No device configured or online');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    $hik = hikvisionFromDevice($device);

    if (!$hik) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    // Pull since the last successful sync, capped at 7 days back the first time
    $since = $device['last_sync']
        ? date('Y-m-d\TH:i:sP', strtotime($device['last_sync']))
        : date('Y-m-d\TH:i:sP', strtotime('-7 days'));

    $records = $hik->getAccessEvents($since);

    if (empty($records)) {
        Database::execute("UPDATE zkteco_devices SET last_sync = NOW() WHERE id = ?", [$device['id']]);
        Session::setFlash('warning', 'No new access events found on device');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($records as $r) {

        $bioId = intval($r['employeeNo'] ?? 0);
        $ts = strtotime($r['timestamp'] ?? '');

        if (!$bioId || !$ts) {
            $skipped++;
            continue;
        }

        $punchTime = date('Y-m-d H:i:s', $ts);

        // MEMBER LOOKUP - unknown IDs are ignored silently
        $member = Database::fetchOne(
            "SELECT id FROM members WHERE biometric_id = ? LIMIT 1",
            [$bioId]
        );

        if (!$member) {
            $skipped++;
            continue;
        }

        $memberId = $member['id'];

        // DUPLICATE CHECK
        $duplicate = Database::fetchOne(
            "SELECT id FROM attendance_logs WHERE biometric_id = ? AND check_in = ? LIMIT 1",
            [$bioId, $punchTime]
        );

        if ($duplicate) {
            $skipped++;
            continue;
        }

        // CHECK TYPE - Hikvision face terminals report a verify mode string
        $verifyMode = strtolower($r['verify_mode'] ?? '');
        $checkType = 'face';
        if (strpos($verifyMode, 'card') !== false) {
            $checkType = 'card';
        } elseif (strpos($verifyMode, 'fp') !== false || strpos($verifyMode, 'finger') !== false) {
            $checkType = 'fingerprint';
        }

        // AUTO TOGGLE LOGIC - first punch = check in, next punch = check out
        $openAttendance = Database::fetchOne(
            "SELECT id, check_in FROM attendance_logs 
             WHERE biometric_id = ? AND check_out IS NULL 
             ORDER BY check_in DESC LIMIT 1",
            [$bioId]
        );

        if ($openAttendance) {
            $checkInTime = strtotime($openAttendance['check_in']);
            $checkOutTime = strtotime($punchTime);

            if ($checkOutTime <= $checkInTime) {
                $skipped++;
                continue;
            }

            $duration = round(($checkOutTime - $checkInTime) / 60);

            Database::execute(
                "UPDATE attendance_logs SET check_out = ?, duration_minutes = ?, status = 'present' WHERE id = ?",
                [$punchTime, $duration, $openAttendance['id']]
            );

            $updated++;
            continue;
        }

        Database::execute(
            "INSERT INTO attendance_logs (member_id, biometric_id, device_id, check_in, check_type, status)
             VALUES (?, ?, ?, ?, ?, 'present')",
            [$memberId, $bioId, $device['id'], $punchTime, $checkType]
        );

        $inserted++;
    }

    Database::execute("UPDATE zkteco_devices SET last_sync = NOW() WHERE id = ?", [$device['id']]);

    Session::setFlash(
        'success',
        "Synced successfully. {$inserted} check-ins, {$updated} check-outs, {$skipped} skipped."
    );

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
//Ends here

// Add/Edit device
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deviceId = intval($_POST['device_id'] ?? 0);
    $name = trim($_POST['device_name'] ?? '');
    $ip = trim($_POST['device_ip'] ?? '');
    $port = intval($_POST['port'] ?? 80);
    $location = trim($_POST['location'] ?? '');
    $isDefault = isset($_POST['is_default']) ? 1 : 0;

    // Hikvision-specific fields
    $deviceType = trim($_POST['device_type'] ?? 'hikvision') ?: 'hikvision';
    $deviceUsername = trim($_POST['device_username'] ?? '');
    $devicePassword = trim($_POST['device_password'] ?? '');

    if (empty($name) || empty($ip)) {
        Session::setFlash('danger', 'Device name and IP address are required');
    } elseif (empty($deviceUsername) || empty($devicePassword)) {
        Session::setFlash('danger', 'Username and password are required for Hikvision devices');
    } else {
        if ($isDefault) {
            Database::execute("UPDATE zkteco_devices SET is_default = 0");
        }

        if ($deviceId) {
            Database::execute(
                "UPDATE zkteco_devices 
                 SET device_name = ?, device_type = ?, device_ip = ?, port = ?, 
                     device_username = ?, device_password = ?, location = ?, is_default = ? 
                 WHERE id = ?",
                [$name, $deviceType, $ip, $port, $deviceUsername, $devicePassword, $location, $isDefault, $deviceId]
            );
            Session::setFlash('success', 'Device updated successfully');
        } else {
            Database::insert(
                "INSERT INTO zkteco_devices 
                    (device_name, device_type, device_ip, port, device_username, device_password, location, is_default) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$name, $deviceType, $ip, $port, $deviceUsername, $devicePassword, $location, $isDefault]
            );
            Session::setFlash('success', 'Device added successfully');
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Delete device
if (isset($_GET['delete']) && $deviceId = intval($_GET['delete'])) {
    Database::execute("DELETE FROM zkteco_devices WHERE id = ?", [$deviceId]);
    Session::setFlash('success', 'Device removed');
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Get devices
$devices = Database::fetchAll("SELECT * FROM zkteco_devices ORDER BY created_at DESC");

// Get device for edit
$editDevice = null;
if (isset($_GET['edit']) && $editId = intval($_GET['edit'])) {
    $editDevice = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$editId]);
}

// Get members with biometric IDs
$members = Database::fetchAll(
    "SELECT id, biometric_id, first_name, last_name 
     FROM members 
     WHERE status = 'active' 
     ORDER BY first_name 
     LIMIT 500"
);

// Get default device info for user management
$defaultDevice = Database::fetchOne("SELECT * FROM zkteco_devices WHERE is_default = 1 OR status = 'online' LIMIT 1");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- Device List & Add Form -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Device Form -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4">
                <?php echo $editDevice ? 'Edit Device' : 'Add New Device'; ?>
            </h3>
            <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input type="hidden" name="device_id" value="<?php echo $editDevice['id'] ?? ''; ?>">
                <input type="hidden" name="device_type" value="hikvision">
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Device Name</label>
                    <input type="text" name="device_name" value="<?php echo htmlspecialchars($editDevice['device_name'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g. Main Entrance Terminal">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">IP Address</label>
                    <input type="text" name="device_ip" value="<?php echo htmlspecialchars($editDevice['device_ip'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g. 10.0.8.200">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                    <input type="number" name="port" value="<?php echo $editDevice['port'] ?? 80; ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="device_username" value="<?php echo htmlspecialchars($editDevice['device_username'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g. admin">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="device_password" value="<?php echo htmlspecialchars($editDevice['device_password'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                    <input type="text" name="location" value="<?php echo htmlspecialchars($editDevice['location'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g. Main Entrance">
                </div>
                
                <div class="md:col-span-2 flex items-center gap-2">
                    <input type="checkbox" name="is_default" id="is_default" value="1" 
                           <?php echo ($editDevice['is_default'] ?? 0) ? 'checked' : ''; ?>
                           class="w-4 h-4 text-blue-600 rounded border-gray-300">
                    <label for="is_default" class="text-sm text-gray-700">Set as default device</label>
                </div>
                
                <div class="md:col-span-2 flex items-center gap-3">
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                        <?php echo $editDevice ? 'Update Device' : 'Add Device'; ?>
                    </button>
                    <?php if ($editDevice): ?>
                        <a href="devices.php" class="px-5 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- Devices Table -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900">Configured Devices</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 font-medium text-gray-500">Device</th>
                            <th class="text-left px-5 py-3 font-medium text-gray-500">IP:Port</th>
                            <th class="text-center px-5 py-3 font-medium text-gray-500">Status</th>
                            <th class="text-center px-5 py-3 font-medium text-gray-500">Default</th>
                            <th class="text-left px-5 py-3 font-medium text-gray-500">Last Sync</th>
                            <th class="text-center px-5 py-3 font-medium text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($devices as $device): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($device['device_name']); ?></p>
                                    <?php if ($device['location']): ?>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($device['location']); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 font-mono text-gray-600"><?php echo $device['device_ip']; ?>:<?php echo $device['port']; ?></td>
                                <td class="px-5 py-3 text-center">
                                    <span class="badge-<?php echo Helper::statusBadge($device['status']); ?> px-2 py-0.5 text-xs rounded-full border inline-flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                        <?php echo ucfirst($device['status']); ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <?php if ($device['is_default']): ?>
                                        <i data-lucide="check-circle" class="w-5 h-5 text-blue-500 mx-auto"></i>
                                    <?php else: ?>
                                        <span class="text-gray-300">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 text-gray-500 text-xs"><?php echo $device['last_sync'] ? Helper::relativeTime($device['last_sync']) : 'Never'; ?></td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="?test=<?php echo $device['id']; ?>" class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors" title="Test Connection">
                                            <i data-lucide="wifi" class="w-4 h-4"></i>
                                        </a>
                                        <a href="?sync_users=<?php echo $device['id']; ?>" class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Sync Users">
                                            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                        </a>
                                        <a href="?restart=<?php echo $device['id']; ?>" onclick="return confirm('Restart this device?')" class="p-2 text-gray-500 hover:text-orange-600 hover:bg-orange-50 rounded-lg transition-colors" title="Restart">
                                            <i data-lucide="power" class="w-4 h-4"></i>
                                        </a>
                                        <a href="?edit=<?php echo $device['id']; ?>" class="p-2 text-gray-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Edit">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </a>
                                        <a href="?delete=<?php echo $device['id']; ?>" onclick="return confirmDelete('Remove this device?')" class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($devices)): ?>
                            <tr><td colspan="6" class="px-5 py-8 text-center text-gray-400">No devices configured</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Device User Management -->
    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i data-lucide="users" class="w-5 h-5 text-blue-500"></i>
                Device Users
            </h3>
            <p class="text-sm text-gray-500 mb-4">
                Manage users on <?php echo $defaultDevice ? htmlspecialchars($defaultDevice['device_name']) : 'the default device'; ?>.
            </p>
            
            <!-- Add User to Device -->
            <form id="deviceUserForm" class="space-y-3 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Member</label>
                    <div class="max-h-48 overflow-y-auto border border-gray-200 rounded-lg">
                        <select id="memberSelect" class="w-full px-3 py-2 border-0 focus:ring-0 text-sm">
                            <option value="">Choose member...</option>
                            <?php foreach ($members as $m): ?>
                                <?php 
                                $displayBioId = !empty($m['biometric_id']) ? $m['biometric_id'] : $m['id'];
                                ?>
                                <option value="<?php echo $m['id']; ?>" data-biometric="<?php echo $displayBioId; ?>">
                                    <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?>
                                    (ID: <?php echo $displayBioId; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Biometric / Employee ID</label>
                    <input type="number" id="biometricId" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Auto or enter ID">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="manageDeviceUser('add')" class="py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium flex items-center justify-center gap-1">
                        <i data-lucide="plus" class="w-4 h-4"></i> Add
                    </button>
                    <button type="button" onclick="manageDeviceUser('remove')" class="py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium flex items-center justify-center gap-1">
                        <i data-lucide="minus" class="w-4 h-4"></i> Remove
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="manageDeviceUser('enable')" class="py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium flex items-center justify-center gap-1">
                        <i data-lucide="check" class="w-4 h-4"></i> Enable
                    </button>
                    <button type="button" onclick="manageDeviceUser('disable')" class="py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm font-medium flex items-center justify-center gap-1">
                        <i data-lucide="x" class="w-4 h-4"></i> Disable
                    </button>
                </div>
            </form>
            
            <!-- Device Stats -->
            <div class="border-t border-gray-100 pt-4">
                <h4 class="text-sm font-medium text-gray-700 mb-3">Device Info</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Default Device</span>
                        <span class="font-medium"><?php echo $defaultDevice ? htmlspecialchars($defaultDevice['device_name']) : 'None'; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">IP Address</span>
                        <span class="font-mono"><?php echo $defaultDevice['device_ip'] ?? 'N/A'; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Status</span>
                        <span class="font-medium <?php echo ($defaultDevice['status'] ?? 'offline') === 'online' ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo ucfirst($defaultDevice['status'] ?? 'offline'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Quick Actions</h3>
            <div class="space-y-2">
                <?php foreach ($devices as $device): ?>
                    <a href="?sync_users=<?php echo $device['id']; ?>" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition-colors">
                        <span class="text-sm font-medium text-blue-700">Sync to <?php echo htmlspecialchars($device['device_name']); ?></span>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                    </a>
                <?php endforeach; ?>
                <a href="?sync=device" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition-colors">
                    <span class="text-sm font-medium text-red-700">Pull access events</span>
                    <i data-lucide="download" class="w-4 h-4 text-gray-400"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-fill biometric ID from member selection
document.getElementById('memberSelect').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    const bioId = option.getAttribute('data-biometric');
    if (bioId) {
        document.getElementById('biometricId').value = bioId;
    }
});

// Manage device user via AJAX
async function manageDeviceUser(action) {
    const memberId = document.getElementById('memberSelect').value;
    const biometricId = document.getElementById('biometricId').value;
    
    if (!memberId && !biometricId) {
        alert('Please select a member or enter a biometric ID');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', action);
    formData.append('member_id', memberId);
    formData.append('biometric_id', biometricId);
    
    try {
        const response = await fetch('<?php echo API_URL; ?>/hikvision-user.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Server returned ' + response.status + ':', text.substring(0, 500));
            alert('Server error ' + response.status + '. Check browser console for details.');
            return;
        }
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message);
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        console.error('Fetch error:', error);
        alert('Network error: ' + error.message);
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>