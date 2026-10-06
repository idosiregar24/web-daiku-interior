# Sprint 15 · 04 — Link Klien Format Surat

> Induk: [`../sprint-15-surat-resmi-rab.md`](../sprint-15-surat-resmi-rab.md) · Keputusan: K1
> Status: **selesai 2026-10-06**

## Rancangan
- `Pages/Public/Quotation.tsx` tampil sebagai "surat" yang sama dengan PDF:
  kop (logo + kontak), nomor, perihal, kepada, tabel kolom sama, total,
  terbilang, catatan, tanda tangan, footer — tetap dengan tombol
  setuju/tolak & unduh PDF yang sudah ada. Responsif di HP.
- `PublicQuotationResource` (whitelist) menambah: nomor surat, perihal,
  terbilang, catatan, rekening, kop (Instagram, tagline), penandatangan.
  Tetap tanpa data internal (referensi, catatan permintaan, reviewer).

## Checklist
- [x] **[Backend]** Field baru di `PublicQuotationResource` (whitelist)
- [x] **[UI]** Halaman link klien format surat
- [x] **[Test]** Field baru ada, data internal tetap absen

## Catatan pelaksanaan (2026-10-06)
- `PublicQuotationResource` menambah `letter` (= `QuotationLetter`, isi untuk
  klien saja); test whitelist diperbarui dan kini juga mengunci daftar kunci `letter`.
- Komponen `LetterDocument` (kop, nomor, perihal, kepada, tabel — kartu di HP —,
  total, terbilang, skema, catatan, tanda tangan, footer) dipakai
  `Pages/Public/Quotation.tsx`; tombol **Unduh PDF** + Setujui di bawah surat.
- Route baru `public.quotation.pdf` (`penawaran/{token}/pdf`, throttle 30/menit,
  `noindex`, `no-referrer`) — 404 bila link sudah usang/ditarik.
- Tampilan web belum dicek di browser (diuji lewat test + smoke test 200 di MySQL).
