# Sprint 13 · 10 — PWA & Ringan untuk Android

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **selesai sebagian 2026-10-05** (uji Lighthouse/throttling menunggu MySQL + Chrome) · Prasyarat: 09 · Keputusan: H7, H8 · Default: D6

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
- [x] **[Setup]** Manifest + ikon + service worker minimal; bisa "Tambahkan ke layar utama"
- [x] **[Setup]** "Ingat saya" tercentang default
- [x] **[Setup]** Chunk halaman tukang bebas Recharts/TanStack; ukuran dicatat
- [ ] **[Test]** Lighthouse PWA "installable" lulus; uji throttling low-end dicatat di sini
  📌 **Status: Sebagian** — feature test `tests/Feature/PwaTest.php` lulus (manifest, ikon PNG per ukuran, logo → ikon, SW tanpa cache, `<link rel="manifest">`). Lighthouse + throttling "Low-end mobile"/CPU 4× belum: butuh aplikasi jalan (MySQL Laragon mati saat dikerjakan) dan Chrome — jalankan saat uji HP di Sub 12.

## Catatan pelaksanaan (2026-10-05)
- **Manifest dinamis** `GET /manifest.webmanifest` (`PwaController`, publik): nama &
  tagline dari Pengaturan Situs, `start_url` `/` (RoleRedirect), `standalone`,
  `theme_color` Daiku Yellow. **Ikon PNG digambar GD**
  (`/pwa/icon-{192|512|180}-{any|maskable}.png`): logo Pengaturan Situs di atas
  putih (maskable: di zona aman 60%), atau monogram "D" gelap di atas kuning
  bila logo kosong/SVG. URL ikon membawa versi logo (`v`) → cache
  `immutable`, berganti saat logo diganti.
- `public/sw.js`: hanya `install`/`activate` — **tanpa** cache & tanpa `fetch`
  handler (H6). Didaftarkan di `app.tsx` hanya di build production.
  `app.blade.php`: `<link rel="manifest">`, `theme-color`, `apple-touch-icon`.
- "Ingat saya" tercentang default (`Auth/Login.tsx`, D6).
- **Ringan (H8)** — berlaku untuk semua halaman:
  - `pusher-js`/Echo di-import dinamis hanya bila `VITE_BROADCAST_CONNECTION=pusher`.
  - Prompt "Buka Proyek" (CEO) di-`lazy` — form, date picker & select-nya keluar dari chunk layout.
  - `DataTable` dipecah: pembungkus ringan + `DataTableGrid` (TanStack) yang
    di-`lazy`; prop baru `mobileCard` (dipakai Sub 11) merender kartu di bawah
    `md` tanpa memuat TanStack. Daftar Tugas tukang di bawah `lg` tidak me-mount grid.
  - CSS global menghormati `prefers-reduced-motion`.
- **Ukuran** (vite build, total JS halaman + dependensinya):

  | Halaman | Sebelum | Sesudah (gzip) |
  |---|---|---|
  | Chunk AppLayout | 446 kB | 151 kB |
  | Hari Ini | 894 kB | 720 kB (234 kB) |
  | Tugas | 979 kB | 865 kB (282 kB) |
  | Lembur | 897 kB | 835 kB (269 kB) |
  | Lainnya | 884 kB | 597 kB (195 kB) |
  | Penalti | 915 kB | 852 kB (276 kB) |
  | Pengajuan Barang | 971 kB | 770 kB (252 kB) |

  Tidak ada lagi TanStack/Recharts/pusher di halaman tukang. Sisa terbesar:
  inti React+Inertia+axios (362 kB, tak terhindarkan), zod (112 kB — form
  RHF+Zod wajib per frontend-standards), DatePicker+locale (≈87 kB) di
  Tugas/Lembur/Penalti — kandidat `lazy` berikutnya bila uji HP masih lambat.
