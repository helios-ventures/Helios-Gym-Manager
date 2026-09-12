<?php
/**
 * Invoices Listing
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;

Auth::requirePermission('pos', 'view');

$pageTitle = 'Invoices';
$pageDescription = 'Browse all invoices';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$search = $_GET['search'] ?? '';

$params = [];
$where = "1=1";

if ($search) {
    $where .= " AND (s.invoice_number LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)";
    $s = "%{$search}%";
    $params = [$s, $s, $s];
}

$total = Database::fetchOne("SELECT COUNT(*) as count FROM sales s LEFT JOIN members m ON s.member_id=m.id WHERE {$where}", $params)['count'] ?? 0;
$offset = ($page - 1) * $perPage;

$sales = Database::fetchAll(
    "SELECT s.*, m.first_name, m.last_name FROM sales s LEFT JOIN members m ON s.member_id=m.id WHERE {$where} ORDER BY s.created_at DESC LIMIT {$offset}, {$perPage}",
    $params
);

$totalPages = (int) ceil($total / $perPage);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex items-center gap-4">
        <div class="flex-1 max-w-md relative">
            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search invoice or customer..."
                   class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Search</button>
    </form>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Invoice #</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Customer</th>
                    <th class="text-right px-5 py-3 font-medium text-gray-500">Amount</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Method</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Status</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Date</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($sales as $sale): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-5 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                    <td class="px-5 py-3"><?php echo htmlspecialchars($sale['first_name'] ? $sale['first_name'].' '.$sale['last_name'] : ($sale['customer_name'] ?? 'Walk-in')); ?></td>
                    <td class="px-5 py-3 text-right font-medium"><?php echo Helper::money($sale['total_amount']); ?></td>
                    <td class="px-5 py-3 text-center"><span class="capitalize text-xs bg-gray-100 px-2 py-1 rounded"><?php echo $sale['payment_method']; ?></span></td>
                    <td class="px-5 py-3 text-center"><span class="badge-<?php echo Helper::statusBadge($sale['payment_status']); ?> px-2 py-0.5 text-xs rounded-full border"><?php echo ucfirst($sale['payment_status']); ?></span></td>
                    <td class="px-5 py-3 text-gray-500"><?php echo Helper::datetime($sale['created_at']); ?></td>
                    <td class="px-5 py-3 text-center"><a href="invoice.php?id=<?php echo $sale['id']; ?>" class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 text-xs font-medium">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sales)): ?><tr><td colspan="7" class="px-5 py-12 text-center text-gray-400">No invoices found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-5 py-4 border-t border-gray-100">
        <p class="text-sm text-gray-500">Page <?php echo $page; ?> of <?php echo $totalPages; ?></p>
        <div class="flex gap-1">
            <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1.5 bg-gray-100 rounded-lg text-sm hover:bg-gray-200">Previous</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1.5 bg-gray-100 rounded-lg text-sm hover:bg-gray-200">Next</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
