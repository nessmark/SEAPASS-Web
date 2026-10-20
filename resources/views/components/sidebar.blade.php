{{-- Sidebar Component --}}
@php
    $icon = function (string $name) {
        $paths = [
            'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
            'fleet'     => '<path d="M3 17.5c1.5 1 2.5 1 4 0s2.5-1 4 0 2.5 1 4 0 2.5-1 4 0"/><path d="M5 15V9.5a1 1 0 0 1 .6-.92l5.8-2.5a1 1 0 0 1 .8 0l5.8 2.5a1 1 0 0 1 .6.92V15"/><path d="M12 6V3"/>',
            'schedule'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
            'ticket'    => '<path d="M3 9.5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2 2.5 2.5 0 0 0 0 5 2 2 0 0 1-2 2H5a2 2 0 0 1-2-2 2.5 2.5 0 0 0 0-5z"/><path d="M14 8v8" stroke-dasharray="2 3"/>',
            'advisory'  => '<path d="M4 10v4a1 1 0 0 0 1 1h3l5 4V5L8 9H5a1 1 0 0 0-1 1z"/><path d="M17.5 9a4 4 0 0 1 0 6"/>',
            'fare'      => '<circle cx="12" cy="12" r="9"/><path d="M14.5 9.5A2.6 2.6 0 0 0 12 8c-1.4 0-2.5.8-2.5 2s1.1 2 2.5 2 2.5.8 2.5 2-1.1 2-2.5 2a2.6 2.6 0 0 1-2.5-1.5"/><path d="M12 6.5v11"/>',
            'reports'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
            'users'     => '<path d="M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19"/><circle cx="10" cy="8" r="3.2"/><path d="M20 19v-1.5a3.5 3.5 0 0 0-2.6-3.4"/><path d="M15.5 5.2a3.2 3.2 0 0 1 0 5.6"/>',
            'passenger' => '<path d="M17 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 5 18.5V20"/><circle cx="11" cy="8" r="3.4"/>',
            'scanner'   => '<path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2"/><path d="M4 12h16"/>',
            'logout'    => '<path d="M14 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h10"/>',
            'chevron'   => '<path d="m6 9 6 6 6-6"/>',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
    };
@endphp
<nav class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sidebar-logo" href="{{ route('admin.dashboard') }}">SeaPass</a>
    <div class="sidebar-workspace"><span class="workspace-mark">SJ</span><span><strong>San Jose Port</strong><small>Operations workspace</small></span></div>
    <a href="{{ route('admin.dashboard') }}" class="nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <span class="nav-icon">{!! $icon('dashboard') !!}</span>
        <span>Dashboard</span>
    </a>
    <div class="nav-group-label">Port operations</div>
    <a href="{{ route('admin.boats') }}" class="nav-item {{ request()->routeIs('admin.boats') ? 'active' : '' }}">
        <span class="nav-icon">{!! $icon('fleet') !!}</span>
        <span>Fleet Management</span>
    </a>
    <a href="{{ route('admin.trip-schedules') }}" class="nav-item {{ request()->routeIs('admin.trip-schedules*') ? 'active' : '' }}">
        <span class="nav-icon">{!! $icon('schedule') !!}</span>
        <span>Trip &amp; Schedule</span>
    </a>
    <a href="{{ route('admin.tickets.index') }}" class="nav-item {{ request()->routeIs('admin.tickets*') ? 'active' : '' }}">
        <span class="nav-icon">{!! $icon('ticket') !!}</span>
        <span>Ticketing &amp; Booking</span>
    </a>
    <a href="{{ route('admin.travel-advisory') }}" class="nav-item {{ request()->routeIs('admin.travel-advisory') ? 'active' : '' }}">
        <span class="nav-icon">{!! $icon('advisory') !!}</span>
        <span>Travel Advisory</span>
    </a>
    <a href="{{ route('admin.sms') }}" class="nav-item {{ request()->routeIs('admin.sms') ? 'active' : '' }}"><span class="nav-icon">{!! $icon('advisory') !!}</span><span>SMS delivery</span></a>
    <div class="nav-group-label">Management</div>
    <a href="{{ route('admin.fare-management') }}" class="nav-item {{ request()->routeIs('admin.fare-management*') ? 'active' : '' }}">
        <span class="nav-icon">{!! $icon('fare') !!}</span>
        <span>Fare Management</span>
    </a>
    <a href="{{ route('admin.reports-audit') }}" class="nav-item {{ request()->routeIs('admin.reports-audit*') ? 'active' : '' }}">
        <span class="nav-icon">{!! $icon('reports') !!}</span>
        <span>Reports &amp; Audit</span>
    </a>
    {{-- User Management Dropdown --}}
    <div class="nav-dropdown">
        <button type="button" aria-controls="userManagementMenu" aria-expanded="{{ request()->routeIs('admin.user-management.*') ? 'true' : 'false' }}" class="nav-item nav-dropdown-toggle {{ request()->routeIs('admin.user-management.*') ? 'active' : '' }}" id="userManagementToggle">
            <span class="nav-icon">{!! $icon('users') !!}</span>
            <span>User Management</span>
            <span class="dropdown-arrow">{!! $icon('chevron') !!}</span>
        </button>
        <div class="nav-dropdown-menu" id="userManagementMenu">
            <a href="{{ route('admin.user-management.passengers') }}" class="nav-dropdown-item {{ request()->routeIs('admin.user-management.passengers') ? 'active' : '' }}">
                <span class="nav-icon">{!! $icon('passenger') !!}</span>
                <span>Passenger List</span>
            </a>
            <a href="{{ route('admin.user-management.scanner-staff') }}" class="nav-dropdown-item {{ request()->routeIs('admin.user-management.scanner-staff*') ? 'active' : '' }}">
                <span class="nav-icon">{!! $icon('scanner') !!}</span>
                <span>Scanner Staff</span>
            </a>
        </div>
    </div>
    <div class="sidebar-account">
    <a class="account-profile" href="{{ route('admin.user-management.profile') }}"><span class="user-avatar">{{ mb_substr(auth()->user()->name ?? 'Admin', 0, 1) }}</span><span><strong>{{ auth()->user()->name ?? 'Administrator' }}</strong><small>{{ ucfirst(auth()->user()->role ?? 'Admin') }}</small></span></a>
    <form method="POST" action="{{ route('logout') }}" style="margin: 0; width: 100%;">
        @csrf
        <button type="submit" class="nav-item logout-btn">
            <span class="nav-icon">{!! $icon('logout') !!}</span>
            <span>Logout</span>
        </button>
    </form>
    </div>
</nav>
