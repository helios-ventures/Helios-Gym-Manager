<?php
/**
 * Attendance Management - Daily attendance view with ZKTeco integration
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\ZKTeco;
use Gym\Core\Session;
use Gym\Core\Response;

Auth::requirePermission('attendance', 'view');

$pageTitle = "Today's Attendance";
$pageDescription = 'Real-time attendance tracking';

// Handle manual check-in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_checkin'])) {
    Auth::requirePermission('attendance', 'manage');
    
    $memberId = intval($_POST['member_id'] ?? 0);
    $checkType = $_POST['check_type'] ?? 'manual';
    
    if ($memberId) {
        $member = Database::fetchOne("SELECT * FROM members WHERE id = ? AND status = 'active'", [$memberId]);
        if ($member) {
            // Check if already checked in today without checkout
            $existing = Database::fetchOne(
                "SELECT * FROM attendance_logs WHERE member_id = ? AND DATE(check_in) = CURDATE() AND check_out IS NULL",
                [$memberId]
            );
            
            if (!$existing) {
                Database::insert(
                    "INSERT INTO attendance_logs (member_id, biometric_id, check_in, status, check_type, notes) 
                     VALUES (?, ?, NOW(), 'present', ?, 'Manual check-in')",
                    [$memberId, $member['biometric_id'], $checkType]
                );
                Session::setFlash('success', 'Check-in recorded for ' . $member['first_name'] . ' ' . $member['last_name']);
            } else {
                Session::setFlash('warning', 'Member is already checked in');
            }
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Handle checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    Auth::requirePermission('attendance', 'manage');
    
    $logId = intval($_POST['log_id'] ?? 0);
    if ($logId) {
        Database::execute(
            "UPDATE attendance_logs SET check_out = NOW(), 
             duration_minutes = TIMESTAMPDIFF(MINUTE, check_in, NOW()) 
             WHERE id = ? AND check_out IS NULL",
            [$logId]
        );
        Session::setFlash('success', 'Check-out recorded successfully');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Sync from ZKTeco device
// Sync from ZKTeco device
if (isset($_GET['sync']) && $_GET['sync'] === 'device') {
    Auth::requirePermission('attendance', 'manage');
    
    $device = Database::fetchOne(
        "SELECT * FROM zkteco_devices WHERE is_default = 1 OR status = 'online' LIMIT 1"
    );
    
    if (!$device) {
        Session::setFlash('warning', 'No ZKTeco device configured or online');
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
    
    foreach ($records as $r) {
        $deviceUserId = intval($r['id'] ?? ($r['userid'] ?? 0));
        $rawTime = $r['timestamp'] ?? ($r['punch_time'] ?? '');
        
        $ts = strtotime($rawTime);
        if (empty($rawTime) || $ts === false || $ts < 1) {
            $skipped++;
            continue;
        }
        $punchTime = date('Y-m-d H:i:s', $ts);
        
        $state = intval($r['state'] ?? 0);
        $type = intval($r['type'] ?? 0);
        
        if (empty($deviceUserId)) {
            $skipped++;
            continue;
        }
        
        // Map device type
        $checkType = 'fingerprint';
        if ($type === 1 || $type === 2) {
            $checkType = 'card';
        } elseif ($type === 3) {
            $checkType = 'face';
        }
        
        $isCheckout = in_array($state, [1, 3, 5]);
        
        // ─── CHECKOUT PAIRING ───
        if ($isCheckout) {
            $openRecord = Database::fetchOne(
                "SELECT id, check_in FROM attendance_logs 
                 WHERE biometric_id = ? AND DATE(check_in) = DATE(?) AND check_out IS NULL 
                 ORDER BY check_in DESC LIMIT 1",
                [$deviceUserId, $punchTime]
            );
            
            if ($openRecord) {
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
                
                Database::execute(
                    "UPDATE attendance_logs 
                     SET check_out = ?, duration_minutes = ?, status = 'present' 
                     WHERE id = ?",
                    [$punchTime, $duration, $openRecord['id']]
                );
                $updated++;
                continue;
            }
        }
        
        // ─── DUPLICATE CHECK ───
        $duplicate = Database::fetchOne(
            "SELECT id FROM attendance_logs 
             WHERE biometric_id = ? AND device_id = ? AND check_in = ?",
            [$deviceUserId, $device['id'], $punchTime]
        );
        
        if ($duplicate) {
            $skipped++;
            continue;
        }
        
        // ─── INSERT ───
        try {
            Database::execute(
                "INSERT INTO attendance_logs 
                 (member_id, biometric_id, device_id, check_in, check_type, status, notes) 
                 VALUES (?, ?, ?, ?, ?, 'present', ?)",
                [
                    $deviceUserId,
                    $deviceUserId,
                    $device['id'],
                    $punchTime,
                    $checkType,
                    $isCheckout ? 'Unmatched checkout from device' : null
                ]
            );
            $inserted++;
        } catch (\Exception $e) {
            $skipped++;
        }
    }
    
    Session::setFlash('success', "Synced: {$inserted} new, {$updated} paired, {$skipped} skipped from " . count($records) . " device records.");
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Get today's attendance
$today = date('Y-m-d');
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

$params = [];
// Build WHERE clause
$where = ["DATE(al.check_in) = ?"];
$params = [$today];

if (!empty($search)) {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ?)";
    $s = "%{$search}%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
}

if (!empty($status)) {
    $where[] = "al.status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);

// Fetch attendance
$attendance = Database::fetchAll(
    "SELECT al.*, m.first_name, m.last_name, m.member_code, m.phone, z.device_name 
     FROM attendance_logs al 
     LEFT JOIN members m ON al.biometric_id = m.biometric_id 
     LEFT JOIN zkteco_devices z ON al.device_id = z.id 
     WHERE {$whereClause} 
     ORDER BY al.check_in DESC",
    $params
);

// Get stats
$totalCheckedIn = Database::fetchOne("SELECT COUNT(DISTINCT member_id) as count FROM attendance_logs WHERE DATE(check_in) = ?", [$today])['count'] ?? 0;
$stillActive = Database::fetchOne("SELECT COUNT(*) as count FROM attendance_logs WHERE DATE(check_in) = ? AND check_out IS NULL", [$today])['count'] ?? 0;
$checkedOut = Database::fetchOne("SELECT COUNT(*) as count FROM attendance_logs WHERE DATE(check_in) = ? AND check_out IS NOT NULL", [$today])['count'] ?? 0;
$activeMembers = Database::fetchOne("SELECT COUNT(*) as count FROM members WHERE status = 'active'")['count'] ?? 0;

// Get members for manual check-in
$members = Database::fetchAll(
    "SELECT id, first_name, last_name, member_code FROM members 
     WHERE status = 'active' AND id NOT IN (SELECT member_id FROM attendance_logs WHERE DATE(check_in) = CURDATE() AND check_out IS NULL)
     ORDER BY first_name"
);

// Get devices
$devices = Database::fetchAll("SELECT * FROM zkteco_devices ORDER BY device_name");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';

ob_start();
?>
<div class="flex items-center gap-2">
    <a href="?sync=device" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors text-sm font-medium">
        <i data-lucide="refresh-cw" class="w-4 h-4"></i> Sync from Device
    </a>
    <a href="reports.php" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
        <i data-lucide="file-text" class="w-4 h-4"></i> Reports
    </a>
</div>
<?php $pageActions = ob_get_clean(); ?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Checked In</p>
                <h3 class="text-2xl font-bold text-blue-700"><?php echo $totalCheckedIn; ?></h3>
            </div>
            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                <i data-lucide="log-in" class="w-5 h-5 text-blue-600"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Still Active</p>
                <h3 class="text-2xl font-bold text-green-700"><?php echo $stillActive; ?></h3>
            </div>
            <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
                <i data-lucide="activity" class="w-5 h-5 text-green-600"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Checked Out</p>
                <h3 class="text-2xl font-bold text-purple-700"><?php echo $checkedOut; ?></h3>
            </div>
            <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
                <i data-lucide="log-out" class="w-5 h-5 text-purple-600"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Attendance Rate</p>
                <h3 class="text-2xl font-bold text-gray-900"><?php echo $activeMembers > 0 ? round(($totalCheckedIn / $activeMembers) * 100) : 0; ?>%</h3>
            </div>
            <div class="w-10 h-10 rounded-lg bg-gray-50 flex items-center justify-center">
                <i data-lucide="percent" class="w-5 h-5 text-gray-600"></i>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Attendance Table -->
    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Attendance Log</h3>
            <form method="GET" class="flex items-center gap-2">
                <div class="relative">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Search member..." 
                           class="pl-9 pr-4 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 w-48">
                </div>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Member</th>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Check In</th>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Check Out</th>
                        <th class="text-center px-5 py-3 font-medium text-gray-500">Duration</th>
                        <th class="text-center px-5 py-3 font-medium text-gray-500">Type</th>
                        <th class="text-center px-5 py-3 font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($attendance as $log): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-semibold">
                                        <?php echo strtoupper(substr($log['first_name'] ?? 'U', 0, 1) . substr($log['last_name'] ?? 'S', 0, 1)); ?>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars(($log['first_name'] ?? 'Unknown') . ' ' . ($log['last_name'] ?? '')); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($log['member_code'] ?? ''); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3"><?php echo Helper::time($log['check_in']); ?></td>
                            <td class="px-5 py-3"><?php echo $log['check_out'] ? Helper::time($log['check_out']) : '<span class="text-green-600 font-medium">Active</span>'; ?></td>
                            <td class="px-5 py-3 text-center">
                                <?php if ($log['duration_minutes']): ?>
                                    <?php echo floor($log['duration_minutes'] / 60) . 'h ' . ($log['duration_minutes'] % 60) . 'm'; ?>
                                <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs capitalize">
                                    <i data-lucide="<?php echo $log['check_type'] === 'fingerprint' ? 'fingerprint' : ($log['check_type'] === 'card' ? 'credit-card' : 'user'); ?>" class="w-3 h-3"></i>
                                    <?php echo $log['check_type'] ?? 'manual'; ?>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <?php if (!$log['check_out']): ?>
                                    <form method="POST" action="" class="inline">
                                        <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                        <input type="hidden" name="checkout" value="1">
                                        <button type="submit" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 text-xs font-medium transition-colors">
                                            Check Out
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">Done</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($attendance)): ?>
                        <tr><td colspan="6" class="px-5 py-12 text-center text-gray-400">
                            <i data-lucide="clipboard-x" class="w-10 h-10 mx-auto mb-2"></i>
                            <p>No attendance records today</p>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Manual Check-in Panel -->
    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i data-lucide="user-plus" class="w-5 h-5 text-blue-500"></i>
                Manual Check-in
            </h3>
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="manual_checkin" value="1">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Member</label>
                    <select name="member_id" required
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Choose member...</option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?php echo $m['id']; ?>">
                                <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_code'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Check Type</label>
                    <select name="check_type"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="manual">Manual Entry</option>
                        <option value="card">Card</option>
                        <option value="face">Face Recognition</option>
                    </select>
                </div>
                <button type="submit" class="w-full py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center justify-center gap-2">
                    <i data-lucide="log-in" class="w-4 h-4"></i> Record Check-in
                </button>
            </form>
        </div>
        
        <!-- Device Status -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i data-lucide="cpu" class="w-5 h-5 text-indigo-500"></i>
                ZKTeco Devices
            </h3>
            <div class="space-y-3">
                <?php foreach ($devices as $device): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center gap-3">
                            <div class="w-2 h-2 rounded-full bg-<?php echo $device['status'] === 'online' ? 'green' : ($device['status'] === 'error' ? 'red' : 'gray'); ?>-500"></div>
                            <div>
                                <p class="font-medium text-sm text-gray-900"><?php echo htmlspecialchars($device['device_name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo $device['device_ip']; ?>:<?php echo $device['port']; ?></p>
                            </div>
                        </div>
                        <span class="text-xs text-gray-400"><?php echo $device['last_sync'] ? Helper::relativeTime($device['last_sync']) : 'Never'; ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($devices)): ?>
                    <div class="text-center py-6 text-gray-400">
                        <p class="text-sm">No devices configured</p>
                        <a href="devices.php" class="text-blue-600 text-xs hover:underline mt-1 inline-block">Add device</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
