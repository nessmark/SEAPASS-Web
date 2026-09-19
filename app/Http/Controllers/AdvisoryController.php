<?php

namespace App\Http\Controllers;

use App\Mail\TravelAdvisoryBroadcastMail;
use App\Models\Advisory;
use App\Models\AdvisoryUserRead;
use App\Models\Passenger;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AdvisoryController extends Controller
{
    /**
     * GET /admin/travel-advisory
     * Display travel advisory dashboard for admin.
     */
    public function adminIndex(): View
    {
        $advisories = \App\Models\TravelAdvisory::orderBy('created_at', 'desc')->get();
        return view('admin.travel_adv_dashboard', compact('advisories'));
    }

    /**
     * Resolve authenticated passenger from Sanctum token or session.
     */
    protected function resolvePassenger(Request $request): ?Passenger
    {
        $user = $request->user('sanctum') ?? auth('sanctum')->user();
        if ($user instanceof Passenger) {
            return $user;
        }

        $userId = $user?->id ?? $request->user()?->id;
        if ($userId) {
            $p = Passenger::find($userId);
            if ($p) return $p;
        }

        $token = $request->bearerToken();
        if ($token) {
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($pat && $pat->tokenable instanceof Passenger) {
                return $pat->tokenable;
            }
        }

        return null;
    }

    protected function resolvePassengerId(Request $request): ?int
    {
        return $this->resolvePassenger($request)?->id;
    }

    /**
     * GET /api/advisories
     * Returns list of published advisories with dynamic boolean is_read flag for the authenticated user.
     * When the account is new, past advisories published prior to registration are excluded.
     */
    public function index(Request $request): JsonResponse
    {
        $passenger = $this->resolvePassenger($request);
        $userId = $passenger?->id;

        // Fetch published advisories
        $query = Advisory::published()
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc');

        // When the account is new, past advisories published prior to registration are null/excluded
        if ($passenger && $passenger->created_at) {
            $query->where(function ($q) use ($passenger) {
                $q->where('published_at', '>=', $passenger->created_at)
                  ->orWhere(function ($sub) use ($passenger) {
                      $sub->whereNull('published_at')
                          ->where('created_at', '>=', $passenger->created_at);
                  });
            });
        }

        $advisories = $query->get();

        // Get read advisory IDs for this user
        $readIds = [];
        if ($userId) {
            $readIds = AdvisoryUserRead::where('user_id', $userId)
                ->pluck('advisory_id')
                ->flip()
                ->all();
        }

        $formatted = $advisories->map(function (Advisory $advisory) use ($readIds) {
            return [
                'id' => $advisory->id,
                'title' => $advisory->title,
                'content' => $advisory->content,
                'severity' => $advisory->severity,
                'type' => $advisory->type ?? 'General Advisory',
                'route' => $advisory->route ?? 'All Routes',
                'effective_from' => $advisory->effective_from,
                'until' => $advisory->until,
                'published_at' => $advisory->published_at?->toIso8601String(),
                'created_at' => $advisory->created_at?->toIso8601String(),
                'is_read' => isset($readIds[$advisory->id]),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    /**
     * GET /api/advisories/unread-count
     * Returns JSON { "unread_count": X } to supply the bottom navigation badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $passenger = $this->resolvePassenger($request);
        if (!$passenger) {
            return response()->json([
                'success' => true,
                'unread_count' => 0,
            ]);
        }

        $query = Advisory::published();
        if ($passenger->created_at) {
            $query->where(function ($q) use ($passenger) {
                $q->where('published_at', '>=', $passenger->created_at)
                  ->orWhere(function ($sub) use ($passenger) {
                      $sub->whereNull('published_at')
                          ->where('created_at', '>=', $passenger->created_at);
                  });
            });
        }

        $userId = $passenger->id;
        $totalPublished = (clone $query)->count();
        if ($totalPublished === 0) {
            return response()->json([
                'success' => true,
                'unread_count' => 0,
            ]);
        }

        $advisoryIds = (clone $query)->pluck('id');
        $readCount = AdvisoryUserRead::where('user_id', $userId)
            ->whereIn('advisory_id', $advisoryIds)
            ->count();

        $unreadCount = max(0, $totalPublished - $readCount);

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * POST /api/advisories/{id}/read
     * Records the read timestamp in advisory_user_reads so the badge counter decrements instantly.
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $advisory = Advisory::find($id);
        if (!$advisory) {
            return response()->json([
                'success' => false,
                'message' => 'Advisory not found.',
            ], 404);
        }

        $userId = $this->resolvePassengerId($request);
        if ($userId) {
            AdvisoryUserRead::updateOrCreate(
                [
                    'user_id' => $userId,
                    'advisory_id' => $advisory->id,
                ],
                [
                    'read_at' => now(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Advisory marked as read.',
        ]);
    }

    /**
     * DELETE /api/advisories/{id}
     * Dismiss/delete an advisory for the authenticated user so it does not crowd their view.
     */
    public function dismissAdvisory(Request $request, $id): JsonResponse
    {
        $userId = $this->resolvePassengerId($request);
        if ($userId) {
            AdvisoryUserRead::updateOrCreate(
                [
                    'user_id' => $userId,
                    'advisory_id' => $id,
                ],
                [
                    'read_at' => now(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Advisory dismissed.',
        ]);
    }

    /**
     * POST /admin/travel-advisory (or POST /api/admin/advisories)
     * Saves HTML content to advisories AND dispatches the queued email job to users simultaneously.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'severity' => 'nullable|string',
            'type' => 'nullable|string',
            'route' => 'nullable|string',
            'effective_from' => 'nullable|string',
            'until' => 'nullable|string',
            'message' => 'nullable|string',
            'content' => 'nullable|string',
            'status' => 'nullable|string',
            'publish_push' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $severity = Advisory::normalizeSeverity($data['severity'] ?? 'info');
        $status = $data['status'] ?? 'Published';
        $publishedAt = ($status === 'Published') ? now() : null;
        $title = trim($data['title']);
        $route = trim($data['route'] ?? 'All Routes');

        // 1. Prevent duplicate publishes (check if identical advisory exists within last 5 minutes)
        $recentDuplicate = Advisory::where('title', $title)
            ->where(function ($q) use ($route) {
                $q->where('route', $route)
                  ->orWhere('affected_route', $route);
            })
            ->where('created_at', '>=', now()->subMinutes(5))
            ->first();

        if ($recentDuplicate) {
            return response()->json([
                'success' => true,
                'message' => 'This advisory has already been published. Duplicate publish prevented.',
                'advisory' => $recentDuplicate,
                'push_status' => 'Sent',
                'is_duplicate' => true,
            ]);
        }

        // Render email-matching HTML template if raw content is not directly supplied
        $htmlContent = $data['content'] ?? null;
        if (empty($htmlContent)) {
            $htmlContent = Advisory::renderEmailHtml([
                'title' => $title,
                'type' => $data['type'] ?? 'General Advisory',
                'route' => $route,
                'effectiveFrom' => $data['effective_from'] ?? 'Immediate',
                'until' => $data['until'] ?? 'Until Further Notice',
                'severity' => ucfirst($severity),
                'message' => $data['message'] ?? '',
            ]);
        }

        // 2. Save or update Advisory in database (avoid duplicate rows by title)
        $advisory = Advisory::updateOrCreate(
            ['title' => $title],
            [
                'content' => $htmlContent,
                'severity' => $severity,
                'type' => $data['type'] ?? 'General Advisory',
                'route' => $route,
                'affected_route' => $route,
                'effective_from' => $data['effective_from'] ?? null,
                'until' => $data['until'] ?? null,
                'is_published' => 1,
                'published_at' => $publishedAt,
            ]
        );

        // Synchronize with travel_advisories table as well
        try {
            \App\Models\TravelAdvisory::updateOrCreate(
                ['title' => $title],
                [
                    'type' => $data['type'] ?? 'General Advisory',
                    'route' => $route,
                    'effective_from' => $data['effective_from'] ?? null,
                    'until' => $data['until'] ?? null,
                    'severity' => ucfirst($severity),
                    'status' => $status,
                    'push_status' => $publishedAt ? 'Sent' : 'Not Sent',
                    'message' => $data['message'] ?? $title,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning("[AdvisoryController] TravelAdvisory sync note: " . $e->getMessage());
        }

        // 3. Dispatch broadcast email only once per advisory (deduplicated by Cache lock)
        $shouldPush = $publishedAt && ($request->boolean('publish_push') || $request->input('publish_push') === true);
        if ($shouldPush) {
            $lockKey = 'advisory_push_sent_' . md5($title . '_' . $route . '_' . date('Y-m-d'));
            if (!\Illuminate\Support\Facades\Cache::has($lockKey)) {
                \Illuminate\Support\Facades\Cache::put($lockKey, true, now()->addMinutes(15));
                $this->dispatchAdvisoryEmails($advisory, $data['message'] ?? $data['title']);
            } else {
                Log::info("[AdvisoryController] Suppressed duplicate email broadcast for title: {$title}");
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Advisory published successfully.',
            'advisory' => $advisory,
            'push_status' => $shouldPush ? 'Sent' : 'Not Sent',
        ]);
    }

    /**
     * Dispatch queued broadcast email to all registered users and passengers.
     */
    protected function dispatchAdvisoryEmails(Advisory $advisory, string $message): void
    {
        try {
            $passengerEmails = Passenger::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email');

            $userEmails = User::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email');

            $adminEmail = config('mail.from.address', 'jembo.magbanua@gmail.com');

            $recipients = $passengerEmails->merge($userEmails)
                ->concat([$adminEmail])
                ->unique()
                ->filter()
                ->values();

            $mailData = [
                'id' => $advisory->id,
                'title' => $advisory->title,
                'type' => $advisory->type ?? 'General Advisory',
                'route' => $advisory->route ?? 'All Routes',
                'effectiveFrom' => $advisory->effective_from ?? 'Immediate',
                'until' => $advisory->until ?? 'Until Further Notice',
                'severity' => ucfirst($advisory->severity),
                'status' => 'Published',
                'message' => $message,
            ];

            foreach ($recipients as $recipient) {
                // Queue the email to ensure instantaneous API response
                Mail::to($recipient)->queue(new TravelAdvisoryBroadcastMail($mailData));
            }
        } catch (\Throwable $e) {
            Log::error("[AdvisoryController] Email broadcast queue error: " . $e->getMessage());
        }
    }
}
