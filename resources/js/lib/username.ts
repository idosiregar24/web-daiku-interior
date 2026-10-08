import { z } from 'zod';

/**
 * Sprint 21 (K5) — login usernames, mirrored from `App\Support\Username`
 * (change both together): lowercase letters and digits only, starting with
 * a letter, 3–30 characters. No spaces, dots, underscores or symbols.
 */
export const USERNAME_PATTERN = /^[a-z][a-z0-9]{2,29}$/;
export const USERNAME_MAX = 30;

export const RESERVED_USERNAMES = [
    'admin', 'administrator', 'superadmin', 'root', 'daiku', 'daikuinterior', 'system', 'sistem', 'support',
    'ceo', 'marketing', 'designer', 'desainer', 'arsitek', 'estimator', 'pm', 'asistenpm', 'kepaladesain',
    'qa', 'finance', 'logistics', 'logistik', 'fieldstaff', 'tukang', 'hr', 'sdm',
];

export const USERNAME_FORMAT_MESSAGE = 'Hanya huruf kecil dan angka, diawali huruf, 3–30 karakter, tanpa spasi, titik atau garis bawah.';
export const IDENTITY_REQUIRED_MESSAGE = 'Isi username atau email (minimal salah satu).';

/** "  Budi " → "budi" — huruf besar dikecilkan, sisanya dibiarkan agar validasi menolaknya. */
export function normalizeUsername(value: string | null | undefined): string {
    return (value ?? '').trim().toLowerCase();
}

export function isValidUsername(value: string | null | undefined): boolean {
    return USERNAME_PATTERN.test(value ?? '');
}

export function isReservedUsername(value: string | null | undefined): boolean {
    return RESERVED_USERNAMES.includes(normalizeUsername(value));
}

/** "budi" → "@budi"; null when there's no username. */
export function atUsername(value: string | null | undefined): string | null {
    return value ? `@${value}` : null;
}

/**
 * A first guess at a username from a person's name, like
 * `Username::suggestFor()`'s first candidate: "Budi Santoso" → "budisantoso".
 * The server check then confirms it or offers a free alternative.
 */
export function usernameFromName(name: string): string {
    return name
        .normalize('NFKD')
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '')
        .replace(/^[0-9]+/, '')
        .slice(0, USERNAME_MAX);
}

/**
 * Zod field for an optional username (empty allowed). `current` is the
 * account's username now — kept as is even if it predates these rules,
 * like the server (`ValidatesUserIdentity`).
 */
export function usernameField(current?: string | null) {
    return z
        .string()
        .refine((value) => value === '' || value === current || isValidUsername(value), USERNAME_FORMAT_MESSAGE)
        .refine((value) => value === current || !isReservedUsername(value), 'Username ini tidak boleh dipakai.');
}

/** Zod field for an optional email (empty allowed). */
export const optionalEmailField = z
    .string()
    .trim()
    .max(255)
    .refine((value) => value === '' || z.email().safeParse(value).success, 'Format email tidak valid.');

/**
 * Mirrors `ValidatesUserIdentity` — an account needs a username, an email,
 * or both. Attach with `.superRefine(requireUsernameOrEmail)`.
 */
export function requireUsernameOrEmail(values: { username: string; email: string }, ctx: z.RefinementCtx) {
    if (values.username.trim() === '' && values.email.trim() === '') {
        ctx.addIssue({ code: 'custom', path: ['username'], message: IDENTITY_REQUIRED_MESSAGE });
        ctx.addIssue({ code: 'custom', path: ['email'], message: IDENTITY_REQUIRED_MESSAGE });
    }
}
