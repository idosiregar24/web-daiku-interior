# Sprint 13 · 06 — Tombol "+ Buat" Global, Pencarian & Terakhir Dibuka

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 03 · Keputusan: #6, #11 (sisa), #12 · Default: D3, D5

## Tujuan
Menambah data dan mencari sesuatu tidak perlu mencari menunya dulu.

## File yang disentuh
- `resources/js/Layouts/AppLayout.tsx` (topbar), `resources/js/Components/shared/CommandMenu.tsx`, `resources/js/Components/shared/QuickCreateMenu.tsx` (baru)
- Halaman tujuan "+ Buat" (membuka dialog tambahnya saat `?create=1`): CRM, Transaksi, Pengajuan Barang, Lembur, Material, Karyawan
- `app/Http/Controllers/SearchController.php` + `routes/web.php` (`search`, `auth`, `throttle:60,1`)
- Test: `tests/Feature/SearchTest.php`

## Rancangan
- **"+ Buat"**: daftar `QUICK_CREATE` (label, ikon, routeName halaman,
  `roles`) di samping `NAV_GROUPS`. Memilih item = kunjungi halaman itu
  dengan `?create=1`; halaman membuka dialog tambahnya sekali lalu
  menghapus query (`replace`). Tombol disembunyikan bila role tidak punya
  aksi tambah.
- **Pencarian**: `CommandMenu` tampil sebagai kolom selebar `md:w-72`
  dengan teks "Cari menu, proyek, klien…" (+ petunjuk Ctrl K). Di HP tetap
  ikon. Hasil: Menu (lokal, seperti sekarang) + data dari `GET search?q=`
  (debounce 250 ms, min. 2 huruf): Proyek, Lead, Quotation, Karyawan
  (CEO/HR) — maks. 5 per jenis, query memakai scope/Policy daftar
  masing-masing (Asisten PM hanya proyeknya, Marketing tanpa angka finance).
- Respons hanya `id`, `label`, `sublabel`, `url` — tidak ada model mentah.
- **Terakhir dibuka** (dari Sub 07 yang dibatalkan): 5 menu terakhir
  disimpan di `localStorage` (try/catch, aman bila kosong/diblokir), tampil
  sebagai grup pertama `CommandMenu` saat kolom cari masih kosong. Menu
  yang tidak lagi boleh dilihat role itu diabaikan.

## Checklist
- [ ] **[UI]** `QuickCreateMenu` per role + dukungan `?create=1` di 6 halaman
- [ ] **[UI]** Kolom cari yang terlihat di topbar desktop
- [ ] **[Backend]** Endpoint `search` (scope per role, whitelist field, throttle)
- [ ] **[UI]** Hasil data di `CommandMenu` dengan grup per jenis
- [ ] **[UI]** "Terakhir dibuka" di `CommandMenu` (`localStorage`)
- [ ] **[Test]** `search`: tiap role hanya mendapat yang boleh dilihat (Asisten PM proyek lain absen, Tukang hanya proyeknya), tamu 302; `/security-review` untuk endpoint ini
