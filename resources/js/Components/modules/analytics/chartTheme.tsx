import { formatRupiah, formatRupiahCompact } from '@/lib/format';

/** The slice of Recharts' tooltip content props this component reads (stable across v2/v3). */
interface RupiahTooltipProps {
    active?: boolean;
    label?: string | number;
    payload?: ReadonlyArray<{ dataKey?: unknown; name?: unknown; value?: unknown; color?: string }>;
}

/**
 * Shared Recharts styling for the Analytics dashboard, following the
 * dataviz skill: recessive hairline grid and axes, validated categorical
 * slots (`--color-viz-*` in app.css — assigned in fixed order), text in
 * ink tokens rather than series colors.
 */
export const VIZ = {
    series1: 'var(--color-viz-1)',
    series2: 'var(--color-viz-2)',
    grid: 'var(--color-viz-grid)',
    axis: 'var(--color-viz-axis)',
} as const;

export const AXIS_TICK = { fontSize: 11, fill: 'var(--color-daiku-muted)' } as const;

export const GRID_PROPS = { stroke: VIZ.grid, strokeDasharray: '0', vertical: false } as const;

/**
 * Tooltip body shared by every Rupiah chart — series swatch + name in
 * muted ink, value in primary ink (never the series color).
 */
export function RupiahTooltip({ active, payload, label }: RupiahTooltipProps) {
    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="rounded-md border border-daiku-border bg-white px-3 py-2 text-xs shadow-sm">
            <p className="mb-1 font-medium text-daiku-dark">{label}</p>
            {payload.map((entry) => (
                <p key={String(entry.name)} className="flex items-center gap-2 text-daiku-muted">
                    <span aria-hidden className="size-2 rounded-sm" style={{ backgroundColor: entry.color }} />
                    {String(entry.name)}
                    <span className="ml-auto pl-3 font-medium text-daiku-dark tabular-nums">
                        {typeof entry.value === 'number' ? formatRupiah(entry.value) : '—'}
                    </span>
                </p>
            ))}
        </div>
    );
}

export const rupiahAxisFormatter = (value: number) => formatRupiahCompact(value);
