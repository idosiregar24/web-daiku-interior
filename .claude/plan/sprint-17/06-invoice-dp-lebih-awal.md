# Sprint 17 · 06 — Invoice DP Langsung Setelah Klien ACC (K2 direvisi)

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T3 · Keputusan K2 (dijawab user 2026-10-07)
> Status: **selesai 2026-10-07**

## Keputusan user
> "K2 langsung, marketing diarahkan untuk langsung terbitkan invoice sembari
> menunggu CEO membuka proyek."

Menggantikan usulan K2 di Sub 03 (Marketing hanya diberi notifikasi, DP baru
ditagih dari termin setelah Buka Proyek).

## Rancangan
- Klien ACC **RAB Proyek utama** (bukan RAB Tambahan) yang skemanya punya
  pembayaran **di muka** → RAB itu masuk antrean Marketing "RAB disetujui
  klien — terbitkan invoice" (`Quotation::scopeBillableUpfront()` dalam
  `awaitingInvoice()`), notifikasi `invoice_to_issue` "Terbitkan Invoice DP".
- Marketing menerbitkan **invoice DP dari RAB** (`InvoiceService::
  issueForQuotation()`, tipe `DP`, nominal = baris "di muka" pertama skema
  bayar) — `project_id`/`termin_id` masih kosong.
- Finance boleh memverifikasi sebelum proyek dibuka: pemasukan (kategori
  DP) dicatat **sekali** saat verifikasi, tanpa proyek.
- CEO "Buka Proyek" → `TerminService::attachUpfrontInvoice()`: invoice itu
  menjadi invoice termin DP (termin INVOICED, sudah "diingatkan" sehingga
  tidak masuk antrean `termin-invoice`), transaksi pemasukannya ditautkan ke
  proyek, dan bila sudah terverifikasi termin langsung lunas
  (`settleFromInvoice`). Tidak pernah ada invoice DP kedua
  (`issueForTermin` menolak termin yang sudah ber-invoice).
- Skema tanpa pembayaran di muka → tidak ada yang ditagih lebih awal;
  Marketing tetap hanya diberi kabar (notifikasi lama).
- Setelah proyek dibuka, invoice tidak bisa lagi diterbitkan dari RAB
  ("Proyek sudah dibuka — terbitkan invoice dari termin proyek").

## Checklist
- [x] **[Backend]** `Quotation::scopeBillableUpfront()`, `upfrontTerm()`,
      `openedProject()`; antrean `awaitingInvoice()` ikut RAB Proyek
- [x] **[Backend]** `InvoiceService::issueForQuotation()` menerbitkan DP RAB
      Proyek + `issuableFor()` (label & nominal untuk halaman RAB)
- [x] **[Backend]** `TerminService::attachUpfrontInvoice()` dipanggil di
      `ProjectService::openFromQuotation()` sebelum `remindInvoices()`
- [x] **[Backend]** `QueueProjectOpening`: notifikasi "Terbitkan Invoice DP"
      + hapus cache inbox Marketing
- [x] **[UI]** `Quotation/Show`: tombol "Terbitkan Invoice DP", teks
      penjelas, dialog memakai label & nominal dari server; antrean
      menampilkan nominal DP
- [x] **[PDF]** Invoice DP tanpa termin: baris "{nama baris} {persen}% dari
      RAB Proyek {nomor}"
- [x] **[Test]** `ProjectOpeningTest`: antrean + notifikasi; DP dibayar
      sebelum Buka Proyek → termin lunas, transaksi tertaut proyek, 1 invoice;
      DP belum dibayar → termin INVOICED lalu lunas saat diverifikasi; tidak
      bisa setelah proyek dibuka; halaman RAB & route
- [x] **[Build]** `php artisan test` 1727 lulus, `npm run build` lulus, Pint lulus

## Catatan pelaksanaan (2026-10-07)
- Test K2 lama dari Sub 03 (notifikasi "menunggu CEO", tanpa item antrean)
  diganti sesuai keputusan baru; alur "DP belum ditagih saat Buka Proyek →
  termin DP masuk `termin-invoice`" tetap diuji.
- Pengikatan transaksi ke proyek memakai update `project_id` yang masih
  kosong pada transaksi pemasukan invoice itu + audit
  `finance.invoice_attached_to_termin` (nominal & kategori tidak berubah).
- Data lokal: RAB Proyek #36 (lead #37) sudah dibuka jadi proyek #7, jadi
  tidak muncul di antrean — DP-nya lewat termin seperti biasa.
