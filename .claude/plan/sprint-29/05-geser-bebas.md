# Sprint 29 · 05 — Geser & Tukar Task Bebas

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Penukaran task per timeline bersifat bebas" · Keputusan F7 · Q29
> Status: **kode selesai 2026-10-21** (pindah timeline dicatat di `task_schedule_changes.note`; tersisa cek browser) · Bergantung: Sub 01

## Keputusan user
> "Penukaran sekarang bebas tanpa harus di timeline yang ditentukan untuk
> task tersebut."

## Kondisi sekarang
`TaskService::reschedule()` dan `swapDays()` memanggil
`ensureWithinTimeline()` → ditolak bila tanggal baru di luar timeline task.
`TaskWeekBoard` memudarkan hari di luar timeline.

## Rancangan
- Geser & Tukar Hari **tidak memeriksa rentang timeline**. Hari Minggu tetap
  ditolak (Sprint 28 K13, `DailyFormSchedule`).
- Tanggal baru jatuh di rentang timeline lain → `milestone_id` ikut pindah
  ke timeline itu (Q29); di luar semua timeline → tetap timeline asal.
  Batas bergulir (`TaskOverdueJob`) memakai timeline barunya.
- Riwayat `task_schedule_changes` mencatat pindah timeline ("dari Timeline A
  ke Timeline B").
- Papan: semua hari kerja bisa dijatuhi kartu; hari di timeline lain diberi
  label kecil nama timeline-nya (bukan dipudarkan).
- **Buat** target tetap wajib di dalam timeline yang dipilih (aturan D6 tidak
  berubah).

## Checklist
- [x] **[Backend]** `reschedule()` / `swapDays()` tanpa `ensureWithinTimeline`, tolak Minggu, pindah `milestone_id` sesuai tanggal
- [x] **[UI]** `TaskWeekBoard` & `RescheduleTaskDialog`: batas tanggal dibuka, label timeline tujuan
- [x] **[UX]** Optimistic (Sub 01); toast kecil "Dipindah ke Timeline B" bila timeline berubah
- [x] **[Test]** Pest: geser ke luar timeline diterima + pindah timeline; Minggu ditolak; tukar dua hari lintas timeline; bergulir memakai timeline baru
- [x] **[Build]** `npm run build`, Pint
