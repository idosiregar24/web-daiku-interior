# Sprint 13 · 02 — Hub Bertab: Fondasi + Keuangan

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **selesai 2026-10-05** · Prasyarat: 01 · Keputusan: #1 · Default: D1

## Tujuan
Grup Keuangan turun dari 13 menu jadi 5, tanpa mengubah satu pun route.

## File yang disentuh
- `resources/js/Layouts/AppLayout.tsx` (`NavItem.tabs`, `useNavGroups`, `useActiveNav`, breadcrumb)
- `resources/js/Components/shared/ModuleTabs.tsx` (baru)
- Halaman Finance: `Dashboard`, `Transactions/Index`, `Termins/Index`, `Invoices/{Index,Verification}`, `StaffPayments/Index`, `Payroll/Index`, `StaffLoans/Index`, `Penalty/Index`, `SupplierDebts/Index`, `AssetInstallments/Index`
- `.claude/rules/design-standards.md` (baris `ModuleTabs` di tabel building block)

## Rancangan
- `NavItem` mendapat `tabs?: NavTab[]` (`label`, `icon`, `routeName`,
  `match?`, `roles?`). Item ber-tab tidak punya `routeName` sendiri — ia
  menuju **tab pertama yang boleh dibuka role itu**.
- `useNavGroups()` menyaring tab per role; item tanpa tab tersisa
  disembunyikan; item dengan **1 tab** tampil dengan label + ikon tab itu
  (bar tab disembunyikan).
- Item aktif = salah satu tab cocok dengan route sekarang.
- `<ModuleTabs />` membaca hub aktif dari `useActiveNav()` dan merender
  `UnderlineTabsList` berisi `<Link>` (kunjungan Inertia biasa, bukan
  state lokal) di bawah `PageHeader`. Setiap halaman anggota hub cukup
  memasang `<ModuleTabs />`.
- Breadcrumb: 🏠 › Keuangan › Penagihan › Invoice (tab = crumb terakhir,
  otomatis — halaman tidak mengirimnya).
- Tab internal halaman yang sudah ada (Termin: daftar/kalender; Payroll)
  diturunkan jadi `TabsList` kecil supaya tidak ada dua baris garis bawah.
- Hub Keuangan sesuai induk §2.1: Cash Flow, Penagihan, Pembayaran Staf,
  Kewajiban, + Dana Family Gathering (menu tunggal). Alokasi Persentase
  pindah ke ⚙ Pengaturan di Sub 03 — sampai saat itu tetap menu tunggal.

## Checklist
- [x] **[UI]** `NavItem.tabs` + penyaringan per role + aturan "1 tab = menu biasa"
- [x] **[UI]** `ModuleTabs` + breadcrumb otomatis dengan tab
- [x] **[Finance]** 4 hub Keuangan dipasang di 11 halaman; tab internal Termin/Payroll jadi `TabsList`
- [x] **[Docs]** `design-standards.md`: kapan memakai hub bertab
- [x] **[Test]** Cek manual sidebar CEO, Finance, PM, Tukang (Penalti tampil sebagai menu sendiri), Marketing (Invoice saja); `npm run build`

## Catatan pelaksanaan (2026-10-05)
- Hasil simulasi `useNavGroups()` per role: CEO 25 menu, PM 16, Finance 12
  (5 di Keuangan), Marketing → "Invoice", Tukang → "Penalti", Logistik →
  "Cicilan Aset".
- `CommandMenu` mendaftar setiap tab hub sebagai "Hub › Tab" (mis.
  "Penagihan › Invoice") supaya nama hub maupun tab bisa dicari.
- Breadcrumb tab hub otomatis; halaman Termin/Penggajian tetap mengirim
  crumb tab internalnya (List/Kalender, Gaji Bulanan/Karyawan).
