/**
 * Common Admin JavaScript
 * Shared functionality for sidebar toggle and theme switching
 */

// Theme Toggle Functionality
(function () {
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    
    if (!themeToggle || !themeIcon) return;

    // Get saved theme preference or default to dark
    const savedTheme = localStorage.getItem('theme') || 'dark';
    const isLightMode = savedTheme === 'light';

    // Apply saved theme on page load
    if (isLightMode) {
        document.body.classList.add('light-mode');
        themeIcon.textContent = '☀️';
    } else {
        document.body.classList.remove('light-mode');
        themeIcon.textContent = '🌙';
    }

    // Toggle theme on button click
    themeToggle.addEventListener('click', function() {
        const isCurrentlyLight = document.body.classList.contains('light-mode');
        
        if (isCurrentlyLight) {
            // Switch to dark mode
            document.body.classList.remove('light-mode');
            themeIcon.textContent = '🌙';
            localStorage.setItem('theme', 'dark');
        } else {
            // Switch to light mode
            document.body.classList.add('light-mode');
            themeIcon.textContent = '☀️';
            localStorage.setItem('theme', 'light');
        }
        
        // Trigger custom event for chart updates
        setTimeout(() => {
            window.dispatchEvent(new CustomEvent('themeChanged'));
        }, 100);
    });
})();

// Sidebar Toggle Functionality
(function () {
    const toggle = document.getElementById('sidebarToggle');
    if (!toggle) return;

    const isMobile = () => window.matchMedia('(max-width: 900px)').matches;

    const setAriaExpanded = (expanded) => {
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };

    const toggleSidebar = () => {
        if (isMobile()) {
            document.body.classList.toggle('sidebar-open');
            setAriaExpanded(document.body.classList.contains('sidebar-open'));
            return;
        }

        document.body.classList.toggle('sidebar-collapsed');
        // Expanded = not collapsed
        setAriaExpanded(!document.body.classList.contains('sidebar-collapsed'));
    };

    toggle.addEventListener('click', toggleSidebar);
    toggle.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleSidebar();
        }
    });

    // Keep ARIA state correct on resize
    window.addEventListener('resize', () => {
        if (isMobile()) {
            setAriaExpanded(document.body.classList.contains('sidebar-open'));
        } else {
            document.body.classList.remove('sidebar-open');
            setAriaExpanded(!document.body.classList.contains('sidebar-collapsed'));
        }
    });
})();

// Dropdown Menu Toggle Functionality
(function () {
    const userManagementToggle = document.getElementById('userManagementToggle');
    const userManagementDropdown = userManagementToggle?.closest('.nav-dropdown');
    
    if (!userManagementToggle || !userManagementDropdown) return;

    // Check if any child route is active to open dropdown on page load
    const isActive = userManagementToggle.classList.contains('active');
    if (isActive) {
        userManagementDropdown.classList.add('open');
    }

    // Toggle dropdown on click
    userManagementToggle.addEventListener('click', function(e) {
        e.preventDefault();
        userManagementDropdown.classList.toggle('open');
    });

    // Close dropdown when clicking outside (optional)
    document.addEventListener('click', function(e) {
        if (!userManagementDropdown.contains(e.target)) {
            // Don't close if clicking on a dropdown item
            if (!e.target.closest('.nav-dropdown-item')) {
                userManagementDropdown.classList.remove('open');
            }
        }
    });
})();

// Helper function to get CSS variable value
function getCSSVariable(variable) {
    return getComputedStyle(document.documentElement).getPropertyValue(variable).trim();
}
