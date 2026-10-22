# Sprint 29 · 13 — Aktivitas Harian Karyawan & Pemeriksaan HR

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Tiap karyawan bisa input daily activity (custom saja)" · Keputusan I1–I2
> Status: **selesai (kode & test)** — 2026-10-21 · uji browser per role & `npm run build` belum · Bergantung: Sub 01

## Keputusan user
> Dibaca "CEO, PM, HR". · "Perhitungan KPI juga nanti akan dicek oleh HR untuk
> melihat task yang diajukan di kemarin hari."

## Rancangan
- Tabel `daily_activities` (`user_id`, `date`, `description` TEXT,
  `project_id` nullable, timestamps, index `[user_id, date]`) +
  `daily_activity_reviews` (append-only: `daily_activity_id` / atau
  `user_id`+`date` untuk "tidak mengisi", `status` SESUAI / PERLU_KLARIFIKASI /
  TIDAK_MENGISI, `note`, `reviewed_by`, timestamps).
- **Menu "Aktivitas Harian"** (semua karyawan ber-akun selain FIELD_STAFF):
  hari ini di atas — kotak teks bebas (beberapa baris aktivitas), opsional
  pilih proyek; riwayat 14 hari di bawah; ubah/hapus hanya hari yang sama.
  Pengingat sore opsional (bukan penalti).
- **"Periksa Aktivitas"** (HR; CEO & PM baca): default **kemarin**, daftar
  semua karyawan `hrEligible()` — isi aktivitas atau "Tidak mengisi" —
  tombol **Sesuai** / **Perlu klarifikasi** (catatan) per orang + "Tandai
  semua yang terisi Sesuai". Klarifikasi → notifikasi karyawan.
- PM membaca aktivitas (untuk timnya / semua — sama dengan matriks baca HR).
- Antrean Perlu Tindakan HR: "Aktivitas kemarin belum diperiksa · n orang".

## Checklist
- [x] **[DB]** `daily_activities`, `daily_activity_reviews`
- [x] **[Backend]** `DailyActivityService` (tulis, ubah hari yang sama, review), route `role:` + Policy (pemilik tulis; HR review; CEO/PM/HR baca), antrean HR
- [x] **[UI]** Halaman Aktivitas Harian (karyawan), Periksa Aktivitas (HR), menu `NAV_GROUPS`
- [x] **[UX]** Simpan cepat tanpa pop-up (Sub 01), HP 390 px (kartu `mobileCard`, tombol 44 px, panel bawah), review sekaligus — uji browser belum
- [x] **[Test]** Pest: tulis & ubah hari sama, ubah kemarin ditolak, Tukang 403; review HR; RBAC baca (CEO/PM/HR ya, lainnya 403)
- [ ] **[Build]** `npm run build` (belum — `npx tsc --noEmit` bersih), Pint ✔

## Hasil (2026-10-21)
- **DB**: `daily_activities` (`user_id`, `date`, `description` TEXT, `project_id`
  nullable → nullOnDelete, index `[user_id, date]`) — **beberapa entri per hari**.
  `daily_activity_reviews` (append-only, tanpa `updated_at`): kunci **orang-hari**
  `user_id` + `date` untuk semua status (bukan `daily_activity_id` — satu hari bisa
  punya beberapa entri, dan "Tidak mengisi" tak punya entri); baris terbaru yang
  berlaku. Model menolak update/delete.
- **Route** (`routes/daily-activities.php`, grup `daily-activities.` di `web.php`,
  sengaja **di luar** `module:hr` supaya PM bisa membaca):
  `GET /aktivitas-harian` + `store/update/destroy` → `role:` semua kecuali
  FIELD_STAFF + `DailyActivityPolicy` (milik sendiri, hari yang sama);
  `GET /aktivitas-harian/periksa` (`daily-activities.review`) → `role:HR|CEO|PM`;
  `POST periksa` / `periksa/semua` → `role:HR`.
- **Aturan**: Sesuai hanya untuk hari yang terisi; Tidak mengisi hanya hari kerja
  kosong; Perlu klarifikasi wajib catatan → notifikasi `daily_activity_clarification`
  (P2, kategori SDM, membuka Aktivitas Harian). Hari ini/masa depan tidak bisa
  diperiksa. Hari libur: hanya yang mengisi yang tampil/diperiksa.
  "Tandai semua yang terisi Sesuai": terisi → Sesuai, kosong (hari kerja) → Tidak
  mengisi, yang sudah diperiksa tidak berubah. Semua diaudit `hr.activity_reviewed`.
- **Hari default & antrean**: `DailyFormSchedule::previousWorkDay()` (Senin →
  Sabtu). Antrean HR `activity-review` "Aktivitas kemarin belum diperiksa" (atau
  "Aktivitas Sabtu, 17 Oktober …"), routeName `daily-activities.review`.
  Daftar: `Employee::activityReviewable($date)` (hrEligible, aktif, akun aktif,
  sudah bergabung).
- **Menu**: "Aktivitas Harian" di grup Utama (semua kecuali Tukang); "Periksa
  Aktivitas" di grup SDM (CEO, HR, PM — PM melihat grup SDM berisi menu ini saja).
- **Untuk Sub 14 (KPI)**: % hari kerja mengisi = hari kerja dengan ≥1
  `daily_activities` / hari kerja; % sesuai = review terbaru SESUAI / hari terisi
  yang diperiksa. Akun tanpa baris karyawan (mis. SUPERADMIN) boleh menulis tetapi
  tidak diperiksa.
- **Belum**: pengingat sore (opsional), seed data demo, uji browser 390 px.
