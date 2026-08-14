# Sprint 1 — Week 1–Week 2 (Bulan 1)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 8 selesai · 2 sebagian · 14 belum mulai (dari 24 task).

## Ido Refael Siregar

### Week 1 (2025-01-06)

- [x] **[Setup]** Init Laravel 11 + Inertia.js v2 + React 18 + TypeScript — *Setup · 2 jam* _(Catatan CSV: composer create-project + npm install)_
  - 📌 **Status:** Selesai. `composer create-project laravel/laravel` + `laravel/breeze` (react-ts, Pest).
- [x] **[Setup]** Konfigurasi Tailwind CSS + shadcn/ui + Daiku theme token — *Setup · 2 jam* _(Catatan CSV: warna daiku-yellow, border radius, font)_
  - 📌 **Status:** Selesai. Tailwind v4 (bukan v3; CLI shadcn terbaru men-generate syntax v4-only) + shadcn/ui preset Radix "Nova" + token warna Daiku di-wire ke `resources/css/app.css`.
- [ ] **[Setup]** Setup Docker Compose: postgres, redis, soketi, nginx — *Setup · 4 jam* _(Catatan CSV: docker-compose.yml lengkap)_
  - 📌 **Status:** Sebagian. `docker-compose.yml` + `Dockerfile` + `docker/nginx/default.conf` sudah dibuat, pakai **MySQL** (mengikuti PRD §3.2, bukan Postgres seperti tertulis di kolom Catatan) + redis + soketi + nginx. Belum pernah dijalankan/diuji — Docker tidak tersedia di environment dev saat ini.
- [x] **[Auth]** Install Laravel Breeze + Spatie Permission + seed 9 roles — *Backend · 4 jam* _(Catatan CSV: role: CEO,Marketing,Designer,dll)_
  - 📌 **Status:** Selesai. 9 role (CEO, MARKETING, DESIGNER, ESTIMATOR, PM, QA, FINANCE, LOGISTICS, FIELD_STAFF) di-seed via `RoleSeeder`, user CEO awal dibuat (`ceo@daikuinterior.com`).
- [ ] **[Auth]** Middleware RBAC + route group per role (web.php) — *Backend · 4 jam*
  - 📌 **Status:** Sebagian. Alias middleware `role`/`permission`/`role_or_permission` (Spatie) sudah didaftarkan di `bootstrap/app.php` dan diuji (`RbacMiddlewareTest`) — sebelumnya route `role:` akan error karena Laravel 11 tidak auto-register alias package. Route group per modul (`Route::middleware(['auth','role:...'])->group(...)`) belum ada karena belum ada controller modul untuk digrup.
- [x] **[Auth]** Redirect post-login per role + AppLayout.tsx (sidebar+topbar) — *Fullstack · 4 jam*
  - 📌 **Status:** Selesai. `AppLayout.tsx` (sidebar fixed kiri per divisi + topbar dengan bell notifikasi & avatar dropdown) sudah dibuat. `RoleRedirectService` + `AuthenticatedSessionController` sudah redirect berdasarkan role (diuji via `RoleRedirectServiceTest`) — saat ini semua role masih resolve ke `/dashboard` karena belum ada landing page khusus per role, tapi mekanismenya sudah lengkap: tinggal isi `RoleRedirectService::ROLE_ROUTES` begitu halaman per-role dibangun.

### Week 2 (2025-01-13)

- [ ] **[Auth]** Login page UI + error handling Inertia form — *Frontend · 2 jam*
- [ ] **[Auth]** User Management: CRUD user + assign role — *Fullstack · 2 jam*
- [ ] **[CRM]** Database migration: leads, pipeline_logs — *Backend · 4 jam*
- [ ] **[CRM]** Lead model + PipelineLog model + relasi Eloquent — *Backend · 4 jam* _(Catatan CSV: scope: byStatus, byPriority)_
- [ ] **[CRM]** LeadController + StoreLeadRequest + LeadService — *Backend · 4 jam* _(Catatan CSV: thin controller pattern)_
- [ ] **[CRM]** Lead index page: tabel + filter status/prioritas — *Frontend · 4 jam*


## Jonathan Sigalingging

### Week 1 (2025-01-06)

- [ ] **[Setup]** Clone repo + setup local environment (PHP 8.3, Node, MySQL, Redis) — *Setup · 2 jam*
- [ ] **[Setup]** Belajar: Laravel 11 basics + Eloquent ORM (dokumentasi resmi) — *Learning · 2 jam*
- [ ] **[Setup]** Belajar: Inertia.js konsep + cara kerja page component — *Learning · 2 jam*
- [x] **[Setup]** Database migration: users, roles, permissions (Spatie) — *Backend · 2 jam*
  - 📌 **Status:** Selesai. Migrasi Spatie (`permission_tables`) + kolom `is_active` tambahan di `users`.
- [x] **[Setup]** Buat komponen UI dasar: Button, Card, Badge, StatusChip — *Frontend · 4 jam* _(Catatan CSV: shadcn + Daiku theme)_
  - 📌 **Status:** Selesai. Button, Card, Badge (shadcn) + `StatusChip` (`Components/shared/StatusChip.tsx`, badge warna per status sesuai PRD §8.3, mapping status lengkap dari semua union type di `types/index.d.ts`).
- [x] **[Setup]** Buat komponen: Table (TanStack), Modal, Dropdown, PageHeader — *Frontend · 4 jam*
  - 📌 **Status:** Selesai. Primitive shadcn Table/Dialog(Modal)/DropdownMenu + `DataTable` (`Components/shared/DataTable.tsx`, wrapper TanStack Table v8 dengan sort toggle & loading/empty state) + `PageHeader` (`Components/shared/PageHeader.tsx`).
- [x] **[Setup]** Buat komponen: Form input, Select, DatePicker, Textarea + AuthLayout — *Frontend · 4 jam* _(Catatan CSV: React Hook Form + Zod)_
  - 📌 **Status:** Selesai. Input, Select, Textarea (shadcn), `AuthLayout.tsx`, dan `DatePicker` (`Components/shared/DatePicker.tsx`, Popover+Calendar, format Bahasa Indonesia via date-fns `id` locale).

### Week 2 (2025-01-13)

- [ ] **[Setup]** Buat Sidebar navigasi dengan conditional menu per role — *Frontend · 4 jam*
- [ ] **[Projects]** Database migration: projects, milestones, tasks — *Backend · 4 jam*
- [ ] **[Projects]** Database migration: progress_logs, daily_task_forms — *Backend · 4 jam*
- [ ] **[Projects]** Database migration: overtime_requests, penalties, family_gathering_fund — *Backend · 4 jam*
- [ ] **[Finance]** Database migration: termins, finance_transactions, qa_forms, materials, assets — *Backend · 4 jam*

