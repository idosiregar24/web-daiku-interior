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
| Backend | Laravel 11 (PHP 8.3+), MySQL 8.0, Spatie Permission (RBAC) |
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

- PHP 8.3+ dengan ekstensi: `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `gd`, `zip`, `intl`
- Composer 2.x
- Node.js 20+ & npm
- MySQL 8.0 (lokal via Laragon/XAMPP, atau `docker compose up -d mysql`)

## Setup lokal

```bash
git clone <url-repo-ini>
cd daiku-interior

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Sesuaikan `DB_*` di `.env` dengan MySQL lokal kamu, lalu buat database
`daiku_interior` (atau sesuai `DB_DATABASE`):

```sql
CREATE DATABASE daiku_interior CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed   # migrasi + seed 9 role RBAC + 1 user demo per role
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

- **Laragon/XAMPP/Nginx manual**: arahkan document root ke `public/`, akses
  lewat vhost (mis. `http://daiku-interior.test`).
- **Cepat tanpa web server**: `php artisan serve`.
- **Docker Compose** (belum divalidasi jalan penuh di semua mesin — lihat
  catatan di `.claude/plan/README.md`): `docker compose up -d`.
- Semua service dalam satu perintah (server + queue listener + log tail +
  vite dev): `composer run dev`.

## Testing

```bash
php artisan test        # Pest — target coverage ≥70% (lihat PRD §10.3)
npm run build            # tsc + vite build, harus lulus tanpa error
```

CI (`.github/workflows/ci.yml`) menjalankan kombinasi keduanya pada setiap
push/PR ke `main`/`develop`.

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
