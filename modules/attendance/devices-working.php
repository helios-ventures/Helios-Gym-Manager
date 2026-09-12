<?php
/**
 * ZKTeco Device Management - CRUD operations for biometric devices
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;
use Gym\Core\ZKTeco;

Auth::requirePermission('attendance', 'manage');

$pageTitle = 'ZKTeco Device Management';
$pageDescription = 'Manage biometric devices and users';

// Test connection
if (isset($_GET['test']) && $deviceId = intval($_GET['test'])) {
    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);
    if ($device) {
        $result = ZKTeco::testConnection($device['device_ip'], $device['port']);
        
        if ($result['success']) {
            Database::execute("UPDATE zkteco_devices SET status = 'online', last_sync = NOW() WHERE id = ?", [$deviceId]);
            Session::setFlash('success', 'Connection successful! Device is online.');
        } else {
            Database::execute("UPDATE zkteco_devices SET status = 'offline' WHERE id = ?", [$deviceId]);
            Session::setFlash('danger', 'Connection failed: ' . $result['message']);
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Sync device users from DB
if (isset($_GET['sync_users']) && $deviceId = intval($_GET['sync_users'])) {
    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);
    if ($device) {
        $zk = new ZKTeco($device['device_ip'], $device['port']);
        if ($zk->connect()) {
            $members = Database::fetchAll("SELECT id, biometric_id, first_name, last_name FROM members WHERE status = 'active' AND biometric_id IS NOT NULL");
            $result = $zk->syncUsersFromDb($members);
            $zk->disconnect();
            
            Database::execute("UPDATE zkteco_devices SET last_sync = NOW() WHERE id = ?", [$deviceId]);
            Session::setFlash($result['success'] ? 'success' : 'warning', $result['message']);
        } else {
            Session::setFlash('danger', 'Failed to connect to device');
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Restart device
if (isset($_GET['restart']) && $deviceId = intval($_GET['restart'])) {
    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);
    if ($device) {
        $zk = new ZKTeco($device['device_ip'], $device['port']);
        if ($zk->connect()) {
            $result = $zk->restart();
            Session::setFlash('success', $result['message']);
        } else {
            Session::setFlash('danger', 'Failed to connect to device');
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Pull attendance data from device
// Pull attendance data from device
if (isset($_GET['sync']) && $_GET['sync'] === 'device') {
    $device = Database::fetchOne(
        "SELECT * FROM zkteco_devices WHERE is_default = 1 OR status = 'online' LIMIT 1"
    );
    
    if (!$device) {
        Session::setFlash('danger', 'No device configured or online');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    
    $zk = new ZKTeco($device['device_ip'], (int) $device['port']);
    
    if (!$zk->connect()) {
        Session::setFlash('danger', 'Failed to connect to device at ' . $device['device_ip'] . ':' . $device['port']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    
    $records = $zk->getAttendance();
    $zk->disconnect();
    
    if (empty($records)) {
        Session::setFlash('warning', 'No attendance records found on device');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    
    foreach ($records as $idx => $r) {
        $bioId = intval($r['id'] ?? ($r['userid'] ?? 0));
        $rawTime = $r['timestamp'] ?? ($r['punch_time'] ?? '');
        
        // Validate timestamp carefully
        $ts = strtotime($rawTime);
        if (empty($rawTime) || $ts === false || $ts < 1) {
            $skipped++;
            $errors[] = "Record #{$idx}: Invalid timestamp '{$rawTime}'";
            continue;
        }
        $punchTime = date('Y-m-d H:i:s', $ts);
        
        $state = intval($r['state'] ?? 0);
        $type = intval($r['type'] ?? 0);
        
        if (empty($bioId)) {
            $skipped++;
            $errors[] = "Record #{$idx}: Empty biometric ID";
            continue;
        }
        
        // ─── MAP BIOMETRIC ID TO MEMBER ───
        //member_id = biometric_id = device user ID
        //$memberId = $bioId;
        $member = Database::fetchOne(
        "SELECT id FROM members WHERE biometric_id = ? LIMIT 1",
            [$bioId]
        );

        $memberId = $member['id'] ?? null;
        
        // Map device type to check_type enum
        $checkType = 'fingerprint';
        if ($type === 1 || $type === 2) {
            $checkType = 'card';
        } elseif ($type === 3) {
            $checkType = 'face';
        }
        
        // States 1,3,5 = Out variants
        $isCheckout = in_array($state, [1, 3, 5]);
        
        // ─── CHECKOUT: PAIR WITH OPEN CHECK-IN ───
        if ($isCheckout) {
            $openRecord = Database::fetchOne(
                "SELECT id, check_in FROM attendance_logs 
                 WHERE biometric_id = ? AND DATE(check_in) = DATE(?) AND check_out IS NULL 
                 ORDER BY check_in DESC LIMIT 1",
                [$memberId, $punchTime]
            );
            
            if ($openRecord) {
                // Prevent double-updating same record
                $alreadyPaired = Database::fetchOne(
                    "SELECT id FROM attendance_logs WHERE id = ? AND check_out = ?",
                    [$openRecord['id'], $punchTime]
                );
                
                if ($alreadyPaired) {
                    $skipped++;
                    continue;
                }
                
                $checkIn = strtotime($openRecord['check_in']);
                $checkOut = strtotime($punchTime);
                $duration = max(0, round(($checkOut - $checkIn) / 60));
                
                try {
                    Database::execute(
                        "UPDATE attendance_logs 
                         SET check_out = ?, duration_minutes = ?, status = 'present' 
                         WHERE id = ?",
                        [$punchTime, $duration, $openRecord['id']]
                    );
                    $updated++;
                } catch (\Exception $e) {
                    $skipped++;
                    $errors[] = "Record #{$idx} checkout pair failed: " . $e->getMessage();
                }
                continue;
            }
            // No open check-in → fall through to insert as unmatched checkout
        }
        
        // ─── CHECK FOR EXACT DUPLICATE ───
        $duplicate = Database::fetchOne(
            "SELECT id FROM attendance_logs 
             WHERE biometric_id = ? AND device_id = ? AND check_in = ?",
            [$memberId, $device['id'], $punchTime]
        );
        
        if ($duplicate) {
            $skipped++;
            continue;
        }
        
        // ─── MAP BIOMETRIC ID TO MEMBER ───
        //$member = Database::fetchOne(
        //    "SELECT id FROM members WHERE biometric_id = ? LIMIT 1",
        //    [$bioId]
       // );

        $memberId = $member['id'] ?? null;

        if (!$memberId) {
            $skipped++;
            $errors[] = "No member found for biometric ID: $bioId";
            continue;
        }

        // ─── INSERT NEW RECORD ───
        try {
            Database::execute(
                "INSERT INTO attendance_logs 
                 (member_id, biometric_id, device_id, check_in, check_type, status, notes) 
                 VALUES (?, ?, ?, ?, ?, 'present', ?)",
                [
                    $memberId,
                    $bioId,
                    $device['id'],
                    $punchTime,
                    $checkType,
                    $isCheckout ? 'Unmatched checkout' : null
                ]
            );

            $inserted++;

        } catch (\Exception $e) {
            $skipped++;
            $errors[] = "Record #{$idx} insert failed: " . $e->getMessage();
        }
        
    }
    
    $msg = "Synced: {$inserted} new, {$updated} paired, {$skipped} skipped. Total on device: " . count($records);
    if (!empty($errors)) {
        $msg .= "<br><br>Errors:<br>" . implode("<br>", array_slice($errors, 0, 5));
    }
    
    Session::setFlash($inserted > 0 || $updated > 0 ? 'success' : 'warning', $msg);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
//Ends here

// Add/Edit device
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deviceId = intval($_POST['device_id'] ?? 0);
    $name = trim($_POST['device_name'] ?? '');
    $ip = trim($_POST['device_ip'] ?? '');
    $port = intval($_POST['port'] ?? 4370);
    $location = trim($_POST['location'] ?? '');
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    
    if (empty($name) || empty($ip)) {
        Session::setFlash('danger', 'Device name and IP address are required');
    } else {
        if ($isDefault) {
            Database::execute("UPDATE zkteco_devices SET is_default = 0");
        }
        
        if ($deviceId) {
            Database::execute(
                "UPDATE zkteco_devices SET device_name = ?, device_ip = ?, port = ?, location = ?, is_default = ? WHERE id = ?",
                [$name, $ip, $port, $location, $isDefault, $deviceId]
            );
            Session::setFlash('success', 'Device updated successfully');
        } else {
            Database::insert(
                "INSERT INTO zkteco_devices (device_name, device_ip, port, location, is_default) VALUES (?, ?, ?, ?, ?)",
                [$name, $ip, $port, $location, $isDefault]
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
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Device Name</label>
                    <input type="text" name="device_name" value="<?php echo htmlspecialchars($editDevice['device_name'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g. Main Entrance Device">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">IP Address</label>
                    <input type="text" name="device_ip" value="<?php echo htmlspecialchars($editDevice['device_ip'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g. 192.168.1.100">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                    <input type="number" name="port" value="<?php echo $editDevice['port'] ?? 4370; ?>"
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
                                // Use biometric_id if set, otherwise fall back to member id
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Biometric ID</label>
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
                        <span class="text-sm font-medium text-gray-700">Sync to <?php echo htmlspecialchars($device['device_name']); ?></span>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                    </a>
                <?php endforeach; ?>
                <a href="?sync=device" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition-colors">
                    <span class="text-sm font-medium text-gray-700">Pull attendance data</span>
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
        const response = await fetch('<?php echo API_URL; ?>/zkteco-user.php', {
            method: 'POST',
            body: formData
        });
        
        // Check if PHP crashed (404, 500, etc.)
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
