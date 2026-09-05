@extends('layouts.admin')

@section('title', 'Travel Advisory')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/travel-adv.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/travel-adv.js') }}"></script>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="adv-header">
                <div>
                    <div class="card-title">Travel Advisory</div>
                    <div class="card-subtitle">
                        Post weather updates, sea condition warnings, and port suspensions, and manage alerts for passengers.
                    </div>
                </div>
            </div>

            <div class="adv-layout">
                {{-- Create Advisory --}}
                <section class="adv-section">
                    <div class="adv-section-header">
                        <h2>Create Advisory</h2>
                        <p>Create and publish travel advisories for passengers and staff.</p>
                    </div>

                    <form class="adv-form" id="advisoryForm">
                        <div class="adv-form-grid">
                            <div class="adv-field">
                                <label>Title</label>
                                <input type="text" id="adv_title" placeholder="e.g., Trip to Dinagat suspended due to bad weather" required>
                            </div>
                            <div class="adv-field">
                                <label>Advisory Type</label>
                                <select id="adv_type" required>
                                    <option>Weather Update</option>
                                    <option>Sea Condition Warning</option>
                                    <option>Port Suspension</option>
                                    <option>Operational Advisory</option>
                                </select>
                            </div>
                            <div class="adv-field">
                                <label>Affected Route</label>
                                <select id="adv_route" required>
                                    <option>All Routes</option>
                                    <option>Surigao → San Jose(Dinagat)</option>
                                    <option>San Jose(Dinagat) → Surigao</option>
                                </select>
                            </div>
                            <div class="adv-field">
                                <label>Effective From</label>
                                <input type="datetime-local" id="adv_effective_from" required>
                            </div>
                            <div class="adv-field">
                                <label>Until (optional)</label>
                                <input type="datetime-local" id="adv_until">
                            </div>
                            <div class="adv-field">
                                <label>Severity</label>
                                <select id="adv_severity" required>
                                    <option>Information</option>
                                    <option>Advisory</option>
                                    <option>Warning</option>
                                    <option>Critical</option>
                                </select>
                            </div>
                            <div class="adv-field full-width">
                                <label>Advisory Message</label>
                                <textarea id="adv_message" rows="4" placeholder="Describe the situation, safety reminders, and instructions for passengers." required></textarea>
                            </div>
                            <div class="adv-field full-width adv-inline">
                                <label>
                                    <input type="checkbox" id="adv_publish_push" checked>
                                    <span>Publish immediately on passenger app and admin dashboard</span>
                                </label>
                            </div>
                        </div>
                        <div class="adv-form-actions">
                            <button type="button" class="adv-btn-secondary" id="adv_save_draft">Save as Draft</button>
                            <button type="submit" class="adv-btn-primary">Publish Advisory</button>
                        </div>
                    </form>
                </section>

                {{-- Manage Alerts --}}
                <section class="adv-section">
                    <div class="adv-section-header">
                        <h2>Manage Alerts</h2>
                        <p>Send and track push notifications sent to the Passenger Mobile App.</p>
                    </div>

                    <div class="adv-filter-row">
                        <select class="adv-filter">
                            <option>All Severities</option>
                            <option>Information</option>
                            <option>Advisory</option>
                            <option>Warning</option>
                            <option>Critical</option>
                        </select>
                        <select class="adv-filter">
                            <option>All Status</option>
                            <option>Draft</option>
                            <option>Published</option>
                            <option>Archived</option>
                        </select>
                    </div>

                    <table class="adv-table" id="advAlertsTable">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Route</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Push Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="advAlertsBody">
                            <tr class="adv-empty-row" id="advEmptyRow">
                                <td colspan="7" class="adv-empty-cell">
                                    No advisories yet. Publish an advisory above to show it here.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="adv-note">
                        Note: Push notifications here represent calls to your future mobile API (for example Firebase Cloud Messaging)
                        that you will integrate later in your thesis implementation.
                    </p>
                </section>
            </div>
        </div>
    </div>

    {{-- Advisory Details Modal Overlay --}}
    <div id="advModalOverlay" class="adv-modal-overlay">
        <div class="adv-modal-card">
            <div class="adv-modal-header">
                <div class="adv-modal-header-left">
                    <div class="adv-modal-icon-badge">📣</div>
                    <div>
                        <h3 id="advModalTitle" class="adv-modal-title">Advisory Title</h3>
                        <div class="adv-modal-badges" id="advModalBadges"></div>
                    </div>
                </div>
                <button type="button" class="adv-modal-close" id="advModalCloseBtn" aria-label="Close modal">&times;</button>
            </div>

            <div class="adv-modal-body">
                <div class="adv-modal-grid">
                    <div class="adv-modal-info-box">
                        <span class="adv-modal-label">ADVISORY TYPE</span>
                        <span class="adv-modal-value" id="advModalType">-</span>
                    </div>
                    <div class="adv-modal-info-box">
                        <span class="adv-modal-label">AFFECTED ROUTE</span>
                        <span class="adv-modal-value" id="advModalRoute">-</span>
                    </div>
                    <div class="adv-modal-info-box">
                        <span class="adv-modal-label">EFFECTIVE FROM</span>
                        <span class="adv-modal-value" id="advModalEffectiveFrom">-</span>
                    </div>
                    <div class="adv-modal-info-box">
                        <span class="adv-modal-label">UNTIL</span>
                        <span class="adv-modal-value" id="advModalUntil">-</span>
                    </div>
                </div>

                <div class="adv-modal-message-section">
                    <span class="adv-modal-label">ADVISORY MESSAGE / DETAILS</span>
                    <div class="adv-modal-message-card" id="advModalMessage">
                        No additional details provided.
                    </div>
                </div>
            </div>

            <div class="adv-modal-footer">
                <button type="button" class="adv-btn-secondary" id="advModalCloseFooterBtn">Close Details</button>
            </div>
        </div>
    </div>
@endsection

