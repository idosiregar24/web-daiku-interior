# Sprint 14 · 01 — Referensi Permintaan RAB (deskripsi, link, foto)

> Induk: [`../sprint-14-rab-referensi-template.md`](../sprint-14-rab-referensi-template.md).
> Status: **selesai 2026-10-06** · Prasyarat: — (Sprint 12 Sub 3–5, 12)

## Tujuan
Saat Marketing (atau PM untuk RAB Tambahan) meminta RAB, Estimator langsung
mendapat semua bahan: deskripsi yang terarah, link referensi, dan foto —
tanpa perlu bertanya lagi lewat chat.

## Rancangan
- **Deskripsi** tetap wajib (`request_note`, maks. 1000 → 2000 karakter),
  dengan petunjuk isian: ukuran/luas, gaya, budget klien, bahan, tenggat.
- **Link referensi**: maks. 5 URL `http/https` (Google Drive, Pinterest,
  Instagram, Maps, video). Tampil sebagai tautan `target=_blank
  rel="noopener noreferrer"`.
- **Foto referensi**: maks. 8 foto JPG/PNG/WEBP, ≤ 5 MB per foto. Disimpan
  di disk privat (`local`, `quotation-references/{quotation_id}/`),
  **bukan** folder publik; dilayani lewat route ber-auth dengan gate yang
  sama seperti `quotations.show`.
- Tabel baru `quotation_references` (`kind` LINK/PHOTO, `url`, `path`,
  `original_name`, `size`, `uploaded_by`) — satu baris per link/foto.
- Berlaku untuk dua pintu permintaan: "Minta RAB …" di Lead
  (`SubmitLeadRequestDialog`) dan "Minta RAB Tambahan" di proyek
  (`RequestAddendumDialog`). Ditampilkan di halaman Quotation (di bawah
  catatan permintaan) untuk semua role yang boleh membuka quotation itu.
- **Tidak** pernah ikut ke link persetujuan klien / PDF
  (`PublicQuotationResource` whitelist) — referensi adalah bahan internal.
- Notifikasi ke Estimator menyebut jumlah lampiran ("+3 foto, 2 link").

## File yang disentuh
- Migrasi `create_quotation_references_table`, model `QuotationReference`, `Quotation::references()`
- `app/Services/QuotationService.php` (`request()`, `requestAddendum()` + `attachReferences()`), `LeadService::submitRequest()`
- `SubmitLeadRequestRequest`, `RequestAddendumRequest` (aturan link/foto)
- `QuotationReferenceController@show` + route `quotations.references.show`
- `QuotationController@show` (muat referensi)
- Frontend: `Components/modules/quotation/RabReferenceFields.tsx` (baru), `SubmitLeadRequestDialog`, `RequestAddendumDialog`, `Pages/Quotation/Show.tsx`, `types/index.d.ts`
- Test: `tests/Feature/Quotation/QuotationReferenceTest.php`

## Checklist
- [x] **[Backend]** Tabel + model + simpan link/foto di kedua pintu permintaan (validasi: http/https, jumlah, tipe & ukuran foto)
- [x] **[Backend]** Foto dilayani lewat route ber-auth (role = `quotations.show`), tidak publik, tidak ikut ke klien
- [x] **[UI]** Isian link + foto (pratinjau, hapus sebelum kirim) di dialog Minta RAB & Minta RAB Tambahan; petunjuk deskripsi
- [x] **[UI]** Halaman Quotation: deskripsi + link + galeri foto untuk Estimator & reviewer
- [x] **[Test]** Simpan link/foto, tolak `javascript:`/terlalu banyak/bukan gambar, foto 200 untuk Estimator & 403 untuk role tanpa akses, tidak ada di halaman klien; `npm run build`

## Catatan pelaksanaan (2026-10-06)
- Tabel `quotation_references` + model `QuotationReference` (batas: 5 link,
  8 foto JPG/PNG/WEBP ≤ 5 MB) — aturan validasi bersama di trait
  `App\Http\Requests\Concerns\ValidatesRabReferences` (dipakai
  `SubmitLeadRequestRequest` & `RequestAddendumRequest`). Deskripsi kini
  maks. 2000 karakter dengan petunjuk isian (ukuran, gaya, bahan, budget, tenggat).
- `QuotationService::request()` / `requestAddendum()` menerima link & foto
  (`attachReferences()`, dalam transaksi yang sama); notifikasi Estimator
  diberi akhiran "(+2 foto, 1 link)".
- Foto di disk privat `local` (`quotation-references/{id}/`), dilayani
  `quotations.references.show` (role = `quotations.show`, scoped binding,
  `nosniff`). `path` disembunyikan dari JSON; halaman klien & PDF tidak
  memuatnya (dites).
- UI: `RabReferenceFields` (dialog Minta RAB & Minta RAB Tambahan) dan
  `RabReferenceList` (halaman Quotation, di bawah catatan permintaan).
- **Batas upload server**: PHP lokal 2 MB/file & 8 MB/request, produksi
  20 MB/request (Dockerfile + nginx). Karena itu foto **dikecilkan di
  browser** sebelum dikirim (JPEG, sisi terpanjang 1600 px, ±200–600 KB);
  8 foto muat di kedua batas. Belum dicoba di HP sungguhan.
