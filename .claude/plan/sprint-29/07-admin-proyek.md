# Sprint 29 · 07 — Role Admin Proyek

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Ronde 4 "seorang admin yang mengurus semua proses khusus di bagian projek" · Keputusan H1–H4 · Q32
> Status: **kode selesai 2026-10-21** — pengecekan % (draf) & upah per item menyusul Sub 08/09; tersisa cek HP 390 px · Bergantung: Sub 06

## Keputusan user
> "Saya mau ada seorang admin yang mengurus semua proses khusus di bagian
> projek saja, masalah penginputan dll." · (ronde 3) upah & daily target hanya
> Pengawas, "kalau mau diubah berikan hak akses saja".

## Rancangan
- Role non-PRD **`ADMIN_PROYEK`** (label "Admin Proyek") di `RoleSeeder`,
  `User::roleLabel`, union `Role` TypeScript, `ROLE_GROUP_ORDER`, akun demo
  `adminproyek` / `adminproyek@daikuinterior.com`.
- **Melihat**: semua proyek (`Project::visibleTo()`), semua tab Detail Proyek
  kecuali termin/invoice klien & Anggaran PM (props tidak dikirim — aturan
  Sprint 12); task terlihat penuh (`taskVisibility = all`).
- **Menginput** (helper `Project::isAdministeredBy($user)` dipakai di Policy):
  TM (jadwal, notulen, peserta, timeline, Tanggal Selesai), bahan & ajukan ke
  Logistik, upah per item & Kepala Tim (Sub 08), daily target (buat/ubah/
  geser/storeMany), status & centang/Setujui/Kembalikan task, pengecekan %
  (sebagai **draf** — pengajuan uangnya tetap dikirim Pengawas, Sub 09),
  temuan, catatan progres.
- **Tidak boleh** (403): TM Selesai, setujui DP / pencairan, Berangkat ke
  Lokasi, Lolos QA, Buka Proyek, RAB, pembayaran, Anggaran.
- Audit: setiap aksi atas nama Admin Proyek (aktor tercatat).
- Menu: Proyek, Tugas, Perlu Tindakan (antrean input: TM belum lengkap,
  target belum dibuat, pengecekan belum diisi) — tanpa grup Keuangan.

## Checklist
- [x] **[Backend]** Role + seeder + akun demo; `isAdministeredBy()`; route `role:` ditambah `ADMIN_PROYEK` hanya pada route input; Policy per aksi
- [x] **[Backend]** Props keuangan/anggaran tidak dikirim ke Admin Proyek
- [x] **[UI]** Label role, menu (`NAV_GROUPS`, `ROLE_GROUP_ORDER`), tombol keputusan tersembunyi (server tetap 403)
- [ ] **[UX]** Halaman awal Admin Proyek = Perlu Tindakan / daftar proyek; HP 390 px
- [x] **[Test]** Pest RBAC: tiap route input 200/302, tiap route keputusan 403, props keuangan kosong
- [x] **[Dok]** `CLAUDE.md` golden rule baru (Admin Proyek), `security-standards.md` §4
- [x] **[Build]** `npm run build`, Pint
