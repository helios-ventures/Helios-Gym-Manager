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

<!--
  Real-time check-in toast. Polls attendance_logs every 7s and pops a toast
  for any new check-in. How "real-time" this is depends on ingestion path:
    - Instant if the device has been registered via "Enable Real-Time Events"
      in Attendance > Devices (api/hikvision-webhook.php inserts the moment
      the device pushes the event).
    - Bounded by the cron interval if relying on cron/pull_attendance.php alone.
  Either way this script just displays whatever's newest in the table.
-->
<script>
(function () {
    const POLL_URL = '<?php echo BASE_URL; ?>/api/recent-checkins.php';
    let lastId = parseInt(localStorage.getItem('gym_last_checkin_id') || '0', 10);

    function showCheckinToast(evt) {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-5 right-5 bg-white border-l-4 rounded-lg shadow-md px-4 py-3 flex items-center gap-3 z-50';
        toast.style.borderLeftColor = '#0f8e73';
        toast.style.animation = 'gymToastIn 0.25s ease-out';
        toast.innerHTML = `
            <div style="background:#0f8e7318;color:#0f8e73" class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900">${evt.first_name} ${evt.last_name} checked in</p>
                <p class="text-xs text-gray-500">${evt.member_code} &middot; just now</p>
            </div>
        `;
        document.body.appendChild(toast);
        if (window.lucide) lucide.createIcons();
        setTimeout(() => toast.remove(), 6000);
    }

    async function pollCheckins() {
        try {
            const res = await fetch(POLL_URL + '?since_id=' + lastId);
            const data = await res.json();
            (data.events || []).forEach(evt => {
                showCheckinToast(evt);
                lastId = Math.max(lastId, evt.id);
            });
            if (data.events && data.events.length) {
                localStorage.setItem('gym_last_checkin_id', lastId);
            }
        } catch (e) { /* silent - non-critical background poll */ }
        setTimeout(pollCheckins, 7000);
    }

    async function seedThenPoll() {
        if (!localStorage.getItem('gym_last_checkin_id')) {
            try {
                const res = await fetch(POLL_URL + '?seed=1');
                const data = await res.json();
                lastId = data.max_id || 0;
                localStorage.setItem('gym_last_checkin_id', lastId);
            } catch (e) { /* fall through and poll from 0 */ }
        }
        pollCheckins();
    }

    seedThenPoll();
})();
</script>
<style>
@keyframes gymToastIn {
    from { transform: translateY(10px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}
</style>

</body>
</html>
