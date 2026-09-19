@extends('layouts.admin')

@section('title', 'Reports & Audit')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/reports-audit.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ticket-book.css') }}?v={{ time() }}">
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="reports-container">
                <div class="reports-header">
                    <div class="card-title">Reports &amp; Audit</div>
                    <div class="card-subtitle">
                        View real-time sales reports, passenger boat capacity statistics, and audit logs for all system activities.
                    </div>
                </div>

                {{-- Sales Report Section --}}
                <section class="reports-section">
                    <div class="reports-section-header">
                        <div>
                            <h2 class="reports-section-title">Sales Report</h2>
                            <p class="reports-section-desc">Filter by date, boat, or route to view detailed ticket sales and revenue.</p>
                        </div>
                        <div class="section-actions">
                            <a href="{{ route('admin.reports-audit.export-sales', request()->query()) }}" class="btn btn-export" title="Download filtered sales transactions as CSV">
                                📥 Export CSV
                            </a>
                            <button type="button" class="btn btn-print" onclick="window.print()" title="Print this sales report">
                                🖨️ Print
                            </button>
                        </div>
                    </div>

                    <form method="GET" action="{{ route('admin.reports-audit') }}" class="reports-filters">
                        <div class="filter-group">
                            <label for="filter_date">Date</label>
                            <input 
                                type="date" 
                                id="filter_date" 
                                name="filter_date" 
                                value="{{ $filterDate ?? '' }}"
                            >
                        </div>
                        <div class="filter-group">
                            <label for="filter_boat">Boat</label>
                            <select id="filter_boat" name="filter_boat">
                                <option value="">All Boats</option>
                                @foreach($boats ?? [] as $boat)
                                    <option value="{{ $boat->id }}" {{ $filterBoat == $boat->id ? 'selected' : '' }}>
                                        {{ $boat->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filter_route">Route</label>
                            <select id="filter_route" name="filter_route">
                                <option value="">All Routes</option>
                                @foreach($routes ?? [] as $route)
                                    <option value="{{ $route }}" {{ $filterRoute == $route ? 'selected' : '' }}>
                                        {{ $route }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <a href="{{ route('admin.reports-audit') }}" class="btn btn-secondary">Clear</a>
                        </div>
                    </form>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Sales Revenue</div>
                            <div class="stat-value" style="color: #059669;">₱{{ number_format($totalSales ?? 0, 2) }}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Total Seats Sold</div>
                            <div class="stat-value">{{ $totalTickets ?? 0 }}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Regular Tickets</div>
                            <div class="stat-value" style="color: #0369A1;">{{ $regularTicketsCount ?? 0 }}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Student Tickets</div>
                            <div class="stat-value" style="color: #6B21A8;">{{ $studentTicketsCount ?? 0 }}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Senior / PWD Tickets</div>
                            <div class="stat-value" style="color: #92400E;">{{ $seniorTicketsCount ?? 0 }}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Confirmed Bookings</div>
                            <div class="stat-value" style="color: #0284C7;">{{ $confirmedBookingsCount ?? 0 }}</div>
                        </div>
                    </div>

                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Booking ID / Ref</th>
                                <th>Trip Date</th>
                                <th>Route</th>
                                <th>Boat</th>
                                <th>Passengers</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($bookings ?? [] as $booking)
                                @php
                                    $ticketList = $booking->passenger_tickets;
                                    $pCount = count($ticketList) ?: (is_array($booking->seat_numbers) ? count($booking->seat_numbers) : 1);
                                    $primaryName = $booking->passenger_name ?: 'Passenger';
                                    $vesselName = $booking->tripSchedule?->boat?->name ?? 'M/B SeaPass';
                                    $tripTimeFormatted = \Carbon\Carbon::hasFormat($booking->departure_time_slot, 'H:i') ? \Carbon\Carbon::createFromFormat('H:i', $booking->departure_time_slot)->format('g:i A') : ($booking->departure_time_slot ?: '7:30 AM');
                                    $tripDateFormatted = optional($booking->trip_date)->format('M d, Y') ?: ($booking->created_at ? $booking->created_at->format('M d, Y') : '-');
                                    $bookingRef = $booking->reference_number ?: ('SP-' . $booking->id);
                                @endphp
                                <tr>
                                    <td><strong>{{ $bookingRef }}</strong></td>
                                    <td>{{ $tripDateFormatted }}</td>
                                    <td>{{ $booking->route }}</td>
                                    <td>{{ $vesselName }}</td>
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
                                    <td><strong style="color: #059669;">₱{{ number_format((float) $booking->amount_collected, 2) }}</strong></td>
                                    <td>
                                        @if(in_array(strtolower($booking->status), ['confirmed', 'completed', 'approved']))
                                            <span class="status-badge status-confirmed">Confirmed</span>
                                        @elseif(in_array(strtolower($booking->status), ['pending', 'to_be_confirmed']))
                                            <span class="status-badge status-pending">Pending</span>
                                        @else
                                            <span class="status-badge status-cancelled">Cancelled</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        No sales transactions found matching your selected criteria.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>

                {{-- Passenger Count Report Section --}}
                <section class="reports-section">
                    <div class="reports-section-header">
                        <div>
                            <h2 class="reports-section-title">Passenger Count Report</h2>
                            <p class="reports-section-desc">Statistics for boat capacity limits and passenger safety compliance.</p>
                        </div>
                        <div class="section-actions">
                            <a href="{{ route('admin.reports-audit.export-capacity', request()->query()) }}" class="btn btn-export" title="Download boat capacity & safety report as CSV">
                                📥 Export CSV
                            </a>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Booked Passengers</div>
                            <div class="stat-value">{{ $totalPassengersCount ?? 0 }}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Average Occupancy Rate</div>
                            <div class="stat-value">{{ $avgOccupancy ?? 0 }}%</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Trips Over Capacity</div>
                            <div class="stat-value" style="color: {{ ($tripsOverCapacityCount ?? 0) > 0 ? '#DC2626' : 'var(--text-primary)' }};">
                                {{ $tripsOverCapacityCount ?? 0 }}
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Total Fleet Capacity</div>
                            <div class="stat-value">{{ $totalCapacitySum ?? 0 }}</div>
                        </div>
                    </div>

                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Departure Time</th>
                                <th>Route</th>
                                <th>Boat Name</th>
                                <th>Max Capacity</th>
                                <th>Seats Booked</th>
                                <th>Available Seats</th>
                                <th>Occupancy %</th>
                                <th>Safety Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($passengerReportData ?? [] as $item)
                                <tr>
                                    <td>{{ $item['trip_date'] }}</td>
                                    <td>{{ $item['route'] }}</td>
                                    <td><strong>{{ $item['boat'] }}</strong></td>
                                    <td>{{ $item['capacity'] }}</td>
                                    <td><strong style="color: #0284C7;">{{ $item['booked'] }}</strong></td>
                                    <td>{{ $item['available'] }}</td>
                                    <td><strong>{{ $item['occupancy'] }}%</strong></td>
                                    <td>
                                        @if($item['status'] === 'Over Capacity')
                                            <span class="status-badge status-cancelled">Over Capacity</span>
                                        @elseif($item['status'] === 'Near Capacity')
                                            <span class="status-badge status-pending">Near Capacity</span>
                                        @else
                                            <span class="status-badge status-confirmed">Normal</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        No trip capacity statistics recorded.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>

                {{-- Audit Log Section --}}
                <section class="reports-section">
                    <div class="reports-section-header">
                        <div>
                            <h2 class="reports-section-title">Audit Log</h2>
                            <p class="reports-section-desc">Track schedule modifications, ticket refunds, and admin activities.</p>
                        </div>
                    </div>

                    <div class="expandable-section">
                        <button class="expand-toggle" data-expand-target="audit-schedule">
                            <span class="icon">▶</span>
                            <span>Schedule & Fare Changes ({{ count($scheduleAuditLogs ?? []) }})</span>
                        </button>
                        <div class="expand-content" id="audit-schedule">
                            @forelse($scheduleAuditLogs ?? [] as $log)
                                <div class="audit-log-item">
                                    <div class="audit-log-header">
                                        <span class="audit-log-action">⚡ {{ $log->action }} (by {{ $log->user_name ?: 'System' }})</span>
                                        <span class="audit-log-time">{{ $log->created_at->diffForHumans() }}</span>
                                    </div>
                                    <div class="audit-log-details">
                                        {{ $log->details }}
                                    </div>
                                </div>
                            @empty
                                <div class="empty-state">No schedule modification records logged yet.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="expandable-section">
                        <button class="expand-toggle" data-expand-target="audit-refund">
                            <span class="icon">▶</span>
                            <span>Ticket Refunds & Cancellations ({{ count($refundAuditLogs ?? []) }})</span>
                        </button>
                        <div class="expand-content" id="audit-refund">
                            @forelse($refundAuditLogs ?? [] as $log)
                                <div class="audit-log-item">
                                    <div class="audit-log-header">
                                        <span class="audit-log-action">🎟️ {{ $log->action }} (by {{ $log->user_name ?: 'System' }})</span>
                                        <span class="audit-log-time">{{ $log->created_at->diffForHumans() }}</span>
                                    </div>
                                    <div class="audit-log-details">
                                        {{ $log->details }}
                                    </div>
                                </div>
                            @empty
                                <div class="empty-state">No ticket refund or cancellation records logged yet.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="expandable-section">
                        <button class="expand-toggle" data-expand-target="audit-all">
                            <span class="icon">▶</span>
                            <span>All System Activities ({{ count($allAuditLogs ?? []) }})</span>
                        </button>
                        <div class="expand-content" id="audit-all">
                            @forelse($allAuditLogs ?? [] as $log)
                                <div class="audit-log-item">
                                    <div class="audit-log-header">
                                        <span class="audit-log-action">📌 {{ $log->action }} — <small style="color: var(--primary-color);">{{ strtoupper($log->category) }}</small></span>
                                        <span class="audit-log-time">{{ $log->created_at->format('M d, Y h:i A') }}</span>
                                    </div>
                                    <div class="audit-log-details">
                                        <strong>User:</strong> {{ $log->user_name ?: 'System Admin' }} | <strong>Details:</strong> {{ $log->details }}
                                    </div>
                                </div>
                            @empty
                                <div class="empty-state">No system activities recorded.</div>
                            @endforelse
                        </div>
                    </div>
                </section>
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
@endsection

@push('scripts')
    <script src="{{ asset('js/ticket-book.js') }}?v={{ time() }}"></script>
    <script>
        // Expandable sections functionality
        document.querySelectorAll('.expand-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const targetId = this.getAttribute('data-expand-target');
                const content = document.getElementById(targetId);
                
                if (content) {
                    const isExpanded = content.classList.contains('show');
                    
                    // Close all other sections
                    document.querySelectorAll('.expand-content').forEach(section => {
                        section.classList.remove('show');
                    });
                    document.querySelectorAll('.expand-toggle').forEach(btn => {
                        btn.classList.remove('expanded');
                    });
                    
                    // Toggle current section
                    if (!isExpanded) {
                        content.classList.add('show');
                        this.classList.add('expanded');
                    }
                }
            });
        });
    </script>
@endpush
