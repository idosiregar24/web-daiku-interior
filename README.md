# Daiku Interior Enterprise System

Sistem informasi internal untuk **Daiku Interior** — mengintegrasikan CRM
(presales), Desain, Quotation, Project Management, Task Management, QA,
Finance, Logistik, Notifikasi, dan Analytics dalam satu platform.

📄 Spesifikasi lengkap: [`.claude/File Skema/Daiku v1.0.0/PRD-Daiku-Interior-System.md`](.claude/File%20Skema/Daiku%20v1.0.0/PRD-Daiku-Interior-System.md)
📋 Rencana kerja & status: [`.claude/plan/README.md`](.claude/plan/README.md)
📐 Standar kode: [`.claude/rules/`](.claude/rules/)

## Tech Stack

| Layer | Teknologi |
|---|---|
| Backend | Laravel 11 (**PHP 8.4+**), MySQL 8.0/8.4, Spatie Permission (RBAC) |
| Frontend | Inertia v2 + React 18 + TypeScript, Tailwind CSS v4, shadcn/ui |
| Queue/Cache | Redis (Predis) — `database` driver dipakai selama Redis lokal belum aktif |
| Real-time | Laravel Echo + Soketi (self-hosted, Pusher-protocol) |
| Ops/Debug | Laravel Horizon, Laravel Telescope (dev) |
| Export | DomPDF, Laravel Excel |
| Testing | Pest PHP |

Detail lengkap & alasan setiap pilihan (termasuk penyesuaian dari PRD
draft awal): [`.claude/plan/README.md`](.claude/plan/README.md) bagian
"Catatan penyesuaian terhadap CSV".

## Prasyarat

- **PHP 8.4+** (`composer.lock` mengunci Symfony 8 → `php >=8.4.1`) dengan ekstensi: `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `gd`, `zip`, `intl`, `fileinfo`
- Composer 2.x
- Node.js 20+ & npm
- MySQL 8.0 (lokal via Laragon/XAMPP, atau `docker compose up -d mysql`)

## Setup lokal

```bash
git clone <url-repo-ini>
cd daiku-interior

# Windows: pcntl/posix (Horizon) tidak ada di Windows — Horizon hanya jalan di worker Docker/Linux
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
npm ci

cp .env.example .env
php artisan key:generate
```

Sesuaikan `DB_*` di `.env` dengan MySQL lokal kamu, lalu buat database
`daiku_interior` (atau sesuai `DB_DATABASE`):

```sql
CREATE DATABASE daiku_interior CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed   # migrasi + role RBAC + 1 user demo per role + data demo semua modul
npm run build                 # atau: npm run dev untuk mode watch
```

> ⚠️ Ini semua **akun demo untuk dev/UAT lokal** (PRD §11.1) — jangan
> pernah jalankan seeder ini di production. `php artisan test` aman
> dijalankan kapan saja tanpa mengganggu data ini (lihat `phpunit.xml`
> — test jalan di SQLite in-memory terisolasi, bukan MySQL dev).

Login awal (dari seeder, ganti password setelah login pertama — semua akun pakai password yang sama):

| Email | Password | Role |
|---|---|---|
| `ceo@daikuinterior.com` | `password` | CEO |
| `marketing@daikuinterior.com` | `password` | MARKETING |
| `designer@daikuinterior.com` | `password` | DESIGNER |
| `estimator@daikuinterior.com` | `password` | ESTIMATOR |
| `pm@daikuinterior.com` | `password` | PM |
| `qa@daikuinterior.com` | `password` | QA |
| `finance@daikuinterior.com` | `password` | FINANCE |
| `logistics@daikuinterior.com` | `password` | LOGISTICS |
| `fieldstaff@daikuinterior.com` | `password` | FIELD_STAFF |
| `superadmin@daikuinterior.com` | `password` | SUPERADMIN *(teknis, di luar PRD — lihat catatan di bawah)* |

> **SUPERADMIN** bukan role dari PRD §7.1 — ini role admin teknis
> tambahan dengan akses penuh ke semua modul (god-mode, lihat
> `app/Http/Middleware/RoleMiddleware.php`) plus CRUD **Data Master**
> (Cabang, Sumber Lead, Kategori Customer, Rekening Bank —
> `/master-data`). Detail & alasan penambahan:
> [`.claude/plan/README.md`](.claude/plan/README.md).

## Menjalankan

- **Laragon**: vhost otomatis `http://web-daiku-interior.test` — pastikan
  versi PHP Laragon = 8.4 (Menu → PHP → Version).
- **Cepat tanpa web server**: `php artisan serve --port=8010`.
- **Scheduler** (penalti 21:00, reminder form 20:30, overdue task/milestone
  00:00, backup database 00:00, termin & follow-up 08:00, prune notifikasi
  02:00 — lihat
  `routes/console.php`): production butuh cron
  `* * * * * php artisan schedule:run`; lokal: `php artisan schedule:work`.
- **Notifikasi real-time**: set `BROADCAST_CONNECTION=pusher` (otomatis
  diteruskan ke frontend lewat `VITE_BROADCAST_CONNECTION`) + Soketi jalan
  (`docker compose up -d soketi`), lalu `npm run build`. Dengan `log`
  (default lokal) notifikasi tetap tersimpan & tampil saat navigasi, hanya
  tidak live.
- **Docker Compose** (belum divalidasi jalan penuh di semua mesin — lihat
  catatan di `.claude/plan/README.md`): `docker compose up -d`.
- Semua service dalam satu perintah (server + queue listener + log tail +
  vite dev): `composer run dev`.

## Produksi

### Deploy manual tanpa Docker (ringkas)

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan db:backup            # backup sebelum migrasi
php artisan migrate --force
# Isi INITIAL_CEO_* dan INITIAL_SUPERADMIN_* di .env dulu (password ≥ 12 karakter)
php artisan db:seed --class=ProductionSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`ProductionSeeder` hanya membuat role, sumber/kategori lead, dan dua akun
awal dari env — tanpa akun demo. `DatabaseSeeder` otomatis menolak data
demo bila `APP_ENV=production`. Rekening bank & cabang asli diisi lewat
**Data Master** (SUPERADMIN). Jalankan queue worker (Horizon) dan cron
scheduler (`* * * * * php artisan schedule:run`) di server.

### HTTPS / Produksi (Docker Compose + Let's Encrypt)

PRD §9.5: HTTPS wajib. Server butuh Docker Engine + **Docker Compose ≥ 2.24.4**,
port 80/443 terbuka, dan DNS `APP_DOMAIN` mengarah ke server. Produksi selalu
memakai dua file: `docker-compose.yml` + `docker-compose.prod.yml`:

- Image berisi kode + aset hasil build (tanpa bind-mount source); yang di-mount
  hanya `.env` (read-only) dan volume `daiku_storage` (log, sesi, file
  upload, backup).
- `nginx` melayani port 80 → redirect ke 443 (kecuali
  `/.well-known/acme-challenge/`) dengan sertifikat Let's Encrypt, HSTS +
  security header (`docker/nginx/production.conf`). Laravel memaksa URL
  `https` saat `APP_ENV=production` (`AppServiceProvider`).
- MySQL, Redis, Soketi **tidak** membuka port ke host. Browser terhubung ke
  Soketi lewat `wss://APP_DOMAIN/app/...` (di-proxy nginx); Laravel memakai
  `soketi:6001` di jaringan internal.

Setup pertama di server:

```bash
git clone <url-repo> /srv/daiku-interior && cd /srv/daiku-interior
git checkout main                       # staging: develop
cp .env.example .env                    # isi nilai produksi — lihat komentar di bagian bawah .env.example
chmod 640 .env && sudo chgrp 33 .env    # harus terbaca www-data (uid 33) di container

C="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

# Sertifikat pertama (nginx belum jalan, certbot memakai port 80 sendiri)
$C run --rm -p 80:80 certbot certonly --standalone \
  -d erp.daikuinterior.com --email it@daikuinterior.com --agree-tos --no-eff-email

bash deploy/deploy.sh main              # build, backup, migrate, start semua service
$C exec --user www-data app php artisan db:seed --class=ProductionSeeder --force
```

Perpanjangan sertifikat — cron di host (harian; `renew` hanya memperbarui
yang hampir kedaluwarsa):

```cron
0 3 * * * cd /srv/daiku-interior && docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm certbot renew --webroot -w /var/www/certbot --quiet && docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T nginx nginx -s reload
```

Perintah artisan di container selalu dengan `--user www-data` (file yang
dibuat root di `storage` tidak bisa ditulis php-fpm). Setelah mengubah
`.env`: `$C up -d` lalu `$C exec --user www-data app php artisan optimize`.

### Deploy otomatis (CI/CD)

`.github/workflows/ci.yml`: setiap push ke `develop` → staging, ke `main` →
production, **hanya setelah job test lulus**. Job deploy SSH ke server lalu
menjalankan `deploy/deploy.sh <branch>`: `git pull --ff-only` → build image →
maintenance mode → `db:backup` → `migrate --force` → recreate container →
`optimize` (cache config/route/view/event) → `horizon:terminate` →
maintenance mode off. Gagal sebelum migrasi = app kembali online di versi
lama; gagal saat/sesudah migrasi = app sengaja tetap maintenance sampai
diperiksa.

Repository secrets (Settings → Secrets and variables → Actions):

| Secret | Isi |
|---|---|
| `STAGING_HOST` / `PRODUCTION_HOST` | IP/hostname server |
| `STAGING_USER` / `PRODUCTION_USER` | user SSH (anggota grup `docker`) |
| `STAGING_SSH_KEY` / `PRODUCTION_SSH_KEY` | private key SSH (public key-nya di `~/.ssh/authorized_keys` server) |
| `STAGING_PATH` / `PRODUCTION_PATH` | path checkout di server, mis. `/srv/daiku-interior` |
| `*_SSH_PORT` *(opsional)* | port SSH, default 22 |
| `*_SSH_FINGERPRINT` *(opsional, disarankan)* | fingerprint SHA256 host key (`ssh-keyscan host \| ssh-keygen -lf -`) — mencegah MITM |

Tanpa secrets, job deploy dilewati dengan notice (build tidak gagal). Server
butuh akses baca ke repo (deploy key) untuk `git pull`. Satu stack per
server (nama container tetap di `docker-compose.yml`).

## Backup & Restore

PRD §3.3/§9.5/§11.4: `php artisan db:backup` berjalan otomatis setiap
**00:00 WIB** (scheduler) — `mysqldump --single-transaction --routines
--triggers --no-tablespaces` → gzip → enkripsi **AES-256-GCM**
(`BACKUP_ENCRYPTION_KEY`) → disimpan di disk `BACKUP_DISK` dengan nama
`<database>_<YYYY-MM-DD_HHMMSS>.sql.gz.enc` → backup lebih tua dari
`BACKUP_RETENTION_DAYS` (30 hari) dihapus. Password DB tidak pernah muncul
di command line (file kredensial sementara 0600). Gagal = exit code ≠ 0 +
log error, dan backup lama tidak dihapus.

| Env | Default | Keterangan |
|---|---|---|
| `BACKUP_ENCRYPTION_KEY` | *(kosong)* | `openssl rand -base64 32`. Kosong = backup **tidak** terenkripsi (warning di log). **Simpan salinan kunci di luar server** — tanpa kunci, backup tidak bisa dipulihkan. |
| `BACKUP_DISK` | `backups` | disk di `config/filesystems.php` |
| `BACKUP_LOCAL_PATH` | `storage/app/backups` | root disk `backups`; arahkan ke volume/disk terpisah |
| `BACKUP_RETENTION_DAYS` | `30` | `0` = tidak pernah menghapus |
| `BACKUP_MYSQLDUMP_PATH` | `mysqldump` | path binary (client MySQL atau MariaDB — terdeteksi otomatis) |

**Lokasi terpisah (§9.5):** default-nya backup ada di volume `storage` server
yang sama. Agar benar-benar terpisah: arahkan `BACKUP_LOCAL_PATH` ke
disk/NFS lain yang di-mount ke container, sinkronkan folder backup ke
penyimpanan lain (rclone/rsync), atau pakai disk `s3`/`sftp` (perlu
`composer require league/flysystem-aws-s3-v3` / `league/flysystem-sftp-v3`
dulu — belum terpasang).

Manual & restore (lokal):

```bash
php artisan db:backup                                   # backup sekarang
php artisan db:backup-decrypt daiku_interior_2026-09-30_000000.sql.gz.enc
#   → storage/app/restore/daiku_interior_2026-09-30_000000.sql.gz (atau --output=...)
gunzip -c storage/app/restore/daiku_interior_2026-09-30_000000.sql.gz | mysql -u root -p daiku_interior
rm storage/app/restore/daiku_interior_2026-09-30_000000.sql.gz   # isinya data tanpa enkripsi
```

Di Docker:

```bash
C="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
$C exec --user www-data app php artisan db:backup-decrypt <file>.sql.gz.enc
$C exec -T app gunzip -c storage/app/restore/<file>.sql.gz \
  | $C exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
$C exec app rm storage/app/restore/<file>.sql.gz
```

Argumen file bisa path lokal atau path di disk backup. Dekripsi dengan kunci
lama (setelah rotasi): `BACKUP_ENCRYPTION_KEY=<kunci lama> php artisan
db:backup-decrypt ...` (di Docker: `$C run --rm -e BACKUP_ENCRYPTION_KEY=...
--user www-data app php artisan db:backup-decrypt ...`). Uji restore secara
berkala ke database terpisah, bukan database produksi.

## Keamanan & audit

- Aksi sensitif (approval quotation, keputusan QA, transaksi/termin/dana,
  penalti, perubahan user & role) tercatat di **Audit Trail**
  (`/audit-logs`, CEO) — append-only, tidak bisa diubah/dihapus siapapun.
- User tidak bisa menghapus akunnya sendiri; nonaktifkan lewat User
  Management (menghapus akan ikut menghapus riwayat penalti/dana).

## Testing

```bash
php artisan test        # Pest — target coverage ≥70% (lihat PRD §10.3)
npm run build            # tsc + vite build, harus lulus tanpa error
```

CI (`.github/workflows/ci.yml`) menjalankan kombinasi keduanya pada setiap
push/PR ke `main`/`develop`, lalu menjalankan ulang test dengan `--coverage`
sebagai laporan (angka total tampil di ringkasan job, belum menggagalkan
build). Setelah angka pertama diketahui, jadikan gerbang: tambahkan
`--min=70` di step "Coverage report" dan hapus `continue-on-error`.

**Lupa password:** link "Lupa password?" di halaman login hanya tampil bila
`MAIL_MAILER` bukan `log`/`array` (email reset benar-benar terkirim). Isi
akun SMTP di `.env` untuk mengaktifkannya.

## Struktur proyek

Mengikuti PRD §3.4 — lihat [`.claude/CLAUDE.md`](.claude/CLAUDE.md) dan
[`.claude/rules/backend-standards.md`](.claude/rules/backend-standards.md)
untuk konvensi lengkap sebelum menambah modul baru. Ringkas:

```
app/Http/Controllers/{CRM,Design,Quotation,Projects,Tasks,Overtime,QA,Finance,Logistics,Analytics}/
app/Services/          # business logic (thin controller pattern)
resources/js/Pages/    # 1:1 dengan controller, per modul
resources/js/Components/shared/   # StatusChip, DataTable, PageHeader, DatePicker, dll
resources/js/Layouts/  # AppLayout (sidebar+topbar), AuthLayout
.claude/plan/           # checklist implementasi per sprint (dari Daiku-Task-Schedule.csv)
.claude/rules/          # standar backend/frontend/database/design/security
.claude/skills/         # panduan langkah-demi-langkah (scaffold modul baru, dst)
```

## Kontribusi

Tim: Ido Refael Siregar, Jonathan Sigalingging. Alur kerja & task per
sprint: [`.claude/plan/`](.claude/plan/). Sebelum membuat modul baru, baca
skill `laravel-inertia-module`
([`.claude/skills/laravel-inertia-skill.md`](.claude/skills/laravel-inertia-skill.md))
— resep scaffold end-to-end yang konsisten dengan pola yang sudah ada di
proyek ini.

---
*Internal — Daiku Interior. Dibangun di atas Laravel 11 + Inertia.js.*
