# Sprint 29 · 08 — Upah Tukang per Item RAB (ganti nama Jasa Pengawas)

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Jasa Pengawas → Upah Tukang", "Pengawas yang menentukan upah" · Keputusan B1–B3, B8, D3 · Rumus §2 (File A)
> Status: **kode selesai 2026-10-21** — backfill: `php artisan daiku:work-items` sekali di server; tab lama per-task jadi "Upah per Task"; tersisa cek browser (HP: kartu per item) · Bergantung: Sub 06, 07

## Keputusan user
> "Jasa Pengawas → Upah Tukang itu penamaannya saja yang diganti, jadi satu
> projek itu ada jumlah upah tukangnya per item." · Pengawas mengisi "nilai
> jasa" di samping RAB dan harga per itemnya, lalu dihitung nilai & bobotnya.

## Rancangan
- **Ganti nama** (B1): semua teks "Jasa Pengawas" → "Upah Tukang" (kartu,
  notifikasi, antrean Finance "Bayar Upah Tukang: DP · Proyek X", label
  kategori transaksi, menu Finance › Pembayaran Staf › Upah Tukang). Kode
  `JASA_PENGAWAS`, tabel `supervisor_fees` / `supervisor_payments` **tetap**
  (riwayat append-only).
- **Item kerja** (B2): tabel `project_work_items` (`project_id`,
  `quotation_item_id` UNIQUE, `team_lead_id` nullable, `team_lead_name`
  nullable, `unit_wage` DECIMAL(15,2) nullable, `set_by`, timestamps). Dibuat
  otomatis saat Buka Proyek (`ProjectService::openFromQuotation`) dan saat RAB
  Tambahan disetujui; proyek ACTIVE yang sudah ada di-backfill (perintah
  artisan sekali jalan). Kepala Tim default = Kepala Tim proyek.
- **Service** `WorkItemService` — satu-satunya tempat rumus: `subtotalWage`
  (satuan upah × volume, `Quantity`/perseratus), `weight` (subtotal RAB ÷ Σ
  subtotal RAB item berupah × 100), `totalWage`, ringkasan per Kepala Tim.
  `supervisor_fees.total_amount` = `totalWage` (disinkronkan setiap upah
  berubah, dikunci setelah pembayaran pertama melebihi — `ensureWithinFee`).
- **Kartu "Upah Tukang"** (B3) di Eksekusi (TM sampai PERSIAPAN; read-only
  sesudahnya kecuali item baru dari RAB Tambahan): tabel per bagian RAB —
  Uraian · Volume · Satuan · Harga RAB · Subtotal RAB · **Satuan Upah**
  (`MoneyInput`, simpan per baris saat keluar kolom — Sub 01, tanpa pop-up) ·
  Subtotal Upah · Bobot · Kepala Tim (pilih per item, atau "terapkan ke
  bagian ini"). Baris aksesoris boleh kosong ("tanpa upah"). Ringkasan bawah:
  total upah, per Kepala Tim, jumlah item tanpa upah.
- **Akses**: isi = Pengawas proyek + Admin Proyek (+ SUPERADMIN); lihat = PM,
  CEO, Finance (nilai), Pengawas. **Harga RAB terbuka untuk Pengawas** —
  `security-standards.md` dicatat (Sprint 24 U4 berubah).
- **Syarat DP** (D3): "upah ✓" = ≥ 1 item berupah (bukan `rate_per_task`).
  `rate_per_task` disembunyikan dari form daily target untuk proyek yang
  punya item kerja (B8, Q14d).

## Checklist
- [x] **[DB]** `project_work_items` + backfill command
- [x] **[Backend]** `WorkItemService` (rumus), pembuatan otomatis (Buka Proyek, RAB Tambahan), sinkron total ke `supervisor_fees`, route simpan upah & Kepala Tim (+ Policy Pengawas / Admin Proyek)
- [x] **[Backend]** Ganti nama di notifikasi, antrean, label kategori; `dpRequirements()` upah dari item
- [x] **[UI]** `WorkItemWageCard` (tabel File A), ganti nama di `SupervisorFeeCard` / Finance; form daily target tanpa upah untuk proyek baru
- [ ] **[UX]** Simpan per baris instan (Sub 01), Tab/Enter pindah ke baris berikutnya, total langsung terhitung di layar, HP: kartu per item
- [x] **[Test]** Pest: angka File A (Dapur Bersih total Rp 7.705.000; bobot dari nilai RAB); RBAC isi (Pengawas, Admin Proyek ya; PM, Marketing 403); harga RAB tidak dikirim ke Marketing/Tukang
- [x] **[Build]** `npm run build`, Pint
