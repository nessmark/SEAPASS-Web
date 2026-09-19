<?php

namespace App\Services;

use App\Models\Passenger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for dispatching Firebase Cloud Messaging (FCM) Push Notifications
 * and persisting In-App Notifications for SeaPass passengers and users.
 */
class FcmPushService
{
    private ?string $serverKey;
    private ?string $projectId;

    public function __construct()
    {
        $this->serverKey = config('services.firebase.server_key', env('FCM_SERVER_KEY'));
        $this->projectId = config('services.firebase.project_id', env('FIREBASE_PROJECT_ID'));
    }

    /**
     * Send push notification and record an in-app database notification for a Passenger.
     *
     * @param Passenger|int $passenger Passenger model or ID
     * @param string $title Notification title
     * @param string $body Notification message body
     * @param array<string, mixed> $data Custom data payload (e.g. booking_id, trip_id)
     * @return bool True if processed
     */
    public function sendToPassenger(Passenger|int $passenger, string $title, string $body, array $data = []): bool
    {
        if (is_int($passenger)) {
            $passenger = Passenger::find($passenger);
        }

        if (!$passenger) {
            Log::warning("[FcmPushService] Attempted to notify non-existent passenger ID.");
            return false;
        }

        // 1. Persist in-app notification in database `notifications` table
        $this->createInAppNotification($passenger, $title, $body, $data);

        // 2. Dispatch FCM Push if device token exists (or topic / mock)
        $token = $passenger->fcm_token ?? $data['device_token'] ?? null;
        if ($token) {
            return $this->sendToToken($token, $title, $body, $data);
        }

        // Log push event for audit / mobile polling
        Log::info("[FcmPushService] In-app notification created for Passenger #{$passenger->id} ({$passenger->email}): '{$title}' - {$body}");

        return true;
    }

    /**
     * Send push notification to a specific FCM device registration token.
     *
     * @param string $deviceToken FCM registration token
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array<string, mixed> $data Custom payload
     * @return bool
     */
    public function sendToToken(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        if (empty($deviceToken)) {
            return false;
        }

        if ($this->serverKey) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $this->serverKey,
                    'Content-Type' => 'application/json',
                ])->post('https://fcm.googleapis.com/fcm/send', [
                    'to' => $deviceToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => array_merge($data, [
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'title' => $title,
                        'body' => $body,
                    ]),
                    'priority' => 'high',
                ]);

                if ($response->successful()) {
                    Log::info("[FcmPushService] FCM push delivered to token: " . substr($deviceToken, 0, 10) . "...");
                    return true;
                }

                Log::error("[FcmPushService] FCM API error response: " . $response->body());
            } catch (\Throwable $e) {
                Log::error("[FcmPushService] FCM HTTP request failed: " . $e->getMessage());
            }
        } else {
            // Local / Test fallback: Record push notification in application log
            Log::info("[FcmPushService] [SIMULATED PUSH] Token: " . substr($deviceToken, 0, 10) . "... | Title: {$title} | Body: {$body} | Data: " . json_encode($data));
        }

        return true;
    }

    /**
     * Send push notification to an FCM topic (e.g. 'all_passengers', 'route_surigao_dinagat').
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        if ($this->serverKey) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $this->serverKey,
                    'Content-Type' => 'application/json',
                ])->post('https://fcm.googleapis.com/fcm/send', [
                    'to' => '/topics/' . $topic,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => $data,
                ]);

                return $response->successful();
            } catch (\Throwable $e) {
                Log::error("[FcmPushService] FCM Topic push failed: " . $e->getMessage());
            }
        }

        Log::info("[FcmPushService] [SIMULATED TOPIC PUSH] Topic: {$topic} | Title: {$title} | Body: {$body}");
        return true;
    }

    /**
     * Persist an in-app database notification to the `notifications` table.
     */
    public function createInAppNotification(Passenger|User $notifiable, string $title, string $body, array $data = []): void
    {
        try {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\TripRescheduledNotification',
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'data' => json_encode([
                    'title' => $title,
                    'body' => $body,
                    'payload' => $data,
                    'timestamp' => now()->toIso8601String(),
                ]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[FcmPushService] Could not write in-app notification: " . $e->getMessage());
        }
    }
}
