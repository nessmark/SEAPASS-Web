<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'action',
        'user_name',
        'details',
        'ip_address',
    ];

    /**
     * Helper to quickly record an audit log entry.
     */
    public static function record(string $category, string $action, string $details, ?string $userName = null): self
    {
        $user = $userName ?: (auth()->user()?->name ?? 'System');
        $ip = request()?->ip() ?: '127.0.0.1';

        return self::create([
            'category' => $category,
            'action' => $action,
            'user_name' => $user,
            'details' => $details,
            'ip_address' => $ip,
        ]);
    }
}
