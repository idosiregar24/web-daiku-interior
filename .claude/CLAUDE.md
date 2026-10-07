# Daiku Interior Enterprise System

Internal web system for **Daiku Interior** (interior design/build company)
covering CRM → Design → Quotation → Project Execution → QA → Finance →
Logistics → Analytics, end to end. Full specification:
[`File Skema/Daiku v1.0.0/PRD-Daiku-Interior-System.md`](File%20Skema/Daiku%20v1.0.0/PRD-Daiku-Interior-System.md)
— **read the relevant PRD section before building any module**; this file
is orientation, not a replacement for it.

Sprint-by-sprint execution plan (checkable checklists, current build
status): [`plan/README.md`](plan/README.md). Source task list:
[`File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`](File%20Skema/Daiku%20v1.0.0/Daiku-Task-Schedule.csv).

## Stack (as actually installed — see `plan/README.md` for PRD deviations)

- **Backend:** Laravel 11, PHP 8.4, MySQL 8.0, Spatie Laravel Permission
  (RBAC), Laravel Horizon, Telescope (dev), DomPDF, Laravel Excel, Predis.
- **Frontend:** Inertia v2 + React 18 + TypeScript, Tailwind CSS **v4**,
  shadcn/ui (Radix + "Nova" preset), Recharts, Laravel Echo + pusher-js
  (Soketi), React Hook Form + Zod, TanStack Table, date-fns, Ziggy.
- **Infra:** Docker Compose scaffold (mysql/redis/app/worker/soketi/nginx)
  — written but untested locally (no Docker on this dev machine); local
  dev runs natively on Laragon (MySQL + nginx already running,
  `daiku-interior.test` vhost).

## Coding standards (read before writing code)

@rules/backend-standards.md
@rules/database-standards.md
@rules/frontend-standards.md
@rules/design-standards.md
@rules/security-standards.md

## Project-specific skills

- `laravel-inertia-module` (`skills/laravel-inertia-skill.md`) — the
  step-by-step recipe for scaffolding a new module end-to-end (migration →
  model → service → request → controller → route → page). Use this
  whenever starting a module from PRD §4 or `plan/sprint-0N.md`.
- `react-component` (`skills/react-component-skill.md`) — recipe for a new
  reusable component under `resources/js/Components/`.
- `front-end-design` (`skills/front-end-design/SKILL.md`) — Daiku design
  system applied in practice (tokens, layouts, shadcn CLI usage, status
  chips, charts).

## Golden rules (the ones people actually get wrong)

1. **`.claude/File Skema/Daiku v1.0.0/daiku_schema.sql` exists and is more
   authoritative than PRD §5.1's prose schema sketch where they disagree**
   (ULID-style PKs, richer ENUMs, several tables §5.1 never sketched at all
   — staff_loans, supplier_debts, finance_allocation_configs, audit_logs,
   quotations, designs, etc.). Read it before building any module whose
   tables aren't shipped yet. The already-shipped Lead/RBAC/User tables
   stay on bigint PKs for now — see `.claude/plan/README.md` "Schema
   discovery" section for the full list of what hasn't been reconciled.
2. **RBAC matrix (PRD §7.1) is a contract.** Every new route needs the
   right `role:` middleware *and*, where PRD says "R\*" (own data only) or
   describes an ownership rule, a Policy — not just a role check.
   Exception: `SUPERADMIN` is a technical admin role added outside the
   PRD (`database/seeders/RoleSeeder.php`) with unconditional god-mode
   access to every `role:`-gated route — see `app/Http/Middleware/RoleMiddleware.php`.
3. **`users` has no `role` column.** Roles are Spatie
   (`$user->hasRole()`/`assignRole()`) — see `RoleSeeder`. Don't
   re-introduce a redundant enum column.
4. **Tailwind v4, not v3.** Colors live in `resources/css/app.css`
   (`@theme` blocks), not `tailwind.config.js` (deleted — v4 is CSS-first).
   Never hardcode hex/default-Tailwind colors; use the Daiku tokens.
5. **`Components` folder is capitalized.** `npx shadcn add` generates
   lowercase `@/components/...` imports — fix the casing to
   `@/Components/...` immediately or the TypeScript build breaks (Windows
   hides the case collision locally; CI won't).
6. **Task immutability (PRD §4.5):** Field Staff can only change a task's
   `status`/`kendala`/`note` — never `title`/`description`/`due_date`.
   Enforce via Policy, not just frontend hiding of the fields.
7. **Audit trail is append-only** (PRD §9.4) — no `destroy` route/policy
   ever, for anyone, on audit/finance/penalty logs. Sensitive actions go
   through `AuditLogService::record()` inside the acting service's
   transaction; `App\Models\AuditLog` itself throws on update/delete.
   Users are deactivated (`is_active`), never deleted — deletion cascades
   would erase their penalty/fund history.
8. **Sidebar nav (`Layouts/AppLayout.tsx`) tracks reality.** A module's
   `NAV_GROUPS` entry gets a real `routeName` only once its `index` route
   actually exists — until then it renders disabled ("Segera"). Since
   Sprint 13, sibling list pages are one **hub** (`NavItem.tabs`, shown by
   `<ModuleTabs />` under the page header), setup pages live in the pinned
   **⚙ Pengaturan** hub, group order follows the role (`ROLE_GROUP_ORDER`),
   and menu badges come only from `ActionInboxService` (`navBadges`) —
   see `design-standards.md` §4 before adding a menu.
9. **SDM / HR module (Sprint 10, outside the PRD — `plan/sprint-10-sdm.md`).**
   `HR` is a non-PRD role like SUPERADMIN. All `hr.*` routes sit behind one
   `module:hr` gate (`ModuleAccessMiddleware`, the future per-user module
   access hook) plus per-action `role:`. HR — not Finance — manages
   employees; job titles are `position_id` → Divisi → Jabatan, never free
   text; `base_salary` only changes via a CEO-approved `salary_changes` row.
   Field staff are never part of SDM: query employees through
   `Employee::query()->hrEligible()`. An employee's own pages are `my.*`
   (`employee.self` middleware) and show only final data.
10. **Materials & masters (Sprint 11 — `plan/sprint-11-quotation-satuan-material.md`).**
    Units (`units`), vendors (`vendors`) and material categories/synonyms
    are masters — never free text; pick through `UnitSelect` /
    `VendorSelect` / `Unit::options()` / `Vendor::options()`. Material
    quantities are DECIMAL(12,2): do arithmetic in hundredths via
    `App\Support\Quantity`, never raw floats. Catalog items are born only
    through `MaterialCatalogService::create()` (Logistics — "satu pintu";
    `match_key` UNIQUE, similar items need a reason); project material
    lines move only through `ProjectMaterialService` / `StockService`
    (lock line → material), out-of-catalog items only through
    `MaterialRequestService`. A project reaches COMPLETED only via
    `ProjectService::completeIfFinished()` (all milestones QA'd, no
    leftover, no undecided request).
11. **Revisi alur (Sprint 12 — `plan/sprint-12-revisi-alur.md`, outside the
    PRD).** Roles `ASISTEN_PM` and `KEPALA_DESAIN` (stacked on DESIGNER —
    `User::STACKED_ROLES`; the UI calls DESIGNER "Arsitek"). Quotations have
    3 types (SURVEY / DESAIN / PROYEK), are asked for by Marketing
    (DIMINTA), reviewed per item **PM / Asisten PM → CEO** (CEO only for
    PROYEK) and approved by the **client on a public link**
    (`penawaran/{token}`) — never by a staff button. A project is born only
    from the CEO's "Buka Proyek" on an approved RAB Proyek; its termins come
    from the approved payment scheme; a RAB Tambahan (`parent_quotation_id`)
    adds to the project instead. Marketing issues every invoice, Finance
    alone verifies it (`InvoiceService` books the income once). A RAB
    Proyek's DP is billed right after the client's approval, before Buka
    Proyek, and attached to the DP termin when the project opens
    (`TerminService::attachUpfrontInvoice()`, Sprint 17 Sub 06). Designs are
    locked until their Jasa Desain invoice is verified, then assigned by a
    Kepala Desain. "Alokasi Dana Proyek" (`ProjectBudgetService`) and
    realisations (`BudgetRealizationService`, over-budget → CEO) are the
    project PM's alone (`ProjectPolicy::manageBudget`) and never sent to
    Marketing or the Asisten PM; an Asisten PM works only on the projects
    it is assigned to (`Project::isManagedBy()`).
12. **Navigasi & HP (Sprint 13 — `plan/sprint-13-navigasi-ux.md`).** No
    route, gate or business rule changed — only how people reach pages.
    "Perlu Tindakan" (`inbox.index`) is built once in `ActionInboxService`
    from the list pages' own scopes (`Project::visibleTo()`,
    `ProjectMaterial::visibleTo()`, `Task::awaitingDailyForm()`…); a new
    queue is one method there, never a second query in a page. The topbar
    search (`search`) answers a whitelist per role, scoped like the list
    pages. A Tukang (primary role FIELD_STAFF) gets the phone layout below
    `lg`: `BottomNav`, "Hari Ini" (`today.index`, their landing page),
    "Lainnya" (`more.index`); saving from a task card uses the existing
    `daily-forms.store` / `tasks.updateStatus`. The daily-form hour and
    working days live only in `config/daiku.php` (`DailyFormSchedule`).
    Pages used in the field pass `DataTable` a `mobileCard` and use
    `ResponsiveDialogContent`; heavy, role-only code is `lazy()`-loaded.
13. **RAB & surat resmi (Sprint 14–15 — `plan/sprint-14-…`, `plan/sprint-15-…`).**
    A RAB's name comes only from `Quotation::title()` (custom name → "RAB
    Tambahan" → type label); "Buat RAB → Lainnya" is a RAB Proyek with
    `custom_name`, same flow. Offers and invoices are company letters built
    once in `App\Support\Letters\*` and rendered by `pdf/layouts/letter` and
    the client's link (`LetterDocument`) — change the letter there, never per
    page. Letter numbers (`377/OFF/Daiku/IX/2026`, INV for invoices) come only
    from `LetterNumberService`; an offer is numbered when sent. RAB request
    references (links/photos) are internal; the signature asset is never
    served publicly (`SiteSetting::PUBLIC_ASSETS`).
14. **Form & data lead (Sprint 16 — `plan/sprint-16-penanda-wajib.md`).**
    Every label whose Form Request rule is `required` gets
    `<FormLabel required>` / `<InputLabel required>` / `<RequiredMark />`
    (dynamic for `required_if`); never write "(opsional)". A lead's contact
    is `phone` (digits only, `08…`, through `App\Support\Phone` /
    `lib/phone.ts` — never a hand-rolled regex or `whatsappNumber()`) and/or
    `email`, at least one (`ValidatesLeadContact`). A lead's city is
    `city_id` → Master Kota (`cities`, SUPERADMIN in Data Master), picked
    through `CitySelect` / `City::options()` — never free text; the home
    city is `config('daiku.home_city')`.

## Local environment

- **PHP 8.4 is required** — `composer.lock` pins Symfony 8 (`php >=8.4.1`).
  Installed at `D:\laragon\bin\php\php-8.4.26-Win32-vs17-x64` (the PATH
  `php` may be an older 8.2/8.3). Install deps with
  `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  — Horizon's pcntl/posix don't exist on Windows; Horizon runs in the
  Linux Docker worker only.
- App: `http://web-daiku-interior.test` (Laragon Apache vhost, root =
  `public/`) — only once Laragon's PHP is switched to 8.4 (Menu → PHP →
  Version). Otherwise `php artisan serve --port=8010` (port 8000 is used
  by another local project on this machine).
  **Known 500 on the vhost (Sprint 17 Sub 01):** Laragon's Apache loads PHP
  as a thread-safe `mod_php` module (`D:\laragon\etc\apache2\mod_php.conf`,
  still pointing at 8.3 as of 2026-10-07). Under concurrent requests the
  `.env` values set by one thread can vanish for another (phpdotenv/putenv
  is not thread-safe) → sporadic `production.ERROR: No application
  encryption key has been specified`. Prefer `php artisan serve` (or a
  non-threaded PHP setup) for client-link demos. Do **not** "fix" it with
  `config:cache` locally — a cached config makes `php artisan test` ignore
  `phpunit.xml` (SQLite) and run against the dev MySQL database.
- DB: MySQL 8.4 via Laragon, `root` / no password, database `daiku_interior`.
  `php artisan migrate:fresh --seed` = full demo data (every role:
  `{role}@daikuinterior.com` / `password`). Production uses
  `ProductionSeeder` instead — `DatabaseSeeder` refuses demo data when
  `APP_ENV=production`.
- Real-time: `BROADCAST_CONNECTION=log` locally (no Soketi without
  Docker). The frontend mirrors it via `VITE_BROADCAST_CONNECTION`, so
  flipping both to `pusher` turns the live notification bell on.
- No local Redis — `CACHE_STORE`/`QUEUE_CONNECTION` are `database` for
  now (documented inline in `.env`); flip to `redis` once
  `docker compose up -d redis` (or a native install) is available.
- `npm run build` = `tsc && vite build` — must pass clean; this is also
  what CI (`.github/workflows/ci.yml`) runs.
- `php artisan test` (Pest) — target ≥70% coverage per PRD §10.3.

## Language

Code/comments in English. Anything **user-facing** — UI text, validation
messages, notifications — in **Bahasa Indonesia** (PRD §1.4).
