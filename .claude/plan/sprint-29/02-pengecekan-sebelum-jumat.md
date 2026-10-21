# Sprint 29 · 02 — Pengecekan Mingguan Boleh Sebelum Jumat

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Form pengecekan mingguan boleh diisi sebelum Jumat" · Keputusan C2
> Status: **kode selesai 2026-10-20** (tersisa cek HP 390 px di browser) · Bergantung: Sub 01 (pola simpan)

## Kondisi sekarang
Server **sudah** menerima laporan hari apa pun selama PRODUKSI/PEMASANGAN
(`WeeklyReportService::submit`). Yang mengikat Jumat hanya tampilan:
`WeeklyReportsCard` ("diisi Pengawas tiap Jumat"), `QcPanelCard`
("hari ini Jumat", `mine` hanya saat `is_report_day`), `projectWaiting.ts`
(Giliran Anda hanya saat `is_report_day`).

## Rancangan
- Teks: "Pengecekan mingguan — paling lambat Jumat".
- Panel QC & Ringkasan: baris pengecekan minggu ini **"Belum diisi"** sejak
  Senin (tone muted), **warning** di hari Jumat dan sesudahnya bila belum
  diisi; "Giliran Anda" untuk Pengawas sejak Senin.
- Antrean Perlu Tindakan Pengawas (baru, `ActionInboxService`):
  "Pengecekan mingguan belum diisi · Minggu ke-N" — mulai Kamis (sehari
  sebelum batas), `routeName` ke tab Eksekusi `#laporan-mingguan`.
- Pengingat Jumat 15:00 (`WeeklyReportReminderJob`) tidak berubah.
- Isi form (% per item) berubah di Sub 09 — sub ini hanya waktu & tampilan.

## Checklist
- [x] **[UI]** `WeeklyReportsCard`, `QcPanelCard`, `projectWaiting.ts`: teks & aturan tampil
- [x] **[Backend]** `ActionInboxService`: antrean pengecekan belum diisi (Kamis–Jumat), scope Pengawas proyek
- [ ] **[UX]** Form pengecekan bisa dibuka kapan saja dari panel QC; simpan pakai `projectVisit` (Sub 01); HP 390 px
- [x] **[Test]** Pest: submit hari Selasa diterima; antrean muncul Kamis, hilang setelah dikirim; tidak dobel
- [x] **[Build]** `npm run build`, Pint
