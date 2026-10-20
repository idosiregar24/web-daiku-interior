# Sprint 29 · 03 — TM Selesai Tanpa Bagian Pengawas

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Langkah 3 dan 4 diselesaikan di TM" · Keputusan D1–D3
> Status: **belum dikerjakan** · Bergantung: Sub 01

## Keputusan user
> "Fase untuk upah dan daily target itu biarkan Pengawas saja, jadi Pengawas
> yang isi bagian itu dan TM bisa dibuat statusnya selesai." · "Tidak harus
> di hari H atau di saat TM."

## Kondisi sekarang
"TM Selesai" (`TechnicalMeetingService`, `CompleteMeetingButton` "n/6")
mensyaratkan notulen, ≥1 peserta, ≥1 timeline, ≥1 bahan, **jasa pengawas
terisi**, Tanggal Selesai. Kartu TM: 1 Jadwal & Rapat · 2 Timeline · 3 Jasa
Pengawas & Tanggal Selesai · 4 Bahan (Pengawas).

## Rancangan
- Syarat "TM Selesai" = **bagian PM saja**: tanggal & notulen · ≥1 peserta ·
  ≥1 timeline · Tanggal Selesai proyek (4/4).
- Kartu TM: **1 Jadwal & Rapat · 2 Timeline · 3 Tanggal Selesai** (PM) +
  kotak terpisah **"Bagian Pengawas"** — Bahan & Upah Tukang, ditandai
  "boleh diisi setelah TM, sebelum Start Produksi" (Upah Tukang baru penuh di
  Sub 08; sementara tetap kolom nilai jasa yang ada, tidak lagi wajib).
- Syarat DP (kartu Syarat, PERSIAPAN) tetap memeriksa bahan · upah · daily
  target (D3) — jadi bagian Pengawas tetap wajib, hanya pindah waktunya.
- Pengawas melihat langkahnya di "Giliran Anda" saat PERSIAPAN (sudah ada,
  teks disesuaikan).

## Checklist
- [ ] **[Backend]** `TechnicalMeetingService::complete()` / checklist: hapus syarat bahan & jasa; `SaveTechnicalMeetingRequest` jasa opsional
- [ ] **[UI]** `TechnicalMeetingCard` dipecah (PM 3 langkah + "Bagian Pengawas"); `CompleteMeetingButton` "n/4"
- [ ] **[UX]** Penjelasan singkat di kotak Pengawas; `PageHeader step` PERSIAPAN = "Pengawas melengkapi bahan, upah, daily target"
- [ ] **[Test]** Pest: TM Selesai tanpa bahan & jasa diterima; syarat DP tetap menolak tanpa bahan/upah; test lama `ExecutionFlowTest` disesuaikan
- [ ] **[Build]** `npm run build`, Pint
