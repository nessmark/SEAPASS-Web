@extends('layouts.admin')

@section('title', 'Crew/Staff Accounts')

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
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .crew-table th {
            background-color: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
        }
        .crew-table tr:hover {
            background-color: var(--bg-tertiary);
        }
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .btn-edit, .btn-delete {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }
        .btn-edit {
            background-color: var(--accent-color);
            color: var(--bg-primary);
        }
        .btn-delete {
            background-color: #dc3545;
            color: white;
        }
        .btn-edit:hover {
            opacity: 0.9;
        }
        .btn-delete:hover {
            opacity: 0.9;
        }
    </style>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="boats-header">
                <div>
                    <div class="card-title">Crew/Staff Accounts</div>
                    <div class="card-subtitle">Create logins for boat crew or ticket sellers</div>
                </div>
                <button class="boats-button" type="button" id="openCrewModal">Add Crew/Staff</button>
            </div>

            @if (session('success'))
                <div class="alert">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert error">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- Add Crew/Staff Modal --}}
            <div class="boat-modal-backdrop" id="crewModalBackdrop" style="display: none;">
                <div class="boat-modal" role="dialog" aria-modal="true" aria-labelledby="crewModalTitle">
                    <div class="boat-modal-header">
                        <h2 id="crewModalTitle">Add Crew/Staff Account</h2>
                        <button type="button" class="boat-modal-close" id="closeCrewModal" aria-label="Close">×</button>
                    </div>
                    <form method="POST" action="{{ route('admin.user-management.crew-staff.store') }}" class="boat-modal-form">
                        @csrf
                        <div class="boat-modal-grid">
                            <div class="boat-field">
                                <label>Name <span style="color: red;">*</span></label>
                                <input type="text" name="name" value="{{ old('name') }}" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Email <span style="color: red;">*</span></label>
                                <input type="email" name="email" value="{{ old('email') }}" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Password <span style="color: red;">*</span></label>
                                <input type="password" name="password" required minlength="8">
                            </div>
                            <div class="boat-field">
                                <label>Confirm Password <span style="color: red;">*</span></label>
                                <input type="password" name="password_confirmation" required minlength="8">
                            </div>
                            <div class="boat-field">
                                <label>Role <span style="color: red;">*</span></label>
                                <select name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="crew" {{ old('role') === 'crew' ? 'selected' : '' }}>Crew</option>
                                    <option value="staff" {{ old('role') === 'staff' ? 'selected' : '' }}>Staff</option>
                                    <option value="ticket_seller" {{ old('role') === 'ticket_seller' ? 'selected' : '' }}>Ticket Seller</option>
                                </select>
                            </div>
                        </div>
                        <div class="boat-modal-actions">
                            <button type="button" class="boat-modal-cancel" id="cancelCrewModal">Cancel</button>
                            <button type="submit" class="boat-modal-save">Create Account</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Edit Crew/Staff Modal --}}
            <div class="boat-modal-backdrop" id="editCrewModalBackdrop" style="display: none;">
                <div class="boat-modal" role="dialog" aria-modal="true" aria-labelledby="editCrewModalTitle">
                    <div class="boat-modal-header">
                        <h2 id="editCrewModalTitle">Edit Crew/Staff Account</h2>
                        <button type="button" class="boat-modal-close" id="closeEditCrewModal" aria-label="Close">×</button>
                    </div>
                    <form method="POST" id="editCrewForm" class="boat-modal-form">
                        @csrf
                        @method('PUT')
                        <div class="boat-modal-grid">
                            <div class="boat-field">
                                <label>Name <span style="color: red;">*</span></label>
                                <input type="text" name="name" id="editName" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Email <span style="color: red;">*</span></label>
                                <input type="email" name="email" id="editEmail" required maxlength="255">
                            </div>
                            <div class="boat-field">
                                <label>Password (leave blank to keep current)</label>
                                <input type="password" name="password" minlength="8">
                            </div>
                            <div class="boat-field">
                                <label>Confirm Password</label>
                                <input type="password" name="password_confirmation" minlength="8">
                            </div>
                            <div class="boat-field">
                                <label>Role <span style="color: red;">*</span></label>
                                <select name="role" id="editRole" required>
                                    <option value="">Select Role</option>
                                    <option value="crew">Crew</option>
                                    <option value="staff">Staff</option>
                                    <option value="ticket_seller">Ticket Seller</option>
                                </select>
                            </div>
                        </div>
                        <div class="boat-modal-actions">
                            <button type="button" class="boat-modal-cancel" id="cancelEditCrewModal">Cancel</button>
                            <button type="submit" class="boat-modal-save">Update Account</button>
                        </div>
                    </form>
                </div>
            </div>

            @if($crewStaff->isEmpty())
                <div class="empty-state" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                    <p>No crew/staff accounts found.</p>
                </div>
            @else
                <table class="crew-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($crewStaff as $user)
                            <tr>
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    <span style="text-transform: capitalize;">{{ $user->role ?? 'N/A' }}</span>
                                </td>
                                <td>{{ $user->created_at->format('M d, Y') }}</td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-edit" onclick="editCrewStaff({{ $user->id }}, '{{ $user->name }}', '{{ $user->email }}', '{{ $user->role ?? '' }}')">Edit</button>
                                        <form method="POST" action="{{ route('admin.user-management.crew-staff.destroy', $user) }}" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this account?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-delete">Delete</button>
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

    @push('scripts')
        <script>
            // Modal controls
            const openCrewModal = document.getElementById('openCrewModal');
            const crewModalBackdrop = document.getElementById('crewModalBackdrop');
            const closeCrewModal = document.getElementById('closeCrewModal');
            const cancelCrewModal = document.getElementById('cancelCrewModal');

            const editCrewModalBackdrop = document.getElementById('editCrewModalBackdrop');
            const closeEditCrewModal = document.getElementById('closeEditCrewModal');
            const cancelEditCrewModal = document.getElementById('cancelEditCrewModal');

            if (openCrewModal) {
                openCrewModal.addEventListener('click', () => {
                    crewModalBackdrop.style.display = 'flex';
                });
            }

            if (closeCrewModal) {
                closeCrewModal.addEventListener('click', () => {
                    crewModalBackdrop.style.display = 'none';
                });
            }

            if (cancelCrewModal) {
                cancelCrewModal.addEventListener('click', () => {
                    crewModalBackdrop.style.display = 'none';
                });
            }

            if (closeEditCrewModal) {
                closeEditCrewModal.addEventListener('click', () => {
                    editCrewModalBackdrop.style.display = 'none';
                });
            }

            if (cancelEditCrewModal) {
                cancelEditCrewModal.addEventListener('click', () => {
                    editCrewModalBackdrop.style.display = 'none';
                });
            }

            // Close on backdrop click
            if (crewModalBackdrop) {
                crewModalBackdrop.addEventListener('click', (e) => {
                    if (e.target === crewModalBackdrop) {
                        crewModalBackdrop.style.display = 'none';
                    }
                });
            }

            if (editCrewModalBackdrop) {
                editCrewModalBackdrop.addEventListener('click', (e) => {
                    if (e.target === editCrewModalBackdrop) {
                        editCrewModalBackdrop.style.display = 'none';
                    }
                });
            }

            function editCrewStaff(id, name, email, role) {
                document.getElementById('editName').value = name;
                document.getElementById('editEmail').value = email;
                document.getElementById('editRole').value = role;
                document.getElementById('editCrewForm').action = '{{ url("admin/user-management/crew-staff") }}/' + id;
                editCrewModalBackdrop.style.display = 'flex';
            }
        </script>
    @endpush
@endsection
