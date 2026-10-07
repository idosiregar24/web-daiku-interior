<?php

namespace App\Http\Requests\Profile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 18 Sub 04 — `PushSubscription.toJSON()` from the browser. The
 * endpoint is where the server will POST later, so it must be an https URL
 * (every browser push service is); it is never fetched on the user's behalf
 * beyond that encrypted push.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endpoint.*' => 'Alamat notifikasi perangkat tidak valid.',
            'keys.*' => 'Kunci notifikasi perangkat tidak valid.',
            'keys.*.*' => 'Kunci notifikasi perangkat tidak valid.',
            'content_encoding.in' => 'Format notifikasi perangkat tidak didukung.',
        ];
    }
}
