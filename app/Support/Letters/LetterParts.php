<?php

namespace App\Support\Letters;

use App\Models\BankAccount;
use App\Models\SiteSetting;
use App\Support\Terbilang;
use Illuminate\Support\Carbon;

/**
 * Sprint 15 — the pieces every company letter shares (offer, invoice): the
 * letterhead, the signer, the bank line of the "Catatan". One place, so the
 * PDF (pdf/layouts/letter.blade.php) and the client's link page agree.
 */
final class LetterParts
{
    /**
     * Letterhead contact icons (location, email, WhatsApp, Instagram) as in
     * the company's letter, 24×24 line art — inlined as data URIs so DomPDF
     * and the client's page draw the very same marks.
     */
    private const ICONS = [
        'address' => '<path d="M20 10c0 5-5.5 10.2-7.4 11.8a1 1 0 0 1-1.2 0C9.5 20.2 4 15 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'email' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-9 5.7a2 2 0 0 1-2 0L2 7"/>',
        'phone' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M9.6 8.4c.3-.5 1-.6 1.4-.2l.8.9c.3.4.3.9 0 1.2l-.5.5a6 6 0 0 0 2.4 2.4l.5-.5c.3-.3.8-.3 1.2 0l.9.8c.4.4.3 1.1-.2 1.4-1.2.7-2.8.5-4.2-.6a10 10 0 0 1-1.7-1.7c-1.1-1.4-1.3-3-.6-4.2z"/>',
        'instagram' => '<rect width="20" height="20" x="2" y="2" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="0.6"/>',
    ];

    /** @return array<string, mixed> */
    public static function company(SiteSetting $site): array
    {
        return [
            'name' => $site->site_name ?: SiteSetting::DEFAULT_NAME,
            'logo' => $site->logoDataUri(),
            'address' => $site->company_address,
            'email' => $site->company_email,
            'phone' => $site->company_phone,
            'instagram' => $site->company_instagram ? ltrim($site->company_instagram, '@') : null,
            'footer' => $site->letterFooterLine(),
            'icons' => array_map(fn (string $paths) => self::svgDataUri($paths), self::ICONS),
        ];
    }

    private static function svgDataUri(string $paths): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$paths.'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** @return array{name: string|null, title: string|null, signature: string|null} */
    public static function signer(SiteSetting $site): array
    {
        return [
            'name' => $site->signer_name,
            'title' => $site->signer_title,
            'signature' => $site->signatureDataUri(),
        ];
    }

    /** "Kamis, 1 Oktober 2026" */
    public static function date(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('l, j F Y');
    }

    public static function rupiah(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }

    /**
     * "… senilai Rp 5.000.000 (LIMA JUTA RUPIAH) dapat dibayarkan via Bank
     * Mandiri 108.00.30312988 a.n. PT …" — every active account, "atau".
     */
    public static function paymentLine(string $what, float|int|string $amount, SiteSetting $site): string
    {
        $accounts = BankAccount::query()->where('is_active', true)->orderBy('label')->get(['bank_name', 'account_no']);
        $via = $accounts
            ->map(fn (BankAccount $account) => trim("{$account->bank_name} {$account->account_no}"))
            ->implode(' atau ');

        return trim(sprintf(
            '%s senilai Rp %s (%s)%s%s.',
            $what,
            self::rupiah($amount),
            Terbilang::rupiah($amount),
            $via !== '' ? " dapat dibayarkan via {$via}" : '',
            $via !== '' && $site->company_legal_name ? " a.n. {$site->company_legal_name}" : '',
        ));
    }

    /** One "Catatan" line per non-empty line of free text. @return list<string> */
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map(
            fn (string $line) => trim(preg_replace('/^\s*(\d+[.)]|[-•*])\s*/u', '', $line)),
            preg_split('/\R/u', (string) $text) ?: [],
        )));
    }
}
