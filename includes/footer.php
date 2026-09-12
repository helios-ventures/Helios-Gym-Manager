<?php
/**
 * Footer Template
 */
?>
    </main>
    
    <!-- Footer -->
    <footer class="border-t border-gray-200 bg-white px-4 lg:px-8 py-4 mt-auto">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-2 text-sm text-gray-500">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($settings['gym_name'] ?? 'FitLife Gym'); ?>. All rights reserved.</p>
            <p>Powered by <span class="font-medium">Gym Management System</span> v<?php echo APP_VERSION; ?></p>
        </div>
    </footer>
    
</div><!-- /mainWrapper -->

<!-- Global JavaScript -->
<script>
    // Initialize Lucide icons
    lucide.createIcons();
    
    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
    }
    
    // Sidebar toggle for desktop
    let sidebarCollapsed = false;
    function toggleSidebarDesktop() {
        const sidebar = document.getElementById('sidebar');
        const mainWrapper = document.getElementById('mainWrapper');
        sidebarCollapsed = !sidebarCollapsed;
        
        if (sidebarCollapsed) {
            sidebar.style.width = '80px';
            sidebar.classList.add('sidebar-collapsed');
            mainWrapper.style.marginLeft = '80px';
            mainWrapper.classList.remove('lg:ml-72');
            mainWrapper.classList.add('lg:ml-20');
            
            // Hide text elements
            document.querySelectorAll('#sidebar .nav-group span, #sidebar .submenu, #sidebar .text-xs, #sidebar .font-bold').forEach(el => {
                el.style.display = 'none';
            });
        } else {
            sidebar.style.width = '';
            sidebar.classList.remove('sidebar-collapsed');
            mainWrapper.style.marginLeft = '';
            mainWrapper.classList.add('lg:ml-72');
            mainWrapper.classList.remove('lg:ml-20');
            
            document.querySelectorAll('#sidebar .nav-group span, #sidebar .submenu, #sidebar .text-xs, #sidebar .font-bold').forEach(el => {
                el.style.display = '';
            });
        }
    }
    
    // Submenu toggle
    function toggleMenu(element) {
        const submenu = element.nextElementSibling;
        const chevron = element.querySelector('[data-lucide="chevron-down"]');
        
        if (submenu) {
            submenu.classList.toggle('max-h-0');
            submenu.classList.toggle('max-h-64');
            submenu.classList.toggle('opacity-0');
            submenu.classList.toggle('opacity-100');
            
            if (chevron) {
                chevron.classList.toggle('rotate-180');
            }
        }
    }
    
    // Notifications toggle
    function toggleNotifications() {
        const panel = document.getElementById('notificationsPanel');
        const profilePanel = document.getElementById('profilePanel');
        profilePanel.classList.add('hidden');
        panel.classList.toggle('hidden');
    }
    
    // Profile toggle
    function toggleProfile() {
        const panel = document.getElementById('profilePanel');
        const notifPanel = document.getElementById('notificationsPanel');
        notifPanel.classList.add('hidden');
        panel.classList.toggle('hidden');
    }
    
    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#notificationsPanel') && !e.target.closest('[onclick="toggleNotifications()"]')) {
            document.getElementById('notificationsPanel').classList.add('hidden');
        }
        if (!e.target.closest('#profilePanel') && !e.target.closest('[onclick="toggleProfile()"]')) {
            document.getElementById('profilePanel').classList.add('hidden');
        }
    });
    
    // Auto-remove flash messages after 5 seconds
    setTimeout(() => {
        const flashContainer = document.getElementById('flashContainer');
        if (flashContainer) {
            flashContainer.querySelectorAll('.toast').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateX(100%)';
                el.style.transition = 'all 0.3s ease';
                setTimeout(() => el.remove(), 300);
            });
        }
    }, 5000);
    
    // Confirm delete
    function confirmDelete(message = 'Are you sure you want to delete this item?') {
        return confirm(message);
    }
    
    // Show loading spinner
    function showLoading(element) {
        element.innerHTML = '<div class="spinner w-5 h-5"></div>';
        element.disabled = true;
    }
    
    // Format money
    function formatMoney(amount) {
        return '<?php echo $settings['currency_symbol'] ?? "Ksh"; ?> ' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }
    
    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // AJAX helper
    async function fetchAPI(url, options = {}) {
        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...options.headers
                },
                ...options
            });
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            return { success: false, message: 'Network error occurred' };
        }
    }
    
    // Initialize tooltips
    document.querySelectorAll('[title]').forEach(el => {
        el.addEventListener('mouseenter', function() {
            // Simple tooltip implementation
        });
    });
    
    // Keep session alive
    setInterval(() => {
        fetch('<?php echo BASE_URL; ?>/api/keepalive.php').catch(() => {});
    }, 60000); // Every minute
</script>

<?php if (isset($extraJs)): ?>
    <?php echo $extraJs; ?>
<?php endif; ?>

</body>
</html>
