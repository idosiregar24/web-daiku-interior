# Sprint 16 · 08 — Kota Lead dari Dropdown (Master Kota)

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K14–K15
> Status: **selesai 2026-10-07** · Butuh Sub 01. Tidak bergantung pada Sub
> 02–07.

## Masalah
`leads.city` adalah teks bebas, sehingga "Pekanbaru", "pekanbaru", "Pku",
dan "Kota Pekanbaru" tercatat sebagai kota yang berbeda. Golden rule #10
juga melarang teks bebas untuk data master.

## Rancangan
- **Master `cities`** (K14): kolom `id`, `name` (unique), `province`
  (nullable), dan timestamps.
  - Dikelola di **Data Master → Kota** (`role:SUPERADMIN`, sama seperti
    Sumber Lead).
  - Kota yang masih dipakai lead tidak bisa dihapus, hanya diganti nama
    (pola `LeadSourceController::destroy`).
- **`CitySeeder`** dipanggil dari `DatabaseSeeder` **dan**
  `ProductionSeeder`, dan hanya menambah nama yang belum ada. Isinya:
  - 12 kab/kota Riau: Pekanbaru, Dumai, Kampar, Bengkalis, Siak,
    Pelalawan, Rokan Hulu, Rokan Hilir, Indragiri Hulu, Indragiri Hilir,
    Kuantan Singingi, Kepulauan Meranti.
  - Kota besar sekitar: Batam, Tanjung Pinang, Padang, Bukittinggi, Medan,
    Jambi, Palembang, Jakarta.
- **`leads.city_id`**: FK nullable dengan index. Kota tetap opsional,
  sama seperti sekarang. Konversi data lama dari `leads.city`:
  - Cocokkan nama tanpa beda huruf besar/kecil.
  - Peta alias untuk varian yang dikenal ("Pku", "Kota Pekanbaru" →
    Pekanbaru; "Bangkinang" → Kampar).
  - Nama yang tidak cocok dibuat sebagai baris master baru supaya tidak
    hilang, lalu dicatat di "Catatan pelaksanaan" untuk dirapikan admin.
  - `down()` mengembalikan teks dari `cities.name`.
- **`CitySelect`** (`Components/shared/`): mengikuti pola `VendorSelect`.
  - Dikelompokkan per provinsi dengan Riau di atas dan Pekanbaru paling
    atas, ditambah pilihan "Tanpa kota".
  - Komponen `Select` biasa sudah cukup untuk ±20–60 baris. Kalau master
    tumbuh jauh di atas itu, ganti ke combobox (`npx shadcn add command`).
- **Kota belum ada:** Marketing tidak bisa mengetik kota baru. Di bawah
  dropdown tertulis "Kota tidak ada? Minta admin menambahkan di Data
  Master." Inilah yang menjaga data tetap konsisten.
- **Survey** (K15): saat menjadwalkan survey, centang "Luar Pekanbaru"
  otomatis bernilai benar bila kota lead bukan
  `config('daiku.home_city')` (default `Pekanbaru`). Centang itu masih
  bisa diubah, dan aturan `LeadSurveyRequest` tidak berubah.

## Checklist
- [x] **[Backend]** Migrasi `cities` + `leads.city_id` + konversi data
      lama (alias) + hapus `leads.city` (`down()` reversibel)
- [x] **[Backend]** Model `City` (`leads()`, `City::options()`), relasi
      `Lead::city()`, `CitySeeder` (Database + Production)
- [x] **[Backend]** `MasterData\CityController` store/update/destroy +
      Form Request + route `master-data.cities.*` (SUPERADMIN)
- [x] **[Backend]** `StoreLeadRequest`/`UpdateLeadRequest`: `city_id`
      `nullable|exists:cities,id`. `LeadController` mengirim `cities`
      dan eager-load `city:id,name` di index/show
- [x] **[Backend]** `config/daiku.php` `home_city` + default centang luar
      Pekanbaru di form survey
- [x] **[UI]** `Components/shared/CitySelect.tsx`
- [x] **[UI]** `LeadFormDialog` Kota → `CitySelect`; `CRM/Show` menampilkan
      `city.name`; tipe `Lead` + `CityOption` di `types/index.d.ts`
- [x] **[UI]** Data Master: tab "Kota" (nama + provinsi), dengan pola
      `BranchManager`
- [x] **[Docs]** Golden rule #10 di `.claude/CLAUDE.md`: tambahkan kota ke
      daftar master (`CitySelect` / `City::options()`)
- [x] **[Test]** RBAC `master-data.cities.*` (SUPERADMIN 302, MARKETING
      403); kota yang dipakai lead tidak bisa dihapus; `city_id` tidak
      dikenal ditolak; default survey luar kota
- [x] **[Build]** `php artisan test`, `npm run build`, `migrate:fresh --seed`
      lulus; tambah lead + Data Master Kota dicek di browser

## Catatan pelaksanaan (2026-10-07)
- K14 dijalankan sesuai usulan (daftar pendek, ditambah admin) — user tidak
  menjawab, usulan dipakai.
- `City` model: `DEFAULTS` (20 kota/kabupaten → provinsi), `ALIASES`
  (PKU, Kota Pekanbaru, Bangkinang→Kampar, Duri→Bengkalis, dst.),
  `canonicalName()`, `idFor()` (seeder), `options()`, `scopeOrdered()`
  (home city → Riau → provinsi lain). Satu migrasi
  `create_cities_and_move_lead_city_to_city_id`: FK `restrictOnDelete`;
  nama tak dikenal → baris master baru (provinsi kosong), tidak hilang.
- Data lokal setelah migrasi: Pekanbaru 24, Dumai 1, Kampar 1 (dari
  "Bangkinang"), 11 lead tanpa kota; `CitySeeder` menambah sisa daftar (20 kota).
- `CityController` + `CityRequest` (nama dirapikan spasi, unik), route
  `master-data.cities.*` di grup `role:SUPERADMIN`; tab "Kota" di Data
  Master (`CityManager`, tombol hapus hanya untuk kota tanpa lead).
- K15: atribut `Lead::is_outside_home_city` (di-append hanya di detail
  lead) → default centang "Luar Pekanbaru" di Jadwalkan Survey
  (`LeadTimeline`) dan Ajukan Survey (`SubmitLeadRequestDialog`).
- `CitySelect` dikelompokkan per provinsi + "Tanpa kota" + teks bantu
  "Kota tidak ada? Minta admin…". Grid form lead diberi `items-start`
  supaya label kolom sebelah tidak turun saat ada teks bantu/error.
- Verifikasi: `php artisan test` 1693 lulus, `npm run build` lulus,
  `migrate:fresh --seed` lulus di database sementara (`daiku_seedcheck`,
  lalu dihapus — database lokal tidak di-reset agar data uji lead #37
  untuk Sprint 17 tetap ada). Dicek di browser (Chrome headless): form
  Tambah Lead desktop + 390px + error, tab Data Master → Kota.
