# Sprint 12 · 14 — Nama Tombol & Penutup

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **selesai 2026-10-05** · Prasyarat: 01–13 (nama tombol bisa kapan saja) · Keputusan: #5

## Tujuan
Semua tombol memakai gaya **kata kerja + objek**; seeder demo, dokumentasi,
dan review keamanan Sprint 12 dirapikan.

## Langkah nama tombol (dua tahap)
1. **Inventaris**: grep label `<Button>`/`DropdownMenuItem`/`AlertDialogAction`
   di `resources/js/Pages` & `Components` → tabel "Halaman · Sekarang · Jadi"
   di file ini (bagian bawah) → **user mengecek dulu**.
2. **Terapkan** setelah disetujui; test yang mencari teks tombol ikut diperbarui.

Contoh gaya: Deal Desain → **Ajukan Desain/Survey** · Submit → **Kirim ke
PM** · Approve → **Setujui RAB** · Mark Paid → **Konfirmasi Pembayaran**.

## Checklist
- [x] **[UI]** Inventaris label tombol → tabel usulan untuk dicek user
- [x] **[UI]** Terapkan nama tombol yang disetujui
- [x] **[Setup]** DemoDataSeeder: lead dengan FU & survey, 3 jenis quotation di berbagai status, desain menunggu bayar/penugasan, proyek dibuka dari RAB dengan alokasi, realisasi, satu overrun, satu addendum
- [x] **[Docs]** `plan/README.md` (status + catatan deviasi PRD), `CLAUDE.md` (role ASISTEN_PM, KEPALA_DESAIN; alur quotation), `security-standards.md`
- [x] **[Setup]** `npm run build`, `pint --test`, `php artisan test`, `/security-review` (halaman publik, invoice, alokasi)

## Tabel usulan nama tombol

Inventaris 2026-10-05: semua `<Button>` / `DropdownMenuItem` di
`resources/js/Pages` & `Components` (di luar `ui/`). **Disetujui user
("terapkan semua") dan diterapkan 2026-10-05** — 94 label di 52 file.

**Tetap (tidak diusulkan diubah):** tombol "Batal" / "Simpan" / "Kembali" /
"Tutup" di dalam dialog (objeknya sudah jelas dari judul dialog); tautan
navigasi (Daftar Desain, Data Lead, Template KPI, Riwayat Stok, Statistik
Pipeline, Kembali ke …, Profil Saya, Lihat semua notifikasi); tombol ikon
tanpa teks (pakai `aria-label`); label yang sudah kata kerja + objek
(Tambah Lead, Catat Realisasi, Terbitkan Invoice, Buka Proyek, Minta Revisi,
Kirim Desain ke Klien, Setujui Penawaran, Batalkan RAB, dll.).

### A. Salah / menyesatkan (prioritas)

| # | Halaman | Sekarang | Jadi | Alasan |
|---|---|---|---|---|
| A1 | Quotation → RAB Builder | Submit ke CEO | **Kirim ke PM** | Sejak Sub 4 RAB direview PM / Asisten PM dulu, baru CEO |
| A2 | Quotation → Detail | Kirim ke Client | **Kirim ke Klien** | Bahasa Indonesia, seragam dengan "Kirim Desain ke Klien" |
| A3 | Desain → Detail (desain lama) | Client ACC | **Konfirmasi ACC Klien** | Bahasa Inggris |

### B. Bahasa Inggris → Indonesia

| # | Halaman | Sekarang | Jadi |
|---|---|---|---|
| B1 | QA → Detail | Approve / Reject | **Setujui QA** / **Tolak QA** |
| B2 | Form Harian | Submit | **Kirim Form Harian** |
| B3 | Proyek → Task, Task Saya | Update Status | **Ubah Status Task** |
| B4 | Menu pengguna (sidebar) | Log Out | **Keluar** |
| B5 | Lupa Password | Email Password Reset Link | **Kirim Link Reset Password** |
| B6 | Quotation → Detail | Export PDF / Export Excel | **Unduh PDF** / **Unduh Excel** |
| B7 | Finance, Transaksi, Kedisiplinan, Aset, Material | Export Excel | **Unduh Excel** |
| B8 | CRM, Karyawan, Proyek, Material, Milestone | Edit Lead / Edit Data / Edit Proyek / Edit | **Ubah Lead** / **Ubah Data Karyawan** / **Ubah Proyek** / **Ubah Material** / **Ubah Milestone** |

### C. Kata kerja tanpa objek → tambah objek

| # | Halaman | Sekarang | Jadi |
|---|---|---|---|
| C1 | Proyek → Finance | Tandai Dibayar | **Konfirmasi Pembayaran** (contoh di rencana) |
| C2 | Proyek → Dokumen, Kalender Termin, Invoice, Evaluasi | PDF / Excel | **Unduh PDF** / **Unduh Excel** |
| C3 | Lembur (dialog ajukan) | Ajukan | **Ajukan Lembur** |
| C4 | Lembur (keputusan) | Setujui / Tolak | **Setujui Lembur** / **Tolak Lembur** |
| C5 | Gaji (keputusan CEO) | Setujui / Tolak | **Setujui Perubahan Gaji** / **Tolak Perubahan Gaji** |
| C6 | Gaji | Ajukan Perubahan | **Ajukan Perubahan Gaji** |
| C7 | Evaluasi (keputusan) | Setujui / Kembalikan | **Setujui Evaluasi** / **Kembalikan Evaluasi** |
| C8 | Pengajuan Barang (PM) | Setujui / Tolak | **Setujui Pengajuan** / **Tolak Pengajuan** |
| C9 | Pengajuan Barang (Logistik) | Tinjau | **Tinjau Pengajuan** |
| C10 | Alokasi Dana & Executive Dashboard (overrun) | Setujui / Tolak | **Setujui Overrun** / **Tolak Overrun** |
| C11 | Invoice (Finance) | Bukti Bayar / Verifikasi / Tolak | **Kirim Bukti Bayar** / **Verifikasi Pembayaran** / **Tolak Pembayaran** |
| C12 | Gaji, Cicilan Aset, Upah Tukang | Bayar | **Bayar Gaji** / **Bayar Cicilan** / **Bayar Upah** |
| C13 | Penalti (dialog bayar) | Simpan (n) | **Catat Pembayaran Penalti** |
| C14 | Termin (dialog bayar) | Simpan Pembayaran | **Catat Pembayaran** |
| C15 | Kedisiplinan | Catat / Batalkan | **Catat Kedisiplinan** / **Batalkan Catatan** |
| C16 | KPI | Hitung | **Hitung KPI** |
| C17 | Material | Pemakaian Proyek | **Catat Pemakaian Proyek** |
| C18 | Desain → daftar | Tugaskan | **Tugaskan Desain** |
| C19 | Desain → diskusi | Kirim | **Kirim Pesan** |
| C20 | Proyek → dialog RAB Tambahan | Minta RAB | **Minta RAB Tambahan** |
| C21 | Quotation → Detail | Mulai Susun | **Mulai Susun RAB** |
| C22 | CRM, Quotation (penolakan klien) | Klien Menolak / Klien Menolak Penawaran | **Catat Penolakan Klien** |
| C23 | Form Harian | Isi Form | **Isi Form Harian** |
| C24 | Hutang Supplier, Cicilan Aset | Detail | **Lihat Detail** |
| C25 | Desain → Detail (desain lama) | Tambah Sub-Staff | **Tambah Asisten** (selaras label "Arsitek"/asisten di Sub 8) |
