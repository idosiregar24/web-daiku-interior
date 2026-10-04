# Sprint 12 · 06 — Invoice oleh Marketing & Verifikasi Finance

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **belum dikerjakan** · Prasyarat: 05 · Keputusan: #3, #16, #20, #21

## Tujuan
Marketing menerbitkan semua invoice (Jasa Survey, Jasa Desain, DP, termin);
Finance memverifikasi pembayaran. Verifikasi memicu tahap berikutnya.

## File yang disentuh
- `app/Services/{TerminService,FinanceTransactionService,BankAccountService,LeadService}.php`
- `app/Http/Controllers/Finance/TerminController.php` (PDF termin yang ada — dipakai ulang untuk PDF invoice)
- `routes/web.php` (blok `finance.*`)
- Baru: `Invoice` model/migration, `InvoiceService`, `InvoiceController`,
  `app/Events/InvoiceVerified.php`, halaman invoice
- `resources/js/Layouts/AppLayout.tsx` (`NAV_GROUPS`)
- Test: `tests/Feature/Finance/*`

## Rancangan
```
invoices   lead_id, project_id (nullable), quotation_id, termin_id (nullable),
           type (JASA_SURVEY | JASA_DESAIN | DP | TERMIN | PELUNASAN | TAMBAHAN),
           number (unik, otomatis), amount, due_date, issued_by, issued_at,
           status (DITERBITKAN | MENUNGGU_VERIFIKASI | TERVERIFIKASI | DITOLAK),
           payment_proof_url (nullable), bank_account_id (nullable),
           verified_by, verified_at, reject_reason
           index(status, issued_at)
```
- **Marketing** "Terbitkan Invoice" dari quotation CLIENT_APPROVED (SURVEY/
  DESAIN → 1 invoice 100%) atau dari termin proyek (sub-plan 07). PDF invoice.
- Marketing/Finance mengisi bukti bayar (link) → MENUNGGU_VERIFIKASI.
- **Finance** "Verifikasi Pembayaran" (pilih rekening masuk) → TERVERIFIKASI
  → transaksi PEMASUKAN + saldo rekening lewat service yang ada; atau
  "Tolak" (alasan wajib) → kembali DITERBITKAN, notif Marketing.
- Event `InvoiceVerified` → listener di sini: JASA_SURVEY →
  `LeadService::markSurveyReady()`. Listener lain dipasang di 07 (termin
  PAID), 08 (desain terbuka), 09 (alokasi terbuka).
- Listener `QuotationClientApproved` (SURVEY) → survey MENUNGGU_BAYAR.
- Halaman: **Invoice** (Marketing: terbitkan & pantau), **Verifikasi
  Pembayaran** (Finance: antrean). Audit `finance.invoice_verified/rejected`.

## Checklist
- [ ] **[Database]** Tabel `invoices` + nomor otomatis
- [ ] **[Finance]** `InvoiceService`: terbitkan (Marketing), unggah bukti, verifikasi/tolak (Finance) → transaksi & saldo, event `InvoiceVerified`
- [ ] **[CRM]** Listener: SURVEY disetujui client → MENUNGGU_BAYAR; invoice JASA_SURVEY terverifikasi → SIAP
- [ ] **[UI]** Halaman Invoice (Marketing), antrean Verifikasi (Finance), PDF invoice, nav
- [ ] **[Test]** Hanya Marketing menerbitkan, hanya Finance memverifikasi (lainnya 403); verifikasi membuat transaksi sekali (idempotent); tolak wajib alasan; survey luar kota jadi SIAP setelah verifikasi
