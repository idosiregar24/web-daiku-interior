# Sprint 17 · 07 — "Kirim Bukti Bayar" Bisa Dijangkau Langsung

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Masukan user 2026-10-07
> Status: **selesai 2026-10-07** (cek browser belum — MySQL lokal mati saat penutupan)

## Masalah
> "Bagian upload bukti pembayaran, saya bingung karena tidak ada action
> langsung ke situ — ternyata harus masuk dulu ke menu Invoice."

Dialog "Kirim Bukti Bayar" (`InvoiceProofDialog`) hanya dipakai di
`Finance/Invoices/Index`. Halaman tempat invoice diterbitkan dan dilihat
(RAB, tab Finance proyek, kartu Dokumen proyek) tidak punya tombolnya, dan
Perlu Tindakan tidak punya antrean untuk invoice yang menunggu bukti.

## Rancangan
- Komponen `InvoiceProofButton` (`InvoiceDialogs.tsx`): tombol "Kirim Bukti
  Bayar" + dialog, hanya untuk invoice DITERBITKAN dan role Marketing /
  Finance (/ SUPERADMIN); alasan penolakan Finance tampil di sebelahnya.
  Dipakai di `Quotation/Show` (baris invoice), `Projects/Show` (baris termin
  ber-invoice & daftar invoice di tab Dokumen).
- Antrean Marketing **"Invoice menunggu bukti bayar"**
  (`ActionInboxService::invoicesAwaitingProof()`, scope
  `Invoice::awaitingProof(?User)` — lead milik Marketing itu), jatuh tempo
  terdekat dulu; item → `finance.invoices.index?awaiting_proof=1&proof={id}`
  yang langsung membuka dialog (`proofInvoice` dari server, jadi tetap jalan
  walau invoice-nya di halaman lain). Badge di menu Invoice ikut otomatis.
- Daftar Invoice: filter `awaiting_proof` (scope yang sama) + Notice "Tampilkan
  semua invoice".
- Cache inbox Marketing dibersihkan saat bukti dikirim (bisa oleh Finance) dan
  saat Finance menolak bukti (invoice kembali ke antrean).
- Notifikasi: "Bukti Bayar Ditolak" kini membuka dialog Kirim Bukti Bayar
  invoice itu; "Pembayaran Menunggu Verifikasi" membuka antrean verifikasi
  Finance (`notificationHref.ts` — sebelumnya `invoice_id` tidak dikenali).
- Pesan sukses saat invoice terbit menyebut langkah berikutnya: "Setelah
  klien membayar, tekan 'Kirim Bukti Bayar' di baris invoice ini."

## Checklist
- [x] **[Backend]** `Invoice::scopeAwaitingProof()`, antrean
      `invoicesAwaitingProof()`, filter & prop `proofInvoice` di
      `InvoiceController`
- [x] **[Backend]** `forgetMarketingOf()` di `submitProof()` & `reject()`;
      prop `canSubmitProof` / `canSubmitInvoiceProof` + `reject_reason` di
      halaman RAB & proyek
- [x] **[UI]** `InvoiceProofButton` di halaman RAB, termin proyek, dokumen
      proyek; dialog otomatis + Notice filter di daftar Invoice
- [x] **[UI]** `notificationHref`: `invoice_rejected`,
      `invoice_awaiting_verification`
- [x] **[Test]** `tests/Feature/Finance/InvoiceProofAccessTest.php` —
      antrean milik Marketing sendiri, hilang setelah bukti dikirim, kembali
      setelah ditolak; filter + `proofInvoice`; prop halaman RAB per role;
      kirim bukti dari halaman RAB
- [x] **[Build]** `php artisan test` 1733 lulus, `npm run build` lulus, Pint lulus
- [ ] **[Browser]** Cek sebagai Marketing: antrean, dialog otomatis, tombol
      di halaman RAB & proyek (MySQL lokal mati saat penutupan)

## Catatan
- Bukti bayar masih berupa **link** (Google Drive dsb.), belum unggah file.
  Unggah file langsung (foto/PDF bukti transfer, disimpan privat, hanya
  Marketing/Finance/CEO yang bisa membuka) bisa jadi langkah berikutnya bila
  diinginkan — pola penyimpanannya sudah ada di foto referensi RAB (Sprint 14).
