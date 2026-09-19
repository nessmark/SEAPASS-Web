@extends('layouts.admin')

@section('title', 'Fleet Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/boats.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/boats.js') }}"></script>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="boats-header">
                <div>
                    <div class="card-title">Fleet Management</div>
                    <div class="card-subtitle">Manage lancha vessels - Add, Edit, Delete boats and set status</div>
                </div>

                <button class="boats-button" type="button" id="openBoatModal">Add Boat</button>
            </div>

            @if (session('success'))
                <div class="alert">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert error">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- Add Boat Modal --}}
            <div class="boat-modal-backdrop" id="boatModalBackdrop" style="display: none;">
                <div class="boat-modal" role="dialog" aria-modal="true" aria-labelledby="boatModalTitle">
                    <div class="boat-modal-header">
                        <h2 id="boatModalTitle">Add Boat</h2>
                        <button type="button" class="boat-modal-close" id="closeBoatModal" aria-label="Close">×</button>
                    </div>
                    <form method="POST" action="{{ route('admin.boats.store') }}" class="boat-modal-form" enctype="multipart/form-data">
                        @csrf
                        <div class="boat-modal-grid">
                            <div class="boat-field">
                                <label>Boat Name <span style="color: red;">*</span></label>
                                <input type="text" name="name" value="{{ old('name') }}" required maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>License Number</label>
                                <input type="text" name="license_number" value="{{ old('license_number') }}" maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>Passenger Capacity</label>
                                <input type="number" name="passenger_capacity" value="{{ old('passenger_capacity') }}" min="0" placeholder="Capacity">
                            </div>
                            <div class="boat-field">
                                <label>Owner</label>
                                <input type="text" name="owner" value="{{ old('owner') }}" maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>Operator</label>
                                <input type="text" name="operator" value="{{ old('operator') }}" maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>Boat Number</label>
                                <input type="text" name="boat_number" value="{{ old('boat_number') }}" maxlength="50">
                            </div>
                            <div class="boat-field">
                                <label>Image</label>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/jpg,image/gif">
                                <small style="color: var(--text-secondary);">Max size: 2MB (JPEG, PNG, JPG, GIF)</small>
                            </div>
                            <div class="boat-field">
                                <label>Status <span style="color: red;">*</span></label>
                                <select name="status" required>
                                    <option value="Active" {{ old('status', 'Active') === 'Active' ? 'selected' : '' }}>Active</option>
                                    <option value="Under Maintenance" {{ old('status') === 'Under Maintenance' ? 'selected' : '' }}>Under Maintenance</option>
                                    <option value="Out of Service" {{ old('status') === 'Out of Service' ? 'selected' : '' }}>Out of Service</option>
                                </select>
                            </div>
                        </div>
                        <div class="boat-modal-actions">
                            <button type="button" class="boat-modal-cancel" id="cancelBoatModal">Cancel</button>
                            <button type="submit" class="boat-modal-save">Save Boat</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Edit Boat Modal --}}
            <div class="boat-modal-backdrop" id="editBoatModalBackdrop" style="display: none;">
                <div class="boat-modal" role="dialog" aria-modal="true" aria-labelledby="editBoatModalTitle">
                    <div class="boat-modal-header">
                        <h2 id="editBoatModalTitle">Edit Boat</h2>
                        <button type="button" class="boat-modal-close" id="closeEditBoatModal" aria-label="Close">×</button>
                    </div>
                    <form method="POST" id="editBoatForm" class="boat-modal-form" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <div class="boat-modal-grid">
                            <div class="boat-field">
                                <label>Boat Name <span style="color: red;">*</span></label>
                                <input type="text" name="name" id="edit_name" required maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>License Number</label>
                                <input type="text" name="license_number" id="edit_license_number" maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>Passenger Capacity</label>
                                <input type="number" name="passenger_capacity" id="edit_passenger_capacity" min="0" placeholder="Capacity">
                            </div>
                            <div class="boat-field">
                                <label>Owner</label>
                                <input type="text" name="owner" id="edit_owner" maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>Operator</label>
                                <input type="text" name="operator" id="edit_operator" maxlength="100">
                            </div>
                            <div class="boat-field">
                                <label>Boat Number</label>
                                <input type="text" name="boat_number" id="edit_boat_number" maxlength="50">
                            </div>
                            <div class="boat-field">
                                <label>Image</label>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/jpg,image/gif">
                                <small style="color: var(--text-secondary);">Max size: 2MB (JPEG, PNG, JPG, GIF)</small>
                                <div id="edit_current_image" style="margin-top: 8px;"></div>
                            </div>
                            <div class="boat-field">
                                <label>Status <span style="color: red;">*</span></label>
                                <select name="status" id="edit_status" required>
                                    <option value="Active">Active</option>
                                    <option value="Under Maintenance">Under Maintenance</option>
                                    <option value="Out of Service">Out of Service</option>
                                </select>
                            </div>
                        </div>
                        <div class="boat-modal-actions">
                            <button type="button" class="boat-modal-cancel" id="cancelEditBoatModal">Cancel</button>
                            <button type="submit" class="boat-modal-save">Update Boat</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="boats-grid">
                @forelse ($boats as $boat)
                    <div class="boat-card">
                        <div class="boat-actions">
                            <button class="boat-edit" type="button" onclick="openEditModal({{ $boat->id }}, '{{ addslashes($boat->name) }}', '{{ addslashes($boat->license_number ?? '') }}', '{{ $boat->passenger_capacity ?? '' }}', '{{ addslashes($boat->owner ?? '') }}', '{{ addslashes($boat->operator ?? '') }}', '{{ addslashes($boat->boat_number ?? '') }}', '{{ $boat->status ?? 'Active' }}', '{{ $boat->image ? asset('Boats_images/' . $boat->image) : '' }}')" aria-label="Edit {{ $boat->name }}" title="Edit">
                                ✏️
                            </button>
                            <form method="POST" action="{{ route('admin.boats.destroy', $boat) }}" style="display: inline;">
                                @csrf
                                @method('DELETE')
                                <button class="boat-delete" type="submit" aria-label="Delete {{ $boat->name }}" title="Delete" onclick="return confirm('Are you sure you want to delete this boat?');">
                                    🗑️
                                </button>
                            </form>
                        </div>
                        
                        <div class="boat-meta">
                            @if($boat->image)
                                <div class="boat-image-container">
                                    <img src="{{ asset('Boats_images/' . $boat->image) }}" alt="{{ $boat->name }}" class="boat-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="boat-icon" style="display: none;">🚤</div>
                                </div>
                            @else
                                <div class="boat-icon">🚤</div>
                            @endif
                            <div class="boat-details">
                                <div class="boat-name" title="{{ $boat->name }}">{{ $boat->name }}</div>
                                @if($boat->license_number)
                                <div class="boat-detail-row">
                                    <span class="boat-detail-label">License No.:</span>
                                    <span class="boat-detail-value">{{ $boat->license_number }}</span>
                                </div>
                                @endif
                                <div class="boat-detail-row">
                                    <span class="boat-detail-label">Capacity:</span>
                                    <span class="boat-detail-value">{{ $boat->passenger_capacity ?? '—' }} passengers</span>
                                </div>
                                @if($boat->owner)
                                <div class="boat-detail-row">
                                    <span class="boat-detail-label">Owner:</span>
                                    <span class="boat-detail-value">{{ $boat->owner }}</span>
                                </div>
                                @endif
                                @if($boat->operator)
                                <div class="boat-detail-row">
                                    <span class="boat-detail-label">Operator:</span>
                                    <span class="boat-detail-value">{{ $boat->operator }}</span>
                                </div>
                                @endif
                                @if($boat->boat_number)
                                <div class="boat-detail-row">
                                    <span class="boat-detail-label">Boat No.:</span>
                                    <span class="boat-detail-value">{{ $boat->boat_number }}</span>
                                </div>
                                @endif

                                <div class="boat-detail-row boat-status-row">
                                    <span class="boat-detail-label">Status:</span>
                                    <form method="POST"
                                          action="{{ route('admin.boats.updateStatus', $boat) }}"
                                          class="boat-status-form">
                                        @csrf
                                        @method('PATCH')
                                        <select name="status" onchange="this.form.submit()" class="boat-status-select">
                                            <option value="Active" {{ ($boat->status ?? 'Active') === 'Active' ? 'selected' : '' }}>
                                                Active
                                            </option>
                                            <option value="Under Maintenance" {{ ($boat->status ?? 'Active') === 'Under Maintenance' ? 'selected' : '' }}>
                                                Under Maintenance
                                            </option>
                                            <option value="Out of Service" {{ ($boat->status ?? 'Active') === 'Out of Service' ? 'selected' : '' }}>
                                                Out of Service
                                            </option>
                                        </select>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div style="color: var(--text-secondary); padding: 8px 2px;">
                        No boats yet. Add your first boat using the form above.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endsection

