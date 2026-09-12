<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('members', 'view');
$pageTitle = 'Subscriptions';
$subs = Database::fetchAll("SELECT ms.*, m.first_name, m.last_name, m.member_code, p.name as plan_name 
    FROM member_subscriptions ms 
    JOIN members m ON ms.member_id = m.id 
    JOIN subscription_plans p ON ms.plan_id = p.id 
    ORDER BY ms.created_at DESC LIMIT 100");
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100"><h3 class="font-semibold text-gray-900">All Subscriptions</h3></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr><th class="text-left px-5 py-3 font-medium text-gray-500">Member</th><th class="text-left px-5 py-3 font-medium text-gray-500">Plan</th><th class="text-left px-5 py-3 font-medium text-gray-500">Period</th><th class="text-right px-5 py-3 font-medium text-gray-500">Amount</th><th class="text-center px-5 py-3 font-medium text-gray-500">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($subs as $s): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-3"><p class="font-medium text-gray-900"><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></p><p class="text-xs text-gray-500"><?php echo $s['member_code']; ?></p></td>
                    <td class="px-5 py-3"><?php echo htmlspecialchars($s['plan_name']); ?></td>
                    <td class="px-5 py-3 text-gray-500"><?php echo Helper::date($s['start_date']); ?> - <?php echo Helper::date($s['end_date']); ?></td>
                    <td class="px-5 py-3 text-right font-medium"><?php echo Helper::money($s['amount_paid']); ?></td>
                    <td class="px-5 py-3 text-center"><span class="badge-<?php echo \Gym\Core\Helper::statusBadge($s['status']); ?> px-2 py-0.5 text-xs rounded-full border"><?php echo ucfirst($s['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($subs)): ?><tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">No subscriptions found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
