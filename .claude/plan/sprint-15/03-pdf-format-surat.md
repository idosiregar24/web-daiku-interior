# Sprint 15 · 03 — PDF Format Surat (Penawaran & Invoice)

> Induk: [`../sprint-15-surat-resmi-rab.md`](../sprint-15-surat-resmi-rab.md)
> Status: **selesai 2026-10-06**

## Rancangan (mengikuti contoh user)
- Kop tiap halaman: logo kiri, blok kuning kanan (alamat, email, telepon,
  Instagram), garis hitam; footer hitam `NAMA | tagline | tahun`;
  watermark logo samar di tengah.
- Isi: tanggal (kanan), Nomor, Perihal ("Penawaran …" / "Invoice …"),
  Kepada (nama klien), pembuka, tabel **NO · URAIAN PEKERJAAN · UKURAN
  (P, T) · VOLUME · SATUAN · SATUAN (Rp) · HARGA (Rp)** (bagian pekerjaan
  A, B… bila ada), TOTAL (+ diskon/pembulatan), **TERBILANG**, Catatan
  bernomor (nilai + rekening + catatan RAB), skema bayar (RAB Proyek),
  penutup, "Hormat kami," + logo + tanda tangan + nama.
- Layout Blade bersama `pdf/layouts/letter.blade.php` dipakai penawaran,
  invoice, dan termin.

## Checklist
- [x] **[PDF]** Layout surat bersama (kop, footer, watermark, tanda tangan)
- [x] **[PDF]** Penawaran & invoice (+ termin) memakai layout baru
- [x] **[Test]** PDF terunduh untuk RAB draf/terkirim & invoice; isi memuat nomor, terbilang, catatan

## Catatan pelaksanaan (2026-10-06)
- `resources/views/pdf/layouts/letter.blade.php` (DomPDF): kop tetap tiap halaman
  (logo/nama kiri, blok kuning kontak kanan, garis hitam), footer hitam, watermark
  logo 6%, tabel berkepala biru muda seperti contoh, TERBILANG, Catatan bernomor,
  skema termin (bila > 1 baris), penutup, tanda tangan + nama/jabatan, cap LUNAS.
- Data dari `App\Support\Letters\{LetterParts, QuotationLetter, InvoiceLetter}`;
  `pdf/quotation` & `pdf/invoice` kini hanya pintu masuk ke layout (input view
  tetap sama). Invoice Jasa Survey/Desain mengulang baris RAB-nya (+ diskon/
  pembulatan); DP/termin/pelunasan/tambahan = satu baris.
- Nama file: `penawaran-377-OFF-Daiku-IX-2026-{klien}.pdf` / `...-draf-v2-...`,
  `invoice-12-INV-Daiku-IX-2026.pdf` (tanpa "/").
- **PDF termin lama (`pdf/termin`, Sprint 4) tidak diubah**: sejak Sprint 12
  tagihan termin diterbitkan lewat Invoice, yang sudah memakai format baru.
- Dicek visual dari data demo (penawaran Jasa Desain terkirim, draf RAB
  Proyek, invoice Jasa Desain) — sesuai contoh user.
