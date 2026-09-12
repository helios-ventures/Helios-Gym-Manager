<?php
/**
 * Dashboard - Main Entry Point
 */

require_once __DIR__ . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\Auth;
use Gym\Core\Helper;

$pageTitle = 'Dashboard';
$pageDescription = 'Overview of your gym operations';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';

// Get dashboard statistics
$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');

// Total members
$totalMembers = Database::fetchOne("SELECT COUNT(*) as count FROM members WHERE status = 'active'")['count'] ?? 0;

// Today's attendance
$todayAttendance = Database::fetchOne("SELECT COUNT(DISTINCT member_id) as count FROM attendance_logs WHERE DATE(check_in) = ?", [$today])['count'] ?? 0;

// Monthly revenue
$monthlyRevenue = Database::fetchOne("SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(created_at) >= ? AND payment_status = 'paid'", [$firstDayOfMonth])['total'] ?? 0;

// Expiring soon (next 7 days)
$expiringSoon = Database::fetchOne("SELECT COUNT(*) as count FROM members WHERE expiry_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)", [$today, $today])['count'] ?? 0;

// Recent members
$recentMembers = Database::fetchAll(
    "SELECT m.*, p.name as plan_name 
     FROM members m 
     LEFT JOIN member_subscriptions ms ON m.id = ms.member_id AND ms.status = 'active'
     LEFT JOIN subscription_plans p ON ms.plan_id = p.id
     ORDER BY m.created_at DESC LIMIT 5"
);

// Today's attendance list
$todayAttendanceList = Database::fetchAll(
    "SELECT al.*, m.first_name, m.last_name, m.member_code 
     FROM attendance_logs al 
     LEFT JOIN members m ON al.member_id = m.id 
     WHERE DATE(al.check_in) = ? 
     ORDER BY al.check_in DESC LIMIT 10",
    [$today]
);

// Recent sales
$recentSales = Database::fetchAll(
    "SELECT s.*, m.first_name, m.last_name 
     FROM sales s 
     LEFT JOIN members m ON s.member_id = m.id 
     ORDER BY s.created_at DESC LIMIT 5"
);

// Low stock products
$lowStock = Database::fetchAll(
    "SELECT * FROM products WHERE stock_quantity <= low_stock_threshold AND status = 'active' ORDER BY stock_quantity ASC LIMIT 5"
);

// Weekly attendance data for chart
$weeklyAttendance = Database::fetchAll(
    "SELECT DATE(check_in) as date, COUNT(DISTINCT member_id) as count 
     FROM attendance_logs 
     WHERE check_in >= DATE_SUB(?, INTERVAL 7 DAY) 
     GROUP BY DATE(check_in) ORDER BY date",
    [$today]
);

// Revenue by day for chart
$weeklyRevenue = Database::fetchAll(
    "SELECT DATE(created_at) as date, COALESCE(SUM(total_amount), 0) as total 
     FROM sales 
     WHERE created_at >= DATE_SUB(?, INTERVAL 7 DAY) AND payment_status = 'paid'
     GROUP BY DATE(created_at) ORDER BY date",
    [$today]
);
?>

<!-- Stats Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6">
    <!-- Total Members -->
    <div class="card-hover bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">Total Members</p>
                <h3 class="text-2xl font-bold text-gray-900"><?php echo number_format($totalMembers); ?></h3>
                <p class="text-xs text-green-600 mt-1 flex items-center gap-1">
                    <i data-lucide="trending-up" class="w-3 h-3"></i> Active members
                </p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center">
                <i data-lucide="users" class="w-6 h-6 text-blue-600"></i>
            </div>
        </div>
    </div>
    
    <!-- Today's Attendance -->
    <div class="card-hover bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">Today's Attendance</p>
                <h3 class="text-2xl font-bold text-gray-900"><?php echo number_format($todayAttendance); ?></h3>
                <p class="text-xs text-blue-600 mt-1 flex items-center gap-1">
                    <i data-lucide="activity" class="w-3 h-3"></i> Checked in today
                </p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-green-50 flex items-center justify-center">
                <i data-lucide="clipboard-check" class="w-6 h-6 text-green-600"></i>
            </div>
        </div>
    </div>
    
    <!-- Monthly Revenue -->
    <div class="card-hover bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">Monthly Revenue</p>
                <h3 class="text-2xl font-bold text-gray-900"><?php echo Helper::money($monthlyRevenue); ?></h3>
                <p class="text-xs text-green-600 mt-1 flex items-center gap-1">
                    <i data-lucide="trending-up" class="w-3 h-3"></i> This month
                </p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-purple-50 flex items-center justify-center">
                <i data-lucide="banknote" class="w-6 h-6 text-purple-600"></i>
            </div>
        </div>
    </div>
    
    <!-- Expiring Soon -->
    <div class="card-hover bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">Expiring Soon</p>
                <h3 class="text-2xl font-bold text-gray-900"><?php echo number_format($expiringSoon); ?></h3>
                <p class="text-xs text-yellow-600 mt-1 flex items-center gap-1">
                    <i data-lucide="alert-triangle" class="w-3 h-3"></i> Next 7 days
                </p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-yellow-50 flex items-center justify-center">
                <i data-lucide="calendar-clock" class="w-6 h-6 text-yellow-600"></i>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6">
    
    <!-- Attendance Chart -->
    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-96">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-900">Weekly Attendance</h3>
            <a href="<?php echo BASE_URL; ?>/modules/attendance/index.php" class="text-sm text-blue-600 hover:underline">View all</a>
        </div>
        <div class="relative h-72">
            <canvas id="attendanceChart"></canvas>
        </div>
    </div>
    
<!-- Revenue Chart -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-96">
    
    <div class="flex items-center justify-between mb-4">
        <h3 class="font-semibold text-gray-900">Weekly Revenue</h3>

        <a href="<?php echo BASE_URL; ?>/modules/reports/sales.php"
           class="text-sm text-blue-600 hover:underline">
            View all
        </a>
    </div>

    <div class="relative h-72">
        <canvas id="revenueChart"></canvas>
    </div>

</div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mt-6">
    
    <!-- Recent Members -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Recent Members</h3>
            <a href="<?php echo BASE_URL; ?>/modules/members/index.php" class="text-sm text-blue-600 hover:underline">View all</a>
        </div>
        <div class="divide-y divide-gray-50">
            <?php foreach ($recentMembers as $member): ?>
                <div class="flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
                            <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-gray-900">
                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                            </p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($member['member_code']); ?> • <?php echo htmlspecialchars($member['plan_name'] ?? 'No plan'); ?></p>
                        </div>
                    </div>
                    <span class="badge-<?php echo Helper::statusBadge($member['status']); ?> px-2.5 py-1 text-xs rounded-full border">
                        <?php echo ucfirst($member['status']); ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($recentMembers)): ?>
                <div class="px-5 py-8 text-center text-gray-400 text-sm">No members found</div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Today's Attendance -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Today's Check-ins</h3>
            <a href="<?php echo BASE_URL; ?>/modules/attendance/index.php" class="text-sm text-blue-600 hover:underline">View all</a>
        </div>
        <div class="divide-y divide-gray-50 max-h-80 overflow-y-auto">
            <?php foreach ($todayAttendanceList as $att): ?>
                <div class="flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center text-white font-semibold text-sm">
                            <i data-lucide="check" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-gray-900">
                                <?php echo htmlspecialchars(($att['first_name'] ?? 'Unknown') . ' ' . ($att['last_name'] ?? '')); ?>
                            </p>
                            <p class="text-xs text-gray-500"><?php echo Helper::time($att['check_in']); ?> • <?php echo htmlspecialchars($att['check_type'] ?? 'fingerprint'); ?></p>
                        </div>
                    </div>
                    <span class="badge-<?php echo Helper::statusBadge($att['status'] ?? 'present'); ?> px-2.5 py-1 text-xs rounded-full border">
                        <?php echo ucfirst($att['status'] ?? 'Present'); ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($todayAttendanceList)): ?>
                <div class="px-5 py-8 text-center text-gray-400 text-sm">No check-ins today</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mt-6">
    
    <!-- Recent Sales -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Recent Sales</h3>
            <a href="<?php echo BASE_URL; ?>/modules/pos/history.php" class="text-sm text-blue-600 hover:underline">View all</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Invoice</th>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Customer</th>
                        <th class="text-right px-5 py-3 font-medium text-gray-500">Amount</th>
                        <th class="text-center px-5 py-3 font-medium text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($recentSales as $sale): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-3 font-medium"><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                            <td class="px-5 py-3">
                                <?php echo htmlspecialchars($sale['first_name'] ? $sale['first_name'] . ' ' . $sale['last_name'] : $sale['customer_name'] ?? 'Walk-in'); ?>
                            </td>
                            <td class="px-5 py-3 text-right font-medium"><?php echo Helper::money($sale['total_amount']); ?></td>
                            <td class="px-5 py-3 text-center">
                                <span class="badge-<?php echo Helper::statusBadge($sale['payment_status']); ?> px-2 py-0.5 text-xs rounded-full border">
                                    <?php echo ucfirst($sale['payment_status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentSales)): ?>
                        <tr><td colspan="4" class="px-5 py-8 text-center text-gray-400">No sales today</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Low Stock Alerts -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Low Stock Alerts</h3>
            <a href="<?php echo BASE_URL; ?>/modules/pos/products.php" class="text-sm text-blue-600 hover:underline">Manage</a>
        </div>
        <div class="divide-y divide-gray-50">
            <?php foreach ($lowStock as $product): ?>
                <div class="flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
                            <i data-lucide="package" class="w-5 h-5 text-red-500"></i>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-gray-900"><?php echo htmlspecialchars($product['name']); ?></p>
                            <p class="text-xs text-gray-500">SKU: <?php echo htmlspecialchars($product['sku']); ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-red-600"><?php echo $product['stock_quantity']; ?> left</p>
                        <p class="text-xs text-gray-500">Min: <?php echo $product['low_stock_threshold']; ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($lowStock)): ?>
                <div class="px-5 py-8 text-center text-gray-400 text-sm">
                    <i data-lucide="check-circle" class="w-8 h-8 mx-auto mb-2 text-green-500"></i>
                    All products well stocked
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php
$extraJs = '<script>
    // Weekly Attendance Chart
    const attendanceCtx = document.getElementById("attendanceChart").getContext("2d");
    new Chart(attendanceCtx, {
        type: "line",
        data: {
            labels: ' . json_encode(array_map(fn($d) => date('D', strtotime($d['date'])), $weeklyAttendance)) . ',
            datasets: [{
                label: "Members",
                data: ' . json_encode(array_map(fn($d) => $d['count'], $weeklyAttendance)) . ',
                borderColor: "#3b82f6",
                backgroundColor: "rgba(59, 130, 246, 0.1)",
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: "#3b82f6"
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
                x: { grid: { display: false } }
            }
        }
    });
    
    // Weekly Revenue Chart
    const revenueCtx = document.getElementById("revenueChart").getContext("2d");
    new Chart(revenueCtx, {
        type: "bar",
        data: {
            labels: ' . json_encode(array_map(fn($d) => date('D', strtotime($d['date'])), $weeklyRevenue)) . ',
            datasets: [{
                label: "Revenue",
                data: ' . json_encode(array_map(fn($d) => $d['total'], $weeklyRevenue)) . ',
                backgroundColor: "#8b5cf6",
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true },
                x: { grid: { display: false } }
            }
        }
    });
</script>';
?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
