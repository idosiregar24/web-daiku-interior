# Sprint 13 · 08 — HP Tukang: Navigasi Bawah & Lainnya

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 03 · Keputusan: H1, H9, H10 · Default: D8

## Tujuan
Di HP, tukang tidak lagi melihat sidebar — cukup 4 tombol di bawah.

## File yang disentuh
- `resources/js/Layouts/AppLayout.tsx` (mode FIELD_STAFF < `lg`)
- `resources/js/Components/shared/BottomNav.tsx` (baru)
- `resources/js/Pages/More/Index.tsx` (baru, "Lainnya") + route `more.index` (`role:FIELD_STAFF`)
- `app/Http/Controllers/MoreController.php` (total penalti bulan ini — pakai query yang sama dengan halaman Penalti)
- Label "Task" → "Tugas" di halaman tukang (`Tasks/Index`, `DailyForm/Index`, judul menu FIELD_STAFF)
- Test: `tests/Feature/MorePageTest.php`

## Rancangan
- FIELD_STAFF di bawah `lg`: sembunyikan tombol hamburger & breadcrumb
  panjang; tampilkan `BottomNav` tetap di bawah (tinggi ±64px +
  `env(safe-area-inset-bottom)`), konten diberi padding bawah.
  Tombol: **Hari Ini** (sementara `tasks.index`; jadi layar Hari Ini di
  Sub 09) · **Tugas** · **Lembur** · **Lainnya**. Tombol aktif = ikon +
  label tebal, area sentuh ≥ 48px.
- **Lainnya**: kartu "Penalti bulan ini: Rp X" (tautan ke Penalti),
  daftar Pengajuan Barang, Proyek, Profil, Keluar.
- Di `lg` ke atas tukang tetap mendapat sidebar biasa.

## Checklist
- [ ] **[UI]** `BottomNav` untuk FIELD_STAFF di bawah `lg`; hamburger disembunyikan
- [ ] **[UI]** Halaman Lainnya + total penalti bulan ini
- [ ] **[UI]** "Tugas" menggantikan "Task" di layar tukang
- [ ] **[Test]** `more.index`: FIELD_STAFF 200, role lain 403; total penalti hanya miliknya
