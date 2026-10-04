import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { UnitOption } from '@/types';

interface UnitSelectProps {
    /** Unit id as a string ('' = nothing picked) — react-hook-form friendly. */
    value: string;
    onChange: (value: string) => void;
    /** Active units from Master Satuan (`Unit::options()`). */
    units: UnitOption[];
    /** The record's current unit — kept selectable even after SUPERADMIN deactivated it. */
    current?: UnitOption | null;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
}

/**
 * Master Satuan dropdown (Sprint 11 Sub 1). Units are managed only in
 * Data Master → Satuan; every form picks from here instead of typing a
 * unit as free text.
 */
export function UnitSelect({ value, onChange, units, current, disabled, className, ...props }: UnitSelectProps) {
    const options = current && !units.some((unit) => unit.id === current.id) ? [...units, current] : units;

    return (
        <Select value={value} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger className={className ?? 'w-full'} aria-label={props['aria-label'] ?? 'Satuan'}>
                <SelectValue placeholder="Pilih satuan" />
            </SelectTrigger>
            <SelectContent>
                {options.map((unit) => (
                    <SelectItem key={unit.id} value={String(unit.id)}>
                        {unit.code} <span className="text-daiku-muted">— {unit.name}</span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
