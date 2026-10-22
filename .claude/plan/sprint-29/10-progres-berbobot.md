# Sprint 29 · 10 — Progres Proyek Berbobot (rumus Excel)

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Keputusan B7 · Q22 = "Iya" · Rumus §2 (File C)
> Status: **selesai** (2026-10-21) · Bergantung: Sub 09

## Rancangan
- `Project::actualProgress()` (satu tempat — golden rule #17): proyek dengan
  item berupah → **Σ (bobot × progres item) ÷ 100**; proyek tanpa item kerja
  (lama) → actual activity seperti sekarang.
- `Project::withActivityCounts()` / daftar proyek & dashboard: hitung dari
  agregat `work_item_progress` (satu query, tanpa N+1).
- Ikut otomatis: daftar proyek, Ringkasan, stepper, syarat **70 % Berangkat
  ke Lokasi** (+ notifikasi 70 % sekali), dashboard PM/CEO/divisi, H-30 %.
- Actual activity daily target tetap di tab **Activity Target** & penalti —
  diberi judul "Actual activity (daily target)" supaya tidak tertukar dengan
  "Progres pekerjaan".
- Tooltip progres: "Dari pengecekan Pengawas per item RAB, ditimbang nilai RAB".

## Checklist
- [x] **[Backend]** `actualProgress()` + agregat daftar; 70 % memakai nilai baru
- [x] **[UI]** Label & tooltip progres; Activity Target diberi judul jelas
- [x] **[UX]** Angka progres sama di semua tempat (daftar, detail, dashboard)
- [x] **[Test]** Pest: angka File C (67,70 % dari data simulasi); proyek lama tetap actual activity; 70 % terkunci/terbuka sesuai progres berbobot
- [x] **[Dok]** `CLAUDE.md` golden rule #17
- [x] **[Build]** `npx tsc --noEmit` bersih, Pint (`npm run build` penuh menunggu Sub 11 selesai)

## Catatan implementasi
- **Satu rumus** — `WorkItemService::withWeightedSums()` menambah dua subquery
  ke query proyek (`work_rab_total` = Σ nilai RAB item berupah,
  `work_rab_progress` = Σ nilai RAB × % pengecekan); `weightedFromSums()`
  membaginya (2 desimal, `null` = tidak ada item berupah).
  `weightedProgress()` (detail, 70 %) memakai agregat yang sama dengan daftar,
  `figures()`/`checkOverview()` tetap untuk kartu pengecekan.
- **Project** — `actualProgress()` = `progressFigure()['progress']`;
  `progressFigure()` → `{progress, basis: weighted|activity}`;
  `activityProgress()` = actual activity lama (tab Activity Target, penalti,
  proyek tanpa item berupah — termasuk item kerja yang belum diberi Satuan
  Upah). Daftar: `withActivityCounts()` (hitungan task + jumlah berbobot,
  tanpa N+1 — diuji: jumlah query sama untuk 1 atau 5 proyek) +
  `listedProgress()` (`null` = proyek actual activity tanpa daily target → "—").
- **Konsumen** — daftar proyek (`actual_progress` + `progress_basis`, kolom
  "Progres"), Detail Proyek `project` & `execution.progress/progressBasis`,
  dashboard PM/divisi, QA, Analytics CEO, H-30 % (`sendDeadlineReminders`),
  70 % Berangkat (`approveDeparture`, `checkProgress`). Pesan menyebut dasarnya
  (`ProductionService::progressText()`: "progres berbobot 67,7%" /
  "actual activity 80%").
- **70 % dari pengecekan** — `WeeklyReportService::submit()` memanggil
  `ProductionService::checkProgress()` setelah mencatat % per item; notifikasi
  tetap sekali (`progress_70_notified_at`). Selesai task tetap memanggilnya
  (tidak mengubah progres berbobot, aman).
- **UI** — `lib/progress.ts` (`PROGRESS_BASIS_LABEL`/`_HINT`,
  `formatProgress()`, `progressPhrase()`); Ringkasan "Progres Berbobot" dengan
  blok terpisah "Actual activity (daily target)" + target s.d. hari ini +
  selisih; kartu Berangkat ke Lokasi, QC panel, stepper ("progres proyek
  mencapai 70 %"), Perlu tindakan proyek, tombol Berangkat menamai dasarnya;
  tab Activity Target berjudul "Actual Activity (Daily Target)".
  `RELOAD.wages` ikut memuat `project` (Satuan Upah pertama mengubah dasar).
- **Test** — `tests/Feature/Projects/WeightedProgressTest.php` (File C 67,70 %
  & 7,83 % sesudah pengecekan pertama, proyek lama, daftar/dashboard/Analytics
  + jumlah query, 70 % dari pengecekan + notifikasi sekali, H-30 %).
