<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleTemplate extends Model
{
    use HasFactory;

    protected $table = 'schedule_templates';

    protected $fillable = [
        'boat_id',
        'route',
        'departure_time',
        'arrival_time',
        'recurrence_type',
        'days_of_week',
        'available_seats',
        'effective_from',
        'effective_until',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'available_seats' => 'integer',
    ];

    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class, 'boat_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(TripSchedule::class, 'schedule_template_id');
    }

    /**
     * Check whether this recurrence template applies to a given calendar date.
     */
    public function appliesToDate(Carbon $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $checkDay = $date->copy()->startOfDay();

        if ($this->effective_from && $checkDay->lt($this->effective_from->copy()->startOfDay())) {
            return false;
        }

        if ($this->effective_until && $checkDay->gt($this->effective_until->copy()->startOfDay())) {
            return false;
        }

        if ($this->recurrence_type === 'daily') {
            return true;
        }

        if ($this->recurrence_type === 'weekly' && is_array($this->days_of_week)) {
            $shortDay = $date->format('D'); // Mon, Tue, etc.
            $fullDay = $date->format('l');  // Monday, Tuesday, etc.
            
            foreach ($this->days_of_week as $d) {
                if (strcasecmp($d, $shortDay) === 0 || strcasecmp($d, $fullDay) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Human-readable recurrence summary (e.g., "Daily" or "Every Mon, Wed, Fri").
     */
    public function getFormattedRecurrenceAttribute(): string
    {
        if ($this->recurrence_type === 'daily') {
            return 'Daily (Every Day)';
        }

        if (is_array($this->days_of_week) && !empty($this->days_of_week)) {
            return 'Every ' . implode(', ', $this->days_of_week);
        }

        return 'Weekly';
    }

    /**
     * Formatted 12-hour departure time string (e.g. "10:30 AM").
     */
    public function getFormattedDepartureTimeAttribute(): string
    {
        if (!$this->departure_time) {
            return '—';
        }

        try {
            return Carbon::createFromFormat('H:i', $this->departure_time)->format('g:i A');
        } catch (\Throwable) {
            return $this->departure_time;
        }
    }

    /**
     * Formatted 12-hour arrival time string (e.g. "12:30 PM").
     */
    public function getFormattedArrivalTimeAttribute(): string
    {
        if (!$this->arrival_time) {
            // Default +2 hours from departure
            try {
                return Carbon::createFromFormat('H:i', $this->departure_time)->addHours(2)->format('g:i A');
            } catch (\Throwable) {
                return '—';
            }
        }

        try {
            return Carbon::createFromFormat('H:i', $this->arrival_time)->format('g:i A');
        } catch (\Throwable) {
            return $this->arrival_time;
        }
    }
}
