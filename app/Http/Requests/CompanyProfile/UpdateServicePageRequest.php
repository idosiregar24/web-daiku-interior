<?php

namespace App\Http\Requests\CompanyProfile;

use App\Models\ServicePage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 20 Sub 06 — the text of one service page. Mirrored by the Zod
 * schema in Pages/Settings/ServicePages/Edit.tsx. The slug is not here:
 * it comes from ServiceCatalog only.
 */
class UpdateServicePageRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Empty rows of the list editors are not data.
        $this->merge([
            'highlights' => array_values(array_filter(
                (array) $this->input('highlights', []),
                fn ($line) => trim((string) $line) !== '',
            )),
            'faqs' => array_values(array_filter(
                (array) $this->input('faqs', []),
                fn ($faq) => is_array($faq) && (trim((string) ($faq['q'] ?? '')) !== '' || trim((string) ($faq['a'] ?? '')) !== ''),
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'headline' => ['required', 'string', 'max:150'],
            'intro' => ['required', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:10000'],
            'highlights' => ['array', 'max:8'],
            'highlights.*' => ['string', 'max:120'],
            'faqs' => ['array', 'max:'.ServicePage::MAX_FAQS],
            'faqs.*.q' => ['required', 'string', 'max:200'],
            'faqs.*.a' => ['required', 'string', 'max:1000'],
            'meta_description' => ['required', 'string', 'max:160'],
            'is_published' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Nama layanan wajib diisi.',
            'title.max' => 'Nama layanan maksimal 120 karakter.',
            'headline.required' => 'Judul halaman wajib diisi.',
            'headline.max' => 'Judul halaman maksimal 150 karakter.',
            'intro.required' => 'Teks pembuka wajib diisi.',
            'intro.max' => 'Teks pembuka maksimal 1000 karakter.',
            'body.required' => 'Isi halaman wajib diisi.',
            'body.max' => 'Isi halaman maksimal 10.000 karakter.',
            'highlights.max' => 'Maksimal 8 poin keunggulan.',
            'highlights.*.max' => 'Tiap poin keunggulan maksimal 120 karakter.',
            'faqs.max' => 'Maksimal '.ServicePage::MAX_FAQS.' pertanyaan.',
            'faqs.*.q.required' => 'Pertanyaan wajib diisi.',
            'faqs.*.q.max' => 'Pertanyaan maksimal 200 karakter.',
            'faqs.*.a.required' => 'Jawaban wajib diisi.',
            'faqs.*.a.max' => 'Jawaban maksimal 1000 karakter.',
            'meta_description.required' => 'Deskripsi Google wajib diisi.',
            'meta_description.max' => 'Deskripsi Google maksimal 160 karakter.',
        ];
    }
}
