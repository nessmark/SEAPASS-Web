@php
    use App\Support\ManilaClock;

    $departure = $schedule->departure_time->copy()->timezone(ManilaClock::TIMEZONE);
    $arrival = $schedule->arrival_time
        ? $schedule->arrival_time->copy()->timezone(ManilaClock::TIMEZONE)
        : $departure->copy()->addHours(2);
    $variant = $variant ?? 'scheduled';

    $capacity = (int) ($schedule->boat->passenger_capacity ?? 0);
    $available = (int) $schedule->available_seats;
    $booked = max(0, $capacity - $available);
    $loadPct = $capacity > 0 ? round($booked / $capacity * 100) : 0;

    $minutes = $departure->diffInMinutes($arrival);
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    $duration = $hours > 0 ? ($rest > 0 ? "{$hours}h {$rest}m" : "{$hours}h") : "{$minutes}m";

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
            <span class="trip-meta">
                <span class="trip-boat">{{ $schedule->boat->name }}</span>
                <span class="trip-date">{{ $departure->format('D, M j') }}</span>
            </span>
            @if($variant === 'active')
                <span class="trip-status-badge status-departed">Departed</span>
            @elseif($variant === 'completed')
                <span class="trip-status-badge status-arrived">Arrived</span>
            @endif
        </div>
    </div>

    {{-- The journey read as a journey. The time is the content, so it leads. --}}
    <div class="trip-rail">
        <div class="trip-rail-stop">
            <span class="trip-time">{{ $departure->format('g:i') }}<span class="trip-mer">{{ $departure->format('A') }}</span></span>
            <span class="trip-stop-label">Departs</span>
        </div>
        <div class="trip-rail-line" aria-hidden="true">
            <span class="trip-duration">{{ $duration }}</span>
        </div>
        <div class="trip-rail-stop trip-rail-stop-end">
            <span class="trip-time">{{ $arrival->format('g:i') }}<span class="trip-mer">{{ $arrival->format('A') }}</span></span>
            <span class="trip-stop-label">Arrives</span>
        </div>
    </div>

    {{-- "30 / 30" is a number you have to decode. A bar you just see. --}}
    <div class="trip-load" title="{{ $booked }} booked of {{ $capacity }} seats">
        <div class="trip-load-track">
            <div class="trip-load-fill @if($loadPct >= 100) is-full @elseif($loadPct >= 80) is-tight @endif" style="width: {{ $loadPct }}%"></div>
        </div>
        <span class="trip-load-text">
            @if($capacity === 0)
                No capacity set
            @elseif($available === 0)
                Full
            @else
                <strong>{{ $available }}</strong> of {{ $capacity }} seats left
            @endif
        </span>
    </div>

    @if($variant === 'active')
        <div class="trip-status-control">
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

    @if($variant === 'scheduled' && $schedule->notes)
        <p class="trip-note">{{ $schedule->notes }}</p>
    @endif

    @if($variant === 'scheduled')
        <div class="trip-card-actions">
            <button class="trip-action" type="button" onclick="viewSeatMap({{ $schedule->id }})">
                <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4v8h12V4"/><path d="M5 12h14"/><path d="M7 12v8M17 12v8"/></svg>
                Seats
            </button>
            <button class="trip-action" type="button"
                    onclick="openEditScheduleModal({{ $schedule->id }}, {{ $schedule->boat_id }}, {{ json_encode($schedule->route) }}, {{ json_encode($departure->format('Y-m-d')) }}, {{ json_encode($departure->format('H:i')) }}, {{ (int) $schedule->available_seats }}, {{ json_encode($schedule->notes ?? '') }})">
                <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M14.5 6.5l3 3"/></svg>
                Edit
            </button>
            <form method="POST" action="{{ route('admin.trip-schedules.destroy', $schedule) }}" class="trip-action-destroy">
                @csrf
                @method('DELETE')
                <button class="trip-action trip-action-danger" type="submit" aria-label="Delete this schedule"
                        onclick="return confirm('Delete the {{ $departure->format('g:i A') }} {{ $schedule->route }} schedule?');">
                    <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M10 11v6M14 11v6"/></svg>
                </button>
            </form>
        </div>
    @endif
</div>
