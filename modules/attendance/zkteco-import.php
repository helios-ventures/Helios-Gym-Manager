<?php
/**
 * ZKTeco User Import - ENHANCED for Timezone-Based Membership Management
 * Path: /modules/attendance/zkteco-import.php
 * 
 * Features:
 * - Browse all users on ZKTeco device
 * - Show timezone status (ACTIVE vs NO_ACCESS)
 * - Set membership expiry date during import
 * - Auto-assign DEFAULT timezone to imported members
 * - Bulk import with membership details
 * - Mark existing members
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;
use Gym\Core\ZKTeco;

Auth::requirePermission('attendance', 'manage');

$pageTitle = 'Import ZKTeco Users';
$pageDescription = 'Browse and import users from biometric device';

$deviceId = intval($_GET['device_id'] ?? 0);

if (empty($deviceId)) {
    Session::setFlash('danger', 'Please select a device first');
    header('Location: ' . BASE_URL . '/modules/attendance/devices.php');
    exit;
}

// Get device
$device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE id = ?", [$deviceId]);

if (!$device) {
    Session::setFlash('danger', 'Device not found');
    header('Location: ' . BASE_URL . '/modules/attendance/devices.php');
    exit;
}

$deviceUsers = [];
$deviceUserCount = 0;

// ─────────────────────────────────────────────────────────────
// Fetch users from device
// ─────────────────────────────────────────────────────────────

if (isset($_GET['refresh_users']) || empty($_SESSION['zkteco_users_' . $deviceId])) {
    
    try {
        $zk = new ZKTeco($device['device_ip'], (int)$device['port']);
        
        if (!$zk->connect()) {
            Session::setFlash('danger', 'Cannot connect to device at ' . $device['device_ip'] . ':' . $device['port']);
            header('Location: ' . BASE_URL . '/modules/attendance/devices.php');
            exit;
        }
        
        // Get all users from device
        $deviceUsers = $zk->getAllUsers();
        $zk->disconnect();
        
        if (empty($deviceUsers)) {
            Session::setFlash('warning', 'No users found on device');
            $deviceUsers = [];
        } else {
            // Cache in session for performance
            $_SESSION['zkteco_users_' . $deviceId] = $deviceUsers;
            Session::setFlash('success', 'Loaded ' . count($deviceUsers) . ' users from device');
        }
        
    } catch (\Exception $e) {
        Session::setFlash('danger', 'Error fetching users: ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/modules/attendance/devices.php');
        exit;
    }
    
} else {
    $deviceUsers = $_SESSION['zkteco_users_' . $deviceId] ?? [];
}

$deviceUserCount = count($deviceUsers);

// ─────────────────────────────────────────────────────────────
// Handle bulk import with timezone support
// ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_users'])) {
    
    $selectedUserIds = $_POST['selected_users'] ?? [];
    $selectedUserIds = array_filter($selectedUserIds);
    
    // Import options
    $defaultExpiryDays = intval($_POST['default_expiry_days'] ?? 365);
    $setTimezoneActive = isset($_POST['set_timezone_active']) ? 1 : 0;
    
    if (empty($selectedUserIds)) {
        Session::setFlash('warning', 'Please select users to import');
    } else {
        
        $imported = 0;
        $failed = 0;
        $skipped = 0;
        
        // Get ZK instance for timezone assignment if enabled
        $zk = null;
        if ($setTimezoneActive) {
            $zk = new ZKTeco($device['device_ip'], (int)$device['port']);
            if (!$zk->connect()) {
                $zk = null;
            }
        }
        
        foreach ($selectedUserIds as $bioId) {
            $bioId = (int)$bioId;
            
            // Find user in device list
            $deviceUser = null;
            foreach ($deviceUsers as $u) {
                if ($u['uid'] == $bioId) {
                    $deviceUser = $u;
                    break;
                }
            }
            
            if (!$deviceUser) {
                $skipped++;
                continue;
            }
            
            // Check if already exists in system
            $existing = Database::fetchOne(
                "SELECT id FROM members WHERE biometric_id = ?",
                [$bioId]
            );
            
            if ($existing) {
                $skipped++;
                continue;
            }
            
            // Extract name (split from device user name or use defaults)
            $firstName = 'Member';
            $lastName = (string)$bioId;
            
            if (!empty($deviceUser['name'])) {
                // Remove "EXPIRED_" or "USER_" prefixes from device names
                $name = preg_replace('/^(EXPIRED_|USER_)/', '', $deviceUser['name']);
                $nameParts = explode(' ', trim($name), 2);
                $firstName = $nameParts[0];
                $lastName = $nameParts[1] ?? (string)$bioId;
            }
            
            // Generate member code
            $memberCode = 'MEM' . str_pad($bioId, 5, '0', STR_PAD_LEFT);
            
            // Calculate expiry date
            $expiryDate = date('Y-m-d', strtotime("+{$defaultExpiryDays} days"));
            
            try {
                // Insert as new member with timezone support
                Database::insert(
                    "INSERT INTO members 
                     (member_code, first_name, last_name, biometric_id, phone, email, join_date, expiry_date, status)
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, 'active')",
                    [
                        $memberCode,
                        $firstName,
                        $lastName,
                        $bioId,
                        '',      // No phone initially
                        '',      // No email initially
                        $expiryDate
                    ]
                );
                
                // ⭐ Set timezone to DEFAULT if requested
                if ($zk && $setTimezoneActive) {
                    $zk->setUserTimeZone($bioId, ZKTeco::TIMEZONE_DEFAULT);
                }
                
                $imported++;
                
            } catch (\Exception $e) {
                error_log("Import error for user $bioId: " . $e->getMessage());
                $failed++;
            }
        }
        
        // Disconnect ZK
        if ($zk) {
            $zk->disconnect();
        }
        
        $message = "✅ Imported $imported users successfully";
        if ($skipped > 0) {
            $message .= " ($skipped already existed)";
        }
        if ($failed > 0) {
            $message .= " ($failed failed)";
        }
        if ($setTimezoneActive && $imported > 0) {
            $message .= " | Timezone set to DEFAULT (1) for all imports";
        }
        
        Session::setFlash('success', $message);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────
// Get existing members from system
// ─────────────────────────────────────────────────────────────

$systemMembers = [];
$systemBioIds = Database::fetchAll(
    "SELECT id, biometric_id FROM members WHERE biometric_id IS NOT NULL"
);

foreach ($systemBioIds as $m) {
    $systemMembers[$m['biometric_id']] = $m['id'];
}

// ─────────────────────────────────────────────────────────────
// Calculate timezone statistics
// ─────────────────────────────────────────────────────────────

$timezoneStats = [
    'active' => 0,      // TIMEZONE_DEFAULT (1)
    'no_access' => 0,   // TIMEZONE_NO_ACCESS (2)
];

foreach ($deviceUsers as $u) {
    $tz = $u['timezone'] ?? ZKTeco::TIMEZONE_DEFAULT;
    if ($tz == ZKTeco::TIMEZONE_NO_ACCESS) {
        $timezoneStats['no_access']++;
    } else {
        $timezoneStats['active']++;
    }
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="space-y-6">
    
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <p class="text-gray-500 mt-1">
                Users on <strong><?php echo htmlspecialchars($device['device_name']); ?> Biometric</strong>
                / IP <?php echo $device['device_ip']; ?>:<?php echo $device['port']; ?>
            </p>
        </div>
        <a href="devices.php" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">
            ← Back to Devices
        </a>
    </div>
    
    <!-- Stats - ENHANCED with timezone info -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
            <p class="text-sm text-blue-700">Total Users on Device</p>
            <p class="text-3xl font-bold text-blue-900"><?php echo $deviceUserCount; ?></p>
        </div>
        <div class="bg-green-50 p-4 rounded-lg border border-green-200">
            <p class="text-sm text-green-700">Active (Timezone 1)</p>
            <p class="text-3xl font-bold text-green-900"><?php echo $timezoneStats['active']; ?></p>
        </div>
        <div class="bg-red-50 p-4 rounded-lg border border-red-200">
            <p class="text-sm text-red-700">No Access (Timezone 2)</p>
            <p class="text-3xl font-bold text-red-900"><?php echo $timezoneStats['no_access']; ?></p>
        </div>
        <div class="bg-amber-50 p-4 rounded-lg border border-amber-200">
            <p class="text-sm text-amber-700">Already in System</p>
            <p class="text-3xl font-bold text-amber-900">
                <?php 
                $alreadyImported = 0;
                foreach ($deviceUsers as $u) {
                    if (isset($systemMembers[$u['uid']])) {
                        $alreadyImported++;
                    }
                }
                echo $alreadyImported;
                ?>
            </p>
        </div>
    </div>
    
    <!-- Toolbar -->
    <div class="flex items-center justify-between gap-4">
        <div class="flex gap-2">
            <a href="?device_id=<?php echo $deviceId; ?>&refresh_users=1" 
               class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2">
                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                Refresh from Device
            </a>
        </div>
    </div>
    
    <!-- Users Table - ENHANCED with timezone column -->
    <form method="POST" action="" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-bold text-gray-900">ZKTeco Device Users</h2>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" id="selectAll" class="w-4 h-4 rounded">
                <span class="text-gray-600">Select All</span>
            </label>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-6 py-3 font-medium text-gray-500 w-12">
                            <input type="checkbox" class="w-4 h-4 rounded">
                        </th>
                        <th class="text-left px-6 py-3 font-medium text-gray-500">Biometric ID</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-500">Name</th>
                        <th class="text-center px-6 py-3 font-medium text-gray-500">Check Type</th>
                        <th class="text-center px-6 py-3 font-medium text-gray-500">Timezone Status</th>
                        <th class="text-center px-6 py-3 font-medium text-gray-500">In System</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($deviceUsers)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-400">
                                No users found on device
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deviceUsers as $user): ?>
                            <?php 
                            $isImported = isset($systemMembers[$user['uid']]);
                            $userTimezone = $user['timezone'] ?? ZKTeco::TIMEZONE_DEFAULT;
                            $isNoAccess = $userTimezone == ZKTeco::TIMEZONE_NO_ACCESS;
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors <?php echo $isImported ? 'bg-gray-50' : ''; ?> <?php echo $isNoAccess ? 'border-l-4 border-red-500' : ''; ?>">
                                <td class="px-6 py-4">
                                    <input type="checkbox" 
                                           name="selected_users[]" 
                                           value="<?php echo $user['uid']; ?>"
                                           class="w-4 h-4 rounded select-user"
                                           <?php echo $isImported ? 'disabled' : ''; ?>>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono font-bold text-gray-900"><?php echo $user['uid']; ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($user['name'] ?? "User {$user['uid']}"); ?></p>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php 
                                    $checkType = 'Fingerprint';
                                    if ($user['type'] ?? false) {
                                        if ($user['type'] == 1 || $user['type'] == 2) {
                                            $checkType = 'Card';
                                        } elseif ($user['type'] == 3) {
                                            $checkType = 'Face';
                                        }
                                    }
                                    ?>
                                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">
                                        <?php echo $checkType; ?>
                                    </span>
                                </td>
                                <!-- ⭐ NEW: Timezone Status Column -->
                                <td class="px-6 py-4 text-center">
                                    <?php if ($isNoAccess): ?>
                                        <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded font-medium">
                                            🚫 NO ACCESS (TZ 2)
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded font-medium">
                                            ✓ ACTIVE (TZ 1)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php if ($isImported): ?>
                                        <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded font-medium">
                                            ✓ Already imported
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-500">Available</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Action Bar - ENHANCED with import options -->
        <?php if (!empty($deviceUsers)): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                
                <!-- Import Options -->
                <div class="mb-4 p-4 bg-white rounded-lg border border-gray-200 space-y-3">
                    <h4 class="font-medium text-gray-900">Import Options</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Expiry Date Option -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Default Membership Duration
                            </label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="default_expiry_days" value="365" min="1" max="1460"
                                       class="w-20 px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <span class="text-sm text-gray-600">days</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Set when imported members' memberships expire</p>
                        </div>
                        
                        <!-- ⭐ NEW: Timezone Option -->
                        <div>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="set_timezone_active" value="1" checked
                                       class="w-4 h-4 rounded">
                                <span class="text-sm font-medium text-gray-700">Set timezone to ACTIVE (1)</span>
                            </label>
                            <p class="text-xs text-gray-500 mt-1">Imported members will be able to access immediately</p>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">
                        <span id="selectedCount">0</span> user(s) selected
                    </p>
                    <button type="submit" name="import_users" value="1"
                            class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium flex items-center gap-2"
                            id="importBtn"
                            disabled>
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Import Selected Users
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </form>
    
    <!-- Info Card - ENHANCED with timezone info -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="font-semibold text-blue-900 mb-2">ℹ️ How it Works</h3>
        <ul class="text-sm text-blue-800 space-y-1">
            <li>✓ Select users from the list above</li>
            <li>✓ Set default membership duration (default: 365 days)</li>
            <li>✓ Check "Set timezone to ACTIVE" to allow immediate access</li>
            <li>✓ Click "Import Selected Users" to add them to the system</li>
            <li>✓ Users marked "NO ACCESS (TZ 2)" are expired and won't have access until renewed</li>
            <li>✓ New imported members will have status "Active" with biometric ID linked</li>
            <li>✓ You can then edit their details (phone, email, membership plan, etc.)</li>
        </ul>
    </div>
    
    <!-- Legend -->
    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
        <h4 class="font-semibold text-gray-900 mb-2">Timezone Status Legend</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div class="flex items-center gap-3">
                <span class="inline-block px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium">✓ ACTIVE (TZ 1)</span>
                <span class="text-gray-600">User can access the device</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-block px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium">🚫 NO ACCESS (TZ 2)</span>
                <span class="text-gray-600">User cannot access (membership expired/disabled)</span>
            </div>
        </div>
    </div>
</div>

<script>
// Select all checkbox
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('input[name="selected_users[]"]');
    checkboxes.forEach(cb => {
        if (!cb.disabled) {
            cb.checked = this.checked;
        }
    });
    updateSelectedCount();
});

// Individual checkboxes
document.querySelectorAll('input[name="selected_users[]"]').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

// Update count and button state
function updateSelectedCount() {
    const selected = document.querySelectorAll('input[name="selected_users[]"]:checked').length;
    document.getElementById('selectedCount').textContent = selected;
    document.getElementById('importBtn').disabled = selected === 0;
}

// Check if all checkboxes should be checked
function updateSelectAllState() {
    const checkboxes = document.querySelectorAll('input[name="selected_users[]"]:not(:disabled)');
    const checked = document.querySelectorAll('input[name="selected_users[]"]:not(:disabled):checked');
    document.getElementById('selectAll').checked = checkboxes.length > 0 && checkboxes.length === checked.length;
}

// Initial state
updateSelectedCount();
updateSelectAllState();
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>