import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import type { User } from '@/types';
import { Search, UserPlus, X } from 'lucide-react';
import { useId, useMemo, useRef, useState } from 'react';

export interface AttendeeValue {
    user_id?: string;
    name?: string;
}

type Option = Pick<User, 'id' | 'name'>;

const MAX_SHOWN = 8;

/** Lower-case, single spaces — how two names are compared. */
function normalize(value: string): string {
    return value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('id-ID');
}

/**
 * Sprint 33 — TM attendees as chips: type a name → the matching accounts
 * show under the field → Enter (or a click) adds the highlighted one; with
 * no match, Enter adds the typed name as a guest (vendor / orang luar).
 * Someone already added is never offered again, and the same typed name
 * can't be added twice (the server refuses duplicates too). Backspace on an
 * empty field removes the last chip.
 */
export function AttendeePicker({
    value,
    onChange,
    options,
    max = 50,
}: {
    value: AttendeeValue[];
    onChange: (next: AttendeeValue[]) => void;
    options: Option[];
    max?: number;
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [notice, setNotice] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const listId = useId();

    const byId = useMemo(() => new Map(options.map((option) => [String(option.id), option])), [options]);
    const takenIds = new Set(value.map((row) => row.user_id).filter(Boolean));
    const takenNames = new Set(value.map((row) => normalize(row.user_id ? (byId.get(row.user_id)?.name ?? '') : (row.name ?? ''))));
    const typed = normalize(query);

    const matches = useMemo(() => {
        const free = options.filter((option) => !takenIds.has(String(option.id)));
        if (typed === '') return free.slice(0, MAX_SHOWN);

        return free
            .filter((option) => normalize(option.name).includes(typed))
            .sort((a, b) => Number(!normalize(a.name).startsWith(typed)) - Number(!normalize(b.name).startsWith(typed)) || a.name.localeCompare(b.name))
            .slice(0, MAX_SHOWN);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [options, typed, value]);

    // A typed name that is no account and not on the list yet can join as a guest.
    const canAddGuest = typed !== '' && !takenNames.has(typed) && !matches.some((option) => normalize(option.name) === typed);
    const rows = matches.length + (canAddGuest ? 1 : 0);
    const full = value.length >= max;

    function reset() {
        setQuery('');
        setActive(0);
        inputRef.current?.focus();
    }

    function addUser(option: Option) {
        if (full) return;
        onChange([...value, { user_id: String(option.id), name: '' }]);
        setNotice(null);
        reset();
    }

    function addGuest() {
        const name = query.trim().replace(/\s+/g, ' ');
        if (full || name === '') return;
        if (takenNames.has(normalize(name))) {
            setNotice(`${name} sudah ada di daftar peserta.`);
            return;
        }
        onChange([...value, { user_id: '', name: name.slice(0, 120) }]);
        setNotice(null);
        reset();
    }

    function pick(index: number) {
        if (index < matches.length) addUser(matches[index]);
        else if (canAddGuest) addGuest();
    }

    function onKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (rows > 0) pick(Math.min(active, rows - 1));
            else if (typed !== '' && takenNames.has(typed)) setNotice(`${query.trim()} sudah ada di daftar peserta.`);
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setActive((index) => (rows === 0 ? 0 : (index + 1) % rows));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => (rows === 0 ? 0 : (index - 1 + rows) % rows));
        } else if (event.key === 'Escape') {
            setOpen(false);
        } else if (event.key === 'Backspace' && query === '' && value.length > 0) {
            onChange(value.slice(0, -1));
        }
    }

    const labelOf = (row: AttendeeValue) => (row.user_id ? (byId.get(row.user_id)?.name ?? 'Akun') : (row.name ?? ''));

    return (
        <div className="space-y-2">
            {value.length > 0 && (
                <ul className="flex flex-wrap gap-1.5" aria-label="Peserta terpilih">
                    {value.map((row, index) => (
                        <li
                            key={`${row.user_id || 'guest'}-${row.name ?? ''}-${index}`}
                            className="inline-flex items-center gap-1 rounded-full bg-daiku-yellow-light py-1 pr-1 pl-3 text-sm text-foreground ring-1 ring-daiku-yellow/60"
                        >
                            {labelOf(row)}
                            {!row.user_id && <span className="text-xs text-daiku-muted">· tamu</span>}
                            <button
                                type="button"
                                onClick={() => onChange(value.filter((_, i) => i !== index))}
                                className="flex size-6 items-center justify-center rounded-full text-daiku-muted hover:bg-background hover:text-foreground"
                                aria-label={`Hapus ${labelOf(row)}`}
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="relative sm:max-w-md">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-daiku-muted" />
                <Input
                    ref={inputRef}
                    value={query}
                    disabled={full}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActive(0);
                        setOpen(true);
                        setNotice(null);
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setOpen(false)}
                    onKeyDown={onKeyDown}
                    placeholder={full ? `Maksimal ${max} peserta` : 'Ketik nama peserta, lalu Enter'}
                    className="pl-9 max-md:h-11"
                    role="combobox"
                    aria-expanded={open && rows > 0}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-label="Cari peserta"
                />
                {open && rows > 0 && (
                    <ul
                        id={listId}
                        role="listbox"
                        className="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-lg bg-background p-1 shadow-md ring-1 ring-border"
                    >
                        {matches.map((option, index) => (
                            <li
                                key={option.id}
                                role="option"
                                aria-selected={index === active}
                                // mousedown, not click: the field's blur would close the list first.
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    addUser(option);
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={cn('cursor-pointer rounded-md px-3 py-2 text-sm', index === active ? 'bg-daiku-yellow-light text-foreground' : 'text-foreground')}
                            >
                                {option.name}
                            </li>
                        ))}
                        {canAddGuest && (
                            <li
                                role="option"
                                aria-selected={active === matches.length}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    addGuest();
                                }}
                                onMouseEnter={() => setActive(matches.length)}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm',
                                    active === matches.length ? 'bg-daiku-yellow-light text-foreground' : 'text-daiku-muted',
                                )}
                            >
                                <UserPlus className="size-4 shrink-0" />
                                Tambah “{query.trim()}” sebagai tamu (orang luar / vendor)
                            </li>
                        )}
                    </ul>
                )}
            </div>
            {notice && <p className="text-xs font-medium text-warning-ink">{notice}</p>}
        </div>
    );
}
