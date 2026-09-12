<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('reports', 'view');
$pageTitle = 'Financial Reports';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$revenue = Database::fetchOne("SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) AND payment_status='paid'", [$dateFrom, $dateTo])['total'] ?? 0;
$subscriptions = Database::fetchOne("SELECT COALESCE(SUM(amount_paid), 0) as total FROM member_subscriptions WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)", [$dateFrom, $dateTo])['total'] ?? 0;
$productSales = Database::fetchOne("SELECT COALESCE(SUM(si.total_price), 0) as total FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE s.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) AND si.product_id IS NOT NULL AND s.payment_status='paid'", [$dateFrom, $dateTo])['total'] ?? 0;
$monthly = Database::fetchAll("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE payment_status='paid' GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month DESC LIMIT 12");
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">From</label><input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">To</label><input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm"></div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Generate</button>
    </form>
</div>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center"><p class="text-sm text-gray-500">Total Revenue</p><h3 class="text-2xl font-bold text-green-700"><?php echo Helper::money($revenue); ?></h3></div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center"><p class="text-sm text-gray-500">Subscription Revenue</p><h3 class="text-2xl font-bold text-blue-700"><?php echo Helper::money($subscriptions); ?></h3></div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center"><p class="text-sm text-gray-500">Product Sales</p><h3 class="text-2xl font-bold text-purple-700"><?php echo Helper::money($productSales); ?></h3></div>
</div>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="font-semibold text-gray-900 mb-4">Monthly Revenue</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr><th class="text-left px-5 py-3 font-medium text-gray-500">Month</th><th class="text-right px-5 py-3 font-medium text-gray-500">Revenue</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($monthly as $m): ?><tr class="hover:bg-gray-50"><td class="px-5 py-3"><?php echo $m['month']; ?></td><td class="px-5 py-3 text-right font-medium"><?php echo Helper::money($m['total']); ?></td></tr><?php endforeach; ?>
                <?php if (empty($monthly)): ?><tr><td colspan="2" class="px-5 py-8 text-center text-gray-400">No data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
