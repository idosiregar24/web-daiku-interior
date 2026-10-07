# Sprint 17 · 05 — Invoice Berbeda dari Penawaran

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T4 · Keputusan K3
> Status: **selesai 2026-10-07** · K3 dijalankan sesuai usulan (user tidak menjawab)

## Kondisi sekarang
`pdf/quotation` dan `pdf/invoice` sama-sama merender `pdf/layouts/letter`
dari `QuotationLetter` / `InvoiceLetter`. Isinya hampir sama:
- Invoice jasa mencetak ulang seluruh tabel item RAB.
- Bedanya hanya kata `kind` (PENAWARAN/INVOICE), nomor (OFF/INV), meta
  ("Berlaku Sampai" ↔ "Jatuh Tempo" + "Ref. Penawaran"), kalimat pembuka
  dan penutup, serta cap LUNAS.

Klien dan staf tidak bisa membedakan keduanya sekilas.

## Rancangan (usulan K3)
Penawaran **tidak berubah** (format surat Sprint 15). Invoice mendapat
**tata letak tagihan** sendiri, tetap dengan kop & footer yang sama
supaya berasal dari satu identitas:

1. **Judul besar "INVOICE"** di bawah kop, disertai garis aksen. Tidak
   memakai blok "Perihal/Kepada, Dengan hormat".
2. **Dua kotak berdampingan:**
   - Ditagihkan kepada: nama, kontak, alamat lead;
   - No. Invoice · Tanggal · Jatuh Tempo · Ref. Penawaran · Proyek/Termin.
3. **Tabel ringkas:** satu baris per kelompok RAB (invoice jasa) atau per
   termin (invoice termin: "Termin 1 — DP 30% dari RAB Proyek {nomor}").
   Rincian item tetap ada di penawaran yang dirujuk.
4. **Kotak "Total Tagihan"** yang menonjol + terbilang.
5. **Kotak "Cara Pembayaran":** rekening dari Data Master + "cantumkan No.
   Invoice pada berita transfer".
6. **Cap status:** BELUM DIBAYAR (abu) / MENUNGGU VERIFIKASI / LUNAS.
7. Penutup singkat, tanpa "Demikian penawaran…". Tanda tangan tetap ada.

Pelaksanaan:
- **Layout baru `pdf/layouts/invoice`.** `InvoiceLetter` menambah bagian
  yang dibutuhkan (`billTo`, `summaryRows`, `paymentInfo`, `status`).
  Bagian bersama (kop, footer, terbilang, ikon) tetap dari `LetterParts`
  (Golden rule #13: surat dibangun sekali di `App\Support\Letters\*`).
- **Tampilan web invoice** (bila ada di link klien / pratinjau staf)
  memakai komponen yang membaca data yang sama. Bila belum ada tampilan
  web invoice, cukup PDF dan catat itu.

## Checklist
- [x] **[Keputusan]** K3 — dijalankan sesuai usulan (user tidak menjawab)
- [x] **[Backend]** `InvoiceLetter` → data tata letak tagihan; layout
      `pdf/layouts/invoice` + `pdf/invoice` & `pdf/termin` memakainya
- [x] **[UI]** Tampilan web invoice mengikuti (bila ada), atau dicatat
      "PDF saja" → **PDF saja** (tidak ada halaman web invoice; link klien
      hanya untuk penawaran)
- [x] **[Test]** Snapshot/assert isi PDF: invoice memuat "INVOICE", "Total
      Tagihan", "Cara Pembayaran", dan **tidak** memuat "Berlaku Sampai" /
      "Demikian penawaran"; penawaran tidak berubah
- [x] **[Visual]** Cetak 3 contoh (invoice jasa survey, jasa desain,
      termin DP) + 1 penawaran, lalu bandingkan berdampingan — ditambah
      invoice Pelunasan (BELUM DIBAYAR), invoice Menunggu Verifikasi dan
      draf termin
- [x] **[Build]** `php artisan test` + `npm run build` lulus — `php artisan
      test` lulus (1722); `npm run build` tidak dijalankan di sub ini (tidak
      ada perubahan TS), dijalankan lead engineer

## Catatan pelaksanaan (2026-10-07)

K3 tidak dijawab user → dijalankan sesuai usulan ✱. Penawaran tidak
berubah (HTML penawaran sebelum/sesudah identik selain urutan CSS).

**Backend**
- `App\Support\Letters\InvoiceLetter` ditulis ulang menjadi data **tata
  letak tagihan**: `billTo` (nama, No. HP via `Phone::format` + email,
  alamat + kota lead), `meta` (No. Invoice · Tanggal · Jatuh Tempo ·
  Ref. Penawaran · Proyek · Termin), `summaryRows` (invoice jasa: satu baris
  per kelompok RAB — kelompok tunggal "Umum" tampil sebagai nama jasanya;
  invoice termin: satu baris "Termin 1 — DP 30% dari RAB Proyek {nomor}"),
  `adjustments` (Subtotal / Diskon / Pembulatan untuk jasa; "Dikurangi
  pembayaran diterima" bila termin sudah dibayar sebagian sebelum invoice
  terbit), `total` + `totalInWords`, `paymentInfo` (rekening aktif +
  a.n. badan usaha + "Cantumkan No. Invoice … pada berita transfer."),
  `status` (DITERBITKAN → BELUM DIBAYAR abu · MENUNGGU_VERIFIKASI →
  MENUNGGU VERIFIKASI oranye · TERVERIFIKASI → LUNAS hijau), catatan
  singkat dan penutup tanpa "Demikian penawaran…".
- `InvoiceLetter::forTermin()` — PDF termin yang **belum** ber-invoice
  (Keuangan → Termin) memakai tata letak yang sama, bertanda **DRAF** tanpa
  nomor (nomor tetap hanya dari `LetterNumberService` saat invoice
  diterbitkan), total = sisa piutang, cap DIBAYAR SEBAGIAN bila ada
  pembayaran parsial. Termin yang **sudah** ber-invoice: `termins.pdf`
  langsung mengalirkan PDF invoice-nya (`InvoiceController::streamPdf()`),
  jadi satu termin tidak punya dua dokumen tagihan berbeda.
- `LetterParts::bankAccounts()` — satu sumber rekening aktif untuk baris
  pembayaran penawaran dan kotak "Cara Pembayaran" invoice.
- `Termin::paymentTerm()` (relasi ke baris skema bayar, untuk label
  "DP"/"Pelunasan"). `InvoiceLetter::RELATIONS` = relasi yang dimuat
  controller PDF (lead + kontak + kota, proyek, RAB, termin).

**Tampilan**
- Layout baru `pdf/layouts/invoice` (tabel + CSS inline, tanpa flex/grid).
  Kop, footer, watermark dan tanda tangan dipecah menjadi partial
  `pdf/layouts/partials/{frame-css,letterhead,signature}` yang dipakai
  penawaran dan invoice — satu identitas perusahaan.
- **PDF saja**: tidak ada halaman web invoice (link klien hanya untuk
  penawaran), jadi tidak ada perubahan TS.

**Test** — `tests/Feature/Finance/InvoiceLayoutTest.php` (baru: isi invoice
jasa & termin, cap status, PDF termin draf/ber-invoice, penawaran tetap);
`CompanyLetterTest` disesuaikan (invoice tidak lagi memuat item RAB).
`php artisan test` penuh: 1722 lulus.

**Catatan terbuka**
- Watermark logo (bawaan Sprint 15, penawaran juga) tampil sebagai kotak
  abu bila logo yang diunggah berlatar pekat — bukan perubahan sub ini.
- `termins.pdf` boleh dibuka PM; sejak sub ini termin ber-invoice
  mengalirkan invoice-nya ke PM juga (isi sama dengan termin di Detail
  Proyek). Tombol PDF di Detail Proyek untuk termin ber-invoice masih ke
  `finance.invoices.pdf` (tanpa PM → 403 untuk PM); bisa diarahkan ke
  `finance.termins.pdf` bila diinginkan.

**Penutupan sprint (lead, 2026-10-07):** `php artisan test` penuh 1723
lulus, `npm run build` lulus, Pint lulus. Cek browser (Chrome headless,
Marketing): Salin Link di `http://web-daiku-interior.test` (bukan secure
context) → `execCommand('copy')` = true, toast "Link disalin.", tombol
"Tersalin"; peringatan "alamat lokal" tampil; kartu survey lead #37 SIAP;
notice RAB Proyek otomatis di lead #31. Uji dari HP sungguhan (K1) belum.
