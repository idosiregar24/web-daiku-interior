# Sprint 29 — Sub-plan & File Excel Proses Bisnis

Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md)
(revisi user, rumus Excel, alur kerja, keputusan, kecepatan & UX).

## Sub-plan — "Kerjakan Sprint 29 Sub N"

| # | Sub | Bergantung | Status |
|---|---|---|---|
| 01 | [Kecepatan simpan Detail Proyek + form bahan tanpa menunggu](01-kecepatan-simpan.md) | — | kode selesai — tunggu cek browser |
| 02 | [Pengecekan mingguan sebelum Jumat](02-pengecekan-sebelum-jumat.md) | 01 | kode selesai — tunggu cek browser |
| 03 | [TM Selesai tanpa bagian Pengawas](03-tm-selesai.md) | 01 | kode selesai — tunggu cek browser |
| 04 | [Daily target: approval & centang Pengawas](04-daily-target-pengawas.md) | 01 | kode selesai (Admin Proyek menyusul Sub 07) — tunggu cek browser |
| 05 | [Geser & tukar task bebas](05-geser-bebas.md) | 01 | kode selesai — tunggu cek browser |
| 06 | [Banyak Pengawas](06-banyak-pengawas.md) | 01 | kode selesai — tunggu cek browser |
| 07 | [Admin Proyek](07-admin-proyek.md) | 06 | kode selesai — tunggu cek browser |
| 08 | [Upah Tukang per item RAB](08-upah-tukang.md) | 06, 07 | kode selesai — tunggu cek browser |
| 08b | [Tim Tukang & pembagian upah per orang](08b-tim-tukang-pembagian-upah.md) | 08 | **dibatalkan** (upah sampai Kepala Tim saja) |
| 09 | [DP & pencairan upah dari pengecekan %](09-dp-pencairan-upah.md) | 02, 08 | kode selesai — tunggu cek browser & /security-review |
| 10 | [Progres proyek berbobot](10-progres-berbobot.md) | 09 | kode selesai — tunggu cek browser |
| 11 | [Bahan custom → Logistik + status pemesanan](11-bahan-logistik-status.md) | 01 | kode selesai — tunggu cek browser |
| 12 | [QA per item RAB](12-qa-per-item.md) | 09 | kode selesai — tunggu cek browser |
| 13 | [Aktivitas Harian & pemeriksaan HR](13-aktivitas-harian.md) | 01 | kode selesai — tunggu cek browser |
| 14 | [KPI dari Aktivitas Harian](14-kpi-aktivitas.md) | 13 | kode selesai — tunggu cek browser |
| 15 | [Uji alur, kecepatan ulang & dokumen](15-uji-dokumen.md) | semua | sebagian — ukur kecepatan, cek browser & /security-review → [Sprint 30](../sprint-30-verifikasi-sprint-29.md) |

Setiap sub punya checklist **[UX]** (standar induk §5c) dan memakai pola simpan
cepat Sub 01 (induk §5b K1–K7).

## File Excel proses bisnis

Taruh di sini file Excel yang dipakai tim sekarang (di luar sistem). Analisis:
induk §2.

| File | Isi | Dipakai untuk |
|---|---|---|
| `Penawaran Bang Binsar.xlsx` | RAB klien (harga per item) + tabel kedua **Satuan Upah** per item → subtotal upah | Sub 08 — Pengawas mengisi upah di samping harga RAB |
| `PROGRES PERSENTAS Bulan AGUSTUS 28-2.xlsx` | Progres per item per pengecekan (CEK 1–5, tanggal), bobot = nilai RAB item, persentase per Vendor/Kepala Tim | Sub 09 — pengecekan % & pencairan upah |
| `SIMULASI PERSENTASI.xlsx` | Simulasi yang sama per hari (25 Sep – 31 Okt), Harga → Nilai → Bobot, total % proyek, jadwal per item (sel biru muda) | Sub 10 — rumus bobot & progres berbobot |
| `Upah Tim Interior DAIKU.xlsx` | Master bobot tingkatan karir (Ketua Tim 24 … Perusahaan 5), anggota & % per tim (Putra, Evan, Yusup), DP / Termin 1–8 sebagai % upah tim → bagian per orang | Sub 08b — pembagian upah per orang |

File baru: tambahkan baris di tabel ini.
