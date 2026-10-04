import { AXIS_TICK, GRID_PROPS, VIZ } from '@/Components/modules/analytics/chartTheme';
import { CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { formatKpiValue, KPI_MAX_SCORE } from './KpiTypes';

export interface KpiTrendDatum {
    label: string;
    value: number | null;
    /** Extra line under the value in the tooltip (e.g. "8 karyawan · Terbuka"). */
    note?: string;
}

interface KpiTrendChartProps {
    data: KpiTrendDatum[];
    /** Series name — the card title names it, so no legend (single series). */
    name: string;
    ariaLabel: string;
}

interface TrendTooltipProps {
    active?: boolean;
    payload?: ReadonlyArray<{ payload?: KpiTrendDatum; value?: unknown }>;
    name: string;
}

function TrendTooltip({ active, payload, name }: TrendTooltipProps) {
    const datum = payload?.[0]?.payload;
    if (!active || !datum) return null;

    return (
        <div className="rounded-lg border border-border bg-popover px-3 py-2 text-xs shadow-md">
            <p className="mb-1 font-medium text-daiku-dark">{datum.label}</p>
            <p className="flex items-center gap-2 text-daiku-muted">
                <span aria-hidden className="size-2 rounded-sm" style={{ backgroundColor: VIZ.series1 }} />
                {name}
                <span className="ml-auto pl-3 font-medium text-daiku-dark tabular-nums">{formatKpiValue(datum.value)}</span>
            </p>
            {datum.note && <p className="mt-1 text-daiku-muted">{datum.note}</p>}
        </div>
    );
}

/**
 * Monthly KPI total (0–120 scale) over time — one series in viz slot 1, a
 * dashed hairline at 100 (= on target), crosshair tooltip. Months without
 * a value leave a gap rather than dropping to zero.
 */
export function KpiTrendChart({ data, name, ariaLabel }: KpiTrendChartProps) {
    return (
        <div className="h-64" role="img" aria-label={ariaLabel}>
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={data} margin={{ left: 4, right: 12, top: 8 }}>
                    <CartesianGrid {...GRID_PROPS} />
                    <XAxis dataKey="label" tick={AXIS_TICK} axisLine={{ stroke: VIZ.axis }} tickLine={false} />
                    <YAxis
                        domain={[0, KPI_MAX_SCORE]}
                        ticks={[0, 40, 80, 100, 120]}
                        tick={AXIS_TICK}
                        axisLine={false}
                        tickLine={false}
                        width={36}
                    />
                    <ReferenceLine y={100} stroke={VIZ.axis} strokeDasharray="4 4" />
                    <Tooltip content={<TrendTooltip name={name} />} cursor={{ stroke: VIZ.axis, strokeWidth: 1 }} />
                    <Line
                        dataKey="value"
                        name={name}
                        type="monotone"
                        stroke={VIZ.series1}
                        strokeWidth={2}
                        dot={{ r: 4, fill: VIZ.series1, stroke: 'var(--color-card)', strokeWidth: 2 }}
                        activeDot={{ r: 5, fill: VIZ.series1, stroke: 'var(--color-card)', strokeWidth: 2 }}
                        connectNulls={false}
                        isAnimationActive={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
