# Sprint 13 · 12 — Penutup

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **selesai sebagian 2026-10-05** (uji HP sungguhan & `/security-review` penuh menunggu) · Prasyarat: 01–11 (kecuali 07, dibatalkan)

## Tujuan
Dokumentasi, data demo, dan pengujian menyeluruh untuk navigasi baru.

## Checklist
- [x] **[Docs]** `CLAUDE.md` golden rule #8 (sidebar: hub bertab, ⚙ Pengaturan, badge dari `ActionInboxService`), `design-standards.md` §4 (hub, BottomNav), `frontend-standards.md`, `plan/README.md` (status Sprint 13)
- [x] **[Setup]** Seeder demo: tiap role punya minimal 1 item di Perlu Tindakan; tukang punya task hari ini dengan sebagian form harian belum diisi
- [x] **[Test]** Tabel jumlah menu per role sebelum/sesudah (dicatat di file induk §1); `npm run build`, `pint --test`, `php artisan test`
- [ ] **[Test]** Uji di HP sungguhan (Android kelas menengah) sebagai tukang, PM, QA, dan Logistik; `/security-review` untuk `search`, `inbox`, `nav-preferences`
  📌 **Status: Sebagian** — smoke test aplikasi sungguhan (MySQL, `php artisan serve`, login tiap role demo via curl): semua halaman Sprint 13 200, Asisten PM 403 di halaman tukang, CEO mendarat di Perlu Tindakan & tukang di Hari Ini, manifest & ikon 200. Review keamanan terarah dilakukan (lihat catatan). **Belum**: uji di HP Android sungguhan + Lighthouse/throttling (Sub 10) + cek 390/820/1366px (Sub 11), dan menjalankan skill `/security-review` atas seluruh diff Sprint 13.

## Catatan pelaksanaan (2026-10-05)
- **Docs**: `CLAUDE.md` aturan #8 (hub, ⚙ Pengaturan, urutan per role,
  badge dari `ActionInboxService`) + aturan baru **#12** (Sprint 13);
  `design-standards.md` §4 (hub, Perlu Tindakan & badge, HP/BottomNav);
  `frontend-standards.md` §4 (`mobileCard`, `ResponsiveDialogContent`,
  lazy/ringan); README & file induk (tabel menu §1).
- **Seeder** `DemoTodayDemoSeeder` (dipanggil `DatabaseSeeder`): tukang demo
  dapat 3 tugas jatuh tempo hari ini (form harian 1 sudah, 2 belum) + 1
  tugas minggu depan. Hasil `migrate:fresh --seed` (DB scratch): setiap role
  berantrean punya ≥1 item di Perlu Tindakan — CEO 5 antrean, PM 4, Asisten
  PM 3, Finance 3, Estimator 2, Marketing 2, HR 2, QA/Logistik/Kepala Desain
  1, Tukang 2. Arsitek & SUPERADMIN memang tanpa antrean (desain Sub 04).
- **Bug ditemukan saat uji MySQL**: antrean QA mengurutkan `qa_forms.updated_at`
  yang tidak ada (`QaForm` tanpa timestamps) — lolos di test karena SQLite
  menganggap nama kolom tak dikenal dalam kutip ganda sebagai string.
  Diperbaiki (`created_at`), dan `at` string dinormalkan ke ISO.
- **Review keamanan terarah**: `nav-preferences` (auth, throttle, whitelist
  grup, baris sendiri, 204), `inbox` + `navBadges` (cache per user id,
  scope = halaman daftar, gaji hanya CEO), `search` (Sub 06),
  `today`/`more` (role FIELD_STAFF, data sendiri). **Diperbaiki**: endpoint
  ikon PWA publik menggambar kanvas GD 2048px per request → kini hasilnya
  di-cache per versi logo + `throttle:60,1`.
- `php artisan migrate` dijalankan di DB lokal `daiku_interior` (kolom
  `users.nav_preferences`); data lokal lain tidak disentuh — seed ulang
  dilakukan di DB scratch `daiku_s13`.
