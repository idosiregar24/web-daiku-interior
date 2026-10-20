# Sprint 29 · 11 — Bahan Custom → Ajukan ke Logistik + Status Pemesanan

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Stok barang tidak usah ditampilkan di Pengawas" + ronde 4 · Keputusan E1–E5
> Status: **belum dikerjakan** · Bergantung: Sub 01 (form bahan cepat)

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
- [ ] **[DB]** `project_materials`: `plan_status` (DIRENCANAKAN/DIAJUKAN …) atau perluasan `request_status`, `ordered_at`, `ordered_by`, `expected_at`
- [ ] **[Backend]** `ProjectMaterialService::submitPlan()`, `markOrdered()`; props tanpa stok untuk QA; sumber oleh server; antrean & notifikasi Logistik/Pengawas
- [ ] **[UI]** Planner (katalog + custom, beberapa baris, Ajukan ke Logistik); halaman antrean Logistik (Setujui semua, Tandai Dipesan + perkiraan tiba); chip status di semua tempat
- [ ] **[UX]** Tanpa menunggu (Sub 01 K1–K6); Logistik bisa memproses banyak baris sekaligus; HP 390 px (Logistik di gudang, Pengawas di lokasi)
- [ ] **[Test]** Pest: QA tidak menerima `stock`; alur Direncanakan → Diajukan → Disetujui → Dipesan → Diterima; RBAC (Pengawas ajukan, Logistik proses, QA lain 403); custom lewat review katalog
- [ ] **[Build]** `npm run build`, Pint
