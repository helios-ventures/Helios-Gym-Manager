<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requireAuth();
$user = Auth::user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Database::execute("UPDATE users SET first_name=?, last_name=?, email=?, phone=? WHERE id=?", [$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $user['id']]);
    if (!empty($_POST['password'])) {
        Database::execute("UPDATE users SET password_hash=? WHERE id=?", [\Gym\Core\Auth::hashPassword($_POST['password']), $user['id']]);
    }
    \Gym\Core\Session::setFlash('success', 'Profile updated');
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}
$pageTitle = 'My Profile';
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="max-w-lg mx-auto bg-white rounded-xl border border-gray-100 shadow-sm p-6">
    <div class="text-center mb-6">
        <div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-2xl font-bold mx-auto mb-3">
            <?php echo strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)); ?>
        </div>
        <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></h3>
        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($user['role_name']); ?></p>
    </div>
    <form method="POST" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">First Name</label><input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label><input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Phone</label><input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">New Password (leave blank)</label><input type="password" name="password" class="w-full px-3 py-2 border border-gray-200 rounded-lg" placeholder="Leave blank to keep"></div>
        <button type="submit" class="w-full py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">Update Profile</button>
    </form>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
