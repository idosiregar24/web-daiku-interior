# Sprint 29 · 08b — Tim Tukang & Pembagian Upah per Orang

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Untuk pembagian upah yang bisa dicairkan itu ada perhitungannya" · File D `Upah Tim Interior DAIKU.xlsx` · Keputusan B9 · Q34–Q36
> Status: **belum dikerjakan** · Bergantung: Sub 08 · Dikerjakan sebelum Sub 09

## Dari Excel (File D)
- Master bobot **Tingkatan Karir**: Ketua Tim 24 · Tukang 1 17 · Tukang 2 15 ·
  Helper 1 9 · Helper 2 7 · Perusahaan 5.
- Tiap tim: anggota + bobot (boleh beda dari master); % orang = bobot ÷ Σ
  bobot tim × 100 (Tim Putra Σ 77: Ketua 31,17 %, Tukang 1 22,08 % ×2,
  Tukang 2 18,18 %, Perusahaan 6,49 %).
- Pencairan: DP 10 % · Termin 1 13 % · Termin 2 22 % · Termin 3 22 % … (% dari
  total upah tim); bagian orang = upah tim × % pencairan × % orang.

## Rancangan
- **Master Tingkatan Karir** (`career_levels`: nama, bobot default, urutan,
  aktif; "Perusahaan" ditandai `is_company`) — ⚙ Pengaturan › Data Master,
  diubah CEO/SUPERADMIN. Seed = angka File D.
- **Tim Tukang** (`work_teams`: nama "Tim Putra", Kepala Tim = user / nama;
  `work_team_members`: user tukang **atau** nama, tingkatan, bobot — default
  dari master, boleh diubah). Tim dipakai ulang lintas proyek (Q36); anggota &
  bobot bisa disesuaikan per proyek (salinan `project_team_members` saat tim
  dipilih untuk proyek, supaya riwayat pembagian proyek lama tidak berubah).
  Baris **Perusahaan** otomatis ada di tiap tim (bobot master).
- Kepala Tim per item (Sub 08) = **pilih Tim**, bukan hanya nama.
- **Rincian pembagian** — `WageSplitService::split(amount, projectTeam)`:
  nominal × bobot ÷ Σ bobot, pembulatan Rupiah dengan sisa pembulatan ke Ketua
  Tim (Σ rincian = nominal persis). Tampil di: pengajuan DP/pencairan (Pengawas
  & PM), antrean Finance, slip pembayaran (PDF/print, untuk diserahkan ke
  tim). Disimpan sebagai snapshot di pengajuan (append-only).
- **Bagian Perusahaan** (Q34, usulan): tidak dibayarkan — Finance membayar Σ
  bagian anggota; bagian perusahaan tercatat di rincian ("tetap di
  perusahaan"). Bila user menjawab lain, cukup ubah satu aturan di service.
- **Tabel ringkas seperti Excel** di kartu Upah Tukang: per tim — Upah · DP ·
  Pencairan 1…n (% dan Rupiah) · Sisa, plus rincian per orang bila dibuka.

## Checklist
- [ ] **[DB]** `career_levels` (+ seed File D), `work_teams`, `work_team_members`, `project_team_members` (snapshot per proyek)
- [ ] **[Backend]** CRUD master (CEO/SUPERADMIN), CRUD tim (Pengawas, Admin Proyek, PM), `WageSplitService` (pembulatan, Σ persis), snapshot rincian di pengajuan
- [ ] **[UI]** Data Master › Tingkatan Karir; halaman / dialog Tim Tukang; pilih tim di kartu Upah Tukang; rincian per orang di pengajuan & Finance; slip cetak
- [ ] **[UX]** Bobot default terisi otomatis dari tingkatan, % langsung terlihat saat mengetik; HP 390 px; tanpa menunggu (Sub 01)
- [ ] **[Test]** Pest: angka File D — Tim Putra DP 770.500 → Ketua 240.156, Tukang 1 170.110 ×2, Tukang 2 140.091, Perusahaan 50.032 (Σ persis); Termin 1 13 % = 1.001.650; Tim Evan Σ 72 → Ketua 33,33 %; ubah master tidak mengubah snapshot proyek lama; RBAC
- [ ] **[Build]** `npm run build`, Pint
