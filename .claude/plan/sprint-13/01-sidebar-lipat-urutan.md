# Sprint 13 · 01 — Sidebar: Grup Dilipat & Urutan per Role

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **selesai 2026-10-05** · Prasyarat: — · Keputusan: #7, #10 · Default: D3

## Tujuan
Sidebar hanya menampilkan judul grup + isi grup yang sedang dipakai, dan
urutan grup mengikuti cara kerja tiap role.

## File yang disentuh
- Migrasi `add_nav_preferences_to_users_table` (JSON nullable) + cast `array` di `User`
- `app/Http/Controllers/Profile/NavPreferenceController.php` + Form Request (`collapsed_groups: string[]` — whitelist label grup yang ada; favorit tidak dibuat, lihat induk #11)
- `routes/web.php` (`PATCH profile/nav-preferences`, `auth`, `throttle:60,1`)
- `app/Http/Middleware/HandleInertiaRequests.php` (`auth.user.nav_preferences`)
- `resources/js/Layouts/AppLayout.tsx`, `resources/js/types/index.d.ts`
- Test: `tests/Feature/Profile/NavPreferenceTest.php`

## Rancangan
- Header grup jadi tombol (ikon chevron). Grup berisi halaman aktif
  **selalu terbuka** dan tidak bisa dilipat saat itu. Default: semua grup
  terbuka (perilaku sekarang) sampai user melipatnya.
- Simpan ke `nav_preferences.collapsed_groups` lewat `router.patch(...,
  { preserveState: true, preserveScroll: true })` dengan debounce; state
  lokal langsung berubah (optimistic).
- `ROLE_GROUP_ORDER: Partial<Record<Role, string[]>>` di `AppLayout.tsx`;
  `useNavGroups()` mengurutkan grup sesuai role utama (role tumpuk ikut
  role dasarnya). Contoh: FINANCE → Keuangan dulu; LOGISTICS → Logistik;
  HR → SDM; CEO → Utama, Eksekutif, Keuangan, …
- Sheet HP (hamburger) memakai komponen sidebar yang sama, jadi ikut terlipat.
- `CommandMenu` dan "Modul Anda" tetap membaca `useNavGroups()` — tidak
  terpengaruh pelipatan.

## Checklist
- [x] **[Setup]** Kolom `users.nav_preferences` + endpoint simpan (validasi whitelist)
- [x] **[UI]** Header grup bisa dilipat; grup aktif selalu terbuka; tersimpan per user
- [x] **[UI]** Urutan grup per role (`ROLE_GROUP_ORDER`)
- [x] **[UI]** Sheet HP memakai perilaku yang sama
- [x] **[Test]** Simpan preferensi (sukses, nilai asing ditolak, tamu 302); `npm run build`

## Catatan pelaksanaan (2026-10-05)
- Simpan preferensi lewat `window.axios.patch` (debounce 600 ms), **bukan**
  `router.patch` — kunjungan Inertia baru membatalkan kunjungan yang sedang
  jalan, jadi melipat grup lalu langsung klik menu bisa membatalkan
  navigasi. Karena itu endpoint mengembalikan **204**, bukan `back()`.
  Nilai terakhir disimpan juga di modul JS (`localCollapsed`) supaya
  halaman yang dibuka sebelum simpan selesai tidak "membuka lagi" grupnya.
- Whitelist label grup: `UpdateNavPreferenceRequest::GROUPS` (sudah memakai
  nama grup final Sub 02–03: Keuangan, tanpa Operasional/Sistem).
- Grup ⚙ Pengaturan `pinned` (tanpa header) — tidak bisa dilipat.
