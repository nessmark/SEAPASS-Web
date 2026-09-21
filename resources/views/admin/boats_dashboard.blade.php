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
        <div class="page-body">
            <div class="boats-header">
                <div>
                    <div class="card-title">Fleet Management</div>
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
                                <small style="color: var(--text-2);">Max size: 2MB (JPEG, PNG, JPG, GIF)</small>
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
                                <small style="color: var(--text-2);">Max size: 2MB (JPEG, PNG, JPG, GIF)</small>
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
                    @php
                        $status = $boat->status ?? 'Active';
                        // The controller accepts exactly these three. Anything else in the
                        // column is legacy data, so surface it instead of letting the select
                        // fall back to its first option and silently overwrite the real value.
                        $boatStatuses = ['Active', 'Under Maintenance', 'Out of Service'];
                        $known = in_array($status, $boatStatuses, true);
                        $statusKey = match (true) {
                            $status === 'Active' => 'active',
                            $status === 'Under Maintenance' => 'maintenance',
                            $status === 'Out of Service' => 'out',
                            default => 'unknown',
                        };
                        $registry = array_filter([$boat->operator, $boat->owner]);
                    @endphp
                    <div class="boat-card boat-card-{{ $statusKey }}">
                        {{-- A fleet page is about boats, so the photo is the card, not a 40px chip. --}}
                        <div class="boat-banner">
                            @if($boat->image)
                                <img src="{{ asset('Boats_images/' . $boat->image) }}" alt="{{ $boat->name }}" class="boat-image"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            @endif
                            <div class="boat-icon" @if($boat->image) style="display: none;" @endif>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17.5c1.5 1 2.5 1 4 0s2.5-1 4 0 2.5 1 4 0 2.5-1 4 0"/><path d="M5 15V9.5a1 1 0 0 1 .6-.92l5.8-2.5a1 1 0 0 1 .8 0l5.8 2.5a1 1 0 0 1 .6.92V15"/><path d="M12 6V3"/></svg>
                            </div>
                            <span class="boat-chip boat-chip-{{ $statusKey }}">{{ $status }}</span>
                        </div>

                        <div class="boat-body">
                            <div class="boat-ident">
                                <span class="boat-name" title="{{ $boat->name }}">{{ $boat->name }}</span>
                                @if($boat->license_number)
                                    <span class="boat-license">{{ $boat->license_number }}</span>
                                @endif
                            </div>

                            {{-- Capacity drives every schedule on this system, so it is the number. --}}
                            <div class="boat-capacity">
                                <span class="boat-capacity-value">{{ $boat->passenger_capacity ?? '-' }}</span>
                                <span class="boat-capacity-unit">seats</span>
                                @if($boat->boat_number)
                                    <span class="boat-hull">{{ $boat->boat_number }}</span>
                                @endif
                            </div>

                            @if($registry)
                                <div class="boat-registry">
                                    @foreach($registry as $entry)
                                        <span title="{{ $entry }}">{{ $entry }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="boat-actions">
                            <form method="POST" action="{{ route('admin.boats.updateStatus', $boat) }}" class="boat-status-form">
                                @csrf
                                @method('PATCH')
                                <select name="status" onchange="this.form.submit()" class="boat-status-select" aria-label="Status for {{ $boat->name }}">
                                    @unless($known)
                                        <option value="{{ $status }}" selected disabled>{{ $status }}</option>
                                    @endunless
                                    @foreach($boatStatuses as $option)
                                        <option value="{{ $option }}" {{ $status === $option ? 'selected' : '' }}>{{ $option }}</option>
                                    @endforeach
                                </select>
                            </form>
                            <button class="boat-act" type="button"
                                    onclick="openEditModal({{ $boat->id }}, '{{ addslashes($boat->name) }}', '{{ addslashes($boat->license_number ?? '') }}', '{{ $boat->passenger_capacity ?? '' }}', '{{ addslashes($boat->owner ?? '') }}', '{{ addslashes($boat->operator ?? '') }}', '{{ addslashes($boat->boat_number ?? '') }}', '{{ $status }}', '{{ $boat->image ? asset('Boats_images/' . $boat->image) : '' }}')">
                                <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M14.5 6.5l3 3"/></svg>
                                Edit
                            </button>
                            <form method="POST" action="{{ route('admin.boats.destroy', $boat) }}" class="boat-act-destroy">
                                @csrf
                                @method('DELETE')
                                <button class="boat-act boat-act-danger" type="submit" aria-label="Delete {{ $boat->name }}"
                                        onclick="return confirm('Delete {{ addslashes($boat->name) }} from the fleet?');">
                                    <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M10 11v6M14 11v6"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="boats-empty">No boats yet. Add the first one to start scheduling trips.</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection

