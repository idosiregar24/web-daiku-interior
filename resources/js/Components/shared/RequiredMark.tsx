/**
 * Sprint 16 — the red asterisk after a required field's label. "Required"
 * means the field's Form Request rule is `required` (or a `required_if`
 * that currently applies) — pass `<FormLabel required>` / `<InputLabel
 * required>`, or drop this inside a plain shadcn `<Label>`.
 *
 * shadcn labels are `flex gap-2`; the negative margin pulls the mark back
 * next to the text there. Screen readers hear "(wajib)" instead of "*".
 */
export function RequiredMark() {
    return (
        <>
            <span aria-hidden="true" className="text-error-ink in-data-[slot=label]:-ms-1.5 in-data-[slot=form-label]:-ms-1.5">
                *
            </span>
            <span className="sr-only">(wajib)</span>
        </>
    );
}
