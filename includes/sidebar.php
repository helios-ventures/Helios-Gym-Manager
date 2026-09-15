<?php
/**
 * Sidebar Navigation Component
 */

use Gym\Core\Auth;
use Gym\Core\Helper;
use Gym\Core\Session;  // <-- ADD THIS LINE

$currentUser = Auth::user();
$navigation = Auth::getNavigation();
$currentUrl = $_SERVER['REQUEST_URI'];
$settings = Auth::getSettings();
$gymName = $settings['gym_name'] ?? 'FitLife Gym';
?>

<!-- Mobile Sidebar Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 sidebar-overlay z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside id="sidebar" class="fixed top-0 left-0 h-full w-72 bg-sidebar-bg text-white z-50 sidebar-transition transform -translate-x-full lg:translate-x-0 flex flex-col">
    
    <!-- Sidebar Header -->
    <div class="flex items-center justify-between px-6 py-5 border-b border-gray-700">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center shadow-lg">
                <i data-lucide="dumbbell" class="w-5 h-5 text-white"></i>
            </div>
            <div class="flex flex-col">
                <span class="font-bold text-lg leading-tight"><?php echo htmlspecialchars($gymName); ?></span>
                <span class="text-xs text-gray-400">Management System</span>
            </div>
        </div>
        <!-- Close button for mobile -->
        <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-700 transition-colors">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>
    
    <!-- User Info Card
    <div class="mx-4 mt-4 p-4 rounded-xl bg-gray-800/50 border border-gray-700">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
                <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1) . substr($currentUser['last_name'] ?? 'S', 0, 1)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-medium text-sm truncate"><?php echo htmlspecialchars(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')); ?></p>
                <p class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars($currentUser['role_name'] ?? 'User'); ?></p>
            </div>
        </div>
    </div>  -->
    
    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        <?php foreach ($navigation as $item): ?>
            <?php 
            $hasPermission = true;
            if (isset($item['module']) && $item['url'] !== '#') {
                $hasPermission = Auth::can($item['module']);
            }
            
            $isActive = false;
            $hasActiveChild = false;
            
            if ($item['url'] !== '#') {
                $isActive = strpos($currentUrl, $item['url']) !== false;
            }
            
            foreach ($item['children'] as $child) {
                if (strpos($currentUrl, $child['url']) !== false) {
                    $hasActiveChild = true;
                    break;
                }
            }
            
            $menuOpen = $isActive || $hasActiveChild;
            ?>
            
            <?php if ($hasPermission): ?>
                <div class="nav-group">
                    <!-- Parent Menu Item -->
                    <a href="<?php echo $item['url'] === '#' ? 'javascript:void(0)' : BASE_URL . $item['url']; ?>"
                       onclick="<?php echo $item['url'] === '#' ? 'toggleMenu(this)' : ''; ?>"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo ($isActive || $hasActiveChild) ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/25' : 'text-gray-400 hover:text-white hover:bg-sidebar-hover'; ?>">
                        <i data-lucide="<?php echo $item['icon']; ?>" class="w-5 h-5 flex-shrink-0"></i>
                        <span class="flex-1"><?php echo $item['title']; ?></span>
                        <?php if (!empty($item['children'])): ?>
                            <i data-lucide="chevron-down" class="w-4 h-4 transition-transform duration-200 <?php echo $menuOpen ? 'rotate-180' : ''; ?>"></i>
                        <?php endif; ?>
                    </a>
                    
                    <!-- Submenu -->
                    <?php if (!empty($item['children'])): ?>
                        <div class="submenu overflow-hidden transition-all duration-200 <?php echo $menuOpen ? 'max-h-64 opacity-100' : 'max-h-0 opacity-0'; ?>">
                            <div class="ml-4 pl-4 border-l border-gray-700 mt-1 space-y-1">
                                <?php foreach ($item['children'] as $child): ?>
                                    <?php 
                                    $childActive = strpos($currentUrl, $child['url']) !== false;
                                    $canAccess = Auth::can($item['module'], $child['action'] ?? 'view');
                                    ?>
                                    <?php if ($canAccess): ?>
                                        <a href="<?php echo BASE_URL . $child['url']; ?>"
                                           class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-all duration-200 <?php echo $childActive ? 'bg-blue-600/20 text-blue-400 font-medium' : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800'; ?>">
                                            <div class="w-1.5 h-1.5 rounded-full <?php echo $childActive ? 'bg-blue-400' : 'bg-gray-600'; ?>"></div>
                                            <?php echo $child['title']; ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    
    <!-- Sidebar Footer -->
    <div class="px-4 py-4 border-t border-gray-700">
        <div class="space-y-2">
            <!-- Quick Actions -->
            
            <a href="<?php echo BASE_URL; ?>/modules/pos/index.php" 
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium bg-green-600 hover:bg-green-700 text-white transition-colors">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                Quick Sale
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/members/create.php" 
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium bg-purple-600 hover:bg-purple-700 text-white transition-colors">
                <i data-lucide="user-plus" class="w-4 h-4"></i>
                New Member
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/attendance/zkteco-import.php?device_id=<?php echo $defaultDevice['id'] ?? 0; ?>"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium bg-orange-600 hover:bg-red-700 text-white transition-colors">
                <i data-lucide="users" class="w-4 h-4"></i>
                Import Biometric User
                
            </a>
            <a href="?sync=device" 
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium bg-green-600 hover:bg-green-700 text-white transition-colors">
                <i data-lucide="fingerprint-pattern" class="w-4 h-4"></i>
                Sync Attendance
            </a>
        </div>
        
        <!-- Logout -->
        <a href="<?php echo BASE_URL; ?>/logout.php" 
           onclick="return confirm('Are you sure you want to logout?')"
           class="flex items-center gap-3 px-3 py-2 mt-3 rounded-lg text-sm font-medium text-red-400 hover:text-red-300 hover:bg-red-400/10 transition-colors">
            <i data-lucide="log-out" class="w-4 h-4"></i>
            Logout
        </a>
        
        <!-- Version -->
        <div class="mt-3 pt-3 border-t border-gray-700 text-center">
            <span class="text-xs text-gray-600">v<?php echo APP_VERSION; ?></span>
        </div>
    </div>
</aside>

<!-- Main Content Wrapper -->
<div id="mainWrapper" class="lg:ml-72 min-h-screen transition-all duration-300">
    
    <!-- Top Navigation Bar -->
    <header class="sticky top-0 z-30 bg-white/80 backdrop-blur-md border-b border-gray-200 px-4 lg:px-8 py-3">
        <div class="flex items-center justify-between">
            <!-- Left: Toggle & Breadcrumb -->
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 transition-colors">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <button onclick="toggleSidebarDesktop()" class="hidden lg:flex p-2 rounded-lg hover:bg-gray-100 transition-colors" title="Toggle Sidebar">
                    <i data-lucide="panel-left" class="w-5 h-5"></i>
                </button>
                <div class="hidden sm:flex items-center gap-2 text-sm text-gray-500">
                    <a href="<?php echo BASE_URL; ?>/index.php" class="hover:text-blue-600 transition-colors">Home</a>
                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($pageTitle); ?></span>
                </div>
            </div>
            
            <!-- Right: Search & Actions -->
            <div class="flex items-center gap-3">
                <!-- Global Search -->
                <div class="relative hidden md:block">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" 
                           id="globalSearch"
                           placeholder="Search members, invoices..."
                           class="pl-10 pr-4 py-2 w-64 bg-gray-100 border-0 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all">
                </div>
                
                <!-- Notifications -->
                <div class="relative">
                    <button onclick="toggleNotifications()" class="relative p-2 rounded-lg hover:bg-gray-100 transition-colors">
                        <i data-lucide="bell" class="w-5 h-5 text-gray-600"></i>
                        <span class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                    </button>
                    
                    <!-- Notifications Dropdown -->
                    <div id="notificationsPanel" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                            <h3 class="font-semibold text-sm">Notifications</h3>
                            <span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full">3</span>
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <a href="#" class="flex gap-3 px-4 py-3 hover:bg-gray-50 border-b border-gray-50">
                                <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="alert-triangle" class="w-4 h-4 text-yellow-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate">5 memberships expiring today</p>
                                    <p class="text-xs text-gray-500">2 hours ago</p>
                                </div>
                            </a>
                            <a href="#" class="flex gap-3 px-4 py-3 hover:bg-gray-50 border-b border-gray-50">
                                <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="user-plus" class="w-4 h-4 text-green-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate">New member registered</p>
                                    <p class="text-xs text-gray-500">3 hours ago</p>
                                </div>
                            </a>
                            <a href="#" class="flex gap-3 px-4 py-3 hover:bg-gray-50">
                                <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="box" class="w-4 h-4 text-blue-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate">Low stock alert: Whey Protein</p>
                                    <p class="text-xs text-gray-500">5 hours ago</p>
                                </div>
                            </a>
                        </div>
                        <div class="px-4 py-2 border-t border-gray-100 text-center">
                            <a href="#" class="text-xs text-blue-600 hover:underline">View all notifications</a>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button onclick="toggleProfile()" class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-white font-semibold text-xs">
                            <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <span class="hidden md:block text-sm font-medium"><?php echo htmlspecialchars($currentUser['first_name'] ?? 'User'); ?></span>
                        <i data-lucide="chevron-down" class="w-4 h-4 text-gray-400 hidden md:block"></i>
                    </button>
                    
                    <!-- Profile Dropdown -->
                    <div id="profilePanel" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100">
                            <p class="font-medium text-sm"><?php echo htmlspecialchars(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></p>
                        </div>
                        <div class="py-1">
                            <a href="<?php echo BASE_URL; ?>/modules/settings/profile.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                <i data-lucide="user" class="w-4 h-4"></i> My Profile
                            </a>
                            <a href="<?php echo BASE_URL; ?>/modules/settings/general.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                <i data-lucide="settings" class="w-4 h-4"></i> Settings
                            </a>
                        </div>
                        <div class="border-t border-gray-100 py-1">
                            <a href="<?php echo BASE_URL; ?>/logout.php" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                <i data-lucide="log-out" class="w-4 h-4"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Page Content -->
    <main class="p-4 lg:p-8">
        
        <!-- Flash Messages -->
        <?php $flashes = Session::getFlashes(); ?>
        <?php if (!empty($flashes)): ?>
            <div id="flashContainer" class="fixed top-20 right-4 z-50 space-y-2 w-80">
                <?php foreach ($flashes as $flash): ?>
                    <?php 
                    $flashColors = [
                        'success' => 'bg-green-50 border-green-400 text-green-800',
                        'danger' => 'bg-red-50 border-red-400 text-red-800',
                        'warning' => 'bg-yellow-50 border-yellow-400 text-yellow-800',
                        'info' => 'bg-blue-50 border-blue-400 text-blue-800',
                    ];
                    $flashIcon = [
                        'success' => 'check-circle',
                        'danger' => 'x-circle',
                        'warning' => 'alert-triangle',
                        'info' => 'info',
                    ];
                    $type = $flash['type'] ?? 'info';
                    $colorClass = $flashColors[$type] ?? $flashColors['info'];
                    $iconName = $flashIcon[$type] ?? 'info';
                    ?>
                    <div class="toast flex items-start gap-3 px-4 py-3 rounded-lg border-l-4 shadow-lg <?php echo $colorClass; ?>">
                        <i data-lucide="<?php echo $iconName; ?>" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                        <div class="flex-1 text-sm"><?php echo htmlspecialchars($flash['message']); ?></div>
                        <button onclick="this.parentElement.remove()" class="flex-shrink-0 hover:opacity-70">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Page Header -->
        <?php if (!isset($hidePageHeader) || !$hidePageHeader): ?>
            <div class="mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($pageTitle); ?></h1>
                        <?php if (isset($pageDescription)): ?>
                            <p class="mt-1 text-sm text-gray-500"><?php echo htmlspecialchars($pageDescription); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($pageActions)): ?>
                        <div class="flex items-center gap-2">
                            <?php echo $pageActions; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
