<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Dashboard')</title>
    
    {{-- CSS Files --}}
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dark-mode.css') }}">
    <link rel="stylesheet" href="{{ asset('css/light-mode.css') }}">
    
    <style>
        .header {
            position: sticky;
            top: 0;
            z-index: 1000;
        }
    </style>
    
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
        {{-- Header --}}
        <div class="header">
            <div class="header-left">
                <div class="hamburger" id="sidebarToggle" role="button" tabindex="0" aria-label="Toggle sidebar" aria-expanded="true">☰</div>
            </div>
            <div class="header-right">
                <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle light/dark mode">
                    <span id="themeIcon">🌙</span>
                </button>
                <button class="icon-btn">🔔</button>
            </div>
        </div>

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
