# Sprint 35 — Timeline = jadwal item RAB, Lembar Progres seperti Excel

> Status: **kode selesai 2026-10-22** — test lulus, build & Pint bersih, cek
> headless browser (Pengawas: Lembar Progres; PM: form Timeline). Keputusan
> user: pilihan A ("timeline jadi satu-satunya jadwal") + "format form
> progress harus mirip Excel".

## Asal
Ada dua jadwal untuk pekerjaan yang sama: Timeline (PM saat TM — dasar daily
target, penalti) dan jadwal per item di Lembar Progres (Pengawas — dasar
status terlambat). Nama timeline sering = item RAB, tanggalnya bisa bertentangan.

## Dikerjakan
- [x] **[DB]** `milestone_work_item` (timeline ↔ item RAB; item boleh di beberapa timeline — produksi, pemasangan; timeline boleh tanpa item — "Pemesanan Bahan")
- [x] **[Backend]** `MilestoneService` create/update menerima `work_item_ids` (hanya item proyek itu), hapus timeline → jadwal item ikut; `WorkItemService::syncTimelineSchedule()` = tanggal item (mulai paling awal → selesai paling akhir timeline-nya), dipakai status Terlambat & antrean; `timelineOptions()` = checklist form (prop `timelineItems`, hanya untuk pengelola timeline); `scheduleOverview` mengirim `timelines` per item
- [x] **[Dihapus]** "Atur jadwal" per item, "Jadwalkan Berurutan", route `projects.work-items.schedule` / `scheduleSequence`, `ProjectPolicy::scheduleWorkItems` (diganti `can.linkTimelines` = `manageWork`)
- [x] **[UI]** Form Timeline: checklist **Item RAB di timeline ini** (per bagian RAB, cari, "Pilih n item yang belum punya timeline", keterangan "di n timeline lain / tanpa upah"); kartu TM menampilkan item tiap timeline + peringatan item berupah yang belum masuk timeline
- [x] **[UI]** Lembar Progres mengikuti Excel: NO · Uraian (+ volume) · Nilai · Bobot (dibekukan kiri) | baris bulan + tanggal | blok biru = timeline item (nama di tooltip), angka % pengecekan | TOTAL % · Kontribusi (dibekukan kanan) | baris "Jumlah · progres proyek" (nilai total, bobot 100 %, progres kumulatif per tanggal, total proyek)
- [x] **[Langkah Kerja]** Persiapan: "Masukkan tiap item berupah ke timeline" (PM / Admin Proyek → tab Timeline)
- [x] **[Seed]** item berupah demo dibagi ke timeline proyeknya
- [x] **[Test]** `WorkItemScheduleTest` ditulis ulang (tautan, tanggal ikut timeline, hapus timeline, item proyek lain ditolak, RBAC, terlambat & antrean); `ProjectStepsTest`

## Menunggu
- Penanda di Lembar Progres "ada daily target selesai untuk item ini hari itu" (sekarang daily target → timeline → item sudah tersambung datanya).
