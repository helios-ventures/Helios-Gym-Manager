<?php
/**
 * Reports Module - Member Reports with export functionality
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Response;

Auth::requirePermission('reports', 'view');

$pageTitle = 'Member Reports';
$pageDescription = 'View, download and export member reports';

// Date range
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$planId = $_GET['plan_id'] ?? '';
$export = $_GET['export'] ?? '';

$params = [];
$where = ["m.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$params[] = $dateFrom;
$params[] = $dateTo;

if ($status) {
    $where[] = "m.status = ?";
    $params[] = $status;
}
if ($planId) {
    $where[] = "m.id IN (SELECT member_id FROM member_subscriptions WHERE plan_id = ? AND status = 'active')";
    $params[] = $planId;
}

$whereClause = implode(' AND ', $where);

// Summary stats
$newMembers = Database::fetchOne("SELECT COUNT(*) as count FROM members m WHERE {$whereClause}", $params)['count'] ?? 0;
$activeMembers = Database::fetchOne("SELECT COUNT(*) as count FROM members WHERE status = 'active'")['count'] ?? 0;
$expiredMembers = Database::fetchOne("SELECT COUNT(*) as count FROM members WHERE status = 'expired'")['count'] ?? 0;

// Get report data
$reportData = Database::fetchAll(
    "SELECT m.member_code, m.first_name, m.last_name, m.phone, m.email, m.gender, m.join_date, 
            m.expiry_date, m.status, m.fitness_goal, m.current_weight_kg, m.height_cm,
            p.name as plan_name, ms.end_date as sub_end
     FROM members m
     LEFT JOIN member_subscriptions ms ON m.id = ms.member_id AND ms.status = 'active'
     LEFT JOIN subscription_plans p ON ms.plan_id = p.id
     WHERE {$whereClause}
     ORDER BY m.created_at DESC",
    $params
);

// Plans for filter
$plans = Database::fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY name");

// Export handlers
if ($export === 'csv') {
    $csvData = array_map(fn($m) => [
        'Member Code' => $m['member_code'],
        'Name' => $m['first_name'] . ' ' . $m['last_name'],
        'Phone' => $m['phone'],
        'Email' => $m['email'],
        'Gender' => ucfirst($m['gender'] ?? ''),
        'Join Date' => $m['join_date'],
        'Expiry Date' => $m['expiry_date'] ?? '',
        'Status' => ucfirst($m['status']),
        'Plan' => $m['plan_name'] ?? 'None',
        'Fitness Goal' => ucfirst(str_replace('_', ' ', $m['fitness_goal'] ?? '')),
        'Weight (kg)' => $m['current_weight_kg'] ?? '',
    ], $reportData);
    Response::csv($csvData, 'member_report_' . $dateFrom . '_to_' . $dateTo . '.csv');
}

if ($export === 'print') {
    Response::printTable($reportData, 'Member Report (' . Helper::date($dateFrom) . ' - ' . Helper::date($dateTo) . ')');
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';

ob_start();
?>
<div class="flex items-center gap-2">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
        <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> CSV
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'print'])); ?>" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm font-medium">
        <i data-lucide="printer" class="w-4 h-4"></i> Print
    </a>
</div>
<?php $pageActions = ob_get_clean(); ?>

<!-- Filters -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
            <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" 
                   class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
            <input type="date" name="date_to" value="<?php echo $dateTo; ?>" 
                   class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="">All</option>
                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
            <select name="plan_id" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="">All Plans</option>
                <?php foreach ($plans as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $planId == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">
            <i data-lucide="filter" class="w-4 h-4 inline mr-1"></i> Generate
        </button>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500 mb-1">New Members</p>
        <h3 class="text-3xl font-bold text-blue-700"><?php echo number_format($newMembers); ?></h3>
        <p class="text-xs text-gray-400 mt-1"><?php echo Helper::date($dateFrom); ?> - <?php echo Helper::date($dateTo); ?></p>
    </div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500 mb-1">Active Members</p>
        <h3 class="text-3xl font-bold text-green-700"><?php echo number_format($activeMembers); ?></h3>
        <p class="text-xs text-gray-400 mt-1">Currently active</p>
    </div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500 mb-1">Expired</p>
        <h3 class="text-3xl font-bold text-red-700"><?php echo number_format($expiredMembers); ?></h3>
        <p class="text-xs text-gray-400 mt-1">Need renewal</p>
    </div>
</div>

<!-- Data Table -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Member</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Contact</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Plan</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Status</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Join Date</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Expiry</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($reportData as $row): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($row['member_code']); ?></p>
                        </td>
                        <td class="px-5 py-3">
                            <p class="text-gray-600"><?php echo htmlspecialchars($row['phone']); ?></p>
                        </td>
                        <td class="px-5 py-3"><?php echo htmlspecialchars($row['plan_name'] ?? 'None'); ?></td>
                        <td class="px-5 py-3 text-center">
                            <span class="badge-<?php echo Helper::statusBadge($row['status']); ?> px-2 py-0.5 text-xs rounded-full border">
                                <?php echo ucfirst($row['status']); ?>
                            </span>
                        </td>
                        <td class="px-5 py-3"><?php echo Helper::date($row['join_date']); ?></td>
                        <td class="px-5 py-3"><?php echo $row['expiry_date'] ? Helper::date($row['expiry_date']) : 'N/A'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($reportData)): ?>
                    <tr><td colspan="6" class="px-5 py-12 text-center text-gray-400">No data found for selected period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
