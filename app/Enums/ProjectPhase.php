<?php

namespace App\Enums;

/**
 * Sprint 24 D1 — where an ACTIVE project is in its execution, in order.
 * The phase locks the order of work: daily targets only after the TM
 * (PERSIAPAN), the Tukang's check-ins only once production started
 * (PRODUKSI, after the PM approved the Pengawas's DP), going to the site
 * only from PRODUKSI. Moved only by ProjectPhaseService. Projects opened
 * before Sprint 24 are PRODUKSI (the column default), so nothing locks them.
 * Values must match `ProjectPhase` in resources/js/types/index.d.ts.
 */
enum ProjectPhase: string
{
    case GambarKerja = 'GAMBAR_KERJA';
    case Tm = 'TM';
    case Persiapan = 'PERSIAPAN';
    case Produksi = 'PRODUKSI';
    case Pemasangan = 'PEMASANGAN';

    public function label(): string
    {
        return match ($this) {
            self::GambarKerja => 'Gambar Kerja',
            self::Tm => 'Technical Meeting',
            self::Persiapan => 'Persiapan Produksi',
            self::Produksi => 'Produksi',
            self::Pemasangan => 'Pemasangan di Lokasi',
        };
    }

    /** Position in the flow — compare with isAtLeast(). */
    public function rank(): int
    {
        return match ($this) {
            self::GambarKerja => 1,
            self::Tm => 2,
            self::Persiapan => 3,
            self::Produksi => 4,
            self::Pemasangan => 5,
        };
    }

    public function isAtLeast(self $phase): bool
    {
        return $this->rank() >= $phase->rank();
    }

    /** Daily targets may be planned from PERSIAPAN on (D1 — "setelah TM selesai"). */
    public function allowsTargets(): bool
    {
        return $this->isAtLeast(self::Persiapan);
    }

    /** The Tukang works (checks targets off, gets penalised, targets roll over) from PRODUKSI on. */
    public function isProducing(): bool
    {
        return $this->isAtLeast(self::Produksi);
    }
}
