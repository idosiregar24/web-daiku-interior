# Sprint 31 — Penerapan Sisa File "SIMULASI PERSENTASI.xlsx"

> Status: **kode selesai 2026-10-22** (Sub 01–04, 07) — 2387 test lulus, build &
> Pint bersih, migrasi dev jalan; tersisa cek browser (ikut Sprint 30 Sub 02).
> Sub 03–04 dibuat **menurut pemahaman Claude** (user: "buat dulu sesuai
> pemahaman kamu" 2026-10-22) — siapa pengisi jadwal masih didiskusikan (§3).
> Sub 05–06 menunggu jawaban user. Di luar PRD; lanjutan Sprint 29.

## 1. Asal

Audit ulang `sprint-29/SIMULASI PERSENTASI.xlsx` (2026-10-22, semua rumus,
nilai & warna sel) terhadap sistem. Sudah sama sejak Sprint 29: BOBOT (kolom
I), % per tanggal pengecekan, TOTAL % proyek (AV24 = 67,70 %). Yang kurang:

| # | Di Excel | Sebelum sprint ini |
|---|---|---|
| 1 | Jadwal kerja per item (sel biru muda, blok 4 hari berurutan) | Tidak ada — jadwal per Timeline / daily target |
| 2 | Blok biru kedua akhir Oktober (baris 4–14) | Tidak ada |
| 3 | Tabel item × tanggal + Total % (AU) + Kontribusi (AV) | Data ada, tampilan tidak |
| 4 | Pengecekan 20 & 25 Okt di minggu yang sama | Dibatasi satu per minggu (unique `project_id, week_start`) |
| 5 | Tahap "DP + Marking 1" / "Marking 2" | DP ada, Marking tidak |
| 6 | Kelompok "LT 2 (Parsial 1)", kode item "PN5" | Tidak ada |

Catatan: di Excel `AV = SUM(Q:AT) × bobot` mulai kolom Q, `AU = SUM(P:AT)`
mulai P — tidak berpengaruh pada data contoh; sistem menjumlah semua tanggal.

## 2. Dikerjakan

### Sub 01 — Rekap Progres per Item (#3)
- [x] **[Backend]** `WorkItemService::progressRecap()` — per item: Bobot, % per tanggal pengecekan, Total %, Kontribusi (= Total × Bobot ÷ 100); per tanggal: progres ditambahkan & kumulatif (dibagi sekali, tanpa pembulatan menumpuk); total = progres berbobot
- [x] **[Backend]** Prop `execution.progressRecap` — hanya untuk yang melihat pengecekan (CEO/PM/Finance/Pengawas proyek + Admin Proyek)
- [x] **[UI]** `ProgressRecapCard` di tab Eksekusi (Produksi & Pemasangan, di bawah Pengecekan Mingguan): tabel dengan kolom Item tetap saat digulir, footer "Progres ditambahkan" & "Progres proyek (kumulatif)"; HP = kartu per item dengan chip tanggal
- [x] **[Test]** angka File C: baris 4 (30 + 50 = 80 %, kontribusi 3,82), Klinik EEG 6,28, 9 Okt +7,83 %, akhir 67,70 % = progres berbobot; Logistik tidak menerima prop

### Sub 02 — Pengecekan lebih dari sekali seminggu (#4)
- [x] **[DB]** `weekly_reports.sequence` (urutan dalam minggu); unique `(project_id, week_start)` diganti index biasa
- [x] **[Backend]** Proyek berupah per item: pengecekan berikutnya boleh dikirim kapan saja **setelah pengecekan terakhir minggu itu diputuskan PM** (satu pengajuan uang menunggu PM per minggu); tiap pengecekan = baris baru (yang ditolak tetap sebagai riwayat). Proyek lama tanpa upah per item tetap satu laporan per minggu. `WeeklyReportService::canSubmit()` = aturan yang sama untuk UI (`currentWeek.open`)
- [x] **[Backend]** `WeeklyReport::label()` — "Minggu ke-3 · Pengecekan ke-2" di notifikasi, label pembayaran Finance, pesan
- [x] **[UI]** Kartu Pengecekan Mingguan terbuka lagi setelah PM memutuskan; judul form & konfirmasi menyebut pengecekan ke-berapa; riwayat menampilkan label & tanggal kirim
- [x] **[Test]** 20 & 25 Okt satu minggu: kedua ditolak selama pertama menunggu PM, sesudah diputuskan terkirim sebagai Pengecekan ke-2; proyek lama tetap satu per minggu; tes kirim ulang setelah ditolak diperbarui (baris baru)
- [x] **[Seed]** Proyek Hendra: minggu ini pengecekan ke-1 (+10 %, disetujui), draf ke-2 (+15 % → 75 %)

### Sub 03–04 — Jadwal per Item (#1, #2) — menurut pemahaman Claude
- [x] **[DB]** `project_work_items.production_start/end` + `installation_start/end` (blok biru pertama = produksi, blok kedua = **pemasangan**; semua opsional)
- [x] **[Backend]** `WorkItemService::updateSchedule()` (di dalam tanggal proyek, selesai ≥ mulai, pemasangan tidak mulai sebelum produksi, diaudit `work_item.scheduled`, **tidak** terkunci setelah Start Produksi) · `scheduleOverview()` + `scheduleStatus()`: Belum dijadwalkan · Akan datang · Berjalan · **Terlambat** (jendela terakhir lewat, progres < 100 %) · Selesai · scope `ProjectWorkItem::behindSchedule()`
- [x] **[Backend]** Pengisi: **Pengawas proyek / Admin Proyek** (`ProjectPolicy::scheduleWorkItems`, route `projects.work-items.schedule`, `role:QA|ADMIN_PROYEK`); semua yang membuka tab Eksekusi membaca (`execution.workSchedule`, dari Persiapan)
- [x] **[Backend]** Perlu Tindakan "Item terlambat dari jadwal · n item" untuk PM proyek & Pengawas proyek
- [x] **[UI]** `WorkScheduleCard` (Persiapan → Pemasangan): bagan per hari seperti sel biru (produksi biru, pemasangan hijau, hari ini, Minggu diarsir), status per item, dialog "Atur Jadwal" (`DatePicker`, `ResponsiveDialogContent`); HP = kartu per item
- [x] **[Test]** `WorkItemScheduleTest` (RBAC, validasi tanggal, status & antrean); seed Hendra (1 terlambat), Siska (sebagian belum), Dewi (selesai)

### Sub 07 — UX: tab "Item RAB", Lembar Progres, isi jadwal cepat (usulan Claude, disetujui user 2026-10-22)
Dasar: cek browser 2026-10-22 — tab Eksekusi Pengawas 9 kartu ±3.500 px, satu daftar item di 4 kartu terpisah, jadwal & progres tidak dalam satu tampilan, isi jadwal per item lewat dialog.
- [x] **[UI]** Tab baru **Item RAB** di Detail Proyek (sub-tab **Lembar Progres · Upah Tukang · QC Item**); tab Eksekusi tinggal kartu alur (6 kartu ±1.650 px); QC Item pindah dari Timeline & QC (QC Timeline tetap untuk proyek lama)
- [x] **[UI]** `WorkProgressSheet` — satu tabel seperti Excel: Item · Bobot · Total · Kontribusi · Status · ✎ lalu kolom per hari (biru produksi, hijau pemasangan, angka % pengecekan di selnya); kolom **Hari ini** = isian pengecekan (`WorkCheckForm` `renderSheet`); baris "Progres proyek" kumulatif per tanggal + pratinjau; ringkasan uang, catatan, Kirim di bawahnya; HP = kartu isian dengan tombol cepat; geser otomatis ke hari ini bila di luar layar. Menggantikan kartu Rekap Progres & Jadwal per Item
- [x] **[UI]** Atur Jadwal = **mulai + lama (hari)** (selesai dihitung); **Jadwalkan Berurutan** (`scheduleSequence`, route `projects.work-items.scheduleSequence`): item terpilih, mulai item pertama, lama, jeda antar item, pemasangan opsional, pratinjau hasil
- [x] **[UI]** Kartu Pengecekan di Eksekusi: status + riwayat + keputusan PM, isian lewat tombol "Isi Pengecekan →"; Syarat DP "Upah Tukang" → Item RAB › Upah Tukang
- [x] **[Backend]** Tautan pindah: notifikasi QA item & antrean "Item menunggu QA" → `tab=items&view=qc`; "Item terlambat" & "Pengecekan belum diisi" (proyek berupah) → `tab=items&view=progres`
- [x] **[Test]** `WorkItemScheduleTest` + 1 (tangga File C 2–5/3–6/4–7 Okt, satu item di luar proyek → tidak ada yang tersimpan); suite 2388 lulus; cek headless browser (Pengawas desktop + HP, PM, Logistik)

## 3. Menunggu jawaban user

| Sub | Pertanyaan |
|---|---|
| 03 (#1) | **Diskusi:** jadwal per item diisi Pengawas (dibuat) atau PM saat TM? Perlu disetujui PM? Wajib sebelum DP / Start Produksi? |
| 04 (#2) | Blok biru kedua akhir Oktober **dianggap pemasangan** — benar? |
| 05 (#5) | "Marking" = kegiatan apa (ukur/tandai di lokasi)? Perlu dicatat sebagai langkah/tahap? |
| 06 (#6) | "Parsial 1" = serah terima bertahap? Perlu progres & serah terima per parsial? Kode item (PN5) perlu di RAB? |
| 08 | ~~Daily target ↔ item RAB~~ — **diputuskan 2026-10-23: disambung, Opsi A (longgar)** → [Sprint 38](sprint-38-daily-target-item-rab.md). % pengecekan tetap manual Pengawas; target memberi Progres Target per item sebagai acuan. |
