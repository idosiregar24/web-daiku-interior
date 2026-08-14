---
name: laravel-inertia-module
description: Use when scaffolding a new Daiku Interior module end-to-end (migration → model → service → form request → controller → route → Inertia page) — e.g. starting CRM leads, Design, Quotation, Projects, Tasks, Overtime, QA, Finance, or Logistics from PRD §4. Keeps every module structured the same way instead of each one drifting into its own pattern.
---

# Laravel + Inertia Module Skill

This is the repeatable recipe for turning one row of
`.claude/plan/sprint-0N.md` (or one module section of PRD §4) into working
code. Follow the order below — each step depends on the previous one.
Cross-reference `.claude/rules/backend-standards.md`,
`database-standards.md`, and `security-standards.md` for the *why*; this
file is the *in what order*.

## 0. Read first

- The relevant PRD §4.x section (features + business rules) for the module.
- The relevant PRD §5 schema block for the tables involved.
- The relevant RBAC row in PRD §7.1 for who can Create/Read/Update/Delete.

## 1. Migration(s)

```bash
php artisan make:migration create_leads_table
```

- Table/column names exactly as PRD §5 sketches them (don't rename for
  taste — other modules' FKs and the CSV notes assume PRD's names).
- Status columns: `string`, not MySQL `ENUM` (see `database-standards.md`
  §2).
- Add indexes for columns that'll be filtered/sorted on a list page
  (`database-standards.md` §5).
- Run it locally: `php artisan migrate` (targets the `daiku_interior`
  MySQL database already configured in `.env`).

## 2. Model

`app/Models/{Model}.php` — flat namespace, not per-module (see
`backend-standards.md` §2).

- Explicit `$fillable`.
- `casts()` for status columns → PHP enum (create `app/Enums/{X}Status.php`
  if it doesn't exist yet; the string values must match the TypeScript
  union in `resources/js/types/index.d.ts` exactly).
- Relations per PRD §5.2 diagram.
- Query scopes for the filters the index page will need
  (`scopeByStatus`, `scopeByPriority`, `scopeOverdue`, etc.).

## 3. Form Request(s)

`app/Http/Requests/{Modul}/Store{Model}Request.php` (+ `Update...` if
different rules apply). All validation lives here — see
`security-standards.md` §3. Mirror these rules in the frontend Zod schema
later (step 6) — don't let them drift apart.

## 4. Service

`app/Services/{Modul}Service.php` — only if the module has business logic
beyond plain CRUD (state transitions, calculations, cross-model
side-effects). Simple lookup tables (e.g. read-only Material list) can skip
this and let the controller call the model directly — don't force a
Service that just forwards to Eloquent.

Business rules from PRD §6 (state machines) belong here, e.g.:
- Lead → LOST requires a reason (PRD §4.1).
- Quotation approval must be CEO-then-PM, sequential (PRD §4.3).
- QA rejection twice in a row notifies the CEO (PRD §4.6).

## 5. Controller

`app/Http/Controllers/{Modul}/{Model}Controller.php` — thin (see
`backend-standards.md` §1):

```php
public function index(Request $request)
{
    return Inertia::render('{Modul}/Index', [
        'items' => Lead::query()
            ->byStatus($request->status)
            ->with(['assignedTo'])
            ->latest()
            ->paginate(20),
    ]);
}
```

## 6. Route

Add to `routes/web.php` inside a role-gated group (see
`backend-standards.md` §3). Route names: `{modul}.{resource}.{action}`.

## 7. Inertia page(s)

`resources/js/Pages/{Modul}/{Action}.tsx` — see
`.claude/rules/frontend-standards.md` for the page/form/table conventions,
and `.claude/skills/front-end-design/SKILL.md` for visual conventions.
Zod schema for the create/edit form mirrors the Form Request from step 3.

## 8. Wire up navigation

Once the `index` route exists, give its `NAV_GROUPS` entry in
`resources/js/Layouts/AppLayout.tsx` a real `routeName` (it currently
renders disabled/"Segera" for modules that aren't built yet) — don't
leave a working module unreachable from the sidebar.

## 9. Tests

Pest feature test for the controller (happy path + one RBAC-denied case),
unit test for any Service method with a business rule. Target ≥70%
coverage per PRD §10.3.

## 10. Update the plan

Tick the corresponding checkbox in `.claude/plan/sprint-0N.md` (with a
short status note if only partially done) — the plan files are meant to
stay a living, accurate checklist, not a static copy of the CSV.
