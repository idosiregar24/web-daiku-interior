# Sprint 13 · 11 — HP/Tablet untuk PM & Asisten PM

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 04, 05 · Keputusan: P1–P3

## Tujuan
Pekerjaan PM di lapangan (ACC, cek progres, QA) nyaman dari HP atau tablet.

## File yang disentuh
- `resources/js/Components/shared/DataTable.tsx` (prop `mobileCard?: (row) => ReactNode`)
- `resources/js/Pages/{Inbox/Index,Overtime/Index,Logistics/MaterialRequests/Index,QA/Show,Projects/Show}.tsx`
- Dialog keputusan (setujui/tolak lembur & pengajuan) → panel bawah di layar kecil
- `.claude/rules/frontend-standards.md` §4 (kapan memakai `mobileCard`)

## Rancangan
- `DataTable` dengan `mobileCard` merender daftar kartu di bawah `md`
  (toolbar & paginasi tetap); tanpa prop = perilaku sekarang.
- ACC dari kartu: tombol **Setujui Lembur / Tolak Lembur** langsung di
  kartu, alasan tolak di panel bawah.
- Detail Proyek di HP: `UnderlineTabsList` bisa digeser horizontal,
  Overview & Progress satu kolom, Gantt/Kalender diganti daftar milestone
  di bawah `md`.
- Uji di 390px (HP) dan 820px (tablet potret).

## Checklist
- [ ] **[UI]** `DataTable` `mobileCard` + dokumentasi
- [ ] **[UI]** Lembur, Pengajuan Barang, Perlu Tindakan: kartu + ACC dari kartu
- [ ] **[UI]** Detail Proyek & Form QA nyaman di 390px / 820px
- [ ] **[Test]** Cek manual sebagai PM & Asisten PM di 390px dan 820px; `npm run build`
