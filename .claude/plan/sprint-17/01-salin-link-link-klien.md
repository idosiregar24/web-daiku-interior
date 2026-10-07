# Sprint 17 · 01 — Salin Link & Link Klien

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T1, T2 · Keputusan K1
> Status: **belum dikerjakan**

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
- [ ] **[UI]** `lib/clipboard.ts` + pakai di `ShareLinkPanel` &
      `RabHistoryCard`, toast sukses/gagal
- [ ] **[Backend]** Shared prop `appUrlIsLocal` (HandleInertiaRequests)
- [ ] **[UI]** Notice "alamat lokal" di `ShareLinkPanel`
- [ ] **[Infra]** Telusuri penyebab `MissingAppKeyException` + catat; perbaiki
      atau dokumentasikan pencegahannya
- [ ] **[Security]** Ziggy grup `public` untuk `penawaran/{token}`; pastikan
      tombol Setujui/PDF di halaman publik masih jalan
- [ ] **[Test]** Feature: halaman publik tidak memuat nama route internal
      (mis. `horizon.`, `finance.`); `appUrlIsLocal` benar untuk `.test`
      vs domain publik
- [ ] **[Build]** `npm run build` + `php artisan test` lulus; salin link dicoba
      di `http://web-daiku-interior.test` (Chrome) dan di HP lewat alamat
      yang bisa dijangkau (K1)
