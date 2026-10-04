# Sprint 12 · 14 — Nama Tombol & Penutup

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **belum dikerjakan** · Prasyarat: 01–13 (nama tombol bisa kapan saja) · Keputusan: #5

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
- [ ] **[UI]** Inventaris label tombol → tabel usulan untuk dicek user
- [ ] **[UI]** Terapkan nama tombol yang disetujui
- [ ] **[Setup]** DemoDataSeeder: lead dengan FU & survey, 3 jenis quotation di berbagai status, desain menunggu bayar/penugasan, proyek dibuka dari RAB dengan alokasi, realisasi, satu overrun, satu addendum
- [ ] **[Docs]** `plan/README.md` (status + catatan deviasi PRD), `CLAUDE.md` (role ASISTEN_PM, KEPALA_DESAIN; alur quotation), `security-standards.md`
- [ ] **[Setup]** `npm run build`, `pint --test`, `php artisan test`, `/security-review` (halaman publik, invoice, alokasi)

## Tabel usulan nama tombol
_(diisi pada langkah inventaris)_
