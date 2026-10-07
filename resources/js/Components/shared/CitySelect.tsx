import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { CityOption } from '@/types';
import { useMemo } from 'react';

interface CitySelectProps {
    /** City id as a string ('' = no city) — react-hook-form friendly. */
    value: string;
    onChange: (value: string) => void;
    /** Master Kota (`City::options()`), already ordered: home city, Riau, then other provinces. */
    cities: CityOption[];
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
}

const NONE = '__none';

/**
 * Sprint 16 Sub 08 — Master Kota dropdown. Cities are only added by the
 * SUPERADMIN in Data Master → Kota; a lead's city is picked here, never
 * typed, so "Pekanbaru" / "PKU" can't become two cities.
 */
export function CitySelect({ value, onChange, cities, disabled, className, ...props }: CitySelectProps) {
    // Keep the server's order; group consecutive rows by province.
    const groups = useMemo(() => {
        const byProvince = new Map<string, CityOption[]>();
        cities.forEach((city) => {
            const key = city.province ?? 'Lainnya';
            byProvince.set(key, [...(byProvince.get(key) ?? []), city]);
        });

        return [...byProvince.entries()];
    }, [cities]);

    return (
        <div className="space-y-1">
            <Select value={value === '' ? NONE : value} onValueChange={(next) => onChange(next === NONE ? '' : next)} disabled={disabled}>
                <SelectTrigger className={className ?? 'w-full'} aria-label={props['aria-label'] ?? 'Kota'}>
                    <SelectValue placeholder="Pilih kota" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>Tanpa kota</SelectItem>
                    {groups.map(([province, rows]) => (
                        <SelectGroup key={province}>
                            <SelectLabel>{province}</SelectLabel>
                            {rows.map((city) => (
                                <SelectItem key={city.id} value={String(city.id)}>
                                    {city.name}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    ))}
                </SelectContent>
            </Select>
            <p className="text-xs text-daiku-muted">Kota tidak ada? Minta admin menambahkannya di Data Master → Kota.</p>
        </div>
    );
}
