<?php
/**
 * User Management - Settings Module
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;

Auth::requirePermission('settings', 'view');

$pageTitle = 'User Management';
$pageDescription = 'Manage system users and their roles';

// Only super admin and admin can manage users
if (!Auth::isAdmin()) {
    Helper::redirect('/index.php', 'warning', 'Access denied');
}

// Add/Edit user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $userId = intval($_POST['user_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $roleId = intval($_POST['role_id'] ?? 3);
    $password = $_POST['password'] ?? '';
    $status = $_POST['status'] ?? 'active';
    
    try {
        if ($userId) {
            // Update
            $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, role_id = ?, status = ?";
            $params = [$firstName, $lastName, $email, $phone, $roleId, $status];
            
            if (!empty($password)) {
                $sql .= ", password_hash = ?";
                $params[] = Auth::hashPassword($password);
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $userId;
            
            Database::execute($sql, $params);
            Session::setFlash('success', 'User updated successfully');
        } else {
            // Create
            if (empty($password)) {
                Session::setFlash('danger', 'Password is required for new users');
            } else {
                Database::insert(
                    "INSERT INTO users (username, password_hash, email, phone, first_name, last_name, role_id, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$username, Auth::hashPassword($password), $email, $phone, $firstName, $lastName, $roleId, $status]
                );
                Session::setFlash('success', 'User created successfully');
            }
        }
    } catch (\Exception $e) {
        Session::setFlash('danger', 'Error: ' . $e->getMessage());
    }
    
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Toggle status
if (isset($_GET['toggle']) && $toggleId = intval($_GET['toggle'])) {
    $current = Database::fetchOne("SELECT status FROM users WHERE id = ?", [$toggleId]);
    if ($current) {
        $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
        Database::execute("UPDATE users SET status = ? WHERE id = ?", [$newStatus, $toggleId]);
        Session::setFlash('success', 'User status updated to ' . $newStatus);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Delete user
if (isset($_GET['delete']) && $deleteId = intval($_GET['delete'])) {
    // Prevent deleting yourself
    if ($deleteId === Auth::id()) {
        Session::setFlash('danger', 'Cannot delete your own account');
    } else {
        Database::execute("DELETE FROM users WHERE id = ?", [$deleteId]);
        Session::setFlash('success', 'User deleted');
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Get users
$users = Database::fetchAll(
    "SELECT u.*, r.name as role_name, r.slug as role_slug 
     FROM users u 
     JOIN roles r ON u.role_id = r.id 
     ORDER BY u.created_at DESC"
);

// Get roles
$roles = Database::fetchAll("SELECT * FROM roles ORDER BY id");

// Get user for edit
$editUser = null;
if (isset($_GET['edit']) && $editId = intval($_GET['edit'])) {
    $editUser = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$editId]);
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- User Form -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 sticky top-24">
            <h3 class="font-semibold text-gray-900 mb-4"><?php echo $editUser ? 'Edit User' : 'Add User'; ?></h3>
            
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="user_id" value="<?php echo $editUser['id'] ?? ''; ?>">
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($editUser['first_name'] ?? ''); ?>" required
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($editUser['last_name'] ?? ''); ?>" required
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username" value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>" required <?php echo $editUser ? 'readonly class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50"' : 'class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"'; ?>>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($editUser['phone'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                    <select name="role_id" required
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo ($editUser['role_id'] ?? '') == $role['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['name']); ?> - <?php echo htmlspecialchars($role['description'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password <?php echo $editUser ? '(leave blank to keep)' : '*'; ?></label>
                    <input type="password" name="password" <?php echo $editUser ? '' : 'required'; ?>
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                           placeholder="<?php echo $editUser ? 'Leave blank to keep' : 'Enter password'; ?>">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        <option value="active" <?php echo ($editUser['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($editUser['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo ($editUser['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                
                <div class="flex gap-2 pt-2">
                    <button type="submit" name="save_user" class="flex-1 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">
                        <?php echo $editUser ? 'Update' : 'Create'; ?> User
                    </button>
                    <?php if ($editUser): ?>
                        <a href="users.php" class="px-4 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 text-sm">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Users List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900">All Users</h3>
                <span class="text-sm text-gray-500"><?php echo count($users); ?> users</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 font-medium text-gray-500">User</th>
                            <th class="text-left px-5 py-3 font-medium text-gray-500">Role</th>
                            <th class="text-center px-5 py-3 font-medium text-gray-500">Status</th>
                            <th class="text-left px-5 py-3 font-medium text-gray-500">Last Login</th>
                            <th class="text-center px-5 py-3 font-medium text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold text-xs">
                                            <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                                            <p class="text-xs text-gray-500">@<?php echo htmlspecialchars($user['username']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium
                                        <?php echo $user['role_slug'] === 'super_admin' ? 'bg-red-100 text-red-700' : ($user['role_slug'] === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700'); ?>">
                                        <?php echo htmlspecialchars($user['role_name']); ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="badge-<?php echo Helper::statusBadge($user['status']); ?> px-2 py-0.5 text-xs rounded-full border">
                                        <?php echo ucfirst($user['status']); ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-gray-500"><?php echo $user['last_login'] ? Helper::relativeTime($user['last_login']) : 'Never'; ?></td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="?edit=<?php echo $user['id']; ?>" class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Edit">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </a>
                                        <a href="?toggle=<?php echo $user['id']; ?>" class="p-2 text-gray-500 hover:text-yellow-600 hover:bg-yellow-50 rounded-lg transition-colors" title="Toggle Status">
                                            <i data-lucide="power" class="w-4 h-4"></i>
                                        </a>
                                        <?php if ($user['id'] !== Auth::id()): ?>
                                            <a href="?delete=<?php echo $user['id']; ?>" onclick="return confirmDelete('Delete this user?')" 
                                               class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400">No users found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Role Info -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-900 mb-4">Role Definitions</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="p-4 bg-red-50 rounded-lg border border-red-100">
                    <h4 class="font-semibold text-red-800 text-sm">Super Admin</h4>
                    <p class="text-xs text-red-600 mt-1">Full system access. Can manage everything including users and settings.</p>
                </div>
                <div class="p-4 bg-purple-50 rounded-lg border border-purple-100">
                    <h4 class="font-semibold text-purple-800 text-sm">Admin</h4>
                    <p class="text-xs text-purple-600 mt-1">Can manage members, attendance, POS, communication, and view reports.</p>
                </div>
                <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                    <h4 class="font-semibold text-blue-800 text-sm">Staff</h4>
                    <p class="text-xs text-blue-600 mt-1">Limited access. Can manage attendance and POS, send messages.</p>
                </div>
                <div class="p-4 bg-gray-50 rounded-lg border border-gray-100">
                    <h4 class="font-semibold text-gray-800 text-sm">Member</h4>
                    <p class="text-xs text-gray-600 mt-1">Portal access only. Can view own profile and attendance history.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
