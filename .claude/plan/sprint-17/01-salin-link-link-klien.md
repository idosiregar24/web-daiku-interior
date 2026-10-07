# Sprint 17 · 01 — Salin Link & Link Klien

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T1, T2 · Keputusan K1
> Status: **selesai 2026-10-07**

## Rancangan
- **`lib/clipboard.ts` → `copyText(text): Promise<boolean>`**
  - Pakai `navigator.clipboard.writeText` bila `window.isSecureContext`.
  - Selain itu pakai cadangan `<textarea>` tersembunyi + `document.execCommand('copy')`.
  - Mengembalikan `false` bila keduanya gagal.
  - Dipakai `ShareLinkPanel` **dan** `RabHistoryCard`, yang sekarang
    masing-masing memanggil `navigator.clipboard` sendiri.
- **Umpan balik:** toast `sonner` "Link disalin" atau "Gagal menyalin —
  tekan lama pada link untuk menyalin". Teks link tetap ter-*select*
  supaya bisa disalin manual. Tidak ada lagi `catch {}` yang diam.
- **Peringatan link lokal (K1):** bila host `APP_URL` berakhiran `.test`,
  `.local`, `localhost` atau `127.*`, `ShareLinkPanel` menampilkan
  `Notice tone="warning"`: "Link ini memakai alamat lokal — hanya bisa
  dibuka di komputer ini, tidak dari HP klien." Flag dikirim dari server
  sebagai shared prop `appUrlIsLocal`, tidak ditebak di frontend.
- **Error app key (T2b):**
  - Telusuri 4 entri `production.ERROR … No application encryption key`
    (5–6 Okt, lewat `public/index.php`). Cek apakah ada akses lewat vhost
    Apache dengan PHP berbeda (Golden rule: Laragon PHP harus 8.4), atau
    `.env` sedang ditulis saat request masuk.
  - Catat hasilnya di "Catatan pelaksanaan".
  - Bila penyebabnya `config:cache` lama: `php artisan optimize:clear`,
    lalu tulis langkah pencegahannya di CLAUDE.md "Local environment".
- **Ziggy publik:** `app.blade.php` memakai `@routes` dengan grup
  `public` (`config/ziggy.php` → `groups.public` = `public.quotation.*`,
  `login`) bila request adalah halaman publik. Halaman berautentikasi
  tetap memakai seluruh route.

## Checklist
- [x] **[UI]** `lib/clipboard.ts` + pakai di `ShareLinkPanel` &
      `RabHistoryCard`, toast sukses/gagal
- [x] **[Backend]** Shared prop `appUrlIsLocal` (HandleInertiaRequests)
- [x] **[UI]** Notice "alamat lokal" di `ShareLinkPanel`
- [x] **[Infra]** Telusuri penyebab `MissingAppKeyException` + catat; perbaiki
      atau dokumentasikan pencegahannya
- [x] **[Security]** Ziggy grup `public` untuk `penawaran/{token}`; pastikan
      tombol Setujui/PDF di halaman publik masih jalan
- [x] **[Test]** Feature: halaman publik tidak memuat nama route internal
      (mis. `horizon.`, `finance.`); `appUrlIsLocal` benar untuk `.test`
      vs domain publik
- [x] **[Build]** `npm run build` + `php artisan test` lulus; salin link dicoba
      di `http://web-daiku-interior.test` (Chrome) dan di HP lewat alamat
      yang bisa dijangkau (K1)

## Catatan pelaksanaan (2026-10-07)

**Salin link (T1).** `resources/js/lib/clipboard.ts` → `copyText(text)`:
`navigator.clipboard.writeText` hanya bila `window.isSecureContext`; selain
itu (atau bila ditolak) `<textarea>` tersembunyi + `document.execCommand('copy')`;
`false` bila keduanya gagal. `ShareLinkPanel` dan `RabHistoryCard` memakai
fungsi ini — tidak ada lagi `catch {}` yang diam. `ShareLinkPanel`:
toast "Link disalin." / "Gagal menyalin — tekan lama pada link untuk
menyalin." dan saat gagal kolom link di-*focus* + *select* (lewat `useId`,
karena `Input` shadcn di React 18 tidak meneruskan `ref`). `RabHistoryCard`
mempertahankan teks toast lamanya.

**Peringatan link lokal (K1).** Shared prop `appUrlIsLocal`
(`HandleInertiaRequests::appUrlIsLocal()`, lazy): host `APP_URL` kosong,
`localhost`/`*.localhost`, `*.test`, `*.local`, `127.*`, `::1` → `true`.
IP LAN (`192.168.*`) sengaja **tidak** dianggap lokal — itu justru cara
uji dari HP (K1). Tipe di `PageProps` (`types/index.d.ts`).
`ShareLinkPanel` menampilkan `Notice tone="warning"`.

**Ziggy publik.** `config/ziggy.php` baru: `groups.public` =
`public.quotation.*`, `login`. `app.blade.php` memakai
`@routes(request()->routeIs('public.*') ? 'public' : null)` — halaman
publik hanya menerima route publik (Setujui = `public.quotation.approve`,
PDF = `public.quotation.pdf`, satu-satunya `route()` di
`Pages/Public/Quotation.tsx`; `PublicLayout`/`LetterDocument`/`BrandMark`
tidak memanggil `route()`), halaman staf tetap seluruh route (tidak ada
`only`/`except` global). Kunjungan Inertia berikutnya (redirect setelah
Setujui) tidak merender ulang blade, jadi daftar route tetap yang awal.

**Tes.** `tests/Feature/Quotation/ClientLinkSafetyTest.php` (11 tes):
HTML halaman publik memuat `public.quotation.approve`/`pdf` tetapi tidak
`horizon.`/`finance.`/`master-data.`/`quotations.`/`dashboard`; halaman staf
tetap memuat `quotations.show`/`finance.*`; `appUrlIsLocal` untuk 7 URL;
prop bersama mengikuti `config('app.url')`. Lulus, bersama
`QuotationClientLinkTest`, `CompanyLetterTest`, `PwaTest` (58 tes).
`npx tsc --noEmit` bersih.

**Error app key (T2b) — temuan.**
- 8 baris log = 4 kejadian × 2 (request + render halaman error), semuanya
  `production.*`: **seluruh** `.env` tidak terbaca (APP_ENV jatuh ke
  default `production`, APP_KEY null), lalu `EncryptCookies` gagal.
- Bukan config cache (`bootstrap/cache/` hanya `packages.php` &
  `services.php`), dan `.env` tidak diubah sejak 24 Sep — jadi bukan
  "sedang ditulis".
- Semua stack trace (juga 72 error web lainnya) berakhir di
  `public/index.php` sebagai `{main}`, tanpa `resources/server.php` →
  request datang lewat **vhost Apache Laragon**, bukan `php artisan serve`.
- `D:\laragon\etc\apache2\mod_php.conf` memuat PHP sebagai **modul Apache
  thread-safe** (`php8apache2_4.dll`, mpm_winnt = banyak thread dalam satu
  proses) dan **masih menunjuk PHP 8.3.33**, bukan 8.4.
- Penyebab yang paling cocok (tidak bisa dibuktikan dengan reproduksi):
  phpdotenv memakai `putenv`/`getenv` yang tidak thread-safe. Pada dua
  request bersamaan, thread B melihat variabel sudah ada (di-`putenv`
  thread A) sehingga tidak menulisnya ke `$_SERVER`/`$_ENV`-nya sendiri;
  saat request A selesai, PHP mengembalikan environment proses → thread B
  kehilangan semua nilai `.env`. Pola acak, jarang, dan "semua variabel
  hilang sekaligus" sesuai dengan ini.
- Tindakan: `.env` tidak diubah. Pencegahan ditulis di `.claude/CLAUDE.md`
  "Local environment": pakai `php artisan serve` (atau PHP non-thread)
  untuk demo link klien, dan **jangan** `config:cache` di lokal (cache
  config membuat `php artisan test` mengabaikan `phpunit.xml`/SQLite dan
  memakai MySQL dev).

**Belum / terbuka.**
- **[Build]** tidak dicentang: `npm run build` tidak dijalankan (agen lain
  sedang build; hanya `tsc --noEmit`), dan salin link belum dicoba di
  Chrome pada `http://web-daiku-interior.test` maupun di HP — cadangan
  `execCommand` belum diverifikasi di browser sungguhan.
- Ganti PHP vhost Laragon ke 8.4 (Menu → PHP → Version) masih tugas user;
  apakah itu juga menghilangkan error app key belum diketahui (modul
  Apache tetap thread-safe).
- Halaman tamu lain (login, lupa password) masih menerima seluruh route
  Ziggy — di luar lingkup sub ini.

**Penutupan sprint (lead, 2026-10-07):** `php artisan test` penuh 1723
lulus, `npm run build` lulus, Pint lulus. Cek browser (Chrome headless,
Marketing): Salin Link di `http://web-daiku-interior.test` (bukan secure
context) → `execCommand('copy')` = true, toast "Link disalin.", tombol
"Tersalin"; peringatan "alamat lokal" tampil; kartu survey lead #37 SIAP;
notice RAB Proyek otomatis di lead #31. Uji dari HP sungguhan (K1) belum.
