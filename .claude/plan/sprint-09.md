# Sprint 9 — Gap PRD lanjutan (audit 2026-09-30, di luar CSV)

> Sumber: audit gap 2026-09-30 (PRD vs kode, setelah demo pengguna). Task di
> file ini tidak ada di `Daiku-Task-Schedule.csv`. Aturan kerja sama dengan
> Sprint 8: `.claude/CLAUDE.md` + `.claude/rules/*` (thin controller →
> Service, Form Request, `role:` + Policy sesuai PRD §7.1, audit append-only,
> tanpa route `destroy` untuk data finance, UI Bahasa Indonesia, token Daiku,
> `@/Components` huruf besar).

**Ringkasan status:** 44 selesai · 0 sebagian · 1 belum (dari 45 task). Sisa: verifikasi migrasi + seed di MySQL (MySQL Laragon sedang mati). Suite: **1003 test** (sebelum sprint: 600), `npm run build` dan `pint --test` bersih.

## Keputusan (dikunci 2026-09-30)

1. **Edit proyek:** CEO semua proyek, PM hanya proyek miliknya (`pm_id`).
   Ganti PM hanya oleh CEO (PRD §4.4 "PM di-assign oleh CEO"). Status manual
   ACTIVE ↔ ON_HOLD, ACTIVE/ON_HOLD → CANCELLED (alasan wajib, terminal);
   COMPLETED hanya dari alur QA. Nilai kontrak hanya bisa diubah selama
   belum ada pembayaran termin; bila berubah, nominal termin dihitung ulang
   dari persentasenya. Proyek non-ACTIVE tidak kena penalti/overdue otomatis.
2. **Edit/hapus task:** PM (tidak dibatasi per proyek, sama seperti membuat
   task). Task DONE yang upahnya sudah dibayar terkunci penuh. Hapus hanya
   bila task belum punya riwayat (form harian, lembur, penalti, pembayaran).
   Status OVER dipulihkan ke status sebelumnya (kolom baru
   `tasks.pre_overdue_status`) bila deadline dimundurkan.
3. **Quotation:** klien menolak (CEO/Marketing, dari SENT_TO_CLIENT) →
   DRAFT. Setiap penolakan (CEO/PM/klien) menyimpan snapshot versi di
   `quotation_revisions` (append-only) lalu `version` naik. Masa berlaku =
   tanggal kirim (PM approve) + 14 hari; kedaluwarsa hanya peringatan.
4. **Status desain:** tahap setelah ACC tidak bisa dipilih manual tanpa
   Client ACC. Status maju otomatis mengikuti quotation/deal/proyek (hanya
   maju, tidak menimpa HOLD_CLIENT/REVISI_CLIENT/REJECT_PRODUKSI).
5. **Saldo rekening dihitung, tidak disimpan:** `bank_accounts.balance`
   di-rename jadi `opening_balance` (saldo awal); saldo berjalan = saldo
   awal + masuk − keluar. **Pindah Dana** = 2 kaki (keluar dari rekening
   asal, masuk ke rekening tujuan), dikecualikan dari total arus kas
   perusahaan. PINDAH_DANA dan GAJI_KARYAWAN menjadi kategori sistem (tidak
   bisa dicatat lewat "Catat Transaksi" manual).
6. **Dashboard divisi:** Designer → KPI Desain (+ omset/piutang desain),
   Estimator → Dashboard Quotation, PM → Monitor Proyek (overdue), QA →
   Dashboard QA. Halaman landing per role ikut berubah.
7. **Gaji karyawan tetap:** daftar karyawan terpisah (boleh ditautkan ke
   akun user, tidak wajib), satu pembayaran per karyawan per bulan. CEO
   baca, Finance kelola, role lain (termasuk PM) tidak boleh melihat.
   Transaksi gaji **tidak** memakai `reference_id` — penanda "upah tukang
   sudah dibayar" mencari `kategori=GAJI_KARYAWAN AND reference_id=task.id`.
8. **Cicilan aset:** rencana cicilan diisi Logistik di form aset;
   pembayaran dicatat Finance (PENGELUARAN kategori ANGSURAN + rekening),
   ledger append-only.
9. **Infrastruktur (sebatas kode; belum ada Docker/VPS di mesin dev):**
   backup harian terenkripsi + retensi 30 hari, konfigurasi HTTPS produksi,
   deploy CI via SSH, laporan coverage di CI (belum jadi gerbang), link
   "Lupa password?" hanya tampil bila mailer sungguhan terkonfigurasi.
10. **Penagihan penalti = pembayaran manual** (diputuskan user 2026-09-30):
    tukang membayar tunai/transfer, Finance mencatat pelunasan penalti per
    tukang + rekening (PEMASUKAN kategori PENALTY_COLLECT, kategori sistem).
    Upah **tidak** dipotong. Penalti yang dibayar ditandai
    (`penalties.is_deducted` + waktu/transaksi pelunasan). Saldo Dana Family
    Gathering yang bisa dipakai = penalti yang sudah dibayar − penggunaan
    dana; penggunaan dana keluar dari rekening (transaksi PENGELUARAN) agar
    saldo rekening tetap benar.

## Peta eksekusi

```
Fase 0  (utama)      ── kunci keputusan, file ini
Fase 1  (PARALEL)    ── SA-1 Eksekusi · SA-2 Presales · SA-3 Finance rekening
                        SA-4 Dashboard divisi · SA-5 Infrastruktur · SA-6 Cicilan aset & gaji
Fase 2  (utama)      ── integrasi: NAV_GROUPS, tombol dashboard, DemoDataSeeder,
                        penagihan penalti (setelah keputusan)
Fase 3  (utama + review) ── test penuh, build, pint, seed di DB scratch, review keamanan, docs
```

### Aturan paralel

- Subagent hanya menulis file miliknya. File bersama `routes/web.php`,
  `resources/js/types/index.d.ts`, `Components/shared/StatusChip.tsx`,
  `app/Models/User.php`, `app/Providers/AppServiceProvider.php` hanya boleh
  disentuh dengan `Edit` kecil di bagian modulnya sendiri (bila gagal karena
  file berubah: baca ulang, ulangi — jangan menimpa).
- Route ditambahkan bersamaan dengan / setelah method controller-nya ada:
  Ziggy (`@routes`) me-refleksi setiap controller, jadi route ke class atau
  method yang belum ada memecahkan semua test halaman.
- Hanya agen utama: `AppLayout.tsx` (NAV_GROUPS), `DemoDataSeeder`/
  `DatabaseSeeder`/`ProductionSeeder` (kecuali rename kolom `balance` oleh
  SA-3), `.claude/`, `composer.json`/`package.json`.
- Tidak ada `migrate` ke DB lokal `daiku_interior` (data demo user); migrasi
  diuji di DB scratch. Subagent tidak menjalankan `npm run build`, cukup
  `npx tsc --noEmit`. Pint hanya pada file sendiri.

---

## Fase 1 — Pembangunan (PARALEL)

### SA-1 · Eksekusi: edit proyek & task
- [x] **[Projects]** Edit proyek: route `projects.update`, `ProjectPolicy::update`, service + audit, notifikasi PM baru, dialog di detail proyek
- [x] **[Projects]** Efek status non-ACTIVE: penalti/overdue dilewati; task/milestone baru ditolak untuk CANCELLED/COMPLETED
- [x] **[Tasks]** Edit task (`tasks.update`) + pemulihan status OVER
- [x] **[Tasks]** Hapus task (`tasks.destroy`) dengan penjaga riwayat
- [x] **[Tasks]** Filter task hari ini / minggu ini / terlambat (PRD §4.5)
- [x] **[Test]** RBAC (termasuk tukang 403 saat edit task) + aturan bisnis

### SA-2 · Presales: quotation & desain
- [x] **[Quotation]** Klien menolak penawaran → DRAFT (`quotations.clientReject`)
- [x] **[Quotation]** Riwayat revisi (`quotation_revisions`) + nomor versi
- [x] **[Quotation]** Masa berlaku 14 hari + peringatan kedaluwarsa
- [x] **[Design]** Sub-staff desain (pivot `design_staff`)
- [x] **[Design]** Integritas & sinkronisasi otomatis status desain
- [x] **[Test]** Quotation, Design, PresalesIntegrationTest

### SA-3 · Finance: rekening, pindah dana, export
- [x] **[Finance]** Saldo rekening otomatis (`opening_balance` + transaksi)
- [x] **[Finance]** Arus kas per rekening di dashboard Finance
- [x] **[Finance]** Pindah Dana 2 kaki + kategori sistem (PINDAH_DANA, GAJI_KARYAWAN)
- [x] **[Finance]** Filter rekening + export Excel multi-sheet (per bulan/proyek/rekening)
- [x] **[Finance]** Kalender termin: dialog pembayaran parsial
- [x] **[Overtime]** Notifikasi PM saat Finance menolak lembur (PRD §6.6)
- [x] **[Test]** Finance, Master Data, Overtime

### SA-4 · Dashboard per divisi
- [x] **[Design]** KPI Desain per PIC + omset/piutang desain per bulan
- [x] **[Quotation]** Dashboard Estimator
- [x] **[Projects]** Monitor Proyek PM (overdue monitor, PRD §4.4)
- [x] **[QA]** Dashboard QA (tanpa detail task, PRD §4.6)
- [x] **[Setup]** Landing per role (`RoleRedirectService`)
- [x] **[Test]** RBAC + scoping PM + isi dashboard

### SA-5 · Infrastruktur
- [x] **[Setup]** `db:backup` terenkripsi + retensi 30 hari + jadwal 00:00 WIB
- [x] **[Setup]** Konfigurasi HTTPS produksi (nginx + compose override)
- [x] **[Setup]** Deploy CI via SSH + laporan coverage
- [x] **[Auth]** "Lupa password?" hanya bila mailer terkonfigurasi
- [x] **[Docs]** README (backup/restore, HTTPS, deploy) + `.env.example`
- [x] **[Test]** Backup, jadwal, link reset

### SA-6 · Finance: cicilan aset & gaji karyawan
- [x] **[Logistics]** Rencana cicilan di form aset + aturannya
- [x] **[Finance]** Ledger pembayaran cicilan + halaman Cicilan Aset
- [x] **[Finance]** Master karyawan + penggajian bulanan
- [x] **[Test]** Cicilan aset, penggajian (termasuk aturan `reference_id`)

### SA-7 · Finance: penagihan penalti (setelah keputusan user)
- [x] **[Finance]** Pencatatan pembayaran penalti per tukang (PENALTY_COLLECT + rekening, penalti ditandai lunas)
- [x] **[Finance]** Dana Family Gathering: saldo tersedia dari penalti yang sudah dibayar; penggunaan dana lewat rekening
- [x] **[Finance]** Halaman Penalti & Dana: status Belum dibayar/Lunas, ringkasan tertagih vs belum
- [x] **[Test]** RBAC + aturan (tidak dobel bayar, saldo dana, rekening)

## Fase 2 — Integrasi (utama)
- [x] **[Setup]** NAV_GROUPS (Cicilan Aset, Penggajian) + tombol ke dashboard divisi di halaman index modul
- [x] **[Setup]** DemoDataSeeder: sub-staff, riwayat revisi, pindah dana, karyawan + gaji, aset bercicilan

## Fase 3 — Verifikasi & dokumentasi
- [x] **[Test]** `php artisan test` penuh + `npm run build` + `pint --test`
- [ ] **[Test]** `migrate:fresh --seed` di DB scratch; checklist skenario demo tetap valid
- [x] **[Security]** Review keamanan diff (subagent read-only)
  - (H) **PM bisa membaca gaji karyawan** lewat daftar Transaksi & export Excel (baris GAJI_KARYAWAN dari penggajian) → baris gaji kini disaring untuk selain CEO/FINANCE (`hide_salary_payments`, diset server, tidak bisa dimatikan dari query string).
  - (L/M) **Formula injection di export Excel** (`=HYPERLINK(...)` di deskripsi jadi formula hidup) → `App\Exports\SafeValueBinder` global di `config/excel.php`.
  - (L) `FundTransfer` kini menolak update/delete di level model; `deploy.sh` menolak `APP_DEBUG=true` dan `BACKUP_ENCRYPTION_KEY` kosong.
  - Tidak diubah (dicatat): dashboard Finance mengirim nomor rekening lengkap ke PM (PM memang punya akses baca Finance – Transaction).
  - Regresi: `tests/Feature/Security/Sprint9HardeningTest.php`. Suite: **1003 test** lulus.
- [x] **[Docs]** `README.md` plan (tabel sprint, keputusan) + CLAUDE.md bila perlu

## Catatan pelaksanaan (deviasi & temuan)

- **Edit proyek/task (SA-1):** alasan ON_HOLD/CANCELLED tidak punya kolom —
  dibaca ulang dari audit `project.updated`. `markDone` milestone juga
  diblokir di proyek tertutup (kalau tidak, proyek batal masih bisa minta QA).
  Edit/hapus milestone di proyek tertutup **belum** diblokir di server. Update
  status task tetap boleh di proyek tertutup (agar upah task DONE bisa dibayar).
  Validasi `StoreTaskRequest` ikut diperketat (milestone harus satu proyek,
  assignee harus FIELD_STAFF aktif). 🐞 Bug lama: edit milestone dari UI selalu
  gagal (form tidak mengirim `status`) — diperbaiki.
- **Quotation (SA-2):** kolom tambahan `quotation_approvals.version` agar
  riwayat approval tidak ambigu antar versi. Keputusan CEO/PM/klien kini satu
  jalur `recordDecision` dengan `lockForUpdate` (klik ganda tidak mencatat dua
  keputusan). Pembuat keputusan tidak lagi dinotifikasi atas keputusannya
  sendiri. Persetujuan klien (Konfirmasi Deal) masih hanya tercatat di audit,
  bukan di `quotation_approvals`.
- **Rekening (SA-3):** dua kaki Pindah Dana sama-sama `reference_id =
  fund_transfers.id`. Transfer melebihi saldo rekening asal **tidak** diblokir
  (sama seperti pengeluaran lain) — hanya peringatan di dialog. Hapus rekening
  yang sudah punya transaksi ditolak. Belum ada validasi server untuk pasangan
  kategori ↔ jenis (PRD §4.7 dan UI berbeda untuk OWNER — perlu keputusan
  produk). 🐞 Bug lama: parsing bulan `'Y-m'` meloncat bulan pada tanggal
  29–31 (label cash flow, kalender termin, Analytics) → `'!Y-m'`.
- **Dashboard divisi (SA-4):** piutang desain = nilai kontrak − DP − pelunasan
  (bukan Σ `sisa_piutang`, karena termin bisa < 100%). Turnaround Estimator
  dihitung dari pembuatan quotation (waktu submit tidak disimpan). Jumlah
  "ditolak bulan ini" di Dashboard QA dibaca dari audit trail.
- **Infrastruktur (SA-5):** 🐞 **blocker produksi** ditemukan: Telescope
  (require-dev) didaftarkan tanpa syarat di `bootstrap/providers.php` → fatal
  setelah `composer install --no-dev`; kini didaftarkan dari
  `AppServiceProvider` hanya bila terpasang. Image Docker memakai
  `mariadb-client` (MariaDB 11.8) untuk dump MySQL 8 — **belum** diuji di
  server. Trusted proxies sengaja tidak di-set (nginx → FastCGI langsung).
  Docker/nginx/certbot/deploy SSH belum bisa diuji di mesin dev.
- **Cicilan aset & gaji (SA-6):** `installment_amount` & `installment_due_day`
  (1–28) tambahan di luar schema. Akun FIELD_STAFF tidak bisa ditautkan ke
  karyawan (tukang dibayar per task). Gaji Rp 0 diperbolehkan.
- **Penalti (SA-7):** penggunaan Dana Family Gathering dicatat sebagai
  PENGELUARAN kategori LAINNYA. Belum ada notifikasi ke tukang saat
  pembayaran penaltinya dicatat.

## Pertanyaan terbuka

1. ~~Penagihan penalti~~ — diputuskan: pembayaran manual (keputusan #10).
2. *(Dari Sprint 8)* Drop kolom string `leads.source`/`leads.category`
   setelah backfill production diverifikasi.
3. Checklist QA per tipe milestone — menunggu template dari tim QA (PRD §12
   no. 2 & 6).
