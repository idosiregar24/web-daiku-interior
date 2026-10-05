# Sprint 13 · 01 — Sidebar: Grup Dilipat & Urutan per Role

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: — · Keputusan: #7, #10 · Default: D3

## Tujuan
Sidebar hanya menampilkan judul grup + isi grup yang sedang dipakai, dan
urutan grup mengikuti cara kerja tiap role.

## File yang disentuh
- Migrasi `add_nav_preferences_to_users_table` (JSON nullable) + cast `array` di `User`
- `app/Http/Controllers/Profile/NavPreferenceController.php` + Form Request (`collapsed_groups: string[]`, `favorites: string[]` — whitelist label grup / routeName yang ada)
- `routes/web.php` (`PATCH profile/nav-preferences`, `auth`, `throttle:60,1`)
- `app/Http/Middleware/HandleInertiaRequests.php` (`auth.user.nav_preferences`)
- `app/Services/RoleRedirectService.php` (CEO → Perlu Tindakan baru diaktifkan di Sub 04; di sini cukup siapkan)
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
- [ ] **[Setup]** Kolom `users.nav_preferences` + endpoint simpan (validasi whitelist)
- [ ] **[UI]** Header grup bisa dilipat; grup aktif selalu terbuka; tersimpan per user
- [ ] **[UI]** Urutan grup per role (`ROLE_GROUP_ORDER`)
- [ ] **[UI]** Sheet HP memakai perilaku yang sama
- [ ] **[Test]** Simpan preferensi (sukses, nilai asing ditolak, tamu 302); `npm run build`
