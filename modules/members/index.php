<?php
/**
 * Members List - Membership Management
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Response;

Auth::requirePermission('members', 'view');

$pageTitle = 'All Members';
$pageDescription = 'Manage gym members and their subscriptions';

// Filters
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$plan = $_GET['plan'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

// Build query
$params = [];
$where = [];

if ($status) {
    $where[] = "m.status = ?";
    $params[] = $status;
}
if ($search) {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ? OR m.phone LIKE ? OR m.email LIKE ?)";
    $searchParam = "%{$search}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}
if ($plan) {
    $where[] = "ms.plan_id = ?";
    $params[] = $plan;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$countSql = "SELECT COUNT(*) as count FROM members m LEFT JOIN member_subscriptions ms ON m.id = ms.member_id AND ms.status = 'active' {$whereClause}";
$totalCount = Database::fetchOne($countSql, $params)['count'] ?? 0;

// Pagination
$pagination = Helper::paginate($totalCount, $page, $perPage);

// Get members
$sql = "SELECT m.*, p.name as plan_name, p.duration_days, ms.end_date as subscription_end, ms.status as sub_status,
        (SELECT weight_kg FROM weight_logs WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1) as latest_weight
        FROM members m 
        LEFT JOIN member_subscriptions ms ON m.id = ms.member_id AND ms.status = 'active'
        LEFT JOIN subscription_plans p ON ms.plan_id = p.id
        {$whereClause}
        ORDER BY m.created_at DESC 
        LIMIT {$pagination['offset']}, {$perPage}";
$members = Database::fetchAll($sql, $params);

// Get plans for filter
$plans = Database::fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price");

// Export handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportData = array_map(fn($m) => [
        'Member Code' => $m['member_code'],
        'Name' => $m['first_name'] . ' ' . $m['last_name'],
        'Phone' => $m['phone'],
        'Email' => $m['email'],
        'Gender' => ucfirst($m['gender'] ?? 'N/A'),
        'Join Date' => $m['join_date'],
        'Expiry Date' => $m['expiry_date'] ?? 'N/A',
        'Status' => ucfirst($m['status']),
        'Plan' => $m['plan_name'] ?? 'No Active Plan',
    ], $members);
    Response::csv($exportData, 'members_' . date('Y-m-d') . '.csv');
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';

// Page actions
ob_start();
?>
<div class="flex items-center gap-2">
    <a href="?export=csv" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
        <i data-lucide="download" class="w-4 h-4"></i> Export
    </a>
    <a href="create.php" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
        <i data-lucide="plus" class="w-4 h-4"></i> Add Member
    </a>
</div>
<?php
$pageActions = ob_get_clean();
?>

<!-- Filters -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Search by name, code, phone..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
        </div>
        <div class="w-40">
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" onchange="this.form.submit()"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="">All Status</option>
                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            </select>
        </div>
        <div class="w-48">
            <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
            <select name="plan" onchange="this.form.submit()"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="">All Plans</option>
                <?php foreach ($plans as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $plan == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium">
            <i data-lucide="filter" class="w-4 h-4 inline mr-1"></i> Filter
        </button>
        <?php if ($status || $search || $plan): ?>
            <a href="index.php" class="px-4 py-2 text-gray-500 hover:text-gray-700 text-sm">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Members Table -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm data-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-5 py-3.5 font-semibold text-gray-700">Member</th>
                    <th class="text-left px-5 py-3.5 font-semibold text-gray-700">Contact</th>
                    <th class="text-left px-5 py-3.5 font-semibold text-gray-700">Plan</th>
                    <th class="text-center px-5 py-3.5 font-semibold text-gray-700">Status</th>
                    <th class="text-left px-5 py-3.5 font-semibold text-gray-700">Expiry</th>
                    <th class="text-center px-5 py-3.5 font-semibold text-gray-700">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($members as $member): ?>
                    <?php 
                    $daysUntil = $member['expiry_date'] ? Helper::daysUntil($member['expiry_date']) : null;
                    $expiryClass = $daysUntil !== null && $daysUntil <= 7 ? 'text-red-600 font-medium' : 'text-gray-600';
                    ?>
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
                                    <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($member['member_code']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <p class="text-gray-900"><?php echo htmlspecialchars($member['phone']); ?></p>
                            <?php if ($member['email']): ?>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($member['email']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4">
                            <?php if ($member['plan_name']): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-50 text-blue-700 rounded-lg text-xs font-medium">
                                    <i data-lucide="award" class="w-3 h-3"></i>
                                    <?php echo htmlspecialchars($member['plan_name']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-400 text-xs">No active plan</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-center">
                            <span class="badge-<?php echo Helper::statusBadge($member['status']); ?> px-2.5 py-1 text-xs rounded-full border inline-flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                <?php echo ucfirst($member['status']); ?>
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <?php if ($member['expiry_date']): ?>
                                <span class="<?php echo $expiryClass; ?> text-sm">
                                    <?php echo Helper::date($member['expiry_date']); ?>
                                </span>
                                <?php if ($daysUntil !== null && $daysUntil <= 7 && $daysUntil >= 0): ?>
                                    <span class="block text-xs text-red-500"><?php echo $daysUntil; ?> days left</span>
                                <?php elseif ($daysUntil !== null && $daysUntil < 0): ?>
                                    <span class="block text-xs text-red-500">Expired</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-400 text-sm">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-center gap-1">
                                <a href="view.php?id=<?php echo $member['id']; ?>" 
                                   class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="View">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </a>
                                <a href="edit.php?id=<?php echo $member['id']; ?>" 
                                   class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors" title="Edit">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </a>
                                <a href="renew.php?id=<?php echo $member['id']; ?>" 
                                   class="p-2 text-gray-500 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition-colors" title="Renew Subscription">
                                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                </a>
                                <?php if (Auth::isAdmin()): ?>
                                    <a href="delete.php?id=<?php echo $member['id']; ?>" 
                                       onclick="return confirmDelete('Are you sure you want to delete this member? This action cannot be undone.')"
                                       class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="6" class="px-5 py-16 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center">
                                    <i data-lucide="users" class="w-8 h-8 text-gray-400"></i>
                                </div>
                                <p class="text-gray-500">No members found</p>
                                <a href="create.php" class="text-blue-600 hover:underline text-sm">Add your first member</a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="flex items-center justify-between px-5 py-4 border-t border-gray-100">
            <p class="text-sm text-gray-500">
                Showing <?php echo $pagination['offset'] + 1; ?> - <?php echo min($pagination['offset'] + $pagination['per_page'], $pagination['total']); ?> of <?php echo $pagination['total']; ?> members
            </p>
            <div class="flex items-center gap-1">
                <?php if ($pagination['has_previous']): ?>
                    <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>&plan=<?php echo $plan; ?>"
                       class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($pagination['total_pages'], $page + 2); $i++): ?>
                    <a href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>&plan=<?php echo $plan; ?>"
                       class="px-3 py-1.5 rounded-lg text-sm font-medium <?php echo $i === $page ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?> transition-colors">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                <?php if ($pagination['has_next']): ?>
                    <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>&plan=<?php echo $plan; ?>"
                       class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
