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
    Database::insert("INSERT INTO member_subscriptions (member_id, plan_id, start_date, end_date, amount_paid, payment_method, mpesa_code, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)", [$memberId, $planId, $startDate, $endDate, $plan['price'], $_POST['payment_method'] ?? 'cash', trim($_POST['mpesa_code'] ?? '') ?: null, \Gym\Core\Auth::id()]);
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
            <?php foreach ($plans as $p): ?><option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>"><?php echo htmlspecialchars($p['name']); ?> - <?php echo Helper::money($p['price']); ?> / <?php echo $p['duration_days']; ?> days</option><?php endforeach; ?>
        </select></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label><select name="payment_method" id="paymentMethod" class="w-full px-3 py-2 border border-gray-200 rounded-lg"><option value="cash">Cash</option><option value="card">Card</option><option value="mpesa">M-Pesa</option><option value="mobile_money">Mobile Money</option><option value="bank_transfer">Bank Transfer</option></select></div>

        <div id="mpesaFields" class="hidden space-y-3 p-4 bg-green-50 rounded-lg">
            <div class="flex gap-2">
                <input type="tel" id="mpesaPhone" placeholder="Phone for STK push e.g. 0712345678" value="<?php echo htmlspecialchars($member['phone']); ?>" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm">
                <button type="button" id="stkPushBtn" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium whitespace-nowrap">Send STK Push</button>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">M-Pesa Confirmation Code</label>
                <input type="text" name="mpesa_code" id="mpesaCode" placeholder="e.g. QGH7XXXXX1 (auto-fills after STK push, or type manually)" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono">
            </div>
            <p id="stkStatus" class="text-xs text-gray-500"></p>
        </div>

        <div class="flex gap-3"><button type="submit" class="px-6 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-medium flex items-center gap-2"><i data-lucide="refresh-cw" class="w-4 h-4"></i> Renew</button><a href="view.php?id=<?php echo $memberId; ?>" class="px-6 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</a></div>
    </form>
</div>

<script>
const paymentMethod = document.getElementById('paymentMethod');
const mpesaFields = document.getElementById('mpesaFields');
const planSelect = document.querySelector('select[name="plan_id"]');

function toggleMpesaFields() {
    mpesaFields.classList.toggle('hidden', paymentMethod.value !== 'mpesa');
}
paymentMethod.addEventListener('change', toggleMpesaFields);
toggleMpesaFields();

document.getElementById('stkPushBtn').addEventListener('click', async function () {
    const btn = this;
    const phone = document.getElementById('mpesaPhone').value.trim();
    const status = document.getElementById('stkStatus');
    const selectedOption = planSelect.options[planSelect.selectedIndex];

    if (!phone) { alert('Enter a phone number first'); return; }
    if (!planSelect.value) { alert('Select a plan first'); return; }

    const price = selectedOption.getAttribute('data-price');

    btn.disabled = true;
    btn.textContent = 'Sending...';
    status.textContent = '';

    try {
        const initRes = await fetch('<?php echo BASE_URL; ?>/api/mpesa-stk-push.php', {
            method: 'POST',
            body: new URLSearchParams({
                phone, amount: price, purpose: 'subscription',
                reference_id: <?php echo $memberId; ?>,
                account_reference: '<?php echo htmlspecialchars($member['member_code']); ?>',
                description: 'Gym Membership'
            })
        });
        const initData = await initRes.json();

        if (!initData.success) {
            status.textContent = initData.message;
            btn.disabled = false;
            btn.textContent = 'Send STK Push';
            return;
        }

        status.textContent = 'Waiting for the member to enter their PIN...';
        btn.textContent = 'Waiting...';

        const checkoutId = initData.checkout_request_id;
        let attempts = 0;

        const poll = setInterval(async () => {
            attempts++;
            const statusRes = await fetch('<?php echo BASE_URL; ?>/api/mpesa-status.php?checkout_request_id=' + encodeURIComponent(checkoutId));
            const statusData = await statusRes.json();

            if (statusData.status === 'completed') {
                clearInterval(poll);
                document.getElementById('mpesaCode').value = statusData.mpesa_receipt_number || '';
                status.textContent = 'Payment confirmed - code filled in below. Click Renew to finish.';
                btn.disabled = false;
                btn.textContent = 'Send STK Push';
            } else if (statusData.status === 'failed' || statusData.status === 'cancelled') {
                clearInterval(poll);
                status.textContent = 'Payment was not completed on the phone. Try again or use another method.';
                btn.disabled = false;
                btn.textContent = 'Send STK Push';
            } else if (attempts > 20) {
                clearInterval(poll);
                status.textContent = 'Still waiting - if the member paid, the code will appear once confirmed. You can also enter it manually above.';
                btn.disabled = false;
                btn.textContent = 'Send STK Push';
            }
        }, 3000);

    } catch (e) {
        status.textContent = 'Network error: ' + e.message;
        btn.disabled = false;
        btn.textContent = 'Send STK Push';
    }
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
