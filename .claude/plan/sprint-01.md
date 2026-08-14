# Sprint 1 — Week 1–Week 2 (Bulan 1)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 20 selesai · 1 sebagian · 3 belum mulai (dari 24 task).

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
- [x] **[Auth]** Middleware RBAC + route group per role (web.php) — *Backend · 4 jam*
  - 📌 **Status:** Selesai. Alias middleware `role`/`permission`/`role_or_permission` (Spatie) didaftarkan di `bootstrap/app.php` (diuji di `RbacMiddlewareTest`) dan sekarang benar-benar dipakai di route group nyata: `crm.leads.*` (`role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM` untuk read, `role:CEO|MARKETING` untuk write) dan `users.*` (`role:CEO`) di `routes/web.php`.
- [x] **[Auth]** Redirect post-login per role + AppLayout.tsx (sidebar+topbar) — *Fullstack · 4 jam*
  - 📌 **Status:** Selesai. `AppLayout.tsx` (sidebar fixed kiri per divisi + topbar dengan bell notifikasi & avatar dropdown) sudah dibuat. `RoleRedirectService` + `AuthenticatedSessionController` sudah redirect berdasarkan role (diuji via `RoleRedirectServiceTest`) — saat ini semua role masih resolve ke `/dashboard` karena belum ada landing page khusus per role, tapi mekanismenya sudah lengkap: tinggal isi `RoleRedirectService::ROLE_ROUTES` begitu halaman per-role dibangun.

### Week 2 (2025-01-13)

- [x] **[Auth]** Login page UI + error handling Inertia form — *Frontend · 2 jam*
  - 📌 **Status:** Selesai. `Pages/Auth/Login.tsx` di-restyle penuh ke shadcn/Daiku (Input/Label/Checkbox/Button, teks Bahasa Indonesia, error inline per field).
- [x] **[Auth]** User Management: CRUD user + assign role — *Fullstack · 2 jam*
  - 📌 **Status:** Selesai. `Auth\UserController` (CEO-only, route `users.*`) + `UserService` (create/update + assignRole/syncRoles) + `Pages/Auth/Users/{Index,Create,Edit}.tsx` (RHF+Zod, DataTable, Switch untuk is_active). Diuji di `UserManagementTest` (akses CEO-only + assign/ubah role).
- [x] **[CRM]** Database migration: leads, pipeline_logs — *Backend · 4 jam*
  - 📌 **Status:** Selesai. Migrasi `leads` (PRD §4.1 + §5.1, termasuk field kategori/layanan/kota/gender/lost_reason dari narasi §4.1) dan `pipeline_logs` (append-only).
- [x] **[CRM]** Lead model + PipelineLog model + relasi Eloquent — *Backend · 4 jam* _(Catatan CSV: scope: byStatus, byPriority)_
  - 📌 **Status:** Selesai. `Lead` (scope `byStatus`, `byPriority`, `overdueFollowUp`; relasi `assignee()`/`creator()`/`pipelineLogs()`) + `PipelineLog` (append-only, `UPDATED_AT = null`) + enum native `LeadStatus`/`LeadPriority` di `app/Enums/`.
- [x] **[CRM]** LeadController + StoreLeadRequest + LeadService — *Backend · 4 jam* _(Catatan CSV: thin controller pattern)_
  - 📌 **Status:** Selesai. `CRM\LeadController` (thin — `index`+`store`; `edit`/`update`/`updateStatus` menyusul Sprint 2 sesuai baris CSV berikutnya) + `StoreLeadRequest` + `LeadService::create()`/`changeStatus()` (aturan alasan-lost-wajib & LOST-terminal, auto-tulis PipelineLog). Diuji di `CRM/LeadTest`.
- [x] **[CRM]** Lead index page: tabel + filter status/prioritas — *Frontend · 4 jam*
  - 📌 **Status:** Selesai. `Pages/CRM/Index.tsx` — DataTable + StatusChip + filter status/prioritas + search nama klien, follow-up lewat tanggal di-highlight merah.


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

- [x] **[Setup]** Buat Sidebar navigasi dengan conditional menu per role — *Frontend · 4 jam*
  - 📌 **Status:** Selesai. `NAV_GROUPS` di `AppLayout.tsx` di-beri properti `roles` per item, dipetakan dari matriks RBAC PRD §7.1 (grup/menu yang rolenya tidak punya akses sama sekali disembunyikan, bukan sekadar di-disable).
- [x] **[Projects]** Database migration: projects, milestones, tasks — *Backend · 4 jam*
  - 📌 **Status:** Selesai. Migrasi `projects`, `milestones`, `tasks` (PRD §4.4/§4.5 + §5.1) — model/controller-nya baru masuk Sprint 2 (lihat sprint-02.md).
- [x] **[Projects]** Database migration: progress_logs, daily_task_forms — *Backend · 4 jam*
  - 📌 **Status:** Selesai. Migrasi `progress_logs` (append-only) dan `daily_task_forms` (unique per task+tanggal, PRD §4.5).
- [x] **[Projects]** Database migration: overtime_requests, penalties, family_gathering_fund — *Backend · 4 jam*
  - 📌 **Status:** Selesai. Migrasi `overtime_requests` (approval PM→Finance terpisah), `penalties`, `family_gathering_fund` (append-only).
- [x] **[Finance]** Database migration: termins, finance_transactions, qa_forms, materials, assets — *Backend · 4 jam*
  - 📌 **Status:** Selesai. Migrasi `termins`, `finance_transactions` (kolom `bank_account_id` PRD §4.7 sengaja ditunda ke Sprint 4 — lihat komentar migrasi), `qa_forms`, `materials`, `assets`.

