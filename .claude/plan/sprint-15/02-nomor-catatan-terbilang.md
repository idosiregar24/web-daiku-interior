# Sprint 15 · 02 — Nomor Surat, Catatan per RAB & Terbilang

> Induk: [`../sprint-15-surat-resmi-rab.md`](../sprint-15-surat-resmi-rab.md) · Keputusan: K2, K4
> Status: **selesai 2026-10-06**

## Rancangan
- Tabel `letter_sequences (year, last_number)` + `LetterNumberService::next(code)`
  (`lockForUpdate`): satu urutan untuk OFF & INV per tahun →
  `377/OFF/Daiku/IX/2026`. Kode perusahaan `Daiku` di `config/daiku.php`.
- `quotations.letter_number`: diberikan saat RAB **dikirim ke klien**
  (`sendToClient`); dikosongkan saat versi baru dibuka (revisi) → versi
  berikutnya mendapat nomor baru. Sebelum dikirim, PDF bertanda **DRAF**.
- `invoices.number`: invoice baru memakai format `…/INV/Daiku/…`
  (invoice lama tetap nomor `INV-YYYYMM-NNNN`).
- `quotations.client_notes`: catatan untuk klien, diisi Estimator selama
  DRAFT; kosong = catatan bawaan jenisnya dari Pengaturan (K4).
- `App\Support\Terbilang::rupiah()` — "LIMA JUTA RUPIAH".

## Checklist
- [x] **[Backend]** Urutan nomor OFF/INV + `letter_number` + format invoice baru
- [x] **[Backend]** `client_notes` (+ endpoint simpan, Estimator, DRAFT) & terbilang
- [x] **[UI]** Isian "Catatan untuk Klien" di halaman Quotation
- [x] **[Test]** Nomor berurutan lintas OFF/INV, reset per tahun, nomor baru tiap versi; terbilang; catatan hanya Estimator saat DRAFT

## Catatan pelaksanaan (2026-10-06)
- `letter_sequences` + `LetterSequence` + `LetterNumberService::next('OFF'|'INV', $date)`
  (`lockForUpdate`), kode perusahaan `config('daiku.letter.company_code')` (env
  `LETTER_COMPANY_CODE`, bawaan "Daiku").
- `QuotationService::sendToClient()` memberi `letter_number` (OFF);
  `closeVersion()` (revisi) mengosongkannya. `InvoiceService::nextNumber()` → INV
  (invoice lama tetap `INV-YYYYMM-NNNN`); kolom `invoices.number` dilebarkan ke 60.
- `quotations.client_notes` + `PUT quotations/{id}/client-notes`
  (`role:ESTIMATOR`, `throttle:60,1`, DRAFT saja) + kartu `ClientNotesCard`
  di halaman Quotation (Estimator edit; role lain baca, dengan penanda "bawaan").
- `App\Support\Terbilang::rupiah()` (sampai triliun, sen dibuang).
