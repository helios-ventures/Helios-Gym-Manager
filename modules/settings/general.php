<?php
/**
 * General Settings - System Administration Module
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;

Auth::requirePermission('settings', 'view');

$pageTitle = 'General Settings';
$pageDescription = 'Configure gym information and system settings';

// Only admin can save
$canEdit = Auth::isAdmin();

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'setting_') === 0) {
            $settingKey = substr($key, 8);
            Database::execute(
                "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                 ON DUPLICATE KEY UPDATE setting_value = ?",
                [$settingKey, $value, $value]
            );
        }
    }
    Session::setFlash('success', 'Settings saved successfully');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Get all settings
$settings = Auth::getSettings();

// Group settings
$groups = [
    'general' => ['icon' => 'building-2', 'title' => 'Gym Information', 'description' => 'Basic gym details'],
    'financial' => ['icon' => 'banknote', 'title' => 'Financial Settings', 'description' => 'Tax and currency settings'],
    'communication' => ['icon' => 'message-circle', 'title' => 'Communication', 'description' => 'SMS, email, and WhatsApp settings'],
    'attendance' => ['icon' => 'clipboard-check', 'title' => 'Attendance', 'description' => 'Attendance tracking settings'],
    'pos' => ['icon' => 'shopping-cart', 'title' => 'Point of Sale', 'description' => 'Receipt and POS settings'],
    'security' => ['icon' => 'shield', 'title' => 'Security', 'description' => 'Password and session settings'],
];

// Get settings by group
$groupedSettings = [];
foreach ($groups as $groupKey => $groupInfo) {
    $groupedSettings[$groupKey] = Database::fetchAll(
        "SELECT * FROM system_settings WHERE setting_group = ? ORDER BY setting_key",
        [$groupKey]
    );
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<?php if (!$canEdit): ?>
<div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-lg mb-6">
    <p class="text-yellow-700 text-sm">You are viewing settings in read-only mode. Contact an administrator to make changes.</p>
</div>
<?php endif; ?>

<form method="POST" action="" class="space-y-6">
    
    <?php foreach ($groups as $groupKey => $groupInfo): ?>
        <?php if (!empty($groupedSettings[$groupKey])): ?>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                        <i data-lucide="<?php echo $groupInfo['icon']; ?>" class="w-5 h-5 text-blue-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900"><?php echo $groupInfo['title']; ?></h3>
                        <p class="text-xs text-gray-500"><?php echo $groupInfo['description']; ?></p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($groupedSettings[$groupKey] as $setting): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <?php echo ucwords(str_replace('_', ' ', $setting['setting_key'])); ?>
                            </label>
                            
                            <?php if (strlen($setting['setting_value'] ?? '') > 50): ?>
                                <textarea name="setting_<?php echo $setting['setting_key']; ?>" rows="2"
                                          <?php echo !$canEdit ? 'readonly' : ''; ?>
                                          class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 <?php echo !$canEdit ? 'bg-gray-50' : ''; ?>"><?php echo htmlspecialchars($setting['setting_value'] ?? ''); ?></textarea>
                            <?php elseif (strpos($setting['setting_key'], 'enabled') !== false || strpos($setting['setting_key'], 'auto') !== false): ?>
                                <select name="setting_<?php echo $setting['setting_key']; ?>"
                                        <?php echo !$canEdit ? 'disabled' : ''; ?>
                                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 <?php echo !$canEdit ? 'bg-gray-50' : ''; ?>">
                                    <option value="1" <?php echo ($setting['setting_value'] ?? '') === '1' ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="0" <?php echo ($setting['setting_value'] ?? '') === '0' ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            <?php else: ?>
                                <input type="text" name="setting_<?php echo $setting['setting_key']; ?>"
                                       value="<?php echo htmlspecialchars($setting['setting_value'] ?? ''); ?>"
                                       <?php echo !$canEdit ? 'readonly' : ''; ?>
                                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 <?php echo !$canEdit ? 'bg-gray-50' : ''; ?>">
                            <?php endif; ?>
                            
                            <?php if ($setting['description']): ?>
                                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($setting['description']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
    
    <?php if ($canEdit): ?>
        <div class="flex items-center justify-end">
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center gap-2">
                <i data-lucide="save" class="w-4 h-4"></i> Save All Settings
            </button>
        </div>
    <?php endif; ?>
</form>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
