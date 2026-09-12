<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('communication', 'view');
$pageTitle = 'Message Logs';
$logs = Database::fetchAll("SELECT ml.*, m.first_name, m.last_name FROM message_logs ml LEFT JOIN members m ON ml.recipient_id = m.id ORDER BY ml.created_at DESC LIMIT 100");
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100"><h3 class="font-semibold text-gray-900">Message Logs</h3></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr><th class="text-left px-5 py-3 font-medium text-gray-500">Recipient</th><th class="text-left px-5 py-3 font-medium text-gray-500">Type</th><th class="text-left px-5 py-3 font-medium text-gray-500">Content</th><th class="text-center px-5 py-3 font-medium text-gray-500">Status</th><th class="text-left px-5 py-3 font-medium text-gray-500">Date</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($logs as $l): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-3"><?php echo htmlspecialchars(($l['first_name']??'Unknown').' '.$l['last_name']??''); ?></td>
                    <td class="px-5 py-3"><span class="px-2 py-0.5 text-xs rounded-full bg-gray-100 uppercase"><?php echo $l['message_type']; ?></span></td>
                    <td class="px-5 py-3"><p class="text-gray-600 truncate max-w-xs"><?php echo htmlspecialchars($l['content']); ?></p></td>
                    <td class="px-5 py-3 text-center"><span class="badge-<?php echo Helper::statusBadge($l['status']); ?> px-2 py-0.5 text-xs rounded-full border"><?php echo ucfirst($l['status']); ?></span></td>
                    <td class="px-5 py-3 text-gray-500 text-xs"><?php echo Helper::datetime($l['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?><tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">No messages sent yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
