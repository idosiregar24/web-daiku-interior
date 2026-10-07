# Sprint 16 · 05 — Logistik, Data Master, Pengaturan

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K2, K4–K9
> Status: **selesai 2026-10-07** · Butuh Sub 01

Cara kerja sama dengan Sub 02. Form Logistik dipakai dari HP gudang, jadi
cek di lebar 390px.

## Checklist
### Logistik
- [x] `Components/modules/logistics/MaterialFormDialog.tsx`: "satu
      pintu" katalog; alasan item mirip wajib bila ada kemiripan, jadi
      bintang dinamis
- [x] `Components/modules/logistics/AssetFormDialog.tsx`
- [x] `Components/modules/logistics/StockMovementDialog.tsx`
- [x] `Components/modules/logistics/MaterialRequestDialog.tsx`: bintang
      di header kolom item (K8)
- [x] `Components/modules/logistics/MaterialRequestReviewDialog.tsx`:
      alasan TOLAK, bintang dinamis
- [x] `Components/modules/logistics/PmRequestDecisionDialog.tsx`
- [x] `Pages/Logistics/Materials/Duplicates.tsx`

### Data Master
- [x] `Components/modules/master-data/BankAccountManager.tsx`
- [x] `Components/modules/master-data/BranchManager.tsx`
- [x] `Components/modules/master-data/MaterialCategoryManager.tsx`
- [x] `Components/modules/master-data/MaterialSynonymManager.tsx`
- [x] `Components/modules/master-data/NameOnlyLookupManager.tsx`
- [x] `Components/modules/master-data/UnitManager.tsx`
- [x] `Pages/MasterData/Vendors/Index.tsx`

### Pengaturan
- [x] `Pages/Settings/Edit.tsx` (14 label: profil, kop surat, catatan
      bawaan RAB). Unggahan aset (`BrandAssetCard`) opsional, jadi tanpa
      bintang

### Selesai
- [x] `npm run build` lulus · cek di browser: MaterialFormDialog (390px),
      Pengaturan Situs

## Catatan pelaksanaan (2026-10-07)
- Helper label (`text()` di MaterialFormDialog & MaterialRequestDialog,
  `field()` di MaterialRequestReviewDialog) mendapat parameter `required`;
  `textField()` Vendor tidak — semua kolomnya `nullable`.
- Bintang dinamis lewat tampilnya field: Proyek di Stok Keluar
  (`required` hanya `stockOut`), Total Cicilan aset (`exclude_unless`),
  isian review pengajuan per keputusan (TOLAK → alasan; PAKAI_KATALOG →
  barang & sumber; DAFTAR_KATALOG → kategori & harga gudang), alasan
  "barang mirip" (hanya muncul bila ada kemiripan), dan form pengajuan
  versi tukang (`minimal`: Spesifikasi/Satuan/Harga/Alasan tidak tampil,
  catatan opsional).
- Data Master: Saldo Awal & Urutan `nullable` → tanpa bintang; switch aktif
  tanpa bintang (K6).
- Pengaturan Situs: hanya **Nama Sistem** yang `required`; field kop surat
  lain `nullable` (diisi `SiteSettingSeeder`) → tanpa bintang.
- Zod ≠ server: tidak ditemukan.
