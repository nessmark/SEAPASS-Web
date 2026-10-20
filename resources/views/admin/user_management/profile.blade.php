@extends('layouts.admin')
@section('title', 'Profile')
@section('content')
    <div class="workspace-heading"><div><h1>Profile</h1><p class="page-description">Your account details and access to the SeaPass workspace.</p></div></div>
    <section class="card profile-panel" aria-labelledby="profile-heading">
        <div class="profile-identity"><span class="profile-avatar">{{ $initials ?: 'A' }}</span><div><h2 id="profile-heading">{{ $user?->name ?? 'Admin' }}</h2><p>{{ $user?->email }}</p></div></div>
        <dl class="profile-details"><div><dt>Member since</dt><dd>{{ $memberSince }}</dd></div><div><dt>Account age</dt><dd>{{ $usingFor }}</dd></div><div><dt>Workspace role</dt><dd>{{ ucfirst($user?->role ?? 'Admin') }}</dd></div></dl>
    </section>
@endsection
