# Sprint 13 · 07 — Favorit & Terakhir Dibuka

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 01, 03 · Keputusan: #11 · Default: D3

## Tujuan
Menu yang paling sering dipakai seseorang selalu ada di atas.

## File yang disentuh
- `resources/js/Layouts/AppLayout.tsx`, `resources/js/Components/shared/CommandMenu.tsx`
- Validasi `favorites` di Form Request Sub 01 (routeName harus ada di menu yang boleh dilihat user)

## Rancangan
- Ikon pin muncul saat hover pada item menu (dan di menu konteks HP:
  tekan lama → "Sematkan"). Disimpan di `nav_preferences.favorites`
  (urutan = urutan menyematkan, maks. 6).
- Grup **Favorit** di paling atas sidebar, hanya jika ada isinya. Item
  favorit yang tidak lagi boleh dilihat (role berubah) diabaikan diam-diam.
- **Terakhir dibuka**: 5 menu terakhir di `localStorage` (try/catch, aman
  bila kosong), tampil sebagai grup pertama `CommandMenu` saat kolom cari
  masih kosong.

## Checklist
- [ ] **[UI]** Sematkan / lepas sematan menu; grup Favorit di atas sidebar
- [ ] **[UI]** "Terakhir dibuka" di `CommandMenu`
- [ ] **[Test]** Favorit dengan routeName asing/tidak berhak ditolak; `npm run build`
