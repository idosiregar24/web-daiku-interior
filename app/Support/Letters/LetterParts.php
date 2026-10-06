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
    /** @return array<string, string|null> */
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
        ];
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
