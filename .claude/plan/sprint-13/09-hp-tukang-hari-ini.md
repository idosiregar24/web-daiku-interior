# Sprint 13 · 09 — HP Tukang: Hari Ini, Tugas + Form Harian

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 08 · Keputusan: H2, H3, H4 · Default: D7

## Tujuan
Tukang menyelesaikan urusan hariannya dari satu layar, 2–3 ketukan per tugas.

## File yang disentuh
- `app/Http/Controllers/Tasks/TodayController.php` (baru) + route `today.index` (`role:FIELD_STAFF`)
- `app/Services/ActionInboxService.php` (sumber tukang dari Sub 04)
- `app/Services/RoleRedirectService.php` (FIELD_STAFF → `today.index`)
- Konstanta jam penalti (mis. `config/daiku.php` `daily_form.penalty_at`) dipakai `routes/console.php` **dan** layar Hari Ini
- `resources/js/Pages/Today/Index.tsx`, `resources/js/Components/modules/tasks/{TaskCard,TaskActionSheet}.tsx`
- `resources/js/Pages/Tasks/Index.tsx` (mode kartu untuk tukang)
- Test: `tests/Feature/Tasks/TodayTest.php`

## Rancangan
- **Hari Ini**: sapaan, `Notice` warning "n form harian belum diisi ·
  batas sebelum penalti 21:00" (hilang bila semua terisi / hari libur),
  lalu `TaskCard` untuk task aktif hari ini: judul, proyek, status
  (`StatusChip`), tanda form harian ✔/✖.
- **Ketuk kartu → `TaskActionSheet`** (`Sheet side="bottom"`):
  1. Status sebagai tombol besar bergrid (hanya transisi yang diizinkan
     `TaskPolicy`/`TaskService`), 2. kendala & catatan, 3. tombol
     **Simpan** lebar penuh. Simpan = panggil endpoint ubah status yang
     ada, lalu (jika form harian hari ini belum ada) endpoint kirim form
     harian yang ada — atau satu endpoint gabungan tipis yang memanggil
     kedua service dalam satu transaksi. Field yang tidak boleh diubah
     tukang (judul/deskripsi/tenggat) tidak dikirim sama sekali.
- **Tugas** (tab bawah): semua task tukang sebagai kartu (bukan
  `DataTable`) di bawah `lg`, filter status sebagai chip.
- Form Harian lama tetap ada untuk CEO/PM (rekap), tukang tidak perlu membukanya lagi.

## Checklist
- [ ] **[Backend]** `today.index` + data dari `ActionInboxService`; tukang mendarat di Hari Ini
- [ ] **[Backend]** Jam penalti jadi satu konstanta dipakai scheduler & UI
- [ ] **[UI]** `TaskCard` + `TaskActionSheet` (status tombol besar, simpan sekali)
- [ ] **[UI]** Daftar Tugas mode kartu di HP
- [ ] **[Test]** Simpan dari sheet: status + form harian tercatat sekali; judul/tenggat tidak bisa diubah (Policy); task milik tukang lain 403
