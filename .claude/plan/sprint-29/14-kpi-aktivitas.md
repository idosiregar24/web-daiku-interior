# Sprint 29 · 14 — KPI dari Aktivitas Harian

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "KPI" · Keputusan I3 · Q33
> Status: **selesai (2026-10-21)** — sisa: uji browser 390 px · Bergantung: Sub 13

## Kondisi sekarang (dijelaskan ke user ronde 3)
KPI SDM bulanan (Sprint 10): template per jabatan, indikator **Otomatis**
(`KpiMetricRegistry`) atau **Manual** (HR mengetik). Skor = aktual ÷ target ×
100 (atau dibalik untuk "makin rendah makin baik"), maks 120; total = Σ skor ×
bobot ÷ Σ bobot terisi; bulan ditutup HR → terkunci.

## Rancangan
- Dua metrik **Otomatis** baru di `KpiMetricRegistry` (semua role kecuali
  FIELD_STAFF):
  - `daily_activity_fill_rate` — "% hari kerja mengisi aktivitas harian"
    (hari kerja = `DailyFormSchedule`, dikurangi cuti bila ada).
  - `daily_activity_ok_rate` — "% aktivitas dinilai Sesuai oleh HR" (dari
    `daily_activity_reviews`; hari belum diperiksa tidak dihitung).
- HR memilihnya di template jabatan seperti metrik lain; rumus KPI tidak
  berubah.
- Halaman KPI karyawan: baris metrik ini bisa diklik → daftar hari
  (terisi / tidak / perlu klarifikasi) bulan itu.
- Penjelasan rumus KPI ditampilkan di halaman KPI (kotak "Cara menghitung")
  — menjawab pertanyaan user ronde 3.

## Checklist
- [x] **[Backend]** Dua metrik + query bulanan (satu query per periode, tanpa N+1)
- [x] **[UI]** Pilihan metrik di template; rincian hari; kotak "Cara menghitung"
- [x] **[Test]** Pest: 20 hari kerja, 18 terisi → 90 %; Sesuai 15 dari 18 diperiksa → 83,33 %; skor & bobot ikut rumus lama
- [x] **[Build]** Pint; `npx tsc --noEmit` bersih (`npm run build` penuh di Sub 15 — sub lain berjalan bersamaan)

## Hasil (2026-10-21)
- **Metrik** (`KpiMetricRegistry`, roles semua kecuali FIELD_STAFF → disarankan di
  Template KPI, arah "makin tinggi makin baik", satuan %):
  - `daily_activity_fill_rate` = hari kerja dengan ≥ 1 `daily_activities` ÷ hari
    kerja × 100.
  - `daily_activity_ok_rate` = hari terisi (termasuk hari libur yang terisi) yang
    pemeriksaan **terbaru**-nya SESUAI ÷ hari terisi yang sudah diperiksa × 100.
    Belum ada yang diperiksa → tanpa data (bobot dibagi, aturan lama).
  - **Jendela hitung**: bulan periode, mulai `join_date` bila bergabung di bulan itu,
    sampai **kemarin** (WIB — hari ini masih bisa diisi & belum bisa diperiksa).
    Hari kerja = `DailyFormSchedule::isWorkDay()`; hari libur hanya tampil bila
    terisi dan tidak masuk hari kerja. Akun nonaktif → tanpa data (tidak diperiksa
    HR, sama dengan `Employee::activityReviewable()`); karyawan tetap lewat
    `hrEligible()->active()` di `KpiService::rebuild()`.
  - **Cuti**: belum ada modul cuti/izin di sistem — belum dikurangkan (tambahkan di
    `loadDailyActivityMonths()` begitu ada).
- **Tanpa N+1**: `KpiMetricRegistry::prime()` dipanggil sekali per `rebuild()` —
  3 query (join_date, entri per orang-hari `DATE(date)`, pemeriksaan) untuk semua
  karyawan bulan itu; dua metrik memakai data yang sama.
- **DB**: `kpi_scores.detail` (JSON, nullable) — rincian di balik nilai otomatis
  (hari kerja, terisi, diperiksa, Sesuai, klarifikasi, tidak mengisi, belum diperiksa,
  daftar hari). Ditulis bersama `actual` setiap Hitung, ikut terkunci saat periode
  ditutup (`KpiScore` menolak update) → bulan tertutup tidak berubah walau HR
  memeriksa ulang. Metrik lain/indikator manual: `null`.
- **UI**: `KpiScoreTable` — nama indikator Aktivitas Harian bisa diklik → 
  `KpiActivityBreakdown` (ringkasan + daftar hari: n aktivitas / Tidak mengisi +
  chip pemeriksaan). Tampil di dialog Rincian halaman KPI, tab KPI profil karyawan
  dan "Milik Saya" (bulan tertutup). Kotak **"Cara menghitung"**
  (`KpiFormulaCard`) di halaman KPI dan tab KPI karyawan (teks versi karyawan
  untuk `selfView`).
- **Rumus KPI tidak berubah**; RBAC tidak berubah (tidak ada route baru).
- **Test**: `tests/Feature/HR/KpiDailyActivityTest.php` (9) — 90 % / 83,33 %, terbaru
  menang, hari libur, sebelum bergabung, bulan berjalan s.d. kemarin, akun
  nonaktif, prime tanpa query, skor 94,74 × 30 % + 92,59 × 70 % = 93,23, Tukang
  tidak dinilai, bulan tertutup tetap, indikator manual tanpa rincian.
- **Belum**: metrik belum dimasukkan ke template demo (`KpiDemoSeeder`); uji browser 390 px.
