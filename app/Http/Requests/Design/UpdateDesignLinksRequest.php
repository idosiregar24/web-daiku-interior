<?php

namespace App\Http\Requests\Design;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 23 K18/K19b — the "Link Desain" card of a design born from a RAB
 * Jasa Desain: the WhatsApp group where the client, Marketing and the
 * architects talk, and the design links (Drive / Figma). Route
 * `role:MARKETING|DESIGNER`, narrowed by DesignPolicy::manageLinks() to the
 * lead's Marketing and the design team. The state rules (assigned yet?,
 * an approved design keeps a link) live in DesignService::updateLinks().
 */
class UpdateDesignLinksRequest extends FormRequest
{
    /** Hosts a WhatsApp group / chat link may point at. */
    public const WHATSAPP_HOSTS = ['wa.me', 'chat.whatsapp.com'];

    public function authorize(): bool
    {
        return $this->user()->can('manageLinks', $this->route('design'));
    }

    public function rules(): array
    {
        return [
            'whatsapp_group_url' => ['bail', 'nullable', 'string', 'max:500', 'url:http,https', $this->whatsappHostRule()],
            'design_urls' => ['nullable', 'array', 'max:20'],
            'design_urls.*' => ['bail', 'required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_group_url.url' => 'Link grup WhatsApp harus berupa URL yang valid (http/https).',
            'whatsapp_group_url.max' => 'Link grup WhatsApp maksimal 500 karakter.',
            'design_urls.max' => 'Maksimal 20 link desain.',
            'design_urls.*.required' => 'Link desain tidak boleh kosong.',
            'design_urls.*.url' => 'Link desain harus berupa URL yang valid (http/https).',
            'design_urls.*.max' => 'Link desain maksimal 2048 karakter.',
        ];
    }

    /** chat.whatsapp.com (group invite) or wa.me — nothing else passes as "grup WA". */
    private function whatsappHostRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $host = is_string($value) ? strtolower((string) parse_url($value, PHP_URL_HOST)) : '';

            if (! in_array($host, self::WHATSAPP_HOSTS, true)) {
                $fail('Link grup WhatsApp harus dari chat.whatsapp.com atau wa.me.');
            }
        };
    }
}
