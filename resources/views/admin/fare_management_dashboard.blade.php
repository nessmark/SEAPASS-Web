@extends('layouts.admin')

@section('title', 'Fare Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fare-management.css') }}">
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="fare-header">
                <div>
                    <div class="card-title">Fare Management</div>
                    <div class="card-subtitle">
                        Set pricing by route for Regular, Student, and Senior Citizen passengers.
                    </div>
                </div>
            </div>

            {{-- Fare Matrix Section --}}
            <section class="fare-section">
                <h2 class="fare-section-title">Fare Matrix</h2>
                <p class="fare-section-desc">Configure fare per route and passenger type (Regular, Student, Senior Citizen).</p>

                <form class="fare-form" id="fareMatrixForm" method="POST" action="{{ route('admin.fare-management.store') }}">
                    @csrf
                    <div class="fare-table-wrap">
                        <table class="fare-table" aria-label="Fare matrix by route and passenger type">
                            <thead>
                                <tr>
                                    <th scope="col">Route</th>
                                    <th scope="col">Regular (₱)</th>
                                    <th scope="col">Student (₱)</th>
                                    <th scope="col">Senior Citizen (₱)</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="fareMatrixBody">
                                @foreach($fares ?? [] as $fare)
                                    <tr data-route="{{ $fare->route ?? '' }}">
                                        <td class="fare-route">{{ $fare->route ?? '' }}</td>
                                        <td>
                                            <input type="number" name="fares[{{ $fare->route ?? '' }}][regular]" value="{{ $fare->regular ?? '' }}" min="0" step="0.01" placeholder="0.00" class="fare-input" aria-label="Regular fare for {{ $fare->route ?? '' }}">
                                        </td>
                                        <td>
                                            <input type="number" name="fares[{{ $fare->route ?? '' }}][student]" value="{{ $fare->student ?? '' }}" min="0" step="0.01" placeholder="0.00" class="fare-input" aria-label="Student fare for {{ $fare->route ?? '' }}">
                                        </td>
                                        <td>
                                            <input type="number" name="fares[{{ $fare->route ?? '' }}][senior]" value="{{ $fare->senior ?? '' }}" min="0" step="0.01" placeholder="0.00" class="fare-input" aria-label="Senior citizen fare for {{ $fare->route ?? '' }}">
                                        </td>
                                        <td>
                                            <button type="button" class="fare-btn fare-btn-save" data-route="{{ $fare->route ?? '' }}">Save</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="fare-form-actions">
                        <button type="submit" class="fare-btn fare-btn-primary">Save All Fares</button>
                    </div>
                </form>
            </section>

            @if (session('success'))
                <div class="fare-alert fare-alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="fare-alert fare-alert-error">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="fare-alert fare-alert-error">{{ $errors->first() }}</div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/fare-management.js') }}"></script>
@endpush
