<?php
/**
 * Member Profile View
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;

Auth::requirePermission('members', 'view');

$memberId = intval($_GET['id'] ?? 0);
if (!$memberId) {
    Helper::redirect('/modules/members/index.php', 'warning', 'Member not found');
}

$member = Database::fetchOne(
    "SELECT m.*, p.name as plan_name, p.duration_days, ms.start_date as sub_start, ms.end_date as sub_end, ms.status as sub_status, ms.payment_method
     FROM members m 
     LEFT JOIN member_subscriptions ms ON m.id = ms.member_id AND ms.status = 'active'
     LEFT JOIN subscription_plans p ON ms.plan_id = p.id
     WHERE m.id = ?",
    [$memberId]
);

if (!$member) {
    Helper::redirect('/modules/members/index.php', 'warning', 'Member not found');
}

// Weight history
$weightHistory = Database::fetchAll(
    "SELECT * FROM weight_logs WHERE member_id = ? ORDER BY created_at DESC LIMIT 10",
    [$memberId]
);

// Attendance stats
$attendanceStats = Database::fetchOne(
    "SELECT COUNT(*) as total_visits, 
            COUNT(DISTINCT DATE(check_in)) as unique_days,
            MAX(check_in) as last_visit
     FROM attendance_logs WHERE member_id = ?",
    [$memberId]
);

// Subscription history
$subscriptionHistory = Database::fetchAll(
    "SELECT ms.*, p.name as plan_name 
     FROM member_subscriptions ms 
     JOIN subscription_plans p ON ms.plan_id = p.id 
     WHERE ms.member_id = ? ORDER BY ms.created_at DESC",
    [$memberId]
);

$pageTitle = 'Member Profile';
$pageDescription = $member['first_name'] . ' ' . $member['last_name'] . ' - ' . $member['member_code'];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';

$bmi = ($member['height_cm'] && $member['current_weight_kg']) ? Helper::calculateBmi($member['current_weight_kg'], $member['height_cm']) : null;
$daysUntil = $member['expiry_date'] ? Helper::daysUntil($member['expiry_date']) : null;
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- Profile Card -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 text-center">
            <div class="w-24 h-24 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold text-2xl mx-auto mb-4">
                <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
            </div>
            <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h2>
            <p class="text-sm text-gray-500 mb-4"><?php echo htmlspecialchars($member['member_code']); ?></p>
            
            <span class="badge-<?php echo Helper::statusBadge($member['status']); ?> px-3 py-1 text-sm rounded-full border inline-flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-current"></span>
                <?php echo ucfirst($member['status']); ?>
            </span>
            
            <div class="mt-6 pt-6 border-t border-gray-100 text-left space-y-3">
                <div class="flex justify-between">
                    <span class="text-sm text-gray-500">Phone</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($member['phone']); ?></span>
                </div>
                <?php if ($member['email']): ?>
                <div class="flex justify-between">
                    <span class="text-sm text-gray-500">Email</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($member['email']); ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="text-sm text-gray-500">Gender</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo ucfirst($member['gender'] ?? 'N/A'); ?></span>
                </div>
                <?php if ($member['date_of_birth']): ?>
                <div class="flex justify-between">
                    <span class="text-sm text-gray-500">Age</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo floor((time() - strtotime($member['date_of_birth'])) / 31556926); ?> years</span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="text-sm text-gray-500">Joined</span>
                    <span class="text-sm font-medium text-gray-900"><?php echo Helper::date($member['join_date']); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-gray-500">Expiry</span>
                    <span class="text-sm font-medium <?php echo $daysUntil !== null && $daysUntil <= 7 ? 'text-red-600' : 'text-gray-900'; ?>">
                        <?php echo $member['expiry_date'] ? Helper::date($member['expiry_date']) : 'N/A'; ?>
                        <?php if ($daysUntil !== null && $daysUntil <= 7 && $daysUntil >= 0): ?>
                            <span class="text-red-500">(<?php echo $daysUntil; ?> days)</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            
            <div class="mt-6 pt-6 border-t border-gray-100 grid grid-cols-2 gap-3">
                <a href="edit.php?id=<?php echo $member['id']; ?>" 
                   class="flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                    <i data-lucide="edit-3" class="w-4 h-4"></i> Edit
                </a>
                <a href="renew.php?id=<?php echo $member['id']; ?>" 
                   class="flex items-center justify-center gap-2 px-4 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm font-medium">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i> Renew
                </a>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-900 mb-4">Activity Stats</h3>
            <div class="grid grid-cols-2 gap-4">
                <div class="text-center p-4 bg-blue-50 rounded-lg">
                    <p class="text-2xl font-bold text-blue-700"><?php echo number_format($attendanceStats['total_visits'] ?? 0); ?></p>
                    <p class="text-xs text-blue-600 mt-1">Total Visits</p>
                </div>
                <div class="text-center p-4 bg-green-50 rounded-lg">
                    <p class="text-2xl font-bold text-green-700"><?php echo number_format($attendanceStats['unique_days'] ?? 0); ?></p>
                    <p class="text-xs text-green-600 mt-1">Unique Days</p>
                </div>
            </div>
            <?php if ($attendanceStats['last_visit']): ?>
                <p class="text-xs text-gray-500 mt-4 text-center">Last visit: <?php echo Helper::relativeTime($attendanceStats['last_visit']); ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- BMI & Weight Card -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                    <i data-lucide="scale" class="w-5 h-5 text-green-500"></i>
                    Weight & BMI Tracking
                </h3>
                <a href="weight-tracking.php?id=<?php echo $member['id']; ?>" class="text-sm text-blue-600 hover:underline">View Details</a>
            </div>
            
            <?php if ($bmi): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="text-center p-4 bg-gray-50 rounded-lg">
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($member['current_weight_kg'], 1); ?> kg</p>
                        <p class="text-xs text-gray-500 mt-1">Current Weight</p>
                    </div>
                    <div class="text-center p-4 bg-gray-50 rounded-lg">
                        <p class="text-2xl font-bold text-<?php echo Helper::bmiColor($bmi); ?>-600"><?php echo $bmi; ?></p>
                        <p class="text-xs text-gray-500 mt-1">BMI (<?php echo Helper::bmiCategory($bmi); ?>)</p>
                    </div>
                    <?php if ($member['target_weight_kg']): ?>
                    <div class="text-center p-4 bg-gray-50 rounded-lg">
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($member['target_weight_kg'], 1); ?> kg</p>
                        <p class="text-xs text-gray-500 mt-1">Target Weight</p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Weight Chart -->
            <?php if (!empty($weightHistory)): ?>                
                
                    <div class="relative h-72">
                        <canvas id="weightChart"></canvas>
                    </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-400">
                    <i data-lucide="scale" class="w-10 h-10 mx-auto mb-2"></i>
                    <p>No weight records yet</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Active Subscription -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i data-lucide="credit-card" class="w-5 h-5 text-purple-500"></i>
                Subscription
            </h3>
            
            <?php if ($member['plan_name']): ?>
                <div class="flex items-center justify-between p-4 bg-purple-50 rounded-lg">
                    <div>
                        <p class="font-semibold text-purple-900"><?php echo htmlspecialchars($member['plan_name']); ?></p>
                        <p class="text-sm text-purple-600">
                            <?php echo Helper::date($member['sub_start']); ?> - <?php echo Helper::date($member['sub_end']); ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="badge-<?php echo Helper::statusBadge($member['sub_status']); ?> px-2.5 py-1 text-xs rounded-full border">
                            <?php echo ucfirst($member['sub_status']); ?>
                        </span>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-6 bg-gray-50 rounded-lg">
                    <p class="text-gray-500 mb-3">No active subscription</p>
                    <a href="renew.php?id=<?php echo $member['id']; ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                        <i data-lucide="plus" class="w-4 h-4"></i> Add Subscription
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Subscription History -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Subscription History</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-4 py-3 font-medium text-gray-500">Plan</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-500">Period</th>
                            <th class="text-right px-4 py-3 font-medium text-gray-500">Amount</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($subscriptionHistory as $sub): ?>
                            <tr>
                                <td class="px-4 py-3 font-medium"><?php echo htmlspecialchars($sub['plan_name']); ?></td>
                                <td class="px-4 py-3 text-gray-500"><?php echo Helper::date($sub['start_date']); ?> - <?php echo Helper::date($sub['end_date']); ?></td>
                                <td class="px-4 py-3 text-right"><?php echo Helper::money($sub['amount_paid']); ?></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="badge-<?php echo Helper::statusBadge($sub['status']); ?> px-2 py-0.5 text-xs rounded-full border">
                                        <?php echo ucfirst($sub['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($subscriptionHistory)): ?>
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No subscription history</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Health Notes -->
        <?php if ($member['health_notes']): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i data-lucide="file-text" class="w-5 h-5 text-yellow-500"></i>
                Health Notes
            </h3>
            <div class="p-4 bg-yellow-50 rounded-lg text-sm text-gray-700">
                <?php echo nl2br(htmlspecialchars($member['health_notes'])); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($weightHistory)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php
$extraJs = '<script>
    const weightCtx = document.getElementById("weightChart").getContext("2d");
    new Chart(weightCtx, {
        type: "line",
        data: {
            labels: ' . json_encode(array_reverse(array_map(fn($w) => date('M d', strtotime($w['created_at'])), $weightHistory))) . ',
            datasets: [{
                label: "Weight (kg)",
                data: ' . json_encode(array_reverse(array_map(fn($w) => $w['weight_kg'], $weightHistory))) . ',
                borderColor: "#10b981",
                backgroundColor: "rgba(16, 185, 129, 0.1)",
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: false },
                x: { grid: { display: false } }
            }
        }
    });
</script>';
?>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
