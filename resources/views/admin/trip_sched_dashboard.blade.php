@extends('layouts.admin')

@section('title', 'Trip & Schedule Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/trip-schedules.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/trip-schedules.js') }}?v={{ file_exists(public_path('js/trip-schedules.js')) ? filemtime(public_path('js/trip-schedules.js')) : time() }}"></script>
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
                    <form method="POST" action="{{ route('admin.trip-schedules.store') }}" id="scheduleForm" class="trip-modal-form">
                        @csrf
                        <input type="hidden" name="template_id" id="schedule_template_id">
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
                            {{-- Recurrence Pattern Selector --}}
                            <div class="trip-field full-width">
                                <label>Schedule Type / Recurrence</label>
                                <select name="recurrence_type" id="schedule_recurrence_type">
                                    <option value="single">Single Date (One-Time Schedule)</option>
                                    <option value="daily">Daily (Every Day)</option>
                                    <option value="weekly">Weekly on Specific Days</option>
                                </select>
                                <small style="color: var(--text-secondary); display: block; margin-top: 4px;">
                                    Choose "Daily" or "Weekly" to automatically generate and repeat trips into the future.
                                </small>
                            </div>

                            {{-- Days of Week Selector (for Weekly) --}}
                            <div class="trip-field full-width" id="daysOfWeekContainer" style="display: none;">
                                <label>Repeat On Days <span style="color: red;">*</span></label>
                                <div class="day-pills-selector">
                                    @foreach(['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'] as $short => $full)
                                        <label class="day-pill-label">
                                            <input type="checkbox" name="days_of_week[]" value="{{ $short }}" class="day-pill-input">
                                            <span class="day-pill-btn" title="{{ $full }}">{{ $short }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Departure Date for Single Date --}}
                            <div class="trip-field" id="singleDateContainer">
                                <label>Departure Date <span style="color: red;">*</span></label>
                                <input type="date" name="departure_date" id="schedule_departure_date" required>
                            </div>

                            {{-- Effective Range for Recurring (Optional Until) --}}
                            <div class="trip-field" id="recurringDateContainer" style="display: none;">
                                <label>Repeat Until (Optional)</label>
                                <input type="date" name="effective_until" id="schedule_effective_until" placeholder="Leave blank for indefinite">
                                <small style="color: var(--text-secondary); display: block; margin-top: 4px;">
                                    Leave blank to repeat indefinitely until toggled off.
                                </small>
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
                                <label>Estimated Arrival Time (Optional)</label>
                                <input type="time" name="arrival_time" id="schedule_arrival_time">
                                <small style="color: var(--text-secondary); display: block; margin-top: 4px;">
                                    Auto-set to +2 hours if left blank.
                                </small>
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
                <button class="trip-tab" data-tab="recurring">
                    Recurring Rules
                    @if(isset($scheduleTemplates) && $scheduleTemplates->isNotEmpty())
                        <span class="tab-badge">{{ $scheduleTemplates->count() }}</span>
                    @endif
                </button>
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

            {{-- Recurring Schedule Rules Tab --}}
            <div class="trip-tab-content" id="tab-recurring">
                <div class="recurring-header-bar">
                    <div class="recurring-header-text">
                        <h3>Recurring Trip Schedule Rules</h3>
                        <p>Automated rules that continuously spawn bookable ferry trips based on set days and times.</p>
                    </div>
                </div>

                @if(isset($scheduleTemplates) && $scheduleTemplates->isNotEmpty())
                    <div class="recurring-table-container">
                        <table class="recurring-table">
                            <thead>
                                <tr>
                                    <th>Fleet / Vessel</th>
                                    <th>Route</th>
                                    <th>Times</th>
                                    <th>Recurrence Pattern</th>
                                    <th>Seats</th>
                                    <th>Effective Duration</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($scheduleTemplates as $template)
                                    <tr>
                                        <td>
                                            <strong>{{ $template->boat?->name ?? 'Vessel' }}</strong>
                                            <div style="font-size: 12px; color: var(--text-secondary);">Capacity: {{ $template->boat?->passenger_capacity ?? 0 }}</div>
                                        </td>
                                        <td>{{ $template->route }}</td>
                                        <td>
                                            <div><strong>Dep:</strong> {{ $template->formatted_departure_time }}</div>
                                            <div style="font-size: 12px; color: var(--text-secondary);"><strong>Arr:</strong> {{ $template->formatted_arrival_time }}</div>
                                        </td>
                                        <td>
                                            <span class="badge-recurrence">{{ $template->formatted_recurrence }}</span>
                                        </td>
                                        <td>{{ $template->available_seats ?? ($template->boat?->passenger_capacity ?? 0) }} seats</td>
                                        <td>
                                            @if($template->effective_until)
                                                Until {{ $template->effective_until->format('M d, Y') }}
                                            @else
                                                <span style="color: #0284c7; font-weight: 500;">Runs Indefinitely</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($template->is_active)
                                                <span class="badge-active-status active">Active</span>
                                            @else
                                                <span class="badge-active-status inactive">Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="action-btn-group">
                                                {{-- Edit Rule --}}
                                                <button type="button" class="action-mini-btn" style="background: rgba(14, 165, 233, 0.1); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.3);"
                                                    onclick="openEditRecurringModal({{ json_encode([
                                                        'id' => $template->id,
                                                        'boat_id' => $template->boat_id,
                                                        'route' => $template->route,
                                                        'departure_time' => $template->departure_time,
                                                        'arrival_time' => $template->arrival_time,
                                                        'recurrence_type' => $template->recurrence_type,
                                                        'days_of_week' => $template->days_of_week,
                                                        'available_seats' => $template->available_seats,
                                                        'effective_until' => $template->effective_until ? $template->effective_until->format('Y-m-d') : null,
                                                        'notes' => $template->notes,
                                                    ]) }})" title="Edit recurring rule">
                                                    ✏️ Edit
                                                </button>

                                                {{-- Generate Now --}}
                                                <form method="POST" action="{{ route('admin.recurring-schedules.generate', $template) }}" style="display: inline;">
                                                    @csrf
                                                    <button type="submit" class="action-mini-btn btn-gen-now" title="Spawn trips immediately for next 3 months (90 days)">
                                                        ⚡ Generate
                                                    </button>
                                                </form>

                                                {{-- Toggle Active/Inactive --}}
                                                <form method="POST" action="{{ route('admin.recurring-schedules.toggle', $template) }}" style="display: inline;">
                                                    @csrf
                                                    @method('PATCH')
                                                    @if($template->is_active)
                                                        <button type="submit" class="action-mini-btn btn-toggle-deactivate" title="Pause this rule">
                                                            Pause
                                                        </button>
                                                    @else
                                                        <button type="submit" class="action-mini-btn btn-toggle-activate" title="Resume this rule">
                                                            Resume
                                                        </button>
                                                    @endif
                                                </form>

                                                {{-- Delete --}}
                                                <form method="POST" action="{{ route('admin.recurring-schedules.destroy', $template) }}" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this recurring rule? Unbooked future trips will be removed, and any booked passengers will be automatically rescheduled to the nearest sailing and notified.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="action-mini-btn btn-delete-rule" title="Delete rule">
                                                        🗑️
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="empty-state" style="text-align: center; padding: 48px 20px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px;">
                        <div style="font-size: 38px; margin-bottom: 12px;">⏰</div>
                        <h3 style="margin: 0 0 6px 0; color: var(--text-primary);">No Recurring Schedule Rules Defined</h3>
                        <p style="margin: 0 0 18px 0; font-size: 14px; color: var(--text-secondary);">
                            Set up daily or weekly repeating ferry schedules to automatically spawn bookable trips for passengers.
                        </p>
                        <button class="trip-button" type="button" onclick="document.getElementById('openScheduleModal').click()">
                            + Add First Recurring Rule
                        </button>
                    </div>
                @endif
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
