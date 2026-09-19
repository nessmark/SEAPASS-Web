/**
 * Boats page interactions: modal open/close
 */

document.addEventListener('DOMContentLoaded', () => {
    // Add Boat Modal
    const openBtn = document.getElementById('openBoatModal');
    const backdrop = document.getElementById('boatModalBackdrop');
    const closeBtn = document.getElementById('closeBoatModal');
    const cancelBtn = document.getElementById('cancelBoatModal');

    if (openBtn && backdrop) {
        const openModal = () => {
            backdrop.style.display = 'flex';
        };

        const closeModal = () => {
            backdrop.style.display = 'none';
        };

        openBtn.addEventListener('click', openModal);
        closeBtn?.addEventListener('click', closeModal);
        cancelBtn?.addEventListener('click', closeModal);

        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && backdrop.style.display === 'flex') {
                closeModal();
            }
        });
    }

    // Edit Boat Modal
    const editBackdrop = document.getElementById('editBoatModalBackdrop');
    const closeEditBtn = document.getElementById('closeEditBoatModal');
    const cancelEditBtn = document.getElementById('cancelEditBoatModal');

    if (editBackdrop) {
        const closeEditModal = () => {
            editBackdrop.style.display = 'none';
        };

        closeEditBtn?.addEventListener('click', closeEditModal);
        cancelEditBtn?.addEventListener('click', closeEditModal);

        editBackdrop.addEventListener('click', (e) => {
            if (e.target === editBackdrop) {
                closeEditModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && editBackdrop.style.display === 'flex') {
                closeEditModal();
            }
        });
    }
});

/**
 * Open edit modal and populate form with boat data
 */
function openEditModal(boatId, name, licenseNumber, passengerCapacity, owner, operator, boatNumber, status, imageUrl) {
    const editBackdrop = document.getElementById('editBoatModalBackdrop');
    const editForm = document.getElementById('editBoatForm');
    const currentImageDiv = document.getElementById('edit_current_image');

    // Set form action
    editForm.action = `/admin/boats/${boatId}`;

    // Populate form fields
    document.getElementById('edit_name').value = name || '';
    document.getElementById('edit_license_number').value = licenseNumber || '';
    document.getElementById('edit_passenger_capacity').value = passengerCapacity || '';
    document.getElementById('edit_owner').value = owner || '';
    document.getElementById('edit_operator').value = operator || '';
    document.getElementById('edit_boat_number').value = boatNumber || '';
    document.getElementById('edit_status').value = status || 'Active';

    // Show current image if exists
    if (imageUrl) {
        currentImageDiv.innerHTML = `
            <small style="color: var(--text-secondary); display: block; margin-bottom: 4px;">Current Image:</small>
            <img src="${imageUrl}" alt="Current boat image" style="max-width: 200px; max-height: 150px; border-radius: 4px; border: 1px solid var(--border-color);">
        `;
    } else {
        currentImageDiv.innerHTML = '';
    }

    // Show modal
    editBackdrop.style.display = 'flex';
}

