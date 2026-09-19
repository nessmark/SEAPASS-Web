@extends('layouts.admin')

@section('title', 'Scanner Staff Accounts')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/boats.css') }}">
    <style>
        .crew-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .crew-table th,
        .crew-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .crew-table th {
            background-color: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .crew-table tr:hover {
            background-color: var(--bg-tertiary);
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .btn-edit, .btn-delete, .btn-toggle {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-edit {
            background-color: var(--accent-color);
            color: var(--bg-primary);
        }
        .btn-delete {
            background-color: #dc3545;
            color: white;
        }
        .btn-toggle-deactivate {
            background-color: #f59e0b;
            color: white;
        }
        .btn-toggle-activate {
            background-color: #10b981;
            color: white;
        }
        .btn-edit:hover, .btn-delete:hover, .btn-toggle:hover {
            opacity: 0.88;
            transform: translateY(-1px);
        }
        .badge-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-active {
            background-color: #d1fae5;
            color: #065f46;
        }
        .badge-inactive {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .port-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background-color: var(--bg-tertiary);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
        }
    </style>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="boats-header">
                <div>
                    <div class="card-title">Scanner Staff Accounts</div>
                    <div class="card-subtitle">Manage gate scanners and boarding verification personnel for ports &amp; vessels</div>
                </div>
                <button class="boats-button" type="button" id="openScannerModal">
                    <span>+ Add Scanner Staff</span>
                </button>
            </div>

            @if (session('success'))
                <div class="alert" style="background-color: #d1fae5; color: #065f46; border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 6px; margin-top: 16px;">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert error" style="background-color: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; padding: 12px 16px; border-radius: 6px; margin-top: 16px;">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- Add Scanner Staff Modal --}}
            <div class="boat-modal-backdrop" id="scannerModalBackdrop" style="display: none;">
                <div class="boat-modal" role="dialog" aria-modal="true" aria-labelledby="scannerModalTitle">
                    <div class="boat-modal-header">
                        <h2 id="scannerModalTitle">Add Scanner Staff Account</h2>
                        <button type="button" class="boat-modal-close" id="closeScannerModal" aria-label="Close">×</button>
                    </div>
                    <form method="POST" action="{{ route('admin.user-management.scanner-staff.store') }}" class="boat-modal-form">
                        @csrf
                        <div class="boat-modal-grid">
                            <div class="boat-field">
                                <label>Full Name <span style="color: red;">*</span></label>
                                <input type="text" name="name" value="{{ old('name') }}" placeholder="e.g., Gate Officer Alex" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Email Address <span style="color: red;">*</span></label>
                                <input type="email" name="email" value="{{ old('email') }}" placeholder="scanner@seapass.ph" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Assigned Port / Terminal</label>
                                <select name="assigned_port">
                                    <option value="Surigao City Port" {{ old('assigned_port') === 'Surigao City Port' ? 'selected' : '' }}>Surigao City Port</option>
                                    <option value="San Jose Port (Dinagat)" {{ old('assigned_port') === 'San Jose Port (Dinagat)' ? 'selected' : '' }}>San Jose Port (Dinagat)</option>
                                    <option value="All Ports" {{ old('assigned_port') === 'All Ports (Floating Crew)' ? 'selected' : '' }}>All Ports (Floating Crew)</option>
                                </select>
                            </div>
                            <div class="boat-field">
                                <label>Password <span style="color: red;">*</span></label>
                                <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters">
                            </div>
                            <div class="boat-field">
                                <label>Confirm Password <span style="color: red;">*</span></label>
                                <input type="password" name="password_confirmation" required minlength="8" placeholder="Repeat password">
                            </div>
                        </div>
                        <div class="boat-modal-actions">
                            <button type="button" class="boat-modal-cancel" id="cancelScannerModal">Cancel</button>
                            <button type="submit" class="boat-modal-save">Create Scanner Account</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Edit Scanner Staff Modal --}}
            <div class="boat-modal-backdrop" id="editScannerModalBackdrop" style="display: none;">
                <div class="boat-modal" role="dialog" aria-modal="true" aria-labelledby="editScannerModalTitle">
                    <div class="boat-modal-header">
                        <h2 id="editScannerModalTitle">Edit Scanner Staff Account</h2>
                        <button type="button" class="boat-modal-close" id="closeEditScannerModal" aria-label="Close">×</button>
                    </div>
                    <form method="POST" id="editScannerForm" class="boat-modal-form">
                        @csrf
                        @method('PUT')
                        <div class="boat-modal-grid">
                            <div class="boat-field">
                                <label>Full Name <span style="color: red;">*</span></label>
                                <input type="text" name="name" id="editName" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Email Address <span style="color: red;">*</span></label>
                                <input type="email" name="email" id="editEmail" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Assigned Port / Terminal</label>
                                <select name="assigned_port" id="editAssignedPort">
                                    <option value="Surigao City Port">Surigao City Port</option>
                                    <option value="San Jose Port (Dinagat)">San Jose Port (Dinagat)</option>
                                    <option value="All Ports (Floating Crew)">All Ports (Floating Crew)</option>
                                </select>
                            </div>
                            <div class="boat-field">
                                <label>New Password (leave blank to keep current)</label>
                                <input type="password" name="password" minlength="8" placeholder="Leave empty to keep current">
                            </div>
                            <div class="boat-field">
                                <label>Confirm New Password</label>
                                <input type="password" name="password_confirmation" minlength="8" placeholder="Repeat new password">
                            </div>
                        </div>
                        <div class="boat-modal-actions">
                            <button type="button" class="boat-modal-cancel" id="cancelEditScannerModal">Cancel</button>
                            <button type="submit" class="boat-modal-save">Update Scanner Account</button>
                        </div>
                    </form>
                </div>
            </div>

            @if($scannerStaff->isEmpty())
                <div class="empty-state" style="text-align: center; padding: 48px 20px; color: var(--text-secondary);">
                    <div style="font-size: 40px; margin-bottom: 12px;">📷</div>
                    <h3 style="margin: 0 0 6px 0; color: var(--text-primary);">No Scanner Staff Accounts Found</h3>
                    <p style="margin: 0 0 18px 0; font-size: 14px;">Create scanner accounts for terminal staff to verify tickets and QR boarding passes in the mobile app.</p>
                    <button class="boats-button" type="button" onclick="document.getElementById('openScannerModal').click()">
                        + Add First Scanner Account
                    </button>
                </div>
            @else
                <table class="crew-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Assigned Port / Terminal</th>
                            <th>Status</th>
                            <th>Created Date</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($scannerStaff as $user)
                            <tr>
                                <td>
                                    <strong>{{ $user->name }}</strong>
                                </td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    <span class="port-badge">
                                        📍 {{ $user->assigned_port ?: 'Surigao City Port' }}
                                    </span>
                                </td>
                                <td>
                                    @if($user->is_active ?? true)
                                        <span class="badge-status badge-active">Active</span>
                                    @else
                                        <span class="badge-status badge-inactive">Inactive</span>
                                    @endif
                                </td>
                                <td>{{ $user->created_at ? $user->created_at->format('M d, Y') : 'N/A' }}</td>
                                <td>
                                    <div class="action-buttons" style="justify-content: flex-end;">
                                        {{-- Edit Button --}}
                                        <button class="btn-edit" onclick="editScannerStaff({{ $user->id }}, '{{ addslashes($user->name) }}', '{{ addslashes($user->email) }}', '{{ addslashes($user->assigned_port ?? 'Surigao City Port') }}')">
                                            Edit
                                        </button>

                                        {{-- Toggle Status Button --}}
                                        <form method="POST" action="{{ route('admin.user-management.scanner-staff.toggle', $user) }}" style="display: inline;">
                                            @csrf
                                            @method('PATCH')
                                            @if($user->is_active ?? true)
                                                <button type="submit" class="btn-toggle btn-toggle-deactivate" title="Deactivate this scanner account">
                                                    Deactivate
                                                </button>
                                            @else
                                                <button type="submit" class="btn-toggle btn-toggle-activate" title="Activate this scanner account">
                                                    Activate
                                                </button>
                                            @endif
                                        </form>

                                        {{-- Delete Button --}}
                                        <form method="POST" action="{{ route('admin.user-management.scanner-staff.destroy', $user) }}" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete scanner account for {{ addslashes($user->name) }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-delete" title="Delete account">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add Modal controls
            const openScannerModal = document.getElementById('openScannerModal');
            const closeScannerModal = document.getElementById('closeScannerModal');
            const cancelScannerModal = document.getElementById('cancelScannerModal');
            const scannerModalBackdrop = document.getElementById('scannerModalBackdrop');

            function showScannerModal() {
                if (scannerModalBackdrop) scannerModalBackdrop.style.display = 'flex';
            }
            function hideScannerModal() {
                if (scannerModalBackdrop) scannerModalBackdrop.style.display = 'none';
            }

            if (openScannerModal) openScannerModal.addEventListener('click', showScannerModal);
            if (closeScannerModal) closeScannerModal.addEventListener('click', hideScannerModal);
            if (cancelScannerModal) cancelScannerModal.addEventListener('click', hideScannerModal);

            // Edit Modal controls
            const closeEditScannerModal = document.getElementById('closeEditScannerModal');
            const cancelEditScannerModal = document.getElementById('cancelEditScannerModal');
            const editScannerModalBackdrop = document.getElementById('editScannerModalBackdrop');

            function hideEditScannerModal() {
                if (editScannerModalBackdrop) editScannerModalBackdrop.style.display = 'none';
            }

            if (closeEditScannerModal) closeEditScannerModal.addEventListener('click', hideEditScannerModal);
            if (cancelEditScannerModal) cancelEditScannerModal.addEventListener('click', hideEditScannerModal);

            // Close when clicking outside modal
            window.addEventListener('click', function(e) {
                if (e.target === scannerModalBackdrop) hideScannerModal();
                if (e.target === editScannerModalBackdrop) hideEditScannerModal();
            });
        });

        function editScannerStaff(id, name, email, assignedPort) {
            document.getElementById('editName').value = name;
            document.getElementById('editEmail').value = email;
            
            const portSelect = document.getElementById('editAssignedPort');
            if (portSelect) {
                let matched = false;
                for (let i = 0; i < portSelect.options.length; i++) {
                    if (portSelect.options[i].value === assignedPort) {
                        portSelect.selectedIndex = i;
                        matched = true;
                        break;
                    }
                }
                if (!matched && assignedPort) {
                    const opt = document.createElement('option');
                    opt.value = assignedPort;
                    opt.textContent = assignedPort;
                    opt.selected = true;
                    portSelect.appendChild(opt);
                }
            }

            document.getElementById('editScannerForm').action = '{{ url("admin/user-management/ScannerStaff") }}/' + id;
            document.getElementById('editScannerModalBackdrop').style.display = 'flex';
        }
    </script>
@endpush
