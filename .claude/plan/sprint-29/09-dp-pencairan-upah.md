# Sprint 29 · 09 — DP & Pencairan Upah dari Pengecekan % per Item

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Keputusan B4–B6, C1 · Rumus §2 (File B)
> Status: **belum dikerjakan** · Bergantung: Sub 02, 08

## Keputusan user
> "PM menyetujui pencairan upah, ada DP upah." · "DP upah 10 %, PM. DP yang
> disetujui adalah tanda Start." · "Yang bisa diajukan ke Finance adalah
> berapa progress yang sudah dikerjakan dan dihitung berdasarkan upah itu." ·
> Pengawas yang mengajukan dan menyerahkan uang ke Kepala Tim; bukti tidak
> wajib.

## Rancangan
- **DP upah** (B4): kartu Syarat DP → "Ajukan DP Upah" (usulan 10 % × total
  upah, tampil nominalnya) → PM "Setujui DP Upah" (nominal bisa diubah,
  `ExecutionDecisionDialog`) = **Start Produksi** (`ProjectPhaseService`,
  seperti sekarang) → antrean Finance → dibayar ke Pengawas.
- **Pengecekan % per item** (C1): form laporan mingguan berisi tabel item
  berupah — Uraian · Kepala Tim · Progres sebelumnya · **Tambahan % hari
  ini** (0 … sisa) · Progres baru — seperti kolom CEK File B. Disimpan di
  `work_item_progress` (append-only; koreksi = baris negatif + catatan; Σ per
  item 0–100). Draf tersimpan otomatis (Admin Proyek boleh mengisi draf).
- **Ringkasan langsung di form**: progres proyek (bobot), upah boleh cair,
  sudah cair (DP + sebelumnya), **cair kali ini**, rincian per Kepala Tim.
- **Kirim pengecekan** (Pengawas) = pengajuan pembayaran jenis MINGGUAN
  sebesar "cair kali ini" → PM setujui (boleh menurunkan) / tolak (alasan
  wajib; % tetap tercatat, nominal bisa diajukan lagi minggu depan) →
  Finance. **Pelunasan**: semua item 100 % → pengajuan PELUNASAN = sisa.
- Batas: Σ pembayaran ≤ total upah (`ensureWithinFee`, kunci baris proyek).
- Penerima = Pengawas yang mengajukan; setiap DP/pencairan memuat **rincian
  per tim dan per orang** dari Sub 08b (`WageSplitService`, snapshot) —
  tampil di pengajuan, dialog PM, antrean Finance, dan slip cetak. Pengawas
  boleh menambah catatan/foto serah ke Kepala Tim (opsional).

## Checklist
- [ ] **[DB]** `work_item_progress` (item, `checked_on`, `percent` DECIMAL(5,2), `recorded_by`, `weekly_report_id`, `note`, timestamps)
- [ ] **[Backend]** `WorkItemService::progress()`, `payableWage()`, `alreadyPaid()`; `WeeklyReportService::submit` menyimpan % + membuat pengajuan; DP dari total upah; pelunasan otomatis saat 100 %
- [ ] **[Backend]** Notifikasi PM "Pengajuan Upah Tukang · Minggu ke-N", Finance "Bayar Upah Tukang", Pengawas saat dibayar
- [ ] **[UI]** Form pengecekan % per item + ringkasan uang; dialog PM dengan rincian; Finance › Upah Tukang menampilkan rincian Kepala Tim
- [ ] **[UX]** Isi % cepat (tombol +10/+20/+50/Selesai 100 %), draf otomatis, HP 390 px untuk Pengawas; ConfirmDialog saat kirim pengajuan uang
- [ ] **[Test]** Pest: angka File B (Putra 69,48 %, upah boleh cair Rp 5.322.000; DP Rp 770.500 → cair kali ini Rp 4.551.500); Σ % > 100 ditolak; tidak melebihi total upah; RBAC (Pengawas kirim, Admin Proyek draf saja, PM setujui, Finance bayar)
- [ ] **[Build]** `npm run build`, Pint, `/security-review` (uang)
