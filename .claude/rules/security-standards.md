# Security Standards

Turunan langsung dari PRD §9 (Security Requirements). Ini bukan
opsional/nice-to-have — beberapa poin (audit trail, alasan lost wajib,
blocking QA) adalah *business rule*, bukan sekadar hardening teknis.

## 1. Authentication

- Session-based via Laravel Breeze (bukan token/Sanctum SPA) — sudah
  ter-install. Jangan tambahkan auth guard lain tanpa alasan kuat.
- Password: bcrypt, cost factor default Laravel (12) — jangan diturunkan
  untuk "performa dev", override lewat `.env` per environment kalau perlu,
  bukan ubah `config/hashing.php`.
- Rate limit login (`throttle`) sudah bawaan Breeze — jangan dihapus.

## 2. Authorization — 3 lapis, bukan cuma role check di controller

1. **Route middleware** — `role:CEO|PM` (Spatie) di `routes/web.php` per
   grup modul, mengikuti matriks RBAC PRD §7.1.
2. **Policy** — untuk resource-level check ("task milik tukang ini",
   "proyek milik PM ini"). Buat `php artisan make:policy TaskPolicy
   --model=Task` per model yang butuh ownership check, daftarkan di
   `AuthServiceProvider`. **Wajib** untuk:
   - `Task::update()` — hanya assignee yang boleh ubah status, hanya PM
     yang boleh ubah judul/deskripsi/tanggal (PRD §4.5, task `is_locked`).
   - `Project::view()` — field staff hanya lihat proyek yang task-nya
     di-assign ke mereka (RBAC matriks `R*` = "hanya data milik user").
   - `Penalty::view()` — tukang hanya lihat penalti miliknya sendiri.
3. **Service-layer gate check** — untuk rule lintas-tabel yang policy saja
   tidak cukup (mis. "quotation hanya bisa dibuat kalau
   `Design::clientAcc = true`" — PRD §4.3). Cek ini di Service, lempar
   exception domain-spesifik kalau gagal, jangan biarkan controller lolos
   ke Service dengan data tidak valid.

Field Staff (`FIELD_STAFF`) TIDAK PERNAH boleh:
- Mengubah `title`/`description`/`due_date` pada `Task` (immutable, PRD
  §4.5) — hanya `status`, `kendala`, `note`.
- Melihat detail proyek/task yang bukan miliknya.

QA TIDAK PERNAH boleh melihat detail task tukang — hanya ringkasan
progres milestone (PRD §4.6). Jangan expose relasi `tasks` di response
`QaForm` yang dikirim ke role QA.

## 3. Input validation

- **Semua** input lewat Form Request (`php artisan make:request`) — tidak
  ada `$request->all()` langsung ke `Model::create()` tanpa validasi.
- `$fillable` eksplisit di setiap model (lihat `backend-standards.md` §4)
  sebagai lapis kedua terhadap mass assignment, bukan pengganti Form
  Request.
- Rate limit API/route sensitif: 60 req/menit per user (`throttle:60,1`)
  — sudah standar Laravel, terapkan eksplisit di route group modul yang
  sering di-hit (task status update, daily form submit).

## 4. RBAC — matriks PRD §7.1 adalah kontrak, bukan saran

Setiap kali menambah route baru untuk suatu modul, cek baris matriks di
PRD §7.1 dulu. Contoh yang sering salah kalau tidak dicek:

- `Quotation Approval` → **PM / Asisten PM saja** (Sprint 23 K8 menggantikan
  urutan PM → CEO Sprint 12 #7 dan CEO → PM PRD §7.1). ACC PM langsung
  READY_TO_SEND untuk semua tipe; CEO hanya menerima notifikasi info (RAB
  Proyek) dan menulis catatan append-only (`quotations.notes.store`,
  `role:CEO`) — **tidak** bisa mengembalikan RAB. Route `quotations.review`
  = `role:PM|ASISTEN_PM`; `QuotationService::reviewStage()` hanya
  menerima SUBMITTED.
- **Buka Proyek** (Sprint 23 K13): `projects.openings.open` = `role:PM` +
  `ProjectOpeningPolicy::open` (hanya PM yang meng-ACC RAB Proyek itu —
  `project_openings.opener_id`; semua PM bila Asisten PM yang meng-ACC atau
  PM itu nonaktif). CEO tidak membuka proyek.
- **Foto bukti bayar** (Sprint 23 K12d): disimpan di disk privat
  (`InvoicePaymentProof::DISK`), dikodekan ulang ke WebP tanpa EXIF, hanya
  dibuka lewat `finance.invoices.proofs.show` (`role:CEO|MARKETING|FINANCE`
  + `InvoicePolicy::viewPaymentProofs`), tabel append-only — jangan pernah
  beri URL publik.
- `Finance – Termin`: termin tidak lagi dibuat manual (Sprint 12 Sub 7 —
  dari skema bayar saat Buka Proyek). Invoice diterbitkan **Marketing**,
  pembayaran diverifikasi **hanya Finance**; termin yang sudah ber-invoice
  tidak bisa "Tandai Dibayar" langsung (supaya pemasukan tidak tercatat
  dua kali).
- `Analytics – Executive`: **hanya CEO**, full. Role lain dapat "Analytics
  – Per Divisi" (partial dashboard sesuai divisi mereka sendiri) — jangan
  reuse query yang sama tanpa scoping per divisi.
- **Link persetujuan klien** (`penawaran/{token}`, Sprint 12 Sub 5) adalah
  satu-satunya route publik yang menulis data — dua aksi: `setujui` dan
  `tolak` (Sprint 23 K11, catatan wajib 3–1000 karakter, IP/perangkat
  diaudit): token acak 48 karakter per
  versi, `throttle:30,1`, `noindex` + `no-referrer`, respons lewat
  `PublicQuotationResource` (whitelist — jangan kirim model mentah), link
  versi lama hanya menampilkan "sudah diperbarui".
- **Alokasi Dana & Realisasi** (Sprint 12 Sub 9–10): tulis = PM proyek itu
  saja (`ProjectPolicy::manageBudget`), overrun diputuskan CEO; baca = CEO,
  Finance, PM. Marketing dan Asisten PM **tidak menerima props-nya sama
  sekali** — filter di controller, bukan hanya disembunyikan di UI.
- **Asisten PM** (Sprint 12 Sub 11) hanya pada proyek yang ditugaskan
  (`Project::isManagedBy()` di Policy) — role check saja tidak cukup.
- **Pengawas (QA/QC)** (Sprint 24) hanya pada proyek yang ia awasi
  (`Project::isSupervisedBy()`, `ProjectPolicy::supervise` /
  `manageTargets` / `planMaterials`): daily target, DP / pelunasan, laporan
  mingguan, temuan, rencana bahan — boleh diubah/dihapus olehnya sampai
  Produksi (`reviseMaterialPlan`; route `project-materials.update/destroy`
  menerima `QA`, baris pengajuan tukang tidak). QA lain tetap **tidak** melihat task (PRD §4.6 —
  `taskVisibility($user, $project)`). Pengawas = QA = QC (satu orang):
  pemeriksaan QA timeline juga hanya oleh Pengawas proyek itu
  (`QaFormPolicy::review`); proyek tanpa Pengawas tetap terbuka untuk semua
  user role QA.
- **Admin Proyek** (Sprint 29 Sub 07, role `ADMIN_PROYEK`): boleh
  **menginput** di semua proyek (TM, timeline, bahan, daily target &
  pemeriksaannya, temuan, catatan) — `role:` hanya ditambahkan di route
  input; **tidak pernah** TM Selesai, DP/pencairan, Berangkat ke Lokasi,
  Tandai Selesai timeline, QA, Anggaran, Buka Proyek, RAB, pembayaran (403
  oleh route + Policy). Props termin, dokumen & anggaran tidak dikirim.
- **Upah Tukang per item RAB** (Sprint 29 Sub 08, B3): kartu Upah Tukang
  **membuka harga RAB ke Pengawas proyek** (keputusan user — Sprint 24 U4
  dulu sengaja tanpa harga). Diisi hanya Pengawas proyek / Admin Proyek
  (`ProjectPolicy::setWages`, route `role:QA|ADMIN_PROYEK`); prop
  `execution.workItems` hanya untuk yang melihat uang (CEO/PM/Finance/
  Pengawas proyek) + Admin Proyek — tidak untuk Logistik, Marketing, Tukang.
  Upah yang sudah diisi terkunci setelah Start Produksi; total tidak pernah
  di bawah yang sudah diajukan/dibayar.
- **Jasa Pengawas** (Sprint 24 D4 — sejak Sprint 29 berlabel "Upah Tukang"): keputusan PM proyek itu saja
  (`ProjectPolicy::approveExecution`, juga laporan mingguan & "Berangkat ke
  Lokasi"), pembayaran **hanya Finance** (`finance.supervisor-payments.pay`),
  tidak pernah melebihi nilai jasa, tanpa route hapus. Nilai jasa &
  pembayaran hanya dikirim ke CEO / PM / Finance / Pengawas proyek itu.
- **Pengecekan % per item** (Sprint 29 Sub 09): mengirim pengecekan =
  mengajukan uang → hanya Pengawas proyek (`weekly-reports.store`,
  `role:QA` + `supervise`); Admin Proyek hanya mengisi draf
  (`fillWorkCheck`). `work_item_progress` append-only (koreksi = baris minus
  + catatan), Σ per item 0–100.
- **QA per item RAB** (Sprint 29 Sub 12): keputusan Lolos / Perlu perbaikan
  hanya Pengawas proyek (`projects.item-qa.decide`, `role:QA` +
  `ProjectPolicy::reviewItemQa`) — **bukan** Admin Proyek / PM. Foto QA di
  disk privat (WebP tanpa EXIF), hanya lewat `projects.item-qa.photo`
  (`viewItemQa` — role Timeline & QC); `work_item_qa_checks` append-only.
- **Pembagian Upah per Orang** (Sprint 32): template Tim Tukang = `role:CEO|PM|QA|ADMIN_PROYEK`,
  bobot tingkatan karir **CEO saja**; pembagian di proyek = Pengawas proyek /
  Admin Proyek (`ProjectPolicy::splitWages`). Slip per orang memuat nominal
  upah → prop `execution.wageSplit` hanya untuk yang melihat Upah Tukang.
  Alat bantu saja — tidak ada route yang memindahkan uang.
- **Aktivitas Harian** (Sprint 29 Sub 13): ditulis sendiri hari itu saja
  (`DailyActivityPolicy`); pemeriksaan HR per orang-hari append-only; route
  `daily-activities.*` — bukan `module:hr`.

Matriks 403 untuk route-route Sprint 29: `tests/Feature/Projects/Sprint29RbacTest.php`.

Setiap route ber-role wajib feature test RBAC (PRD §10.3 DoD: "RBAC sudah
diuji untuk semua role yang relevan") — minimal: 1 test role yang berhak
(200/302 sukses), 1 test role yang tidak berhak (403).

## 5. Audit trail — append-only

PRD §9.4: "Audit log tidak bisa dihapus oleh siapapun (termasuk CEO)."

- Aksi sensitif WAJIB tercatat: approval quotation, keputusan QA,
  perubahan finance, penjatuhan penalti — user ID, timestamp, IP, action
  type, data before/after.
- Implementasi: model log terpisah (append-only, tanpa route/controller
  `destroy`), atau package audit (`spatie/laravel-activitylog` — evaluasi
  saat modul finance/QA mulai dibangun, belum ter-install saat ini).
- Jangan pernah beri role manapun (termasuk CEO) endpoint untuk menghapus
  baris di tabel audit/log.

## 6. Data protection

- `.env` tidak pernah di-commit (`.gitignore` sudah menghandle ini) —
  jangan taruh secret di `config/*.php` langsung, selalu lewat `env()` +
  masuk ke `.env.example` sebagai placeholder kosong.
- HTTPS wajib di staging/production (Nginx + Let's Encrypt, lihat
  `docker-compose.yml` service `nginx`) — di lokal HTTP via Laragon tidak
  masalah.
- Database tidak pernah expose ke public internet — di Docker Compose,
  service `mysql` hanya reachable dari network internal `daiku` (lihat
  `docker-compose.yml`), port host `3307` hanya untuk akses dev/debug.

## 7. Sebelum PR/merge

Jalankan `/security-review` (skill Claude Code yang sudah tersedia) pada
diff yang menyentuh: auth, payment/finance, file/URL input (link desain,
invoice), atau endpoint yang menerima role apapun selain pemiliknya
sendiri.
