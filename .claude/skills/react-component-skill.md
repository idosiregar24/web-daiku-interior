---
name: react-component
description: Use when creating a new reusable React/TypeScript component in resources/js/Components (shared or modules) — e.g. StatusChip, DataTable, PageHeader, DatePicker, a Kanban board, a chart widget. Keeps new components consistent with the project's shadcn/Tailwind v4 conventions instead of each one inventing its own prop/style pattern.
---

# React Component Skill

Recipe for adding a new component under `resources/js/Components/`. For
shadcn primitives themselves (`Components/ui/`), use the CLI instead — see
`.claude/skills/front-end-design/SKILL.md`. This skill is for the
composed, project-specific components built *on top of* those primitives.

## 1. Decide where it lives

- `Components/shared/` — used by 2+ modules (StatusChip, DataTable,
  PageHeader, DatePicker, ConfirmDialog).
- `Components/modules/` — specific to one module (a Kanban task board, a
  quotation line-item editor, a pipeline funnel widget).

Don't add a third "just in case" location — if unsure, start in
`modules/` under the owning feature; promote to `shared/` the second time
another module needs it.

## 2. File & export convention

- One component per file, filename = component name, `PascalCase.tsx`.
- Default export the component; named export any tightly-coupled helper
  types/variants (mirrors how `Components/ui/button.tsx` exports both
  `Button` and `buttonVariants`).
- Props typed with an explicit interface, not inline object types, when
  the component has more than ~2 props:

```tsx
interface StatusChipProps {
  status: TaskStatus; // reuse the union from @/types, never a raw `string`
  className?: string;
}

export function StatusChip({ status, className }: StatusChipProps) {
  return (
    <Badge className={cn(STATUS_COLOR[status], className)}>
      {STATUS_LABEL[status]}
    </Badge>
  );
}
```

## 3. Styling

- Compose with shadcn primitives (`Badge`, `Card`, `Button`, etc.) plus
  `cn()` from `@/lib/utils` for conditional/merged classes — never
  string-concatenate class names manually.
- Colors/spacing come from Daiku tokens (`design-standards.md`) — no
  hardcoded hex or default Tailwind palette colors.
- Accept an optional `className` prop on any component meant to be reused
  in different layout contexts, and merge it last via `cn(...)` so callers
  can override spacing/width.

## 4. Data in vs. data fetched

Components in `Components/` should be **presentational** — they receive
data via props (usually already-fetched Inertia page props, passed down)
rather than fetching their own data. The one sanctioned exception is a
component that subscribes to `window.Echo` for live updates (e.g. a
notification bell) — that's a side effect of "live", not a fetch.

## 5. Forms

If the component wraps a form field, build it on top of
`Components/ui/form.tsx` (`FormField`/`FormItem`/`FormControl`) so it
plugs into a parent `useForm()` (React Hook Form) context — don't manage
its own `useState` for a value that a form should own. See
`frontend-standards.md` §3.

## 6. Before considering it done

- [ ] Props reuse existing types from `@/types` where a matching
      union/interface already exists — no re-declared literal unions.
- [ ] No hardcoded colors (`design-standards.md` §1).
- [ ] Works in both a narrow (mobile, inside `<Sheet>`) and wide (desktop
      sidebar) context if it's meant to appear in `AppLayout`.
- [ ] `npm run build` passes.
