<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Canonical clock for SeaPass trip lifecycle: Philippine Standard Time (UTC+8).
 */
final class ManilaClock
{
    public const TIMEZONE = 'Asia/Manila';

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public static function nowDb(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function parseLocal(string $datetime): Carbon
    {
        return Carbon::parse($datetime, self::TIMEZONE);
    }

    public static function toIso8601(Carbon|\DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $carbon = $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse($value, self::TIMEZONE);

        return $carbon->timezone(self::TIMEZONE)->format('Y-m-d\TH:i:sP');
    }
}
