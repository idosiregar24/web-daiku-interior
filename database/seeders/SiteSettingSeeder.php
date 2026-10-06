<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Sprint 15 — the company profile printed on every letter (kop surat of the
 * offer / invoice PDF and the client's link): address, email, WhatsApp,
 * Instagram, legal name for bank transfers, signer. Real company data, so
 * both DatabaseSeeder and ProductionSeeder call it.
 *
 * Fills empty fields only — never overwrites what the CEO already set in
 * Pengaturan Situs, so it is safe to run again. Logo and signature images
 * are uploaded in Pengaturan Situs.
 */
class SiteSettingSeeder extends Seeder
{
    public const COMPANY = [
        'company_address' => 'Jl. Yos Sudarso, Rumbai, Pekanbaru',
        'company_email' => 'daikupku@gmail.com',
        'company_phone' => '0811 759 7766',
        'company_instagram' => 'DaikuInterior',
        'company_legal_name' => 'PT Daiku Shankara Kreasitech',
        'signer_name' => 'Fendra Budiono',
    ];

    public function run(): void
    {
        $settings = SiteSetting::current();

        $missing = array_filter(
            self::COMPANY,
            fn (string $value, string $column) => blank($settings->{$column}),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing !== []) {
            $settings->update($missing);
        }
    }
}
