@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <style>
        .command-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #0284C7 0%, #0F766E 100%);
            padding: 24px 28px;
            border-radius: 14px;
            color: #FFFFFF;
            box-shadow: 0 4px 20px rgba(2, 132, 199, 0.15);
        }
        .command-title {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 6px 0;
            letter-spacing: 0.5px;
        }
        .command-subtitle {
            font-size: 13.5px;
            color: #E0F2FE;
            margin: 0;
        }
        .command-actions {
            display: flex;
            gap: 10px;
        }
        .cmd-btn {
            background: #FFFFFF;
            color: #0284C7;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .cmd-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            color: #0369A1;
        }
        .cmd-btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: #FFFFFF;
            backdrop-filter: blur(8px);
        }
        .cmd-btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            color: #FFFFFF;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: var(--card-bg, #FFFFFF);
            border: 1px solid var(--border-color, #E2E8F0);
            padding: 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .kpi-info-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-secondary, #64748B);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .kpi-info-value {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-primary, #0F172A);
        }
        .kpi-subtext {
            font-size: 12px;
            color: #059669;
            margin-top: 4px;
            font-weight: 600;
        }
        .kpi-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .kpi-icon-blue { background: #E0F2FE; color: #0284C7; }
        .kpi-icon-teal { background: #CCFBF1; color: #0D9488; }
        .kpi-icon-purple { background: #F3E8FF; color: #7C3AED; }
        .kpi-icon-amber { background: #FEF3C7; color: #D97706; }

        .fleet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        .boat-mini-card {
            background: var(--bg-primary, #F8FAFC);
            border: 1px solid var(--border-color, #E2E8F0);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .boat-avatar {
            width: 46px;
            height: 46px;
            border-radius: 8px;
            background: #0284C7;
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 800;
        }
        .boat-info-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary, #0F172A);
            margin: 0 0 2px 0;
        }
        .boat-info-meta {
            font-size: 12px;
            color: var(--text-secondary, #64748B);
        }
        .badge-active {
            background: #DCFCE7;
            color: #15803D;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 99px;
        }
        .badge-maintenance {
            background: #FEF3C7;
            color: #B45309;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 99px;
        }
        .status-badge-sm {
            padding: 3px 8px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
        }
        .status-confirmed { background: #DCFCE7; color: #15803D; }
        .status-pending { background: #FEF3C7; color: #B45309; }
        .status-cancelled { background: #FEE2E2; color: #B91C1C; }

        @media (max-width: 1024px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .command-header { flex-direction: column; align-items: flex-start; gap: 14px; }
        }
    </style>
@endpush

@section('content')
    <!-- Command Header -->
    <div class="command-header">
        <div>
            <h1 class="command-title">SeaPass Port Command Center</h1>
            <p class="command-subtitle">Real-time maritime passenger ticketing, vessel schedules, and port security overview.</p>
        </div>
        <div class="command-actions">
            <a href="{{ route('admin.tickets.index') }}" class="cmd-btn">
                🎟️ Counter Booking
            </a>
            <a href="{{ route('admin.travel-advisory') }}" class="cmd-btn cmd-btn-secondary">
                📢 Dispatch Advisory
            </a>
        </div>
    </div>

    <!-- 1. KPI Summary Cards Grid -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Today's Revenue</div>
                <div class="kpi-info-value" style="color: #059669;">₱{{ number_format($todayRevenue ?? 0, 2) }}</div>
                <div class="kpi-subtext">Total Sales: ₱{{ number_format($totalSalesAllTime ?? 0, 2) }}</div>
            </div>
            <div class="kpi-icon-box kpi-icon-blue">💰</div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Active Fleet</div>
                <div class="kpi-info-value">{{ $activeBoatsCount ?? 0 }} / {{ $totalBoatsCount ?? 0 }}</div>
                <div class="kpi-subtext">Operational Boats</div>
            </div>
            <div class="kpi-icon-box kpi-icon-teal">🚢</div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Trips Scheduled</div>
                <div class="kpi-info-value">{{ $tripsTodayCount ?? 0 }}</div>
                <div class="kpi-subtext">Total Schedules: {{ $totalSchedulesCount ?? 0 }}</div>
            </div>
            <div class="kpi-icon-box kpi-icon-purple">📅</div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Passengers</div>
                <div class="kpi-info-value">{{ $totalPassengersCount ?? 0 }}</div>
                <div class="kpi-subtext">Registered Users</div>
            </div>
            <div class="kpi-icon-box kpi-icon-amber">👥</div>
        </div>
    </div>

    <!-- 2. Main Operational Grid -->
    <div class="dashboard-grid">
        <!-- Live Recent Bookings Table -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <div class="card-title" style="margin-bottom: 4px;">Recent Booking Activity</div>
                    <div class="card-subtitle" style="margin-bottom: 0;">Latest bookings submitted via mobile app &amp; counter desk.</div>
                </div>
                <a href="{{ route('admin.tickets.index') }}" style="font-size: 13px; font-weight: 700; color: #0284C7; text-decoration: none;">View All →</a>
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th>Ref #</th>
                        <th>Passenger</th>
                        <th>Route</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentBookings ?? [] as $booking)
                        <tr>
                            <td><strong>{{ $booking->reference_number ?: ('SP' . $booking->id) }}</strong></td>
                            <td>{{ $booking->passenger_name }}</td>
                            <td>{{ $booking->route }}</td>
                            <td>
                                <span style="font-size: 11px; font-weight: 600; color: var(--text-secondary); background: var(--bg-tertiary); padding: 2px 6px; border-radius: 4px;">
                                    {{ $booking->passenger_breakdown['text'] }}
                                </span>
                            </td>
                            <td><strong style="color: #059669;">₱{{ number_format((float)$booking->amount_collected, 2) }}</strong></td>
                            <td>
                                @if(in_array(strtolower($booking->status), ['confirmed', 'completed', 'approved']))
                                    <span class="status-badge-sm status-confirmed">Confirmed</span>
                                @elseif(in_array(strtolower($booking->status), ['pending', 'to_be_confirmed']))
                                    <span class="status-badge-sm status-pending">Pending</span>
                                @else
                                    <span class="status-badge-sm status-cancelled">Cancelled</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 24px; color: var(--text-secondary);">No recent bookings recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Weekly Revenue Analytics Chart -->
        <div class="card">
            <div class="card-title" style="margin-bottom: 4px;">Weekly Revenue Trend</div>
            <div class="card-subtitle">Daily sales revenue over the past 7 days.</div>
            <div class="chart-container">
                <canvas id="weeklyRevenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- 3. Fleet & Security Grid -->
    <div class="dashboard-grid">
        <!-- Active Vessel Fleet -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <div class="card-title" style="margin-bottom: 4px;">Active Vessel Fleet</div>
                    <div class="card-subtitle" style="margin-bottom: 0;">Registered passenger boats operating Surigao ⇄ San Jose.</div>
                </div>
                <a href="{{ route('admin.boats') }}" style="font-size: 13px; font-weight: 700; color: #0284C7; text-decoration: none;">Manage Fleet →</a>
            </div>

            <div class="fleet-grid">
                @forelse($boats ?? [] as $boat)
                    <div class="boat-mini-card">
                        <div class="boat-avatar">⛵</div>
                        <div style="flex: 1;">
                            <div class="boat-info-title">{{ $boat->name }}</div>
                            <div class="boat-info-meta">
                                Capacity: <strong>{{ $boat->passenger_capacity }} passengers</strong>
                            </div>
                            <div class="boat-info-meta">
                                Lic: {{ $boat->license_number ?: 'SP-BOAT-' . $boat->id }}
                            </div>
                        </div>
                        <div>
                            @if(strtolower($boat->status) === 'active')
                                <span class="badge-active">Active</span>
                            @else
                                <span class="badge-maintenance">{{ $boat->status }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div style="padding: 16px; color: var(--text-secondary);">No registered boats in the fleet.</div>
                @endforelse
            </div>
        </div>

        <!-- Active Advisories & Audit Stream -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <div class="card-title" style="margin-bottom: 4px;">Recent Advisories &amp; System Activity</div>
                    <div class="card-subtitle" style="margin-bottom: 0;">Latest push notifications and admin activity logs.</div>
                </div>
                <a href="{{ route('admin.reports-audit') }}" style="font-size: 13px; font-weight: 700; color: #0284C7; text-decoration: none;">Audit Log →</a>
            </div>

            <!-- Advisories List -->
            <div style="margin-bottom: 16px;">
                @forelse($activeAdvisories ?? [] as $adv)
                    <div style="background: var(--bg-primary); border-left: 4px solid #0284C7; padding: 10px 14px; border-radius: 6px; margin-bottom: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 13px; color: var(--text-primary);">📢 {{ $adv->title }}</strong>
                            <span style="font-size: 11px; color: var(--text-secondary);">{{ $adv->created_at->diffForHumans() }}</span>
                        </div>
                        <p style="font-size: 12px; color: var(--text-secondary); margin: 4px 0 0 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            {{ $adv->message }}
                        </p>
                    </div>
                @empty
                    <div style="font-size: 13px; color: var(--text-secondary);">No active advisories broadcasted.</div>
                @endforelse
            </div>

            <!-- Recent Audit Trail -->
            <div style="border-top: 1px solid var(--border-color); padding-top: 12px;">
                <div style="font-size: 12px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">Latest Activity Log</div>
                @forelse($recentAuditLogs ?? [] as $log)
                    <div style="font-size: 12.5px; padding: 6px 0; border-bottom: 1px dashed var(--border-color); color: var(--text-primary); display: flex; justify-content: space-between;">
                        <span>⚡ <strong>{{ $log->action }}</strong>: {{ \Illuminate\Support\Str::limit($log->details, 45) }}</span>
                        <span style="font-size: 11px; color: var(--text-secondary);">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                @empty
                    <div style="font-size: 12px; color: var(--text-secondary);">No audit logs recorded yet.</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('weeklyRevenueChart');
            if (ctx) {
                const weeklyData = @json($weeklyData ?? []);
                const labels = weeklyData.map(item => item.day);
                const revenues = weeklyData.map(item => item.revenue);

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Sales Revenue (₱)',
                            data: revenues,
                            backgroundColor: 'rgba(2, 132, 199, 0.75)',
                            borderColor: '#0284C7',
                            borderWidth: 1.5,
                            borderRadius: 6,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) { return '₱' + value; }
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
@endpush
