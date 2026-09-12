<?php
/**
 * Header Template - Included in all pages
 */

if (!defined('GYM_ACCESS')) {
    require_once dirname(__DIR__) . '/config/config.php';
}

use Gym\Core\Auth;
use Gym\Core\Session;

// Check authentication
if (!isset($publicPage) || !$publicPage) {
    Auth::requireAuth();
}

// Get current user
$currentUser = Auth::user();
$settings = Auth::getSettings();
$pageTitle = isset($pageTitle) ? $pageTitle : \Gym\Core\Helper::getPageTitle();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="<?php echo htmlspecialchars($settings['gym_name'] ?? APP_NAME); ?>">
    <title><?php echo htmlspecialchars($pageTitle); ?> | <?php echo htmlspecialchars($settings['gym_name'] ?? APP_NAME); ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo ASSETS_URL; ?>/images/favicon.ico">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    
    <!-- Custom Tailwind Config -->
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        sidebar: {
                            bg: '#0f172a',
                            hover: '#1e293b',
                            active: '#1e40af',
                            text: '#94a3b8',
                            textActive: '#ffffff'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    
    <!-- Custom Styles -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        * { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #64748b; }
        
        /* Sidebar transitions */
        .sidebar-transition { transition: transform 0.3s ease-in-out, width 0.3s ease-in-out; }
        
        /* Mobile sidebar overlay */
        .sidebar-overlay {
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }
        
        /* Card hover effect */
        .card-hover { transition: all 0.2s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        
        /* Button ripple */
        .btn { position: relative; overflow: hidden; }
        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s, height 0.3s;
        }
        .btn:active::after { width: 300px; height: 300px; }
        
        /* Data table */
        .data-table th { position: sticky; top: 0; z-index: 10; }
        .data-table tr:hover td { background-color: #f8fafc; }
        
        /* Form focus */
        .form-input:focus { 
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Toast notifications */
        .toast {
            animation: slideIn 0.3s ease, fadeOut 0.3s ease 4.7s forwards;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(100%); }
        }
        
        /* Loading spinner */
        .spinner {
            border: 3px solid #e2e8f0;
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Print styles */
        @media print {
            .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; }
        }
        
        /* Status badges */
        .badge-success { @apply bg-green-100 text-green-800 border-green-200; }
        .badge-warning { @apply bg-yellow-100 text-yellow-800 border-yellow-200; }
        .badge-danger { @apply bg-red-100 text-red-800 border-red-200; }
        .badge-info { @apply bg-blue-100 text-blue-800 border-blue-200; }
        .badge-secondary { @apply bg-gray-100 text-gray-800 border-gray-200; }
    </style>
    
    <?php if (isset($extraCss)): ?>
        <?php echo $extraCss; ?>
    <?php endif; ?>
</head>
<body class="bg-gray-50 text-gray-900 antialiased min-h-screen">
