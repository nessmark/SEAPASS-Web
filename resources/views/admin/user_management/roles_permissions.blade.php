@extends('layouts.admin')

@section('title', 'Roles & Permissions')

@push('styles')
    <style>
        .roles-permissions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        .permissions-card {
            background-color: var(--bg-secondary);
            border-radius: 12px;
            padding: 20px;
        }
        .permissions-card h3 {
            margin-bottom: 15px;
            color: var(--text-primary);
        }
        .permission-item {
            padding: 8px 0;
            color: var(--text-secondary);
            font-size: 14px;
        }
        .permission-item::before {
            content: "✓ ";
            color: var(--accent-color);
            font-weight: bold;
        }
        .user-role-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .user-role-table th,
        .user-role-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .user-role-table th {
            background-color: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
        }
        .user-role-table tr:hover {
            background-color: var(--bg-tertiary);
        }
        .role-select {
            padding: 6px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            cursor: pointer;
        }
        .role-select:focus {
            outline: none;
            border-color: var(--accent-color);
        }
        @media (max-width: 900px) {
            .roles-permissions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="card-title">Roles & Permissions</div>
            <div class="card-subtitle">Define who can edit schedules and who can only view reports</div>

            @if (session('success'))
                <div class="alert" style="margin-top: 15px;">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert error" style="margin-top: 15px;">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="roles-permissions-grid">
                @foreach($roles as $roleKey => $roleData)
                    <div class="permissions-card">
                        <h3>{{ $roleData['name'] }}</h3>
                        @foreach($roleData['permissions'] as $permission)
                            <div class="permission-item">{{ ucfirst(str_replace('_', ' ', $permission)) }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div style="margin-top: 30px;">
                <h3 style="margin-bottom: 15px; color: var(--text-primary);">User Roles</h3>
                
                @if($users->isEmpty())
                    <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                        <p>No users found.</p>
                    </div>
                @else
                    <table class="user-role-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Current Role</th>
                                <th>Change Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $user)
                                <tr>
                                    <td>{{ $user->name }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td>
                                        <span style="text-transform: capitalize; padding: 4px 12px; border-radius: 12px; background-color: var(--accent-color); color: var(--bg-primary); font-size: 12px;">
                                            {{ $user->role ?? 'N/A' }}
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.user-management.roles-permissions.update', $user) }}" style="display: inline;">
                                            @csrf
                                            @method('PUT')
                                            <select name="role" class="role-select" onchange="this.form.submit()">
                                                <option value="">Select Role</option>
                                                <option value="admin" {{ ($user->role ?? '') === 'admin' ? 'selected' : '' }}>Administrator</option>
                                                <option value="crew" {{ ($user->role ?? '') === 'crew' ? 'selected' : '' }}>Crew</option>
                                                <option value="staff" {{ ($user->role ?? '') === 'staff' ? 'selected' : '' }}>Staff</option>
                                                <option value="ticket_seller" {{ ($user->role ?? '') === 'ticket_seller' ? 'selected' : '' }}>Ticket Seller</option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
@endsection
