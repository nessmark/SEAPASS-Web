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
        $advisory = TravelAdvisory::findOrFail($id ?: $request->input('id'));
        if ($advisory->status !== 'Published') return response()->json(['success'=>false,'message'=>'Only published advisories can be sent.'],422);
        $status = app(\App\Services\AdvisoryDeliveryService::class)->broadcast($advisory);
        return response()->json(['success'=>$status !== 'Failed','push_status'=>$status,'message'=>'Broadcast recorded. Email and SMS delivery status is tracked separately.']);
    }
}
