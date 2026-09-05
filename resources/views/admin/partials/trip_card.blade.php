@php
    use App\Support\ManilaClock;

    $departure = $schedule->departure_time->copy()->timezone(ManilaClock::TIMEZONE);
    $arrival = $schedule->arrival_time
        ? $schedule->arrival_time->copy()->timezone(ManilaClock::TIMEZONE)
        : $departure->copy()->addHours(2);
    $variant = $variant ?? 'scheduled';
    $cardClass = 'trip-card';
    if ($variant === 'active') {
        $cardClass .= ' trip-card-active';
    } elseif ($variant === 'completed') {
        $cardClass .= ' trip-card-completed';
    }
@endphp
<div class="{{ $cardClass }}"
     data-schedule-id="{{ $schedule->id }}"
     data-departure="{{ ManilaClock::toIso8601($departure) }}"
     data-arrival="{{ ManilaClock::toIso8601($arrival) }}"
     data-status="{{ $schedule->status }}">
    <div class="trip-card-header">
        <div class="trip-card-title">
            <span class="trip-route">{{ $schedule->route }}</span>
            <span class="trip-boat">{{ $schedule->boat->name }}</span>
            @if($variant === 'active')
                <span class="trip-status-badge status-departed">Departed</span>
            @elseif($variant === 'completed')
                <span class="trip-status-badge status-arrived">Arrived</span>
            @endif
        </div>
        @if($variant === 'scheduled')
            <div class="trip-card-actions">
                <button class="trip-action-btn" type="button"
                        onclick="openEditScheduleModal({{ $schedule->id }}, {{ $schedule->boat_id }}, {{ json_encode($schedule->route) }}, {{ json_encode($departure->format('Y-m-d')) }}, {{ json_encode($departure->format('H:i')) }}, {{ (int) $schedule->available_seats }}, {{ json_encode($schedule->notes ?? '') }})"
                        title="Edit">
                    ✏️
                </button>
                <button class="trip-action-btn" type="button" onclick="viewSeatMap({{ $schedule->id }})" title="View Seat Map">
                    🪑
                </button>
                <form method="POST" action="{{ route('admin.trip-schedules.destroy', $schedule) }}" style="display: inline;">
                    @csrf
                    @method('DELETE')
                    <button class="trip-action-btn" type="submit" title="Delete" onclick="return confirm('Are you sure you want to delete this schedule?');">
                        🗑️
                    </button>
                </form>
            </div>
        @endif
    </div>
    <div class="trip-card-body">
        <div class="trip-info-row">
            <span class="trip-label">Departure:</span>
            <span class="trip-value">{{ $departure->format('M d, Y h:i A') }}</span>
        </div>
        @if($variant !== 'active')
            <div class="trip-info-row">
                <span class="trip-label">Arrival:</span>
                <span class="trip-value">{{ $arrival->format('M d, Y h:i A') }}</span>
            </div>
        @endif
        @if($variant === 'active')
            <div class="trip-info-row">
                <span class="trip-label">Status:</span>
                <form method="POST" action="{{ route('admin.trip-schedules.updateStatus', $schedule) }}" class="trip-status-form">
                    @csrf
                    @method('PATCH')
                    <select name="status" onchange="this.form.submit()" class="trip-status-select">
                        <option value="Departed" {{ $schedule->status === 'Departed' ? 'selected' : '' }}>Departed</option>
                        <option value="Arrived" {{ $schedule->status === 'Arrived' ? 'selected' : '' }}>Arrived</option>
                    </select>
                </form>
            </div>
        @endif
        <div class="trip-info-row">
            <span class="trip-label">Available Seats:</span>
            <span class="trip-value">{{ $schedule->available_seats }} / {{ $schedule->boat->passenger_capacity ?? 0 }}</span>
        </div>
        @if($variant === 'scheduled' && $schedule->notes)
            <div class="trip-info-row">
                <span class="trip-label">Notes:</span>
                <span class="trip-value">{{ $schedule->notes }}</span>
            </div>
        @endif
    </div>
</div>
