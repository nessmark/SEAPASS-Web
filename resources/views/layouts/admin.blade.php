<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Dashboard')</title>
    
    @include('partials.head-icons')
    
    {{-- CSS Files --}}
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dark-mode.css') }}">
    <link rel="stylesheet" href="{{ asset('css/light-mode.css') }}">
    
    {{-- Additional Styles --}}
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/workspace.css') }}?v=20261020">
    
    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    {{-- Include Sidebar Component --}}
    @include('components.sidebar')

    {{-- Main Content --}}
    <button type="button" class="sidebar-backdrop" id="sidebarBackdrop" aria-label="Close navigation" tabindex="-1"></button>
    <main class="main-content" id="main-content">
        {{-- Sticky Top Header --}}
        <header class="header">
            <div class="header-left">
                <button type="button" class="hamburger" id="sidebarToggle" role="button" tabindex="0" aria-label="Toggle sidebar" aria-expanded="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h10"/></svg></button>
                <div class="header-brand">
                    <span class="header-location">San Jose Port</span>
                    <span class="header-divider" aria-hidden="true">/</span>
                    <span class="header-current">@yield('title', 'Overview')</span>
                </div>
            </div>
            <div class="header-right">
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle light/dark mode">
                    <span id="themeIcon"></span>
                </button>
                <a href="{{ route('admin.travel-advisory') }}" class="icon-btn" aria-label="Travel advisories" title="Travel advisories"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></a>
                <a href="{{ route('admin.user-management.profile') }}" class="user-avatar" aria-label="Your profile">{{ mb_substr(auth()->user()->name ?? 'Admin', 0, 1) }}</a>
            </div>
        </header>

        {{-- Page Content --}}
        @yield('content')

        {{-- Footer --}}
        <div class="footer">
            SeaPass <span aria-hidden="true">·</span> San Jose Port operations
        </div>
    </main>

    @include('components.assistant')

    {{-- Common JavaScript --}}
    <script src="{{ asset('js/admin.js') }}"></script>
    
    {{-- Page-specific Scripts --}}
    @stack('scripts')
</body>
</html>
