<?php

namespace Database\Seeders;

use App\Enums\FinanceCategory;
use App\Models\FinanceAllocationConfig;
use Illuminate\Database\Seeder;

/**
 * PRD §4.7's default allocation percentages — real company configuration,
 * so ProductionSeeder runs this too. Idempotent: an existing label is left
 * untouched, so re-running never overwrites a percentage CEO/Finance
 * already changed.
 */
class FinanceAllocationConfigSeeder extends Seeder
{
    /** label => [percentage, kategori]. Penyusutan/Listrik have no own FinanceCategory — mapped to the closest one. */
    private const DEFAULTS = [
        'Gaji' => [12, FinanceCategory::GajiKaryawan],
        'Operasional' => [2, FinanceCategory::Operasional],
        'Consumable' => [1, FinanceCategory::Consumable],
        'Penyusutan' => [1, FinanceCategory::PeralatanAset],
        'Listrik' => [1, FinanceCategory::Operasional],
        'Konsumsi' => [1, FinanceCategory::Konsumsi],
        'Lembur' => [1, FinanceCategory::LemburBonus],
        'Bonus' => [1, FinanceCategory::LemburBonus],
        'Angsuran' => [1, FinanceCategory::Angsuran],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $label => [$percentage, $kategori]) {
            FinanceAllocationConfig::firstOrCreate(
                ['label' => $label],
                ['percentage' => $percentage, 'kategori' => $kategori->value, 'is_active' => true],
            );
        }
    }
}
