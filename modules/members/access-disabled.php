<?php
/**
 * Disabled / Expired Members - anyone currently blocked from biometric access,
 * whether by auto-expiry or a manual staff toggle. This is the follow-up list.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;

Auth::requirePermission('members', 'view');

$pageTitle = 'Disabled / Expired Members';
$pageDescription = 'Members currently blocked from biometric access';

$search = $_GET['search'] ?? '';
$reason = $_GET['reason'] ?? '';

$params = [];
$where = ["m.biometric_enabled = 0"];

if ($reason && in_array($reason, ['expired', 'manual'], true)) {
    $where[] = "m.disabled_reason = ?";
    $params[] = $reason;
}

if ($search) {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ?)";
    $s = "%{$search}%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
}
$whereClause = implode(' AND ', $where);

$members = Database::fetchAll(
    "SELECT m.* FROM members m WHERE {$whereClause} ORDER BY m.expiry_date ASC LIMIT 300",
    $params
);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search member..."
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div class="w-48">
            <label class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
            <select name="reason" onchange="this.form.submit()" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="">All Reasons</option>
                <option value="expired" <?php echo $reason === 'expired' ? 'selected' : ''; ?>>Expired</option>
                <option value="manual" <?php echo $reason === 'manual' ? 'selected' : ''; ?>>Manually Disabled</option>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-medium">Filter</button>
    </form>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900">Access Disabled</h3>
        <span class="text-sm text-gray-500"><?php echo count($members); ?> member(s)</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Member</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Phone</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Reason</th>
                    <th class="text-left px-5 py-3 font-medium text-gray-500">Expiry</th>
                    <th class="text-center px-5 py-3 font-medium text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($members as $m): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($m['member_code']); ?></p>
                        </td>
                        <td class="px-5 py-3 text-gray-600"><?php echo htmlspecialchars($m['phone']); ?></td>
                        <td class="px-5 py-3">
                            <span class="px-2 py-0.5 text-xs rounded-full <?php echo $m['disabled_reason'] === 'expired' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                <?php echo $m['disabled_reason'] === 'expired' ? 'Expired' : 'Manually Disabled'; ?>
                            </span>
                        </td>
                        <td class="px-5 py-3 text-gray-600"><?php echo $m['expiry_date'] ? Helper::date($m['expiry_date']) : 'N/A'; ?></td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-center gap-2">
                                <a href="view.php?id=<?php echo $m['id']; ?>" class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 text-xs font-medium">View</a>
                                <?php if ($m['disabled_reason'] === 'expired'): ?>
                                    <a href="renew.php?id=<?php echo $m['id']; ?>" class="px-3 py-1.5 bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-100 text-xs font-medium">Renew</a>
                                <?php else: ?>
                                    <button type="button" onclick="enableAccess(<?php echo $m['id']; ?>, this)" class="px-3 py-1.5 bg-green-50 text-green-600 rounded-lg hover:bg-green-100 text-xs font-medium">Enable</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-400">No disabled members - everyone has access</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function enableAccess(memberId, btn) {
    if (!confirm('Re-enable biometric access for this member?')) return;

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'Working...';

    try {
        const response = await fetch('<?php echo BASE_URL; ?>/modules/members/toggle-access.php', {
            method: 'POST',
            body: new URLSearchParams({ member_id: memberId, action: 'enable' })
        });
        const result = await response.json();
        alert(result.message);
        if (result.success) {
            location.reload();
        } else {
            btn.disabled = false;
            btn.textContent = original;
        }
    } catch (e) {
        alert('Network error: ' + e.message);
        btn.disabled = false;
        btn.textContent = original;
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
