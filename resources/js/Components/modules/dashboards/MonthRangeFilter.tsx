import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import type { MonthOption } from '@/types';
import { router } from '@inertiajs/react';

interface MonthRangeFilterProps {
    /** Ziggy route the filter reloads (its `from`/`to` query string). */
    routeName: string;
    range: { from: string; to: string };
    /** Selectable months, newest first (DivisionDashboardService::monthOptions()). */
    options: MonthOption[];
    /** Props to refresh — the rest of the page keeps its data. */
    only?: string[];
}

/**
 * "Dari … sampai …" month pickers for a dashboard section. The server
 * clamps/swaps whatever it receives, so a reversed pick still renders.
 */
export function MonthRangeFilter({ routeName, range, options, only }: MonthRangeFilterProps) {
    function apply(next: Partial<MonthRangeFilterProps['range']>) {
        router.get(route(routeName), { ...range, ...next }, { preserveScroll: true, preserveState: true, replace: true, only });
    }

    return (
        <div className="flex items-center gap-1.5">
            <Select value={range.from} onValueChange={(from) => apply({ from })}>
                <SelectTrigger size="sm" className="w-28" aria-label="Dari bulan">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <span className="text-xs text-muted-foreground">s/d</span>
            <Select value={range.to} onValueChange={(to) => apply({ to })}>
                <SelectTrigger size="sm" className="w-28" aria-label="Sampai bulan">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
