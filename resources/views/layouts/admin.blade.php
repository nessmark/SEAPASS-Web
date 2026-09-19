<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Dashboard')</title>
    
    {{-- Favicon / Tab Icon --}}
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/seapass_logo.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/seapass_logo.png') }}">
    
    {{-- CSS Files --}}
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dark-mode.css') }}">
    <link rel="stylesheet" href="{{ asset('css/light-mode.css') }}">
    
    {{-- Additional Styles --}}
    @stack('styles')
    
    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    {{-- Include Sidebar Component --}}
    @include('components.sidebar')

    {{-- Main Content --}}
    <div class="main-content">
        {{-- Sticky Top Header --}}
        <header class="header">
            <div class="header-left">
                <button type="button" class="hamburger" id="sidebarToggle" role="button" tabindex="0" aria-label="Toggle sidebar" aria-expanded="true">☰</button>
                <div class="header-brand">
                    <span class="header-logo-text">SeaPass</span>
                    <span class="header-badge">Admin</span>
                </div>
            </div>
            <div class="header-right">
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle light/dark mode">
                    <span id="themeIcon">🌙</span>
                </button>
                <button type="button" class="icon-btn" aria-label="Notifications" title="Notifications">🔔</button>
            </div>
        </header>

        {{-- Page Content --}}
        @yield('content')

        {{-- Footer --}}
        <div class="footer">
            Copyright © 2026 SeaPass Ticketing System. All rights reserved.
        </div>
    </div>

    {{-- Common JavaScript --}}
    <script src="{{ asset('js/admin.js') }}"></script>
    
    {{-- Page-specific Scripts --}}
    @stack('scripts')
</body>
</html>
