# Sprint 29 · 14 — KPI dari Aktivitas Harian

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "KPI" · Keputusan I3 · Q33
> Status: **belum dikerjakan** · Bergantung: Sub 13

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
- [ ] **[Backend]** Dua metrik + query bulanan (satu query per periode, tanpa N+1)
- [ ] **[UI]** Pilihan metrik di template; rincian hari; kotak "Cara menghitung"
- [ ] **[Test]** Pest: 20 hari kerja, 18 terisi → 90 %; Sesuai 15 dari 18 diperiksa → 83,33 %; skor & bobot ikut rumus lama
- [ ] **[Build]** `npm run build`, Pint
