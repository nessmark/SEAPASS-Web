@extends('layouts.admin')

@section('title', 'Trip & Schedule Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/trip-schedules.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/trip-schedules.js') }}"></script>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="trip-header">
                <div>
                    <div class="card-title">Trip & Schedule Management</div>
                    <div class="card-subtitle">Manage schedules, monitor trips, and view seat maps</div>
                </div>
                <button class="trip-button" type="button" id="openScheduleModal">Add Schedule</button>
            </div>

            @if (session('success'))
                <div class="alert">{{ session('success') }}</div>
            @endif

            @if (session('error'))
                <div class="alert error">{{ session('error') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert error">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- Add/Edit Schedule Modal --}}
            <div class="trip-modal-backdrop" id="scheduleModalBackdrop" style="display: none;">
                <div class="trip-modal" role="dialog" aria-modal="true">
                    <div class="trip-modal-header">
                        <h2 id="scheduleModalTitle">Add Trip Schedule</h2>
                        <button type="button" class="trip-modal-close" id="closeScheduleModal" aria-label="Close">×</button>
                    </div>
                    <form method="POST" id="scheduleForm" class="trip-modal-form">
                        @csrf
                        <div id="scheduleFormMethod" style="display: none;"></div>
                        <div class="trip-modal-grid">
                            <div class="trip-field">
                                <label>Boat / Fleet <span style="color: red;">*</span></label>
                                <select name="boat_id" id="schedule_boat_id" required>
                                    <option value="">Select Boat</option>
                                    @foreach($boats as $boat)
                                        <option value="{{ $boat->id }}" data-capacity="{{ $boat->passenger_capacity ?? 0 }}">
                                            {{ $boat->name }} (Capacity: {{ $boat->passenger_capacity ?? 0 }} seats)
                                        </option>
                                    @endforeach
                                </select>
                                <small id="boat_capacity_info" style="color: #0284c7; display: block; margin-top: 4px; font-weight: 600; font-size: 13px;">
                                    Fleet Seat Capacity: —
                                </small>
                            </div>
                            <div class="trip-field">
                                <label>Route <span style="color: red;">*</span></label>
                                <select name="route" id="schedule_route" required>
                                    <option value="Surigao → San Jose(Dinagat)">Surigao → San Jose(Dinagat)</option>
                                    <option value="San Jose(Dinagat) → Surigao">San Jose(Dinagat) → Surigao</option>
                                </select>
                            </div>
                            <div class="trip-field">
                                <label>Departure Date <span style="color: red;">*</span></label>
                                <input type="date" name="departure_date" id="schedule_departure_date" required>
                            </div>
                            <div class="trip-field">
                                <label>Departure Time <span style="color: red;">*</span></label>
                                <select name="departure_time_slot" id="schedule_departure_time_slot" required>
                                    <option value="07:30">7:30 AM</option>
                                    <option value="10:30">10:30 AM</option>
                                    <option value="13:30">1:30 PM</option>
                                    <option value="17:00">5:00 PM</option>
                                </select>
                            </div>
                            <div class="trip-field">
                                <label>Available Seats <span style="color: red;">*</span></label>
                                <input type="number" name="available_seats" id="schedule_available_seats" required min="0" placeholder="0">
                                <small id="available_seats_help" style="color: var(--text-secondary); display: block; margin-top: 4px;">
                                    Auto-set to fleet capacity (<span id="capacity_val" style="font-weight: bold; color: #111;">—</span> seats)
                                </small>
                            </div>
                            <div class="trip-field full-width">
                                <label>Notes</label>
                                <textarea name="notes" id="schedule_notes" rows="3" maxlength="500" placeholder="Additional notes..."></textarea>
                            </div>
                        </div>
                        <div class="trip-modal-actions">
                            <button type="button" class="trip-modal-cancel" id="cancelScheduleModal">Cancel</button>
                            <button type="submit" class="trip-modal-save">Save Schedule</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Tabs --}}
            <div class="trip-tabs"
                 data-manila-now="{{ $manilaNow }}"
                 data-live-fingerprint="{{ $liveFingerprint }}">
                <button class="trip-tab active" data-tab="scheduled">Scheduled Trips</button>
                <button class="trip-tab" data-tab="active">Active Trips</button>
                <button class="trip-tab" data-tab="completed">Recent Completed</button>
            </div>

            {{-- Scheduled Trips Tab --}}
            <div class="trip-tab-content active" id="tab-scheduled">
                <div class="trip-grid" id="scheduled-trip-grid"
                     data-live-grid="scheduled">
                    @include('admin.partials.trip_tab_grid', [
                        'trips' => $scheduledTrips,
                        'variant' => 'scheduled',
                        'emptyMessage' => 'No scheduled trips. Add a new schedule to get started.',
                    ])
                </div>
            </div>

            {{-- Active Trips Tab --}}
            <div class="trip-tab-content" id="tab-active">
                <div class="trip-grid" id="active-trip-grid"
                     data-live-grid="active">
                    @include('admin.partials.trip_tab_grid', [
                        'trips' => $activeTrips,
                        'variant' => 'active',
                        'emptyMessage' => 'No active trips at the moment.',
                    ])
                </div>
            </div>

            {{-- Completed Trips Tab --}}
            <div class="trip-tab-content" id="tab-completed">
                <div class="trip-grid" id="completed-trip-grid"
                     data-live-grid="completed">
                    @include('admin.partials.trip_tab_grid', [
                        'trips' => $completedTrips,
                        'variant' => 'completed',
                        'emptyMessage' => 'No completed trips to display.',
                    ])
                </div>
            </div>
        </div>
    </div>

    {{-- Seat Map Modal --}}
    <div class="trip-modal-backdrop" id="seatMapModalBackdrop" style="display: none;">
        <div class="trip-modal seat-map-modal" role="dialog" aria-modal="true">
            <div class="trip-modal-header">
                <h2 id="seatMapModalTitle">Seat Map</h2>
                <button type="button" class="trip-modal-close" id="closeSeatMapModal" aria-label="Close">×</button>
            </div>
            <div class="seat-map-container" id="seatMapContainer">
                <!-- Seat map will be loaded here -->
            </div>
        </div>
    </div>
@endsection
