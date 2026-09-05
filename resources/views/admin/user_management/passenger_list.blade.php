@extends('layouts.admin')

@section('title', 'Passenger List')

@push('styles')
    <style>
        .passenger-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .passenger-table th,
        .passenger-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .passenger-table th {
            background-color: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
        }
        .passenger-table tr:hover {
            background-color: var(--bg-tertiary);
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }
        .search-filter {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .search-filter input {
            flex: 1;
            min-width: 250px;
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
        }
    </style>
@endpush

@section('content')
    <div class="dashboard-grid full-width">
        <div class="card">
            <div class="card-title">Passenger List</div>
            <div class="card-subtitle">View registered app users</div>

            @if (session('success'))
                <div class="alert" style="margin-top: 15px;">{{ session('success') }}</div>
            @endif

            <div class="search-filter">
                <input type="text" id="searchInput" placeholder="Search by name or email..." onkeyup="filterTable()">
            </div>

            @if($passengers->isEmpty())
                <div class="empty-state">
                    <p>No passengers found.</p>
                </div>
            @else
                <table class="passenger-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Registered Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="passengerTableBody">
                        @foreach($passengers as $passenger)
                            <tr>
                                <td>{{ $passenger->name }}</td>
                                <td>{{ $passenger->email }}</td>
                                <td>{{ $passenger->created_at->format('M d, Y') }}</td>
                                <td>
                                    <span style="padding: 4px 12px; border-radius: 12px; background-color: var(--accent-color); color: var(--bg-primary); font-size: 12px;">
                                        Active
                                    </span>
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
            function filterTable() {
                const input = document.getElementById('searchInput');
                const filter = input.value.toLowerCase();
                const table = document.getElementById('passengerTableBody');
                const rows = table.getElementsByTagName('tr');

                for (let i = 0; i < rows.length; i++) {
                    const nameCell = rows[i].getElementsByTagName('td')[0];
                    const emailCell = rows[i].getElementsByTagName('td')[1];
                    
                    if (nameCell && emailCell) {
                        const nameText = nameCell.textContent || nameCell.innerText;
                        const emailText = emailCell.textContent || emailCell.innerText;
                        
                        if (nameText.toLowerCase().indexOf(filter) > -1 || emailText.toLowerCase().indexOf(filter) > -1) {
                            rows[i].style.display = '';
                        } else {
                            rows[i].style.display = 'none';
                        }
                    }
                }
            }
        </script>
    @endpush
@endsection
