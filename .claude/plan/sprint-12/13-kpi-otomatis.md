# Sprint 12 · 13 — KPI Otomatis Estimator & PM

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **belum dikerjakan** · Prasyarat: 04, Sprint 10 (KPI) · Keputusan: #9

## Tujuan
Data review RAB (sub-plan 04) menjadi indikator KPI otomatis di modul KPI
Sprint 10.

## File yang disentuh
- `app/Services/Kpi/KpiMetricRegistry.php`, `app/Services/KpiService.php`
- `app/Enums/KpiIndicatorSource.php` (AUTO sudah ada)
- `database/seeders/*Kpi*` (template indikator default)
- Test: `tests/Feature/HR/*`

## Rancangan
Metrik baru di `KpiMetricRegistry` (per user, per periode KPI, berdasarkan
tanggal keputusan review):

| Metrik | Untuk | Rumus |
|---|---|---|
| `estimator_first_pass_rate` | Estimator (pembuat quotation) | Σ item ✔ pada review PM **versi pertama** ÷ Σ item yang direview di versi pertama × 100 |
| `estimator_returned_count` | Estimator | jumlah keputusan "kembalikan" (PM atau CEO) atas quotation-nya |
| `pm_review_escaped_count` | PM/Asisten PM yang ACC | jumlah RAB yang ia ACC lalu **dikembalikan CEO** |

- Arah KPI: first-pass rate *higher is better*; dua hitungan *lower is
  better* (`KpiDirection`).
- Hanya quotation yang punya review di periode itu; tanpa data → "Tidak ada
  data" (bukan 0).
- Indikator default ditambahkan ke template KPI jabatan Estimator & PM
  (HR tetap bisa mengubah bobot).

## Checklist
- [ ] **[HR]** Tiga metrik di registry + query dari `quotation_item_reviews` / `quotation_approvals`
- [ ] **[HR]** Seed indikator default di template Estimator & PM
- [ ] **[Test]** Perhitungan per periode (versi pertama saja untuk first-pass; pengembalian CEO dihitung ke PM yang ACC, bukan ke Estimator saja)
