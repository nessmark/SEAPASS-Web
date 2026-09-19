@extends('layouts.admin')

@section('title', 'Ticketing & Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ticket-book.css') }}?v={{ time() }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/qrcode.min.js') }}"></script>
    <script src="{{ asset('js/ticket-book.js') }}?v={{ time() }}"></script>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="ticket-header">
                <div>
                    <div class="card-title">Ticketing &amp; Bookings</div>
                    <div class="card-subtitle">
                        Manage bookings, walk‑in ticketing, and refund requests in one place.
                    </div>
                </div>
            </div>

            <div class="ticket-layout">
                {{-- Manage Bookings --}}
                <section class="ticket-section">
                    <div class="ticket-section-header">
                        <div>
                            <h2>Manage Bookings</h2>
                            <p>Select a date to view pending, confirmed, and cancelled bookings.</p>
                        </div>
                        <div class="ticket-filter">
                            <form method="GET" action="{{ route('admin.tickets.index') }}">
                                <label for="ticket_filter_date">Booking Date</label>
                                <input
                                    type="date"
                                    id="ticket_filter_date"
                                    name="booking_date"
                                    value="{{ $selectedDate ?? '' }}"
                                    onchange="this.form.submit()"
                                >
                            </form>
                        </div>
                    </div>

                    <div class="ticket-tabs" data-ticket-tabs data-requires-date>
                        <button class="ticket-tab active" data-tab="pending">
                            Pending
                            @if(isset($toBeConfirmedCount) && $toBeConfirmedCount > 0)
                                <span class="badge-tab-count" style="background: #f59e0b; color: #fff; border-radius: 9999px; padding: 1px 7px; font-size: 11px; font-weight: 700; margin-left: 4px;">{{ $toBeConfirmedCount }} ID</span>
                            @endif
                        </button>
                        <button class="ticket-tab" data-tab="confirmed">Confirmed</button>
                        <button class="ticket-tab" data-tab="cancelled">Cancelled</button>
                    </div>

                    <div class="ticket-tab-content active" id="tab-pending">
                        <table class="ticket-table">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>Passengers</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="ticketPendingBody">
                                @forelse($pendingBookings ?? [] as $booking)
                                    @php
                                        $ticketList = $booking->passenger_tickets;
                                        $pCount = count($ticketList) ?: (is_array($booking->seat_numbers) ? count($booking->seat_numbers) : 1);
                                        $primaryName = $booking->passenger_name ?: 'Passenger';
                                        $vesselName = $booking->tripSchedule?->boat?->name ?? 'M/B SeaPass';
                                        $tripTimeFormatted = \Carbon\Carbon::hasFormat($booking->departure_time_slot, 'H:i') ? \Carbon\Carbon::createFromFormat('H:i', $booking->departure_time_slot)->format('g:i A') : $booking->departure_time_slot;
                                        $tripDateFormatted = optional($booking->trip_date)->format('M d, Y') ?: ($booking->created_at ? $booking->created_at->format('M d, Y') : '');
                                        $bookingRef = $booking->reference_number ?: ('SP-' . $booking->id);
                                        $isVerificationRequired = $booking->isToBeConfirmed() || $booking->hasDiscounts();
                                    @endphp
                                    <tr data-ticket-id="{{ $booking->id }}" data-trip-date="{{ optional($booking->trip_date)->toDateString() }}">
                                        <td>
                                            <strong>{{ $bookingRef }}</strong>
                                            @if($booking->isToBeConfirmed())
                                                <div style="margin-top: 3px;">
                                                    <span style="background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.35); padding: 2px 7px; border-radius: 9999px; font-size: 10.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 3px;" title="Paid via PayMongo. Student/Senior/PWD ID awaiting verification.">
                                                        ⏳ ID Verification
                                                    </span>
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="passenger-cell-group">
                                                <span class="passenger-primary-name">{{ $primaryName }}</span>
                                                <button 
                                                    type="button" 
                                                    class="passenger-badge-btn btn-view-passengers" 
                                                    data-booking-id="{{ $booking->id }}"
                                                    data-booking-ref="{{ $bookingRef }}"
                                                    data-route="{{ $booking->route }}"
                                                    data-trip-date="{{ $tripDateFormatted }}"
                                                    data-trip-time="{{ $tripTimeFormatted }}"
                                                    data-vessel="{{ $vesselName }}"
                                                    data-passengers="{{ json_encode($ticketList) }}"
                                                    data-total-amount="₱{{ number_format((float) $booking->amount_collected, 2) }}"
                                                    data-amount="{{ (float) $booking->amount_collected }}"
                                                    data-is-to-be-confirmed="{{ $isVerificationRequired ? '1' : '0' }}"
                                                    data-status="{{ $booking->status }}"
                                                    title="Click to view details for {{ $pCount }} {{ $pCount === 1 ? 'passenger' : 'passengers' }}"
                                                >
                                                    <span class="badge-icon">👥</span>
                                                    <span class="badge-count">{{ $pCount }} {{ $pCount === 1 ? 'Passenger' : 'Passengers' }}</span>
                                                    <span class="badge-view-icon">👁️</span>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                                @if($isVerificationRequired)
                                                    <button type="button" class="btn-approve-booking-action" data-booking-id="{{ $booking->id }}" data-booking-ref="{{ $bookingRef }}" style="background-color: #059669; color: #ffffff; border: none; font-weight: 700; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;" title="Approve discounted ID and issue boarding pass QR">
                                                        ✓ Approve
                                                    </button>
                                                    <button type="button" class="btn-reject-refund-action" data-booking-id="{{ $booking->id }}" data-booking-ref="{{ $bookingRef }}" data-amount="₱{{ number_format((float) $booking->amount_collected, 2) }}" style="background-color: #dc2626; color: #ffffff; border: none; font-weight: 700; padding: 6px 10px; border-radius: 6px; cursor: pointer; font-size: 12px;" title="Reject ID and automatically refund via PayMongo">
                                                        ✕ Reject &amp; Refund
                                                    </button>
                                                @else
                                                    <button type="button" class="btn-confirm-booking-action" data-booking-id="{{ $booking->id }}" style="background-color: #81C7A8; color: #0D5C3A; border: none; font-weight: 700; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;">
                                                        ✓ Confirm
                                                    </button>
                                                @endif
                                                <select
                                                    class="ticket-status-select"
                                                    data-original-status="{{ $booking->status }}"
                                                    @if($booking->status !== 'pending' && $booking->status !== 'to_be_confirmed') disabled @endif
                                                    style="padding: 4px 6px; font-size: 12px;"
                                                >
                                                    <option value="pending" @selected($booking->status === 'pending')>Pending</option>
                                                    @if($booking->isToBeConfirmed())
                                                        <option value="to_be_confirmed" selected>To Be Confirmed</option>
                                                    @endif
                                                    <option value="confirmed" @selected($booking->status === 'confirmed')>Confirmed</option>
                                                    <option value="cancelled" @selected($booking->status === 'cancelled')>Cancelled</option>
                                                </select>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr id="ticketPendingEmptyRow">
                                        <td colspan="3" style="padding: 14px 10px; text-align: center; color: var(--text-secondary); font-size: 13px;">
                                            Pending bookings created from Override / Counter Booking and Mobile Passenger Booking API will appear here for review and confirmation.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="ticket-tab-content" id="tab-confirmed">
                        <table class="ticket-table">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>Passengers</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="ticketConfirmedBody">
                                @forelse($confirmedBookings ?? [] as $booking)
                                    @php
                                        $ticketList = $booking->passenger_tickets;
                                        $pCount = count($ticketList) ?: (is_array($booking->seat_numbers) ? count($booking->seat_numbers) : 1);
                                        $primaryName = $booking->passenger_name ?: 'Passenger';
                                        $vesselName = $booking->tripSchedule?->boat?->name ?? 'M/B SeaPass';
                                        $tripTimeFormatted = \Carbon\Carbon::hasFormat($booking->departure_time_slot, 'H:i') ? \Carbon\Carbon::createFromFormat('H:i', $booking->departure_time_slot)->format('g:i A') : $booking->departure_time_slot;
                                        $tripDateFormatted = optional($booking->trip_date)->format('M d, Y') ?: ($booking->created_at ? $booking->created_at->format('M d, Y') : '');
                                        $bookingRef = $booking->reference_number ?: ('SP-' . $booking->id);
                                    @endphp
                                    <tr data-ticket-id="{{ $booking->id }}" data-trip-date="{{ optional($booking->trip_date)->toDateString() }}">
                                        <td><strong>{{ $bookingRef }}</strong></td>
                                        <td>
                                            <div class="passenger-cell-group">
                                                <span class="passenger-primary-name">{{ $primaryName }}</span>
                                                <button 
                                                    type="button" 
                                                    class="passenger-badge-btn btn-view-passengers" 
                                                    data-booking-ref="{{ $bookingRef }}"
                                                    data-route="{{ $booking->route }}"
                                                    data-trip-date="{{ $tripDateFormatted }}"
                                                    data-trip-time="{{ $tripTimeFormatted }}"
                                                    data-vessel="{{ $vesselName }}"
                                                    data-passengers="{{ json_encode($ticketList) }}"
                                                    data-total-amount="₱{{ number_format((float) $booking->amount_collected, 2) }}"
                                                    title="Click to view details for {{ $pCount }} {{ $pCount === 1 ? 'passenger' : 'passengers' }}"
                                                >
                                                    <span class="badge-icon">👥</span>
                                                    <span class="badge-count">{{ $pCount }} {{ $pCount === 1 ? 'Passenger' : 'Passengers' }}</span>
                                                    <span class="badge-view-icon">👁️</span>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-pill status-confirmed">Confirmed</span>
                                        </td>
                                        <td>
                                            <button 
                                                type="button" 
                                                class="btn-print-ticket-action"
                                                data-booking="{{ json_encode($booking->print_payload) }}"
                                                title="Print E-Ticket & Boarding Pass"
                                            >
                                                <span>🖨️</span>
                                                <span>Print / View QR</span>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr id="ticketConfirmedEmptyRow">
                                        <td colspan="4" style="padding: 14px 10px; text-align: center; color: var(--text-secondary); font-size: 13px;">
                                            Confirmed bookings from both the admin desk and Mobile Passenger Booking API will be listed here.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="ticket-tab-content" id="tab-cancelled">
                        <table class="ticket-table">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>Passengers</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="ticketCancelledBody">
                                @forelse($cancelledBookings ?? [] as $booking)
                                    @php
                                        $ticketList = $booking->passenger_tickets;
                                        $pCount = count($ticketList) ?: (is_array($booking->seat_numbers) ? count($booking->seat_numbers) : 1);
                                        $primaryName = $booking->passenger_name ?: 'Passenger';
                                        $vesselName = $booking->tripSchedule?->boat?->name ?? 'M/B SeaPass';
                                        $tripTimeFormatted = \Carbon\Carbon::hasFormat($booking->departure_time_slot, 'H:i') ? \Carbon\Carbon::createFromFormat('H:i', $booking->departure_time_slot)->format('g:i A') : $booking->departure_time_slot;
                                        $tripDateFormatted = optional($booking->trip_date)->format('M d, Y') ?: ($booking->created_at ? $booking->created_at->format('M d, Y') : '');
                                        $bookingRef = $booking->reference_number ?: ('SP-' . $booking->id);
                                    @endphp
                                    <tr data-ticket-id="{{ $booking->id }}" data-trip-date="{{ optional($booking->trip_date)->toDateString() }}">
                                        <td><strong>{{ $bookingRef }}</strong></td>
                                        <td>
                                            <div class="passenger-cell-group">
                                                <span class="passenger-primary-name">{{ $primaryName }}</span>
                                                <button 
                                                    type="button" 
                                                    class="passenger-badge-btn btn-view-passengers" 
                                                    data-booking-ref="{{ $bookingRef }}"
                                                    data-route="{{ $booking->route }}"
                                                    data-trip-date="{{ $tripDateFormatted }}"
                                                    data-trip-time="{{ $tripTimeFormatted }}"
                                                    data-vessel="{{ $vesselName }}"
                                                    data-passengers="{{ json_encode($ticketList) }}"
                                                    data-total-amount="₱{{ number_format((float) $booking->amount_collected, 2) }}"
                                                    title="Click to view details for {{ $pCount }} {{ $pCount === 1 ? 'passenger' : 'passengers' }}"
                                                >
                                                    <span class="badge-icon">👥</span>
                                                    <span class="badge-count">{{ $pCount }} {{ $pCount === 1 ? 'Passenger' : 'Passengers' }}</span>
                                                    <span class="badge-view-icon">👁️</span>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-pill status-cancelled">Cancelled</span>
                                            @if($booking->refund_status === 'refunded' || (float) $booking->refund_amount > 0)
                                                <div style="font-size: 11px; color: #0284c7; margin-top: 4px; font-weight: 600;" title="Refund ID: {{ $booking->paymongo_refund_id ?? 'N/A' }}">
                                                    ✓ Refunded ₱{{ number_format((float) ($booking->refund_amount ?: $booking->amount_collected), 2) }} via PayMongo
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr id="ticketCancelledEmptyRow">
                                        <td colspan="3" style="padding: 14px 10px; text-align: center; color: var(--text-secondary); font-size: 13px;">
                                            Cancelled bookings (including those cancelled by passengers via the Mobile Booking app) will appear here.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- Override / Counter Booking --}}
                <section class="ticket-section">
                    <div class="ticket-section-header">
                        <h2>Override / Counter Booking</h2>
                        <p>Manually book tickets for walk‑in passengers paying with cash.</p>
                    </div>

                    <form class="ticket-form" id="ticketOverrideForm">
                        @csrf
                        <div class="ticket-form-grid">
                            <div class="ticket-field">
                                <label>Passenger Name</label>
                                <input type="text" id="ticket_name" placeholder="Full name" required>
                            </div>
                            <div class="ticket-field">
                                <label>Contact Number</label>
                                <input type="text" id="ticket_contact" placeholder="09XX XXX XXXX">
                            </div>
                            <div class="ticket-field">
                                <label>Route</label>
                                <select id="ticket_route">
                                    <option value="Surigao → San Jose(Dinagat)">Surigao → San Jose(Dinagat)</option>
                                    <option value="San Jose(Dinagat) → Surigao">San Jose(Dinagat) → Surigao</option>
                                </select>
                            </div>
                            <div class="ticket-field">
                                <label>Trip Date</label>
                                <input type="date" id="ticket_date" required>
                            </div>
                            <div class="ticket-field">
                                <label>Trip Time</label>
                                <select id="ticket_time" required>
                                    <option selected disabled>Select Trip Time</option>
                                    <option value="7:30 AM">7:30 AM</option>
                                    <option value="10:30 AM">10:30 AM</option>
                                    <option value="1:30 PM">1:30 PM</option>
                                    <option value="5:00 PM">5:00 PM</option>
                                </select>
                            </div>
                            <div class="ticket-field">
                                <label>Assigned Boat</label>
                                <select id="ticket_boat">
                                    <option selected disabled>Select boat</option>
                                    @forelse($boats ?? [] as $boat)
                                        @if(($boat->status ?? 'Active') === 'Active')
                                            <option value="{{ $boat->id }}" data-boat-name="{{ $boat->name }}">
                                                {{ $boat->name }} (Capacity: {{ $boat->passenger_capacity ?? 0 }})
                                            </option>
                                        @endif
                                    @empty
                                        <option disabled>No active boats available</option>
                                    @endforelse
                                </select>
                            </div>
                            <div class="ticket-field full-width">
                                <label>Seat Numbers (based on selected trip &amp; boat)</label>
                                <input
                                    type="text"
                                    id="ticket_seat_numbers_display"
                                    placeholder="Select seats from the seat map below"
                                    readonly
                                >
                                <input type="hidden" id="ticket_trip_schedule_id">
                                <input type="hidden" id="ticket_selected_seats">
                                <small style="color: var(--text-secondary);">
                                    Seat options come from the available seat map of the chosen boat and scheduled trip.
                                </small>
                            </div>
                            <div class="ticket-field full-width">
                                <label>Seat Map</label>
                                <div id="ticket_seat_map_container" class="ticket-seat-map">
                                    <div style="font-size: 12px; color: var(--text-secondary);">
                                        Select Route, Trip Date, Trip Time, and Boat to load the seat map.
                                    </div>
                                </div>
                            </div>
                            <div class="ticket-field full-width">
                                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Select Passenger Seats &amp; Types <span style="color: red;">*</span></label>
                                <div class="passenger-category-box" style="display: flex; flex-direction: column; gap: 10px; background: var(--bg-tertiary); padding: 14px; border-radius: 12px; border: 1px solid var(--border-color);">
                                    <!-- Regular Row -->
                                    <div class="category-row" style="display: flex; align-items: center; justify-content: space-between; background: var(--card-bg); padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border-color);">
                                        <div>
                                            <strong style="font-size: 13px; color: var(--text-primary);">Regular (Adult: ≥12 years)</strong>
                                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;" id="unit_price_regular">₱0.00 / passenger</div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <button type="button" class="counter-btn" id="btn_dec_regular">-</button>
                                            <span id="count_regular" class="counter-count-num">0</span>
                                            <button type="button" class="counter-btn" id="btn_inc_regular">+</button>
                                        </div>
                                    </div>
                                    <!-- Student Row -->
                                    <div class="category-row" style="display: flex; align-items: center; justify-content: space-between; background: var(--card-bg); padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border-color);">
                                        <div>
                                            <strong style="font-size: 13px; color: var(--text-primary);">Student</strong>
                                            <span style="font-size: 10px; background: rgba(78, 205, 196, 0.15); color: #4ecdc4; padding: 2px 7px; border-radius: 4px; font-weight: 700; margin-left: 6px;">Discounted</span>
                                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;" id="unit_price_student">₱0.00 / passenger</div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <button type="button" class="counter-btn" id="btn_dec_student">-</button>
                                            <span id="count_student" class="counter-count-num">0</span>
                                            <button type="button" class="counter-btn" id="btn_inc_student">+</button>
                                        </div>
                                    </div>
                                    <!-- Senior Citizen / PWD Row -->
                                    <div class="category-row" style="display: flex; align-items: center; justify-content: space-between; background: var(--card-bg); padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border-color);">
                                        <div>
                                            <strong style="font-size: 13px; color: var(--text-primary);">Senior Citizen / PWD</strong>
                                            <span style="font-size: 10px; background: rgba(78, 205, 196, 0.15); color: #4ecdc4; padding: 2px 7px; border-radius: 4px; font-weight: 700; margin-left: 6px;">20% Off</span>
                                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;" id="unit_price_senior">₱0.00 / passenger</div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <button type="button" class="counter-btn" id="btn_dec_senior">-</button>
                                            <span id="count_senior" class="counter-count-num">0</span>
                                            <button type="button" class="counter-btn" id="btn_inc_senior">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Dynamic Per-Passenger Details (Matching Mobile App) --}}
                            <div class="ticket-field full-width" id="passenger_details_section" style="display: none;">
                                <label style="font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                                    <span id="passenger_details_title">Passenger Details (0 Total)</span>
                                    <span style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Enter legal name as shown on ID</span>
                                </label>
                                <div id="passenger_accordion_list" class="passenger-accordion-container">
                                    {{-- Injected dynamically by ticket-book.js --}}
                                </div>
                            </div>
                            <div class="ticket-field">
                                <label>Payment Method</label>
                                <select id="ticket_payment">
                                    <option value="Cash (Walk‑in)">Cash (Walk‑in)</option>
                                    <option value="GCash / E‑Wallet">GCash / E‑Wallet</option>
                                </select>
                            </div>
                            <div class="ticket-field">
                                <label>Amount Collected</label>
                                <input type="number" id="ticket_amount" min="0" step="0.01" placeholder="0.00" readonly>
                                <small style="color: var(--text-secondary); font-size: 12px; display: block; margin-top: 4px;">
                                    Amount is calculated automatically based on route, passenger type, and number of seats.
                                </small>
                            </div>
                            <div class="ticket-field full-width">
                                <label>Notes</label>
                                <textarea id="ticket_notes" rows="3" placeholder="Special requests, remarks, or override reason"></textarea>
                            </div>
                        </div>
                        <div class="ticket-form-actions">
                            <button type="button" class="ticket-btn-secondary" id="ticket_clear_btn">Clear</button>
                            <button type="submit" class="ticket-btn-primary" id="ticket_book_btn">Book Ticket</button>
                        </div>
                    </form>
                </section>

                {{-- Refund Requests --}}
                <section class="ticket-section full-width-section">
                    <div class="ticket-section-header">
                        <h2>Refund Requests</h2>
                        <p>Handle cancellation and refund requests from passengers.</p>
                    </div>

                    <div class="table-responsive" style="width: 100%; overflow-x: auto;">
                        <table class="ticket-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Passenger</th>
                                    <th>Route</th>
                                    <th>Trip Time</th>
                                    <th>Amount</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="8" style="padding: 14px 10px; text-align: center; color: var(--text-secondary); font-size: 13px;">
                                        Refund requests created from Override / Counter Booking and the future Mobile Passenger Booking API
                                        will appear here, where admins can approve, reject, or review details.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    {{-- Booking status change confirmation modal --}}
    <div id="ticket_confirm_modal" class="ticket-confirm-modal" aria-hidden="true">
        <div class="ticket-confirm-card" role="dialog" aria-modal="true" aria-labelledby="ticket_confirm_title">
            <div class="ticket-confirm-header">
                <h2 id="ticket_confirm_title">Confirm status change</h2>
            </div>
            <div class="ticket-confirm-body">
                <p data-ticket-confirm-message></p>
            </div>
            <div class="ticket-confirm-actions">
                <button type="button" class="ticket-btn-secondary" data-ticket-confirm-cancel>Cancel</button>
                <button type="button" class="ticket-btn-primary" data-ticket-confirm-ok>OK</button>
            </div>
        </div>
    </div>

    {{-- Printable Boarding Pass & Receipt Modal --}}
    <div id="ticket_print_modal" class="ticket-print-modal" aria-hidden="true">
        <div class="ticket-print-modal-backdrop" data-print-modal-close></div>
        <div class="ticket-print-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ticketPrintModalTitle">
            <div class="ticket-print-modal-header">
                <div class="modal-header-left">
                    <span class="modal-badge">TICKETING &amp; BOARDING PASS</span>
                    <h3 id="ticketPrintModalTitle">Passenger E-Ticket &amp; Receipt</h3>
                </div>
                <div class="modal-header-controls">
                    <div class="print-format-toggle" role="group" aria-label="Print Format">
                        <button type="button" class="btn-format-toggle active" data-format="thermal" title="Thermal POS Receipt (80mm)">
                            🧾 Thermal (80mm)
                        </button>
                        <button type="button" class="btn-format-toggle" data-format="standard" title="Standard A4 / Letter">
                            📄 Standard A4
                        </button>
                    </div>
                    <button type="button" class="btn-modal-print" id="btnTriggerPrint">
                        🖨️ Print Receipt
                    </button>
                    <button type="button" class="btn-modal-close" data-print-modal-close aria-label="Close modal">
                        ✕
                    </button>
                </div>
            </div>
            <div class="ticket-print-modal-body">
                <div id="ticketPrintSheet" class="ticket-print-sheet format-thermal">
                    {{-- Dynamically generated by ticket-book.js --}}
                </div>
            </div>
        </div>
    </div>

    {{-- Passenger Details Modal Card (Dark-themed, matching application design system) --}}
    <div id="passenger_details_modal" class="passenger-modal-backdrop" style="display: none;" aria-hidden="true">
        <div class="passenger-details-modal" role="dialog" aria-modal="true" aria-labelledby="passengerModalTitle">
            <div class="passenger-modal-header">
                <div class="passenger-modal-title-wrap">
                    <h2 id="passengerModalTitle">Passenger Details</h2>
                    <span class="passenger-booking-pill" id="modalBookingRef">SP-0000</span>
                </div>
                <button type="button" class="passenger-modal-close" id="closePassengerModal" aria-label="Close modal">×</button>
            </div>

            <div class="passenger-modal-meta">
                <div class="modal-meta-item">
                    <span class="modal-meta-label">Route</span>
                    <span class="modal-meta-value" id="modalRouteText">-</span>
                </div>
                <div class="modal-meta-item">
                    <span class="modal-meta-label">Trip Schedule</span>
                    <span class="modal-meta-value" id="modalScheduleText">-</span>
                </div>
                <div class="modal-meta-item">
                    <span class="modal-meta-label">Vessel</span>
                    <span class="modal-meta-value" id="modalVesselText">-</span>
                </div>
            </div>

            <div class="passenger-modal-body">
                <div class="passenger-list-container" id="passengerDetailsList">
                    {{-- Dynamically populated via ticket-book.js --}}
                </div>
            </div>

            <div class="passenger-modal-actions">
                <div class="passenger-modal-summary">
                    <span class="summary-count" id="modalPassengerCount">0 Passengers</span>
                    <span class="summary-sep">•</span>
                    <span class="summary-total" id="modalBookingAmount">₱0.00</span>
                </div>
                <div class="passenger-modal-btn-group">
                    <button type="button" class="btn-modal-approve-action" id="modalApproveBtn" style="display: none; background: #059669; color: #ffffff; border: none; font-weight: 700; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px;" title="Approve Student/Senior/PWD ID and generate boarding pass">
                        ✓ Approve Discount &amp; Issue Ticket
                    </button>
                    <button type="button" class="btn-modal-reject-action" id="modalRejectBtn" style="display: none; background: #dc2626; color: #ffffff; border: none; font-weight: 700; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px;" title="Reject ID verification and refund payment via PayMongo">
                        ✕ Reject &amp; Refund Payment
                    </button>
                    <button type="button" class="passenger-modal-cancel" id="cancelPassengerModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Discount ID Photo Lightbox Modal --}}
    <div id="id_photo_lightbox_modal" class="id-lightbox-backdrop" style="display: none;" aria-hidden="true">
        <div class="id-lightbox-content" role="dialog" aria-modal="true">
            <div class="id-lightbox-header">
                <div class="id-lightbox-title-wrap">
                    <h3 id="idLightboxPassengerName">Discount ID Verification</h3>
                    <span class="id-lightbox-cat-badge" id="idLightboxCategory">Discount Verification</span>
                </div>
                <button type="button" class="id-lightbox-close" id="closeIdLightbox" aria-label="Close ID preview">×</button>
            </div>
            <div class="id-lightbox-body">
                <img id="idLightboxImage" src="" alt="Discount ID Document" />
            </div>
            <div class="id-lightbox-footer">
                <a id="idLightboxFullLink" href="#" target="_blank" rel="noopener noreferrer" class="id-lightbox-ext-link">
                    <span>↗ Open Full Image</span>
                </a>
                <button type="button" class="id-lightbox-btn-close" id="cancelIdLightbox">Close Preview</button>
            </div>
        </div>
    </div>

    {{-- Rejection & Automated Refund Modal --}}
    <div id="rejection_refund_modal" class="id-lightbox-backdrop" style="display: none; z-index: 10000;" aria-hidden="true">
        <div class="id-lightbox-content" style="max-width: 480px; border-radius: 16px; overflow: hidden; background: #ffffff;" role="dialog" aria-modal="true">
            <div class="id-lightbox-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca; padding: 16px 20px;">
                <div class="id-lightbox-title-wrap">
                    <h3 style="color: #dc2626; margin: 0; font-size: 16px; font-weight: 700;">Reject &amp; Refund Booking</h3>
                    <span class="id-lightbox-cat-badge" style="background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.3);">Automated PayMongo Refund</span>
                </div>
                <button type="button" class="id-lightbox-close" id="closeRejectionModal" aria-label="Close modal">×</button>
            </div>
            <form id="rejectionRefundForm" style="padding: 20px 24px; margin: 0;">
                <input type="hidden" id="rejectBookingId" value="">
                <p style="margin: 0 0 14px 0; font-size: 13.5px; color: #475569; line-height: 1.5;">
                    Rejecting booking <strong id="rejectBookingRefDisplay" style="color: #0f172a;">#SP-0000</strong> will <strong style="color: #dc2626;">automatically refund <span id="rejectRefundAmountDisplay">₱0.00</span></strong> directly back to the passenger's GCash / E-Wallet via PayMongo and release the reserved seat(s) back to open capacity.
                </p>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 6px; color: #1e293b;">
                        Reason for Rejection <span style="color: #ef4444;">*</span>
                    </label>
                    <select id="rejectPresetReason" style="width: 100%; padding: 9px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-size: 13px; margin-bottom: 10px; color: #0f172a;">
                        <option value="Uploaded ID photo is unreadable or blurry.">Uploaded ID photo is unreadable or blurry</option>
                        <option value="Expired Student / Senior Citizen / PWD ID card.">Expired Student / Senior Citizen / PWD ID card</option>
                        <option value="Passenger name does not match the name on the uploaded ID.">Passenger name does not match the name on the ID</option>
                        <option value="Invalid or non-accredited institution ID presented.">Invalid or non-accredited institution ID presented</option>
                        <option value="custom">Other (Specify custom reason below)...</option>
                    </select>
                    <textarea id="rejectCustomReason" rows="3" maxlength="500" placeholder="Specify explanation for rejection and refund..." style="width: 100%; padding: 9px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-size: 13px; box-sizing: border-box; color: #0f172a; resize: vertical; display: none;"></textarea>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                    <button type="button" id="cancelRejectionModal" style="padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #f8fafc; color: #475569; font-weight: 600; cursor: pointer; font-size: 13px;">Cancel</button>
                    <button type="submit" id="confirmRejectRefundBtn" style="padding: 8px 18px; border-radius: 8px; border: none; background: #dc2626; color: #ffffff; font-weight: 700; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                        <span>Confirm Rejection &amp; Refund</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Embed fare data for auto-calculation --}}
    <script>
        // Fetch fare data from API or use default structure
        window.fareData = {
            'Surigao → San Jose(Dinagat)': { regular: 0, student: 0, senior: 0 },
            'San Jose(Dinagat) → Surigao': { regular: 0, student: 0, senior: 0 }
        };

        // Load fares from API
        fetch('{{ route("admin.api.fares") }}')
            .then(response => response.json())
            .then(data => {
                if (data.fares) {
                    window.fareData = data.fares;
                    if (typeof calculateTicketAmount === 'function') {
                        calculateTicketAmount();
                    }
                }
            })
            .catch(error => {
                console.warn('Could not load fare data from API:', error);
            });
    </script>
@endsection
