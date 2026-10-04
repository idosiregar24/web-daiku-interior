# Sprint 11 · Sub 4 — Pengajuan Barang (PM/Estimator & Tukang) ke Logistik

> Induk: [`../sprint-11-quotation-satuan-material.md`](../sprint-11-quotation-satuan-material.md) §1 (#13).
> Perluasan dari Sprint 12 keputusan #31: **Tukang** juga bisa mengajukan.
> Status: **selesai 2026-10-04** · Prasyarat: Sub 3

## Tujuan
Barang di luar katalog dan kebutuhan barang dari Tukang lewat pengajuan;
Logistik yang memutuskan & menginput barangnya.

## Dua jalur
```
A. PM / Estimator: Ajukan barang (nama, spesifikasi, satuan, qty, estimasi harga,
   alasan, vendor & link foto opsional) ──────────────────────────────┐
                                                                      ▼
B. Tukang (FIELD_STAFF): Ajukan barang (nama + jumlah + catatan)      Logistik meninjau
      → status MENUNGGU_PM, notif PM proyek                           (induk #13):
      → PM "Setujui Pengajuan" / "Tolak" (alasan) ────────────────▶   pakai katalog /
                                                                      daftarkan katalog /
                                                                      custom / tolak
```
- Tukang hanya untuk proyek tempat ia punya task (Policy). Isian minimal;
  Logistik melengkapi satuan, spesifikasi, harga saat meninjau.
- ACC jalur B: **PM proyek itu** (`pm_id`). Asisten PM ditambahkan di
  Sprint 12 Sub 11 (`Project::isManagedBy()`).
- Status baris: `MENUNGGU_PM` (jalur B saja) → `DIAJUKAN` → `DISETUJUI` /
  `DITOLAK`. Tidak boleh dibeli/dipakai sebelum DISETUJUI.
- Snapshot isian awal pengaju disimpan (`requested_snapshot`); Logistik
  boleh mengubah isi.
- Audit: `logistics.material_request_pm_approved/rejected`,
  `logistics.material_request_approved/rejected`.

## File yang disentuh
- `app/Services/ProjectMaterialService.php` (Sub 3), baru `MaterialRequestService`
- `app/Policies/` (baru `ProjectMaterialPolicy`), `app/Http/Controllers/Logistics/*`, routes `logistics.*`
- Halaman: antrean Logistik (`Pages/Logistics/MaterialRequests/Index.tsx`), form ajukan di tab Material proyek,
  form ajukan Tukang (halaman tugas/daily form Tukang), antrean PM
- `routes/console.php` (pengingat), `NotificationService`
- Test: `tests/Feature/Logistics/*`

## Checklist
- [x] **[Logistics]** `MaterialRequestService`: ajukan (PM/Estimator → DIAJUKAN; Tukang → MENUNGGU_PM), keputusan PM, tinjau Logistik (4 keputusan + ubah isi + snapshot), notifikasi, audit
- [x] **[UI]** Form ajukan (PM/Estimator lengkap; Tukang minimal), antrean PM, halaman antrean Logistik (barang mirip + riwayat pengajuan serupa, form keputusan)
- [x] **[Logistics]** Pengingat pengajuan belum ditinjau 1 hari kerja (job terjadwal, idempotent) + ringkasan tertunda untuk CEO
- [x] **[Logistics]** Retur barang custom: petakan ke katalog / daftarkan barang baru + harga gudang, lalu stok masuk
- [x] **[Projects]** Blokir COMPLETED juga bila ada pengajuan MENUNGGU_PM/DIAJUKAN
- [x] **[Test]** Tukang → PM → Logistik; Tukang di proyek lain 403; PM proyek lain 403; baris belum DISETUJUI tidak bisa dibeli/dipakai; PM/Estimator tidak bisa membuat custom langsung; 4 keputusan Logistik; pengingat tidak dobel
