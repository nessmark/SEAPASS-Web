@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <style>
        /* Page header — a title block, not a coloured banner (DESIGN-SYSTEM.md §0.2) */
        .command-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: var(--s-4);
            margin-bottom: var(--s-7);
        }
        .command-title {
            font-size: 30px;
            font-weight: 600;
            letter-spacing: -0.022em;
            color: var(--text);
            margin: 0 0 4px;
        }
        .command-subtitle {
            font-size: 15px;
            color: var(--text-2);
            margin: 0;
            max-width: 62ch;
        }
        .command-actions { display: flex; gap: var(--s-2); flex-shrink: 0; }

        .cmd-btn {
            display: inline-flex;
            align-items: center;
            gap: var(--s-2);
            height: 40px;
            padding: 0 var(--s-4);
            border: none;
            border-radius: var(--r-md);
            background: var(--accent);
            color: var(--on-accent);
            font-weight: 600;
            font-size: 14.5px;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 160ms var(--ease), transform 120ms var(--ease);
        }
        .cmd-btn:hover { background: var(--accent-strong); color: var(--on-accent); }
        .cmd-btn:active { transform: scale(.98); }
        .cmd-btn svg { width: 17px; height: 17px; }

        .cmd-btn-secondary { background: var(--accent-tint); color: var(--accent-strong); }
        .cmd-btn-secondary:hover { background: var(--accent-tint); filter: brightness(1.08); color: var(--accent-strong); }

        /* KPI tiles */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: var(--s-4);
            margin-bottom: var(--s-6);
        }
        .kpi-card {
            background: var(--surface);
            border: none;
            padding: var(--s-5) var(--s-5) var(--s-5) var(--s-6);
            border-radius: var(--r-lg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--s-4);
            box-shadow: var(--e1), var(--lit-edge);
            transition: box-shadow var(--dur) var(--ease), transform var(--dur) var(--ease);
        }
        .kpi-card:hover { box-shadow: var(--e2), var(--lit-edge); transform: translateY(-1px); }
        .kpi-info-label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.045em;
            margin-bottom: 8px;
        }
        .kpi-info-value {
            font-size: 32px;
            font-weight: 600;
            letter-spacing: -0.025em;
            color: var(--text);
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
        }
        .kpi-subtext {
            font-size: 12.5px;
            color: var(--text-2);
            margin-top: 6px;
            font-weight: 500;
        }
        .kpi-icon-box {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            border-radius: var(--r-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--accent-tint);
            color: var(--accent-strong);
        }
        .kpi-icon-box svg { width: 19px; height: 19px; }
        /* one accent: the old blue/teal/purple/amber boxes all resolve to the brand tint */
        .kpi-icon-blue, .kpi-icon-teal, .kpi-icon-purple, .kpi-icon-amber {
            background: var(--accent-tint);
            color: var(--accent-strong);
        }

        /* Fleet */
        .fleet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: var(--s-3);
        }
        .boat-mini-card {
            background: var(--surface-2);
            border: none;
            border-radius: var(--r-md);
            padding: var(--s-4);
            display: flex;
            align-items: center;
            gap: var(--s-3);
            transition: background-color 160ms var(--ease);
        }
        .boat-mini-card:hover { background: var(--accent-tint-2); }
        .boat-avatar {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            border-radius: var(--r-sm);
            background: var(--accent-tint);
            color: var(--accent-strong);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .boat-avatar svg { width: 21px; height: 21px; }
        .boat-info-title {
            font-size: 15px;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: var(--text);
            margin: 0 0 2px 0;
        }
        .boat-info-meta { font-size: 12.5px; color: var(--text-2); }

        /* Booking table cells */
        .cell-ref, .cell-route { white-space: nowrap; }
        .cell-ref strong { font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
        .cat-chip {
            display: inline-block;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-2);
            background: var(--surface-2);
            padding: 3px 9px;
            border-radius: var(--r-pill);
            white-space: nowrap;
        }

        /* Status pills */
        .badge-active, .badge-maintenance, .status-badge-sm {
            display: inline-flex;
            align-items: center;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: var(--r-pill);
        }
        .badge-active   { background: var(--success-tint); color: var(--success-fg); }
        .badge-maintenance { background: var(--warning-tint); color: var(--warning-fg); }
        .status-confirmed { background: var(--success-tint); color: var(--success-fg); }
        .status-pending   { background: var(--warning-tint); color: var(--warning-fg); }
        .status-cancelled { background: var(--danger-tint);  color: var(--danger-fg); }

        @media (max-width: 1024px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .command-header { flex-direction: column; align-items: flex-start; gap: var(--s-4); }
            .command-actions { width: 100%; }
            .cmd-btn { flex: 1; justify-content: center; }
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2 2.5 2.5 0 0 0 0 5 2 2 0 0 1-2 2H5a2 2 0 0 1-2-2 2.5 2.5 0 0 0 0-5z"/><path d="M14 8v8" stroke-dasharray="2 3"/></svg> Counter Booking
            </a>
            <a href="{{ route('admin.travel-advisory') }}" class="cmd-btn cmd-btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10v4a1 1 0 0 0 1 1h3l5 4V5L8 9H5a1 1 0 0 0-1 1z"/><path d="M17.5 9a4 4 0 0 1 0 6"/></svg> Dispatch Advisory
            </a>
        </div>
    </div>

    <!-- 1. KPI Summary Cards Grid -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Today's Revenue</div>
                <div class="kpi-info-value" style="color: var(--text);">₱{{ number_format($todayRevenue ?? 0, 2) }}</div>
                <div class="kpi-subtext">Total Sales: ₱{{ number_format($totalSalesAllTime ?? 0, 2) }}</div>
            </div>
            <div class="kpi-icon-box kpi-icon-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 20V5h5.5a4 4 0 0 1 0 8H7"/><path d="M5 9h9M5 12h9"/></svg></div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Active Fleet</div>
                <div class="kpi-info-value">{{ $activeBoatsCount ?? 0 }} / {{ $totalBoatsCount ?? 0 }}</div>
                <div class="kpi-subtext">Operational Boats</div>
            </div>
            <div class="kpi-icon-box kpi-icon-teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17.5c1.5 1 2.5 1 4 0s2.5-1 4 0 2.5 1 4 0 2.5-1 4 0"/><path d="M5 15V9.5a1 1 0 0 1 .6-.92l5.8-2.5a1 1 0 0 1 .8 0l5.8 2.5a1 1 0 0 1 .6.92V15"/><path d="M12 6V3"/></svg></div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Trips Scheduled</div>
                <div class="kpi-info-value">{{ $tripsTodayCount ?? 0 }}</div>
                <div class="kpi-subtext">Total Schedules: {{ $totalSchedulesCount ?? 0 }}</div>
            </div>
            <div class="kpi-icon-box kpi-icon-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></div>
        </div>

        <div class="kpi-card">
            <div>
                <div class="kpi-info-label">Passengers</div>
                <div class="kpi-info-value">{{ $totalPassengersCount ?? 0 }}</div>
                <div class="kpi-subtext">Registered Users</div>
            </div>
            <div class="kpi-icon-box kpi-icon-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19"/><circle cx="10" cy="8" r="3.2"/><path d="M20 19v-1.5a3.5 3.5 0 0 0-2.6-3.4"/><path d="M15.5 5.2a3.2 3.2 0 0 1 0 5.6"/></svg></div>
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
                <a href="{{ route('admin.tickets.index') }}" style="font-size: 13px; font-weight: 600; color: var(--accent); text-decoration: none;">View All →</a>
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
                            <td class="cell-ref"><strong>{{ $booking->reference_number ?: ('SP' . $booking->id) }}</strong></td>
                            <td>{{ $booking->passenger_name }}</td>
                            <td class="cell-route">{{ $booking->route }}</td>
                            <td>
                                @php $bd = $booking->passenger_breakdown; @endphp
                                @foreach(['regular' => 'Regular', 'student' => 'Student', 'senior' => 'Senior/PWD'] as $key => $label)
                                    @if(($bd[$key] ?? 0) > 0)
                                        <span class="cat-chip">{{ $bd[$key] }} {{ $label }}</span>
                                    @endif
                                @endforeach
                            </td>
                            <td><strong style="color: var(--text);">₱{{ number_format((float)$booking->amount_collected, 2) }}</strong></td>
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
                            <td colspan="6" style="text-align: center; padding: 24px; color: var(--text-2);">No recent bookings recorded.</td>
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
                <a href="{{ route('admin.boats') }}" style="font-size: 13px; font-weight: 600; color: var(--accent); text-decoration: none;">Manage Fleet →</a>
            </div>

            <div class="fleet-grid">
                @forelse($boats ?? [] as $boat)
                    <div class="boat-mini-card">
                        <div class="boat-avatar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17.5c1.5 1 2.5 1 4 0s2.5-1 4 0 2.5 1 4 0 2.5-1 4 0"/><path d="M5 15V9.5a1 1 0 0 1 .6-.92l5.8-2.5a1 1 0 0 1 .8 0l5.8 2.5a1 1 0 0 1 .6.92V15"/><path d="M12 6V3"/></svg></div>
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
                    <div style="padding: 16px; color: var(--text-2);">No registered boats in the fleet.</div>
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
                <a href="{{ route('admin.reports-audit') }}" style="font-size: 13px; font-weight: 600; color: var(--accent); text-decoration: none;">Audit Log →</a>
            </div>

            <!-- Advisories List -->
            <div style="margin-bottom: 16px;">
                @forelse($activeAdvisories ?? [] as $adv)
                    <div style="background: var(--bg-primary); border-left: 4px solid var(--accent); padding: 10px 14px; border-radius: var(--r-sm); margin-bottom: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 13px; color: var(--text);">{{ $adv->title }}</strong>
                            <span style="font-size: 11px; color: var(--text-2);">{{ $adv->created_at->diffForHumans() }}</span>
                        </div>
                        <p style="font-size: 12px; color: var(--text-2); margin: 4px 0 0 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            {{ $adv->message }}
                        </p>
                    </div>
                @empty
                    <div style="font-size: 13px; color: var(--text-2);">No active advisories broadcasted.</div>
                @endforelse
            </div>

            <!-- Recent Audit Trail -->
            <div style="border-top: 1px solid var(--border-color); padding-top: 12px;">
                <div style="font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; margin-bottom: 8px;">Latest Activity Log</div>
                @forelse($recentAuditLogs ?? [] as $log)
                    <div style="font-size: 12.5px; padding: 6px 0; border-bottom: 1px dashed var(--border-color); color: var(--text); display: flex; justify-content: space-between;">
                        <span><strong>{{ $log->action }}</strong>: {{ \Illuminate\Support\Str::limit($log->details, 45) }}</span>
                        <span style="font-size: 11px; color: var(--text-2);">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                @empty
                    <div style="font-size: 12px; color: var(--text-2);">No audit logs recorded yet.</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Light mode is declared on body.light-mode, dark on :root — read body first.
            const css = (name) => getComputedStyle(document.body).getPropertyValue(name).trim()
                || getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            const ctx = document.getElementById('weeklyRevenueChart');
            if (ctx) {
                const weeklyData = @json($weeklyData ?? []);
                const labels = weeklyData.map(item => item.day);
                const revenues = weeklyData.map(item => item.revenue);

                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Sales Revenue (₱)',
                            data: revenues,
                            backgroundColor: css('--accent'),
                            hoverBackgroundColor: css('--accent-strong'),
                            borderWidth: 0,
                            borderRadius: 8,
                            borderSkipped: false,
                            maxBarThickness: 44,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 420, easing: 'easeOutQuart' },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: css('--surface-3'),
                                titleColor: css('--text'),
                                bodyColor: css('--text-2'),
                                padding: 10,
                                cornerRadius: 10,
                                displayColors: false,
                                callbacks: {
                                    label: (c) => '₱' + Number(c.parsed.y).toLocaleString('en-PH', { minimumFractionDigits: 2 })
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false, drawBorder: false },
                                ticks: { color: css('--text-3'), font: { size: 11.5 } }
                            },
                            y: {
                                beginAtZero: true,
                                border: { display: false },
                                grid: { color: css('--chart-grid'), drawTicks: false },
                                ticks: {
                                    color: css('--text-3'),
                                    font: { size: 11.5 },
                                    padding: 10,
                                    maxTicksLimit: 5,
                                    callback: (value) => '₱' + Number(value).toLocaleString('en-PH')
                                }
                            }
                        }
                    }
                });

                window.addEventListener('themeChanged', () => {
                    const ds = chart.data.datasets[0];
                    ds.backgroundColor = css('--accent');
                    ds.hoverBackgroundColor = css('--accent-strong');
                    chart.options.scales.x.ticks.color = css('--text-3');
                    chart.options.scales.y.ticks.color = css('--text-3');
                    chart.options.scales.y.grid.color = css('--chart-grid');
                    chart.options.plugins.tooltip.backgroundColor = css('--surface-3');
                    chart.options.plugins.tooltip.titleColor = css('--text');
                    chart.options.plugins.tooltip.bodyColor = css('--text-2');
                    chart.update('none');
                });
            }
        });
    </script>
@endpush
