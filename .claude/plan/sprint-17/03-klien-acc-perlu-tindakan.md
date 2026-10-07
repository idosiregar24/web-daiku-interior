# Sprint 17 · 03 — Klien ACC → "Perlu Tindakan" Marketing

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T3 · Keputusan K2
> Status: **belum dikerjakan**

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
- [ ] **[Backend]** Scope `Quotation::awaitingInvoice()` + antrean
      `invoiceToIssue()` untuk MARKETING
- [ ] **[Backend]** `forget()` cache inbox saat klien ACC & invoice terbit
- [ ] **[Backend]** Notifikasi Marketing untuk ACC RAB Proyek (K2)
- [ ] **[UI]** Item antrean membuka dialog Terbitkan Invoice di halaman RAB
- [ ] **[Test]** Klien ACC RAB Desain → item muncul di inbox Marketing
      pemilik (bukan Marketing lain); invoice terbit → item hilang; Buka
      Proyek → termin DP masuk `termin-invoice`
- [ ] **[Build]** `php artisan test` + `npm run build` lulus; dicek sebagai
      `marketing@daikuinterior.com`
