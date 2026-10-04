<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Position;
use Illuminate\Database\Seeder;

/**
 * SDM (Sprint 10, decision #10) — a starting Divisi → Jabatan structure for
 * local/staging/UAT. Production gets its real structure entered by HR in
 * SDM → Divisi & Jabatan. Idempotent.
 */
class OrganizationStructureSeeder extends Seeder
{
    /** @var array<string, list<string>> division → positions */
    public const STRUCTURE = [
        'Presales' => ['Marketing', 'Estimator'],
        'Desain' => ['Desainer Interior', 'Arsitek', 'Drafter'],
        'Proyek' => ['Project Manager', 'Quality Assurance'],
        'Keuangan' => ['Admin Finance'],
        'Logistik' => ['Staf Gudang'],
        'Umum' => ['Admin Kantor', 'Staf SDM'],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::STRUCTURE as $divisionName => $positions) {
            $division = Division::firstOrCreate(['name' => $divisionName], ['sort_order' => $order++, 'is_active' => true]);

            foreach ($positions as $i => $positionName) {
                Position::firstOrCreate(
                    ['division_id' => $division->id, 'name' => $positionName],
                    ['sort_order' => $i, 'is_active' => true],
                );
            }
        }
    }

    public static function position(string $name): Position
    {
        return Position::where('name', $name)->firstOrFail();
    }
}
