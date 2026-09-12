<?php
/**
 * Weight Loss Management - Track member weight, BMI, and progress
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Validator;

Auth::requirePermission('members', 'view');

$memberId = intval($_GET['id'] ?? 0);
$member = null;

if ($memberId) {
    $member = Database::fetchOne("SELECT id, first_name, last_name, member_code, height_cm, current_weight_kg, target_weight_kg FROM members WHERE id = ?", [$memberId]);
}

// Add weight log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_weight'])) {
    Auth::requirePermission('members', 'edit');
    
    $validator = Validator::make($_POST, [
        'member_id' => 'required|integer',
        'weight_kg' => 'required|numeric',
        'body_fat_percent' => 'numeric',
        'muscle_kg' => 'numeric',
        'notes' => 'max:500',
    ]);
    
    if ($validator->passes()) {
        $data = $validator->validated();
        $m = Database::fetchOne("SELECT height_cm FROM members WHERE id = ?", [$data['member_id']]);
        $bmi = Helper::calculateBmi($data['weight_kg'], $m['height_cm'] ?? 170);
        
        Database::insert(
            "INSERT INTO weight_logs (member_id, weight_kg, body_fat_percent, muscle_kg, bmi, notes, logged_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['member_id'], $data['weight_kg'], $data['body_fat_percent'] ?? null,
                $data['muscle_kg'] ?? null, $bmi, $data['notes'] ?? null, Auth::id()
            ]
        );
        
        // Update member current weight
        Database::execute("UPDATE members SET current_weight_kg = ? WHERE id = ?", [$data['weight_kg'], $data['member_id']]);
        
        Helper::redirect('/modules/members/weight-tracking.php?id=' . $data['member_id'], 'success', 'Weight log added successfully');
    }
}

// Get members for dropdown
$members = Database::fetchAll("SELECT id, first_name, last_name, member_code FROM members WHERE status = 'active' ORDER BY first_name");

// Get weight logs
$weightLogs = [];
$chartData = [];

if ($memberId) {
    $weightLogs = Database::fetchAll(
        "SELECT wl.*, u.first_name as logged_by_name, u.last_name as logged_by_last 
         FROM weight_logs wl 
         LEFT JOIN users u ON wl.logged_by = u.id 
         WHERE wl.member_id = ? ORDER BY wl.created_at DESC",
        [$memberId]
    );
    
    $chartData = array_reverse(Database::fetchAll(
        "SELECT weight_kg, bmi, created_at FROM weight_logs WHERE member_id = ? ORDER BY created_at ASC LIMIT 30",
        [$memberId]
    ));
}

$pageTitle = 'Weight Tracking';
$pageDescription = $member ? 'Weight progress for ' . $member['first_name'] . ' ' . $member['last_name'] : 'Track member weight and BMI';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<!-- Member Selector -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex items-center gap-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Select Member</label>
            <select name="id" onchange="this.form.submit()" 
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                <option value="">Choose a member...</option>
                <?php foreach ($members as $m): ?>
                    <option value="<?php echo $m['id']; ?>" <?php echo $memberId == $m['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_code'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($member): ?>
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
            <p class="text-sm text-gray-500 mb-1">Current Weight</p>
            <h3 class="text-2xl font-bold text-gray-900"><?php echo number_format($member['current_weight_kg'], 1); ?> kg</h3>
        </div>
        <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
            <p class="text-sm text-gray-500 mb-1">Target Weight</p>
            <h3 class="text-2xl font-bold text-gray-900"><?php echo $member['target_weight_kg'] ? number_format($member['target_weight_kg'], 1) . ' kg' : 'Not set'; ?></h3>
        </div>
        <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
            <p class="text-sm text-gray-500 mb-1">BMI</p>
            <?php if ($member['height_cm'] && $member['current_weight_kg']): 
                $bmi = Helper::calculateBmi($member['current_weight_kg'], $member['height_cm']);
            ?>
                <h3 class="text-2xl font-bold text-<?php echo Helper::bmiColor($bmi); ?>-600"><?php echo $bmi; ?></h3>
                <p class="text-xs text-gray-500"><?php echo Helper::bmiCategory($bmi); ?></p>
            <?php else: ?>
                <h3 class="text-lg text-gray-400">N/A</h3>
            <?php endif; ?>
        </div>
        <div class="bg-white rounded-xl p-5 border border-gray-100 shadow-sm text-center">
            <p class="text-sm text-gray-500 mb-1">To Target</p>
            <?php if ($member['target_weight_kg'] && $member['current_weight_kg']):
                $diff = $member['current_weight_kg'] - $member['target_weight_kg'];
            ?>
                <h3 class="text-2xl font-bold <?php echo $diff > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                    <?php echo ($diff > 0 ? '+' : '') . number_format($diff, 1); ?> kg
                </h3>
            <?php else: ?>
                <h3 class="text-lg text-gray-400">N/A</h3>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Weight Chart -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Weight Progress Chart</h3>
            <?php if (!empty($chartData)): ?>
                
                <div class="relative h-72">
                <canvas id="weightChart" height="100"></canvas>
                </div>
            <?php else: ?>
                <div class="text-center py-12 text-gray-400">
                    <i data-lucide="trending-down" class="w-12 h-12 mx-auto mb-3"></i>
                    <p>No weight data recorded yet</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Add Weight Form -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Log Weight</h3>
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                <input type="hidden" name="add_weight" value="1">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Weight (kg) *</label>
                    <input type="number" name="weight_kg" step="0.01" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Body Fat %</label>
                    <input type="number" name="body_fat_percent" step="0.1"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Muscle (kg)</label>
                    <input type="number" name="muscle_kg" step="0.01"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2" 
                              class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                              placeholder="Optional notes..."></textarea>
                </div>
                <button type="submit" class="w-full py-2.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium flex items-center justify-center gap-2">
                    <i data-lucide="plus" class="w-4 h-4"></i> Add Entry
                </button>
            </form>
        </div>
    </div>
    
    <!-- Weight History Table -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm mt-6">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Weight History</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Date</th>
                        <th class="text-right px-5 py-3 font-medium text-gray-500">Weight (kg)</th>
                        <th class="text-right px-5 py-3 font-medium text-gray-500">BMI</th>
                        <th class="text-right px-5 py-3 font-medium text-gray-500">Body Fat %</th>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Change</th>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Notes</th>
                        <th class="text-left px-5 py-3 font-medium text-gray-500">Logged By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php 
                    $prevWeight = null;
                    foreach ($weightLogs as $log): 
                        $change = $prevWeight !== null ? round($log['weight_kg'] - $prevWeight, 2) : null;
                        $prevWeight = $log['weight_kg'];
                    ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-3"><?php echo Helper::datetime($log['created_at']); ?></td>
                            <td class="px-5 py-3 text-right font-medium"><?php echo number_format($log['weight_kg'], 2); ?></td>
                            <td class="px-5 py-3 text-right"><?php echo $log['bmi'] ?? 'N/A'; ?></td>
                            <td class="px-5 py-3 text-right"><?php echo $log['body_fat_percent'] ?? 'N/A'; ?></td>
                            <td class="px-5 py-3">
                                <?php if ($change !== null): ?>
                                    <span class="<?php echo $change < 0 ? 'text-green-600' : ($change > 0 ? 'text-red-600' : 'text-gray-400'); ?>">
                                        <i data-lucide="<?php echo $change < 0 ? 'trending-down' : ($change > 0 ? 'trending-up' : 'minus'); ?>" class="w-3 h-3 inline"></i>
                                        <?php echo ($change > 0 ? '+' : '') . number_format($change, 2); ?> kg
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-gray-500"><?php echo htmlspecialchars($log['notes'] ?? ''); ?></td>
                            <td class="px-5 py-3 text-gray-500"><?php echo htmlspecialchars(($log['logged_by_name'] ?? 'System') . ' ' . ($log['logged_by_last'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($weightLogs)): ?>
                        <tr><td colspan="7" class="px-5 py-8 text-center text-gray-400">No weight records yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-12 text-center">
        <i data-lucide="users" class="w-16 h-16 mx-auto text-gray-300 mb-4"></i>
        <h3 class="text-lg font-medium text-gray-900 mb-2">Select a Member</h3>
        <p class="text-gray-500">Choose a member from the dropdown above to view their weight tracking data.</p>
    </div>
<?php endif; ?>

<?php if (!empty($chartData)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php
$extraJs = '<script>
    const weightCtx = document.getElementById("weightChart").getContext("2d");
    new Chart(weightCtx, {
        type: "line",
        data: {
            labels: ' . json_encode(array_map(fn($d) => date('M d', strtotime($d['created_at'])), $chartData)) . ',
            datasets: [{
                label: "Weight (kg)",
                data: ' . json_encode(array_map(fn($d) => $d['weight_kg'], $chartData)) . ',
                borderColor: "#10b981",
                backgroundColor: "rgba(16, 185, 129, 0.1)",
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: "#10b981"
            }' . ($member['target_weight_kg'] ? ', {
                label: "Target",
                data: ' . json_encode(array_fill(0, count($chartData), $member['target_weight_kg'])) . ',
                borderColor: "#ef4444",
                borderWidth: 1,
                borderDash: [5, 5],
                pointRadius: 0,
                fill: false
            }' : '') . ']
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { position: "top" } 
            },
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
