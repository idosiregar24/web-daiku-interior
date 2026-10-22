# Sprint 29 · 11 — Bahan Custom → Ajukan ke Logistik + Status Pemesanan

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Stok barang tidak usah ditampilkan di Pengawas" + ronde 4 · Keputusan E1–E5
> Status: **selesai (2026-10-21)** — sisa: uji browser 390 px · Bergantung: Sub 01 (form bahan cepat)

## Keputusan user
> "Pastikan untuk bahan yang ingin digunakan di proyek yang dimasukkan oleh
> Pengawas bisa di-custom untuk diajukan ke pihak Logistik untuk dilakukan
> pemesanan dan kelihatan statusnya sudah sampai mana."

## Kondisi sekarang
- Rencana Pengawas (`TmMaterialPlanner`) langsung menjadi baris
  `project_materials` (`request_status` DISETUJUI); sumber dipilih di browser
  dari `stock`. Pengawas melihat "stok n".
- Barang di luar katalog = `source CUSTOM` lewat `MaterialRequestService`
  (MENUNGGU_PM → DIAJUKAN → DISETUJUI / DITOLAK oleh Logistik).
- Penerimaan = `qty_received`; **tidak ada data "sudah dipesan"** (catatan
  Sprint 27 Sub 04).

## Rancangan
1. **Stok** (E1): `materialOptions` untuk viewer yang hanya QA tanpa `stock`
   & `cost_price`; sumber ditentukan server (stok cukup → GUDANG, kurang →
   PEMBELIAN), Logistik bisa mengubah.
2. **Planner Pengawas** (E2): satu daftar "Rencana Bahan" — baris katalog
   (cari nama/kode) **atau "+ Bahan lain (custom)"**: nama, spesifikasi,
   jumlah, satuan (`UnitSelect`), foto/link opsional. Mode beberapa baris +
   "Simpan semua" dari Sub 01.
3. **Ajukan ke Logistik** (E3): baris berstatus **Direncanakan** sampai
   Pengawas menekan "Ajukan ke Logistik (n bahan)" → **Diajukan** + notifikasi
   & antrean Logistik "Pengajuan bahan · Proyek X · n bahan". Baris custom
   tetap lewat review katalog Logistik (`MaterialRequestService::review` —
   cocokkan ke katalog / buat item baru, golden rule #10).
4. **Pemrosesan Logistik**: Setujui / Tolak (alasan) per baris atau sekaligus
   → **Dipesan** (baru: `ordered_at`, `ordered_by`, `vendor_id`,
   `expected_at`) → **Diterima** sebagian / lengkap (`recordPurchase`, sudah
   ada) atau **Dikeluarkan dari gudang** (`issue`, sudah ada).
5. **Status terlihat** (E4): chip per baris di planner, tab Material, kartu
   Pemesanan Bahan, dan Ringkasan — satu fungsi `materialStatus()` (perluasan
   `materialArrival()` Sprint 27): Direncanakan · Diajukan · Disetujui ·
   Ditolak (alasan) · Dipesan (perkiraan tiba dd/mm) · Diterima sebagian
   (x/y) · Lengkap · Dari gudang. `waitingOn`: Diajukan/Disetujui/Dipesan →
   "Logistik".
6. **Ubah/hapus** (E5): Pengawas sampai Diajukan; sesudahnya hanya Logistik.
7. Notifikasi Pengawas saat Ditolak, Dipesan, Diterima lengkap.

## Checklist
- [x] **[DB]** `project_materials`: perluasan `request_status` (DIRENCANAKAN) + `request_channel` RENCANA, `ordered_at`, `ordered_by`, `expected_at`, `order_note`
- [x] **[Backend]** `ProjectMaterialService::submitPlan()`, `markOrdered()`; props tanpa stok untuk QA; sumber oleh server; antrean & notifikasi Logistik/Pengawas
- [x] **[UI]** Planner (katalog + custom, beberapa baris, Ajukan ke Logistik); halaman antrean Logistik (Setujui semua, Tandai Dipesan + perkiraan tiba); chip status di semua tempat
- [x] **[UX]** Tanpa menunggu (Sub 01 K1–K6); Logistik bisa memproses banyak baris sekaligus; HP 390 px (Logistik di gudang, Pengawas di lokasi) — kartu HP & panel bawah dibuat, belum dicek di browser
- [x] **[Test]** Pest: QA tidak menerima `stock`; alur Direncanakan → Diajukan → Disetujui → Dipesan → Diterima; RBAC (Pengawas ajukan, Logistik proses, QA lain 403); custom lewat review katalog
- [x] **[Build]** Pint; `npx tsc --noEmit` bersih (`npm run build` penuh di akhir sprint — sub lain berjalan bersamaan)

## Hasil (2026-10-21)
- **Data** (`2026_10_21_182108_add_order_tracking_to_project_materials_table`, reversibel):
  `ordered_at`, `ordered_by` (FK users, null on delete), `expected_at`, `order_note` +
  index `(request_channel, request_status)`. Status baru `DIRENCANAKAN` di
  `request_status` (string), kanal baru `RENCANA` di `request_channel`.
- **Satu status** — `ProjectMaterial::orderStatus()` → `MaterialOrderStatus`
  (DIRENCANAKAN · MENUNGGU_PM · DIAJUKAN · DISETUJUI · DITOLAK · DIPESAN · SEBAGIAN ·
  LENGKAP · DARI_GUDANG), di-append sebagai `order_status`. UI: `materialStatus()`
  (`materialArrival.ts`, `materialArrival()` kini membacanya) + `<MaterialStatusChip>`
  (`StatusChip domain="materialOrder"`, pemegang giliran di `waitingOn.ts`:
  Direncanakan → Pengawas, Diajukan/Disetujui/Dipesan/Sebagian → Logistik). Tampil di
  planner TM, tab Material (Rencana Bahan + tiap baris tabel sumber), kartu Pemesanan
  Bahan (Eksekusi, + "n sudah dipesan"), Ringkasan (baris "Rencana bahan belum
  diajukan ke Logistik"), halaman Logistik Pemesanan Bahan.
- **Siapa lewat Logistik** — `ProjectMaterialService::plansThroughLogistics()`: Pengawas
  proyek & Admin Proyek, kecuali yang juga merencanakan langsung (Logistik, PM proyek
  itu, Estimator). PM / Logistik / Estimator tetap seperti dulu (langsung DISETUJUI).
- **E1** — Pengawas / Admin Proyek (`seeCost` false: bukan CEO/PM/Asisten PM/Logistik/
  Estimator/Finance) tidak menerima `stock`/`cost_price` (materialOptions & relasi
  material) maupun `unit_price`/`cost_total` baris; kolom biaya & stok disembunyikan.
  Sumber dipilih server (`sourceFor`: stok ≥ jumlah → GUDANG, kurang → PEMBELIAN) saat
  rencana, saat jumlah diubah, dan diulang saat diajukan; `source` dari mereka diabaikan
  (Form Request: `nullable`). Logistik boleh mengganti sumber saat Setujui.
- **E2/E3** — katalog lewat `projects.materials.store/storeMany` (draf; item sama
  selagi draf = jumlah diganti), custom lewat `projects.material-plans.custom`
  (`StorePlanCustomMaterialRequest`: nama, spesifikasi, jumlah, satuan master, link foto,
  catatan; form "Simpan & tambah lagi"). "Ajukan ke Logistik (n bahan)" =
  `projects.material-plans.submit` → semua draf DIAJUKAN sekaligus, audit
  `logistics.material_plan_submitted`, satu notifikasi `material_plan_submitted` ke
  Logistik (lonceng → Pemesanan Bahan proyek itu). Rute: `role:QA|ADMIN_PROYEK` +
  `ProjectPolicy::planMaterials` + `plansThroughLogistics`.
- **Antrean** — `ActionInboxService::materialPlans()` (`material-plans`, satu baris per
  proyek "Pengajuan bahan · Proyek X" / "n bahan", badge menu Pemesanan Bahan). Baris
  Rencana Bahan dikeluarkan dari Pengajuan Barang (halaman, antrean `material-logistics`,
  pengingat harian) lewat `scopeNotFromPlan()`. Antrean TM Pengawas menghitung
  "bahan direncanakan" lewat `scopePlanned()` (termasuk draf).
- **Logistik** — halaman `logistics.material-orders.index` (`role:CEO|PM|ASISTEN_PM|LOGISTICS`,
  baris per `ProjectMaterial::visibleTo()`, draf tidak tampil; filter status/proyek,
  dikelompokkan per proyek, centang + "Setujui (n) / Tolak (n) / Tandai Dipesan (n)",
  kartu di HP). `logistics.material-orders.decide` (`decidePlan`: Setujui katalog,
  sumber/vendor opsional; Tolak + alasan, juga untuk custom) dan
  `logistics.material-orders.order` (`markOrdered`: hanya baris dibeli yang disetujui &
  belum diterima; perkiraan tiba ≥ hari ini, vendor, catatan; ulang = ubah tanggal) —
  `role:LOGISTICS`. Custom disetujui hanya lewat review katalog yang ada
  (`MaterialRequestService::review`, dialog yang sama). Penerimaan lewat `purchase` /
  `issue` yang sudah ada (dialog aksi kini menerima `only`). Semua keputusan diaudit
  (`logistics.material_plan_approved/rejected`, `logistics.material_ordered`).
- **E5** — `ProjectMaterialPolicy::revisePlan`: baris RENCANA diubah/dihapus Pengawas
  proyek / Admin Proyek selama DIRENCANAKAN (proyek berjalan, fase apa pun); sesudah
  diajukan hanya Logistik. PM tidak mengubah draf Pengawas.
- **Notifikasi Pengawas** (+ Admin Proyek yang merencanakan): `material_plan_rejected`
  (decidePlan / review TOLAK), `material_ordered`, `material_received` (sekali, saat
  baris jadi Lengkap / Dari gudang — juga baris non-rencana di proyek berpengawas).
- **Penyelesaian proyek** — baris DIAJUKAN menahan COMPLETED (seperti pengajuan lain);
  draf DIRENCANAKAN tidak.
- **Test** — `tests/Feature/Logistics/MaterialPlanTest.php` (6 test): E1 props tanpa
  stok/harga & sumber oleh server, custom + ajukan + notifikasi + antrean + lonceng,
  kunci E5, alur Setujui/Tolak/custom review/Dipesan/Sebagian/Lengkap/Dari gudang +
  notifikasi, RBAC halaman (CEO/PM proyek/Logistik ya, PM lain kosong, QA/Admin/Tukang
  403), penahan COMPLETED. `ExecutionFlowTest` "Pengawas revises…" disesuaikan ke E5.
- **Catatan** — syarat DP "materials" (`dpRequirements`) tetap terpenuhi oleh draf
  Rencana Bahan (scope `planned()`); belum diwajibkan "sudah diajukan".
