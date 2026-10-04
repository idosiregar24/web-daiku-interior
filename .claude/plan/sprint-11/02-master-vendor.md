# Sprint 11 · Sub 2 — Master Vendor

> Induk: [`../sprint-11-quotation-satuan-material.md`](../sprint-11-quotation-satuan-material.md).
> Asal keputusan: Sprint 12 keputusan #32 ([`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md)) —
> dipindah ke Sprint 11 karena pembelian material (Sub 3) membutuhkannya.
> Status: **belum dikerjakan** · Prasyarat: —

## Tujuan
Vendor/supplier jadi master yang dipakai ulang (pembelian material, hutang
supplier, nanti realisasi anggaran Sprint 12).

## File yang disentuh
- Baru: model/migration `Vendor`, `VendorService`, `MasterData/VendorController`, requests,
  halaman `Pages/MasterData/Vendors/Index.tsx`, `Components/shared/VendorSelect.tsx`
- `routes/web.php` (group `master-data` ±baris 575 = `role:SUPERADMIN`; vendor dapat group sendiri)
- `resources/js/Layouts/AppLayout.tsx` (`NAV_GROUPS`)
- `app/Models/SupplierDebt.php`, `app/Services/SupplierDebtService.php`,
  `app/Http/Requests/Finance/StoreSupplierDebtRequest.php`
- `resources/js/Pages/Finance/SupplierDebts/{Create,Index,Show}.tsx`,
  `resources/js/Components/modules/finance/SupplierDebtPaymentDialog.tsx`,
  `resources/js/Pages/Projects/Show.tsx` (menampilkan `supplier_name`)
- Test: `tests/Feature/MasterData/*`, `tests/Feature/Finance/*`

## Rancangan
```
vendors   name (unik), contact, address (text), type (MATERIAL | JASA),
          bank_name, bank_account_number, account_holder, is_active, created_by
```
- CRUD **SUPERADMIN + CEO** saja: route `role:CEO`, prefix
  `master-data/vendors`, name `master-data.vendors.*` (SUPERADMIN lolos via
  god-mode). Nav di grup Data Master, terlihat CEO + SUPERADMIN.
- Role lain hanya memilih lewat `VendorSelect` (vendor aktif, props dari
  controller). Vendor terpakai → hanya bisa dinonaktifkan.
- **Hutang supplier**: `supplier_debts.supplier_name` → `vendor_id`
  (buat vendor dari nama unik ter-trim, backfill, hapus kolom; `down()`
  mengembalikan teks). Bila vendor belum ada: "Vendor belum terdaftar —
  minta CEO menambahkannya di Data Master → Vendor."

## Checklist
- [ ] **[MasterData]** Migration + model `Vendor` (`$fillable`, scope `active`), `VendorService`, requests, controller, route `role:CEO`
- [ ] **[UI]** Halaman Data Master → Vendor (DataTable + Dialog, toggle aktif) + `VendorSelect` + nav
- [ ] **[Database]** Migrasi `supplier_debts.supplier_name` → `vendor_id` (reversibel) + update service/request/halaman hutang & `Projects/Show.tsx`
- [ ] **[Test]** RBAC (CEO/SUPERADMIN 200; FINANCE/LOGISTICS/PM 403), vendor terpakai tidak bisa dihapus, backfill hutang supplier
