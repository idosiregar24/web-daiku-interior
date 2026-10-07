# Sprint 17 · 05 — Invoice Berbeda dari Penawaran

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T4 · Keputusan K3
> Status: **belum dikerjakan** · **menunggu jawaban K3**

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
- [ ] **[Keputusan]** K3 dijawab user (boleh dengan contoh invoice yang
      biasa dipakai Daiku)
- [ ] **[Backend]** `InvoiceLetter` → data tata letak tagihan; layout
      `pdf/layouts/invoice` + `pdf/invoice` & `pdf/termin` memakainya
- [ ] **[UI]** Tampilan web invoice mengikuti (bila ada), atau dicatat
      "PDF saja"
- [ ] **[Test]** Snapshot/assert isi PDF: invoice memuat "INVOICE", "Total
      Tagihan", "Cara Pembayaran", dan **tidak** memuat "Berlaku Sampai" /
      "Demikian penawaran"; penawaran tidak berubah
- [ ] **[Visual]** Cetak 3 contoh (invoice jasa survey, jasa desain,
      termin DP) + 1 penawaran, lalu bandingkan berdampingan
- [ ] **[Build]** `php artisan test` + `npm run build` lulus
