import { SectionCard } from '@/Components/shared/SectionCard';
import { Calculator } from 'lucide-react';
import { KPI_MAX_SCORE } from './KpiTypes';

interface KpiFormulaCardProps {
    /** The employee's own view — no mention of HR's buttons. */
    selfView?: boolean;
}

/**
 * Sprint 29 Sub 14 — "Cara menghitung": the KPI formula of KpiService in
 * plain words (scoring, weight redistribution, month total, locking) and
 * the two Aktivitas Harian metrics. Text only — the formula itself lives
 * in KpiService / KpiMetricRegistry.
 */
export function KpiFormulaCard({ selfView = false }: KpiFormulaCardProps) {
    return (
        <SectionCard
            title="Cara menghitung"
            icon={Calculator}
            description="Rumus yang dipakai sistem untuk setiap indikator dan total bulanan."
        >
            <ol className="list-decimal space-y-2 pl-5 text-sm text-foreground">
                <li>
                    <span className="font-medium">Skor indikator</span> = aktual ÷ target × 100. Untuk indikator "makin rendah makin baik":
                    target ÷ aktual × 100 (aktual 0 = skor maksimal). Skor dibatasi 0–{KPI_MAX_SCORE}.
                </li>
                <li>
                    <span className="font-medium">Nilai</span> = skor × bobot ÷ jumlah bobot indikator yang punya nilai. Indikator tanpa
                    data (otomatis tanpa data, manual belum diisi) tidak dinilai dan bobotnya dibagi ke indikator lain.
                </li>
                <li>
                    <span className="font-medium">Total bulan</span> = jumlah nilai semua indikator (skala 0–{KPI_MAX_SCORE}, 100 = sesuai
                    target). Contoh: target 90%, aktual 81% → skor 90; bobot 30 dari 100 → nilai 27.
                </li>
                <li>
                    {selfView
                        ? 'Indikator otomatis dihitung dari data sistem. Setelah SDM menutup periode, nilainya terkunci dan tidak berubah lagi.'
                        : 'Indikator otomatis dihitung ulang setiap "Hitung KPI" dan saat periode ditutup. Setelah ditutup, nilai (beserta rinciannya) terkunci.'}
                </li>
                <li>
                    <span className="font-medium">Aktivitas Harian</span>: "% hari kerja mengisi" = hari kerja dengan minimal satu aktivitas
                    ÷ hari kerja (sejak tanggal bergabung sampai kemarin; hari libur tidak dihitung). "% aktivitas dinilai Sesuai" = hari
                    terisi yang pemeriksaan terakhir SDM-nya Sesuai ÷ hari terisi yang sudah diperiksa — hari yang belum diperiksa tidak
                    dihitung. Klik nama indikatornya di tabel untuk melihat rincian per hari.
                </li>
            </ol>
        </SectionCard>
    );
}
