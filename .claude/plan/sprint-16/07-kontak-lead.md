# Sprint 16 · 07 — Kontak Lead: No. HP & Email Berformat

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K10–K13
> Status: **selesai 2026-10-07** · Butuh Sub 01 (prop `required`). Tidak
> bergantung pada Sub 02–06, jadi boleh dikerjakan langsung setelah Sub 01.

## Masalah
`leads.contact` adalah satu kolom teks bebas ("phone/email"). Isinya
campur aduk: `0812-1111-0001`, `+62 812…`, email, handle Instagram.
Akibatnya muncul dua salinan `whatsappNumber()` (`Dashboard.tsx` dan
`ShareLinkPanel.tsx`) yang masing-masing menebak apakah isinya nomor HP.

## Rancangan
- **Kolom:** `contact` diganti `phone` (string 20, nullable) dan `email`
  (string 255, nullable), dengan syarat **minimal satu terisi** (K10).
  Keduanya diberi index (dipakai pencarian & cek lead ganda nanti).
- **No. HP** (K11): hanya angka, diawali `08`, panjang 10–13 digit
  (`/^08\d{8,11}$/`). Disimpan sebagai angka saja (`081234567890`) dan
  ditampilkan `0812-3456-7890`.
  - Normalisasi dilakukan di **dua sisi**: `prepareForValidation()` di
    Form Request dan `onChange` di input. Spasi, strip, dan titik dibuang;
    `+62`/`62` di depan diubah jadi `0`.
  - Input memakai `inputMode="numeric"`, dan huruf langsung ditolak saat
    diketik.
- **Email** (K12): `email:rfc` di server, sedangkan Zod memakai `z.email()`.
  Semua domain diterima (Gmail hanyalah salah satunya). Disimpan dalam
  huruf kecil setelah di-`trim`.
- **Satu sumber helper:**
  - `App\Support\Phone`: `normalize()`, `isValid()`, `format()`,
    `whatsapp()`.
  - `resources/js/lib/phone.ts`: fungsi yang sama untuk frontend.
    Kedua salinan `whatsappNumber()` yang ada dihapus dan diganti helper
    ini.
- **Form:** label grup **"Kontak \*"** diikuti dua isian "No. HP" dan
  "Email", plus keterangan "Isi No. HP, email, atau keduanya." (aturan
  grup, K8 Sub 01).
- **Data lama** (K13), lewat migrasi data:
  - Isi yang mengandung `@` dan domain → `email`.
  - Isi yang lolos `Phone::normalize()` → `phone`.
  - Sisanya (handle IG, nomor rusak) ditambahkan ke `notes` dengan awalan
    "Kontak lama: …", sehingga tidak ada data yang hilang.
  - Lead tanpa HP/email tetap tersimpan, tetapi harus dilengkapi saat
    diedit.
  - `down()` menyusun kembali `contact` dari `phone ?? email`.

## Checklist
- [x] **[Backend]** Migrasi `phone` + `email` + konversi data lama + hapus
      `contact` (`down()` reversibel)
- [x] **[Backend]** `App\Support\Phone` (normalize/isValid/format/whatsapp)
- [x] **[Backend]** `StoreLeadRequest` & `UpdateLeadRequest`:
      normalisasi, aturan `phone` `required_without:email` + regex,
      `email` `required_without:phone` + `email:rfc`. Pesan dalam Bahasa
      Indonesia, misalnya "No. HP hanya angka dan diawali 08 (10–13
      digit)."
- [x] **[Backend]** Ganti semua pemakai `contact`: `Lead` (`$fillable`),
      `LeadService` (teks notifikasi follow-up), `DashboardController`
      (select), `QuotationController` (eager `lead:id,client_name,…`)
- [x] **[Backend]** `LeadFactory`, `DemoDataSeeder`, `Demo/WorkflowScenarioSeeder`
      memakai format baru (`08…` angka saja + email contoh)
- [x] **[UI]** `lib/phone.ts` dan hapus duplikat `whatsappNumber()` di
      `Dashboard.tsx` & `ShareLinkPanel.tsx`
- [x] **[UI]** `LeadFormDialog`: grup Kontak (No. HP + Email); Zod mencerminkan
      server, termasuk `refine` minimal satu
- [x] **[UI]** Tampilan: kolom Kontak di `CRM/Index`, `CRM/Show`, widget
      follow-up `Dashboard`, `Quotation/Show` → `ShareLinkPanel` (prop
      `phone`). Tipe `Lead` di `types/index.d.ts`
- [x] **[Test]** Pest: huruf ditolak, tidak diawali 08 ditolak, `+62 812-…`
      tersimpan `0812…`, email salah ditolak, keduanya kosong ditolak,
      email saja OK. Unit test `Phone`. Perbarui test lead yang sudah ada
- [x] **[Build]** `php artisan test`, `npm run build`, `migrate:fresh --seed`
      lulus; tambah & edit lead dicek di browser (desktop + 390px)

## Catatan pelaksanaan (2026-10-07)
- K10 dijalankan sesuai usulan (dua isian, minimal satu) — user tidak
  menjawab, usulan dipakai.
- Aturan bersama Store/Update: trait `Requests/Concerns/ValidatesLeadContact`
  (`prepareForValidation` menormalkan HP & email; `required_without`
  silang + regex `Phone::PATTERN` + `email:rfc`).
- Migrasi `split_contact_into_phone_and_email_on_leads_table` (index pada
  `phone` & `email`). Data lokal: 37/37 lead → `phone`, 0 ke catatan.
- `Lead::contactLabel()` (teks notifikasi follow-up) dan
  `Lead::scopeSearch()` — daftar lead & pencarian topbar kini juga mencari
  email dan digit No. HP ("+62 812-7777" menemukan 0812 7777…).
- Frontend: `lib/phone.ts` (`normalizePhone`, `sanitizePhoneInput`,
  `isValidPhone`, `formatPhone`, `whatsappNumber`, `contactLabel`); dua
  salinan `whatsappNumber()` lama dihapus. `ShareLinkPanel` menerima prop
  `phone`. Input HP: huruf langsung dibuang saat diketik, dinormalkan saat
  blur. Detail lead menampilkan "No. HP" dan "Email" terpisah.
- Test: `tests/Unit/PhoneTest.php`, `tests/Feature/CRM/LeadContactTest.php`
  (normalisasi, email saja, 5 kasus tolak, update, pencarian, migrasi
  konversi + rollback); test lama dipindah ke `phone`.
