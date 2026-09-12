<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
Auth::requirePermission('settings', 'view');
if (!Auth::isAdmin()) { \Gym\Core\Helper::redirect('/index.php', 'warning', 'Access denied'); }
$pageTitle = 'System Configuration';
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-8 text-center">
    <div class="w-16 h-16 rounded-full bg-indigo-50 flex items-center justify-center mx-auto mb-4">
        <i data-lucide="settings" class="w-8 h-8 text-indigo-500"></i>
    </div>
    <h3 class="text-lg font-semibold text-gray-900 mb-2">System Configuration</h3>
    <p class="text-gray-500 max-w-lg mx-auto">Advanced system settings including database configuration, backup scheduling, email/SMS provider setup, and integration settings.</p>
    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-w-3xl mx-auto">
        <a href="general.php" class="p-4 border border-gray-200 rounded-xl hover:border-blue-300 hover:bg-blue-50 transition-all text-left">
            <i data-lucide="sliders" class="w-6 h-6 text-blue-600 mb-2"></i>
            <p class="font-medium text-gray-900">General Settings</p>
            <p class="text-xs text-gray-500">Gym info, financial, communication</p>
        </a>
        <a href="users.php" class="p-4 border border-gray-200 rounded-xl hover:border-blue-300 hover:bg-blue-50 transition-all text-left">
            <i data-lucide="users" class="w-6 h-6 text-blue-600 mb-2"></i>
            <p class="font-medium text-gray-900">User Management</p>
            <p class="text-xs text-gray-500">Roles and permissions</p>
        </a>
        <a href="backup.php" class="p-4 border border-gray-200 rounded-xl hover:border-blue-300 hover:bg-blue-50 transition-all text-left">
            <i data-lucide="database" class="w-6 h-6 text-blue-600 mb-2"></i>
            <p class="font-medium text-gray-900">Backup & Restore</p>
            <p class="text-xs text-gray-500">Database backups</p>
        </a>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
