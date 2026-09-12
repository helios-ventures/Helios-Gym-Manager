<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
Auth::requirePermission('settings', 'view');
if (!Auth::isAdmin()) { \Gym\Core\Helper::redirect('/index.php', 'warning', 'Access denied'); }
$pageTitle = 'Backup & Restore';
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="max-w-2xl mx-auto bg-white rounded-xl border border-gray-100 shadow-sm p-6">
    <div class="flex items-center gap-3 mb-6">
        <div class="w-12 h-12 rounded-full bg-green-50 flex items-center justify-center">
            <i data-lucide="database" class="w-6 h-6 text-green-600"></i>
        </div>
        <div>
            <h3 class="font-semibold text-gray-900">Database Backup</h3>
            <p class="text-sm text-gray-500">Export your gym data for safekeeping</p>
        </div>
    </div>
    <div class="space-y-4">
        <div class="p-4 bg-gray-50 rounded-lg">
            <h4 class="font-medium text-gray-900 mb-2">Manual Backup</h4>
            <p class="text-sm text-gray-500 mb-3">Create a full database backup now. The SQL file will be downloaded to your device.</p>
            <a href="../../database.sql" download class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
                <i data-lucide="download" class="w-4 h-4"></i> Download Database Schema
            </a>
        </div>
        <div class="p-4 bg-gray-50 rounded-lg">
            <h4 class="font-medium text-gray-900 mb-2">Auto Backup Settings</h4>
            <p class="text-sm text-gray-500">Configure automatic backups via cron jobs. Add this to your crontab:</p>
            <code class="block mt-2 p-3 bg-gray-800 text-green-400 rounded-lg text-xs font-mono">0 2 * * * mysqldump -u root gym_management > /backups/gym_$(date +\%Y\%m\%d).sql</code>
        </div>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
