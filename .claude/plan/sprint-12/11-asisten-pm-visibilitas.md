# Sprint 12 · 11 — Asisten PM per Proyek & Visibilitas Marketing

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **selesai 2026-10-05** · Prasyarat: 07, 09, 10 · Keputusan: #22, #30 · Default: D2

## Tujuan
Asisten PM bekerja di proyek yang ditugaskan padanya (tanpa alokasi/
realisasi), dan Marketing melihat progres proyek tanpa data finance internal.

## File yang disentuh
- `app/Policies/{ProjectPolicy,TaskPolicy}.php`, `app/Http/Controllers/Projects/*`
- `routes/web.php` (gate `role:CEO|PM` di blok proyek/milestone/task/progress → tambah ASISTEN_PM di aksi yang relevan)
- `app/Http/Controllers/Projects/ProjectController.php` (`show` — props per role; `update` untuk ganti Asisten PM)
- `resources/js/Pages/Projects/{Index,Show}.tsx`
- Test: `tests/Feature/Projects/*`, `tests/Feature/RbacMiddlewareTest.php`

## Rancangan
- **Penugasan**: CEO memilih saat Buka Proyek (07); PM proyek bisa
  mengganti di Edit Proyek (D2). Audit `project.assistant_pm_changed`.
- **Asisten PM proyek itu** boleh: semua yang PM boleh di proyek (milestone,
  task, progress, ACC pengajuan barang) **kecuali** Alokasi Dana &
  Realisasi (Policy `manageBudget` = `pm_id` saja). Helper
  `Project::isManagedBy(User)` = `pm_id` atau `assistant_pm_id`.
- Daftar proyek Asisten PM = proyek di mana ia `assistant_pm_id`.
- **Marketing** di Detail Proyek: Overview, Milestone, Progress, Termin,
  Invoice, Dokumen. **Tidak dikirim**: alokasi/realisasi, harga modal,
  margin, transaksi kas, hutang supplier, biaya material. Filter di
  controller (props), bukan hanya UI.

## Checklist
- [x] **[Projects]** `isManagedBy()` + Policy proyek/task/milestone mengenali Asisten PM; `manageBudget` tetap PM saja
- [x] **[Projects]** Route gate menambah ASISTEN_PM pada aksi proyek; ganti Asisten PM di Edit Proyek
- [x] **[Logistics]** ACC pengajuan barang Tukang (Sprint 11 Sub 4) juga boleh Asisten PM proyek itu — Policy pakai `isManagedBy()`, notifikasi ke PM + Asisten PM
- [x] **[Projects]** `ProjectController::show` memfilter props untuk Marketing
- [x] **[Test]** Asisten PM: bisa task/milestone di proyeknya, 403 di proyek lain & alokasi/realisasi; Marketing: props finance absen, termin/invoice ada

## Catatan
ACC pengajuan barang oleh Asisten PM memperluas Sprint 11 Sub 4
(sudah dikerjakan, saat itu hanya PM) memakai `isManagedBy()` dari sini.
