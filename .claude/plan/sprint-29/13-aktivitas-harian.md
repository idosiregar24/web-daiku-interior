# Sprint 29 · 13 — Aktivitas Harian Karyawan & Pemeriksaan HR

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Tiap karyawan bisa input daily activity (custom saja)" · Keputusan I1–I2
> Status: **belum dikerjakan** · Bergantung: Sub 01

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
- [ ] **[DB]** `daily_activities`, `daily_activity_reviews`
- [ ] **[Backend]** `DailyActivityService` (tulis, ubah hari yang sama, review), route `role:` + Policy (pemilik tulis; HR review; CEO/PM/HR baca), antrean HR
- [ ] **[UI]** Halaman Aktivitas Harian (karyawan), Periksa Aktivitas (HR), menu `NAV_GROUPS`
- [ ] **[UX]** Simpan cepat tanpa pop-up (Sub 01), HP 390 px, review sekaligus
- [ ] **[Test]** Pest: tulis & ubah hari sama, ubah kemarin ditolak, Tukang 403; review HR; RBAC baca (CEO/PM/HR ya, lainnya 403)
- [ ] **[Build]** `npm run build`, Pint
