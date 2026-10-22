# Sprint 32 — Pembagian Upah per Orang (File D)

> Status: **kode selesai 2026-10-22** — 2393 test lulus, build & Pint bersih, migrasi dev +
> demo jalan, cek headless browser (Pengawas desktop + HP 390 px). Di luar PRD;
> lanjutan Sprint 29. Sumber: `sprint-29/Upah Tim Interior DAIKU.xlsx`.

## 1. Asal

Sprint 29 Sub 08b (tim & pembagian per orang) **dibatalkan** 2026-10-21 —
upah berhenti di Kepala Tim. 2026-10-22 user meminta kembali dalam bentuk
**alat bantu**: Kepala Tim kadang gaptek, jadi Pengawas yang membantu membagi
("buat template … isi persentase, jumlah tukang dll"). Jawaban user:

| Pertanyaan | Jawaban |
|---|---|
| Bentuk | **Alat bantu + slip** — uang tetap dibayar Finance ke Pengawas → Kepala Tim; sistem hanya menghitung & menampilkan bagian tiap orang |
| Tim | **Template tim, dipakai ulang** lintas proyek; disalin per proyek (bisa disesuaikan) |
| Persen | **Bobot tingkatan karir** (Ketua Tim 24 · Tukang 1 17 · Tukang 2 15 · Helper 1 9 · Helper 2 7) — % = bobot ÷ Σ bobot tim |
| Perusahaan | **Ya, seperti Excel** — baris Perusahaan (bobot 5) ikut dihitung sebagai bagian perusahaan |

## 2. Dikerjakan

- [x] **[DB]** `career_levels` (isi File D di migrasi — production ikut), `work_teams` (nama unik, Kepala Tim, `members` JSON), `project_wage_splits` (proyek × label Kepala Tim, salinan anggota, template asal)
- [x] **[Backend]** `WageSplitService` — satu-satunya tempat rumus: % orang = bobot ÷ Σ bobot × 100; bagian orang = bagian tim di pembayaran (`supervisor_payments.breakdown`) × %, **rupiah bulat, sisa ke pecahan terbesar** (Σ persis = bagian tim; Excel membulatkan per orang → Perusahaan 50.032 dan Σ kurang Rp 1, sistem 50.033); template tidak pernah mengubah salinan proyek; semua simpan diaudit `wage_split.*`
- [x] **[Backend]** RBAC: ⚙ Pengaturan › **Tim Tukang** (`work-teams.*`, `role:CEO|PM|QA|ADMIN_PROYEK`), bobot tingkatan **CEO saja** (`work-teams.levels.update`); pembagian proyek = Pengawas proyek / Admin Proyek (`projects.wage-splits.save`, `role:QA|ADMIN_PROYEK` + `ProjectPolicy::splitWages`); prop `execution.wageSplit` hanya untuk yang melihat Upah Tukang (CEO/PM/Finance/Pengawas proyek + Admin Proyek), template hanya untuk pengisi
- [x] **[UI]** Halaman **Tim Tukang**: kartu per tim (anggota, bobot, %), kartu Bobot Tingkatan (CEO mengubah), dialog tim — tombol cepat "+ Ketua Tim / + Tukang 1 / …" (bobot terisi sendiri), % langsung terlihat, baris Perusahaan
- [x] **[UI]** Detail Proyek › Item RAB › **Pembagian Upah**: anggota per Kepala Tim (Atur Anggota → pilih template → sesuaikan, pratinjau rupiah dari pencairan terakhir), **Slip per pencairan** (DP / mingguan / pelunasan, tanpa yang ditolak) per orang + **Salin untuk WhatsApp**
- [x] **[Seed]** Template File D: Tim Putra (Σ 77), Tim Evan (Σ 72), Tim Yusup (Σ 76) — nama anggota karangan; Proyek Hendra: "Tim Putra" sudah dibagi, "Tim Yusuf" sengaja kosong (dicoba Pengawas)
- [x] **[Test]** `WageSplitTest`: bobot File D & hanya CEO, RBAC template (Finance/Logistik/Marketing 403), salinan proyek & penyesuaian, label Kepala Tim asing ditolak, angka DP 770.500 → 240.156 · 170.110 · 170.110 · 140.091 · 50.033, prop per role

## 3. Cara cek (akun `qa` / `password`)

1. Sidebar bawah › **Tim Tukang** — tiga tim File D; ubah satu, tambah orang dengan tombol tingkatan.
2. Proyek Hendra Gunawan › **Item RAB › Pembagian Upah** — Tim Putra sudah terbagi; **Atur Anggota** di Tim Yusuf → pilih "Tim Yusup" → Simpan.
3. Di **Slip Pembagian per Pencairan** — bagian tiap orang per pencairan; **Salin untuk WhatsApp**.
4. Login `ceo` → Tim Tukang → ubah Bobot Tingkatan (tim lama tidak berubah).
