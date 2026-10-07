<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 18 Sub 04 — a device that accepted Web Push for `user`. Written
 * only through PushSubscriptionService; the keys are the browser's public
 * encryption keys for that endpoint (not secrets of ours), but they stay
 * hidden from any serialization anyway.
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
        'last_used_at',
    ];

    /** @var list<string> */
    protected $hidden = ['endpoint', 'endpoint_hash', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
