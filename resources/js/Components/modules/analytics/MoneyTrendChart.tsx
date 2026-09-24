import { Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AXIS_TICK, GRID_PROPS, RupiahTooltip, rupiahAxisFormatter, VIZ } from './chartTheme';

interface Series {
    key: string;
    name: string;
    /** `bar` for measured amounts, `line` for a reference (e.g. target). */
    kind: 'bar' | 'line';
}

interface MoneyTrendChartProps<T extends { label: string }> {
    data: T[];
    series: [Series & { key: keyof T & string }, Series & { key: keyof T & string }];
    ariaLabel: string;
}

/**
 * Two Rupiah series over months on ONE shared axis (dataviz: never dual
 * axis). Slots are assigned in fixed order — first series viz-1, second
 * viz-2 — so the same measure never changes color between charts.
 * Legend always shown (≥ 2 series) plus a hover tooltip.
 */
export function MoneyTrendChart<T extends { label: string }>({ data, series, ariaLabel }: MoneyTrendChartProps<T>) {
    const colors = [VIZ.series1, VIZ.series2];

    return (
        <div className="h-72" role="img" aria-label={ariaLabel}>
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ left: 4, right: 8, top: 8 }} barGap={2}>
                    <CartesianGrid {...GRID_PROPS} />
                    <XAxis dataKey="label" tick={AXIS_TICK} axisLine={{ stroke: VIZ.axis }} tickLine={false} />
                    <YAxis tickFormatter={rupiahAxisFormatter} tick={AXIS_TICK} axisLine={false} tickLine={false} width={72} />
                    <Tooltip content={<RupiahTooltip />} cursor={{ fill: 'var(--color-daiku-gray)' }} />
                    <Legend iconType="square" iconSize={10} wrapperStyle={{ fontSize: 12, color: 'var(--color-daiku-muted)' }} />
                    {series.map((item, index) =>
                        item.kind === 'bar' ? (
                            <Bar
                                key={item.key}
                                dataKey={(row: T) => row[item.key]}
                                name={item.name}
                                fill={colors[index]}
                                radius={[4, 4, 0, 0]}
                                maxBarSize={32}
                                isAnimationActive={false}
                            />
                        ) : (
                            <Line
                                key={item.key}
                                dataKey={(row: T) => row[item.key]}
                                name={item.name}
                                type="monotone"
                                stroke={colors[index]}
                                strokeWidth={2}
                                strokeDasharray="6 4"
                                dot={{ r: 4, fill: colors[index], stroke: 'white', strokeWidth: 2 }}
                                connectNulls={false}
                                isAnimationActive={false}
                            />
                        ),
                    )}
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}
