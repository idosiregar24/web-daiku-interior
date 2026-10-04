# Sprint 11 · Sub 5 — Katalog Barang Anti-Dobel

> Induk: [`../sprint-11-quotation-satuan-material.md`](../sprint-11-quotation-satuan-material.md) §1 (#10, #11) dan §5.5 (Lapis 1–6).
> Status: **belum dikerjakan** · Prasyarat: Sub 1, Sub 4

## Tujuan
Barang yang sama tidak bisa tercatat dua kali di katalog walaupun ditulis
berbeda ("Triplek 17mm" / "triplek 17 mm" / "Plywood 17mm").

## File yang disentuh
- `app/Models/Material.php`, `app/Http/Controllers/Logistics/MaterialController.php`,
  `app/Http/Requests/Logistics/{StoreMaterialRequest,UpdateMaterialRequest}.php`
- `app/Http/Controllers/MasterData/MasterDataController.php`, `resources/js/Pages/MasterData/Index.tsx`
  (tab Kategori Material & Sinonim)
- `resources/js/Pages/Logistics/Materials/*`, form material proyek (Sub 3), layar tinjau (Sub 4)
- Baru: `MaterialCatalogService`, halaman Cek Duplikat
- Test: `tests/Feature/Logistics/*`

## Rancangan
Ikuti induk §5.5 Lapis 1–6 (identitas terstruktur, `match_key` UNIQUE,
cek barang mirip, cari katalog dulu, satu pintu Logistik, gabung barang).

## Checklist
- [ ] **[MasterData]** Master `material_categories` (+ prefix kode) dan daftar sinonim nama barang — CRUD SUPERADMIN
- [ ] **[Database]** `materials`: kategori FK, nama dasar, spesifikasi, merek, kode otomatis, `match_key` UNIQUE, `merged_into_id`; migrasi data lama (bentrok → tandai "kemungkinan dobel", tidak gagal)
- [ ] **[Logistics]** `MaterialCatalogService`: normalisasi `match_key`, cari barang mirip, buat barang (tolak persis, mirip → alasan wajib + audit)
- [ ] **[UI]** Form barang terstruktur + panel "Barang serupa sudah ada"; pencarian katalog dulu di form material proyek, saran "Mungkin maksud Anda" sebelum mengajukan
- [ ] **[Logistics]** Halaman Cek Duplikat + gabung barang B → A (stok via pergerakan, referensi diarahkan, B nonaktif, audit)
- [ ] **[Test]** Variasi penulisan ("17mm"/"17 MM"/sinonim) ditolak sebagai dobel; simpan bersamaan → satu yang lolos; gabung barang memindahkan stok & referensi; PM/Estimator tidak bisa membuat barang katalog
