# Sprint 13 · 03 — Hub Lanjutan + ⚙ Pengaturan

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 02 · Keputusan: #1, #2

## Tujuan
Logistik dan SDM memakai hub; semua halaman setup pindah ke satu menu
⚙ Pengaturan di bagian bawah sidebar.

## File yang disentuh
- `resources/js/Layouts/AppLayout.tsx` (grup Logistik, SDM, Eksekutif, Sistem → Pengaturan; slot bawah sidebar)
- Halaman: `Logistics/{Materials,StockMovements}`, `HR/{Kpi,Reviews,Discipline,Structure}`, KPI template, `Users/Index`, `MasterData/{Index,Vendors}`, `Finance/Allocations`, `Settings/Edit`
- `MasterData/Index.tsx` (sudah punya `UnderlineTabsList` sendiri → turunkan jadi `TabsList`)

## Rancangan
- **Logistik**: Material [Katalog | Riwayat Stok] · Pengajuan Barang · Aset Inventaris.
- **SDM**: Dashboard SDM · Karyawan · Gaji · Kinerja [KPI | Evaluasi | Kedisiplinan].
- **Eksekutif**: Analytics · Audit Trail (User Management pindah).
- **⚙ Pengaturan** (dipisah garis, menempel di atas kartu user):
  [Pengguna | Data Master | Vendor | Alokasi Persentase | Divisi & Jabatan |
  Template KPI | Situs], masing-masing dengan `roles` dari menu asalnya
  (Alokasi Persentase CEO/FINANCE, Divisi & Jabatan + Template KPI CEO/HR,
  Data Master SUPERADMIN, Vendor + Situs CEO/SUPERADMIN, Pengguna CEO).
- `routeName` & gate tetap; hanya letak menunya berubah. Halaman yang
  dibuka dari tautan lama (notifikasi, bookmark) tetap jalan.

## Checklist
- [ ] **[UI]** Hub Logistik & SDM
- [ ] **[UI]** Menu ⚙ Pengaturan di bawah sidebar + pemindahan 7 halaman setup
- [ ] **[UI]** Tab internal Data Master diturunkan jadi `TabsList`
- [ ] **[Test]** Cek manual sidebar CEO (±25 menu), HR, Finance, Logistik, SUPERADMIN; `npm run build`
