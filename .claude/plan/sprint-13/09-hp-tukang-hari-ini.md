# Sprint 13 · 09 — HP Tukang: Hari Ini, Tugas + Form Harian

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **selesai 2026-10-05** · Prasyarat: 08 · Keputusan: H2, H3, H4, H11 · Default: D7

## Tujuan
Tukang menyelesaikan urusan hariannya dari satu layar, 2–3 ketukan per tugas.

## File yang disentuh
- `app/Http/Controllers/Tasks/TodayController.php` (baru) + route `today.index` (`role:FIELD_STAFF`)
- `app/Services/ActionInboxService.php` (sumber tukang dari Sub 04)
- `app/Services/RoleRedirectService.php` (FIELD_STAFF → `today.index`)
- Konstanta jam & hari kerja penalti (mis. `config/daiku.php` `daily_form.penalty_at`, `daily_form.work_days`) dipakai `routes/console.php` **dan** layar Hari Ini
- `resources/js/Pages/Today/Index.tsx`, `resources/js/Components/modules/tasks/{TaskCard,TaskActionSheet}.tsx`
- `resources/js/Pages/Tasks/Index.tsx` (mode kartu untuk tukang)
- Test: `tests/Feature/Tasks/TodayTest.php`

## Rancangan
- **Hari Ini**: sapaan, `Notice` warning "n form harian belum diisi ·
  batas sebelum penalti 21:00" (hilang bila semua terisi / hari libur),
  lalu `TaskCard` untuk task aktif hari ini: judul, proyek, status
  (`StatusChip`), tanda form harian ✔/✖.
- **Hari Minggu** (H11 — hari tanpa penalti, ikut `days([1..6])` dari
  konstanta yang sama): tanpa peringatan form harian; isi layar = **daftar
  tugas minggu depan** (Senin–Sabtu berikutnya), dikelompokkan per hari.
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
- [x] **[Backend]** `today.index` + data dari `ActionInboxService`; tukang mendarat di Hari Ini
- [x] **[Backend]** Jam & hari kerja penalti jadi satu konstanta dipakai scheduler & UI; hari Minggu → daftar tugas minggu depan
- [x] **[UI]** `TaskCard` + `TaskActionSheet` (status tombol besar, simpan sekali)
- [x] **[UI]** Daftar Tugas mode kartu di HP
- [x] **[Test]** Simpan dari sheet: status + form harian tercatat sekali; judul/tenggat tidak bisa diubah (Policy); task milik tukang lain 403

## Catatan pelaksanaan (2026-10-05)
- **Tanpa endpoint baru**: `DailyTaskFormService::store()` sudah mengubah
  status task *dan* mencatat form harian dalam satu transaksi. Sheet
  memanggil `daily-forms.store` bila form hari ini belum ada (hari kerja,
  sebelum batas), selain itu `tasks.updateStatus`. Yang dikirim hanya
  status/kendala/catatan.
- Konstanta: `config/daiku.php` (`daily_form.penalty_at`, `reminder_at`,
  `work_days`) lewat `App\Support\DailyFormSchedule` — dipakai
  `routes/console.php` (DailyPenaltyJob & DailyFormReminderJob), batas
  submit di `DailyTaskFormService`, antrean "Form harian belum diisi" di
  `ActionInboxService` (kini menaut ke Hari Ini), `TodayController`, dan
  `TaskController` (tanda form di daftar Tugas).
- `today.index` (`/hari-ini`, `role:FIELD_STAFF`): task terbuka milik
  tukang + `has_form_today` (basis sama dengan `Task::awaitingDailyForm()`,
  jadi "n belum diisi" = angka Perlu Tindakan). Hari libur → `upcoming` =
  tugas Senin–Sabtu berikutnya, dikelompokkan per hari di layar.
  Tukang mendarat di Hari Ini; menu "Hari Ini" di grup Utama (FIELD_STAFF)
  dan tombol pertama navigasi bawah menuju ke sini.
- Komponen `Components/modules/tasks/{TaskCard,TaskActionSheet}.tsx`
  (`Sheet side="bottom"`, status 2×2 tombol besar, Simpan lebar penuh).
  Daftar Tugas: kartu + chip status + paging sederhana untuk tukang di
  bawah `lg`, tabel tetap di `lg` ke atas.
- Task milik tukang lain: `tasks.updateStatus` → 403 (TaskPolicy);
  `daily-forms.store` tetap ditolak dengan error validasi seperti
  sebelumnya (perilaku lama yang sudah dites, tidak diubah).
