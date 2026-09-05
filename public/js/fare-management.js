/**
 * Fare Management dashboard - form and per-row save behavior
 */
(function () {
    'use strict';

    const form = document.getElementById('fareMatrixForm');
    if (!form) return;

    // Optional: per-row "Save" button (for future API: save single route)
    document.querySelectorAll('.fare-btn-save').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const route = this.getAttribute('data-route');
            if (route) {
                // For now, submit full form; later can POST single route via API
                form.submit();
            }
        });
    });

    // Ensure number inputs don't submit non-numeric values
    form.addEventListener('submit', function () {
        document.querySelectorAll('.fare-input').forEach(function (input) {
            if (input.value === '') return;
            const num = parseFloat(input.value);
            if (isNaN(num) || num < 0) input.value = '0';
        });
    });
})();
