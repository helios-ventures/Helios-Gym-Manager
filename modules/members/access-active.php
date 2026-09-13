<?php
/**
 * Active Members - members who currently have biometric access enabled
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;

Auth::requirePermission('members', 'view');

$pageTitle = 'Active Members';
$pageDescription = 'Members who currently have biometric access to the gym';

$search = $_GET['search'] ?? '';

$params = [];
$where = ["m.biometric_enabled = 1"];

if ($search) {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ?)";
    $s = "%{$search}%";
    $params = [$s, $s, $s];
}
$whereClause = implode(' AND ', $where);

$members = Database::fetchAll(
    "SELECT m.*, p.name as plan_name
     FROM members m
     LEFT JOIN member_subscriptions ms ON m.id = ms.member_id AND ms.status = 'active'
     LEFT JOIN subscription_plans p ON ms.plan_id = p.id
     WHERE {$whereClause}
     ORDER BY m.first_name
     LIMIT 300",
    $params
);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex items-center gap-3">
        <div class="relative flex-1 max-w-sm">
            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search member..."
                   class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Search</button>
    </form>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900">Members with Access Enabled</h3>
        <span class="text-sm text-gray-500"><?php echo count($members); ?> member(s)</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Member</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Plan</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Expiry</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Last Synced</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Status</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($members as $m): ?>
                    <?php $daysUntil = $m['expiry_date'] ? Helper::daysUntil($m['expiry_date']) : null; ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($m['member_code']); ?></p>
                        </td>
                        <td class="px-5 py-3"><?php echo htmlspecialchars($m['plan_name'] ?? 'No plan'); ?></td>
                        <td class="px-5 py-3">
                            <?php echo $m['expiry_date'] ? Helper::date($m['expiry_date']) : 'N/A'; ?>
                            <?php if ($daysUntil !== null && $daysUntil <= 7 && $daysUntil >= 0): ?>
                                <span class="block text-xs text-amber-600"><?php echo $daysUntil; ?> days left</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-gray-500 text-xs">
                            <?php echo $m['biometric_synced_at'] ? Helper::relativeTime($m['biometric_synced_at']) : 'Never'; ?>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="badge-<?php echo Helper::statusBadge($m['status']); ?> px-2 py-0.5 text-xs rounded-full border"><?php echo ucfirst($m['status']); ?></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <a href="view.php?id=<?php echo $m['id']; ?>" class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 text-xs font-medium">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                    <tr><td colspan="6" class="px-5 py-10 text-center text-gray-400">No members with active access found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
