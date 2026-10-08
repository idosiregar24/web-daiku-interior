import { Input } from '@/Components/ui/input';
import { normalizeUsername, usernameFromName } from '@/lib/username';
import { cn } from '@/lib/utils';
import { Check, Loader2, X } from 'lucide-react';
import { type ComponentProps, useEffect, useState } from 'react';

export type UsernameStatus = 'available' | 'taken' | 'invalid' | 'reserved' | 'unchanged';

export interface UsernameCheckResult {
    status: UsernameStatus;
    message: string;
    suggestions: string[];
}

/** One call to `username.check` (UserService::checkUsername()). */
export async function checkUsername(
    username: string,
    options: { userId?: number; name?: string; signal?: AbortSignal } = {},
): Promise<UsernameCheckResult> {
    const { data } = await window.axios.get<UsernameCheckResult>(route('username.check'), {
        params: { username, user_id: options.userId, name: options.name || undefined },
        signal: options.signal,
    });

    return data;
}

/**
 * "Buat dari nama" — the first free username for a person's name
 * ("Budi Santoso" → budisantoso, else budis / budi / budi2…), or null when
 * the name gives nothing usable.
 */
export async function suggestUsernameFor(name: string, userId?: number): Promise<string | null> {
    const guess = usernameFromName(name);
    const result = await checkUsername(guess, { userId, name });

    if (result.status === 'available' || result.status === 'unchanged') {
        return guess;
    }

    return result.suggestions[0] ?? null;
}

interface UsernameInputProps extends Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> {
    value: string;
    onChange: (value: string) => void;
    /** The account being edited — its own username reads "unchanged". Omit for a new account. */
    userId?: number;
    /** The person's name, to build suggestions from when the username is taken. */
    personName?: string;
}

/**
 * Sprint 21 Sub 02 — username field with a live availability check: `@`
 * prefix, lower-cased as you type, checked 400 ms after typing stops (an
 * older request is cancelled), ✓/✗ inside the field and clickable
 * suggestions below. Guidance only — the server's `unique` rule decides.
 */
export function UsernameInput({ value, onChange, userId, personName, className, disabled, ...props }: UsernameInputProps) {
    const [result, setResult] = useState<UsernameCheckResult | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (disabled || value === '') {
            setResult(null);
            setLoading(false);
            return;
        }

        const controller = new AbortController();
        setLoading(true);

        const timeout = setTimeout(() => {
            checkUsername(value, { userId, name: personName, signal: controller.signal })
                .then((data) => setResult(data))
                .catch(() => {
                    if (!controller.signal.aborted) setResult(null);
                })
                .finally(() => {
                    if (!controller.signal.aborted) setLoading(false);
                });
        }, 400);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
        // personName only seeds suggestions — no re-check on every keystroke of the name.
    }, [value, userId, disabled]);

    const ok = result?.status === 'available' || result?.status === 'unchanged';
    const shown = !loading && result !== null;

    return (
        <div className="space-y-1.5">
            <div className="relative">
                <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground" aria-hidden>
                    @
                </span>
                <Input
                    {...props}
                    type="text"
                    value={value}
                    disabled={disabled}
                    onChange={(e) => onChange(normalizeUsername(e.target.value).replace(/\s/g, ''))}
                    autoCapitalize="none"
                    autoCorrect="off"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={30}
                    className={cn('pr-9 pl-7', className)}
                />
                <span className="absolute top-1/2 right-3 -translate-y-1/2" aria-hidden>
                    {loading && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
                    {shown && ok && <Check className="size-4 text-success-ink" />}
                    {shown && !ok && <X className="size-4 text-error-ink" />}
                </span>
            </div>

            {shown && (
                <p className={cn('text-xs', ok ? 'text-success-ink' : 'text-error-ink')} aria-live="polite">
                    {result.message}
                </p>
            )}

            {shown && result.suggestions.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                    <span>Saran:</span>
                    {result.suggestions.map((suggestion) => (
                        <button
                            key={suggestion}
                            type="button"
                            onClick={() => onChange(suggestion)}
                            className="rounded-md bg-daiku-gray px-2 py-0.5 font-medium text-foreground hover:bg-daiku-yellow-light"
                        >
                            @{suggestion}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
