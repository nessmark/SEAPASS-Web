<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fare extends Model
{
    protected $fillable = [
        'route',
        'regular',
        'student',
        'senior',
    ];

    protected $casts = [
        'regular' => 'decimal:2',
        'student' => 'decimal:2',
        'senior' => 'decimal:2',
    ];

    public static function canonicalKey(string $from, string $to): string
    {
        return self::normalizePort($from).'|'.self::normalizePort($to);
    }

    public static function canonicalRoute(string $route): string
    {
        $normalized = str_replace(['–', '—', '−', '->', '→'], '|', $route);
        $parts = array_map('trim', explode('|', $normalized, 2));

        return self::canonicalKey($parts[0] ?? '', $parts[1] ?? '');
    }

    public static function normalizePort(string $port): string
    {
        $port = strtolower(trim(preg_replace('/\s+/', ' ', $port) ?? $port));
        $port = trim(preg_replace('/\(.*?\)/', '', $port) ?? $port);

        if (in_array($port, ['san jose', 'dinagat', 'san jose dinagat'], true)) {
            return 'dinagat';
        }

        return $port;
    }

    /**
     * @return array{regular: float, student: float, senior: float}|null
     */
    public static function ratesForRoute(string $from, string $to): ?array
    {
        $wanted = self::canonicalKey($from, $to);

        foreach (self::query()->get() as $fare) {
            if (self::canonicalRoute($fare->route) === $wanted) {
                return [
                    'regular' => (float) $fare->regular,
                    'student' => (float) $fare->student,
                    'senior' => (float) $fare->senior,
                ];
            }
        }

        return null;
    }
}
