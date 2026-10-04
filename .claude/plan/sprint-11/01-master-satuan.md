# Sprint 11 · Sub 1 — Master Satuan

> Induk: [`../sprint-11-quotation-satuan-material.md`](../sprint-11-quotation-satuan-material.md) §1 (#3, #4) dan §4.
> Status: **belum dikerjakan** · Prasyarat: —

## Tujuan
Satuan barang jadi master (dropdown), qty boleh pecahan. Dipakai material
gudang dan item RAB.

## File yang disentuh
- Baru: model/migration `Unit`, `MasterData/UnitController`, requests, seeder satuan
- `app/Http/Controllers/MasterData/MasterDataController.php`, `resources/js/Pages/MasterData/Index.tsx` (tab Satuan)
- `app/Models/{Material,QuotationItem}.php`, `app/Services/{QuotationService,LogisticsService}.php`
- `app/Http/Requests/Logistics/{StoreMaterialRequest,UpdateMaterialRequest}.php`, `app/Http/Requests/Quotation/*`
- `resources/js/Pages/Logistics/Materials/*`, `resources/js/Pages/Quotation/Show.tsx`, `resources/js/types/index.d.ts`
- PDF/Excel quotation
- Test: `tests/Feature/MasterData/MasterDataTest.php`, `tests/Feature/Logistics/LogisticsTest.php`, `tests/Feature/Quotation/*`

## Rancangan
Lihat induk §4. Ringkas:
- `units`: `code` (unik), `name`, `is_active`, `sort_order`; seeder pcs,
  dus, kg, lbr, btg, m, m2, sak, set, unit, ls, titik, m/lari.
- CRUD SUPERADMIN di Data Master → Satuan; satuan terpakai hanya bisa
  dinonaktifkan.
- `materials.unit` & `quotation_items.unit` → `unit_id` (normalisasi teks
  lama + alias, backfill, hapus kolom; `down()` mengembalikan teks).
  Snapshot JSON `quotation_revisions.items` tidak diubah.
- `quotation_items.qty` → `DECIMAL(12,2)`.

## Checklist
- [ ] **[Database]** Tabel `units` + seeder satuan umum
- [ ] **[MasterData]** Data Master → Satuan (CRUD SUPERADMIN, nonaktif bila terpakai)
- [ ] **[Database]** Migrasi `materials.unit` & `quotation_items.unit` → `unit_id` (normalisasi + backfill + hapus kolom teks); qty item RAB → DECIMAL
- [ ] **[UI]** Dropdown satuan di form Material & RAB quotation; PDF/Excel memakai master
- [ ] **[Test]** CRUD satuan (SUPERADMIN saja, role lain 403), backfill migrasi, qty pecahan

## Selesai bila
`php artisan test`, `npm run build`, `pint --test` lulus; material & RAB lama
tetap tampil dengan satuan yang sama.
