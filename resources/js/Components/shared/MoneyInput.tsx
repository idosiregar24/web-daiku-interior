import { Input } from '@/Components/ui/input';
import { forwardRef, useLayoutEffect, useRef, type ComponentProps, type ForwardedRef } from 'react';

type InputProps = Omit<ComponentProps<'input'>, 'value' | 'defaultValue' | 'onChange' | 'type' | 'inputMode' | 'ref'>;

export interface MoneyInputProps extends InputProps {
    /** Plain number or Eloquent decimal string ("1500000", 1500000, "1500000.00"); `''`/null = empty. */
    value: string | number | null | undefined;
    /**
     * Receives the plain value as a string without separators ("1500000",
     * "1500000.5", or "" when cleared) — the same shape a `type="number"`
     * input gave, so Zod schemas and Form Requests stay as they are. Works
     * as RHF's `field.onChange` directly (`<MoneyInput {...field} />`).
     */
    onChange?: (value: string) => void;
    /** Fraction digits allowed after a comma (Indonesian decimal mark). Default 0 — whole Rupiah. */
    decimals?: number;
}

const GROUP = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0, useGrouping: true });

/** "1500000.5" → "1.500.000,5" (keeps a trailing comma / zeros the user is still typing). */
function display(raw: string): string {
    if (raw === '') {
        return '';
    }

    const [whole, fraction] = raw.split('.');
    const grouped = whole === '' ? '' : GROUP.format(Number(whole));

    return fraction === undefined ? grouped : `${grouped === '' ? '0' : grouped},${fraction}`;
}

/** What the user typed ("1.500.000,5") → plain value ("1500000.5"). */
function parse(typed: string, decimals: number): string {
    const [wholePart, ...rest] = typed.split(',');
    const whole = wholePart.replace(/\D/g, '').replace(/^0+(?=\d)/, '');

    if (decimals === 0 || rest.length === 0) {
        return whole;
    }

    const fraction = rest.join('').replace(/\D/g, '').slice(0, decimals);

    return `${whole === '' ? '0' : whole}.${fraction}`;
}

/** Normalise the incoming prop: numbers, decimal strings ("1500000.00" → "1500000" at 0 decimals). */
function normalise(value: MoneyInputProps['value'], decimals: number): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const text = String(value);

    if (!/^\d+(\.\d*)?$/.test(text)) {
        const number = Number(text);

        return Number.isFinite(number) ? String(Math.max(0, decimals === 0 ? Math.round(number) : number)) : '';
    }

    const [whole, fraction = ''] = text.split('.');

    if (decimals === 0) {
        return whole.replace(/^0+(?=\d)/, '');
    }

    // Drop trailing zeros of a stored decimal ("150.50" → "150.5") but keep what's being typed.
    const trimmed = fraction.slice(0, decimals);

    return text.includes('.') ? `${whole}.${trimmed}` : whole;
}

/**
 * Sprint 23 K4 — every money field shows thousands separators
 * ("1.500.000") while the form value stays a plain number string. Use it
 * instead of `<Input type="number">` for any Rupiah amount.
 *
 *   <FormField name="amount" render={({ field }) => <MoneyInput {...field} />} />
 *   <MoneyInput value={data.amount} onChange={(v) => setData('amount', v)} />
 */
export const MoneyInput = forwardRef<HTMLInputElement, MoneyInputProps>(function MoneyInput(
    { value, onChange, decimals = 0, placeholder = '0', ...props },
    ref,
) {
    // shadcn's <Input> doesn't forward refs on React 18, so the element is
    // taken from its events (focus comes before any typing).
    const inputRef = useRef<HTMLInputElement | null>(null);
    // Digits before the caret, restored after the separators are re-inserted.
    const caretDigits = useRef<number | null>(null);

    const attach = (element: HTMLInputElement) => {
        if (inputRef.current !== element) {
            inputRef.current = element;
            assignRef(ref, element);
        }
    };

    const shown = display(normalise(value, decimals));

    useLayoutEffect(() => {
        const input = inputRef.current;

        if (caretDigits.current === null || !input || document.activeElement !== input) {
            return;
        }

        let seen = 0;
        let position = 0;

        while (position < shown.length && seen < caretDigits.current) {
            if (/[\d,]/.test(shown[position])) {
                seen++;
            }

            position++;
        }

        input.setSelectionRange(position, position);
        caretDigits.current = null;
    }, [shown]);

    return (
        <Input
            {...props}
            type="text"
            inputMode={decimals > 0 ? 'decimal' : 'numeric'}
            autoComplete="off"
            placeholder={placeholder}
            value={shown}
            onFocus={(event) => {
                attach(event.currentTarget);
                props.onFocus?.(event);
            }}
            onChange={(event) => {
                attach(event.currentTarget);
                const typed = event.target.value;
                const caret = event.target.selectionStart ?? typed.length;

                caretDigits.current = typed.slice(0, caret).replace(/[^\d,]/g, '').length;
                onChange?.(parse(typed, decimals));
            }}
        />
    );
});

function assignRef<T>(ref: ForwardedRef<T>, value: T) {
    if (typeof ref === 'function') {
        ref(value);
    } else if (ref) {
        ref.current = value;
    }
}
