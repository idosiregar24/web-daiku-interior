# Sprint 15 — Surat Resmi: Kop, Nomor, PDF & Link Klien RAB

> Status: **selesai 2026-10-06** (tampilan web link klien belum dicek di browser). Diminta user 2026-10-06 dengan contoh
> PDF "Invoice Jasa Desain Showroom Egika Desla" (kop Daiku, nomor
> `377/OFF/Daiku/IX/2026`, tabel Uraian/Ukuran P-T/Volume/Satuan/Harga,
> TERBILANG, Catatan, tanda tangan, footer hitam).
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Alur bisnis
> RAB/invoice (Sprint 12, 14) **tidak** berubah — hanya bentuk dokumen.

## 1. Permintaan
1. Cetak PDF penawaran & invoice mengikuti format surat contoh; halaman
   **link klien** (`penawaran/{token}`) disesuaikan dengan format yang sama.
2. Di detail klien (lead), staf melihat semua RAB klien itu (Survey,
   Desain, Proyek, custom) — terdokumentasi — dan tiap RAB punya link
   klien sendiri.

## 2. Keputusan (dijawab user 2026-10-06)
| # | Pertanyaan | Keputusan |
|---|---|---|
| K1 | Riwayat RAB di mana | Detail klien di sistem (staf); link klien tetap **per RAB** (tiap RAB link sendiri) |
| K2 | Nomor surat | `{n}/OFF/Daiku/{bulan romawi}/{tahun}` untuk penawaran, `{n}/INV/Daiku/…` untuk invoice — **satu urutan nomor** berlanjut sepanjang tahun |
| K3 | Tanda tangan | Gambar tanda tangan diunggah + nama penandatangan di Pengaturan Situs |
| K4 | Catatan | Teks bawaan per jenis (Survey/Desain/Proyek) di Pengaturan; Estimator bisa mengubah per RAB; baris rekening bank otomatis |

## 3. Sub-plan
| # | Sub-plan | Isi | Task |
|---|---|---|---|
| 01 | [Data kop surat](sprint-15/01-data-kop-surat.md) | Pengaturan Situs: Instagram, nama badan usaha (a.n. rekening), tagline footer, nama + gambar tanda tangan, catatan bawaan per jenis | 3 |
| 02 | [Nomor surat, catatan & terbilang](sprint-15/02-nomor-catatan-terbilang.md) | Urutan nomor OFF/INV per tahun; catatan per RAB; terbilang Rupiah | 4 |
| 03 | [PDF format surat](sprint-15/03-pdf-format-surat.md) | Penawaran, invoice (+ termin) dengan kop, tabel, terbilang, catatan, tanda tangan, watermark, footer | 3 |
| 04 | [Link klien format surat](sprint-15/04-link-klien-format-surat.md) | Halaman `penawaran/{token}` mengikuti format surat | 3 |
| 05 | [Riwayat RAB lengkap](sprint-15/05-riwayat-rab.md) | Kartu Riwayat RAB: nomor, PDF + link klien per RAB | 3 |
