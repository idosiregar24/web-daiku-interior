# Sprint 13 · 12 — Penutup

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 01–11

## Tujuan
Dokumentasi, data demo, dan pengujian menyeluruh untuk navigasi baru.

## Checklist
- [ ] **[Docs]** `CLAUDE.md` golden rule #8 (sidebar: hub bertab, ⚙ Pengaturan, badge dari `ActionInboxService`), `design-standards.md` §4 (hub, BottomNav), `frontend-standards.md`, `plan/README.md` (status Sprint 13)
- [ ] **[Setup]** Seeder demo: tiap role punya minimal 1 item di Perlu Tindakan; tukang punya task hari ini dengan sebagian form harian belum diisi
- [ ] **[Test]** Tabel jumlah menu per role sebelum/sesudah (dicatat di file induk §1); `npm run build`, `pint --test`, `php artisan test`
- [ ] **[Test]** Uji di HP sungguhan (Android kelas menengah) sebagai tukang dan PM; `/security-review` untuk `search`, `inbox`, `nav-preferences`
