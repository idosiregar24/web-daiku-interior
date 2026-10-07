# Sprint 17 · 03 — Klien ACC → "Perlu Tindakan" Marketing

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T3 · Keputusan K2
> Status: **selesai 2026-10-07**

## Kondisi sekarang
- Klien ACC RAB Jasa Survey/Desain → `PromptInvoiceOnServiceRabApproval`
  mengirim notifikasi `invoice_to_issue` ke Marketing pemilik lead. Klik
  notifikasi membuka halaman RAB (`notificationHref`). Ini **sudah jalan**.
- Tetapi `ActionInboxService` untuk MARKETING hanya punya: follow-up jatuh
  tempo, RAB siap dikirim, dan termin perlu invoice. **Tidak ada antrean
  "RAB disetujui klien, invoice belum terbit"**, sehingga tugas hilang
  begitu notifikasi dibaca.

## Rancangan
- **Antrean baru `invoiceToIssue(User)`** di `ActionInboxService`
  (Golden rule #12: satu method, badge ikut otomatis):
  - Isinya quotation `CLIENT_APPROVED` bertipe SURVEY/DESAIN yang **belum
    punya invoice aktif**, pada lead milik Marketing itu (atau lead tanpa
    Marketing, sama seperti `terminsToInvoice`). Urutannya dari yang
    paling lama disetujui klien.
  - Label: "RAB disetujui klien — terbitkan invoice". Deskripsi: "Klien
    sudah setuju; terbitkan invoice agar bisa dibayar."
  - Tiap item menuju halaman RAB yang langsung membuka dialog Terbitkan
    Invoice (`?action=invoice`, bila dialognya ada di halaman itu).
  - Query memakai scope di `Quotation` (mis. `awaitingInvoice()`) supaya
    halaman daftar dan antrean membaca aturan yang sama.
- **Cache inbox:** pastikan `ActionInboxService::forget()` dipanggil untuk
  Marketing terkait saat klien ACC dan saat invoice terbit. Kalau tidak,
  antrean/badge baru muncul setelah cache kedaluwarsa.
- **RAB Proyek (K2):** klien ACC → Marketing mendapat notifikasi "RAB
  Proyek disetujui klien — menunggu CEO Buka Proyek; tagihan DP muncul di
  Perlu Tindakan setelah proyek dibuka". Tidak ada item antrean, karena
  belum ada yang bisa dikerjakan Marketing.
- **Verifikasi rantai DP:** setelah CEO "Buka Proyek", pastikan termin DP
  mendapat `invoice_reminded_at` (oleh `TerminService::remindInvoices()`
  atau saat dibuat) sehingga langsung masuk antrean `termin-invoice`. Bila
  tidak, perbaiki di sini.

## Checklist
- [x] **[Backend]** Scope `Quotation::awaitingInvoice()` + antrean
      `invoiceToIssue()` untuk MARKETING
- [x] **[Backend]** `forget()` cache inbox saat klien ACC & invoice terbit
- [x] **[Backend]** Notifikasi Marketing untuk ACC RAB Proyek (K2)
- [x] **[UI]** Item antrean membuka dialog Terbitkan Invoice di halaman RAB
- [x] **[Test]** Klien ACC RAB Desain → item muncul di inbox Marketing
      pemilik (bukan Marketing lain); invoice terbit → item hilang; Buka
      Proyek → termin DP masuk `termin-invoice`
- [x] **[Build]** `php artisan test` + `npm run build` lulus; dicek sebagai
      `marketing@daikuinterior.com` — `php artisan test` penuh lulus
      (1722) dan `tsc --noEmit` bersih; `npm run build` & cek manual di
      browser belum dijalankan (dikerjakan lead saat penutupan sprint)

## Catatan pelaksanaan (2026-10-07)

- **K2 dijalankan sesuai usulan (user tidak menjawab).**
- Antrean baru `quotation-invoice` "RAB disetujui klien — terbitkan
  invoice" (`ActionInboxService::invoiceToIssue()`, ikon `invoice`, menu
  `quotations.index` → badge ikut otomatis). Query = scope baru
  `Quotation::awaitingInvoice(?User $marketing)`: `CLIENT_APPROVED`,
  tipe SURVEY/DESAIN, belum punya invoice (satu invoice per RAB jasa, D4 —
  invoice tidak punya status batal, jadi "aktif" = ada baris invoice);
  dengan `$marketing` hanya lead miliknya atau lead tanpa Marketing (sama
  seperti `terminsToInvoice`; catatan: `leads.assigned_to` NOT NULL, jadi
  cabang "tanpa Marketing" hanya defensif). Urut `client_approved_at`
  terlama dulu.
- "Lihat semua" → `quotations.index?awaiting_invoice=1`: filter baru di
  `QuotationController@index` memakai scope yang sama (untuk role
  MARKETING dibatasi lead miliknya, jadi jumlahnya = angka antrean), plus
  `Notice` di `Quotation/Index` dengan tombol "Tampilkan semua RAB".
- Item antrean → `quotations.show?action=invoice`; `Quotation/Show`
  membuka `IssueInvoiceDialog` saat mount bila `canIssueInvoice` dan
  `action=invoice`.
- Cache: `ActionInboxService::forgetMarketingOf(?User)` (lewat
  `DB::afterCommit`, jadi antrean yang dibangun ulang sudah melihat
  perubahan) — dipanggil di `PromptInvoiceOnServiceRabApproval` (klien ACC
  di link publik, tidak ada request Marketing yang memicu
  `ForgetActionInbox`), `InvoiceService::issueForQuotation()` &
  `issueForTermin()` (penerbit bisa bukan pemilik lead), dan
  `TerminService::remindInvoices()`.
- K2: `QueueProjectOpening` (RAB Proyek non-tambahan, saat antrean Buka
  Proyek baru dibuat) mengirim notifikasi `project_rab_awaiting_opening`
  ke Marketing lead (atau pengirim link): "… Menunggu CEO Buka Proyek;
  tagihan DP muncul di Perlu Tindakan setelah proyek dibuka." Tidak ada
  item antrean. Notifikasi umum `quotation_client_approved` tetap terkirim
  seperti sebelumnya.
- **Rantai DP — diperbaiki.** Sebelumnya termin DP baru mendapat
  `invoice_reminded_at` dari `TerminInvoiceReminderJob` pagi berikutnya
  (07:30), jadi tidak langsung masuk `termin-invoice`. Sekarang
  `TerminService::remindInvoices(?Project $project = null)` bisa dibatasi
  ke satu proyek dan dipanggil di `ProjectService::openFromQuotation()`
  dan `addAddendum()` (DP "di muka" RAB Tambahan juga langsung masuk).
  Tetap idempoten (`invoice_reminded_at`); test lama "reminded once per
  termin" disesuaikan (DP sudah diingatkan saat Buka Proyek).
- Test: `tests/Feature/Inbox/ActionInboxServiceTest.php` (klien ACC RAB
  Desain lewat link → item muncul tanpa `fresh` untuk Marketing pemilik,
  bukan rekan; halaman daftar `awaiting_invoice` & `canIssueInvoice`;
  invoice diterbitkan rekan → item hilang; aturan scope & urutan),
  `tests/Feature/Projects/ProjectOpeningTest.php` (K2: notifikasi tanpa
  item antrean; Buka Proyek → DP di `termin-invoice` tanpa `fresh`;
  invoice DP → hilang).

**Penutupan sprint (lead, 2026-10-07):** `php artisan test` penuh 1723
lulus, `npm run build` lulus, Pint lulus. Cek browser (Chrome headless,
Marketing): Salin Link di `http://web-daiku-interior.test` (bukan secure
context) → `execCommand('copy')` = true, toast "Link disalin.", tombol
"Tersalin"; peringatan "alamat lokal" tampil; kartu survey lead #37 SIAP;
notice RAB Proyek otomatis di lead #31. Uji dari HP sungguhan (K1) belum.
