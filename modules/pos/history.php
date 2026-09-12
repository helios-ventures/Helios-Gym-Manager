<?php
/**
 * Sales History - POS Module
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;

Auth::requirePermission('pos', 'view');

$pageTitle = 'Sales History';
$pageDescription = 'View all sales transactions';

$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$params = [$dateFrom, $dateTo];
$where = "s.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";

if ($search) {
    $where .= " AND (s.invoice_number LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)";
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s]);
}

$sales = Database::fetchAll(
    "SELECT s.*, m.first_name, m.last_name, u.first_name as seller_name 
     FROM sales s 
     LEFT JOIN members m ON s.member_id = m.id 
     LEFT JOIN users u ON s.sold_by = u.id 
     WHERE {$where} 
     ORDER BY s.created_at DESC LIMIT 100",
    $params
);

$totalSales = Database::fetchOne(
    "SELECT COALESCE(SUM(total_amount), 0) as total FROM sales s WHERE {$where}",
    $params
)['total'] ?? 0;

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">From</label>
            <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">To</label>
            <input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Invoice or customer..."
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Filter</button>
    </form>
</div>

<div class="bg-blue-50 rounded-xl p-5 mb-6 flex items-center justify-between">
    <div>
        <p class="text-sm text-blue-600">Total Sales</p>
        <h3 class="text-2xl font-bold text-blue-900"><?php echo Helper::money($totalSales); ?></h3>
    </div>
    <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center">
        <i data-lucide="trending-up" class="w-6 h-6 text-blue-600"></i>
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Invoice</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Customer</th>
                    <th class="text-right px-5 py-3 font-medium text-gray-500">Amount</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Payment</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Status</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Date</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Seller</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($sales as $sale): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-5 py-3 font-medium"><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                    <td class="px-5 py-3"><?php echo htmlspecialchars($sale['first_name'] ? $sale['first_name'].' '.$sale['last_name'] : ($sale['customer_name'] ?? 'Walk-in')); ?></td>
                    <td class="px-5 py-3 text-right font-medium"><?php echo Helper::money($sale['total_amount']); ?></td>
                    <td class="px-5 py-3 text-center"><span class="capitalize text-xs bg-gray-100 px-2 py-1 rounded"><?php echo $sale['payment_method']; ?></span></td>
                    <td class="px-5 py-3 text-center">
                        <span class="badge-<?php echo Helper::statusBadge($sale['payment_status']); ?> px-2 py-0.5 text-xs rounded-full border"><?php echo ucfirst($sale['payment_status']); ?></span>
                    </td>
                    <td class="px-5 py-3 text-gray-500"><?php echo Helper::datetime($sale['created_at']); ?></td>
                    <td class="px-5 py-3 text-gray-500"><?php echo htmlspecialchars($sale['seller_name'] ?? 'System'); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sales)): ?>
                <tr><td colspan="7" class="px-5 py-12 text-center text-gray-400">No sales found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
