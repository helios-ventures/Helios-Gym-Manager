<?php
/**
 * Attendance Reports
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;

Auth::requirePermission('attendance', 'view');

$pageTitle = 'Attendance Reports';
$pageDescription = 'Detailed attendance analytics and reporting';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Daily stats
$dailyStats = Database::fetchAll(
    "SELECT DATE(check_in) as date, COUNT(DISTINCT member_id) as unique_members, 
            COUNT(*) as total_checkins, AVG(duration_minutes) as avg_duration
     FROM attendance_logs 
     WHERE check_in BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY DATE(check_in) 
     ORDER BY date DESC",
    [$dateFrom, $dateTo]
);

// Member attendance ranking
$memberRanking = Database::fetchAll(
    "SELECT m.first_name, m.last_name, m.member_code, COUNT(*) as visits,
            AVG(al.duration_minutes) as avg_duration
     FROM attendance_logs al
     JOIN members m ON al.member_id = m.id
     WHERE al.check_in BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY al.member_id
     ORDER BY visits DESC
     LIMIT 20",
    [$dateFrom, $dateTo]
);

// Peak hours
$peakHours = Database::fetchAll(
    "SELECT HOUR(check_in) as hour, COUNT(*) as count
     FROM attendance_logs
     WHERE check_in BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY HOUR(check_in)
     ORDER BY hour",
    [$dateFrom, $dateTo]
);

$totalVisits = array_sum(array_column($dailyStats, 'total_checkins'));
$activeDays = count($dailyStats);
$avgDaily = $activeDays > 0 ? round($totalVisits / $activeDays, 1) : 0;

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
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Generate</button>
    </form>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500">Total Check-ins</p>
        <h3 class="text-2xl font-bold text-blue-700"><?php echo number_format($totalVisits); ?></h3>
    </div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500">Average Daily</p>
        <h3 class="text-2xl font-bold text-green-700"><?php echo $avgDaily; ?></h3>
    </div>
    <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
        <p class="text-sm text-gray-500">Active Days</p>
        <h3 class="text-2xl font-bold text-purple-700"><?php echo $activeDays; ?></h3>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="font-semibold text-gray-900 mb-4">Daily Attendance</h3>
        
        <div class="relative h-72">
        <canvas id="dailyChart"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="font-semibold text-gray-900 mb-4">Peak Hours</h3>
        <div class="relative h-72">
        <canvas id="peakChart"></canvas>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="font-semibold text-gray-900 mb-4">Top Members by Attendance</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Member</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Visits</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Avg Duration</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($memberRanking as $m): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-3 font-medium"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?> <span class="text-gray-400 text-xs"><?php echo $m['member_code']; ?></span></td>
                    <td class="px-5 py-3 text-center font-medium"><?php echo number_format($m['visits']); ?></td>
                    <td class="px-5 py-3 text-center"><?php echo $m['avg_duration'] ? floor($m['avg_duration']/60).'h '.round($m['avg_duration']%60).'m' : 'N/A'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($memberRanking)): ?><tr><td colspan="3" class="px-5 py-8 text-center text-gray-400">No data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_map(fn($d) => date('M d', strtotime($d['date'])), array_reverse($dailyStats))); ?>,
        datasets: [{
            label: 'Check-ins',
            data: <?php echo json_encode(array_map(fn($d) => $d['total_checkins'], array_reverse($dailyStats))); ?>,
            borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', borderWidth: 2, fill: true, tension: 0.4, pointRadius: 4
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } }
});
new Chart(document.getElementById('peakChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(fn($h) => $h['hour'].':00', $peakHours)); ?>,
        datasets: [{
            label: 'Check-ins',
            data: <?php echo json_encode(array_map(fn($h) => $h['count'], $peakHours)); ?>,
            backgroundColor: '#8b5cf6', borderRadius: 4
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } }
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
