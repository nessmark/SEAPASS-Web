<?php

namespace App\Http\Controllers;

use App\Models\Passenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Validate passenger login from the mobile app.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function loginPassenger(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422)->header('Access-Control-Allow-Origin', '*');
        }

        $passenger = Passenger::where('email', $request->string('email')->lower()->toString())
            ->first();

        if (!$passenger || !Hash::check($request->string('password')->toString(), $passenger->password)) {
            return response()->json([
                'message' => 'Invalid Email or Password.',
            ], 401);
        }

        $token = $passenger->createToken('seapass-mobile-app')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'data' => [
                'id' => $passenger->id,
                'name' => $passenger->name,
                'email' => $passenger->email,
                'phone' => $passenger->phone ?? '',
                'token' => $token,
            ],
        ]);
    }

    /**
     * Send a 6-digit OTP code for passenger registration email verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255|unique:passengers,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $request->string('email')->lower()->toString();
        $otp = sprintf("%06d", mt_rand(100000, 999999));

        // Cache OTP for 10 minutes
        Cache::put('otp_' . $email, $otp, now()->addMinutes(10));

        // Log OTP code for debugging / local testing
        Log::info("SeaPass OTP generated for {$email}: {$otp}");

        // Send HTML email with embedded SeaPass logo via Mail facade
        try {
            Mail::send('emails.otp', ['otp' => $otp], function ($message) use ($email) {
                $message->to($email)
                        ->subject('SeaPass OTP Verification Code');
            });
        } catch (\Throwable $e) {
            Log::error("Failed to send OTP email to {$email}: " . $e->getMessage());
        }

        return response()->json([
            'message' => "A 6-digit OTP code has been sent to {$email}.",
        ], 200);
    }

    /**
     * Verify OTP code and create passenger account.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyAndRegister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:passengers,email',
            'password' => 'required|string|min:8|confirmed',
            'otp' => 'required|string|size:6',
            'phone' => 'required|string|min:10|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $request->string('email')->lower()->toString();
        $inputOtp = trim($request->string('otp')->toString());

        $cachedOtp = Cache::get('otp_' . $email);

        if (!$cachedOtp) {
            return response()->json([
                'message' => 'The OTP code has expired or is invalid. Please request a new code.',
            ], 422);
        }

        if ($cachedOtp !== $inputOtp) {
            return response()->json([
                'message' => 'Invalid OTP code. Please check the code and try again.',
            ], 422);
        }

        $passenger = Passenger::create([
            'name' => $request->string('name')->toString(),
            'email' => $email,
            'phone' => $request->string('phone')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
        ]);

        // Clear OTP code from cache upon successful registration
        Cache::forget('otp_' . $email);

        $token = $passenger->createToken('seapass-mobile-app')->plainTextToken;

        return response()->json([
            'message' => 'Passenger registered successfully.',
            'token' => $token,
            'data' => [
                'id' => $passenger->id,
                'name' => $passenger->name,
                'email' => $passenger->email,
                'phone' => $passenger->phone,
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Revoke the current access token of the authenticated passenger.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logoutApi(Request $request)
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully. Token revoked.',
        ], 200);
    }

    /**
     * Get the authenticated passenger profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile(Request $request)
    {
        $passenger = $request->user();

        if (!$passenger) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'data' => [
                'id' => $passenger->id,
                'name' => $passenger->name,
                'email' => $passenger->email,
                'phone' => $passenger->phone ?? '',
            ],
        ], 200);
    }

    /**
     * Handle login authentication
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $input = trim($request->input('username'));
        $password = $request->input('password');
        $remember = $request->filled('remember');

        // Try authenticating as email first, then as name/username
        if (Auth::attempt(['email' => $input, 'password' => $password], $remember) ||
            Auth::attempt(['name' => $input, 'password' => $password], $remember)) {
            $request->session()->regenerate();
            return redirect()->intended('/admin/dashboard');
        }

        return back()->withErrors([
            'username' => 'The provided credentials do not match our records.',
        ])->withInput($request->only('username'));
    }

    /**
     * Handle logout
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
