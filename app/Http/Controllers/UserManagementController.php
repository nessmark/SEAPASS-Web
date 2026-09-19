<?php

namespace App\Http\Controllers;

use App\Models\Passenger;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * UserManagementController
 * Handles user management operations including passengers, crew/staff accounts, and roles/permissions
 */
class UserManagementController extends Controller
{
    /**
     * Display passenger list (registered app users)
     *
     * @return \Illuminate\View\View
     */
    public function passengerList()
    {
        $passengers = Passenger::orderBy('created_at', 'desc')
            ->get();

        return view('admin.user_management.passenger_list', [
            'passengers' => $passengers,
        ]);
    }

    /**
     * Display crew/staff accounts management page
     *
     * @return \Illuminate\View\View
     */
    public function crewStaff()
    {
        // Get crew/staff users (assuming they have a role field or user_type field)
        $crewStaff = User::orderBy('created_at', 'desc')->get();
        
        return view('admin.user_management.crew_staff', [
            'crewStaff' => $crewStaff,
        ]);
    }

    /**
     * Store a new crew/staff account
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeCrewStaff(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|string|in:crew,staff,ticket_seller',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        return redirect()->route('admin.user-management.crew-staff')
            ->with('success', 'Crew/Staff account created successfully.');
    }

    /**
     * Update crew/staff account
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateCrewStaff(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|string|in:crew,staff,ticket_seller',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $user->name = $request->name;
        $user->email = $request->email;
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }
        $user->role = $request->role;
        $user->save();

        return redirect()->route('admin.user-management.crew-staff')
            ->with('success', 'Crew/Staff account updated successfully.');
    }

    /**
     * Delete crew/staff account
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyCrewStaff(User $user)
    {
        $user->delete();

        return redirect()->route('admin.user-management.crew-staff')
            ->with('success', 'Crew/Staff account deleted successfully.');
    }

    /**
     * Display roles & permissions management page
     *
     * @return \Illuminate\View\View
     */
    public function rolesPermissions()
    {
        $users = User::orderBy('name')->get();
        
        // Define available roles and their permissions
        $roles = [
            'admin' => [
                'name' => 'Administrator',
                'permissions' => ['view_reports', 'edit_schedules', 'manage_boats', 'manage_users', 'manage_fares'],
            ],
            'crew' => [
                'name' => 'Crew Member',
                'permissions' => ['view_reports'],
            ],
            'staff' => [
                'name' => 'Staff',
                'permissions' => ['view_reports', 'edit_schedules'],
            ],
            'ticket_seller' => [
                'name' => 'Ticket Seller',
                'permissions' => ['view_reports'],
            ],
        ];

        return view('admin.user_management.roles_permissions', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    /**
     * Update user role and permissions
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateRole(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role' => 'required|string|in:admin,crew,staff,ticket_seller',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $user->role = $request->role;
        $user->save();

        return redirect()->route('admin.user-management.roles-permissions')
            ->with('success', 'User role updated successfully.');
    }

    /**
     * Display profile page (moved from separate route)
     *
     * @return \Illuminate\View\View
     */
    public function profile()
    {
        $user = auth()->user();

        $memberSince = optional($user?->created_at)->format('F d, Y') ?? 'N/A';
        $usingFor = $user?->created_at ? now()->diffForHumans($user->created_at, true) : 'N/A';
        $initials = collect(explode(' ', (string) ($user?->name ?? 'A')))
            ->filter()
            ->map(fn ($part) => mb_substr($part, 0, 1))
            ->take(2)
            ->implode('');

        return view('admin.user_management.profile', [
            'user' => $user,
            'memberSince' => $memberSince,
            'usingFor' => $usingFor,
            'initials' => $initials,
        ]);
    }

    /**
     * Display Scanner Staff accounts management page
     *
     * @return \Illuminate\View\View
     */
    public function scannerStaff()
    {
        $scannerStaff = User::where('role', 'scanner')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.user_management.scanner_staff', [
            'scannerStaff' => $scannerStaff,
        ]);
    }

    /**
     * Store a new scanner staff account
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeScannerStaff(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'assigned_port' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'scanner',
            'assigned_port' => $request->assigned_port ?: 'Surigao City Port',
            'is_active' => true,
        ]);

        return redirect()->route('admin.user-management.scanner-staff')
            ->with('success', 'Scanner Staff account created successfully.');
    }

    /**
     * Update scanner staff account
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateScannerStaff(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'assigned_port' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $user->name = $request->name;
        $user->email = $request->email;
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }
        if ($request->filled('assigned_port')) {
            $user->assigned_port = $request->assigned_port;
        }
        $user->save();

        return redirect()->route('admin.user-management.scanner-staff')
            ->with('success', 'Scanner Staff account updated successfully.');
    }

    /**
     * Delete scanner staff account
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyScannerStaff(User $user)
    {
        $user->delete();

        return redirect()->route('admin.user-management.scanner-staff')
            ->with('success', 'Scanner Staff account deleted successfully.');
    }

    /**
     * Toggle active/inactive status of scanner staff
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggleScannerStaff(User $user)
    {
        $user->is_active = !$user->is_active;
        $user->save();

        $statusText = $user->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.user-management.scanner-staff')
            ->with('success', "Scanner Staff account {$statusText} successfully.");
    }
}
