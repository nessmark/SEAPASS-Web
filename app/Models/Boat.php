<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Boat extends Model
{
    protected $fillable = [
        'name',
        'owner',
        'operator',
        'boat_number',
        'license_number',
        'passenger_capacity',
        'vehicle_capacity',
        'image',
        'status',
    ];
}

