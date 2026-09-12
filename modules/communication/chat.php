<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('communication', 'view');
$pageTitle = 'Chat';
$pageDescription = 'Member chat interface';
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-8 text-center">
    <div class="w-16 h-16 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-4">
        <i data-lucide="message-circle" class="w-8 h-8 text-blue-500"></i>
    </div>
    <h3 class="text-lg font-semibold text-gray-900 mb-2">Chat Module</h3>
    <p class="text-gray-500 max-w-md mx-auto mb-4">The chat module provides real-time messaging between staff and members. Integration with WebSocket server is required for full functionality.</p>
    <div class="text-sm text-gray-400">
        <p>To enable real-time chat:</p>
        <ol class="text-left max-w-md mx-auto mt-2 space-y-1">
            <li>1. Install a WebSocket server (Ratchet or Socket.IO)</li>
            <li>2. Configure connection settings in the admin panel</li>
            <li>3. Members can access chat via their portal</li>
        </ol>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
