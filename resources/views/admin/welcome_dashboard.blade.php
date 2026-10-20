@extends('layouts.admin')
@section('title', 'Overview')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/overview.css') }}?v=20261020">
@endpush
@section('content')
    @php
        $portNow = \Carbon\Carbon::now('Asia/Manila');
        $reviewCount = $pendingReviewCount ?? 0;
        $paymentCount = $pendingPaymentCount ?? 0;
    @endphp
    <div class="workspace-heading">
        <div>
            <p class="page-eyebrow">{{ $portNow->format('l, j F Y') }}</p>
            <h1>Port overview</h1>
            <p class="page-description">The day's departures, passenger bookings and updates in one place.</p>
        </div>
        <a href="{{ route('admin.tickets.index') }}" class="btn-primary">Open booking counter <span aria-hidden="true">↗</span></a>
    </div>

    <div class="port-overview">
        <div class="overview-primary">
            <section class="operation-panel" id="needs-attention" aria-labelledby="attention-heading">
                <div class="operation-panel-heading"><h2 id="attention-heading">Things to do</h2><span class="section-note">Start here</span></div>
                <a class="operation-row" href="{{ route('admin.tickets.index') }}?tab=pending">
                    <span class="operation-symbol"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="5" width="16" height="15" rx="3"/><path d="M8 3v4M16 3v4M8 12h8M8 16h5"/></svg></span>
                    <span class="operation-copy"><strong>Review passenger bookings</strong><small>{{ $reviewCount > 0 ? $reviewCount.' paid booking'.($reviewCount === 1 ? '' : 's').' awaiting discount ID verification.' : 'Check pending bookings and verify student, senior or PWD IDs.' }}</small></span>
                    <span class="row-end">{{ $reviewCount > 0 ? $reviewCount.' to review' : 'Review' }} <span aria-hidden="true">›</span></span>
                </a>
                <a class="operation-row" href="{{ route('admin.trip-schedules') }}">
                    <span class="operation-symbol"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3 2"/></svg></span>
                    <span class="operation-copy"><strong>Prepare today's departures</strong><small>{{ $tripsTodayCount ?? 0 }} scheduled today. Check seat availability and download passenger manifests.</small></span>
                    <span class="row-end">Schedules <span aria-hidden="true">›</span></span>
                </a>
                <a class="operation-row" href="{{ route('admin.travel-advisory') }}">
                    <span class="operation-symbol"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 9v6h4l6 4V5L8 9H4zM18 8a6 6 0 0 1 0 8"/></svg></span>
                    <span class="operation-copy"><strong>Keep passengers informed</strong><small>Publish weather, route or sailing changes before passengers leave for the port.</small></span>
                    <span class="row-end">Advisories <span aria-hidden="true">›</span></span>
                </a>
            </section>

            <section class="operation-panel" id="upcoming-departures" aria-labelledby="departures-heading">
                <div class="operation-panel-heading"><div><h2 id="departures-heading">Upcoming departures</h2><p>Next sailings, in departure order</p></div><a class="text-action" href="{{ route('admin.trip-schedules') }}">All trips <span aria-hidden="true">›</span></a></div>
                @forelse($upcomingTrips ?? [] as $trip)
                    <div class="departure-row">
                        <div class="departure-time"><strong>{{ \Carbon\Carbon::parse($trip->departure_time)->format('g:i') }}<small>{{ \Carbon\Carbon::parse($trip->departure_time)->format('A') }}</small></strong><span>{{ \Carbon\Carbon::parse($trip->departure_time)->format('M j') }}</span></div>
                        <div class="departure-copy"><strong>{{ $trip->route }}</strong><small>{{ $trip->boat?->name ?? 'Vessel pending' }} <span aria-hidden="true">·</span> {{ ucfirst($trip->status) }}</small></div>
                        <a class="text-action manifest-link" href="{{ url('/admin/trip-schedules/'.$trip->id.'/manifest') }}?format=csv">Manifest <span aria-hidden="true">↓</span></a>
                    </div>
                @empty
                    <div class="panel-empty"><strong>No upcoming departures</strong><p>New sailings will appear here once they are scheduled.</p><a class="text-action" href="{{ route('admin.trip-schedules') }}">Manage schedules <span aria-hidden="true">›</span></a></div>
                @endforelse
            </section>

            <section class="operation-panel" id="recent-bookings" aria-labelledby="bookings-heading">
                <div class="operation-panel-heading"><h2 id="bookings-heading">Recent bookings</h2><a class="text-action" href="{{ route('admin.tickets.index') }}">View all <span aria-hidden="true">›</span></a></div>
                <div class="overview-booking-table"><table><thead><tr><th>Passenger / reference</th><th>Journey</th><th>Amount</th><th>Status</th></tr></thead><tbody>
                @forelse($recentBookings ?? [] as $booking)
                    @php $bookingStatus = strtolower($booking->status); $statusType = match($bookingStatus) { 'confirmed', 'completed', 'approved', 'paid' => 'success', 'pending', 'to_be_confirmed', 'awaiting_payment' => 'warning', 'cancelled', 'refunded', 'failed' => 'danger', default => 'info' }; @endphp
                    <tr><td data-label="Passenger"><strong>{{ $booking->passenger_name }}</strong><small>{{ $booking->reference_number ?: ('SP'.$booking->id) }}</small></td><td data-label="Journey">{{ $booking->route }}<small>{{ optional($booking->trip_date)->format('M j, Y') }}</small></td><td data-label="Amount" class="numeric">₱{{ number_format((float)$booking->amount_collected, 2) }}</td><td data-label="Status"><span class="badge {{ $statusType }}">{{ $bookingStatus === 'to_be_confirmed' ? 'ID review' : ucfirst(str_replace('_', ' ', $booking->status)) }}</span></td></tr>
                @empty
                    <tr><td colspan="4"><div class="panel-empty">No bookings yet. Passenger and counter bookings appear here.</div></td></tr>
                @endforelse
                </tbody></table></div>
            </section>
        </div>

        <aside class="overview-secondary" aria-label="Port context">
            <section class="operation-panel port-day-summary">
                <div class="operation-panel-heading"><h2>Today at the port</h2><span class="today-dot" aria-hidden="true"></span></div>
                <div class="day-revenue"><span>Collected today</span><strong>₱{{ number_format($todayRevenue ?? 0, 2) }}</strong><a class="text-action" href="{{ route('admin.reports-audit') }}">View sales report <span aria-hidden="true">›</span></a></div>
                <dl class="port-facts"><div><dt>Departures today</dt><dd>{{ $tripsTodayCount ?? 0 }}</dd></div><div><dt>Active vessels</dt><dd>{{ $activeBoatsCount ?? 0 }} <span>/ {{ $totalBoatsCount ?? 0 }}</span></dd></div><div><dt>Awaiting payment</dt><dd>{{ $paymentCount }}</dd></div></dl>
            </section>
            <section class="operation-panel" aria-labelledby="fleet-heading">
                <div class="operation-panel-heading"><h2 id="fleet-heading">Your fleet</h2><a class="text-action" href="{{ route('admin.boats') }}">Manage <span aria-hidden="true">›</span></a></div>
                @forelse($boats ?? [] as $boat)
                    <a class="context-row" href="{{ route('admin.boats') }}"><span class="vessel-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m4 12 8-3 8 3-3 6H7l-3-6ZM7 11V6h10v5M12 6V3M3 21c2-2 4 2 6 0s4 2 6 0 4 2 6 0"/></svg></span><span class="context-copy"><strong>{{ $boat->name }}</strong><small>{{ $boat->passenger_capacity }} passenger capacity</small></span><span class="state-dot {{ strtolower($boat->status) === 'active' ? 'is-active' : '' }}" title="{{ $boat->status }}"></span><span class="sr-only">{{ $boat->status }}</span></a>
                @empty
                    <div class="panel-empty">No vessels registered.</div>
                @endforelse
            </section>
            <section class="operation-panel" aria-labelledby="updates-heading">
                <div class="operation-panel-heading"><h2 id="updates-heading">Port updates</h2><a class="text-action" href="{{ route('admin.travel-advisory') }}">View all <span aria-hidden="true">›</span></a></div>
                @forelse($activeAdvisories ?? [] as $adv)
                    <a class="update-row" href="{{ route('admin.travel-advisory') }}"><small>{{ $adv->created_at->diffForHumans() }}</small><strong>{{ $adv->title }}</strong><p>{{ \Illuminate\Support\Str::limit($adv->message, 105) }}</p></a>
                @empty
                    <div class="panel-empty"><strong>No port updates</strong><p>Published travel advisories will appear here.</p></div>
                @endforelse
            </section>
            <a class="audit-shortcut" href="{{ route('admin.reports-audit') }}">View reports &amp; activity log <span aria-hidden="true">↗</span></a>
        </aside>
    </div>
@endsection
