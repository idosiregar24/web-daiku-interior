# Sprint 12 · 07 — Buka Proyek oleh CEO & Termin dari Skema

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **belum dikerjakan** · Prasyarat: 06 · Keputusan: #12, #19–#21

## Tujuan
Client menyetujui RAB Proyek → CEO mendapat pop-up "Buka Proyek" → proyek +
termin dibuat otomatis dari skema. Marketing diingatkan menagih termin.

## File yang disentuh
- `app/Services/{ProjectService,TerminService}.php` (`createFromLead`, `TerminService::create`)
- `app/Http/Controllers/Projects/ProjectController.php` (±baris 155 `createFromLead`),
  `Finance/TerminController.php`
- `routes/web.php` (`projects.termins.store` ±baris 330 dihapus; route buka proyek baru)
- `app/Http/Middleware/HandleInertiaRequests.php` (shared prop pop-up CEO)
- `resources/js/Layouts/AppLayout.tsx` (modal Buka Proyek), `Pages/Projects/Show.tsx` (tab Dokumen)
- `routes/console.php` (job harian)
- Test: `tests/Feature/Projects/*`, `tests/Feature/Finance/*`

## Rancangan
```
project_openings   quotation_id (unik), status (MENUNGGU_CEO | DIBUKA), opened_by,
                   opened_at, project_id
projects           + quotation_id (RAB Fix), + assistant_pm_id (nullable)
termins            + payment_term_id, + trigger (TANGGAL | MILESTONE | PROYEK_SELESAI), + invoice_id
```
- Listener `QuotationClientApproved` (PROYEK, bukan addendum) → buat
  `project_openings` MENUNGGU_CEO + notif CEO + lead → CLOSING.
- **Pop-up CEO**: shared prop `pendingProjectOpenings` (hanya untuk CEO);
  modal muncul di halaman mana pun: nama proyek, PM, Asisten PM (opsional,
  D2), tanggal mulai → "Buka Proyek". Bisa ditunda ("Nanti") — tetap ada di
  daftar Proyek → "Menunggu Dibuka".
- `ProjectService::openFromQuotation()` — satu transaksi: proyek, termin
  dari `quotation_payment_terms` (nilai & pemicu disalin), audit
  `project.opened`. Pembuatan termin manual oleh PM dihapus; pembuatan
  proyek manual dari lead dihapus (semua lewat Buka Proyek).
- **Job harian** `TerminInvoiceReminderJob` (idempotent): termin TANGGAL
  jatuh tempo, MILESTONE yang milestone-nya DONE, PROYEK_SELESAI saat
  proyek COMPLETED → notif Marketing "Terbitkan invoice termin N".
- Marketing "Terbitkan Invoice" dari termin → invoice (sub-plan 06);
  listener `InvoiceVerified` (DP/TERMIN/PELUNASAN) → termin PAID lewat
  `TerminService::recordPayment()`.
- Tab **Dokumen** di Detail Proyek (Finance, CEO, PM, Marketing): RAB Fix
  (versi CLIENT_APPROVED + PDF) + daftar invoice.

## Checklist
- [ ] **[Database]** `project_openings`; kolom baru `projects` & `termins`
- [ ] **[Projects]** Listener → `project_openings`; `ProjectService::openFromQuotation()`; hapus jalur manual proyek & termin
- [ ] **[UI]** Modal Buka Proyek (CEO) + daftar "Menunggu Dibuka"; tab Dokumen
- [ ] **[Finance]** Terbitkan invoice dari termin; listener verifikasi → termin PAID
- [ ] **[Finance]** `TerminInvoiceReminderJob` + jadwal di `routes/console.php`
- [ ] **[Test]** Setuju client → opening → proyek + termin sesuai skema (nilai, pemicu); hanya CEO membuka; job tidak dobel; route manual lama hilang

## Catatan
Sistem lama punya proyek tanpa `quotation_id` — biarkan nullable, tab
Dokumen menampilkan "RAB Fix belum tertaut".
