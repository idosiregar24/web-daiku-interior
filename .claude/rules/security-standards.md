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

- `Quotation Approval` → CEO **lalu** PM, berurutan (bukan siapa saja
  duluan). Implementasikan sebagai state check di `QuotationService`:
  approval PM ditolak kalau `ceo_approved_at` masih null.
- `Finance – Termin`: PM cuma `Create`, Finance `Read+Update` (mark paid),
  bukan sebaliknya.
- `Analytics – Executive`: **hanya CEO**, full. Role lain dapat "Analytics
  – Per Divisi" (partial dashboard sesuai divisi mereka sendiri) — jangan
  reuse query yang sama tanpa scoping per divisi.

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
