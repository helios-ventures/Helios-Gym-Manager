<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\MemberAccess;
use Gym\Core\Session;

Auth::requirePermission('members', 'edit');
$memberId = intval($_GET['id'] ?? 0);
$member = Database::fetchOne("SELECT * FROM members WHERE id = ?", [$memberId]);
if (!$member) { Helper::redirect('/modules/members/index.php', 'warning', 'Member not found'); }

$plans = Database::fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $planId = intval($_POST['plan_id']);
    $plan = Database::fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$planId]);
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$plan['duration_days']} days"));
    
    Database::execute("UPDATE member_subscriptions SET status='expired' WHERE member_id=? AND status='active'", [$memberId]);
    Database::insert("INSERT INTO member_subscriptions (member_id, plan_id, start_date, end_date, amount_paid, payment_method, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'active', ?)", [$memberId, $planId, $startDate, $endDate, $plan['price'], $_POST['payment_method'] ?? 'cash', \Gym\Core\Auth::id()]);
    Database::execute("UPDATE members SET expiry_date=?, status='active' WHERE id=?", [$endDate, $memberId]);

    // Restore biometric door access now that the membership is active again
    // (unless staff had manually disabled this member - see MemberAccess).
    // Re-fetch the member so this sees the freshly updated expiry_date/status.
    $renewedMember = Database::fetchOne("SELECT * FROM members WHERE id = ?", [$memberId]);
    if (!empty($renewedMember['biometric_id'])) {
        $accessResult = MemberAccess::syncFromMembershipState($renewedMember);
        if (!$accessResult['success']) {
            Session::setFlash('warning', 'Renewed, but device access sync failed: ' . $accessResult['message'] . '. Retry from the member profile.');
        }
    }

    \Gym\Core\Auth::logActivity('subscription_renew', "Renewed subscription for {$member['member_code']}");
    Helper::redirect('/modules/members/view.php?id=' . $memberId, 'success', 'Subscription renewed successfully! New expiry: ' . Helper::date($endDate));
}
$pageTitle = 'Renew Subscription';
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="max-w-lg mx-auto bg-white rounded-xl border border-gray-100 shadow-sm p-6">
    <h3 class="font-semibold text-gray-900 mb-2">Renew for <?php echo htmlspecialchars($member['first_name'].' '.$member['last_name']); ?></h3>
    <p class="text-sm text-gray-500 mb-4">Current expiry: <?php echo $member['expiry_date'] ? Helper::date($member['expiry_date']) : 'None'; ?></p>
    <form method="POST" class="space-y-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Select Plan</label><select name="plan_id" required class="w-full px-3 py-2 border border-gray-200 rounded-lg">
            <?php foreach ($plans as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> - <?php echo Helper::money($p['price']); ?> / <?php echo $p['duration_days']; ?> days</option><?php endforeach; ?>
        </select></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label><select name="payment_method" class="w-full px-3 py-2 border border-gray-200 rounded-lg"><option value="cash">Cash</option><option value="card">Card</option><option value="mpesa">M-Pesa</option><option value="mobile_money">Mobile Money</option><option value="bank_transfer">Bank Transfer</option></select></div>
        <div class="flex gap-3"><button type="submit" class="px-6 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-medium flex items-center gap-2"><i data-lucide="refresh-cw" class="w-4 h-4"></i> Renew</button><a href="view.php?id=<?php echo $memberId; ?>" class="px-6 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</a></div>
    </form>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
