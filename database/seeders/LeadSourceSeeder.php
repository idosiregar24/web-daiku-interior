<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    /**
     * PRD §4.1's fixed set (Instagram, TikTok, Referral, Walk-in, WhatsApp,
     * Marketplace, Iklan Sosmed, Website) plus "Existing" (klien lama yang
     * order ulang) and the "Lainnya" catch-all — matches the Sumber Lead
     * dropdown already in use, seeded here so `crm.leads.index` can pull
     * it from `lead_sources` instead of hardcoding it in the form.
     */
    private const SOURCES = [
        'Instagram',
        'Website',
        'Referral/Rekomendasi',
        'Walk-in',
        'WhatsApp',
        'TikTok',
        'Marketplace',
        'Existing',
        'Iklan Sosmed',
        'Lainnya',
    ];

    public function run(): void
    {
        foreach (self::SOURCES as $name) {
            LeadSource::firstOrCreate(['name' => $name]);
        }
    }
}
