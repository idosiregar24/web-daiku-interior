# Rencana Implementasi — Daiku Interior Enterprise System

Rencana kerja ini diturunkan langsung dari [`Daiku-Task-Schedule.csv`](../File%20Skema/Daiku%20v1.0.0/Daiku-Task-Schedule.csv) (v1.0.0, 7 sprint / 13 minggu / 4 bulan, 2 developer) dan disilangkan dengan [`PRD-Daiku-Interior-System.md`](../File%20Skema/Daiku%20v1.0.0/PRD-Daiku-Interior-System.md) untuk detail spesifikasi tiap modul. Setiap file `sprint-0N.md` di folder ini adalah checklist yang bisa dicentang langsung seiring progres — CSV asli tetap jadi sumber kebenaran urutan/estimasi, file di sini adalah working copy yang hidup (living checklist).

## Status saat ini

Fase **Foundation** (setup stack sesuai PRD §3) sudah dikerjakan di luar urutan sprint CSV (dilakukan sekaligus di awal agar semua developer punya base yang sama). Rincian per-task ada di `sprint-01.md` (butir-butir Setup minggu 1) yang ditandai **Selesai**/**Sebagian**.

## Peta Sprint

| Sprint | Minggu | Bulan | Fokus Modul | Status | File |
|---|---|---|---|---|---|
| Sprint 1 | Week 1–Week 2 | Bulan 1 | Setup, Auth, CRM, Projects, Finance | 20 selesai / 1 sebagian / 3 belum (24) | [sprint-01.md](sprint-01.md) |
| Sprint 2 | Week 3–Week 4 | Bulan 1 | CRM, Design, Projects, Quotation, Tasks | 19 selesai / 1 sebagian / 0 belum (20) | [sprint-02.md](sprint-02.md) |
| Sprint 3 | Week 5–Week 6 | Bulan 2 | Quotation, CRM, Review, Tasks, DailyForm, Penalty, Overtime | 0 selesai / 0 sebagian / 20 belum (20) | [sprint-03.md](sprint-03.md) |
| Sprint 4 | Week 7–Week 8 | Bulan 2 | QA, Projects, Tasks, Finance | 0 selesai / 0 sebagian / 20 belum (20) | [sprint-04.md](sprint-04.md) |
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
  `sisa_piutang` column, and `bank_account_id` — the shipped migration has
  none of these, `status` is a bare placeholder string.
- `finance_transactions` in the SQL file has `bank_account_id`, `kategori`
  (21-value ENUM matching PRD §4.7 exactly), `qty`, `unit_price` — the
  shipped migration is much closer to PRD §5.1's bare sketch.
- Tables that exist in the SQL file but not anywhere in this codebase yet:
  `staff_loans`, `staff_loan_payments`, `supplier_debts`,
  `supplier_debt_payments`, `finance_allocation_configs`, `audit_logs`,
  `project_materials`, `design_staff`, `quotations`, `quotation_items`,
  `quotation_approvals`, `designs`. `tasks.kendala`/`tasks.note` also exist
  directly on the SQL file's `tasks` table; the shipped migration only has
  them on `daily_task_forms`.

**None of this has been retroactively applied** — the already-shipped Lead
module and its tests stay on bigint PKs and the current column set. Before
building any module whose tables aren't done yet (Design, Quotation,
Finance, Overtime, QA), **read `daiku_schema.sql` for that module first**
and prefer it over PRD §5.1's sketch where they disagree — it's more
detailed and was authored by the same person as the PRD. The PK-strategy
question (ULID vs bigint) needs an explicit decision before Sprint 2 starts
building real modules on top of `leads`/`projects`/`tasks`, since retrofitting
it later only gets more expensive.
