# Sprint 25 — Tab "Activity Target": Daily Target vs Actual per Proyek

> Status: **kode selesai 2026-10-19** (Sub 01–03; Sub 04 dilewati, Q3 = tidak).
> 2198 test lulus (3 baru di `tests/Feature/Projects/ActivityTargetTest.php`),
> `npm run build` & Pint bersih. Angka dicek pada data demo (proyek #1: 6/9
> target → 66% = `actualProgress()`). **Belum:** cek tampilan di browser,
> karena login demo `ceo` ditolak di DB lokal (password sudah diganti).
> Q1–Q6 tidak dijawab user, jadi dipakai usulan Claude (§5).
> Sumber: permintaan user 2026-10-19 + baris terbawah papan tulis Sprint 24
> ([`sprint-24/papan-tulis-2026-10-13.jpg`](sprint-24/papan-tulis-2026-10-13.jpg)):
> **"target – Aktivity / Actual"**. Di luar PRD. Lanjutan Sprint 24 D6/D7/D11.
>
> Dasar yang sudah ada (2026-10-19, belum di-commit): tab "Progress" sudah
> menjadi **Catatan Progress**: catatan PM tanpa persen. Progres proyek di
> semua dashboard = actual activity (`Project::actualProgress()` /
> `Project::withActivityCounts()`).

## 1. Permintaan

> "Ganti → (tab Progress) menjadi **Activity Target** → tambahkan tabel di tab
> ini yang membandingkan kolom **Daily Target** dengan **Actual** → di sini
> juga kelihatan berapa persen proyeknya berdasarkan task yang sudah selesai."

Bacaan Claude: "Plan" dalam permintaan = tab **Progress** (sekarang "Catatan
Progress") di Detail Proyek. Lihat Q1.

## 2. Yang sudah ada (dipakai ulang, tidak diubah)

| Bagian | Di mana | Isi |
|---|---|---|
| Daily target | `tasks` (`planned_date`, `due_date`, `status`, `completed_at`) | Hari target = `planned_date` (tanggal awal, tidak ikut bergulir) |
| Actual | `tasks.status = DONE` + `completed_at` (WIB) | Ditulis saat tukang mencentang (`TaskService::recordActivity`) |
| Bergulir | `task_schedule_changes` kind `ROLLOVER` | Target yang belum selesai pindah ke hari kerja berikutnya |
| Progres proyek | `Project::actualProgress()` | DONE ÷ semua task, bobot sama (E5) |
| Target vs Actual **per tukang per minggu** | Tab Eksekusi, `TargetVsActualCard` ← `ProductionService::targetVsActual()` | Operasional Pengawas: sel "selesai/target", ↻ bergulir, Tukar Hari. **Tetap di Eksekusi.** |

Yang belum ada: tampilan **tingkat proyek dari awal sampai sekarang**. Belum
ada tempat yang menampilkan jumlah target per hari dibanding yang selesai,
beserta persen kumulatif yang naik sampai angka progres proyek hari ini.

## 3. Keputusan (usulan Claude, dikunci setelah §5 dijawab)

| # | Keputusan |
|---|---|
| A1 | **Nama tab** "Catatan Progress" → **"Activity Target"** (ikon tetap `Activity`). Key tab tetap `progress`, jadi `?tab=progress`, `ProjectTab` dan breadcrumb tidak berubah. Hanya labelnya yang diganti. |
| A2 | **Urutan isi tab:** ① ringkasan angka → ② tabel **Daily Target vs Actual** → ③ **Catatan Progress** PM (yang sekarang, pindah ke bawah sebagai section). |
| A3 | **Ringkasan** (`StatCard`): **Progress Proyek** = actual % (sama persis dengan Ringkasan/daftar proyek) · **Target s.d. hari ini** = % target yang dijadwalkan sampai hari ini · **Selisih** = actual − target (poin %, `success-ink` bila ≥ 0, `error-ink` bila < 0) · **Target selesai** "n / total" · **Bergulir** = jumlah kejadian ↻. |
| A4 | **Tabel**: satu baris per **hari kerja** (`DailyFormSchedule::isWorkDay`), dari target pertama sampai target terakhir (atau hari ini bila lebih lambat), dikelompokkan **per minggu** dengan baris subtotal. Minggu berjalan terbuka, minggu lama terlipat. Kolom: **Tanggal · Daily Target · Actual · Selisih · ↻ Bergulir · Target kumulatif % · Actual kumulatif %**. Hari yang belum lewat: Actual "—". |
| A5 | **Definisi = satu sumber.** Hari target = `planned_date ?? due_date`; hari actual = `completed_at` (WIB) dari task DONE; bergulir = `ROLLOVER.from_date`. Persis sama dengan `targetVsActual()`. Logikanya diangkat ke satu helper privat di `ProductionService` yang dipakai keduanya. Penyebut kumulatif = **semua task proyek saat ini**, jadi Actual kumulatif hari ini **=** `actualProgress()` (diuji). Target yang selesai lebih cepat atau lebih lambat dihitung di hari selesainya, sehingga Actual sehari boleh > Daily Target. |
| A6 | **Data**: `ProductionService::activityTarget(Project)` (1 query task + 1 query rollover) → prop `activityTarget` dari `ProjectController::show`, hanya untuk yang boleh melihat tab (`canViewProgressLogs`). Isinya **hanya angka**: tanpa judul task, tanpa nama tukang. |
| A7 | **Akses** sama dengan tab sekarang: CEO, Marketing, Desainer, PM, Asisten PM (proyeknya), QA, Finance, SUPERADMIN; Tukang tidak. Karena hanya angka, aman untuk PRD §4.6 (QA tidak melihat detail task). Rincian per tukang tetap hanya di Eksekusi. |
| A8 | **Eksekusi ↔ Activity Target**: kartu Target vs Actual di Eksekusi mendapat tautan "Lihat seluruh proyek →" ke tab ini. Tidak ada tabel ganda dengan isi yang sama. |
| A9 | **HP**: tabel memakai pola `TableCard`: `<ul className="md:hidden">` berisi kartu per hari dan `<table className="hidden md:table">`. Header `TABLE_HEAD_CLASS`, warna hanya dari token. |
| A10 | Proyek tanpa daily target → `EmptyState` "Belum ada daily target — dibuat Pengawas setelah TM." Ringkasan tetap tampil (0%). |

## 4. Di luar cakupan

- Bobot per task (misalnya menurut upah). Tetap bobot sama (E5 Sprint 24) kecuali Q6 dijawab lain.
- Mengubah aturan penalti, bergulir, atau 70% Berangkat ke Lokasi. Angkanya tetap dari `actualProgress()`.
- Grafik kurva S, kecuali Q3 = ya (lihat Sub 04).

## 5. Pertanyaan untuk user

Tidak dijawab; user meminta langsung dikerjakan (2026-10-19), jadi semua
memakai kolom kanan. Ubah bila user mengoreksi.

| # | Pertanyaan | Usulan bila tidak dijawab |
|---|---|---|
| Q1 | "Plan" yang diganti = tab **Progress / Catatan Progress** di Detail Proyek? | Ya |
| Q2 | Baris tabel **per hari** (dikelompokkan per minggu) atau cukup **per minggu**? | Per hari, dikelompokkan per minggu (A4) |
| Q3 | Perlu **grafik kurva S** (garis Target kumulatif vs Actual kumulatif) di atas tabel? | Tidak sekarang; Sub 04 opsional |
| Q4 | Catatan Progress PM tetap ada di tab ini (di bawah tabel), atau dihapus? | Tetap, di bawah (A2) |
| Q5 | Catatan Progress masih ditolak selama ada timeline menunggu QA (aturan PRD lama). Aturan ini dihapus? | Dihapus: persen manual sudah tidak ada, jadi aturan itu hanya menghalangi catatan |
| Q6 | Target kumulatif dihitung terhadap **semua target saat ini** (bisa bergeser bila Pengawas menambah target)? | Ya, supaya Actual kumulatif hari ini = progres proyek |

## 6. Sub-plan

Satu perintah = satu sub ("Kerjakan Sprint 25 Sub 1").

### Sub 01 — Data Activity Target (backend)
- [x] Helper privat bersama di `ProductionService` (hari target / actual / bergulir); `targetVsActual()` memakainya tanpa mengubah keluarannya
- [x] `ProductionService::activityTarget(Project)`: `summary` (A3) + `weeks[] → days[]` (A4), kumulatif dalam persen bulat ke bawah
- [x] Prop `activityTarget` di `ProjectController::show` (hanya bila `canViewProgressLogs`)
- [x] Tipe `ActivityTarget` di `resources/js/types/index.d.ts`
- [x] Test Pest: definisi (target dari `planned_date` meski sudah bergulir, actual di hari `completed_at` WIB, bergulir terhitung); Actual kumulatif terakhir = `actualProgress()`; hari libur tidak muncul; proyek tanpa task → kosong; Tukang tidak menerima prop; respons tanpa judul task / nama tukang
- [x] Bila Q5 = hapus: buang blokir QA_WAITING di `ProgressLogService` + sesuaikan `ProgressLogTest`

### Sub 02 — Tab Activity Target (frontend)
- [x] Label tab + crumb "Activity Target" (`Show.tsx`, key tetap `progress`)
- [x] Komponen `Components/modules/projects/ActivityTargetPanel.tsx`: ringkasan `StatCard` + tabel per minggu (lipat/buka, subtotal) + kartu HP
- [x] Catatan Progress (`ProgressTimeline` + tombol "Tambah Catatan") jadi `SectionCard` di bawah tabel
- [x] Tautan "Lihat seluruh proyek →" di `TargetVsActualCard` (Eksekusi) → `setTab('progress')`
- [x] `npm run build` bersih

### Sub 03 — Uji & dokumen
- [x] Suite Pest penuh, Pint, build
- [ ] Cek di browser (proyek demo fase PRODUKSI): angka ringkasan = kartu Progress di Ringkasan & kolom Actual Activity di daftar proyek; tampilan HP 390px. *Belum: login demo `ceo` ditolak di DB lokal. Angkanya sudah dicek lewat skrip (proyek #1 = 66%).*
- [x] `CLAUDE.md` aturan 17: satu kalimat tab Activity Target; `simulasi-eksekusi-proyek.md` Langkah 12/15 menyebut tab ini
- [x] README: status Sprint 25

### Sub 04 — (opsional, bila Q3 = ya) Grafik kurva S
- [ ] Muat skill `dataviz`; Recharts di-`lazy()`, dua garis kumulatif (Target / Actual) + garis hari ini, warna dari token
- [ ] Data dari prop yang sama (tanpa query baru)
