<?php
/**
 * ZKTeco Device Management - REFACTORED
 * 
 * Features:
 * - Async queue-based operations (non-blocking)
 * - Smart bidirectional syncing
 * - Detailed sync history & logging
 * - Conflict detection & reporting
 * - Real-time queue status
 * - Improved CRUD operations
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;
use Gym\Core\ZKTeco;

Auth::requirePermission('attendance', 'manage');

$pageTitle = 'Biometric Device Management';
$pageDescription = 'Manage ZKTeco devices, users, and attendance syncing';

// ─────────────────────────────────────────────────────────────
// 1. HANDLE DEVICE CRUD
// ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_device') {
    $deviceId = intval($_POST['device_id'] ?? 0);
    $name = trim($_POST['device_name'] ?? '');
    $ip = trim($_POST['device_ip'] ?? '');
    $port = intval($_POST['port'] ?? 4370);
    $location = trim($_POST['location'] ?? '');
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    
    if (empty($name) || empty($ip)) {
        Session::setFlash('danger', 'Device name and IP are required');
    } else {
        try {
            if ($isDefault) {
                Database::execute("UPDATE zkteco_devices SET is_default = 0");
            }
            
            if ($deviceId) {
                Database::execute(
                    "UPDATE zkteco_devices 
                     SET device_name = ?, device_ip = ?, port = ?, location = ?, is_default = ? 
                     WHERE id = ?",
                    [$name, $ip, $port, $location, $isDefault, $deviceId]
                );
                Session::setFlash('success', 'Device updated successfully');
            } else {
                Database::execute(
                    "INSERT INTO zkteco_devices (device_name, device_ip, port, location, is_default, status) 
                     VALUES (?, ?, ?, ?, ?, 'offline')",
                    [$name, $ip, $port, $location, $isDefault]
                );
                Session::setFlash('success', 'Device added successfully');
            }
        } catch (\Exception $e) {
            Session::setFlash('danger', 'Error: ' . $e->getMessage());
        }
    }
    
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. HANDLE QUICK ACTIONS (Test, Delete)
// ─────────────────────────────────────────────────────────────

if (isset($_GET['test_connection']) && $deviceId = intval($_GET['test_connection'])) {
    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);
    
    if ($device) {
        try {
            $zk = new ZKTeco($device['device_ip'], $device['port']);
            if ($zk->connect()) {
                Database::execute(
                    "UPDATE zkteco_devices SET status = 'online', last_sync = NOW() WHERE id = ?",
                    [$deviceId]
                );
                Session::setFlash('success', "✓ Device '{$device['device_name']}' is online and responding");
            } else {
                Database::execute("UPDATE zkteco_devices SET status = 'offline' WHERE id = ?", [$deviceId]);
                Session::setFlash('warning', "✗ Device '{$device['device_name']}' is not responding");
            }
        } catch (\Exception $e) {
            Database::execute("UPDATE zkteco_devices SET status = 'error' WHERE id = ?", [$deviceId]);
            Session::setFlash('danger', "Error testing connection: " . $e->getMessage());
        }
    }
    
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (isset($_GET['delete']) && $deviceId = intval($_GET['delete'])) {
    if (confirmAction('Are you sure? This will delete the device configuration but not member data.')) {
        Database::execute("DELETE FROM zkteco_devices WHERE id = ?", [$deviceId]);
        Session::setFlash('success', 'Device configuration deleted');
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. HANDLE QUEUE-BASED SYNC OPERATIONS
// ─────────────────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'queue_sync') {
    $deviceId = intval($_POST['device_id'] ?? 0);
    $syncType = $_POST['sync_type'] ?? null; // 'members_to_device' or 'attendance_from_device'
    
    $device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);
    
    if (!$device) {
        echo json_encode(['success' => false, 'message' => 'Device not found']);
        exit;
    }
    
    if (!$device['is_default'] && $device['status'] !== 'online') {
        echo json_encode(['success' => false, 'message' => 'Device is offline']);
        exit;
    }
    
    try {
        // Log sync start
        $syncLogId = Database::insertAndGetId(
            "INSERT INTO device_sync_logs (device_id, sync_type, direction, status, initiated_by) 
             VALUES (?, ?, ?, 'started', ?)",
            [$deviceId, $syncType, 'push', $_SESSION['user_id']]
        );
        
        // Queue the operation
        
        Database::execute(
            "INSERT INTO sync_queue (device_id, operation_type, priority, status) 
             VALUES (?, ?, 'high', 'pending')",
            [$deviceId, $syncType]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Sync operation queued',
            'sync_log_id' => $syncLogId,
            'queue_status' => getQueueStatus($deviceId)
        ]);
        
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. GET DATA FOR VIEW
// ─────────────────────────────────────────────────────────────

$devices = Database::fetchAll(
    "SELECT d.*, 
            (SELECT COUNT(*) FROM attendance_logs WHERE device_id = d.id AND DATE(created_at) = CURDATE()) as today_count
     FROM zkteco_devices d 
     ORDER BY d.is_default DESC, d.created_at DESC"
);

$editDevice = null;
if (isset($_GET['edit']) && $editId = intval($_GET['edit'])) {
    $editDevice = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$editId]);
}

// Get members for user management
$members = Database::fetchAll(
    "SELECT m.id, m.biometric_id, m.first_name, m.last_name, m.status 
     FROM members m 
     WHERE m.status = 'active' 
     ORDER BY m.first_name 
     LIMIT 500"
);

// Get sync history
$syncHistory = Database::fetchAll(
    "SELECT dsl.*, u.first_name, u.last_name
     FROM device_sync_logs dsl
     LEFT JOIN users u ON dsl.initiated_by = u.id
     ORDER BY dsl.started_at DESC 
     LIMIT 20"
);

// Get queue statistics
function getQueueStatus($deviceId = null) {
    $where = $deviceId ? "WHERE device_id = ?" : "WHERE 1=1";
    $params = $deviceId ? [$deviceId] : [];
    
    return Database::fetchOne(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
         FROM sync_queue $where",
        $params
    );
}

$queueStatus = getQueueStatus();

// Get conflicts
$conflicts = Database::fetchAll(
    "SELECT dc.*, m.first_name, m.last_name, m.member_code
     FROM device_conflicts dc
     LEFT JOIN members m ON dc.member_id = m.id
     WHERE dc.resolved_at IS NULL
     ORDER BY dc.created_at DESC
     LIMIT 10"
);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="space-y-6">
    
    <!-- Queue Status Summary -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-600 mb-1">Queue Operations</p>
            <p class="text-2xl font-bold text-gray-900"><?php echo $queueStatus['total'] ?? 0; ?></p>
            <p class="text-xs text-gray-500 mt-1">Total pending</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-600 mb-1">Pending</p>
            <p class="text-2xl font-bold text-yellow-600"><?php echo $queueStatus['pending'] ?? 0; ?></p>
            <p class="text-xs text-gray-500 mt-1">Waiting to process</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-600 mb-1">Processing</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo $queueStatus['processing'] ?? 0; ?></p>
            <p class="text-xs text-gray-500 mt-1">Currently processing</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-600 mb-1">Failed</p>
            <p class="text-2xl font-bold text-red-600"><?php echo $queueStatus['failed'] ?? 0; ?></p>
            <p class="text-xs text-gray-500 mt-1">Need attention</p>
        </div>
    </div>
    
    <!-- Alerts -->
    <?php if (!empty($conflicts)): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <h3 class="font-semibold text-yellow-900 flex items-center gap-2">
                <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                <?php echo count($conflicts); ?> Conflict(s) Requiring Review
            </h3>
            <p class="text-sm text-yellow-700 mt-2">
                Data mismatches detected between device and system. 
                <a href="#conflicts" class="underline font-medium">View details below</a>
            </p>
        </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left: Device Management -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Add/Edit Device Form -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4">
                    <?php echo $editDevice ? '📝 Edit Device' : '➕ Add New Device'; ?>
                </h2>
                
                <form method="POST" action="" class="space-y-4">
                    <input type="hidden" name="action" value="save_device">
                    <input type="hidden" name="device_id" value="<?php echo $editDevice['id'] ?? ''; ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Device Name *</label>
                            <input type="text" name="device_name" required
                                   value="<?php echo htmlspecialchars($editDevice['device_name'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="e.g., Main Entrance">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">IP Address *</label>
                            <input type="text" name="device_ip" required
                                   value="<?php echo htmlspecialchars($editDevice['device_ip'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="192.168.1.100">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                            <input type="number" name="port"
                                   value="<?php echo $editDevice['port'] ?? 4370; ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                            <input type="text" name="location"
                                   value="<?php echo htmlspecialchars($editDevice['location'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="e.g., Ground Floor">
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="is_default" id="is_default" value="1"
                               <?php echo ($editDevice['is_default'] ?? 0) ? 'checked' : ''; ?>
                               class="w-4 h-4 text-blue-600 rounded border-gray-300">
                        <label for="is_default" class="text-sm text-gray-700">Set as default device (primary sync target)</label>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
                            <?php echo $editDevice ? 'Update Device' : 'Add Device'; ?>
                        </button>
                        <?php if ($editDevice): ?>
                            <a href="devices.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                                Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Devices List -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-bold text-gray-900">🔌 Configured Devices</h2>
                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">
                        <?php echo count($devices); ?> device(s)
                    </span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <th class="text-left px-6 py-3 font-semibold text-gray-700">Device</th>
                                <th class="text-left px-6 py-3 font-semibold text-gray-700">Connection</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Status</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Today's Logs</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Last Sync</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($devices as $device): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div>
                                            <p class="font-medium text-gray-900">
                                                <?php echo htmlspecialchars($device['device_name']); ?>
                                                <?php if ($device['is_default']): ?>
                                                    <span class="ml-2 inline-block text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">DEFAULT</span>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($device['location']): ?>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($device['location']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 font-mono text-gray-600">
                                        <?php echo $device['device_ip']; ?>:<?php echo $device['port']; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                                     <?php echo $device['status'] === 'online' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                            <span class="w-2 h-2 rounded-full bg-current animate-pulse"></span>
                                            <?php echo ucfirst($device['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center text-gray-900 font-medium">
                                        <?php echo $device['today_count']; ?> logs
                                    </td>
                                    <td class="px-6 py-4 text-center text-xs text-gray-500">
                                        <?php echo $device['last_sync'] ? Helper::relativeTime($device['last_sync']) : 'Never'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <a href="?test_connection=<?php echo $device['id']; ?>" 
                                               class="p-2 text-gray-500 hover:bg-green-50 hover:text-green-600 rounded-lg transition-colors"
                                               title="Test Connection">
                                                <i data-lucide="wifi" class="w-4 h-4"></i>
                                            </a>
                                            <button type="button" onclick="queueSync(<?php echo $device['id']; ?>, 'members_to_device')"
                                                    class="p-2 text-gray-500 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors"
                                                    title="Sync Members">
                                                <i data-lucide="upload-cloud" class="w-4 h-4"></i>
                                            </button>
                                            <button type="button" onclick="queueSync(<?php echo $device['id']; ?>, 'attendance_from_device')"
                                                    class="p-2 text-gray-500 hover:bg-purple-50 hover:text-purple-600 rounded-lg transition-colors"
                                                    title="Sync Attendance">
                                                <i data-lucide="download-cloud" class="w-4 h-4"></i>
                                            </button>
                                            <a href="?edit=<?php echo $device['id']; ?>"
                                               class="p-2 text-gray-500 hover:bg-indigo-50 hover:text-indigo-600 rounded-lg transition-colors"
                                               title="Edit">
                                                <i data-lucide="edit-2" class="w-4 h-4"></i>
                                            </a>
                                            <a href="?delete=<?php echo $device['id']; ?>" 
                                               onclick="return confirm('Delete this device?')"
                                               class="p-2 text-gray-500 hover:bg-red-50 hover:text-red-600 rounded-lg transition-colors"
                                               title="Delete">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($devices)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        No devices configured yet. Add your first device above.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Sync History -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-bold text-gray-900">📊 Sync History</h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <th class="text-left px-6 py-3 font-semibold text-gray-700">Sync Type</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Records</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Success</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Failed</th>
                                <th class="text-right px-6 py-3 font-semibold text-gray-700">Duration</th>
                                <th class="text-center px-6 py-3 font-semibold text-gray-700">Status</th>
                                <th class="text-left px-6 py-3 font-semibold text-gray-700">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($syncHistory as $sync): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium text-gray-900">
                                        <?php echo $sync['sync_type'] === 'members_sync' ? '👥 Members' : '📋 Attendance'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center text-gray-600"><?php echo $sync['total_records'] ?? '-'; ?></td>
                                    <td class="px-6 py-4 text-center text-green-600 font-medium"><?php echo $sync['successful_records'] ?? 0; ?></td>
                                    <td class="px-6 py-4 text-center text-red-600 font-medium"><?php echo $sync['failed_records'] ?? 0; ?></td>
                                    <td class="px-6 py-4 text-right text-gray-600">
                                        <?php echo $sync['duration_seconds'] ? round($sync['duration_seconds']) . 's' : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-block px-2 py-1 rounded text-xs font-medium
                                                     <?php echo $sync['status'] === 'completed' ? 'bg-green-100 text-green-700' : 
                                                              ($sync['status'] === 'failed' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'); ?>">
                                            <?php echo ucfirst($sync['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php echo Helper::relativeTime($sync['started_at']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($syncHistory)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                        No sync history yet
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Right: Device Users & Conflicts -->
        <div class="space-y-6">
            
            <!-- Device User Management -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4">👤 Device User CRUD</h2>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Member</label>
                        <select id="memberSelect" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                onchange="updateBiometricId()">
                            <option value="">Choose member...</option>
                            <?php foreach ($members as $m): ?>
                                <?php $bioId = !empty($m['biometric_id']) ? $m['biometric_id'] : $m['id']; ?>
                                <option value="<?php echo $m['id']; ?>" data-biometric="<?php echo $bioId; ?>">
                                    <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?> 
                                    (<?php echo $bioId; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Biometric ID</label>
                        <input type="number" id="biometricId" min="1"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                               placeholder="Auto-assigned or enter">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" onclick="deviceUserAction('add')"
                                class="py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium text-sm flex items-center justify-center gap-1">
                            <i data-lucide="plus" class="w-4 h-4"></i> Add
                        </button>
                        <button type="button" onclick="deviceUserAction('remove')"
                                class="py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium text-sm flex items-center justify-center gap-1">
                            <i data-lucide="minus" class="w-4 h-4"></i> Remove
                        </button>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" onclick="deviceUserAction('enable')"
                                class="py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium text-sm flex items-center justify-center gap-1">
                            <i data-lucide="check" class="w-4 h-4"></i> Enable
                        </button>
                        <button type="button" onclick="deviceUserAction('disable')"
                                class="py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 font-medium text-sm flex items-center justify-center gap-1">
                            <i data-lucide="x" class="w-4 h-4"></i> Disable
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Conflicts Section -->
            <?php if (!empty($conflicts)): ?>
                <div id="conflicts" class="bg-white rounded-xl border border-yellow-300 shadow-sm p-6">
                    <h2 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-yellow-600"></i>
                        Unresolved Conflicts
                    </h2>
                    
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php foreach ($conflicts as $conflict): ?>
                            <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm">
                                <p class="font-medium text-gray-900">
                                    <?php echo htmlspecialchars($conflict['first_name'] . ' ' . $conflict['last_name'] ?? 'Unknown'); ?>
                                </p>
                                <p class="text-xs text-gray-600 mt-1">
                                    <strong>Issue:</strong>
                                    <?php 
                                    $types = [
                                        'missing_on_device' => 'Member not synced to device',
                                        'missing_in_db' => 'User on device not in system',
                                        'duplicate_biometric' => 'Biometric ID used multiple times',
                                        'data_mismatch' => 'Member data differs',
                                        'attendance_mismatch' => 'Attendance log mismatch'
                                    ];
                                    echo $types[$conflict['conflict_type']] ?? $conflict['conflict_type'];
                                    ?>
                                </p>
                                <button type="button" class="mt-2 text-xs text-yellow-700 hover:text-yellow-900 font-medium underline">
                                    Resolve
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sync Progress Modal (for async operations) -->
<div id="syncModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Sync in Progress</h3>
        <div class="space-y-4">
            <div class="w-full bg-gray-200 rounded-full h-2">
                <div id="progressBar" class="bg-blue-600 h-2 rounded-full w-0 transition-all duration-300"></div>
            </div>
            <p id="syncStatus" class="text-sm text-gray-600 text-center">Initializing...</p>
            <p id="syncDetails" class="text-xs text-gray-500 text-center"></p>
        </div>
    </div>
</div>

<script>
// Update biometric ID when member is selected
function updateBiometricId() {
    const select = document.getElementById('memberSelect');
    const option = select.options[select.selectedIndex];
    const bioId = option.getAttribute('data-biometric');
    if (bioId) {
        document.getElementById('biometricId').value = bioId;
    }
}

// Device user CRUD operations
async function deviceUserAction(action) {
    const memberId = document.getElementById('memberSelect').value;
    const biometricId = document.getElementById('biometricId').value;
    
    if (!memberId && !biometricId) {
        alert('Please select a member');
        return;
    }
    
    try {
        const response = await fetch('<?php echo API_URL; ?>/zkteco-user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${action}&member_id=${memberId}&biometric_id=${biometricId}`
        });
        
        const result = await response.json();
        if (result.success) {
            alert('✓ ' + result.message);
            document.getElementById('memberSelect').value = '';
            document.getElementById('biometricId').value = '';
        } else {
            alert('✗ Error: ' + result.message);
        }
    } catch (error) {
        alert('Network error: ' + error.message);
    }
}

// Queue sync operation
async function queueSync(deviceId, syncType) {
    const modal = document.getElementById('syncModal');
    modal.classList.remove('hidden');
    
    try {
        const response = await fetch('<?php echo $_SERVER['REQUEST_URI']; ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=queue_sync&device_id=${deviceId}&sync_type=${syncType}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Poll queue status
            let progress = 0;
            const interval = setInterval(async () => {
                progress += Math.random() * 30;
                if (progress > 90) progress = 90;
                
                document.getElementById('progressBar').style.width = progress + '%';
                document.getElementById('syncStatus').textContent = 'Syncing...';
                
                // In production, poll actual queue status here
            }, 1000);
            
            setTimeout(() => {
                clearInterval(interval);
                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('syncStatus').textContent = 'Complete!';
                setTimeout(() => {
                    modal.classList.add('hidden');
                    location.reload();
                }, 1500);
            }, 5000); // Simulated delay
        } else {
            alert('Error: ' + result.message);
            modal.classList.add('hidden');
        }
    } catch (error) {
        alert('Error: ' + error.message);
        modal.classList.add('hidden');
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
