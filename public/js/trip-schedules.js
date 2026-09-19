/**
 * Trip & Schedule Management Interactions
 */

document.addEventListener('DOMContentLoaded', () => {
    // Tab switching
    const tabs = document.querySelectorAll('.trip-tab');
    const tabContents = document.querySelectorAll('.trip-tab-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const targetTab = tab.getAttribute('data-tab');
            
            // Remove active class from all tabs and contents
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding content
            tab.classList.add('active');
            document.getElementById(`tab-${targetTab}`).classList.add('active');
        });
    });

    // Schedule Modal
    const openScheduleBtn = document.getElementById('openScheduleModal');
    const scheduleModal = document.getElementById('scheduleModalBackdrop');
    const closeScheduleBtn = document.getElementById('closeScheduleModal');
    const cancelScheduleBtn = document.getElementById('cancelScheduleModal');
    const scheduleForm = document.getElementById('scheduleForm');

    if (openScheduleBtn && scheduleModal) {
        openScheduleBtn.addEventListener('click', () => {
            resetScheduleForm();
            scheduleModal.style.display = 'flex';
        });
    }

    // Open Recurring Rule Modal button
    const openRecurringModalBtn = document.getElementById('openRecurringModalBtn');
    const recurrenceSelect = document.getElementById('schedule_recurrence_type');
    if (openRecurringModalBtn && scheduleModal) {
        openRecurringModalBtn.addEventListener('click', () => {
            resetScheduleForm();
            if (recurrenceSelect) {
                recurrenceSelect.value = 'daily';
                applyRecurrenceVisibility('daily');
            }
            scheduleModal.style.display = 'flex';
        });
    }

    // Recurrence pattern change listener
    if (recurrenceSelect) {
        recurrenceSelect.addEventListener('change', function() {
            applyRecurrenceVisibility(this.value);
        });
    }

    // Ensure form action is correctly set on submission for recurring rule edits
    if (scheduleForm) {
        scheduleForm.addEventListener('submit', function() {
            const templateId = document.getElementById('schedule_template_id')?.value;
            const methodInput = document.querySelector('#scheduleFormMethod input[name="_method"]');
            if (templateId && methodInput && methodInput.value === 'PUT') {
                this.action = `/admin/recurring-schedules/${templateId}`;
            }
        });
    }

    // Auto-select tab from URL ?tab=recurring
    const urlParams = new URLSearchParams(window.location.search);
    const requestedTab = urlParams.get('tab');
    if (requestedTab) {
        const tabBtn = document.querySelector(`.trip-tab[data-tab="${requestedTab}"]`);
        if (tabBtn) {
            tabBtn.click();
        }
    }

    if (closeScheduleBtn) {
        closeScheduleBtn.addEventListener('click', closeScheduleModal);
    }

    if (cancelScheduleBtn) {
        cancelScheduleBtn.addEventListener('click', closeScheduleModal);
    }

    if (scheduleModal) {
        scheduleModal.addEventListener('click', (e) => {
            if (e.target === scheduleModal) {
                closeScheduleModal();
            }
        });
    }

    // Update available seats based on boat selection
    const boatSelect = document.getElementById('schedule_boat_id');
    if (boatSelect) {
        boatSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const capacity = selectedOption.getAttribute('data-capacity') || 0;
            const availableSeatsInput = document.getElementById('schedule_available_seats');
            if (availableSeatsInput) {
                availableSeatsInput.max = capacity;
                availableSeatsInput.value = capacity;
            }
        });
    }

    // Seat Map Modal
    const seatMapModal = document.getElementById('seatMapModalBackdrop');
    const closeSeatMapBtn = document.getElementById('closeSeatMapModal');

    if (closeSeatMapBtn) {
        closeSeatMapBtn.addEventListener('click', closeSeatMapModal);
    }

    if (seatMapModal) {
        seatMapModal.addEventListener('click', (e) => {
            if (e.target === seatMapModal) {
                closeSeatMapModal();
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (scheduleModal && scheduleModal.style.display === 'flex') {
                closeScheduleModal();
            }
            if (seatMapModal && seatMapModal.style.display === 'flex') {
                closeSeatMapModal();
            }
        }
    });

    startLiveTripStatusUpdates({
        scheduleModal,
        seatMapModal,
    });
});

function closeScheduleModal() {
    const scheduleModal = document.getElementById('scheduleModalBackdrop');
    if (scheduleModal) {
        scheduleModal.style.display = 'none';
        resetScheduleForm();
    }
}

function updateCapacityDisplay(boatSelectElem) {
    if (!boatSelectElem) return;
    const selectedOption = boatSelectElem.options[boatSelectElem.selectedIndex];
    const capacity = selectedOption ? (selectedOption.getAttribute('data-capacity') || 0) : 0;
    const availableSeatsInput = document.getElementById('schedule_available_seats');
    const boatCapacityInfo = document.getElementById('boat_capacity_info');
    const capacityVal = document.getElementById('capacity_val');

    if (availableSeatsInput && boatSelectElem.value) {
        availableSeatsInput.max = capacity;
        availableSeatsInput.value = capacity;
    }
    if (boatCapacityInfo) {
        boatCapacityInfo.textContent = boatSelectElem.value ? `Fleet Seat Capacity: ${capacity} seats` : 'Fleet Seat Capacity: —';
    }
    if (capacityVal) {
        capacityVal.textContent = boatSelectElem.value ? capacity : '—';
    }
}

function applyRecurrenceVisibility(recurrenceType) {
    const singleDateContainer = document.getElementById('singleDateContainer');
    const daysOfWeekContainer = document.getElementById('daysOfWeekContainer');
    const recurringDateContainer = document.getElementById('recurringDateContainer');
    const departureDateInput = document.getElementById('schedule_departure_date');
    const submitBtn = document.querySelector('#scheduleForm .trip-modal-save');
    const modalTitle = document.getElementById('scheduleModalTitle');
    const form = document.getElementById('scheduleForm');

    // Check if the form is currently in Edit mode (single schedule or recurring rule)
    const isEditMode = Boolean(
        form && (
            form.action.includes('/admin/trip-schedules/') ||
            form.action.includes('/admin/recurring-schedules/') ||
            document.querySelector('#scheduleFormMethod input[name="_method"]')
        )
    );

    if (recurrenceType === 'weekly') {
        if (singleDateContainer) singleDateContainer.style.display = 'none';
        if (departureDateInput) {
            departureDateInput.required = false;
            departureDateInput.value = '';
        }
        if (daysOfWeekContainer) daysOfWeekContainer.style.display = 'block';
        if (recurringDateContainer) recurringDateContainer.style.display = 'block';
        if (submitBtn) submitBtn.textContent = isEditMode ? 'Update Recurring Rule' : 'Save Recurring Rule';
        if (modalTitle && !modalTitle.textContent.includes('Edit')) {
            modalTitle.textContent = 'Add Recurring Schedule Rule';
        }
        if (form && !isEditMode) {
            form.action = '/admin/recurring-schedules';
        }
    } else if (recurrenceType === 'daily') {
        if (singleDateContainer) singleDateContainer.style.display = 'none';
        if (departureDateInput) {
            departureDateInput.required = false;
            departureDateInput.value = '';
        }
        if (daysOfWeekContainer) daysOfWeekContainer.style.display = 'none';
        if (recurringDateContainer) recurringDateContainer.style.display = 'block';
        if (submitBtn) submitBtn.textContent = isEditMode ? 'Update Recurring Rule' : 'Save Recurring Rule';
        if (modalTitle && !modalTitle.textContent.includes('Edit')) {
            modalTitle.textContent = 'Add Recurring Schedule Rule';
        }
        if (form && !isEditMode) {
            form.action = '/admin/recurring-schedules';
        }
    } else {
        // 'single'
        if (singleDateContainer) singleDateContainer.style.display = 'block';
        if (departureDateInput) {
            departureDateInput.required = true;
        }
        if (daysOfWeekContainer) daysOfWeekContainer.style.display = 'none';
        if (recurringDateContainer) recurringDateContainer.style.display = 'none';
        if (submitBtn) submitBtn.textContent = isEditMode ? 'Update Schedule' : 'Save Schedule';
        if (modalTitle && !modalTitle.textContent.includes('Edit')) {
            modalTitle.textContent = 'Add Trip Schedule';
        }
        if (form && !isEditMode) {
            form.action = '/admin/trip-schedules';
        }
    }
}

function resetScheduleForm() {
    const form = document.getElementById('scheduleForm');
    const formMethod = document.getElementById('scheduleFormMethod');
    const modalTitle = document.getElementById('scheduleModalTitle');
    const boatCapacityInfo = document.getElementById('boat_capacity_info');
    const capacityVal = document.getElementById('capacity_val');
    const recurrenceSelect = document.getElementById('schedule_recurrence_type');
    const recurrenceField = recurrenceSelect ? recurrenceSelect.closest('.trip-field') : null;
    const templateIdInput = document.getElementById('schedule_template_id');

    if (templateIdInput) {
        templateIdInput.value = '';
    }
    
    if (form) {
        form.action = '/admin/trip-schedules';
        form.reset();
    }
    
    if (formMethod) {
        formMethod.innerHTML = '';
    }
    
    if (modalTitle) {
        modalTitle.textContent = 'Add Trip Schedule';
    }

    if (recurrenceField) {
        recurrenceField.style.display = 'block';
    }

    if (recurrenceSelect) {
        recurrenceSelect.value = 'single';
    }

    // Uncheck any day pills
    document.querySelectorAll('.day-pill-input').forEach(cb => {
        cb.checked = false;
    });

    const untilInput = document.getElementById('schedule_effective_until');
    if (untilInput) untilInput.value = '';
    const arrTimeInput = document.getElementById('schedule_arrival_time');
    if (arrTimeInput) arrTimeInput.value = '';

    applyRecurrenceVisibility('single');

    if (boatCapacityInfo) {
        boatCapacityInfo.textContent = 'Fleet Seat Capacity: —';
    }
    if (capacityVal) {
        capacityVal.textContent = '—';
    }
}

function openEditScheduleModal(id, boatId, route, departureDate, departureSlot, availableSeats, notes) {
    const scheduleModal = document.getElementById('scheduleModalBackdrop');
    const scheduleForm = document.getElementById('scheduleForm');
    const formMethod = document.getElementById('scheduleFormMethod');
    const modalTitle = document.getElementById('scheduleModalTitle');
    const recurrenceSelect = document.getElementById('schedule_recurrence_type');
    const recurrenceField = recurrenceSelect ? recurrenceSelect.closest('.trip-field') : null;
    const templateIdInput = document.getElementById('schedule_template_id');

    if (templateIdInput) {
        templateIdInput.value = '';
    }
    
    if (scheduleForm) {
        scheduleForm.action = `/admin/trip-schedules/${id}`;
    }
    
    if (formMethod) {
        formMethod.innerHTML = '<input type="hidden" name="_method" value="PUT">';
    }
    
    if (modalTitle) {
        modalTitle.textContent = 'Edit Trip Schedule';
    }

    // Hide recurrence option when editing an existing single trip
    if (recurrenceField) {
        recurrenceField.style.display = 'none';
    }
    if (recurrenceSelect) {
        recurrenceSelect.value = 'single';
    }
    applyRecurrenceVisibility('single');
    
    // Populate form fields
    document.getElementById('schedule_boat_id').value = boatId;
    document.getElementById('schedule_route').value = route;
    document.getElementById('schedule_departure_date').value = departureDate;
    document.getElementById('schedule_departure_time_slot').value = departureSlot;
    document.getElementById('schedule_available_seats').value = availableSeats;
    document.getElementById('schedule_notes').value = notes || '';
    
    // Update max seats and capacity display based on selected boat
    const boatSelect = document.getElementById('schedule_boat_id');
    updateCapacityDisplay(boatSelect);
    document.getElementById('schedule_available_seats').value = availableSeats;
    
    if (scheduleModal) {
        scheduleModal.style.display = 'flex';
    }
}

function openEditRecurringModal(data) {
    const scheduleModal = document.getElementById('scheduleModalBackdrop');
    const scheduleForm = document.getElementById('scheduleForm');
    const formMethod = document.getElementById('scheduleFormMethod');
    const modalTitle = document.getElementById('scheduleModalTitle');
    const recurrenceSelect = document.getElementById('schedule_recurrence_type');
    const recurrenceField = recurrenceSelect ? recurrenceSelect.closest('.trip-field') : null;
    const submitBtn = document.querySelector('#scheduleForm .trip-modal-save');
    const templateIdInput = document.getElementById('schedule_template_id');

    if (templateIdInput) {
        templateIdInput.value = data.id;
    }
    
    if (formMethod) {
        formMethod.innerHTML = '<input type="hidden" name="_method" value="PUT">';
    }
    
    if (scheduleForm) {
        scheduleForm.action = `/admin/recurring-schedules/${data.id}`;
    }
    
    if (modalTitle) {
        modalTitle.textContent = 'Edit Recurring Schedule Rule';
    }

    if (submitBtn) {
        submitBtn.textContent = 'Update Recurring Rule';
    }

    if (recurrenceField) {
        recurrenceField.style.display = 'block';
    }

    const recType = data.recurrence_type || 'daily';
    if (recurrenceSelect) {
        recurrenceSelect.value = recType;
    }
    applyRecurrenceVisibility(recType);

    // Explicitly guarantee form action points to /admin/recurring-schedules/{id}
    if (scheduleForm) {
        scheduleForm.action = `/admin/recurring-schedules/${data.id}`;
    }

    // Populate form fields
    if (document.getElementById('schedule_boat_id')) {
        document.getElementById('schedule_boat_id').value = data.boat_id;
    }
    if (document.getElementById('schedule_route')) {
        document.getElementById('schedule_route').value = data.route;
    }
    if (document.getElementById('schedule_departure_time_slot')) {
        const timeSelect = document.getElementById('schedule_departure_time_slot');
        const formattedTime = (data.departure_time || '').substring(0, 5);
        let exists = false;
        for (let opt of timeSelect.options) {
            if (opt.value === formattedTime) {
                exists = true;
                break;
            }
        }
        if (!exists && formattedTime) {
            const newOpt = document.createElement('option');
            newOpt.value = formattedTime;
            newOpt.textContent = formattedTime;
            timeSelect.appendChild(newOpt);
        }
        timeSelect.value = formattedTime;
    }
    if (document.getElementById('schedule_arrival_time')) {
        document.getElementById('schedule_arrival_time').value = data.arrival_time ? (data.arrival_time.substring(0, 5)) : '';
    }
    if (document.getElementById('schedule_effective_until')) {
        document.getElementById('schedule_effective_until').value = data.effective_until || '';
    }
    if (document.getElementById('schedule_available_seats')) {
        document.getElementById('schedule_available_seats').value = data.available_seats;
    }
    if (document.getElementById('schedule_notes')) {
        document.getElementById('schedule_notes').value = data.notes || '';
    }

    // Set day checkboxes
    document.querySelectorAll('.day-pill-input').forEach(cb => {
        cb.checked = Array.isArray(data.days_of_week) && data.days_of_week.includes(cb.value);
    });

    const boatSelect = document.getElementById('schedule_boat_id');
    updateCapacityDisplay(boatSelect);
    if (document.getElementById('schedule_available_seats')) {
        document.getElementById('schedule_available_seats').value = data.available_seats;
    }

    if (scheduleModal) {
        scheduleModal.style.display = 'flex';
    }
}

window.openEditScheduleModal = openEditScheduleModal;
window.openEditRecurringModal = openEditRecurringModal;
window.applyRecurrenceVisibility = applyRecurrenceVisibility;

function viewSeatMap(tripId) {
    const seatMapModal = document.getElementById('seatMapModalBackdrop');
    const seatMapContainer = document.getElementById('seatMapContainer');
    const modalTitle = document.getElementById('seatMapModalTitle');
    
    if (!seatMapModal || !seatMapContainer) return;
    
    // Show loading state
    seatMapContainer.innerHTML = '<div style="text-align: center; padding: 40px;">Loading seat map...</div>';
    seatMapModal.style.display = 'flex';
    
    // Fetch seat map data
    fetch(`/admin/trip-schedules/${tripId}/seat-map`)
        .then(response => response.json())
        .then(data => {
            renderSeatMap(data, seatMapContainer, modalTitle);
        })
        .catch(error => {
            console.error('Error loading seat map:', error);
            seatMapContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-secondary);">Error loading seat map. Please try again.</div>';
        });
}

function renderSeatMap(data, container, titleElement) {
    const trip = data.trip;
    const seatMap = data.seat_map || [];
    const boat = trip.boat;
    
    if (titleElement) {
        titleElement.textContent = `Seat Map - ${trip.route} (${boat.name})`;
    }

    // Group seats by row
    const rowMap = new Map();
    const columns = ['A', 'B', 'C', 'D', 'E'];

    seatMap.forEach((seat, idx) => {
        let seatNum = String(seat.seat_number || '');
        let row = seat.row;
        let col = seat.column;

        if (!row || !col) {
            const match = seatNum.match(/^(\d+)([A-E])$/i);
            if (match) {
                row = parseInt(match[1], 10);
                col = match[2].toUpperCase();
            } else {
                const numericId = parseInt(seatNum, 10) || (idx + 1);
                row = Math.ceil(numericId / 5);
                const colIdx = (numericId - 1) % 5;
                col = columns[colIdx] || 'A';
                seatNum = `${row}${col}`;
            }
        } else {
            col = String(col).toUpperCase();
            row = parseInt(row, 10);
        }

        if (!rowMap.has(row)) {
            rowMap.set(row, []);
        }

        rowMap.get(row).push({
            ...seat,
            seat_number: seatNum,
            row: row,
            column: col,
            isBooked: !!seat.booked || seat.status === 'booked',
        });
    });

    const sortedRows = [...rowMap.keys()].sort((a, b) => a - b);
    
    let html = `
        <div class="seat-map-info">
            <div class="seat-map-info-item">
                <strong>Route:</strong> <span>${trip.route}</span>
            </div>
            <div class="seat-map-info-item">
                <strong>Boat:</strong> <span>${boat.name}</span>
            </div>
            <div class="seat-map-info-item">
                <strong>Departure:</strong> <span>${new Date(trip.departure_time).toLocaleString()}</span>
            </div>
            <div class="seat-map-info-item">
                <strong>Available Seats:</strong> <span>${trip.available_seats} / ${boat.passenger_capacity || 0}</span>
            </div>
        </div>

        <div class="seat-map-legend" style="margin-bottom: 14px;">
            <div class="seat-map-legend-item">
                <div class="seat-map-legend-box available"></div>
                <span>Available</span>
            </div>
            <div class="seat-map-legend-item">
                <div class="seat-map-legend-box booked">✕</div>
                <span>Booked</span>
            </div>
        </div>

        <div class="modal-vessel-cabin">
            <div class="modal-vessel-bow">
                <span class="bow-arrow">▲</span>
                <span>FRONT / BOW</span>
            </div>

            <div class="modal-vessel-exit-row">
                <span>« EXIT</span>
                <span>EXIT »</span>
            </div>
            <div class="modal-vessel-exit-divider"></div>

            <div class="modal-vessel-rows-container">
    `;

    sortedRows.forEach(rowNum => {
        const rowSeats = rowMap.get(rowNum) || [];
        const leftBankSeats = rowSeats.filter(s => s.column === 'A' || s.column === 'B');
        const rightBankSeats = rowSeats.filter(s => s.column === 'C' || s.column === 'D' || s.column === 'E');

        html += `
            <div class="modal-vessel-seat-row">
                <div class="modal-vessel-bank">
        `;

        ['A', 'B'].forEach(col => {
            const seat = leftBankSeats.find(s => s.column === col);
            if (!seat) {
                html += `<div class="modal-seat-box" style="visibility: hidden;"></div>`;
            } else {
                const isBooked = !!seat.isBooked;
                const cls = isBooked ? 'modal-seat-box booked' : 'modal-seat-box available';
                const content = isBooked ? '<span class="seat-x">✕</span>' : col;
                const title = isBooked
                    ? `Seat ${seat.seat_number} - Booked${seat.passenger_name ? ` by ${seat.passenger_name}` : ''}`
                    : `Seat ${seat.seat_number} - Available`;
                html += `<div class="${cls}" title="${title}">${content}</div>`;
            }
        });

        html += `
                </div>
                <div class="modal-vessel-aisle">${rowNum}</div>
                <div class="modal-vessel-bank">
        `;

        ['C', 'D', 'E'].forEach(col => {
            const seat = rightBankSeats.find(s => s.column === col);
            if (!seat) {
                html += `<div class="modal-seat-box" style="visibility: hidden;"></div>`;
            } else {
                const isBooked = !!seat.isBooked;
                const cls = isBooked ? 'modal-seat-box booked' : 'modal-seat-box available';
                const content = isBooked ? '<span class="seat-x">✕</span>' : col;
                const title = isBooked
                    ? `Seat ${seat.seat_number} - Booked${seat.passenger_name ? ` by ${seat.passenger_name}` : ''}`
                    : `Seat ${seat.seat_number} - Available`;
                html += `<div class="${cls}" title="${title}">${content}</div>`;
            }
        });

        html += `
                </div>
            </div>
        `;
    });

    html += `
            </div>
            <div class="modal-vessel-stern">AFT / STERN</div>
        </div>
    `;
    
    container.innerHTML = html;
}

function closeSeatMapModal() {
    const seatMapModal = document.getElementById('seatMapModalBackdrop');
    if (seatMapModal) {
        seatMapModal.style.display = 'none';
    }
}

const MANILA_TZ = 'Asia/Manila';
const LIVE_ENDPOINT = '/admin/trip-schedules/live';
const CLOCK_INTERVAL_MS = 1000;
const POLL_INTERVAL_MS = 10000;

/**
 * Current instant expressed as a UTC epoch, derived from Asia/Manila wall-clock (UTC+8).
 * Does not use the browser's local timezone for comparisons.
 */
function nowManilaMs() {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: MANILA_TZ,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        hourCycle: 'h23',
    }).formatToParts(new Date());

    const map = {};
    parts.forEach((part) => {
        if (part.type !== 'literal') {
            map[part.type] = part.value;
        }
    });

    let hour = map.hour === '24' ? '00' : map.hour;
    return Date.parse(
        `${map.year}-${map.month}-${map.day}T${hour}:${map.minute}:${map.second}+08:00`
    );
}

/**
 * Parse a datetime as Philippine Standard Time.
 * Accepts ISO-8601 with offset, trailing Z, or naive "YYYY-MM-DD HH:mm:ss".
 */
function parseManilaMs(value) {
    if (!value) {
        return NaN;
    }

    const raw = String(value).trim();
    if (/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw)) {
        return new Date(raw).getTime();
    }

    const normalized = raw.replace(' ', 'T');
    const hasSeconds = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(normalized);
    const stamp = hasSeconds ? normalized : `${normalized}:00`;
    return new Date(`${stamp}+08:00`).getTime();
}

function classifyTrip(departureMs, arrivalMs, nowMs) {
    if (nowMs < departureMs) {
        return 'Scheduled';
    }
    if (nowMs >= arrivalMs) {
        return 'Arrived';
    }
    return 'Departed';
}

function gridForStatus(status) {
    if (status === 'Departed') {
        return document.getElementById('active-trip-grid');
    }
    if (status === 'Arrived') {
        return document.getElementById('completed-trip-grid');
    }
    return document.getElementById('scheduled-trip-grid');
}

function refreshEmptyStates() {
    [
        ['scheduled-trip-grid', 'scheduled-empty'],
        ['active-trip-grid', 'active-empty'],
        ['completed-trip-grid', 'completed-empty'],
    ].forEach(([gridId, emptyId]) => {
        const grid = document.getElementById(gridId);
        const empty = document.getElementById(emptyId);
        if (!grid || !empty) {
            return;
        }
        const hasCards = grid.querySelectorAll('.trip-card[data-schedule-id]').length > 0;
        empty.style.display = hasCards ? 'none' : '';
    });
}

function applyStatusChrome(card, newStatus) {
    const titleDiv = card.querySelector('.trip-card-title');
    let badge = card.querySelector('.trip-status-badge');

    if (newStatus === 'Departed') {
        card.classList.add('trip-card-active');
        card.classList.remove('trip-card-completed');
        const actions = card.querySelector('.trip-card-actions');
        if (actions) {
            actions.remove();
        }
        if (!badge && titleDiv) {
            badge = document.createElement('span');
            titleDiv.appendChild(badge);
        }
        if (badge) {
            badge.className = 'trip-status-badge status-departed';
            badge.textContent = 'Departed';
        }
    } else if (newStatus === 'Arrived') {
        card.classList.add('trip-card-completed');
        card.classList.remove('trip-card-active');
        const actions = card.querySelector('.trip-card-actions');
        if (actions) {
            actions.remove();
        }
        if (!badge && titleDiv) {
            badge = document.createElement('span');
            titleDiv.appendChild(badge);
        }
        if (badge) {
            badge.className = 'trip-status-badge status-arrived';
            badge.textContent = 'Arrived';
        }
    }
}

function persistTripStatus(tripId, newStatus) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (!csrfToken || !tripId) {
        return;
    }

    fetch(`/admin/trip-schedules/${tripId}/status`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status: newStatus }),
    }).catch(() => {});
}

function transitionTripCard(card, tripId, newStatus) {
    card.setAttribute('data-status', newStatus);
    applyStatusChrome(card, newStatus);

    const targetGrid = gridForStatus(newStatus);
    if (targetGrid && card.parentElement !== targetGrid) {
        targetGrid.appendChild(card);
    }

    refreshEmptyStates();
    persistTripStatus(tripId, newStatus);
}

function checkRealtimeTripStatuses() {
    const nowMs = nowManilaMs();
    const cards = document.querySelectorAll('.trip-card[data-departure]');

    cards.forEach((card) => {
        const depStr = card.getAttribute('data-departure');
        const arrStr = card.getAttribute('data-arrival');
        const currentStatus = card.getAttribute('data-status');
        const id = card.getAttribute('data-schedule-id');

        if (!depStr || currentStatus === 'Cancelled') {
            return;
        }

        const departureMs = parseManilaMs(depStr);
        const arrivalMs = arrStr
            ? parseManilaMs(arrStr)
            : departureMs + 2 * 60 * 60 * 1000;

        if (Number.isNaN(departureMs) || Number.isNaN(arrivalMs)) {
            return;
        }

        const nextStatus = classifyTrip(departureMs, arrivalMs, nowMs);
        if (nextStatus !== currentStatus) {
            transitionTripCard(card, id, nextStatus);
        }
    });
}

function isTripModalOpen(scheduleModal, seatMapModal) {
    const scheduleOpen = scheduleModal && scheduleModal.style.display === 'flex';
    const seatMapOpen = seatMapModal && seatMapModal.style.display === 'flex';
    return Boolean(scheduleOpen || seatMapOpen);
}

function applyLiveHtml(payload) {
    if (!payload?.html) {
        return;
    }

    Object.entries({
        scheduled: 'scheduled-trip-grid',
        active: 'active-trip-grid',
        completed: 'completed-trip-grid',
    }).forEach(([key, gridId]) => {
        const grid = document.getElementById(gridId);
        if (grid && typeof payload.html[key] === 'string') {
            grid.innerHTML = payload.html[key];
        }
    });

    refreshEmptyStates();
}

function startLiveTripStatusUpdates({ scheduleModal, seatMapModal }) {
    let fingerprint = document.querySelector('.trip-tabs')?.getAttribute('data-live-fingerprint') || null;
    let pollInFlight = false;

    checkRealtimeTripStatuses();

    setInterval(() => {
        checkRealtimeTripStatuses();
    }, CLOCK_INTERVAL_MS);

    const poll = () => {
        if (pollInFlight || isTripModalOpen(scheduleModal, seatMapModal) || document.hidden) {
            return;
        }

        pollInFlight = true;
        fetch(LIVE_ENDPOINT, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('live poll failed');
                }
                return response.json();
            })
            .then((payload) => {
                if (payload.fingerprint && payload.fingerprint !== fingerprint) {
                    fingerprint = payload.fingerprint;
                    applyLiveHtml(payload);
                }
                checkRealtimeTripStatuses();
            })
            .catch(() => {})
            .finally(() => {
                pollInFlight = false;
            });
    };

    poll();
    setInterval(poll, POLL_INTERVAL_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            poll();
        }
    });
}
