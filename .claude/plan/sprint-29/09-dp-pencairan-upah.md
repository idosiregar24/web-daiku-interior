# Sprint 29 · 09 — DP & Pencairan Upah dari Pengecekan % per Item

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Keputusan B4–B6, C1 · Rumus §2 (File B)
> Status: **kode selesai 2026-10-21** — tersisa cek browser (HP 390 px Pengawas, dialog PM), `npm run build` & `/security-review` · Bergantung: Sub 02, 08
>
> **Perubahan dari rancangan:** Sub 08b dibatalkan user ("upah tidak usah sampai ke tukang, per simple saja") —
> tidak ada `WageSplitService`, tim, pembagian per orang, atau slip. "Rincian per tim dan per orang" diganti
> **rincian per Kepala Tim** (`ProjectWorkItem::teamLeadLabel()`), dibekukan di `supervisor_payments.breakdown`
> saat diajukan (diskalakan bila PM mengubah nominal). Progres berbobot hanya **ditampilkan** di form pengecekan;
> `Project::actualProgress()` / 70 % tetap actual activity sampai Sub 10.

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
- [x] **[DB]** `work_item_progress` (item, `project_id`, `checked_on`, `percent` DECIMAL(5,2) bertanda, `recorded_by`, `weekly_report_id`, `note`, timestamps; model `WorkItemProgress` melempar saat update/delete) · `work_item_progress_drafts` (satu baris per proyek: `entries` JSON, `notes`, `links`, `updated_by` — dihapus saat dikirim) · `supervisor_payments.breakdown` JSON
- [x] **[Backend]** `WorkItemService::progress()`, `weightedProgress()`, `payableWage()`, `alreadyPaid()`, `payableNow()`, `allDone()`, `figures()`, `teamShares()`, `recordProgress()` (Σ 0–100, minus = koreksi + catatan), `saveDraft()`, `checkOverview()`; `WeeklyReportService::submit` menyimpan % + `SupervisorPaymentService::requestForReport()` (MINGGUAN DIAJUKAN = cair kali ini, **PELUNASAN** bila semua item 100 %); `decide` laporan = keputusan pengajuannya (turunkan / 0 = tidak dicairkan / tolak → % tetap); pembayaran pengecekan tidak bisa diputuskan terpisah selama laporannya DIAJUKAN; DP 10 % × total upah (+ rincian per Kepala Tim); `requestSettlement` untuk proyek berupah per item = semua item 100 % (proyek lama tetap COMPLETED); proyek tanpa item kerja tetap nominal mingguan diketik PM
- [x] **[Backend]** Notifikasi PM "Pengajuan Upah Tukang · Minggu ke-N" (satu notifikasi, menggantikan "Laporan Mingguan" bila ada pengajuan), Finance "Bayar Upah Tukang", Pengawas saat dibayar (sudah ada)
- [x] **[UI]** `WorkCheckForm` (tabel item berupah: Uraian · Kepala Tim · Sebelumnya · Tambahan % · Progres baru; ringkasan langsung `lib/workCheck.ts` = cermin rumus server: progres berbobot, upah boleh cair, sudah cair, cair kali ini, per Kepala Tim); dialog PM (`ExecutionDecisionDialog` + rincian % & per Kepala Tim, nominal boleh 0); kartu Upah Tukang menampilkan rincian & "diputuskan bersama pengecekan"; Finance › Upah Tukang menampilkan rincian Kepala Tim; label "Ajukan DP Upah · Rp x"
- [ ] **[UX]** Isi % cepat (tombol +10/+20/+50/100 %) ✓, draf otomatis 1,5 dtk (`async`, tanpa pop-up) ✓, ConfirmDialog saat kirim ✓ — **belum dicek di browser HP 390 px**
- [x] **[Test]** Pest (`ExecutionFlowTest`, 7 tes baru): DP 10 % + rincian; angka File B (69,48 %, Rp 5.322.000, cair kali ini Rp 4.551.500; PM menurunkan → sisa Rp 551.500 bisa diajukan lagi; di atas total ditolak); Σ % > 100 / minus tanpa catatan / item tanpa upah ditolak, append-only; RBAC (Pengawas kirim, Admin Proyek draf saja → kirim 403, QA lain / PM / Marketing 403, draf sebelum produksi 403); tolak → % tetap & diajukan ulang; pelunasan 100 % (manual & otomatis); dua Kepala Tim
- [ ] **[Build]** Pint ✓, `npx tsc --noEmit` ✓ — `npm run build` & `/security-review` (uang) belum
