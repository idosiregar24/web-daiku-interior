<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Sprint 18 Sub 04 — the devices a user lets Daiku ring. A device (one
 * browser endpoint) belongs to one user at a time: when someone else logs
 * in on the same phone and turns notifications on, the endpoint moves to
 * them, so the previous user's pushes stop landing on a phone they no
 * longer use.
 */
class PushSubscriptionService
{
    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding?: string|null}  $data
     */
    public function subscribe(User $user, array $data, ?string $userAgent): PushSubscription
    {
        return PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $user->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
            ],
        );
    }

    /** "Matikan di perangkat ini" — only ever the caller's own row. */
    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::where('user_id', $user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete();
    }
}
