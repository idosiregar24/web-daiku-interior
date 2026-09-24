import { Bar, BarChart, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AXIS_TICK, VIZ } from './chartTheme';

export interface FunnelData {
    stages: { key: string; label: string; count: number }[];
    lost: number;
    conversionRate: number;
}

/**
 * PRD §4.10 "Pipeline Funnel". One series → one hue, no legend box (the
 * card title names it); counts are direct-labeled at each bar end and the
 * step-to-step conversion sits beside the chart as text.
 */
export function PipelineFunnel({ data }: { data: FunnelData }) {
    const first = data.stages[0]?.count ?? 0;

    return (
        <div className="grid gap-4 md:grid-cols-[1fr_auto]">
            <div className="h-56" role="img" aria-label={`Funnel pipeline: ${data.stages.map((s) => `${s.label} ${s.count}`).join(', ')}`}>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data.stages} layout="vertical" margin={{ left: 8, right: 40 }}>
                        <XAxis type="number" hide allowDecimals={false} />
                        <YAxis type="category" dataKey="label" width={130} tick={AXIS_TICK} axisLine={false} tickLine={false} />
                        <Tooltip
                            cursor={{ fill: 'var(--color-daiku-gray)' }}
                            formatter={(value) => [`${value} lead`, 'Jumlah']}
                            contentStyle={{ fontSize: 12, borderColor: 'var(--color-daiku-border)', borderRadius: 6 }}
                        />
                        <Bar dataKey="count" fill={VIZ.series1} radius={[0, 4, 4, 0]} maxBarSize={28} isAnimationActive={false}>
                            <LabelList dataKey="count" position="right" className="fill-daiku-dark text-xs font-medium" />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-1 md:content-center">
                <div>
                    <dt className="text-xs text-daiku-muted">Konversi lead → closing</dt>
                    <dd className="text-lg font-semibold tabular-nums">{data.conversionRate}%</dd>
                </div>
                <div>
                    <dt className="text-xs text-daiku-muted">Lead lost</dt>
                    <dd className="text-lg font-semibold tabular-nums">{data.lost}</dd>
                </div>
                {first > 0 && (
                    <div className="col-span-2 md:col-span-1">
                        <dt className="text-xs text-daiku-muted">Per tahap (dari lead masuk)</dt>
                        <dd className="mt-1 space-y-0.5 text-xs text-daiku-muted">
                            {data.stages.slice(1).map((stage) => (
                                <p key={stage.key}>
                                    {stage.label}: <span className="font-medium text-daiku-dark">{Math.round((stage.count / first) * 100)}%</span>
                                </p>
                            ))}
                        </dd>
                    </div>
                )}
            </dl>
        </div>
    );
}
