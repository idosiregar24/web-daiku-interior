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
   ever, for anyone, on audit/finance/penalty logs.
8. **Sidebar nav (`Layouts/AppLayout.tsx`) tracks reality.** A module's
   `NAV_GROUPS` entry gets a real `routeName` only once its `index` route
   actually exists — until then it renders disabled ("Segera").

## Local environment

- App: `http://daiku-interior.test` (Laragon vhost, root = `public/`) or
  `php artisan serve`.
- DB: MySQL via Laragon, `root` / no password, database `daiku_interior`.
  If `mysqld`/`nginx` aren't responding, Laragon's tray app may need a
  restart — see the persisted session memory for manual recovery steps if
  needed.
- No local Redis — `CACHE_STORE`/`QUEUE_CONNECTION` are `database` for
  now (documented inline in `.env`); flip to `redis` once
  `docker compose up -d redis` (or a native install) is available.
- `npm run build` = `tsc && vite build` — must pass clean; this is also
  what CI (`.github/workflows/ci.yml`) runs.
- `php artisan test` (Pest) — target ≥70% coverage per PRD §10.3.

## Language

Code/comments in English. Anything **user-facing** — UI text, validation
messages, notifications — in **Bahasa Indonesia** (PRD §1.4).
