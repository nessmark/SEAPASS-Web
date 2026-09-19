<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Advisory extends Model
{
    use HasFactory;

    protected $table = 'advisories';

    protected $fillable = [
        'title',
        'content',
        'severity',
        'type',
        'route',
        'affected_route',
        'effective_from',
        'until',
        'is_published',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_published' => 'boolean',
    ];

    /**
     * Reads recorded for this advisory by passengers/users.
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AdvisoryUserRead::class, 'advisory_id');
    }

    public function getRouteAttribute(): string
    {
        return $this->attributes['route'] ?? $this->attributes['affected_route'] ?? 'All Routes';
    }

    public function getAffectedRouteAttribute(): string
    {
        return $this->attributes['affected_route'] ?? $this->attributes['route'] ?? 'All Routes';
    }

    public function setRouteAttribute($value): void
    {
        $this->attributes['route'] = $value;
        $this->attributes['affected_route'] = $value;
    }

    public function setAffectedRouteAttribute($value): void
    {
        $this->attributes['affected_route'] = $value;
        $this->attributes['route'] = $value;
    }

    /**
     * Scope to return only published advisories.
     */
    public function scopePublished($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('is_published')
              ->orWhere('is_published', 1)
              ->orWhere('is_published', true);
        })->where(function ($q) {
            $q->whereNull('published_at')
              ->orWhere('published_at', '<=', now());
        });
    }

    /**
     * Normalize severity value (info, warning, critical).
     */
    public static function normalizeSeverity(?string $severity): string
    {
        $s = strtolower(trim($severity ?? 'info'));
        if (in_array($s, ['critical', 'danger'])) {
            return 'critical';
        }
        if (in_array($s, ['warning', 'advisory'])) {
            return 'warning';
        }
        return 'info';
    }

    /**
     * Renders the email-matching HTML template for an advisory.
     */
    public static function renderEmailHtml(array $advisoryData): string
    {
        try {
            return view('emails.travel_advisory', [
                'advisory' => $advisoryData,
            ])->render();
        } catch (\Throwable $e) {
            // Fallback clean responsive card HTML if template engine encounters an issue
            $title = htmlspecialchars($advisoryData['title'] ?? 'Notice');
            $message = nl2br(htmlspecialchars($advisoryData['message'] ?? ''));
            $severity = htmlspecialchars($advisoryData['severity'] ?? 'Information');
            $route = htmlspecialchars($advisoryData['route'] ?? 'All Routes');
            return "<div style='font-family: sans-serif; padding: 16px; background: #ffffff;'>
                <div style='background: #0284C7; color: white; padding: 4px 12px; border-radius: 99px; display: inline-block; font-size: 11px; font-weight: bold;'>{$severity}</div>
                <h2 style='color: #0F172A; margin: 12px 0;'>{$title}</h2>
                <p style='color: #64748B; font-size: 13px;'>Route: <strong>{$route}</strong></p>
                <div style='background: #F0FDF4; border-left: 4px solid #10B981; padding: 12px; margin: 16px 0; color: #065F46;'>{$message}</div>
            </div>";
        }
    }
}
