# Sprint 17 · 02 — Survey ↔ RAB Jasa Survey (Urutan Bebas)

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T5 · Keputusan K4
> Status: **selesai 2026-10-07**

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
- [x] **[Backend]** `linkSurveyToRab()` + panggil dari `request()` dan
      `scheduleSurvey()`; SIAP langsung bila invoice sudah terverifikasi
- [x] **[Backend]** Listener mencari lewat kedua kolom tautan
- [x] **[Backend]** Command `daiku:relink-surveys` (+ `--dry-run`)
- [x] **[UI]** Kartu Survey: baris status pembayaran + link RAB/invoice
- [x] **[Test]** Urutan A (survey → RAB → bayar) dan urutan B (RAB → survey
      → bayar) sama-sama berakhir SIAP; urutan C (RAB → bayar → survey)
      langsung SIAP; RAB dibatalkan melepas tautan (sudah ada, tetap lulus)
- [x] **[Data]** Jalankan command di lokal → survey #6 lead #37 menjadi SIAP
- [x] **[Build]** `php artisan test` + `npm run build` lulus; kartu survey
      lead #37 dicek di browser

## Catatan pelaksanaan (2026-10-07)
- K4 dijalankan sesuai usulan (user tidak menjawab).
- `linkSurveyToRab()` ditaruh di **`QuotationService`** (bukan
  `LeadService`), karena `LeadService` sudah bergantung pada
  `QuotationService` — kebalikannya akan melingkar. Dipanggil dari
  `request()` (RAB Survey) dan `LeadService::scheduleSurvey()` (survey luar
  kota). RAB yang dibatalkan/ditolak tidak ditautkan; RAB yang survey-nya
  BATAL boleh dipakai survey baru.
- `LeadService::settleSurveyPayment()` / `isSurveyPaid()`: survey yang baru
  tertaut ke RAB yang invoice-nya sudah TERVERIFIKASI langsung SIAP.
- Listener `MarkSurveyReadyOnInvoiceVerified` menautkan dulu bila belum,
  lalu mencari survey lewat kedua kolom tautan.
- `php artisan daiku:relink-surveys {--dry-run}` — dry run membatalkan
  semua perubahan dan tidak mengirim notifikasi. Dijalankan di lokal:
  lead #37 survey #1 (id 6) → RAB #34 → **SIAP**.
- Kartu Survey: baris "Belum bisa berangkat: …" menyebut langkah yang
  macet (belum ada RAB / RAB belum disetujui / invoice belum terbit /
  menunggu bukti bayar / menunggu verifikasi Finance) + link ke RAB.
  `LeadController@show` memuat `surveys.quotation` + `invoices`.
- Test: `tests/Feature/CRM/SurveyRabLinkTest.php` (urutan A/B/C, RAB batal,
  survey dalam kota, command dry-run/jalan/idempoten, props kartu).
