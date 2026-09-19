<?php

namespace App\Http\Controllers;

use App\Mail\TravelAdvisoryBroadcastMail;
use App\Models\Advisory;
use App\Models\Passenger;
use App\Models\TravelAdvisory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class TravelAdvisoryController extends Controller
{
    /**
     * Display travel advisory dashboard with DB records.
     */
    public function index()
    {
        $advisories = TravelAdvisory::orderBy('created_at', 'desc')->get();
        return view('admin.travel_adv_dashboard', compact('advisories'));
    }

    /**
     * Store a new advisory in database.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string',
            'route' => 'required|string',
            'effective_from' => 'nullable|string',
            'until' => 'nullable|string',
            'severity' => 'required|string',
            'status' => 'required|string',
            'message' => 'required|string',
            'publish_push' => 'nullable|boolean',
        ]);

        $pushStatus = ($validated['status'] === 'Published' && !empty($request->publish_push)) ? 'Pending' : 'Not Sent';

        $advisory = TravelAdvisory::create([
            'title' => $validated['title'],
            'type' => $validated['type'],
            'route' => $validated['route'],
            'effective_from' => $validated['effective_from'] ?? null,
            'until' => $validated['until'] ?? null,
            'severity' => $validated['severity'],
            'status' => $validated['status'],
            'push_status' => $pushStatus,
            'message' => $validated['message'],
        ]);

        // Synchronize to mobile app Advisories table if published
        if ($validated['status'] === 'Published') {
            $htmlContent = Advisory::renderEmailHtml([
                'title' => $validated['title'],
                'type' => $validated['type'],
                'route' => $validated['route'],
                'effectiveFrom' => $validated['effective_from'] ?? 'Immediate',
                'until' => $validated['until'] ?? 'Until Further Notice',
                'severity' => ucfirst($validated['severity']),
                'message' => $validated['message'],
            ]);

            Advisory::updateOrCreate(
                ['title' => $validated['title']],
                [
                    'content' => $htmlContent,
                    'severity' => Advisory::normalizeSeverity($validated['severity']),
                    'type' => $validated['type'],
                    'affected_route' => $validated['route'],
                    'route' => $validated['route'],
                    'effective_from' => $validated['effective_from'] ?? null,
                    'until' => $validated['until'] ?? null,
                    'is_published' => 1,
                    'published_at' => now(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Advisory created successfully.',
            'advisory' => $advisory,
        ]);
    }

    /**
     * Bulk Email Dispatch triggered by "Send Push Notification" (📲 icon button).
     * Broadcasts the travel advisory to all registered passenger and user emails.
     */
    public function sendPushNotification(Request $request, $id = null)
    {
        $advisory = null;
        if ($id) {
            $advisory = TravelAdvisory::find($id);
        }

        // If already sent, avoid duplicate broadcast
        if ($advisory && $advisory->push_status === 'Sent') {
            return response()->json([
                'success' => true,
                'message' => 'This advisory push notification has already been sent.',
                'already_sent' => true,
                'push_status' => 'Sent',
                'advisory_id' => $advisory->id,
            ]);
        }

        // If not found in DB or called via inline payload, build array from request or record
        $advisoryData = [
            'id' => $advisory ? $advisory->id : $request->input('id'),
            'title' => $advisory ? $advisory->title : $request->input('title', 'Travel Advisory Notice'),
            'type' => $advisory ? $advisory->type : $request->input('type', 'General Advisory'),
            'route' => $advisory ? $advisory->route : $request->input('route', 'All Routes'),
            'effectiveFrom' => $advisory ? $advisory->effective_from : $request->input('effectiveFrom', 'Immediate'),
            'until' => $advisory ? $advisory->until : $request->input('until', 'Until Further Notice'),
            'severity' => $advisory ? $advisory->severity : $request->input('severity', 'Information'),
            'status' => $advisory ? $advisory->status : $request->input('status', 'Published'),
            'message' => $advisory ? $advisory->message : $request->input('message', 'Please stay tuned for official schedule updates.'),
        ];

        // Deduplication lock to prevent multiple rapid clicks from spamming email dispatch
        $lockKey = 'advisory_push_broadcast_' . md5(($advisoryData['title'] ?? '') . '_' . ($advisoryData['route'] ?? '') . '_' . date('Y-m-d'));
        if (\Illuminate\Support\Facades\Cache::has($lockKey)) {
            if ($advisory) {
                $advisory->update(['push_status' => 'Sent', 'status' => 'Published']);
            }
            return response()->json([
                'success' => true,
                'message' => 'Notification was already broadcasted recently. Duplicate dispatch prevented.',
                'already_sent' => true,
                'push_status' => 'Sent',
            ]);
        }
        \Illuminate\Support\Facades\Cache::put($lockKey, true, now()->addMinutes(15));

        // 1. Gather all registered recipient emails from Passengers, Users tables, and configured MAIL_FROM_ADDRESS
        $passengerEmails = Passenger::whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email');

        $userEmails = User::whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email');

        $adminEmail = config('mail.from.address', 'jembo.magbanua@gmail.com');

        $recipients = $passengerEmails->merge($userEmails)->concat([$adminEmail])->unique()->filter()->values();

        // 2. Dispatch email to each recipient via SMTP immediately
        $sentCount = 0;
        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new TravelAdvisoryBroadcastMail($advisoryData));
                $sentCount++;
            } catch (\Exception $e) {
                Log::error("Failed sending advisory email to {$email}: " . $e->getMessage());
            }
        }

        // 3. Update database push_status to 'Sent' if record exists
        if ($advisory) {
            $advisory->update([
                'push_status' => 'Sent',
                'status' => 'Published',
            ]);
        }

        // 4. Synchronize immediately to mobile app Advisories table!
        $htmlContent = Advisory::renderEmailHtml($advisoryData);
        $severityNorm = Advisory::normalizeSeverity($advisoryData['severity'] ?? 'info');

        Advisory::updateOrCreate(
            ['title' => $advisoryData['title']],
            [
                'content' => $htmlContent,
                'severity' => $severityNorm,
                'type' => $advisoryData['type'] ?? 'General Advisory',
                'affected_route' => $advisoryData['route'] ?? 'All Routes',
                'route' => $advisoryData['route'] ?? 'All Routes',
                'effective_from' => $advisoryData['effectiveFrom'] ?? null,
                'until' => $advisoryData['until'] ?? null,
                'is_published' => 1,
                'published_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "Advisory broadcasted successfully! Bulk queued {$sentCount} email(s) to registered users.",
            'recipients_count' => $sentCount,
            'advisory_id' => $advisory ? $advisory->id : $request->input('id'),
            'push_status' => 'Sent',
        ]);
    }
}
