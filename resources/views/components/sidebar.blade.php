{{-- Sidebar Component --}}
<div class="sidebar">
    <div class="sidebar-logo">SeaPass</div>
    <a href="{{ route('admin.dashboard') }}" class="nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <span class="nav-icon">📊</span>
        <span>Dashboard</span>
    </a>
    <a href="{{ route('admin.boats') }}" class="nav-item {{ request()->routeIs('admin.boats') ? 'active' : '' }}">
        <span class="nav-icon">🚤</span>
        <span>Fleet Management</span>
    </a>
    <a href="{{ route('admin.trip-schedules') }}" class="nav-item {{ request()->routeIs('admin.trip-schedules*') ? 'active' : '' }}">
        <span class="nav-icon">⏰</span>
        <span>Trip & Schedule Management</span>
    </a>
    <a href="{{ route('admin.tickets.index') }}" class="nav-item {{ request()->routeIs('admin.tickets*') ? 'active' : '' }}">
        <span class="nav-icon">🎫</span>
        <span>Ticketing &amp; Booking</span>
    </a>
    <a href="{{ route('admin.travel-advisory') }}" class="nav-item {{ request()->routeIs('admin.travel-advisory') ? 'active' : '' }}">
        <span class="nav-icon">📢</span>
        <span>Travel Advisory</span>
    </a>
    <a href="{{ route('admin.fare-management') }}" class="nav-item {{ request()->routeIs('admin.fare-management*') ? 'active' : '' }}">
        <span class="nav-icon">💰</span>
        <span>Fare Management</span>
    </a>
    <a href="{{ route('admin.reports-audit') }}" class="nav-item {{ request()->routeIs('admin.reports-audit*') ? 'active' : '' }}">
        <span class="nav-icon">📋</span>
        <span>Reports &amp; Audit</span>
    </a>
    {{-- User Management Dropdown --}}
    <div class="nav-dropdown">
        <div class="nav-item nav-dropdown-toggle {{ request()->routeIs('admin.user-management.*') ? 'active' : '' }}" id="userManagementToggle">
            <span class="nav-icon">👥</span>
            <span>User Management</span>
            <span class="dropdown-arrow">▼</span>
        </div>
        <div class="nav-dropdown-menu" id="userManagementMenu">
            <a href="{{ route('admin.user-management.passengers') }}" class="nav-dropdown-item {{ request()->routeIs('admin.user-management.passengers') ? 'active' : '' }}">
                <span class="nav-icon">👨‍👩‍👧‍👦</span>
                <span>Passenger List</span>
            </a>
            <a href="{{ route('admin.user-management.scanner-staff') }}" class="nav-dropdown-item {{ request()->routeIs('admin.user-management.scanner-staff*') ? 'active' : '' }}">
                <span class="nav-icon">📷</span>
                <span>Scanner Staff</span>
            </a>
        </div>
    </div>
    <form method="POST" action="{{ route('logout') }}" style="margin: 0; width: 100%;">
        @csrf
        <button type="submit" class="nav-item logout-btn">
            <span class="nav-icon">🚪</span>
            <span>Logout</span>
        </button>
    </form>
</div>
