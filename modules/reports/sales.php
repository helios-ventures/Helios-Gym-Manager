<?php
/**
 * Sales Reports with charts and export
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Response;

Auth::requirePermission('reports', 'view');

$pageTitle = 'Sales Reports';
$pageDescription = 'Sales analytics and reporting';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$export = $_GET['export'] ?? '';

// Summary data
$totalRevenue = Database::fetchOne(
    "SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) AND payment_status = 'paid'",
    [$dateFrom, $dateTo]
)['total'] ?? 0;

$totalTransactions = Database::fetchOne(
    "SELECT COUNT(*) as count FROM sales WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)",
    [$dateFrom, $dateTo]
)['count'] ?? 0;

$avgTransaction = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;

// Daily sales for chart
$dailySales = Database::fetchAll(
    "SELECT DATE(created_at) as date, COALESCE(SUM(total_amount), 0) as total, COUNT(*) as count 
     FROM sales 
     WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) AND payment_status = 'paid'
     GROUP BY DATE(created_at) ORDER BY date",
    [$dateFrom, $dateTo]
);

// Payment method breakdown
$paymentMethods = Database::fetchAll(
    "SELECT payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total 
     FROM sales 
     WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) AND payment_status = 'paid'
     GROUP BY payment_method",
    [$dateFrom, $dateTo]
);

// Top products
$topProducts = Database::fetchAll(
    "SELECT si.item_name, SUM(si.quantity) as qty, SUM(si.total_price) as revenue
     FROM sale_items si 
     JOIN sales s ON si.sale_id = s.id 
     WHERE s.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) AND s.payment_status = 'paid'
     GROUP BY si.item_name ORDER BY revenue DESC LIMIT 10",
    [$dateFrom, $dateTo]
);

// Report data for export
$reportData = Database::fetchAll(
    "SELECT s.invoice_number, s.customer_name, s.total_amount, s.payment_method, s.payment_status, s.created_at
     FROM sales s 
     WHERE s.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
     ORDER BY s.created_at DESC",
    [$dateFrom, $dateTo]
);

if ($export === 'csv') {
    $csv = array_map(fn($r) => [
        'Invoice' => $r['invoice_number'],
        'Customer' => $r['customer_name'] ?? 'Walk-in',
        'Amount' => $r['total_amount'],
        'Payment Method' => $r['payment_method'],
        'Status' => $r['payment_status'],
        'Date' => $r['created_at'],
    ], $reportData);
    Response::csv($csv, 'sales_report_' . $dateFrom . '_to_' . $dateTo . '.csv');
}

if ($export === 'print') Response::printTable($reportData, 'Sales Report');

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';

ob_start();
?>
<a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
    <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> CSV
</a>
<?php $pageActions = ob_get_clean(); ?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">From</label>
            <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">To</label>
            <input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Generate</button>
    </form>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500 mb-1">Total Revenue</p>
        <h3 class="text-2xl font-bold text-green-700"><?php echo Helper::money($totalRevenue); ?></h3>
    </div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500 mb-1">Transactions</p>
        <h3 class="text-2xl font-bold text-blue-700"><?php echo number_format($totalTransactions); ?></h3>
    </div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500 mb-1">Average Sale</p>
        <h3 class="text-2xl font-bold text-purple-700"><?php echo Helper::money($avgTransaction); ?></h3>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="font-semibold text-gray-900 mb-4">Daily Sales Trend</h3>
        <div class="relative h-72">
            <canvas id="salesChart"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="font-semibold text-gray-900 mb-4">Payment Methods</h3>
        <div class="relative h-72">
           <canvas id="paymentChart"></canvas>
        </div>
        
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="font-semibold text-gray-900 mb-4">Top Selling Items</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-500">Item</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-500">Quantity</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-500">Revenue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($topProducts as $p): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium"><?php echo htmlspecialchars($p['item_name']); ?></td>
                    <td class="px-4 py-3 text-right"><?php echo number_format($p['qty']); ?></td>
                    <td class="px-4 py-3 text-right font-medium"><?php echo Helper::money($p['revenue']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topProducts)): ?>
                <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">No data</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php
$extraJs = '<script>
new Chart(document.getElementById("salesChart"), {
    type: "bar",
    data: {
        labels: ' . json_encode(array_map(fn($d) => date('M d', strtotime($d['date'])), $dailySales)) . ',
        datasets: [{
            label: "Revenue",
            data: ' . json_encode(array_map(fn($d) => $d['total'], $dailySales)) . ',
            backgroundColor: "#10b981",
            borderRadius: 6
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } }
});
new Chart(document.getElementById("paymentChart"), {
    type: "doughnut",
    data: {
        labels: ' . json_encode(array_map(fn($p) => ucfirst($p['payment_method']), $paymentMethods)) . ',
        datasets: [{
            data: ' . json_encode(array_map(fn($p) => $p['total'], $paymentMethods)) . ',
            backgroundColor: ["#3b82f6", "#10b981", "#f59e0b", "#8b5cf6", "#ef4444"]
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "right" } } }
});
</script>';
?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
