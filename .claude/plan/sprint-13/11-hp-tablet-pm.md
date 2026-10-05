# Sprint 13 · 11 — HP/Tablet untuk PM, Asisten PM, QA & Logistik

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **selesai sebagian 2026-10-05** (cek manual di 390/820/1366px menunggu aplikasi jalan) · Prasyarat: 04, 05 · Keputusan: P1–P3

## Tujuan
Pekerjaan di lapangan dan gudang (ACC, cek progres, QA, tinjau pengajuan,
stok) nyaman dari HP atau tablet, dan tetap nyaman di laptop — QA &
Logistik berganti-ganti perangkat (dijawab user 2026-10-05).

## File yang disentuh
- `resources/js/Components/shared/DataTable.tsx` (prop `mobileCard?: (row) => ReactNode`)
- PM/Asisten PM: `resources/js/Pages/{Inbox/Index,Overtime/Index,Projects/Show}.tsx`
- QA: `resources/js/Pages/QA/{Index,Show}.tsx` (+ form isian QA)
- Logistik: `resources/js/Pages/Logistics/{MaterialRequests,Materials,StockMovements}/Index.tsx`
- Dialog keputusan (setujui/tolak lembur, pengajuan barang, QA) → panel bawah di layar kecil
- `.claude/rules/frontend-standards.md` §4 (kapan memakai `mobileCard`)

## Rancangan
- `DataTable` dengan `mobileCard` merender daftar kartu di bawah `md`
  (toolbar & paginasi tetap); tanpa prop = perilaku sekarang. Satu halaman,
  dua tampilan — tidak ada halaman "versi HP" terpisah, karena QA &
  Logistik membuka halaman yang sama dari HP maupun laptop.
- ACC dari kartu: tombol **Setujui Lembur / Tolak Lembur**, **Setujui
  Pengajuan / Tinjau Pengajuan** langsung di kartu; alasan tolak di panel bawah.
- **QA**: daftar form QA sebagai kartu (proyek, milestone, status); form
  isian satu kolom dengan tombol keputusan **Setujui QA / Tolak QA** lebar
  penuh di bawah. QA tetap tidak melihat detail task (security-standards §2).
- **Logistik**: tinjauan pengajuan barang & daftar material sebagai kartu;
  angka stok terlihat tanpa geser ke samping.
- Detail Proyek di HP: `UnderlineTabsList` bisa digeser horizontal,
  Overview & Progress satu kolom, Gantt/Kalender diganti daftar milestone
  di bawah `md`.
- Uji di 390px (HP), 820px (tablet potret), dan 1366px (laptop).

## Checklist
- [x] **[UI]** `DataTable` `mobileCard` + dokumentasi
- [x] **[UI]** PM/Asisten PM: Lembur, Pengajuan Barang, Perlu Tindakan — kartu + ACC dari kartu; Detail Proyek nyaman di 390px / 820px
- [x] **[QA]** Daftar & form QA nyaman di HP, keputusan dari panel bawah
- [x] **[Logistics]** Tinjauan pengajuan, Material, Riwayat Stok sebagai kartu di HP
- [ ] **[Test]** Cek manual sebagai PM, Asisten PM, QA, Logistik di 390px / 820px / 1366px; `npm run build`
  📌 **Status: Sebagian** — `npm run build` + `tsc` lulus; cek manual per role & lebar layar belum (MySQL Laragon mati, tak ada browser di sesi ini) — jalankan bersama uji HP Sub 12.

## Catatan pelaksanaan (2026-10-05)
- `DataTable` prop `mobileCard` (+ `rowKey`) — dibangun di Sub 10 bersama
  pemecahan `DataTableGrid`; di bawah `md` merender kartu tanpa TanStack.
  Didokumentasikan di `frontend-standards.md` §4 (HP & tablet, Ringan).
- `ResponsiveDialogContent` (`Components/shared/`): pengganti `DialogContent`
  — panel bawah di HP. Dipasang di dialog keputusan & isian lapangan:
  Setujui/Tolak Lembur (+ Ajukan Lembur), Setujui/Tolak Pengajuan (PM),
  Tinjau Pengajuan (Logistik), Terima/Catat Pemakaian stok, Ubah Status Task.
- **Lembur** & **QA**: kartu `md:hidden` + tabel `hidden md:table`; tombol
  **Setujui/Tolak Lembur** langsung di kartu (fungsi `decisionButtons()`
  dipakai tabel & kartu). **Form QA**: checkbox lebih besar, bilah
  **Tolak QA / Setujui QA** menempel di bawah layar HP, lebar penuh.
- **Pengajuan Barang**, **Material**, **Riwayat Stok**: `mobileCard`
  (stok tampil besar, tanpa geser ke samping); aksi baris diekstrak ke
  `actionsOf()`/`menuFor()` dan dipakai grid + kartu.
- **Detail Proyek**: di bawah `md` tab Milestone memakai `MilestoneList`
  (Tandai Selesai/ubah/hapus) menggantikan Gantt/Kalender; tab sudah bisa
  digeser, Overview satu kolom di bawah `lg`. **Perlu Tindakan** sudah
  berupa kartu sejak Sub 04.
