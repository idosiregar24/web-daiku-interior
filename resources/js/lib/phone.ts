/**
 * Sprint 16 Sub 07 — Indonesian mobile numbers, mirrored from
 * `App\Support\Phone`: stored as digits only starting `08`, 10–13 digits.
 */
export const PHONE_PATTERN = /^08\d{8,11}$/;

/** "+62 812-3456-7890" / "62812…" / "812…" → "0812…"; non-numeric input comes back trimmed. */
export function normalizePhone(value: string | null | undefined): string {
    const trimmed = (value ?? '').trim();
    const stripped = trimmed.replace(/[\s\-.()]/g, '');

    if (!/^\+?\d+$/.test(stripped)) {
        return trimmed;
    }

    const digits = stripped.replace(/^\+/, '');

    if (digits.startsWith('62')) return `0${digits.slice(2)}`;
    if (digits.startsWith('8')) return `0${digits}`;

    return digits;
}

/** Keeps what a phone field may contain while typing: digits and a leading "+". */
export function sanitizePhoneInput(value: string): string {
    const plus = value.trimStart().startsWith('+') ? '+' : '';

    return plus + value.replace(/\D/g, '');
}

export function isValidPhone(value: string | null | undefined): boolean {
    return PHONE_PATTERN.test(value ?? '');
}

/** "081234567890" → "0812-3456-7890". */
export function formatPhone(value: string | null | undefined): string {
    if (!value) return '';

    return isValidPhone(value) ? (value.match(/.{1,4}/g) ?? [value]).join('-') : value;
}

/** "081234567890" → "6281234567890" for wa.me; null when not a valid number. */
export function whatsappNumber(value: string | null | undefined): string | null {
    return isValidPhone(value) ? `62${value!.slice(1)}` : null;
}

/** A lead's contact in one line: "0812-3456-7890", else the email, else "—". */
export function contactLabel(contact: { phone?: string | null; email?: string | null }): string {
    return formatPhone(contact.phone) || contact.email || '—';
}
