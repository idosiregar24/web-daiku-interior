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
import { CalendarIcon } from 'lucide-react';
import { useState } from 'react';

interface DatePickerProps {
    value?: Date;
    onChange?: (date: Date | undefined) => void;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}

/**
 * Single-date picker (Popover + Calendar) formatted in Bahasa Indonesia —
 * see .claude/rules/frontend-standards.md §3. Pair with
 * Components/ui/form.tsx's <FormControl> when used inside a React Hook
 * Form field.
 */
export function DatePicker({
    value,
    onChange,
    placeholder = 'Pilih tanggal',
    disabled,
    className,
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
                        onChange?.(date);
                        setOpen(false);
                    }}
                    locale={id}
                    autoFocus
                />
            </PopoverContent>
        </Popover>
    );
}
