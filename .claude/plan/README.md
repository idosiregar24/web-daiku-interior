# Rencana Implementasi — Daiku Interior Enterprise System

Rencana kerja ini diturunkan langsung dari [`Daiku-Task-Schedule.csv`](../File%20Skema/Daiku%20v1.0.0/Daiku-Task-Schedule.csv) (v1.0.0, 7 sprint / 13 minggu / 4 bulan, 2 developer) dan disilangkan dengan [`PRD-Daiku-Interior-System.md`](../File%20Skema/Daiku%20v1.0.0/PRD-Daiku-Interior-System.md) untuk detail spesifikasi tiap modul. Setiap file `sprint-0N.md` di folder ini adalah checklist yang bisa dicentang langsung seiring progres — CSV asli tetap jadi sumber kebenaran urutan/estimasi, file di sini adalah working copy yang hidup (living checklist).

## Status saat ini

Fase **Foundation** (setup stack sesuai PRD §3) sudah dikerjakan di luar urutan sprint CSV (dilakukan sekaligus di awal agar semua developer punya base yang sama). Rincian per-task ada di `sprint-01.md` (butir-butir Setup minggu 1) yang ditandai **Selesai**/**Sebagian**.

**Demo data (added 2026-08-17):** `database/seeders/DemoDataSeeder.php` walks
the entire Sprint 1–3 business process — Lead→Design→Client ACC→Quotation
RAB→dual approval→confirmDeal→Project→Milestone→Task→DailyForm→Penalty→
FamilyGatheringFund→Overtime — through the real Service layer (not raw
`::create()`), so `php artisan migrate:fresh --seed` gives a UI with
something real to click through: 11 leads spanning every pipeline stage
(incl. one overdue follow-up and one LOST), 8 designs, 5 quotations at
every approval stage, 2 projects with milestones/tasks in different
states, and one of each Overtime outcome (PENDING/APPROVED_PM/
APPROVED_FINANCE/REJECTED). Registered in `DatabaseSeeder` alongside a new
`MasterDataSeeder` (branches/lead categories/bank accounts). Same
"local/staging/UAT only, never production" caveat as `DEMO_USERS`. Login
as `marketing@daikuinterior.com` / `password` (or any other seeded role
email) to browse it.

## Peta Sprint

| Sprint | Minggu | Bulan | Fokus Modul | Status | File |
|---|---|---|---|---|---|
| Sprint 1 | Week 1–Week 2 | Bulan 1 | Setup, Auth, CRM, Projects, Finance | 20 selesai / 1 sebagian / 3 belum (24) | [sprint-01.md](sprint-01.md) |
| Sprint 2 | Week 3–Week 4 | Bulan 1 | CRM, Design, Projects, Quotation, Tasks | 19 selesai / 1 sebagian / 0 belum (20) | [sprint-02.md](sprint-02.md) |
| Sprint 3 | Week 5–Week 6 | Bulan 2 | Quotation, CRM, Review, Tasks, DailyForm, Penalty, Overtime | 19 selesai / 1 sebagian / 0 belum (20) | [sprint-03.md](sprint-03.md) |
| Sprint 4 | Week 7–Week 8 | Bulan 2 | QA, Projects, Tasks, Finance | 20 selesai / 0 sebagian / 0 belum (20) | [sprint-04.md](sprint-04.md) |
| Sprint 5 | Week 9–Week 10 | Bulan 3 | Logistics, Notifications, Analytics | 0 selesai / 1 sebagian / 26 belum (27) | [sprint-05.md](sprint-05.md) |
| Sprint 6 | Week 11–Week 12 | Bulan 3 | Analytics, Logistics, Projects, Tasks, Testing | 0 selesai / 0 sebagian / 21 belum (21) | [sprint-06.md](sprint-06.md) |
| Sprint 7 | Week 13 | Bulan 4 | UAT, Setup, Bugfix, Security, Docs | 0 selesai / 1 sebagian / 9 belum (10) | [sprint-07.md](sprint-07.md) |

## Legenda checklist
- `[x]` — Selesai
- `[ ]` dengan catatan 📌 **Status: Sebagian** — sudah ada progres nyata, belum tuntas
- `[ ]` tanpa catatan — belum dikerjakan

## Catatan penyesuaian terhadap CSV
- **Database:** CSV menyebut "postgres" di catatan task Docker Compose (baris Sprint 1), tapi PRD §3.2 dan skema yang sudah dibangun memakai **MySQL** — dokumen ini mengikuti PRD/implementasi nyata.
- **Tanggal:** semua tanggal di CSV adalah placeholder dari draft awal (mulai 2025-01-06, sudah lewat). Gunakan sebagai urutan Week N relatif terhadap tanggal mulai sprint yang sesungguhnya, bukan tanggal absolut.
- **Role & auth foundation** (Telescope, Horizon, DomPDF, Laravel Excel, Predis) sudah ter-install lebih awal sebagai bagian instalasi stack (PRD §3.2), meski tidak ada baris CSV khusus untuk itu.
- **TanStack Table:** di-pin ke **v8.21.3** (bukan v9 yang ter-install otomatis oleh `npm install` saat "Latest"). v9 adalah major rewrite dengan API berbeda total (`createCoreRowModel` dkk., bukan lagi `useReactTable`/`getCoreRowModel`) dan dokumentasi/tutorial komunitas masih sangat minim saat ini — v8 dipilih supaya tim developer bisa mengikuti dokumentasi resmi & tutorial yang sudah mapan.
- **CRM – Lead write access:** PRD §7.1's matrix cell says MARKETING has CRUD and CEO only R, but §4.1's business rules explicitly say "Hanya Marketing/Sales dan CEO yang bisa membuat/edit lead" — the more specific prose rule was followed (`role:CEO|MARKETING` on the write routes), not the summary matrix cell.
- **`bank_account_id` on `finance_transactions`:** PRD §4.7 requires every transaction to reference a bank account, but no `bank_accounts` table exists in §5.1's schema sketch. Deferred to a follow-up migration in Sprint 4 when the Finance module actually designs that table, rather than guessing its shape now.

## SUPERADMIN role + Data Master module (added 2026-08-15, outside the CSV/PRD)

Requested directly by the user, not from PRD §7.1 or the CSV. Two additions:

1. **`SUPERADMIN` role** (`database/seeders/RoleSeeder.php`) — a technical
   admin role, not a PRD business role. It's **god-mode**: unconditional
   access to every `role:`-gated route via a custom
   `app/Http/Middleware/RoleMiddleware.php` that wraps Spatie's and bypasses
   the check entirely when `hasRole('SUPERADMIN')` — register this alias in
   `bootstrap/app.php` (`'role' => AppRoleMiddleware::class`), not Spatie's
   raw `RoleMiddleware`, or the bypass silently stops working.
2. **Data Master module** (`app/Http/Controllers/MasterData/`, route prefix
   `master-data`, SUPERADMIN-only) — CRUD for reference/lookup tables other
   modules will point to by ID: **Branches** (Cabang), **Lead Sources**,
   **Lead Categories**, **Bank Accounts**. `leads.source`/`leads.category`
   themselves are still plain strings (not FKs into these new tables) —
   wiring that up is a separate, not-yet-done follow-up that touches the
   already-shipped CRM Lead module.
3. **Site Settings** (`app/Http/Controllers/Settings/SiteSettingController.php`,
   route `settings`, **CEO + SUPERADMIN**) — a singleton `site_settings` row
   (`App\Models\SiteSetting::current()`, get-or-create) holding general
   company/application profile (name, address, phone, email, logo URL).
   Not the same thing as PRD §4.7's `finance_allocation_configs`
   (percentage allocations, CEO **+ FINANCE**, not SUPERADMIN) — that's a
   distinct Finance-module concept and hasn't been built.

## Sprint 2 Week 3 — CRM/Design/Projects backend + UI (done 2026-08-15)

- **Lead pipeline CLOSING is now a guarded transition.** `LeadService::changeStatus()`
  (used by `crm.leads.updateStatus`) refuses `CLOSING` outright — that
  status is only reachable through `LeadService::confirmDeal()`
  (`crm.leads.confirmDeal`), which closes the lead **and** creates its
  Project in one DB transaction (PRD §4.4 "Project hanya bisa dibuat dari
  Lead yang berstatus DEAL"). Without this guard a plain status update
  could leave a CLOSING lead with no Project behind it. `UpdateLeadStatusRequest`
  also excludes `CLOSING` from its `in:` rule so the rejection happens at
  validation, not just in the service.
- **Quotation-gate on confirmDeal is still deferred.** PRD §4.3 actually
  requires the Quotation to be `APPROVED` before a deal can close;
  Quotation approval isn't built until Sprint 2 Week 4/Sprint 3, so
  `confirmDeal()` only checks the Lead's own status (`DEAL_DESAIN`) for
  now — add the Quotation check here once that module ships (see the
  docblock on `LeadService::confirmDeal()`).
- **Design module is backend-only this sprint**, per the CSV task wording
  ("DesignController + Design model + relasi Lead", no UI task until Week
  4). `Design::store` (`crm.leads.design.store`, `role:DESIGNER`) exists
  and is tested, but there's no "Buka Desain" button anywhere in the CRM
  UI yet — deliberately deferred to Week 4's brief-form page rather than
  building a throwaway trigger now.
- **Follow-up "notif" half of the CRM task wasn't built** — see the
  📌 Sebagian note on that task in `sprint-02.md`. No other module needs
  Echo/Soketi notification infra yet, so it's deferred rather than
  building it standalone for one dashboard widget.
- **TaskPolicy (task immutability, CLAUDE.md golden rule #6) is still
  Week 4's job** — `Task` model and `Project::tasks()` exist and are
  shown read-only on the Project detail page's Task tab, but there's no
  `TaskController` yet to attach the policy to (task creation/status
  update UI is explicitly a Week 4 CSV task).
- **Project's "Finance" detail tab is a placeholder** — Finance module
  doesn't exist yet (Sprint 4+), the tab just says so rather than being
  omitted, matching the CSV's explicit "layout tab (Overview, Milestone,
  Task, Finance)" wording for the page structure.
- Pagination controls are still not rendered on any index page (Lead,
  Project) — carried over from Sprint 1's Lead index, not something this
  sprint introduced or fixed.

## Sprint 2 Week 4 — Design brief UI + Client ACC + Quotation RAB builder (done 2026-08-15, Ido's tasks only)

- **QuotationStatus's SUBMITTED→CEO_REVIEW→PM_REVIEW→SENT_TO_CLIENT nuance
  deliberately left unresolved this week.** PRD §4.3 lists 7 status values
  in sequence but never states which transitions are "awaiting X" vs
  "X already happened" — genuinely ambiguous prose. Since
  `.claude/plan/sprint-03.md` Week 5 owns the actual approval flow
  ("Quotation approval flow: CEO approve → PM approve (sequential)"),
  `QuotationService` only implements DRAFT→SUBMITTED (`submit()`) this
  week; resolving the CEO_REVIEW/PM_REVIEW semantics is deferred to
  whoever builds that flow, not decided prematurely here. See
  `QuotationStatus`'s docblock.
- **Design status has no validated transition graph**, unlike
  `LeadService::changeStatus()`. PRD describes the 13 `DesignStatus`
  values as a pipeline but no business rule ties specific transitions to
  specific actors the way Lead's pipeline does — `DesignController::update()`
  accepts any status value in one plain field update (same pattern as
  `MilestoneService::update()`). `clientAcc()` is the one transition that
  **is** guarded (must be `WAITING_ACC_DESAIN`, must not already be
  ACC'd) since PRD is explicit about that one.
- **`client_acc` → `GAMBAR_RAB` skips resting at `ACC_DESAIN`.** PRD:
  "Client ACC: Konfirmasi ACC desain → trigger ke tahap Gambar RAB →
  Penawaran" — read as one atomic step (ACC confirmation directly opens
  the RAB stage) rather than two separate manual transitions.
- **No daily `delay_hari` recalculation job.** PRD §4.2 "Sistem hitung
  `delay_hari` otomatis setiap hari" describes a scheduled job; not built
  this week (not itemized in the Week 4 CSV tasks) — `designs.delay_hari`
  stays at its migration default (0) until that job exists. `deadline` IS
  computed (on create/update, from `start_date + target_hari`), just not
  the daily delay check against it.
- **`quotation_approvals` table exists but nothing writes to it yet** —
  created this week per the migration task, consumed starting Week 5.
  `approver_role` stores `'CEO'`/`'PM'` (matching this codebase's Spatie
  role slugs), not `daiku_schema.sql`'s literal `'PROJECT_MANAGER'`.
- **Bug caught during testing, not left in:** `DesignController::clientAcc()`
  originally read `$design->quotation` to get the redirect target —
  `Design` has no such relation (Quotation relates to `Lead`, not
  `Design`, both being lead_id siblings). Fixed to go through
  `$design->lead->quotation`; a feature test now covers the full
  clientAcc → Quotation redirect path so this can't silently regress.

## Sprint 2 Week 4 continued — "Desain"/"Quotation" nav bug fix + Jonathan's Tasks module (done 2026-08-15)

- **Root cause of "fitur Design belum terbuka":** `Design/Show.tsx` and
  `Quotation/Show.tsx` (built earlier the same day) were only reachable by
  URL — the sidebar's "Desain"/"Quotation" entries had no `routeName`, so
  per CLAUDE.md golden rule #8 they always rendered disabled ("Segera"),
  making genuinely-working pages look unbuilt. Fixed by adding
  `DesignController::index()`/`QuotationController::index()` (list pages,
  `Design/Index.tsx`/`Quotation/Index.tsx`) and wiring both nav entries to
  them — the same fix was needed on both, not just Design.
- **First Policy in the codebase (`TaskPolicy`).** Laravel 11's default
  `Controller` skeleton doesn't include `AuthorizesRequests`, so
  `$this->authorize()` didn't exist yet — added the trait to the base
  `app/Http/Controllers/Controller.php`. Also added a `Gate::before` in
  `AppServiceProvider` so `SUPERADMIN` bypasses every Policy the way it
  already bypasses `role:` route middleware — without it, SUPERADMIN would
  have been blocked by `TaskPolicy::updateStatus()`'s ownership check,
  contradicting its god-mode design (see the SUPERADMIN section above).
  Future Policies (`Project::view()`, `Penalty::view()` — flagged in
  security-standards.md §2) get this bypass for free.
- **`tasks.kendala`/`tasks.note` columns added this week** — the original
  Week 3 tasks migration's own docblock already promised "hanya
  status/kendala/note yang bisa diubah" but never actually added those two
  columns (daiku_schema.sql has them; flagged in the Schema discovery
  section below since Week 3). Added via a follow-up migration now that
  `TaskController::updateStatus()` is the feature that actually needs them.
- **`OVER` is not a manually-selectable task status** — PRD: "Status OVER
  otomatis diset oleh sistem jika task belum DONE melewati due_date".
  `UpdateTaskStatusRequest` excludes it from the allowed values; no
  scheduled job sets it automatically yet (same deferral pattern as
  `designs.delay_hari` above — not itemized as a Week 4 CSV task).
- **Task creation vs. status update deliberately split across two pages:**
  `Projects/Show.tsx`'s Task tab (PM, project-scoped — mirrors how
  Milestones are created there) vs. the new global `Tasks/Index.tsx`
  (PRD §4.5 "Task List: Tukang melihat daftar task yang di-assign ke
  mereka" — Field Staff's actual daily view, filterable by
  milestone/assignee/status for PM/CEO).
- **Milestone timeline** (`Components/modules/projects/MilestoneTimeline.tsx`)
  is a vertical connected-dot layout, not horizontal — reads the same at
  any viewport width with plain Tailwind, no charting library needed.

## Sprint 3 (Week 5–6) — Quotation approval/PDF, CRM dashboard, DailyForm, Penalty/Family Fund, Overtime (done 2026-08-16/17)

Full sprint (both developers' tasks), see `sprint-03.md` for the per-task
checklist. Notable decisions and **real bugs the test suite caught before
they shipped**:

- **Quotation's CEO_REVIEW/PM_REVIEW ambiguity (flagged unresolved in
  Sprint 2 Week 4) is now resolved.** `QuotationService::ceoDecision()`/
  `pmDecision()` use the same "state = last completed gate" + single
  entry-point-per-gate pattern as `LeadService`'s CLOSING guard — PM's
  gate is only reachable via `CEO_REVIEW`, which only CEO's approval
  produces, so "CEO dulu, baru PM" (security-standards.md §4's own
  example) is enforced by the state machine itself. `PM_REVIEW` stays
  unpersisted, same simplification as `SUBMITTED`.
- **`OvertimeStatus` follows the already-shipped migration's 4-state
  comment** (`PENDING/APPROVED_PM/APPROVED_FINANCE/REJECTED`), not
  daiku_schema.sql's 5-state ENUM (`PENDING_PM/APPROVED_PM/PENDING_FINANCE/
  APPROVED_FINANCE/REJECTED`) or the PRD flow-chart's own naming — that
  decision predates this sprint (visible in the migration comment itself)
  and is kept rather than re-litigated.
- **`overtime_requests.reject_note` added** — daiku_schema.sql has it,
  the shipped migration didn't; added because OvertimeService's reject
  actions are the feature that actually needs it (same pattern as
  `tasks.kendala`/`note` in Sprint 2 Week 4).
- **`FinanceTransaction` model created minimally** — just enough for
  `OvertimeService::financeDecision()` to write one EXPENSE row
  (`type='OVERTIME_PAY'`) per PRD §6.6. The full Finance module (multi-
  rekening, termin, staff loans, the `type`+`kategori` schema split
  daiku_schema.sql actually uses) is still Sprint 4+ — not built here.
- **Four real bugs found by writing tests, fixed before shipping:**
  1. `PenaltyController::index()` mixed `?:` and `? :` without
     parentheses — a PHP fatal parse error that would have 500'd on
     every request. (PHP disallows unparenthesized mixing of the two.)
  2. `DailyTaskForm` model declared `UPDATED_AT = null` but the migration
     has *neither* `created_at` nor `updated_at` (only `submitted_at`) —
     needed `public $timestamps = false` instead, or every insert failed.
  3. **The big one:** `work_date`/`date_occurred` are cast to `'date'` on
     their models, which Eloquent serializes with a midnight time
     component (`"2026-08-17 00:00:00"`) when *writing* — but three
     separate places compared them with a plain `where('col', $isoDate)`
     against a bare `"2026-08-17"` string, which **silently never
     matched**. In `DailyTaskFormService::store()` this meant the
     one-form-per-task-per-day check never caught real duplicates (the
     DB's unique constraint would have thrown a raw 500 instead of a
     friendly validation error); in `PenaltyService::runDailyCheck()` it
     meant the "already submitted today" and "already penalized today"
     checks would **always miss**, so the daily job would have
     re-penalized every staff member with an active task on every single
     run rather than being idempotent (directly contradicting
     backend-standards.md §5's explicit requirement). Fixed by switching
     all three to `whereDate()`. Caught only because
     `DailyTaskFormTest`/`PenaltyTest` asserted the *behavior*
     (duplicate rejected, idempotent re-run) rather than just "the
     request succeeded."
  4. `FamilyGatheringFund`'s table is `family_gathering_fund`
     (**singular** — see the Sprint 1 migration filename itself), but
     Eloquent's default pluralization guess is `family_gathering_funds`.
     Needed an explicit `protected $table = 'family_gathering_fund'`.
- **"Code review Jonathan Sigalingging" tasks adapted, not skipped** —
  there's no separate colleague in this solo-agent workflow, so both
  instances became a self-review pass (comprehensive tests — the four
  bugs above are its direct output) plus, for Week 6, an actual
  `tests/Feature/PresalesIntegrationTest.php` walking the full
  Lead→Design→Quotation→Deal chain in one test (passed first try). See
  `sprint-03.md` for the per-task notes.

## Sprint 4 (Week 7–8) — QA, Progress Log, Task overdue, Termin, Finance Transaction (done 2026-08-17)

Full sprint (both developers' tasks), see `sprint-04.md` for the per-task
checklist. Notable decisions/deviations:

- **QA business rule read carefully off PRD §6.3's flow chart**: PM's
  "mark milestone done" action does **not** set `Milestone.status` to
  `COMPLETED` directly — it sets `QA_WAITING` (an enum value that already
  existed since Sprint 2) and auto-creates the `QaForm`
  (`MilestoneService::markDone()`). Only QA's approval
  (`QaFormService::review()`) sets `COMPLETED` and auto-advances the next
  milestone by `order` — the "blocking mechanism" CSV task is this state
  gate, not a separate Policy (no ownership check applies here — QA Form
  access is role-only per the §7.1 matrix, unlike Task/Project which do
  need Policies).
- **QA checklist is one fixed generic list**, not "configured per
  milestone type" (PRD's own wording) — no milestone-type taxonomy exists
  anywhere in the codebase (`Milestone` only has name/target_date/status/
  order), so inventing a per-type config system nobody asked for would
  have been scope creep. Documented as a deliberate simplification in
  `QaFormService`'s docblock.
- **Minimal DB-backed (non-real-time) notifications module** built now,
  scoped explicitly away from the full PRD §4.9 Echo/Soketi broadcast
  layer (that's its own Sprint 5 module) — built only because two Sprint 4
  triggers (QA rejected-twice → CEO, Termin H-3 reminder → Finance)
  needed a concrete "notify X" deliverable. Refreshes on Inertia
  navigation (`HandleInertiaRequests` shares unread notifications), not
  push. The bell in `AppLayout.tsx`'s Topbar (previously a static "Belum
  ada notifikasi" placeholder since Sprint 1) is wired to it now too.
- **`finance_transactions` reconciled with `daiku_schema.sql`'s `type` +
  `kategori` split**, as explicitly earmarked by the Sprint 1 migration's
  own comment ("Sprint 4"). `type` now only ever holds
  `PEMASUKAN`/`PENGELUARAN` (`App\Enums\FinanceTransactionType`);
  `kategori` (`App\Enums\FinanceCategory`) carries the finer PRD §4.7
  classification. `OvertimeService::financeDecision()`'s existing write
  updated to match (was `type='OVERTIME_PAY'`, now
  `type=PENGELUARAN, kategori=LEMBUR_BONUS`). `bank_account_id` added
  too, but left **nullable at the DB level** even though PRD §4.7 says
  "wajib" — enforced instead at the `StoreFinanceTransactionRequest`
  layer for manually-created transactions, so OvertimeService's existing
  write (which doesn't collect a bank account) doesn't break.
- **`Termin`/`ProgressLog`/`QaForm` migrations already existed** (dated
  2026-08-15, part of the early full-schema scaffolding — see "Schema
  discovery" below) but with drift from `daiku_schema.sql`: `progress_logs`
  had `design_urls` instead of `ref_urls` (copy/paste from
  `designs.design_urls`), `termins` was missing `bank_account_id`. Both
  fixed via follow-up migrations (drop+add, not `renameColumn()` — the
  columns were still empty/unused, and `renameColumn()` needs
  doctrine/dbal, which isn't installed) rather than editing the
  already-committed originals in place. `qa_forms` needed no fix — it
  already matched exactly.
- **`termins`' `dp_amount`/`pelunasan`/generated `sisa_piutang`** from
  `daiku_schema.sql` deliberately **not** added — the Sprint 4 task list
  only asked for the Sabtu-schedule + 100%-validation + mark-paid flow,
  deeper partial-payment tracking wasn't asked for this sprint.
- **Termin's percentage-100% rule enforced as a ceiling**, not "must
  total exactly 100% before any termin is usable" — `TerminService::create()`
  rejects a new termin if the project's existing total + the new
  percentage would exceed 100%, but a project mid-way through scheduling
  (e.g. only 30% scheduled so far) is a normal, allowed state.
- **A termin tied to a milestone stays locked until that milestone is
  `COMPLETED`** (PRD §6.3 "Termin Sabtu unlocked... Finance bisa generate
  invoice" once QA approves) — enforced in `TerminService::markPaid()`,
  not on PDF export (a draft invoice preview before "unlock" is a
  lower-stakes read action, left open).
- **PM has no dedicated Termin list route** — PRD §7.1's "Finance – Termin"
  row gives PM only `C` (create), not `R`. PM instead sees/schedules
  termins for a project they manage through that project's own Finance
  tab (`ProjectController::show()`'s `termins` prop), while
  `finance.termins.index` (the CSV's "Termin list page Finance") stays
  CEO+Finance only, matching the matrix literally.
- **Cash flow dashboard aggregates in PHP, not SQL `DATE_FORMAT()`** —
  the obvious MySQL grouping query breaks `phpunit.xml`'s SQLite test
  connection (`database-standards.md` §1's "no engine-specific features"
  rule, caught by the dashboard's own smoke test before it shipped).
  Grouped with a `Collection::groupBy()` on the fetched rows instead.
- **Staff payment "already paid" derived, not a new `Task` column** — a
  DONE task's wage is considered paid once a `FinanceTransaction` exists
  with `kategori=GAJI_KARYAWAN` and `reference_id=task.id`
  (`FinanceTransactionService::isTaskPaid()`), rather than adding an
  `is_paid`/`paid_at` column to `Task` — keeps `Task` immutable per
  CLAUDE.md golden rule #6 (nothing here ever writes to it).
- **Two explicit UI redesigns, done alongside the 20 CSV tasks** (not
  itemized in the CSV, requested directly this session):
  1. **Milestone tab** — `MilestoneGanttCalendar.tsx`, a horizontal
     Gantt-style timeline plotted against real calendar months (percentage
     positioning, plain HTML/CSS — Recharts has no Gantt primitive),
     replacing the vertical dot-timeline `MilestoneTimeline.tsx` (deleted,
     in git history if ever needed again). Each milestone's "phase" spans
     from the previous milestone's `target_date` (or the project's
     `start_date` for the first) to its own — the only sequential reading
     of a duration `Milestone` supports, since it has no `start_date` of
     its own.
  2. **Task tab** — grouped into one table per assignee
     (`TaskAssigneeTable`, `useMemo`-derived from the flat `tasks` prop in
     `Projects/Show.tsx`) instead of a single flat table, with an
     "Belum Ditugaskan" bucket for unassigned tasks.
- **`DemoDataSeeder` extended** to walk the first demo project's first two
  milestones through the *real* QA pipeline (one genuine APPROVE, one
  genuine REJECT left at `rejection_count=1`) instead of writing their
  final status directly, plus Progress Logs, a paid + a still-locked
  Termin, and two more `FinanceTransaction` rows (a manual expense, one
  staff wage payment) — see `sprint-04.md`'s own notes section for the
  full narrative.

## ⚠️ Schema discovery: `daiku_schema.sql` (found 2026-08-15, not yet reconciled)

While building the above, the user pointed at
[`File Skema/Daiku v1.0.0/daiku_schema.sql`](../File%20Skema/Daiku%20v1.0.0/daiku_schema.sql)
— a **30-table SQL schema** more detailed than PRD §5.1's prose sketch, and
in places genuinely different from what's been built so far:

- **Primary keys are `VARCHAR(26)` (ULID-style)**, not the `bigint` auto-increment
  every migration in this codebase currently uses. Switching now would touch
  every table and FK already shipped (Lead, User, RBAC, tests) — **not done**,
  flagging for a deliberate decision rather than a silent rewrite.
- `leads.source`, `leads.category`, `leads.gender`, and a `leads.layanan`
  column are all `ENUM`s in the SQL file; the shipped `leads` table has
  `source`/`category` as free strings and has no `layanan`/`gender` split
  the same way.
- `overtime_requests.status` has 5 states (`PENDING_PM`/`APPROVED_PM`/
  `PENDING_FINANCE`/`APPROVED_FINANCE`/`REJECTED`); the shipped migration
  only has 4 (missing the distinct `PENDING_FINANCE` state).
- `termins` in the SQL file has `dp_amount`, `pelunasan`, a generated
  `sisa_piutang` column — **still not added** (deliberately, see Sprint 4's
  notes above: not asked for this sprint). `bank_account_id` and a real
  `status` state machine (`App\Enums\TerminStatus`) **were** added in
  Sprint 4, closing that part of the gap.
- `finance_transactions` in the SQL file has `bank_account_id`, `kategori`
  (21-value ENUM matching PRD §4.7 exactly) — **both added in Sprint 4**
  (`App\Enums\FinanceCategory`, `App\Enums\FinanceTransactionType`). `qty`/
  `unit_price` (line-item detail on a transaction) are **still not added**
  — nothing built so far needs a per-transaction line-item breakdown.
- Tables that still exist in the SQL file but not anywhere in this
  codebase yet: `staff_loans`, `staff_loan_payments`, `supplier_debts`,
  `supplier_debt_payments`, `finance_allocation_configs`, `audit_logs`,
  `project_materials`. (`design_staff`, `quotations`, `quotation_items`,
  `quotation_approvals`, `designs` — listed here as missing when this
  section was first written — were all built in Sprint 2/3 and no longer
  apply.) `tasks.kendala`/`tasks.note` now exist on `tasks` directly too
  (added Sprint 2 Week 4), matching the SQL file — not just on
  `daily_task_forms` as this bullet originally noted.

**None of this has been retroactively applied** — the already-shipped Lead
module and its tests stay on bigint PKs and the current column set. Before
building any module whose tables aren't done yet (Design, Quotation,
Finance, Overtime, QA), **read `daiku_schema.sql` for that module first**
and prefer it over PRD §5.1's sketch where they disagree — it's more
detailed and was authored by the same person as the PRD. The PK-strategy
question (ULID vs bigint) needs an explicit decision before Sprint 2 starts
building real modules on top of `leads`/`projects`/`tasks`, since retrofitting
it later only gets more expensive.
