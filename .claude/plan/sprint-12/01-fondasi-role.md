# Sprint 12 · 01 — Fondasi: Role Asisten PM & Kepala Desain

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md) (alur & keputusan #1–#32).
> Status: **belum dikerjakan** · Prasyarat: **Sprint 11 selesai** · Keputusan: #15, #22
> (Master Vendor sudah dipindah ke Sprint 11 Sub 2.)

## Tujuan
Menyiapkan dua role baru yang dipakai sub-plan berikutnya. Belum ada
perubahan alur — hak aksi masing-masing role datang di sub-plan 04, 08, 11.

## File yang disentuh
- `database/seeders/RoleSeeder.php`, `ProductionSeeder.php`, `DatabaseSeeder.php` (DEMO_USERS)
- `app/Services/RoleRedirectService.php` (landing page role baru)
- `resources/js/Layouts/AppLayout.tsx` (`NAV_GROUPS`), `resources/js/types/index.d.ts` (`Role`)
- `routes/web.php` — route **baca** Proyek & Quotation menambah `ASISTEN_PM`
- Test: `tests/Feature/SeederTest.php`, `RbacMiddlewareTest.php`, `RoleRedirectServiceTest.php`

## Rancangan
- `ASISTEN_PM` — demo user `asistenpm@daikuinterior.com`; landing =
  dashboard proyek; menu baca sama dengan PM (Proyek, Quotation). Hak tulis:
  ACC RAB (sub-plan 04), aksi proyek & ACC pengajuan barang (sub-plan 11).
- `KEPALA_DESAIN` — ditumpuk di atas `DESIGNER` (user punya dua role). Demo
  user `kepaladesain@daikuinterior.com` (DESIGNER + KEPALA_DESAIN). Hak aksi
  di sub-plan 08.

## Checklist
- [ ] **[Setup]** RoleSeeder + ProductionSeeder: `ASISTEN_PM`, `KEPALA_DESAIN`; demo user; `Role` union TS; landing `RoleRedirectService`
- [ ] **[Setup]** `NAV_GROUPS` + route baca: ASISTEN_PM melihat menu baca PM; KEPALA_DESAIN mewarisi DESIGNER
- [ ] **[Test]** SeederTest role baru, RoleRedirect, ASISTEN_PM bisa buka index Proyek/Quotation tapi 403 di aksi tulis PM

## Selesai bila
`php artisan test`, `npm run build`, `pint --test` lulus.
