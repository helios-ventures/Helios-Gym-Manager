<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('communication', 'view');
$pageTitle = 'Message Templates';
$templates = Database::fetchAll("SELECT * FROM message_templates ORDER BY created_at DESC");
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900">Message Templates</h3>
        <a href="send.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">New Message</a>
    </div>
    <div class="divide-y divide-gray-100">
        <?php foreach ($templates as $t): ?>
        <div class="px-5 py-4 hover:bg-gray-50 transition-colors">
            <div class="flex items-center justify-between mb-2">
                <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($t['name']); ?></h4>
                <span class="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-600 uppercase"><?php echo $t['type']; ?></span>
            </div>
            <p class="text-sm text-gray-600 line-clamp-2"><?php echo htmlspecialchars($t['content']); ?></p>
            <?php if ($t['variables']): ?><p class="text-xs text-gray-400 mt-1">Variables: <?php echo str_replace(['"','[',']'], '', $t['variables']); ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($templates)): ?><div class="px-5 py-8 text-center text-gray-400">No templates</div><?php endif; ?>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
