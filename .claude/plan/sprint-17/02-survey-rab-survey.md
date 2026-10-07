# Sprint 17 · 02 — Survey ↔ RAB Jasa Survey (Urutan Bebas)

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T5 · Keputusan K4
> Status: **belum dikerjakan**

## Masalah (lead #37)
Survey #6 (luar Pekanbaru) tetap `MENUNGGU_BAYAR` walau invoice Jasa
Survey #14 sudah `TERVERIFIKASI`. Survey ini dijadwalkan **setelah** RAB
Jasa Survey #34 diminta, padahal tautan `lead_surveys.quotation_id` /
`quotations.lead_survey_id` hanya dibuat di `QuotationService::request()`.
Listener `MarkSurveyReadyOnInvoiceVerified` mencari survey lewat
`quotation_id`, sehingga tidak menemukan apa pun.

## Rancangan
- **Satu tempat menautkan:** `LeadService::linkSurveyToRab(Lead)`
  (private, dalam transaksi pemanggil). Fungsinya mencari pasangan survey
  `MENUNGGU_BAYAR` tanpa RAB ↔ RAB Jasa Survey lead yang sama yang
  berjalan/disetujui tanpa survey, lalu menautkan keduanya. Dipanggil dari:
  - `QuotationService::request()` (menggantikan logika yang ada sekarang);
  - `LeadService::scheduleSurvey()` saat `is_outside_pekanbaru` = true
    (**arah yang hilang**).
- **Sudah lunas duluan:** bila RAB yang ditautkan sudah punya invoice
  `TERVERIFIKASI`, survey langsung `markSurveyReady()` saat ditautkan,
  karena event `InvoiceVerified` sudah lewat dan tidak akan datang lagi.
- **Listener lebih tahan:** `MarkSurveyReadyOnInvoiceVerified` juga mencari
  lewat `quotations.lead_survey_id`, selain `lead_surveys.quotation_id`.
- **Kartu Survey (CRM/Show):** survey `MENUNGGU_BAYAR` menampilkan status
  pembayarannya:
  - "Menunggu RAB Jasa Survey" (belum ada RAB);
  - "RAB Jasa Survey · {status}" + link ke RAB;
  - "Invoice {nomor} · menunggu verifikasi Finance".
  Dengan begitu macetnya terlihat, tidak diam.
- **Data lama (K4):** perintah `php artisan daiku:relink-surveys
  {--dry-run}`. Isinya memanggil `linkSurveyToRab` untuk tiap lead yang
  punya survey `MENUNGGU_BAYAR` tanpa RAB. Idempoten, dan mencetak apa
  yang ditautkan/di-SIAP-kan. Jalankan sekali di lokal (lead #37) dan
  sekali saat deploy.

## Checklist
- [ ] **[Backend]** `linkSurveyToRab()` + panggil dari `request()` dan
      `scheduleSurvey()`; SIAP langsung bila invoice sudah terverifikasi
- [ ] **[Backend]** Listener mencari lewat kedua kolom tautan
- [ ] **[Backend]** Command `daiku:relink-surveys` (+ `--dry-run`)
- [ ] **[UI]** Kartu Survey: baris status pembayaran + link RAB/invoice
- [ ] **[Test]** Urutan A (survey → RAB → bayar) dan urutan B (RAB → survey
      → bayar) sama-sama berakhir SIAP; urutan C (RAB → bayar → survey)
      langsung SIAP; RAB dibatalkan melepas tautan (sudah ada, tetap lulus)
- [ ] **[Data]** Jalankan command di lokal → survey #6 lead #37 menjadi SIAP
- [ ] **[Build]** `php artisan test` + `npm run build` lulus; kartu survey
      lead #37 dicek di browser
