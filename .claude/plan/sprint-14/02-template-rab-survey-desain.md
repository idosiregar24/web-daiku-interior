# Sprint 14 · 02 — "Buat RAB": jenis RAB custom + riwayat RAB per klien

> Induk: [`../sprint-14-rab-referensi-template.md`](../sprint-14-rab-referensi-template.md).
> Status: **selesai 2026-10-06** — keputusan dijawab user 2026-10-06.
>
> Riwayat arah: (1) usulan template terpisah RAB Survey/Desain → (2) owner:
> template Survey & Desain **sama**, pembuat RAB tidak perlu diubah → (3)
> user: RAB cukup bisa **dikelompokkan per jenis dengan riwayat** dan
> **diberi nama custom** bila bukan Survey/Desain/Proyek (rancangan ini).
> Nama file dipertahankan supaya tautan lama tidak putus.

## Permintaan user (2026-10-06)
> "RAB-nya bisa dibuat menjadi beberapa section, jadi kelihatan history
> mulai dari RAB desain, survey dan proyek, atau nama RAB-nya bisa di-custom
> jika dibutuhkan RAB selain ke-3 itu. Kalau mau buat RAB tinggal klik
> 'Buat RAB', isi nama RAB-nya (ada pilihan 3 di atas, kalau tidak ada baru custom)."

## Kondisi sekarang (kode)
- `quotations.type` = enum `SURVEY | DESAIN | PROYEK`, dan **menentukan logika**:
  survey luar kota terbuka setelah RAB Survey dibayar; desain terbuka setelah
  RAB Desain dibayar (`DesignService`); RAB Proyek direview sampai CEO,
  skema DP/termin, lalu Buka Proyek (`QueueProjectOpening`), RAB Tambahan;
  jenis invoice diturunkan dari jenis RAB (`InvoiceType::forQuotation…`).
- Satu RAB berjalan per jenis per lead (`QuotationService::request()`).
- Lead detail menampilkan RAB Survey/Desain di kartu Desain dan RAB Proyek
  di kartu Proyek — belum ada satu tempat riwayat semua RAB.

## Keputusan (dijawab user 2026-10-06)
1. RAB custom berperilaku **seperti proyek** (review PM → CEO, skema DP/termin, Buka Proyek, RAB Tambahan).
2. Tetap **Marketing meminta → Estimator menyusun** (alur Sprint 12 #7).
3. Nama custom **teks bebas + saran** dari nama yang pernah dipakai.

## Rancangan final
- Karena perilakunya sama persis dengan RAB Proyek, RAB custom **adalah RAB
  Proyek bernama khusus**: `quotations.type = PROYEK` + kolom baru
  `quotations.custom_name`. **Tidak ada jenis enum baru** — review, skema
  bayar, invoice, Buka Proyek, RAB Tambahan, dan penutupan lead jalan tanpa
  diubah. Konsekuensi: aturan "satu RAB Proyek berjalan per klien" juga
  berlaku untuk RAB custom (pekerjaan lanjutan di proyek berjalan = RAB Tambahan).
- `Quotation::title()` = satu sumber nama RAB: nama custom (diawali "RAB "
  bila belum) → "RAB Tambahan" → label jenis. Dipakai header, notifikasi,
  PDF, Excel, link klien, Perlu Tindakan, pencarian. Frontend: `quotationTitle()`.
- Tombol **"Buat RAB"** (pengganti "Minta RAB") di detail lead → pilihan
  Jasa Survey · Jasa Desain · Proyek · **Lainnya (isi nama)**, + deskripsi,
  link, foto (Sub 01). Nama custom memakai `<datalist>` saran dari nama yang
  pernah dipakai (`customRabNames`).
- Kartu **"Riwayat RAB"** di detail lead: semua RAB klien dikelompokkan per
  jenis (Jasa Survey · Jasa Desain · Proyek · RAB Tambahan · tiap nama
  custom), tiap baris versi, status, total, tanggal, tautan.
- Daftar Quotation: kolom jenis memakai judul; filter jenis + "Lainnya (nama custom)".

## File yang kemungkinan disentuh
- Migrasi: `quotations.custom_name`; `Quotation::title()`
- `QuotationService` (request/aturan satu-berjalan, review stage, skema bayar), `InvoiceService`, `LeadService::RAB_REQUEST_TYPES`
- `SubmitLeadRequestDialog` → "Buat RAB"; `Pages/CRM/Show.tsx` (kartu riwayat RAB); `Pages/Quotation/{Index,Show}.tsx`; PDF & halaman klien (judul = nama custom)
- `ActionInboxService` (label antrean memakai nama custom)
- Test: `tests/Feature/Quotation/CustomRabTest.php`

## Checklist (disusun final setelah keputusan)
- [x] **[Diskusi]** Jawab keputusan 1–3 (2026-10-06)
- [x] **[Backend]** `custom_name` di RAB Proyek + `Quotation::title()` dipakai di semua tampilan/notifikasi
- [x] **[UI]** "Buat RAB" (3 pilihan + Lainnya) dan kartu riwayat RAB per klien (dikelompokkan per jenis)
- [x] **[Test]** RAB custom dari permintaan sampai lunas; Survey/Desain/Proyek tidak berubah; `npm run build`

## Catatan pelaksanaan (2026-10-06)
- Migrasi `add_custom_name_to_quotations_table` (varchar 100, nullable).
  `Quotation::title()` + `Quotation::FILTER_CUSTOM` (`scopeByType('CUSTOM')`).
  `QuotationService::request(..., ?string $customName)` — nama hanya disimpan
  untuk RAB Proyek; pesan "masih berjalan" menyebut judul RAB yang berjalan.
  `LeadService::RAB_REQUEST_TYPES['RAB_LAINNYA'] = Proyek`;
  `SubmitLeadRequestRequest`: `custom_name` wajib (3–100) bila Lainnya.
- Judul dipakai di: notifikasi QuotationService, pesan sukses, link klien
  (`PublicQuotationResource`), PDF quotation & invoice, Excel, Perlu
  Tindakan, pencarian, halaman Quotation (header/breadcrumb/WhatsApp share),
  daftar Quotation (kolom + filter "RAB Lainnya (nama khusus)").
- Detail lead: tombol **"Buat RAB"** (pengganti "Minta RAB"), dialog dengan
  pilihan RAB Jasa Survey · RAB Jasa Desain · RAB Proyek · **RAB Lainnya**
  (nama + `<datalist>` saran `customRabNames`), dan kartu **Riwayat RAB**
  (`RabHistoryCard`: bagian Jasa Survey → Jasa Desain → Proyek → RAB Tambahan
  → nama custom A–Z). Daftar RAB jasa yang dulu menempel di baris "Quotation"
  dipindah ke kartu ini.
- Diuji: `CustomRabTest` (9 test) + smoke test MySQL (detail lead, filter
  CUSTOM, Quotation, PDF, pencarian, Perlu Tindakan → 200).
