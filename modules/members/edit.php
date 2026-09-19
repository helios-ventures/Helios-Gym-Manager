<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\MemberAccess;

Auth::requirePermission('members', 'edit');
$memberId = intval($_GET['id'] ?? 0);
$member = Database::fetchOne("SELECT * FROM members WHERE id = ?", [$memberId]);
if (!$member) { Helper::redirect('/modules/members/index.php', 'warning', 'Member not found'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newExpiryDate = $_POST['expiry_date'] ?: null;

    Database::execute(
        "UPDATE members SET first_name=?, last_name=?, email=?, phone=?, gender=?, date_of_birth=?, address=?, emergency_contact_name=?, emergency_contact_phone=?, height_cm=?, target_weight_kg=?, fitness_goal=?, health_notes=?, expiry_date=? WHERE id=?",
        [$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['gender'], $_POST['date_of_birth'] ?: null, $_POST['address'], $_POST['emergency_contact_name'], $_POST['emergency_contact_phone'], $_POST['height_cm'] ?: null, $_POST['target_weight_kg'] ?: null, $_POST['fitness_goal'], $_POST['health_notes'], $newExpiryDate, $memberId]
    );

    \Gym\Core\Auth::logActivity('member_update', "Updated member {$member['member_code']}");

    // If the expiry date changed, keep the device in sync (this also covers
    // "extend membership by manual date adjustment" - a renewal isn't the
    // only way expiry_date moves).
    if ($newExpiryDate !== $member['expiry_date']) {
        $updatedMember = Database::fetchOne("SELECT * FROM members WHERE id = ?", [$memberId]);
        if (!empty($updatedMember['biometric_id'])) {
            $accessResult = MemberAccess::syncFromMembershipState($updatedMember);
            if (!$accessResult['success']) {
                Helper::redirect('/modules/members/view.php?id=' . $memberId, 'warning', 'Member updated, but device access sync failed: ' . $accessResult['message']);
            }
        }
    }

    Helper::redirect('/modules/members/view.php?id=' . $memberId, 'success', 'Member updated successfully');
}
$pageTitle = 'Edit Member';
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="max-w-2xl mx-auto bg-white rounded-xl border border-gray-100 shadow-sm p-6">
    <h3 class="font-semibold text-gray-900 mb-4">Edit Member: <?php echo htmlspecialchars($member['first_name'].' '.$member['last_name']); ?></h3>
    <form method="POST" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">First Name</label><input type="text" name="first_name" value="<?php echo htmlspecialchars($member['first_name']); ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label><input type="text" name="last_name" value="<?php echo htmlspecialchars($member['last_name']); ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Phone</label><input type="tel" name="phone" value="<?php echo htmlspecialchars($member['phone']); ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
        </div>
        <div class="grid grid-cols-3 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Gender</label><select name="gender" class="w-full px-3 py-2 border border-gray-200 rounded-lg"><option value="male" <?php echo $member['gender']==='male'?'selected':''; ?>>Male</option><option value="female" <?php echo $member['gender']==='female'?'selected':''; ?>>Female</option><option value="other" <?php echo $member['gender']==='other'?'selected':''; ?>>Other</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">DOB</label><input type="date" name="date_of_birth" value="<?php echo $member['date_of_birth']; ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Height (cm)</label><input type="number" name="height_cm" value="<?php echo $member['height_cm']; ?>" step="0.1" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Address</label><textarea name="address" rows="2" class="w-full px-3 py-2 border border-gray-200 rounded-lg"><?php echo htmlspecialchars($member['address'] ?? ''); ?></textarea></div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact</label><input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($member['emergency_contact_name'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Emergency Phone</label><input type="tel" name="emergency_contact_phone" value="<?php echo htmlspecialchars($member['emergency_contact_phone'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Target Weight (kg)</label><input type="number" name="target_weight_kg" value="<?php echo $member['target_weight_kg']; ?>" step="0.01" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Fitness Goal</label><select name="fitness_goal" class="w-full px-3 py-2 border border-gray-200 rounded-lg"><option value="general_fitness" <?php echo $member['fitness_goal']==='general_fitness'?'selected':''; ?>>General Fitness</option><option value="weight_loss" <?php echo $member['fitness_goal']==='weight_loss'?'selected':''; ?>>Weight Loss</option><option value="muscle_gain" <?php echo $member['fitness_goal']==='muscle_gain'?'selected':''; ?>>Muscle Gain</option><option value="maintenance" <?php echo $member['fitness_goal']==='maintenance'?'selected':''; ?>>Maintenance</option><option value="rehabilitation" <?php echo $member['fitness_goal']==='rehabilitation'?'selected':''; ?>>Rehabilitation</option></select></div>
        </div>

        <div class="p-4 bg-amber-50 border border-amber-100 rounded-lg">
            <label class="block text-sm font-medium text-gray-700 mb-1">Membership Expiry Date</label>
            <input type="date" name="expiry_date" value="<?php echo htmlspecialchars($member['expiry_date'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg">
            <p class="text-xs text-amber-700 mt-1">Manually adjusting this pushes to the biometric device immediately - extending it restores access (unless the member was manually disabled by staff), pulling it into the past disables access.</p>
        </div>

        <div><label class="block text-sm font-medium text-gray-700 mb-1">Health Notes</label><textarea name="health_notes" rows="3" class="w-full px-3 py-2 border border-gray-200 rounded-lg"><?php echo htmlspecialchars($member['health_notes'] ?? ''); ?></textarea></div>
        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">Save Changes</button>
            <a href="view.php?id=<?php echo $memberId; ?>" class="px-6 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</a>
        </div>
    </form>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
