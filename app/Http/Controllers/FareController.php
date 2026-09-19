<?php

namespace App\Http\Controllers;

use App\Models\Fare;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FareController extends Controller
{
    public const ROUTES = [
        'Surigao → Dinagat',
        'Dinagat → Surigao',
    ];

    /** Display names used by ticketing / mobile that map to a stored matrix row. */
    private const ROUTE_ALIASES = [
        'Surigao → Dinagat' => [
            'Surigao → Dinagat',
            'Surigao → San Jose(Dinagat)',
            'Surigao → San Jose',
        ],
        'Dinagat → Surigao' => [
            'Dinagat → Surigao',
            'San Jose(Dinagat) → Surigao',
            'San Jose → Surigao',
        ],
    ];

    public function index(): View
    {
        $this->persistSessionFaresIfPresent();

        return view('admin.fare_management_dashboard', [
            'fares' => $this->matrixRows(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->persistMatrix($request->input('fares', []));
        session()->forget('fare_matrix');

        AuditLog::record(
            'schedule',
            'Fare Matrix Updated',
            'Updated passenger fare matrix and discount rates for vessel routes'
        );

        return redirect()->route('admin.fare-management')
            ->with('success', 'Fare matrix updated successfully.');
    }

    public function forAdmin(): JsonResponse
    {
        $this->persistSessionFaresIfPresent();

        $keyed = Fare::query()->get()->keyBy('route');
        $payload = [];

        foreach (self::ROUTE_ALIASES as $canonical => $aliases) {
            $row = $keyed->get($canonical);
            $rates = [
                'regular' => (float) ($row->regular ?? 0),
                'student' => (float) ($row->student ?? 0),
                'senior' => (float) ($row->senior ?? 0),
            ];
            foreach ($aliases as $alias) {
                $payload[$alias] = $rates;
            }
        }

        return response()->json(['fares' => $payload])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function forPassenger(Request $request): JsonResponse
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $route = (string) $request->query('route', '');

        if ($from === '' || $to === '') {
            $normalized = str_replace(['–', '—', '−', '->', '→'], '|', $route);
            $parts = array_map('trim', explode('|', $normalized, 2));
            $from = $parts[0] ?? '';
            $to = $parts[1] ?? '';
        }

        $rates = Fare::ratesForRoute($from, $to) ?? [
            'regular' => 0.0,
            'student' => 0.0,
            'senior' => 0.0,
        ];

        $regular = (float) $rates['regular'];
        $student = (float) ($rates['student'] > 0 ? $rates['student'] : round($regular * 0.80, 2));
        $senior = (float) ($rates['senior'] > 0 ? $rates['senior'] : round($regular * 0.80, 2));

        return response()->json([
            'from' => $from,
            'to' => $to,
            'regular' => $regular,
            'student' => $student,
            'senior' => $senior,
            'regular_fare' => $regular,
            'student_fare' => $student,
            'senior_pwd_fare' => $senior,
        ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function persistSessionFaresIfPresent(): void
    {
        $sessionFares = session('fare_matrix');
        if (! is_array($sessionFares) || $sessionFares === []) {
            return;
        }

        $this->persistMatrix($sessionFares);
        session()->forget('fare_matrix');
    }

    /**
     * @param  array<string, mixed>  $fares
     */
    private function persistMatrix(array $fares): void
    {
        foreach (self::ROUTES as $route) {
            $row = $fares[$route] ?? [];

            Fare::updateOrCreate(
                ['route' => $route],
                [
                    'regular' => (float) ($row['regular'] ?? 0),
                    'student' => (float) ($row['student'] ?? 0),
                    'senior' => (float) ($row['senior'] ?? 0),
                ]
            );
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function matrixRows()
    {
        $stored = Fare::query()->get()->keyBy('route');

        return collect(self::ROUTES)->map(function (string $route) use ($stored) {
            $row = $stored->get($route);

            return (object) [
                'route' => $route,
                'regular' => $row->regular ?? '',
                'student' => $row->student ?? '',
                'senior' => $row->senior ?? '',
            ];
        });
    }
}
