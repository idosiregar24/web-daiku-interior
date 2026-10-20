# Sprint 29 · 10 — Progres Proyek Berbobot (rumus Excel)

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Keputusan B7 · Q22 = "Iya" · Rumus §2 (File C)
> Status: **belum dikerjakan** · Bergantung: Sub 09

## Rancangan
- `Project::actualProgress()` (satu tempat — golden rule #17): proyek dengan
  item berupah → **Σ (bobot × progres item) ÷ 100**; proyek tanpa item kerja
  (lama) → actual activity seperti sekarang.
- `Project::withActivityCounts()` / daftar proyek & dashboard: hitung dari
  agregat `work_item_progress` (satu query, tanpa N+1).
- Ikut otomatis: daftar proyek, Ringkasan, stepper, syarat **70 % Berangkat
  ke Lokasi** (+ notifikasi 70 % sekali), dashboard PM/CEO/divisi, H-30 %.
- Actual activity daily target tetap di tab **Activity Target** & penalti —
  diberi judul "Actual activity (daily target)" supaya tidak tertukar dengan
  "Progres pekerjaan".
- Tooltip progres: "Dari pengecekan Pengawas per item RAB, ditimbang nilai RAB".

## Checklist
- [ ] **[Backend]** `actualProgress()` + agregat daftar; 70 % memakai nilai baru
- [ ] **[UI]** Label & tooltip progres; Activity Target diberi judul jelas
- [ ] **[UX]** Angka progres sama di semua tempat (daftar, detail, dashboard)
- [ ] **[Test]** Pest: angka File C (67,70 % dari data simulasi); proyek lama tetap actual activity; 70 % terkunci/terbuka sesuai progres berbobot
- [ ] **[Dok]** `CLAUDE.md` golden rule #17
- [ ] **[Build]** `npm run build`, Pint
