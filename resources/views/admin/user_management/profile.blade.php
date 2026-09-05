@extends('layouts.admin')

@section('title', 'Profile')

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="card-title">Profile</div>
            <div class="card-subtitle">Admin Information</div>

            <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                <div style="width: 72px; height: 72px; border-radius: 12px; background: var(--accent-color); color: var(--bg-primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px;">
                    {{ $initials ?: 'A' }}
                </div>

                <div style="flex: 1; min-width: 240px;">
                    <div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">
                        {{ $user?->name ?? 'Admin' }}
                    </div>
                    <div style="margin-top: 6px; color: var(--text-secondary);">
                        {{ $user?->email ?? '' }}
                    </div>
                </div>
            </div>

            <div style="margin-top: 24px; display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 15px;">
                <div class="sales-box">
                    <div class="sales-label">Member since</div>
                    <div class="sales-value" style="font-size: 18px;">{{ $memberSince }}</div>
                </div>
                <div class="sales-box">
                    <div class="sales-label">Using the system for about</div>
                    <div class="sales-value" style="font-size: 18px;">{{ $usingFor }}</div>
                </div>
                <div class="sales-box">
                    <div class="sales-label">Role</div>
                    <div class="sales-value" style="font-size: 18px;">{{ $user->role ?? 'Admin' }}</div>
                </div>
            </div>

            <div style="margin-top: 18px; color: var(--text-secondary); font-size: 13px;">
                Note: "Using the system for about" is based on your account creation date.
            </div>
        </div>
    </div>
@endsection
