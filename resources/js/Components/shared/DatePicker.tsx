import { Button } from '@/Components/ui/button';
import { Calendar } from '@/Components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/Components/ui/popover';
import { cn } from '@/lib/utils';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { CalendarIcon, X } from 'lucide-react';
import { useState } from 'react';

interface DatePickerProps {
    value?: Date;
    onChange?: (date: Date | undefined) => void;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    /** Optional field: shows "Hapus tanggal" under the calendar to empty it. */
    clearable?: boolean;
    /** Days before / after are greyed out and can't be picked (e.g. the project's dates). */
    min?: Date;
    max?: Date;
    /** The month the calendar opens on while empty (else today). */
    defaultMonth?: Date;
}

/**
 * Single-date picker (Popover + Calendar) formatted in Bahasa Indonesia —
 * see .claude/rules/frontend-standards.md §3. Pair with
 * Components/ui/form.tsx's <FormControl> when used inside a React Hook
 * Form field.
 *
 * Clicking the already-selected day keeps it (react-day-picker would emit
 * `undefined`, which left RHF empty while the button still showed the date —
 * Sprint 28 F1). Emptying an optional field is the explicit `clearable`
 * button instead.
 */
export function DatePicker({
    value,
    onChange,
    placeholder = 'Pilih tanggal',
    disabled,
    className,
    clearable = false,
    min,
    max,
    defaultMonth,
}: DatePickerProps) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    className={cn(
                        'w-full justify-start text-left font-normal',
                        !value && 'text-daiku-muted',
                        className,
                    )}
                >
                    <CalendarIcon className="size-4" />
                    {value ? format(value, 'd MMMM yyyy', { locale: id }) : placeholder}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
                <Calendar
                    mode="single"
                    selected={value}
                    onSelect={(date) => {
                        if (date) onChange?.(date);
                        setOpen(false);
                    }}
                    locale={id}
                    defaultMonth={value ?? defaultMonth}
                    disabled={[...(min ? [{ before: min }] : []), ...(max ? [{ after: max }] : [])]}
                    autoFocus
                />
                {clearable && value && (
                    <div className="border-t border-border p-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="w-full"
                            onClick={() => {
                                onChange?.(undefined);
                                setOpen(false);
                            }}
                        >
                            <X className="size-4" />
                            Hapus tanggal
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
