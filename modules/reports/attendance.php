<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('reports', 'view');
$pageTitle = 'Attendance Reports';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$stats = Database::fetchAll("SELECT DATE(check_in) as date, COUNT(DISTINCT member_id) as unique_members, COUNT(*) as total_checks FROM attendance_logs WHERE check_in BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) GROUP BY DATE(check_in) ORDER BY date", [$dateFrom, $dateTo]);
$totalVisits = array_sum(array_column($stats, 'total_checks'));
$avgDaily = count($stats) > 0 ? round($totalVisits / count($stats), 1) : 0;
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
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center"><p class="text-sm text-gray-500">Total Visits</p><h3 class="text-2xl font-bold text-blue-700"><?php echo number_format($totalVisits); ?></h3></div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center"><p class="text-sm text-gray-500">Avg Daily</p><h3 class="text-2xl font-bold text-green-700"><?php echo $avgDaily; ?></h3></div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center"><p class="text-sm text-gray-500">Active Days</p><h3 class="text-2xl font-bold text-purple-700"><?php echo count($stats); ?></h3></div>
</div>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="font-semibold text-gray-900 mb-4">Daily Attendance</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr><th class="text-left px-5 py-3 font-medium text-gray-500">Date</th><th class="text-right px-5 py-3 font-medium text-gray-500">Unique Members</th><th class="text-right px-5 py-3 font-medium text-gray-500">Total Check-ins</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($stats as $s): ?>
                <tr class="hover:bg-gray-50"><td class="px-5 py-3"><?php echo Helper::date($s['date']); ?></td><td class="px-5 py-3 text-right"><?php echo number_format($s['unique_members']); ?></td><td class="px-5 py-3 text-right font-medium"><?php echo number_format($s['total_checks']); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($stats)): ?><tr><td colspan="3" class="px-5 py-8 text-center text-gray-400">No attendance data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
