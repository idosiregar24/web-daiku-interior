---
name: front-end-design
description: Use when building or restyling any Daiku Interior UI — a new Inertia page, a shadcn component variant, a dashboard widget, a status badge, or any layout work in resources/js/. Applies the Daiku Yellow design system (PRD §8) and the project's Tailwind v4 + shadcn/ui "Nova" setup consistently instead of improvising colors/spacing per page.
---

# Front-End Design — Daiku Interior

Practical, step-by-step companion to
`.claude/rules/design-standards.md` (token reference) and
`.claude/rules/frontend-standards.md` (code structure). Read this when
you're about to write or touch UI, not just component logic.

## Before writing any markup

1. **Check `resources/css/app.css` first**, not memory. Two `@theme`
   blocks matter:
   - `@theme inline { --color-primary: var(--primary); ... }` — semantic
     tokens (`bg-primary`, `bg-background`, `border-border`, etc.) that
     shadcn components already consume. `--primary` is Daiku Yellow.
   - `@theme { --color-daiku-yellow: #f5c518; ... }` — raw brand palette
     for cases semantic tokens don't cover (e.g. `bg-daiku-yellow-light`
     for a highlight, `text-daiku-muted` for secondary text outside a
     card/form context).
2. **Never hardcode a hex color or a default Tailwind gray/blue/etc.**
   (`text-gray-500`, `bg-blue-100`). If a token doesn't exist yet for what
   you need, add it to `app.css` — don't invent an inline value.
3. Decide layout first: authenticated page → `<AppLayout>`, auth flow page
   → `<AuthLayout>`. Never hand-roll a sidebar/topbar/centered-card shell.

## Building a new page

1. Start from an existing sibling page in the same module folder if one
   exists (`Pages/{Modul}/Index.tsx` etc.) — copy its layout/header
   pattern rather than reinventing page chrome each time.
2. Wrap content in `<Card>` for anything that reads as a discrete block
   (a form, a summary panel, a table container) — don't build ad-hoc
   `<div className="rounded-lg border ...">` when `<Card>` already does
   this with the right tokens.
3. Page header: title + optional description + primary action button,
   passed as the `header` prop to `<AppLayout>`. Keep it short — the
   sidebar already carries module context, don't repeat "CRM" in every
   page title.
4. Tables: use `Components/shared/DataTable.tsx` (TanStack Table v8
   wrapper — pinned off v9, see `plan/README.md` for why) — see
   `frontend-standards.md` §4. Never re-implement `<table>` markup
   per page.
5. Title/description/primary-action row inside the page content (below
   the topbar) → `Components/shared/PageHeader.tsx`, not a hand-rolled
   `<div>`.
6. A date input → `Components/shared/DatePicker.tsx` (already formats in
   Bahasa Indonesia via date-fns), not a raw `<Calendar>`/`<input
   type="date">`.

## Adding/customizing a shadcn component

1. `npx shadcn@latest add <name> --yes --overwrite` from the project
   root — this respects `components.json` (style `radix-nova`, base color
   `neutral`).
2. **Fix casing immediately after**: grep the new file(s) for
   `@/components/` (lowercase) and replace with `@/Components/`
   (uppercase) — the CLI's default alias doesn't match this project's
   folder casing. Run `npm run build` to confirm TypeScript doesn't flag a
   case-sensitivity conflict before moving on.
3. Don't edit generated primitives in `Components/ui/` to bake in
   Daiku-specific styling (e.g. don't hardcode yellow into `button.tsx`'s
   `secondary` variant). Brand color already flows through `--primary`;
   only touch a primitive's variant string when the PRD names an *exact*
   different behavior (see `button.tsx`'s `default` variant, which uses
   `hover:bg-daiku-yellow-dark` per PRD §8.3's explicit hover spec — that
   was a deliberate, documented exception, not the norm).

## Status badges / chips

Don't pick colors ad hoc per status string. Use
`Components/shared/StatusChip.tsx` — it already maps every status value
across `types/index.d.ts`'s unions to the `success`/`warning`/`error`/
`info` tokens (`design-standards.md` §3). Adding a new status value to a
union type? Add it to `StatusChip`'s `STATUS_TONE` map in the same PR —
don't let it silently fall back to neutral.

## Charts (Recharts)

Any dashboard widget (CEO Executive Dashboard, PRD §4.10) that renders a
chart: load the `dataviz` skill before writing chart code — it governs
color usage, legends, and accessibility for every chart in this app, and
takes precedence over inventing chart colors here. Feed it the Daiku
palette as the "brand" swap-in.

## Sanity check before calling UI done

- [ ] No literal hex/`gray-*`/`blue-*` Tailwind classes introduced.
- [ ] Works inside `<AppLayout>`/`<AuthLayout>`, not a bespoke shell.
- [ ] All user-facing strings are in Bahasa Indonesia (PRD §1.4).
- [ ] `npm run build` passes (TypeScript + Tailwind both compile clean).
