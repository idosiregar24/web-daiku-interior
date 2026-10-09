# Sprint 20 — Company Profile di `/` (SEO Lokal Pekanbaru)

> Status: **Sub 01–06 selesai di kode (2026-10-08)**; Sub 07 tinggal langkah di luar kode (Lighthouse di HP, deploy, Search Console, Google Business Profile, ganti placeholder). Ukuran terukur: `site.css` 7,3 KB gzip, `site.js` 1 KB gzip.
> Sumber: brainstorming dengan user 2026-10-08.
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Di luar PRD
> (PRD tidak punya situs publik). Sistem internal **tidak berubah perilaku**
> untuk user yang sudah login: `/` tetap mengalihkan mereka ke halaman awal
> role-nya (`RoleRedirectService`).

## 1. Tujuan & keputusan yang sudah diambil

**Tujuan:** menjaring lead baru dari pencarian Google lokal ("interior
Pekanbaru", "kitchen set Pekanbaru", "desain interior cafe Pekanbaru").
Kredibilitas bagi klien yang menerima link penawaran adalah bonus.

| # | Keputusan (user, 2026-10-08) | Akibat di kode |
|---|---|---|
| D1 | Tamu yang membuka `/` melihat company profile. User login tetap dialihkan. | Closure `/` di `routes/web.php` bercabang: guest → `SiteController@home`, auth → redirect lama. |
| D2 | Dibangun dengan **Blade** (HTML dari server), bukan Inertia | SEO bagus tanpa Inertia SSR/Node di server. Tanpa bundle React. Entry Vite terpisah `resources/css/site.css` + `resources/js/site.ts` (vanilla, kecil). |
| D3 | Section: Hero · Layanan · Portofolio · Cara Kerja · Angka · Testimoni · Tentang · Kontak | §3 Sub 02. |
| D4 | Tersambung ke sistem: portofolio & testimoni dari Pengaturan, konten bisa diedit. Kontak klien **lewat WhatsApp saja** (K1), tanpa form | Sub 04–05. |
| D5 | Di domain utama (sistem dan profile satu domain) | `robots.txt` + `sitemap.xml` + `noindex` di halaman sistem (Sub 03). |
| D6 | Materi (foto, testimoni, cerita) **placeholder dulu** | Konten placeholder dikumpulkan di **satu** tempat (Sub 01), diganti lewat Pengaturan (Sub 05). |
| D7 | Gaya visual **mengikuti sistem (Daiku Yellow)**, tapi **warna akan diganti nanti** | Token warna dipisah ke `resources/css/tokens.css` yang dipakai sistem **dan** profile. Ganti warna = ubah satu file (Sub 01). Profile tidak boleh punya hex sendiri. |

### Temuan dari kode (memengaruhi rencana)

- `/` sekarang: guest → `login`, auth → `RoleRedirectService` ([routes/web.php:75](../../routes/web.php)).
- **PWA `start_url` = `/`** (`PwaController::manifest`). Bila tidak diubah, tukang yang sesinya habis lalu membuka aplikasi dari layar HP akan melihat company profile, bukan form login → lihat K3.
- Sumber lead **"Website"** sudah ada di `LeadSourceSeeder`. Marketing memilihnya saat mencatat lead dari chat WhatsApp yang diawali pesan bawaan situs.
- `config('daiku.home_city')` = **Pekanbaru**. Master Kota (`cities`) + `CitySelect` sudah ada.
- `SiteSetting` sudah punya `site_name`, `company_address`, `company_phone`, `company_email`, `company_instagram`, `company_legal_name`, logo, favicon. `site_tagline` default = "Enterprise System" (untuk sistem, bukan untuk klien).
- Aset `SiteSetting` ada di disk privat `local`, disajikan lewat route `branding.show`. **`public/storage` belum di-link**, dan **belum ada library olah gambar** (Intervention/Spatie Image).
- Token warna ada di `resources/css/app.css` (`@theme` palet Daiku + `:root --primary` dsb).
- `robots.txt` = izinkan semua, tanpa `Sitemap:`. Halaman link klien (`penawaran/{token}`) sudah `noindex`.

## 2. Keputusan tambahan (dijawab user 2026-10-08)

| # | Pertanyaan | Jawaban |
|---|---|---|
| K1 | Form konsultasi dari web? | **Tidak ada form. Semua tombol kontak membuka WhatsApp** dengan pesan awal terisi. Tidak ada route publik yang menulis data, tidak ada tabel `web_inquiries`. |
| K2 | Siapa yang mengelola Portofolio & Testimoni? | **CEO + MARKETING** (+ SUPERADMIN otomatis). Teks profil (hero, tentang, angka) **CEO + SUPERADMIN**, sama dengan Pengaturan Situs. |
| K3 | PWA `start_url` | **`/app`** (usulan Claude, dijelaskan ke user). Ikon aplikasi di HP staf tetap membuka login/halaman role, bukan company profile. |
| K4 | Halaman per layanan terpisah? | **Dibedakan**: tiap layanan punya halaman sendiri (`/layanan/kitchen-set-pekanbaru` dst.) dengan teks, portofolio dan tombol WhatsApp sendiri. Beranda tetap merangkum semua layanan dan menautkan ke halamannya (Sub 06). |
| K5 | Perlindungan spam | **Tidak diperlukan.** Spam hanya datang lewat form isian, dan K1 meniadakan form. |
| K6 | Library gambar | **`intervention/image` v3** (driver GD) untuk mengecilkan foto + WebP saat diunggah. |

## 3. Sub-plan

| # | Sub | Isi | Bergantung |
|---|---|---|---|
| 01 | Fondasi | Route `/` bercabang, `/app` + PWA, token warna dipisah, entry Vite & layout Blade, konten placeholder | — |
| 02 | Halaman utama | Semua section, mobile-first, tombol WhatsApp, menu HP | 01 |
| 03 | SEO lokal teknis | Meta/OG/canonical, JSON-LD LocalBusiness, `sitemap.xml`, `robots.txt`, `noindex` halaman sistem, performa | 02 |
| 04 | Portofolio | Tabel + unggah foto (WebP) + kelola di ⚙ Pengaturan + grid publik + `/portofolio/{slug}` | 01, K2, K6 |
| 05 | Konten bisa diedit | Tab "Profil Publik" di Pengaturan Situs + Testimoni | 02, K2 |
| 06 | Halaman per layanan | `/layanan/{slug}` per jenis proyek: teks bisa diedit, portofolio sejenis, FAQ, WhatsApp berkonteks | 02, 04, 05 |
| 07 | Uji, deploy & langkah di luar kode | Test, build, Lighthouse, HP, deploy aaPanel, Search Console, Google Business Profile | semua |

Cara menyuruh: **"Kerjakan Sprint 20 Sub 1"** … **Sub 7**. Sub 03, 04 dan 05
saling lepas setelah Sub 02; Sub 06 setelah 04 dan 05.

---

## Sub 01 — Fondasi

- [x] `routes/web.php`: closure `/` → guest: `SiteController@home` (name `site.home`); auth: redirect `RoleRedirectService` seperti sekarang.
- [x] Route baru `GET /app` (name `app.home`) = logika redirect lama (guest → `login`, auth → halaman role). `PwaController` `start_url` → `/app`, `scope` tetap `/`.
- [x] ~~Tombol "Masuk Staf" di profile~~ — dihapus atas permintaan user (2026-10-08): situs publik tidak menautkan ke sistem; staf masuk lewat `/login` atau ikon aplikasi (`/app`).
- [x] Pisahkan token: `resources/css/tokens.css` berisi palet `@theme` Daiku + variabel semantik `:root`/dark. `app.css` meng-`@import` file itu. **Tampilan sistem tidak boleh berubah** (cek visual Dashboard, login, PDF tidak terpengaruh).
- [x] `resources/css/site.css` (Tailwind v4, `@import "tailwindcss"` + `tokens.css` + font Geist) dan `resources/js/site.ts` (menu HP, filter portofolio, lightbox; vanilla, tanpa React). Tambahkan keduanya ke `input` di `vite.config.js`.
- [x] `app/Http/Controllers/Site/SiteController.php` (`home`). Data lewat satu kelas `App\Support\CompanyProfile\ProfileContent` yang menggabungkan `SiteSetting` + konten (placeholder dulu, dari DB setelah Sub 05). View tidak membaca model langsung.
- [x] Layout `resources/views/site/layouts/main.blade.php` (head SEO, header, footer) + partial `resources/views/site/sections/*.blade.php`.
- [x] Placeholder: gambar netral di `public/images/site/placeholder-*.webp` (bukan foto stok bermerek), teks Indonesia ditandai `{{-- PLACEHOLDER --}}` agar mudah dicari.
- [x] Test: guest `GET /` → 200 + view `site.home`; user login `GET /` → redirect sesuai role (test lama tetap lulus); `GET /app` guest → login, auth → role; manifest `start_url` = `/app`.

## Sub 02 — Halaman utama

Urutan section (D3). Semua teks Bahasa Indonesia, kata kunci lokal muncul alami.

- [x] **Header**: logo (`BrandMark` versi Blade dari `site.logoUrl`), menu jangkar (Layanan · Portofolio · Cara Kerja · Kontak), tombol "Konsultasi Gratis" (WhatsApp) (tanpa link ke sistem — lihat Sub 01). Menu HP = panel geser.
- [x] **Hero**: H1 satu kali, memuat lokasi ("Desain & Pembuatan Interior di Pekanbaru"), subjudul, CTA "Konsultasi Gratis" (WhatsApp) + "Lihat Portofolio". Foto hero di-`preload`, dengan `width`/`height`.
- [x] **Layanan**: 3 pilar (`SiteSetting::DEFAULT_LETTER_FOOTER`: Interior Furnishing · Architectural Design · Building Construction) + kartu per jenis dari `ProjectType` (Kitchen Set, Kamar Set, Ruang Tamu/TV, Cafe, Toko/Retail, Kantor, Renovasi). Label dari enum, bukan ditulis ulang. Tiap kartu menaut ke halaman layanannya (Sub 06); sebelum Sub 06 selesai kartu langsung membuka WhatsApp.
- [x] **Portofolio**: grid 6 item (placeholder sampai Sub 04) + "Lihat semua".
- [x] **Cara Kerja**: Konsultasi → Survey Lokasi → Desain 3D → RAB Transparan → Produksi & Pemasangan → QA per Tahap → Serah Terima. Sesuai alur nyata sistem (Sprint 12).
- [x] **Angka**: tahun berdiri, proyek selesai, kota terlayani (placeholder; diedit di Sub 05, **bukan** dihitung otomatis dari tabel `projects`).
- [x] **Testimoni** (placeholder sampai Sub 05).
- [x] **Tentang Kami** + **Kontak**: alamat, telepon, email, Instagram dari `SiteSetting`; embed peta (URL dari Sub 05); area layanan (Pekanbaru + sekitarnya).
- [x] **WhatsApp = satu-satunya jalur kontak (K1)**: satu helper (`ProfileContent::whatsappUrl(?string $context)`) membangun `https://wa.me/62…?text=…` dari nomor lewat `App\Support\Phone` (bukan regex sendiri). Dipakai tombol melayang, Hero, Layanan, Kontak dan detail portofolio.
- [x] Pesan awal menyebut asal & konteks, supaya Marketing tahu sumbernya **Website**: "Halo Daiku Interior, saya melihat website Anda dan ingin konsultasi [Kitchen Set / proyek \"…\"]". Kartu layanan & portofolio mengisi konteksnya sendiri.
- [x] Link WA dibuka di tab baru (`rel="noopener"`). Tidak ada form, tidak ada route POST publik.
- [x] Footer: nama legal, alamat, sosial, © tahun.
- [x] Desain: token Daiku saja (D7), tanpa hex. Teks kuning di atas putih dilarang (`design-standards.md` §2). Layar 360 / 768 / 1366 px tanpa scroll horizontal.
- [x] Aksesibilitas: kontras AA, `alt` semua gambar, fokus terlihat, menu HP bisa ditutup dengan Esc.

## Sub 03 — SEO lokal teknis

- [x] `<title>` & meta description unik per halaman (beranda: "Daiku Interior — Jasa Desain & Interior Pekanbaru | Kitchen Set, Cafe, Kantor"), `<link rel="canonical">`, `lang="id"`.
- [x] Open Graph + Twitter card (gambar 1200×630 dari hero/portofolio).
- [x] JSON-LD `HomeAndConstructionBusiness` (turunan LocalBusiness): name, legalName, address, telephone, email, url, logo, image, `areaServed` Pekanbaru, `sameAs` Instagram, jam buka (Sub 05). Portofolio detail: `CreativeWork`/`ImageObject`.
- [x] `GET /sitemap.xml` (dinamis): beranda, `/portofolio`, setiap portofolio terbit, setiap halaman layanan terbit (Sub 06) (`lastmod` = `updated_at`).
- [x] `public/robots.txt`: `Allow: /`, `Disallow` untuk path sistem (`/login`, `/dashboard`, `/crm`, `/penawaran`…), plus `Sitemap: https://…/sitemap.xml`. Daftar path dibuat dari prefix route, bukan ditebak.
- [x] Header `X-Robots-Tag: noindex` untuk semua respons Inertia/halaman auth (middleware), supaya halaman login sistem tidak tampil di Google.
- [x] Meta verifikasi Google Search Console dari Pengaturan (Sub 05).
- [ ] Performa: gambar WebP + `loading="lazy"` (kecuali hero), `srcset` 2–3 ukuran, CSS < 30 KB gzip, JS < 10 KB. Target Lighthouse Mobile **≥ 90** untuk Performance, SEO, Accessibility.
- [x] Cache: respons halaman publik boleh `Cache-Control: public, max-age=300` untuk guest (tanpa cookie sesi bila memungkinkan; aman karena tidak ada form).

## Sub 04 — Portofolio

- [x] Migrasi `portfolio_items`: `title`, `slug` UNIQUE, `project_type` (string, cast `ProjectType`), `city_id` FK nullable, `location_label` (mis. "Panam", **bukan alamat lengkap**), `year`, `summary`, `description`, `cover_photo_id` nullable, `is_published` bool, `client_consent` bool, `project_id` FK nullable (`nullOnDelete`), `sort_order`, `published_at`, `created_by`, timestamps. Index `(is_published, sort_order)`.
- [x] Migrasi `portfolio_photos`: `portfolio_item_id` (cascade), `path`, `path_thumb`, `width`, `height`, `alt`, `caption`, `sort_order`.
- [x] `composer require intervention/image` (K6). `PortfolioPhotoService`: simpan ke disk `public`, maksimal 2000 px + thumb 600 px, WebP q80, buang EXIF (lokasi GPS dari foto HP). `php artisan storage:link` (+ catatan deploy).
- [x] `PortfolioService`: create/update/publish. **Terbit wajib `client_consent = true`** (cek di Service, pesan Indonesia). Slug otomatis dari judul + kota.
- [x] Halaman sistem: ⚙ Pengaturan → tab **"Portofolio"** (`settings.portfolio.*`, `role:CEO|MARKETING`), daftar + form (React, `DataTable`, unggah banyak foto, urutkan, pilih cover). Dari proyek `COMPLETED` ada tombol "Jadikan Portofolio" yang hanya mengisi judul/jenis/kota; tidak ada nama klien, alamat atau nilai RAB yang ikut.
- [x] Publik: section grid di beranda (6 terbaru/terurut), `/portofolio` (filter per jenis via query string, server-side, paginasi), `/portofolio/{slug}` (galeri + lightbox + tombol WhatsApp yang menyebut judul portofolio).
- [x] Item yang belum terbit → 404 untuk publik.
- [x] Test: RBAC (CEO/MARKETING boleh, PM/FINANCE/FIELD_STAFF 403), publish tanpa consent ditolak, unggah gambar menghasilkan WebP + thumb dan EXIF hilang, halaman publik hanya menampilkan item terbit, sitemap memuat slug terbit.

## Sub 05 — Konten bisa diedit

- [x] Migrasi `add_public_profile_to_site_settings_table`: `public_tagline`, `hero_headline`, `hero_subheadline`, `about_text`, `founded_year`, `stat_projects`, `stat_cities`, `service_area_text`, `whatsapp_phone` (fallback `company_phone`), `whatsapp_greeting`, `maps_embed_url`, `opening_hours`, `google_site_verification`. Ditambah aset `hero_image` ke `ASSETS` + `PUBLIC_ASSETS`.
- [x] Pengaturan Situs (`Settings/Edit.tsx`): tab kecil **"Profil Publik"** (CEO + SUPERADMIN, gate tetap). Form Request + Zod sama; `maps_embed_url` hanya `https://www.google.com/maps/embed?...`.
- [x] Migrasi `testimonials`: `client_label` (mis. "Ibu R., Kitchen Set — Panam"), `quote`, `portfolio_item_id` nullable, `is_published`, `sort_order`, `created_by`. Kelola di ⚙ Pengaturan → tab "Testimoni" (`role:CEO|MARKETING`). Hanya teks, tanpa rating bintang palsu.
- [x] `ProfileContent` membaca semua ini dan memakai placeholder hanya untuk kolom yang kosong. Section disembunyikan bila datanya benar-benar kosong (mis. testimoni 0 setelah mode produksi).
- [x] Test: RBAC, nilai tersimpan tampil di `/`, URL peta selain Google ditolak.

## Sub 06 — Halaman per layanan (K4)

Tujuan: satu halaman per kata kunci ("kitchen set Pekanbaru", "interior cafe
Pekanbaru", "desain kantor Pekanbaru"). Halaman yang isinya sama dengan
halaman lain justru menurunkan peringkat, jadi tiap halaman wajib punya teks
sendiri dan tidak diindeks selama masih placeholder.

- [x] Daftar layanan dari `ProjectType`, **kecuali** `LAINNYA`. `TOKO` + `RETAIL_TOKO` digabung jadi satu halaman ("Toko & Retail"). Pemetaan jenis → slug dibuat sekali (`App\Support\CompanyProfile\ServiceCatalog`), slug = jenis + kota asal (`config('daiku.home_city')`), mis. `kitchen-set-pekanbaru`, `interior-cafe-pekanbaru`, `desain-arsitektur-pekanbaru`.
- [x] Migrasi `service_pages`: `project_type` UNIQUE (string, cast `ProjectType`), `slug` UNIQUE, `title`, `headline`, `intro`, `body` (teks + subjudul sederhana, dirender aman tanpa HTML mentah), `highlights` JSON (poin keunggulan), `faqs` JSON (`[{q, a}]`), `meta_description`, `hero_image` (path, disk `public`, lewat `PortfolioPhotoService` yang sama), `is_published`, `updated_by`, timestamps. Seeder membuat baris untuk tiap layanan dengan teks **placeholder** + `is_published = false`.
- [x] `ServicePageService::update()` + Form Request (`meta_description` ≤ 160 karakter, maksimal 8 FAQ). Terbit hanya bila `intro` dan `body` sudah diubah dari placeholder (cek di Service, pesan Indonesia).
- [x] Halaman sistem: ⚙ Pengaturan → tab **"Halaman Layanan"** (`settings.service-pages.*`, `role:CEO|MARKETING`, sama dengan K2): daftar layanan + status terbit, form edit (React + Zod, bintang wajib sesuai Request), pratinjau link publik.
- [x] Publik `GET /layanan/{slug}` (`site.services.show`):
  - H1 memuat layanan + kota ("Jasa Kitchen Set Pekanbaru"), intro, isi, poin keunggulan.
  - **Portofolio sejenis**: item terbit dengan `project_type` yang sama (Sub 04), maksimal 6, + link ke `/portofolio?jenis=…`.
  - Cara Kerja (partial yang sama dengan beranda, bukan salinan).
  - FAQ (accordion `<details>`, tanpa JS).
  - Tombol WhatsApp dengan konteks layanan ("…ingin konsultasi Kitchen Set").
  - Tautan ke layanan lain di bagian bawah (tautan internal).
- [x] Belum terbit: halaman tetap bisa dibuka (agar kartu beranda tidak rusak) tetapi `noindex` dan tidak masuk sitemap. Slug tidak dikenal → 404. Slug lama berubah? Tidak bisa diubah dari UI (slug tetap dari `ServiceCatalog`).
- [x] SEO: `<title>`/meta description dari kolom sendiri, canonical, JSON-LD `Service` (`provider` = bisnis dari Sub 03, `areaServed` Pekanbaru) + `FAQPage` bila ada FAQ, breadcrumb `BreadcrumbList` (Beranda › Layanan › Kitchen Set).
- [x] Beranda: kartu Layanan menaut ke halamannya. Menu header: "Layanan" jadi dropdown berisi semua halaman layanan. Footer: daftar layanan (tautan internal).
- [x] Test: RBAC tab Pengaturan (CEO/MARKETING 200, PM/FINANCE/FIELD_STAFF 403), terbit dengan teks placeholder ditolak, halaman belum terbit ber-`noindex` & tidak ada di sitemap, halaman terbit ada di sitemap, portofolio sejenis hanya yang terbit & jenisnya sama, `TOKO` dan `RETAIL_TOKO` ke halaman yang sama, slug tak dikenal 404.

## Sub 07 — Uji, deploy & langkah di luar kode

- [x] `php artisan test` lulus (2029), `npm run build` lulus, `pint`.
- [ ] `/security-review` pada diff Sub 04 (unggah file gambar) dan route publik baru (hanya baca).
- [ ] Lighthouse Mobile ≥ 90 (Performance/SEO/Accessibility) di beranda, satu halaman layanan & detail portofolio. Cek di HP sungguhan.
- [ ] Deploy aaPanel: `composer install` (intervention), `php artisan storage:link`, `migrate`, `npm run build`, ekstensi GD + WebP aktif di PHP server. Perbarui `deploy/DEPLOY-AAPANEL.md`.
- [ ] Uji PWA di HP: ikon lama (start_url `/`) → masih masuk ke halaman role bila login. Pasang ulang → `/app`.
- [ ] **Di luar kode, dampak terbesar untuk "interior Pekanbaru":**
  - [ ] **Google Business Profile** (Google Maps) atas nama Daiku Interior, kategori "Desainer interior", alamat & nomor **sama persis** dengan situs (NAP konsisten), link ke domain, foto proyek, minta ulasan klien selesai.
  - [ ] Google Search Console: verifikasi domain, kirim `sitemap.xml`.
  - [ ] Bio Instagram → link ke domain.
- [ ] Ganti semua placeholder (`grep PLACEHOLDER`) dengan materi asli sebelum diumumkan, lalu terbitkan halaman layanan satu per satu.
- [ ] Google Business Profile: daftarkan tiap layanan di bagian "Layanan" dengan link ke halaman layanannya.
- [x] Update `plan/README.md` + `CLAUDE.md` (golden rule baru: situs publik Blade, token warna di `tokens.css`, kontak publik hanya WhatsApp, portofolio terbit wajib persetujuan klien).

## Revisi desain (user, 2026-10-08)

Atas permintaan user, tampilan situs publik dirombak mengikuti tiga referensi
("Horizon Courts") dengan **latar putih bersih**:

- Header: menu di tengah (halaman aktif = garis pil), tombol pil gelap "Konsultasi ↗". Tanpa link "Masuk Staf".
- Hero: satu foto lebar bersudut besar (`rounded-[2rem]`), H1 tipis (font-light) di tengah, pil kaca + link sosial di sudut bawah. Foto utama kini **mendatar** (crop 2:1 / 16:9, min 1200×600).
- Tiap section: label pil kecil di kiri + kalimat besar dua nada di kanan (kalimat pertama gelap, sisanya abu — `ProfileContent::lead()`).
- Tentang: tiga kartu bento (gelap · foto · angka abu muda) + baris "dalam angka" (`facts()`).
- Layanan: kartu foto per layanan dalam baris geser (scroll-snap + tombol panah, `site.ts`), gambar dari `serviceImage()`.
- Halaman detail layanan & portofolio: pola profil (foto kiri, kartu info abu muda kanan dengan chip kuning, CTA pil gelap + teks bantu).
- Kuning menggantikan biru referensi, hanya sebagai isian (chip, nomor tahap, tombol panah) — tidak pernah sebagai teks di atas putih.
- **Foto contoh nyata** (permintaan user): ilustrasi diganti 17 foto interior dari Pexels (lisensi Pexels: bebas komersial, tanpa atribusi; tanpa orang/merek), dipotong ke WebP di `public/images/site` (hero 1920/960 `srcset`, kartu 1200×1200). Sumber tiap file di `Placeholder::PHOTO_CREDITS`. Label "Foto contoh" yang terlihat **dihapus atas permintaan user (2026-10-09)**; tinggal di teks `alt`. Foto ini **bukan karya Daiku**, wajib diganti foto proyek asli sebelum diumumkan (menampilkan karya orang lain sebagai portofolio menyesatkan klien).
- **Animasi** (`site.css` + `site.ts`, tanpa library): hero zoom halus + teks muncul bertahap, section/kartu naik-pudar saat di-scroll (`data-reveal`, `data-reveal-group` = berurutan), angka menghitung naik (`data-count`), menu HP bergeser masuk, dropdown/FAQ membuka lembut, panah tombol bergeser saat hover, garis header saat scroll. Mati otomatis bila `prefers-reduced-motion`; tanpa JS semua konten tetap tampil (`html.js`).

## 4. Ditunda / di luar sprint ini

- Form konsultasi di situs (K1: WhatsApp saja). Bila suatu saat dibuat: antrean `web_inquiries` → "Jadikan Lead", dengan throttle + honeypot karena menjadi route publik yang menulis data.
- Halaman layanan untuk kota lain di luar Pekanbaru (mis. `kitchen-set-dumai`). Baru dibuat bila Daiku memang punya proyek dan teks asli untuk kota itu.
- Blog/artikel ("Tips memilih kitchen set") untuk kata kunci panjang.
- Penggantian warna merek (D7): cukup ubah `tokens.css` setelah Sub 01; tidak perlu sprint sendiri.
- Bahasa Inggris.
