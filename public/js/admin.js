/**
 * Common Admin JavaScript
 * Shared functionality for sidebar toggle and theme switching
 */

// Inline SVG icons (no emoji in UI chrome — see DESIGN-SYSTEM.md)
const SP_ICONS = {
    sun: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>',
    moon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
};

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
        themeIcon.innerHTML = SP_ICONS.sun;
    } else {
        document.body.classList.remove('light-mode');
        themeIcon.innerHTML = SP_ICONS.moon;
    }

    // Toggle theme on button click
    themeToggle.addEventListener('click', function() {
        const isCurrentlyLight = document.body.classList.contains('light-mode');
        
        if (isCurrentlyLight) {
            // Switch to dark mode
            document.body.classList.remove('light-mode');
            themeIcon.innerHTML = SP_ICONS.moon;
            localStorage.setItem('theme', 'dark');
        } else {
            // Switch to light mode
            document.body.classList.add('light-mode');
            themeIcon.innerHTML = SP_ICONS.sun;
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
    // Native button keyboard activation already dispatches click.
    const closeSidebar = () => {
        document.body.classList.remove('sidebar-open');
        setAriaExpanded(!isMobile() && !document.body.classList.contains('sidebar-collapsed'));
    };
    document.getElementById('sidebarBackdrop')?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
    setAriaExpanded(isMobile() ? false : !document.body.classList.contains('sidebar-collapsed'));

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
        userManagementToggle.setAttribute('aria-expanded', userManagementDropdown.classList.contains('open') ? 'true' : 'false');
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

// Keep the stacked table layout understandable, including rows inserted by page scripts.
(function () {
    const labelRows = () => {
        document.querySelectorAll('table').forEach(table => {
            const headers = Array.from(table.querySelectorAll('thead th')).map(h => h.textContent.trim());
            table.querySelectorAll('tbody tr').forEach(row => {
                Array.from(row.children).forEach((cell, i) => {
                    if (cell.tagName === 'TD' && !cell.hasAttribute('data-label') && cell.colSpan === 1 && headers[i]) cell.dataset.label = headers[i];
                });
            });
        });
    };
    labelRows();
    new MutationObserver(labelRows).observe(document.getElementById('main-content'), { childList: true, subtree: true });
})();
