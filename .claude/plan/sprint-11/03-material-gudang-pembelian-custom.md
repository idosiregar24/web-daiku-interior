# Sprint 11 · Sub 3 — Material Proyek: Gudang · Pembelian · Custom · Sisa

> Induk: [`../sprint-11-quotation-satuan-material.md`](../sprint-11-quotation-satuan-material.md) §1 (#5–#9, #12, #14), §5.1–§5.4, §5.6.
> Status: **belum dikerjakan** · Prasyarat: Sub 1, Sub 2

## Tujuan
Baris material proyek punya sumber GUDANG / PEMBELIAN / CUSTOM, siklus
terima → pakai → bereskan sisa (retur ke gudang / susut / serahkan klien),
dan proyek tidak bisa COMPLETED bila masih ada sisa.

## File yang disentuh
- `app/Models/{Material,ProjectMaterial,StockMovement}.php`, `app/Enums/StockMovementType.php`
- `app/Services/{StockService,LogisticsService,ProjectService}.php` (blokir COMPLETED)
- `app/Http/Controllers/Logistics/{ProjectMaterialController,StockMovementController,MaterialController}.php`
- `app/Http/Requests/Logistics/{StoreProjectMaterialRequest,UpdateProjectMaterialRequest,StockMovementRequest}.php`
- `resources/js/Pages/Projects/Show.tsx` (tab Material), `resources/js/Pages/Logistics/StockMovements/*`
- Baru: `ProjectMaterialService`
- Test: `tests/Feature/Logistics/LogisticsTest.php`, `tests/Feature/Projects/*`

## Rancangan
Ikuti induk §5.1–§5.4 dan tabel RBAC §5.6. Catatan tambahan:
- Kolom `project_materials.vendor` (teks) di draf induk §5.3 → **`vendor_id`**
  (master dari Sub 2).
- Kolom pengajuan/tinjauan (`request_status`, `review_decision`, dst.)
  **dibuat di migrasi Sub ini** supaya skema stabil, tapi alurnya dikerjakan
  di Sub 4. Di Sub ini baris dari katalog langsung DISETUJUI.
- Tautan Finance `finance_transaction_id` disiapkan nullable (T2 tertunda).

## Checklist
- [ ] **[Database]** `materials` stok DECIMAL; `project_materials` (+source, custom, unit_id, harga, vendor_id, kolom pengajuan & tinjauan, qty received/returned/wasted/handed_over, `finance_transaction_id`); `stock_movements` (+RETURN, project_material_id, unit_cost, qty DECIMAL)
- [ ] **[Logistics]** `ProjectMaterialService`: rencana dari katalog, keluarkan dari gudang (harga gudang disalin), catat pembelian, pemakaian, retur, susut, serahkan ke klien — transaksi + lock, stok tidak negatif, pakai ≤ diterima, hanya baris DISETUJUI
- [ ] **[Projects]** Tab Material proyek: tabel per sumber (Gudang · Pembelian · Custom), sisa per baris, tombol Retur/Susut/Serahkan klien, total biaya per sumber
- [ ] **[Projects]** Blokir COMPLETED bila masih ada sisa belum dibereskan (pesan menyebut barangnya)
- [ ] **[Logistics]** Riwayat stok: kolom asal retur (proyek), filter jenis RETURN
- [ ] **[Test]** Alur beli 19 → pakai 17 → retur 2 → proyek B ambil 2 (dibebankan harga gudang); PM hanya proyek miliknya; stok tidak negatif; blokir COMPLETED

## Di luar cakupan
Pengajuan barang di luar katalog (Sub 4), anti-dobel katalog (Sub 5),
tautan Finance (menunggu T2).
