# Sprint 13 · 10 — PWA & Ringan untuk Android

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 09 · Keputusan: H7, H8 · Default: D6

## Tujuan
Aplikasi bisa dipasang di layar utama HP dan terasa cepat di Android
kelas bawah–menengah.

## File yang disentuh
- `public/manifest.webmanifest`, ikon 192/512 + maskable (dari logo Pengaturan Situs bila ada, fallback logo bawaan)
- `public/sw.js` (minimal — hanya agar bisa dipasang; **tanpa** cache offline, H6)
- `resources/views/app.blade.php` (`<link rel="manifest">`, `theme-color`, `apple-touch-icon`)
- `resources/js/app.tsx` (daftarkan service worker di production saja)
- `resources/js/Pages/Auth/Login.tsx` (`remember` default `true`, D6)
- `vite.config.ts` (bila perlu `manualChunks` agar Recharts/TanStack tidak masuk chunk halaman tukang)

## Rancangan
- `display: standalone`, `start_url` = `/` (RoleRedirect mengarahkan),
  nama & warna dari `site` (Pengaturan Situs).
- Anggaran ringan: halaman tukang (Hari Ini, Tugas, Lembur, Lainnya,
  Penalti, Pengajuan Barang) tidak mengimpor Recharts/TanStack/DataTable;
  cek output `vite build` (catat ukuran chunk di sini). Hindari animasi
  besar; `prefers-reduced-motion` dihormati.
- Uji di Chrome DevTools: throttling "Low-end mobile" + CPU 4×.

## Checklist
- [ ] **[Setup]** Manifest + ikon + service worker minimal; bisa "Tambahkan ke layar utama"
- [ ] **[Setup]** "Ingat saya" tercentang default
- [ ] **[Setup]** Chunk halaman tukang bebas Recharts/TanStack; ukuran dicatat
- [ ] **[Test]** Lighthouse PWA "installable" lulus; uji throttling low-end dicatat di sini
