<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelAdvisory extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'type',
        'route',
        'effective_from',
        'until',
        'severity',
        'status',
        'push_status',
        'message',
    ];
}
