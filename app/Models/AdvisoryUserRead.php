<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvisoryUserRead extends Model
{
    use HasFactory;

    protected $table = 'advisory_user_reads';

    protected $fillable = [
        'user_id',
        'advisory_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function advisory(): BelongsTo
    {
        return $this->belongsTo(Advisory::class, 'advisory_id');
    }
}
