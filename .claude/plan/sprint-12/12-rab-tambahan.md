# Sprint 12 · 12 — RAB Tambahan (Pekerjaan Tambah / Addendum)

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **belum dikerjakan** · Prasyarat: 05, 07, 09 · Keputusan: #29 · Default: D7

## Tujuan
Pekerjaan tambah setelah deal (blok "Penambahan" di Excel Kopi OZ: plafon
membran 8,84 m², plafon topian meja bar) dibuat sebagai RAB tambahan lewat
alur yang sama, lalu itemnya bisa dialokasikan.

## File yang disentuh
- `app/Services/QuotationService.php`, listener `QuotationClientApproved` (07)
- `app/Services/ProjectBudgetService.php` (09), `TerminService.php`
- `resources/js/Pages/Projects/Show.tsx` (tombol + daftar addendum), `Pages/Quotation/Show.tsx`
- Test: `tests/Feature/Quotation/*`, `tests/Feature/Projects/*`

## Rancangan
- Dari Detail Proyek: Marketing/PM "Minta RAB Tambahan" (catatan wajib) →
  quotation PROYEK dengan `parent_quotation_id` = RAB Fix, `project_id`
  terisi, status DIMINTA → alur 03/04/05 (Estimator → PM → CEO → link client).
- Persetujuan client atas addendum **tidak** membuat `project_openings`;
  listener membedakan lewat `parent_quotation_id`.
- Item addendum muncul di "Item belum dialokasikan" (09).
- Skema bayar addendum → **D7** (default: ditambahkan sebagai termin proyek
  baru bertipe TAMBAHAN; batas 6 dihitung per quotation, bukan per proyek).
- Nilai kontrak proyek = RAB Fix + Σ addendum disetujui (tampil terpisah di
  ringkasan).

## Checklist
- [ ] **[Quotation]** Permintaan RAB Tambahan dari proyek; listener membedakan addendum
- [ ] **[Finance]** Termin TAMBAHAN dari skema addendum (sesuai D7)
- [ ] **[UI]** Daftar addendum di Detail Proyek, nilai kontrak gabungan, item addendum di Alokasi Dana
- [ ] **[Test]** Addendum melewati PM → CEO → client; tidak membuka proyek baru; item & termin bertambah
