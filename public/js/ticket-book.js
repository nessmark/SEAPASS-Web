/**
 * Ticketing & Bookings interactions
 * - Booking status tabs
 * - Override / Counter booking feeds Manage Bookings (Pending/Confirmed/Cancelled)
 */

let ticketCounter = 1;
let currentTripScheduleId = null;
let currentTripSeatMap = [];
let selectedSeatNumbers = new Set();

const TIME_LABEL_TO_SLOT = {
    '7:30 AM': '07:30',
    '10:30 AM': '10:30',
    '1:30 PM': '13:30',
    '5:00 PM': '17:00',
};

function getCsrfToken() {
    const formToken = document.querySelector('#ticketOverrideForm input[name="_token"]');
    if (formToken) {
        return formToken.value;
    }
    const anyToken = document.querySelector('input[name="_token"]');
    return anyToken ? anyToken.value : '';
}

function formatTripTime(dateStr, timeLabel) {
    if (!dateStr) return timeLabel || '';
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) {
        return `${dateStr} · ${timeLabel}`;
    }
    const options = { month: 'short', day: '2-digit', year: 'numeric' };
    return `${d.toLocaleDateString(undefined, options)} · ${timeLabel}`;
}

function hideTicketEmptyRow(bodyId, emptyRowId) {
    const body = document.getElementById(bodyId);
    const emptyRow = document.getElementById(emptyRowId);
    if (!body || !emptyRow) return;
    const hasReal = Array.from(body.querySelectorAll('tr')).some(tr => {
        return tr.id !== emptyRowId && tr.style.display !== 'none';
    });
    emptyRow.style.display = hasReal ? 'none' : '';
}

/**
 * Show a custom confirmation modal styled like a card.
 * Falls back to window.confirm if the modal markup is not present.
 */
function showTicketConfirmDialog(message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('ticket_confirm_modal');
        const messageEl = modal?.querySelector('[data-ticket-confirm-message]');
        const okBtn = modal?.querySelector('[data-ticket-confirm-ok]');
        const cancelBtn = modal?.querySelector('[data-ticket-confirm-cancel]');

        if (!modal || !messageEl || !okBtn || !cancelBtn) {
            const result = window.confirm(message);
            resolve(result);
            return;
        }

        messageEl.textContent = message;
        modal.classList.add('is-visible');

        const cleanup = (value) => {
            modal.classList.remove('is-visible');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdropClick);
            resolve(value);
        };

        const onOk = () => cleanup(true);
        const onCancel = () => cleanup(false);
        const onBackdropClick = (event) => {
            if (event.target === modal) {
                cleanup(false);
            }
        };

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdropClick);
    });
}

function createBookingRow(booking) {
    const pCount = booking.passengers ? booking.passengers.length : (booking.seats ? booking.seats.length : 1);
    const passengersData = escapeHtml(JSON.stringify(booking.passengers || []));
    return `
        <tr data-ticket-id="${booking.id}" data-trip-date="${booking.date}">
            <td><strong>${booking.id}</strong></td>
            <td>
                <div class="passenger-cell-group">
                    <span class="passenger-primary-name">${escapeHtml(booking.name || 'Passenger')}</span>
                    <button 
                        type="button" 
                        class="passenger-badge-btn btn-view-passengers" 
                        data-booking-ref="${booking.id}"
                        data-route="${escapeHtml(booking.route || '')}"
                        data-trip-date="${escapeHtml(booking.date || '')}"
                        data-trip-time="${escapeHtml(booking.tripTime || '')}"
                        data-passengers="${passengersData}"
                        data-total-amount="₱${parseFloat(booking.amount || 0).toFixed(2)}"
                        title="Click to view details for ${pCount} ${pCount === 1 ? 'passenger' : 'passengers'}"
                    >
                        <span class="badge-icon">👥</span>
                        <span class="badge-count">${pCount} ${pCount === 1 ? 'Passenger' : 'Passengers'}</span>
                        <span class="badge-view-icon">👁️</span>
                    </button>
                </div>
            </td>
            <td>
                <select class="ticket-status-select">
                    <option value="pending"${booking.status === 'pending' ? ' selected' : ''}>Pending</option>
                    <option value="confirmed"${booking.status === 'confirmed' ? ' selected' : ''}>Confirmed</option>
                    <option value="cancelled"${booking.status === 'cancelled' ? ' selected' : ''}>Cancelled</option>
                </select>
            </td>
        </tr>
    `;
}

function filterBookingsByDate(selectedDate) {
    const bodyIds = [
        { body: 'ticketPendingBody', empty: 'ticketPendingEmptyRow' },
        { body: 'ticketConfirmedBody', empty: 'ticketConfirmedEmptyRow' },
        { body: 'ticketCancelledBody', empty: 'ticketCancelledEmptyRow' },
    ];

    bodyIds.forEach(({ body, empty }) => {
        const tbody = document.getElementById(body);
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr[data-ticket-id]');
        rows.forEach(row => {
            if (selectedDate && row.dataset.tripDate !== selectedDate) {
                row.style.display = 'none';
            } else {
                row.style.display = '';
            }
        });

        hideTicketEmptyRow(body, empty);
    });
}

function resetTicketSeatMapContainer() {
    const container = document.getElementById('ticket_seat_map_container');
    if (!container) return;

    if (!currentTripScheduleId) {
        container.innerHTML = `
            <div style="font-size: 12px; color: var(--text-secondary);">
                Select Route, Trip Date, Trip Time, and Boat to load the seat map.
            </div>
        `;
    }
}

let categoryCounts = {
    regular: 0,
    student: 0,
    senior: 0
};

function updateCategoryCountDOM() {
    const regEl = document.getElementById('count_regular');
    const stdEl = document.getElementById('count_student');
    const senEl = document.getElementById('count_senior');

    if (regEl) regEl.textContent = categoryCounts.regular;
    if (stdEl) stdEl.textContent = categoryCounts.student;
    if (senEl) senEl.textContent = categoryCounts.senior;
}

function updateUnitPrices() {
    const routeSelect = document.getElementById('ticket_route');
    const route = routeSelect?.value;
    const fareData = window.fareData || {};
    const routeFares = fareData[route] || { regular: 0, student: 0, senior: 0 };

    const regPriceEl = document.getElementById('unit_price_regular');
    const stdPriceEl = document.getElementById('unit_price_student');
    const senPriceEl = document.getElementById('unit_price_senior');

    if (regPriceEl) regPriceEl.textContent = `₱${parseFloat(routeFares.regular || 0).toFixed(2)} / passenger`;
    if (stdPriceEl) stdPriceEl.textContent = `₱${parseFloat(routeFares.student || 0).toFixed(2)} / passenger`;
    if (senPriceEl) senPriceEl.textContent = `₱${parseFloat(routeFares.senior || 0).toFixed(2)} / passenger`;
}

function updateSelectedSeatInputs() {
    const displayInput = document.getElementById('ticket_seat_numbers_display');
    const hiddenInput = document.getElementById('ticket_selected_seats');

    const seats = [...selectedSeatNumbers].sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const label = seats.length ? `Seat(s): ${seats.join(', ')}` : '';

    if (displayInput) {
        displayInput.value = label;
    }
    if (hiddenInput) {
        hiddenInput.value = seats.join(',');
    }
    
    // Sync category counts with total selected seats
    const totalSeatsOnMap = seats.length;
    let currentTotal = categoryCounts.regular + categoryCounts.student + categoryCounts.senior;

    if (currentTotal < totalSeatsOnMap) {
        categoryCounts.regular += (totalSeatsOnMap - currentTotal);
    } else if (currentTotal > totalSeatsOnMap) {
        let diff = currentTotal - totalSeatsOnMap;
        if (categoryCounts.regular >= diff) {
            categoryCounts.regular -= diff;
            diff = 0;
        } else {
            diff -= categoryCounts.regular;
            categoryCounts.regular = 0;
        }
        if (diff > 0 && categoryCounts.student >= diff) {
            categoryCounts.student -= diff;
            diff = 0;
        } else if (diff > 0) {
            diff -= categoryCounts.student;
            categoryCounts.student = 0;
        }
        if (diff > 0 && categoryCounts.senior >= diff) {
            categoryCounts.senior -= diff;
            diff = 0;
        } else if (diff > 0) {
            categoryCounts.senior = 0;
        }
    }

    updateCategoryCountDOM();
    calculateTicketAmount();
    syncPassengerListWithCounters();
}

let passengerList = [];

function syncPassengerListWithCounters() {
    const desired = [];
    
    // Add regular passengers
    for (let i = 0; i < categoryCounts.regular; i++) {
        desired.push('regular');
    }
    // Add student passengers
    for (let i = 0; i < categoryCounts.student; i++) {
        desired.push('student');
    }
    // Add senior passengers
    for (let i = 0; i < categoryCounts.senior; i++) {
        desired.push('senior');
    }

    // Map existing passengers by category so we preserve entered names & IDs
    const existingByCategory = {
        regular: passengerList.filter(p => p.category === 'regular'),
        student: passengerList.filter(p => p.category === 'student'),
        senior: passengerList.filter(p => p.category === 'senior'),
    };

    const newPassengerList = [];
    const seatsArray = [...selectedSeatNumbers].sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

    desired.forEach((cat, idx) => {
        let pObj = existingByCategory[cat] && existingByCategory[cat].length > 0
            ? existingByCategory[cat].shift()
            : null;

        if (!pObj) {
            pObj = {
                category: cat,
                given_name: '',
                last_name: '',
                discount_id: '',
                isExpanded: idx === 0, // expand first passenger by default
            };
        }
        pObj.index = idx + 1;
        pObj.seat = seatsArray[idx] || '';
        newPassengerList.push(pObj);
    });

    passengerList = newPassengerList;
    renderPassengerAccordionList();
}

function renderPassengerAccordionList() {
    const section = document.getElementById('passenger_details_section');
    const container = document.getElementById('passenger_accordion_list');
    const titleEl = document.getElementById('passenger_details_title');
    if (!section || !container) return;

    if (passengerList.length === 0) {
        section.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    section.style.display = 'block';
    if (titleEl) {
        titleEl.textContent = `Passenger Details (${passengerList.length} Total)`;
    }

    const routeSelect = document.getElementById('ticket_route');
    const route = routeSelect?.value;
    const fareData = window.fareData || {};
    const routeFares = fareData[route] || { regular: 0, student: 0, senior: 0 };

    let html = '';

    passengerList.forEach((p, idx) => {
        const catLabel = p.category === 'student' ? 'Student' : (p.category === 'senior' ? 'Senior Citizen / PWD' : 'Adult (Regular)');
        const fare = p.category === 'student' ? parseFloat(routeFares.student || 0) :
                     p.category === 'senior' ? parseFloat(routeFares.senior || 0) :
                     parseFloat(routeFares.regular || 0);

        const fullName = [p.given_name, p.last_name].filter(Boolean).join(' ');
        const isCompleted = Boolean(p.given_name && p.given_name.trim() && p.last_name && p.last_name.trim() && (p.category === 'regular' || (p.discount_id && p.discount_id.trim())));

        html += `
            <div class="passenger-card-item ${p.isExpanded ? 'is-expanded' : ''}" id="passenger_card_${idx}" data-idx="${idx}">
                <div class="passenger-card-header" data-toggle-passenger="${idx}">
                    <div class="passenger-card-header-info">
                        <strong class="passenger-card-title">Passenger ${idx + 1}: ${escapeHtml(catLabel)}</strong>
                        <span class="passenger-name-preview" id="preview_name_${idx}">${escapeHtml(fullName || 'Full name not set')}</span>
                    </div>
                    <div class="passenger-card-header-actions">
                        <span class="passenger-status-chip ${isCompleted ? 'status-completed' : 'status-pending'}" id="status_chip_${idx}">
                            ${isCompleted ? 'Completed' : 'Not completed'}
                        </span>
                        <span class="passenger-accordion-chevron" id="chevron_${idx}">${p.isExpanded ? '▲' : '▼'}</span>
                    </div>
                </div>
                <div class="passenger-card-body" id="passenger_body_${idx}" style="${p.isExpanded ? 'display: block;' : 'display: none;'}">
                    <div class="passenger-card-form-grid">
                        <div class="passenger-input-group">
                            <label>Given names (including suffix) <span style="color: #ef4444;">*</span></label>
                            <input type="text" class="passenger-field-given" data-idx="${idx}" placeholder="e.g. Juan Jr." value="${escapeHtml(p.given_name || '')}">
                        </div>
                        <div class="passenger-input-group">
                            <label>Last name (surname) <span style="color: #ef4444;">*</span></label>
                            <input type="text" class="passenger-field-last" data-idx="${idx}" placeholder="e.g. Dela Cruz" value="${escapeHtml(p.last_name || '')}">
                        </div>
                    </div>

                    ${p.category === 'student' ? `
                        <div class="passenger-discount-box">
                            <div class="discount-box-title">Student Discount ID Verification <span style="color: #ef4444;">*</span></div>
                            <div class="passenger-input-group" style="margin-top: 6px;">
                                <label>ID / Card Number <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="passenger-field-discount" data-idx="${idx}" placeholder="e.g. 2024-STU-1029" value="${escapeHtml(p.discount_id || '')}">
                            </div>
                        </div>
                    ` : ''}

                    ${p.category === 'senior' ? `
                        <div class="passenger-discount-box">
                            <div class="discount-box-title">Senior Citizen / PWD ID Verification <span style="color: #ef4444;">*</span></div>
                            <div class="passenger-input-group" style="margin-top: 6px;">
                                <label>ID / Card Number <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="passenger-field-discount" data-idx="${idx}" placeholder="e.g. OSCA-1954 / PWD-0821" value="${escapeHtml(p.discount_id || '')}">
                            </div>
                        </div>
                    ` : ''}

                    <div class="passenger-card-meta-row">
                        <span class="passenger-fare-tag">Individual Fare: <strong>₱${Number(fare).toFixed(2)}</strong></span>
                        ${p.seat ? `<span class="passenger-seat-tag">💺 Seat ${escapeHtml(p.seat)}</span>` : '<span class="passenger-seat-pending">⚠️ Select seat on map below</span>'}
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

/**
 * Calculate ticket amount based on route, category counts, and dynamic fares
 */
function calculateTicketAmount() {
    updateUnitPrices();

    const routeSelect = document.getElementById('ticket_route');
    const amountInput = document.getElementById('ticket_amount');
    if (!routeSelect || !amountInput) return;

    const route = routeSelect.value;
    const fareData = window.fareData || {};
    const routeFares = fareData[route] || { regular: 0, student: 0, senior: 0 };

    const regFare = parseFloat(routeFares.regular || 0);
    const stdFare = parseFloat(routeFares.student || 0);
    const senFare = parseFloat(routeFares.senior || 0);

    const totalAmount = (categoryCounts.regular * regFare) +
                        (categoryCounts.student * stdFare) +
                        (categoryCounts.senior * senFare);

    amountInput.value = totalAmount.toFixed(2);
}

function renderTicketSeatMap() {
    const container = document.getElementById('ticket_seat_map_container');
    if (!container) return;

    if (!currentTripSeatMap.length) {
        container.innerHTML = `
            <div style="font-size: 12px; color: var(--text-secondary); text-align: center; padding: 24px 0;">
                No seat map available for the selected trip.
            </div>
        `;
        return;
    }

    // Organize seats by row
    const rowMap = new Map();
    const columns = ['A', 'B', 'C', 'D', 'E'];

    currentTripSeatMap.forEach((seat, idx) => {
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

    // 1. Legend at top matching mobile app
    let html = `
        <div class="ticket-seat-legend">
            <div class="ticket-seat-legend-item">
                <div class="ticket-seat-legend-box available"></div>
                <span>Available</span>
            </div>
            <div class="ticket-seat-legend-item">
                <div class="ticket-seat-legend-box selected">✓</div>
                <span>Selected</span>
            </div>
            <div class="ticket-seat-legend-item">
                <div class="ticket-seat-legend-box booked">✕</div>
                <span>Booked</span>
            </div>
        </div>

        <!-- 2. Vessel Cabin Outline -->
        <div class="vessel-cabin">
            <!-- Front / Bow Indicator -->
            <div class="vessel-bow-indicator">
                <span class="bow-arrow">▲</span>
                <span>FRONT / BOW</span>
            </div>

            <!-- Exit Indicators -->
            <div class="vessel-exit-row">
                <span>« EXIT</span>
                <span>EXIT »</span>
            </div>
            <div class="vessel-exit-divider"></div>

            <!-- Dynamic Seat Rows Container -->
            <div class="vessel-rows-container">
    `;

    sortedRows.forEach(rowNum => {
        const rowSeats = rowMap.get(rowNum) || [];
        const leftBankSeats = rowSeats.filter(s => s.column === 'A' || s.column === 'B');
        const rightBankSeats = rowSeats.filter(s => s.column === 'C' || s.column === 'D' || s.column === 'E');

        html += `
            <div class="vessel-seat-row">
                <!-- Left Bank [A] [B] -->
                <div class="vessel-bank left-bank">
        `;

        ['A', 'B'].forEach(col => {
            const seat = leftBankSeats.find(s => s.column === col);
            html += renderSeatBoxHtml(seat, rowNum, col);
        });

        html += `
                </div>

                <!-- Central Aisle [Row Number] -->
                <div class="vessel-aisle">
                    ${rowNum}
                </div>

                <!-- Right Bank [C] [D] [E] -->
                <div class="vessel-bank right-bank">
        `;

        ['C', 'D', 'E'].forEach(col => {
            const seat = rightBankSeats.find(s => s.column === col);
            html += renderSeatBoxHtml(seat, rowNum, col);
        });

        html += `
                </div>
            </div>
        `;
    });

    html += `
            </div>

            <!-- AFT / STERN Indicator -->
            <div class="vessel-stern-indicator">
                AFT / STERN
            </div>
        </div>
    `;

    container.innerHTML = html;
}

function renderSeatBoxHtml(seat, rowNum, col) {
    if (!seat) {
        return `<div class="ticket-seat" style="visibility: hidden;"></div>`;
    }

    const seatNumber = seat.seat_number || `${rowNum}${col}`;
    const isBooked = !!seat.isBooked;
    const isSelected = selectedSeatNumbers.has(seatNumber);

    let classes = 'ticket-seat ';
    let textContent = col;

    if (isBooked) {
        classes += 'booked';
        textContent = '<span class="seat-x">✕</span>';
    } else if (isSelected) {
        classes += 'selected';
        textContent = '✓';
    } else {
        classes += 'available';
    }

    const title = isBooked
        ? `Seat ${seatNumber} - Booked${seat.passenger_name ? ` by ${seat.passenger_name}` : ''}`
        : `Seat ${seatNumber} - Available`;

    return `
        <div
            class="${classes}"
            data-seat-number="${seatNumber}"
            data-column="${col}"
            data-row="${rowNum}"
            title="${title}"
        >
            ${textContent}
        </div>
    `;
}

let seatMapLivePollTimer = null;

function stopSeatMapLivePoll() {
    if (seatMapLivePollTimer) {
        clearInterval(seatMapLivePollTimer);
        seatMapLivePollTimer = null;
    }
}

function startSeatMapLivePoll() {
    if (seatMapLivePollTimer) return;
    seatMapLivePollTimer = setInterval(() => {
        if (currentTripScheduleId && document.visibilityState === 'visible') {
            loadTripForTicketForm(true);
        }
    }, 5000);
}

function loadTripForTicketForm(silent = false) {
    const routeSelect = document.getElementById('ticket_route');
    const dateInput = document.getElementById('ticket_date');
    const timeSelect = document.getElementById('ticket_time');
    const boatSelect = document.getElementById('ticket_boat');

    const route = routeSelect?.value;
    const date = dateInput?.value;
    const timeLabel = timeSelect?.value;
    const boatId = boatSelect?.value;

    if (!route || !date || !timeLabel || !boatId) {
        stopSeatMapLivePoll();
        currentTripScheduleId = null;
        currentTripSeatMap = [];
        selectedSeatNumbers.clear();
        updateSelectedSeatInputs();
        resetTicketSeatMapContainer();
        return;
    }

    const timeSlot = TIME_LABEL_TO_SLOT[timeLabel] || null;
    if (!timeSlot) {
        stopSeatMapLivePoll();
        currentTripScheduleId = null;
        currentTripSeatMap = [];
        selectedSeatNumbers.clear();
        updateSelectedSeatInputs();
        resetTicketSeatMapContainer();
        return;
    }

    const csrfToken = getCsrfToken();
    if (!csrfToken) {
        console.warn('CSRF token not found; cannot load trip seat map.');
        return;
    }

    const container = document.getElementById('ticket_seat_map_container');
    if (container && !silent) {
        container.innerHTML = `
            <div style="font-size: 12px; color: var(--text-secondary); padding: 8px 0;">
                Loading seat map...
            </div>
        `;
    }

    fetch('/admin/trip-schedules/find-for-booking', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            boat_id: boatId,
            route,
            departure_date: date,
            departure_time_slot: timeSlot,
        }),
    })
        .then(async (response) => {
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                const message = errorData.message || 'No matching scheduled trip found for the selected details.';
                throw new Error(message);
            }
            return response.json();
        })
        .then((data) => {
            currentTripScheduleId = data.trip.id;
            currentTripSeatMap = data.seat_map || [];

            const tripIdInput = document.getElementById('ticket_trip_schedule_id');
            if (tripIdInput) {
                tripIdInput.value = currentTripScheduleId;
            }

            if (!silent) {
                selectedSeatNumbers.clear();
                updateSelectedSeatInputs();
            } else {
                // Prune any selected seats that were just booked remotely by mobile or another admin
                const newlyBooked = new Set(
                    currentTripSeatMap
                        .filter(s => s.booked || s.status === 'booked')
                        .map(s => String(s.seat_number).toUpperCase())
                );
                let changed = false;
                selectedSeatNumbers.forEach(s => {
                    if (newlyBooked.has(String(s).toUpperCase())) {
                        selectedSeatNumbers.delete(s);
                        changed = true;
                    }
                });
                if (changed) {
                    updateSelectedSeatInputs();
                }
            }

            renderTicketSeatMap();
            startSeatMapLivePoll();
        })
        .catch((error) => {
            if (!silent) {
                console.error('Error loading trip for booking:', error);
                stopSeatMapLivePoll();
                currentTripScheduleId = null;
                currentTripSeatMap = [];
                selectedSeatNumbers.clear();
                updateSelectedSeatInputs();

                if (container) {
                    container.innerHTML = `
                        <div style="font-size: 12px; color: var(--text-secondary); padding: 8px 0;">
                            ${error.message || 'No matching scheduled trip found for the selected details.'}
                        </div>
                    `;
                }
            }
        });
}

document.addEventListener('DOMContentLoaded', () => {
    // Tabs (with optional date requirement)
    const tabContainers = document.querySelectorAll('[data-ticket-tabs]');
    const filterDateInput = document.getElementById('ticket_filter_date');

    tabContainers.forEach(container => {
        const tabs = container.querySelectorAll('.ticket-tab');
        const contents = container
            .closest('.ticket-section')
            .querySelectorAll('.ticket-tab-content');

        const requiresDate = container.hasAttribute('data-requires-date');
        const dateInput = requiresDate
            ? document.getElementById('ticket_filter_date')
            : null;

        function activateTab(tab) {
            const target = tab.getAttribute('data-tab');

            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));

            tab.classList.add('active');
            const content = document.getElementById(`tab-${target}`);
            if (content) {
                content.classList.add('active');
            }
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                activateTab(tab);
            });
        });

        if (requiresDate && dateInput) {
            dateInput.addEventListener('change', () => {
                filterBookingsByDate(dateInput.value);
            });
        }
    });

    // Category Counter listeners (- 1 +)
    const bindCategoryCounter = (decId, incId, type) => {
        const decBtn = document.getElementById(decId);
        const incBtn = document.getElementById(incId);

        decBtn?.addEventListener('click', () => {
            if (categoryCounts[type] > 0) {
                categoryCounts[type]--;
                updateCategoryCountDOM();
                calculateTicketAmount();
                syncPassengerListWithCounters();
            }
        });

        incBtn?.addEventListener('click', () => {
            const total = categoryCounts.regular + categoryCounts.student + categoryCounts.senior;
            const seatsOnMap = selectedSeatNumbers.size;

            if (seatsOnMap > 0 && total >= seatsOnMap) {
                if (type !== 'regular' && categoryCounts.regular > 0) {
                    categoryCounts.regular--;
                    categoryCounts[type]++;
                } else if (type !== 'student' && categoryCounts.student > 0) {
                    categoryCounts.student--;
                    categoryCounts[type]++;
                } else if (type !== 'senior' && categoryCounts.senior > 0) {
                    categoryCounts.senior--;
                    categoryCounts[type]++;
                }
            } else {
                categoryCounts[type]++;
            }
            updateCategoryCountDOM();
            calculateTicketAmount();
            syncPassengerListWithCounters();
        });
    };

    bindCategoryCounter('btn_dec_regular', 'btn_inc_regular', 'regular');
    bindCategoryCounter('btn_dec_student', 'btn_inc_student', 'student');
    bindCategoryCounter('btn_dec_senior', 'btn_inc_senior', 'senior');

    // Override / Counter booking form
    const form = document.getElementById('ticketOverrideForm');
    const clearBtn = document.getElementById('ticket_clear_btn');
    const routeSelect = document.getElementById('ticket_route');
    const dateInput = document.getElementById('ticket_date');
    const timeSelect = document.getElementById('ticket_time');
    const boatSelect = document.getElementById('ticket_boat');

    // Initial calculation of unit prices
    calculateTicketAmount();

    clearBtn?.addEventListener('click', () => {
        form?.reset();
        currentTripScheduleId = null;
        currentTripSeatMap = [];
        selectedSeatNumbers.clear();

        categoryCounts.regular = 0;
        categoryCounts.student = 0;
        categoryCounts.senior = 0;

        passengerList = [];
        renderPassengerAccordionList();

        const tripIdInput = document.getElementById('ticket_trip_schedule_id');
        if (tripIdInput) {
            tripIdInput.value = '';
        }

        updateSelectedSeatInputs();
        resetTicketSeatMapContainer();
        calculateTicketAmount(); // Reset amount
    });

    [routeSelect, dateInput, timeSelect, boatSelect].forEach(el => {
        if (!el) return;
        el.addEventListener('change', () => {
            loadTripForTicketForm();
            calculateTicketAmount();
        });
    });

    // Accordion click delegation (expand / collapse cards)
    document.addEventListener('click', (e) => {
        const header = e.target.closest('[data-toggle-passenger]');
        if (header) {
            const idx = parseInt(header.getAttribute('data-toggle-passenger'), 10);
            if (passengerList[idx]) {
                passengerList[idx].isExpanded = !passengerList[idx].isExpanded;
                const card = document.getElementById(`passenger_card_${idx}`);
                const body = document.getElementById(`passenger_body_${idx}`);
                const chevron = document.getElementById(`chevron_${idx}`);
                if (card) card.classList.toggle('is-expanded', passengerList[idx].isExpanded);
                if (body) body.style.display = passengerList[idx].isExpanded ? 'block' : 'none';
                if (chevron) chevron.textContent = passengerList[idx].isExpanded ? '▲' : '▼';
            }
        }
    });

    // Input delegation for dynamic passenger accordion fields
    document.addEventListener('input', (e) => {
        const target = e.target;
        const idx = target.getAttribute('data-idx');
        if (idx === null || !passengerList[idx]) return;

        const p = passengerList[idx];

        if (target.classList.contains('passenger-field-given')) {
            p.given_name = target.value;
        } else if (target.classList.contains('passenger-field-last')) {
            p.last_name = target.value;
        } else if (target.classList.contains('passenger-field-discount')) {
            p.discount_id = target.value;
        }

        // Live preview update
        const fullName = [p.given_name, p.last_name].filter(Boolean).join(' ');
        const previewEl = document.getElementById(`preview_name_${idx}`);
        if (previewEl) {
            previewEl.textContent = fullName || 'Full name not set';
        }

        // Status chip update
        const isCompleted = Boolean(p.given_name && p.given_name.trim() && p.last_name && p.last_name.trim() && (p.category === 'regular' || (p.discount_id && p.discount_id.trim())));
        const statusChip = document.getElementById(`status_chip_${idx}`);
        if (statusChip) {
            statusChip.className = `passenger-status-chip ${isCompleted ? 'status-completed' : 'status-pending'}`;
            statusChip.textContent = isCompleted ? 'Completed' : 'Not completed';
        }

        // Synchronize primary passenger name at top of form with Passenger 1
        if (parseInt(idx, 10) === 0) {
            const topNameInput = document.getElementById('ticket_name');
            if (topNameInput && fullName) {
                topNameInput.value = fullName;
            }
        }
    });

    const seatMapContainer = document.getElementById('ticket_seat_map_container');
    if (seatMapContainer) {
        seatMapContainer.addEventListener('click', (e) => {
            const seatEl = e.target.closest('.ticket-seat');
            if (!seatEl || seatEl.classList.contains('booked')) return;

            const seatNumber = (seatEl.dataset.seatNumber || '').trim();
            if (!seatNumber) return;

            if (selectedSeatNumbers.has(seatNumber)) {
                selectedSeatNumbers.delete(seatNumber);
            } else {
                selectedSeatNumbers.add(seatNumber);
            }

            const isSelected = selectedSeatNumbers.has(seatNumber);
            seatEl.classList.toggle('selected', isSelected);
            seatEl.classList.toggle('available', !isSelected);

            if (isSelected) {
                seatEl.textContent = '✓';
            } else {
                seatEl.textContent = seatEl.dataset.column || seatNumber;
            }

            updateSelectedSeatInputs(); // This will also call calculateTicketAmount() and syncPassengerListWithCounters()
        });
    }

    form?.addEventListener('submit', (e) => {
        e.preventDefault();

        const route = document.getElementById('ticket_route')?.value;
        const date = document.getElementById('ticket_date')?.value;
        const timeLabel = document.getElementById('ticket_time')?.value;

        const totalPassengers = categoryCounts.regular + categoryCounts.student + categoryCounts.senior;

        if (!route || !date || !timeLabel) {
            alert('Please select Route, Trip Date, and Trip Time.');
            return;
        }

        if (totalPassengers === 0) {
            alert('Please select at least one passenger using the (+) counter.');
            return;
        }

        if (!currentTripScheduleId) {
            alert('No matching scheduled trip was found for the selected Route, Date, Time, and Boat.');
            return;
        }

        const selectedSeatsInput = document.getElementById('ticket_selected_seats');
        const raw = selectedSeatsInput?.value || '';
        const seatNumbers = raw
            .split(',')
            .map(s => s.trim())
            .filter(n => n.length > 0);

        if (!seatNumbers.length) {
            alert('Please select at least one available seat from the seat map.');
            return;
        }

        // Validate individual passenger details
        for (let i = 0; i < passengerList.length; i++) {
            const p = passengerList[i];
            const catName = p.category === 'student' ? 'Student' : (p.category === 'senior' ? 'Senior Citizen / PWD' : 'Adult');
            if (!p.given_name || !p.given_name.trim() || !p.last_name || !p.last_name.trim()) {
                p.isExpanded = true;
                renderPassengerAccordionList();
                alert(`Please enter both Given name and Last name for Passenger ${i + 1} (${catName}).`);
                const input = document.querySelector(`.passenger-field-given[data-idx="${i}"]`);
                if (input) input.focus();
                return;
            }
            if ((p.category === 'student' || p.category === 'senior') && (!p.discount_id || !p.discount_id.trim())) {
                p.isExpanded = true;
                renderPassengerAccordionList();
                alert(`Please enter ID / Card Number for Passenger ${i + 1} (${catName} Discount Verification).`);
                const input = document.querySelector(`.passenger-field-discount[data-idx="${i}"]`);
                if (input) input.focus();
                return;
            }
        }

        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            alert('Unable to find CSRF token. Please refresh the page and try again.');
            return;
        }

        const bookBtn = document.getElementById('ticket_book_btn');
        if (bookBtn) {
            bookBtn.disabled = true;
            bookBtn.textContent = 'Booking...';
        }

        // Build seat breakdown with exact categories and individual fares
        const fareData = window.fareData || {};
        const routeFares = fareData[route] || { regular: 0, student: 0, senior: 0 };

        const seatBreakdown = passengerList.map((p, idx) => {
            const fare = p.category === 'student' ? parseFloat(routeFares.student || 0) :
                         p.category === 'senior' ? parseFloat(routeFares.senior || 0) :
                         parseFloat(routeFares.regular || 0);

            const fullName = [p.given_name.trim(), p.last_name.trim()].filter(Boolean).join(' ');
            const seat = p.seat || (seatNumbers[idx] || '1C');

            return {
                passenger_id: `P${idx + 1}`,
                passenger_name: fullName,
                category: p.category === 'student' ? 'Student' : (p.category === 'senior' ? 'Senior Citizen' : 'Regular'),
                seat: String(seat),
                individual_fare: fare,
                discount_id: p.discount_id ? p.discount_id.trim() : null,
            };
        });

        const primaryName = seatBreakdown[0]?.passenger_name || document.getElementById('ticket_name')?.value.trim() || 'Walk-in Passenger';
        const contactNum = document.getElementById('ticket_contact')?.value.trim() || null;
        const paymentMethod = document.getElementById('ticket_payment')?.value || 'Cash (Walk-in)';
        const amountCollected = parseFloat(document.getElementById('ticket_amount')?.value || 0);
        const notes = document.getElementById('ticket_notes')?.value.trim() || null;

        fetch(`/admin/trip-schedules/${currentTripScheduleId}/book-seats`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                seat_numbers: seatNumbers,
                passenger_name: primaryName,
                contact_number: contactNum,
                payment_method: paymentMethod,
                amount_collected: amountCollected,
                notes: notes,
                seat_breakdown: seatBreakdown,
            }),
        })
            .then(async (response) => {
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    const message = errorData.message || 'Failed to book seats. Please try again.';
                    throw new Error(message);
                }
                return response.json();
            })
            .then(() => {
                window.location.reload();
            })
            .catch((error) => {
                console.error('Error booking seats:', error);
                alert(error.message || 'Failed to book seats. Please try again.');
            })
            .finally(() => {
                if (bookBtn) {
                    bookBtn.disabled = false;
                    bookBtn.textContent = 'Book Ticket';
                }
            });
    });

    const statusSelects = document.querySelectorAll('.ticket-status-select');
    statusSelects.forEach((select) => {
        select.addEventListener('change', async (event) => {
            const target = event.target;
            const newStatus = target.value;
            const row = target.closest('tr[data-ticket-id]');

            if (!row) {
                return;
            }

            const bookingId = row.dataset.ticketId;
            if (!bookingId) {
                return;
            }

            const originalStatus = target.getAttribute('data-original-status') || 'pending';

            const confirmMessage = `Change booking status to "${newStatus}"? This action cannot be undone.`;
            const confirmed = await showTicketConfirmDialog(confirmMessage);
            if (!confirmed) {
                target.value = originalStatus;
                return;
            }

            const csrfToken = getCsrfToken();
            if (!csrfToken) {
                alert('Unable to find CSRF token. Please refresh the page and try again.');
                target.value = originalStatus;
                return;
            }

            target.disabled = true;

            fetch(`/admin/bookings/${bookingId}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    status: newStatus,
                }),
            })
                .then(async (response) => {
                    if (!response.ok) {
                        const errorData = await response.json().catch(() => ({}));
                        const message = errorData.message || 'Failed to update booking status. Please try again.';
                        throw new Error(message);
                    }
                    return response.json();
                })
                .then(() => {
                    window.location.reload();
                })
                .catch((error) => {
                    console.error('Error updating booking status:', error);
                    alert(error.message || 'Failed to update booking status. Please try again.');
                    target.disabled = false;
                    target.value = originalStatus;
                });
        });
    });

    // Confirm button click listener for Manage Bookings
    document.addEventListener('click', async (event) => {
        const confirmBtn = event.target.closest('.btn-confirm-booking-action');
        if (!confirmBtn) return;

        const bookingId = confirmBtn.dataset.bookingId;
        if (!bookingId) return;

        const confirmMessage = 'Approve and confirm this booking? This will generate the scannable QR code for the passenger.';
        const confirmed = await showTicketConfirmDialog(confirmMessage);
        if (!confirmed) return;

        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            alert('Unable to find CSRF token. Please refresh the page and try again.');
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Confirming...';

        fetch(`/admin/bookings/${bookingId}/status`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                status: 'confirmed',
            }),
        })
            .then(async (response) => {
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    const message = errorData.message || 'Failed to confirm booking.';
                    throw new Error(message);
                }
                return response.json();
            })
            .then(() => {
                window.location.reload();
            })
            .catch((error) => {
                console.error('Error confirming booking:', error);
                alert(error.message || 'Failed to confirm booking.');
                confirmBtn.disabled = false;
                confirmBtn.textContent = '✓ Confirm';
            });
    });
});

/**
 * Ticketing & Bookings interactions
 * - Booking status tabs
 */

document.addEventListener('DOMContentLoaded', () => {
    const tabContainers = document.querySelectorAll('[data-ticket-tabs]:not([data-requires-date])');

    tabContainers.forEach(container => {
        const tabs = container.querySelectorAll('.ticket-tab');
        const contents = container
            .closest('.ticket-section')
            .querySelectorAll('.ticket-tab-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.getAttribute('data-tab');

                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));

                tab.classList.add('active');
                const content = document.getElementById(`tab-${target}`);
                if (content) {
                    content.classList.add('active');
                }
            });
        });
    });
});

/* ==========================================================================
   Ticketing & Boarding Pass Print Engine (Thermal 80mm & Standard A4)
   ========================================================================== */

let activePrintBooking = null;
let currentPrintFormat = 'thermal'; // 'thermal' or 'standard'

function openTicketPrintModal(bookingData) {
    activePrintBooking = bookingData;
    const modal = document.getElementById('ticket_print_modal');
    if (!modal) return;

    modal.classList.add('is-visible');
    const modalBody = modal.querySelector('.ticket-print-modal-body');
    if (modalBody) modalBody.scrollTop = 0;
    renderPrintTickets(activePrintBooking, currentPrintFormat);
}

function closeTicketPrintModal() {
    const modal = document.getElementById('ticket_print_modal');
    if (!modal) return;
    modal.classList.remove('is-visible');
}

function renderPrintTickets(booking, format) {
    const sheet = document.getElementById('ticketPrintSheet');
    if (!sheet || !booking) return;

    sheet.className = `ticket-print-sheet format-${format}`;
    sheet.innerHTML = '';

    const tickets = booking.tickets && booking.tickets.length > 0
        ? booking.tickets
        : [
            {
                passenger_id: 'P1',
                passenger_name: booking.passenger_name || 'Passenger',
                seat: (booking.seat_numbers && booking.seat_numbers[0]) || '1C',
                category: 'Regular',
                individual_fare: booking.amount_collected || 0,
                qr_payload: JSON.stringify({
                    booking_ref: booking.reference_number,
                    passenger_name: booking.passenger_name,
                    vessel: booking.boat_name,
                    seat: (booking.seat_numbers && booking.seat_numbers[0]) || '1C',
                    status: 'CONFIRMED',
                }),
            },
        ];

    if (format === 'thermal') {
        renderThermalTickets(sheet, booking, tickets);
    } else {
        renderStandardTickets(sheet, booking, tickets);
    }

    // Generate scannable QR codes for each passenger
    setTimeout(() => {
        generateAllTicketQRCodes(sheet);
    }, 50);
}

function renderThermalTickets(container, booking, tickets) {
    const totalTickets = tickets.length;
    let html = '';

    tickets.forEach((ticket, idx) => {
        const qrPayloadStr = ticket.qr_payload || JSON.stringify({
            booking_ref: booking.reference_number,
            passenger_name: ticket.passenger_name,
            vessel: booking.boat_name,
            seat: ticket.seat,
            status: 'CONFIRMED',
        });

        html += `
            <div class="thermal-ticket-card">
                <div class="thermal-header">
                    <img src="/images/seapass_logo.png" alt="SeaPass" style="height: 38px; width: auto; margin: 0 auto 4px; display: block;" onerror="this.style.display='none'">
                    <h2 class="thermal-logo-title">SeaPass</h2>
                    <div class="thermal-subtitle">Port Terminal Boarding Pass</div>
                    <div class="thermal-tag">
                        ${totalTickets > 1 ? `TICKET ${idx + 1} OF ${totalTickets} · ` : ''}CONFIRMED
                    </div>
                </div>

                <table class="thermal-meta-table">
                    <tr>
                        <td class="label">Booking Ref:</td>
                        <td class="value">${escapeHtml(booking.reference_number)}</td>
                    </tr>
                    <tr>
                        <td class="label">Route:</td>
                        <td class="value">${escapeHtml(booking.route)}</td>
                    </tr>
                    <tr>
                        <td class="label">Vessel:</td>
                        <td class="value">${escapeHtml(booking.boat_name)}</td>
                    </tr>
                    <tr>
                        <td class="label">Departure:</td>
                        <td class="value">${escapeHtml(booking.trip_date)} · ${escapeHtml(booking.departure_time)}</td>
                    </tr>
                    <tr>
                        <td class="label">Booked Date:</td>
                        <td class="value">${escapeHtml(booking.booking_date || '')}</td>
                    </tr>
                    <tr>
                        <td class="label">Payment:</td>
                        <td class="value">${escapeHtml(booking.payment_method || 'GCash / Counter')}</td>
                    </tr>
                </table>

                <div class="thermal-seat-box">
                    <div class="seat-label">Assigned Seat</div>
                    <div class="seat-num">Seat ${escapeHtml(ticket.seat)}</div>
                </div>

                <table class="thermal-meta-table">
                    <tr>
                        <td class="label">Passenger:</td>
                        <td class="value">${escapeHtml(ticket.passenger_name)}</td>
                    </tr>
                    <tr>
                        <td class="label">Category:</td>
                        <td class="value">${escapeHtml(ticket.category || 'Regular')}</td>
                    </tr>
                    <tr>
                        <td class="label">Individual Fare:</td>
                        <td class="value">₱${Number(ticket.individual_fare || 0).toFixed(2)}</td>
                    </tr>
                </table>

                <div class="thermal-qr-container">
                    <div class="thermal-qr-code" data-qr-payload="${escapeHtml(qrPayloadStr)}"></div>
                    <div class="thermal-qr-caption">SCAN AT PORT GATE FOR BOARDING</div>
                    <div style="font-size: 9.5px; font-family: monospace; font-weight: 700; color: #475569; margin-top: 3px;">
                        *${escapeHtml(booking.reference_number)}-${ticket.passenger_id || (idx + 1)}*
                    </div>
                </div>

                <div class="thermal-fare-summary">
                    <span>${totalTickets > 1 ? `Ticket ${idx + 1} Amount:` : 'Total Amount Paid:'}</span>
                    <span>₱${Number(ticket.individual_fare || booking.amount_collected).toFixed(2)}</span>
                </div>

                <div class="thermal-footer">
                    <div>Valid for one-way voyage on scheduled departure.</div>
                    <div>Thank you for choosing SeaPass Ferry Services!</div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function renderStandardTickets(container, booking, tickets) {
    const totalTickets = tickets.length;
    let html = '';

    tickets.forEach((ticket, idx) => {
        const qrPayloadStr = ticket.qr_payload || JSON.stringify({
            booking_ref: booking.reference_number,
            passenger_name: ticket.passenger_name,
            vessel: booking.boat_name,
            seat: ticket.seat,
            status: 'CONFIRMED',
        });

        html += `
            <div class="standard-ticket-card">
                <div class="standard-header-strip">
                    <div class="standard-brand">
                        <span>🚤</span>
                        <span>SeaPass Ferry Service</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 12px; color: #94a3b8; font-weight: 600;">
                            ${totalTickets > 1 ? `Ticket ${idx + 1} of ${totalTickets}` : 'Official Boarding Pass'}
                        </span>
                        <span class="standard-status-badge">CONFIRMED</span>
                    </div>
                </div>

                <div class="standard-body-grid">
                    <div class="standard-qr-pane">
                        <div class="thermal-qr-code" data-qr-payload="${escapeHtml(qrPayloadStr)}"></div>
                        <div class="standard-qr-caption">
                            Ref: ${escapeHtml(booking.reference_number)}<br>
                            Seat: <strong>${escapeHtml(ticket.seat)}</strong>
                        </div>
                    </div>

                    <div class="standard-details-pane">
                        <div class="standard-route-title">${escapeHtml(booking.route)}</div>
                        
                        <table class="standard-receipt-table">
                            <tr>
                                <td class="label">Passenger Name:</td>
                                <td class="value">${escapeHtml(ticket.passenger_name)}</td>
                            </tr>
                            <tr>
                                <td class="label">Booking Ref:</td>
                                <td class="value"><strong>${escapeHtml(booking.reference_number)}</strong></td>
                            </tr>
                            <tr>
                                <td class="label">Assigned Seat:</td>
                                <td class="value">
                                    <span class="standard-seat-badge">
                                        💺 Seat ${escapeHtml(ticket.seat)}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="label">Fare Category:</td>
                                <td class="value">${escapeHtml(ticket.category || 'Regular')}</td>
                            </tr>
                            <tr>
                                <td class="label">Vessel / Boat:</td>
                                <td class="value">${escapeHtml(booking.boat_name)}</td>
                            </tr>
                            <tr>
                                <td class="label">Trip Schedule:</td>
                                <td class="value">${escapeHtml(booking.trip_date)} · ${escapeHtml(booking.departure_time)}</td>
                            </tr>
                            <tr>
                                <td class="label">Payment Method:</td>
                                <td class="value">${escapeHtml(booking.payment_method || 'GCash / Counter')}</td>
                            </tr>
                        </table>

                        <div class="standard-fare-row">
                            <span class="fare-label">Individual Fare:</span>
                            <span class="fare-value">₱${Number(ticket.individual_fare || 0).toFixed(2)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function generateAllTicketQRCodes(container) {
    const qrContainers = container.querySelectorAll('.thermal-qr-code');
    qrContainers.forEach(el => {
        const payload = el.getAttribute('data-qr-payload');
        if (!payload) return;

        el.innerHTML = '';

        if (typeof QRCode !== 'undefined') {
            try {
                new QRCode(el, {
                    text: payload,
                    width: 140,
                    height: 140,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M,
                });
                return;
            } catch (err) {
                console.warn('QRCode library error, using fallback API:', err);
            }
        }

        // Fallback to QR server API image
        const img = document.createElement('img');
        img.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(payload)}`;
        img.alt = 'QR Code';
        img.style.width = '140px';
        img.style.height = '140px';
        el.appendChild(img);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ==========================================================================
// Passenger Details Modal Dynamic Logic (image_c581cc.png design)
// ==========================================================================

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function openPassengerDetailsModal(info) {
    const modal = document.getElementById('passenger_details_modal');
    if (!modal) return;

    const refEl = document.getElementById('modalBookingRef');
    const routeEl = document.getElementById('modalRouteText');
    const scheduleEl = document.getElementById('modalScheduleText');
    const vesselEl = document.getElementById('modalVesselText');
    const countEl = document.getElementById('modalPassengerCount');
    const amountEl = document.getElementById('modalBookingAmount');
    const listContainer = document.getElementById('passengerDetailsList');

    if (refEl) refEl.textContent = info.ref || 'SP-0000';
    if (routeEl) routeEl.textContent = info.route || 'Route not specified';
    
    const tripDetails = [info.tripDate, info.tripTime].filter(Boolean).join(' · ');
    if (scheduleEl) scheduleEl.textContent = tripDetails || 'N/A';
    if (vesselEl) vesselEl.textContent = info.vessel || 'M/B SeaPass';
    if (amountEl) amountEl.textContent = info.totalAmount || '';

    const passengers = Array.isArray(info.passengers) ? info.passengers : [];
    if (countEl) {
        countEl.textContent = `${passengers.length} ${passengers.length === 1 ? 'Passenger' : 'Passengers'}`;
    }

    if (listContainer) {
        if (passengers.length === 0) {
            listContainer.innerHTML = `
                <div style="padding: 24px; text-align: center; color: #8b949e; font-size: 13px;">
                    No passenger breakdown details recorded for this booking.
                </div>
            `;
        } else {
            listContainer.innerHTML = passengers.map((p, idx) => {
                const name = p.passenger_name || `Passenger ${idx + 1}`;
                const idLabel = p.passenger_id || `Passenger #${idx + 1}`;

                // Format Seat Number e.g. "Seat #1", "Seat #4A"
                let seatLabel = p.seat_display || p.seat || `${idx + 1}`;
                if (!String(seatLabel).toLowerCase().startsWith('seat')) {
                    seatLabel = `Seat #${seatLabel}`;
                }

                // Category & Badge Class
                const catRaw = String(p.category || 'Regular').toLowerCase();
                let catClass = 'cat-regular';
                let catIcon = '🎫';
                let catText = 'Regular';

                if (catRaw.includes('student')) {
                    catClass = 'cat-student';
                    catIcon = '🎓';
                    catText = 'Student';
                } else if (catRaw.includes('senior') || catRaw.includes('pwd')) {
                    catClass = 'cat-senior';
                    catIcon = '👴';
                    catText = 'Senior Citizen';
                }

                // Individual Fare
                const fareHtml = p.individual_fare && parseFloat(p.individual_fare) > 0
                    ? `<span class="passenger-item-fare">₱${parseFloat(p.individual_fare).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>`
                    : '';

                // Discount ID Verification Photo
                let idPhotoHtml = '';
                const isDiscounted = catRaw.includes('student') || catRaw.includes('senior') || catRaw.includes('pwd');
                if (isDiscounted) {
                    if (p.id_photo_url) {
                        idPhotoHtml = `
                            <button type="button" class="passenger-id-preview-btn" onclick="event.stopPropagation(); openIdPhotoLightbox('${escapeHtml(p.id_photo_url)}', '${escapeHtml(name)}', '${escapeHtml(catText)}')">
                                <span class="id-thumb-wrap">
                                    <img src="${escapeHtml(p.id_photo_url)}" alt="ID" class="id-micro-thumb" onerror="this.style.display='none'; this.parentElement.innerHTML='🪪';" />
                                </span>
                                <span>View ID</span>
                            </button>
                        `;
                    } else {
                        idPhotoHtml = `
                            <span class="passenger-id-missing-badge" title="No ID photo attached for this discounted passenger">
                                ⚠️ No ID Uploaded
                            </span>
                        `;
                    }
                }

                return `
                    <div class="passenger-item-card">
                        <div class="passenger-item-left">
                            <div class="passenger-avatar-icon">👤</div>
                            <div class="passenger-name-wrap">
                                <div class="passenger-card-name">${escapeHtml(name)}</div>
                                <div class="passenger-card-id">${escapeHtml(idLabel)}</div>
                            </div>
                        </div>
                        <div class="passenger-item-right">
                            <div class="passenger-seat-badge">
                                <span class="seat-icon">💺</span>
                                <span>${escapeHtml(seatLabel)}</span>
                            </div>
                            <span class="passenger-cat-badge ${catClass}">
                                <span>${catIcon}</span>
                                <span>${escapeHtml(catText)}</span>
                            </span>
                            ${idPhotoHtml}
                            ${fareHtml}
                        </div>
                    </div>
                `;
            }).join('');
        }
    }

    // Configure approve & reject buttons inside passenger details modal
    const approveBtn = document.getElementById('modalApproveBtn');
    const rejectBtn = document.getElementById('modalRejectBtn');
    if (approveBtn && rejectBtn) {
        if (info.isToBeConfirmed === '1' || info.status === 'to_be_confirmed' || info.status === 'pending') {
            approveBtn.style.display = 'inline-block';
            rejectBtn.style.display = 'inline-block';
            approveBtn.dataset.bookingId = info.bookingId || '';
            approveBtn.dataset.bookingRef = info.ref || '';
            rejectBtn.dataset.bookingId = info.bookingId || '';
            rejectBtn.dataset.bookingRef = info.ref || '';
            rejectBtn.dataset.amount = info.totalAmount || '₱0.00';
        } else {
            approveBtn.style.display = 'none';
            rejectBtn.style.display = 'none';
        }
    }

    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closePassengerDetailsModal() {
    const modal = document.getElementById('passenger_details_modal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

async function handleApproveBooking(bookingId, bookingRef) {
    if (!bookingId) return;
    const confirmed = confirm(`Approve discounted booking #${bookingRef}? This will verify the passenger's discount ID, issue the official boarding pass QR code, and notify the passenger via Email & Push Notification.`);
    if (!confirmed) return;

    const csrfToken = getCsrfToken();
    try {
        const response = await fetch(`/admin/bookings/${bookingId}/approve`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Failed to approve booking.');
        }
        alert(data.message || 'Booking approved successfully!');
        window.location.reload();
    } catch (err) {
        console.error('Approval error:', err);
        alert(err.message || 'An error occurred while approving the booking.');
    }
}

function openRejectionRefundModal(bookingId, bookingRef, amount) {
    const modal = document.getElementById('rejection_refund_modal');
    if (!modal) return;
    const idInput = document.getElementById('rejectBookingId');
    const refEl = document.getElementById('rejectBookingRefDisplay');
    const amountEl = document.getElementById('rejectRefundAmountDisplay');
    const presetEl = document.getElementById('rejectPresetReason');
    const customEl = document.getElementById('rejectCustomReason');

    if (idInput) idInput.value = bookingId;
    if (refEl) refEl.textContent = '#' + (bookingRef || 'SP-0000');
    if (amountEl) amountEl.textContent = amount || '₱0.00';
    if (presetEl) presetEl.value = 'Uploaded ID photo is unreadable or blurry.';
    if (customEl) {
        customEl.style.display = 'none';
        customEl.value = '';
    }

    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeRejectionRefundModal() {
    const modal = document.getElementById('rejection_refund_modal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
}

async function handleRejectionRefundSubmit(e) {
    e.preventDefault();
    const bookingId = document.getElementById('rejectBookingId')?.value;
    if (!bookingId) return;

    const preset = document.getElementById('rejectPresetReason')?.value;
    const custom = document.getElementById('rejectCustomReason')?.value?.trim();
    const reason = (preset === 'custom' ? custom : preset) || 'Discount ID could not be verified.';

    if (preset === 'custom' && !custom) {
        alert('Please specify the custom reason for rejection.');
        return;
    }

    const submitBtn = document.getElementById('confirmRejectRefundBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span>Processing Refund via PayMongo...</span>';
    }

    const csrfToken = getCsrfToken();
    try {
        const response = await fetch(`/admin/bookings/${bookingId}/reject-refund`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ reason: reason }),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Failed to reject and refund booking.');
        }
        alert(data.message || 'Booking rejected and automated refund initiated.');
        window.location.reload();
    } catch (err) {
        console.error('Rejection & Refund error:', err);
        alert(err.message || 'An error occurred while rejecting and refunding the booking.');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<span>Confirm Rejection &amp; Refund</span>';
        }
    }
}

function openIdPhotoLightbox(imageUrl, passengerName, category) {
    const lightbox = document.getElementById('id_photo_lightbox_modal');
    if (!lightbox) return;

    const imgEl = document.getElementById('idLightboxImage');
    const nameEl = document.getElementById('idLightboxPassengerName');
    const catEl = document.getElementById('idLightboxCategory');
    const linkEl = document.getElementById('idLightboxFullLink');

    if (imgEl) imgEl.src = imageUrl;
    if (nameEl) nameEl.textContent = passengerName || 'Passenger ID';
    if (catEl) catEl.textContent = category || 'Discount Verification';
    if (linkEl) linkEl.href = imageUrl;

    lightbox.style.display = 'flex';
    lightbox.setAttribute('aria-hidden', 'false');
}

function closeIdPhotoLightbox() {
    const lightbox = document.getElementById('id_photo_lightbox_modal');
    if (lightbox) {
        lightbox.style.display = 'none';
        lightbox.setAttribute('aria-hidden', 'true');
        const imgEl = document.getElementById('idLightboxImage');
        if (imgEl) imgEl.src = '';
    }
}

// Expose lightbox methods globally
window.openIdPhotoLightbox = openIdPhotoLightbox;
window.closeIdPhotoLightbox = closeIdPhotoLightbox;

// Global Event Listeners for Print & Passenger Modal Actions
document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeIdPhotoLightbox();
            closePassengerDetailsModal();
        }
    });

    document.addEventListener('click', (event) => {
        // 0. Lightbox Modal Close
        if (event.target.closest('#closeIdLightbox') || event.target.closest('#cancelIdLightbox') || event.target.closest('.id-lightbox-close')) {
            closeIdPhotoLightbox();
            return;
        }
        const idLightboxModal = document.getElementById('id_photo_lightbox_modal');
        if (idLightboxModal && event.target === idLightboxModal) {
            closeIdPhotoLightbox();
            return;
        }

        // 1. Passenger Details Modal Trigger
        const passengerBtn = event.target.closest('.btn-view-passengers');
        if (passengerBtn) {
            try {
                const passengersJson = passengerBtn.getAttribute('data-passengers');
                const passengers = passengersJson ? JSON.parse(passengersJson) : [];
                const info = {
                    bookingId: passengerBtn.getAttribute('data-booking-id'),
                    ref: passengerBtn.getAttribute('data-booking-ref'),
                    route: passengerBtn.getAttribute('data-route'),
                    tripDate: passengerBtn.getAttribute('data-trip-date'),
                    tripTime: passengerBtn.getAttribute('data-trip-time'),
                    vessel: passengerBtn.getAttribute('data-vessel'),
                    totalAmount: passengerBtn.getAttribute('data-total-amount'),
                    amount: passengerBtn.getAttribute('data-amount'),
                    isToBeConfirmed: passengerBtn.getAttribute('data-is-to-be-confirmed'),
                    status: passengerBtn.getAttribute('data-status'),
                    passengers: passengers
                };
                openPassengerDetailsModal(info);
            } catch (err) {
                console.error('Failed to parse passenger data for modal:', err);
            }
            return;
        }

        // 2. Close Passenger Details Modal
        if (event.target.closest('#closePassengerModal') || event.target.closest('#cancelPassengerModal')) {
            closePassengerDetailsModal();
            return;
        }

        // Close when clicking directly on the modal backdrop
        const pModal = document.getElementById('passenger_details_modal');
        if (pModal && event.target === pModal) {
            closePassengerDetailsModal();
            return;
        }

        // 3. Approve Booking (From Table or Passenger Modal)
        const approveBtn = event.target.closest('.btn-approve-booking-action') || event.target.closest('#modalApproveBtn');
        if (approveBtn) {
            const bId = approveBtn.dataset.bookingId;
            const bRef = approveBtn.dataset.bookingRef;
            if (bId) {
                handleApproveBooking(bId, bRef);
            }
            return;
        }

        // 4. Reject & Refund Booking (From Table or Passenger Modal)
        const rejectBtn = event.target.closest('.btn-reject-refund-action') || event.target.closest('#modalRejectBtn');
        if (rejectBtn) {
            const bId = rejectBtn.dataset.bookingId;
            const bRef = rejectBtn.dataset.bookingRef;
            const bAmt = rejectBtn.dataset.amount || '₱0.00';
            if (bId) {
                openRejectionRefundModal(bId, bRef, bAmt);
            }
            return;
        }

        // 5. Close Rejection Modal
        if (event.target.closest('#closeRejectionModal') || event.target.closest('#cancelRejectionModal')) {
            closeRejectionRefundModal();
            return;
        }
        const rejModal = document.getElementById('rejection_refund_modal');
        if (rejModal && event.target === rejModal) {
            closeRejectionRefundModal();
            return;
        }

        // 6. Print Ticket Actions
        const printBtn = event.target.closest('.btn-print-ticket-action');
        if (printBtn) {
            try {
                const bookingData = JSON.parse(printBtn.getAttribute('data-booking'));
                openTicketPrintModal(bookingData);
            } catch (e) {
                console.error('Failed to parse booking data:', e);
            }
            return;
        }

        const closeBtn = event.target.closest('[data-print-modal-close]');
        if (closeBtn) {
            closeTicketPrintModal();
            return;
        }

        const formatBtn = event.target.closest('.btn-format-toggle');
        if (formatBtn) {
            const format = formatBtn.getAttribute('data-format');
            if (format) {
                currentPrintFormat = format;
                document.querySelectorAll('.btn-format-toggle').forEach(b => b.classList.remove('active'));
                formatBtn.classList.add('active');
                const modalBody = document.querySelector('.ticket-print-modal-body');
                if (modalBody) modalBody.scrollTop = 0;
                if (activePrintBooking) {
                    renderPrintTickets(activePrintBooking, currentPrintFormat);
                }
            }
            return;
        }

        const triggerPrint = event.target.closest('#btnTriggerPrint');
        if (triggerPrint) {
            window.print();
            return;
        }
    });

    // Rejection reason preset change
    const presetSelect = document.getElementById('rejectPresetReason');
    const customTextarea = document.getElementById('rejectCustomReason');
    if (presetSelect && customTextarea) {
        presetSelect.addEventListener('change', function () {
            if (this.value === 'custom') {
                customTextarea.style.display = 'block';
                customTextarea.focus();
            } else {
                customTextarea.style.display = 'none';
            }
        });
    }

    // Rejection form submission
    const rejForm = document.getElementById('rejectionRefundForm');
    if (rejForm) {
        rejForm.addEventListener('submit', handleRejectionRefundSubmit);
    }

    // Escape key to close any open modal
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeTicketPrintModal();
            closePassengerDetailsModal();
            closeRejectionRefundModal();
        }
    });
});


