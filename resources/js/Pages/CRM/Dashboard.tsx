import { AXIS_TICK, VIZ } from '@/Components/modules/analytics/chartTheme';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import type { LeadStatus } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlarmClock, ArrowLeft, BarChart3, Filter, Handshake, Percent, Share2, Users } from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Funnel,
    FunnelChart,
    LabelList,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface CrmDashboardProps {
    funnel: { status: LeadStatus; total: number }[];
    stats: {
        total: number;
        closing: number;
        lost: number;
        conversionRate: number;
        overdueFollowUp: number;
    };
    bySource: { source: string; total: number }[];
}

// Same semantic mapping as StatusChip's STATUS_TONE for these three
// statuses — the funnel should read as the same colors the Lead table
// already uses, not a freshly invented palette.
const FUNNEL_COLOR: Record<LeadStatus, string> = {
    FOLLOW_UP: 'var(--color-info)',
    DEAL_DESAIN: 'var(--color-warning)',
    CLOSING: 'var(--color-success)',
    LOST: 'var(--color-error)',
};

const FUNNEL_LABEL: Record<LeadStatus, string> = {
    FOLLOW_UP: 'Follow-up',
    DEAL_DESAIN: 'Deal Desain',
    CLOSING: 'Closing',
    LOST: 'Lost',
};

// Tooltip box matching chartTheme's RupiahTooltip look.
const TOOLTIP_STYLE = {
    fontSize: 12,
    borderColor: 'var(--color-daiku-border)',
    borderRadius: 8,
    boxShadow: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
} as const;

/**
 * "Pipeline dashboard Marketing: funnel chart + statistik lead"
 * (.claude/plan/sprint-03.md Week 5) — a "Analytics – Per Divisi" partial
 * view (PRD §7.1), not PRD §4.10's full CEO Executive Dashboard (that's
 * the Analytics module, Sprint 5/6 — see LeadController::dashboard()).
 */
export default function CrmDashboard({ funnel, stats, bySource }: CrmDashboardProps) {
    const funnelData = funnel.map((row) => ({
        name: FUNNEL_LABEL[row.status],
        value: row.total,
        fill: FUNNEL_COLOR[row.status],
    }));

    return (
        <AppLayout
            breadcrumbs={[{ label: 'Statistik Pipeline' }]}
        >
            <Head title="Statistik Pipeline" />

            <PageHeader
                title="Statistik Pipeline"
                icon={BarChart3}
                description="Ringkasan funnel dan performa lead Marketing."
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('crm.leads.index')}>
                            <ArrowLeft className="size-4" />
                            Kembali ke Data Lead
                        </Link>
                    </Button>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Total Lead" value={String(stats.total)} icon={Users} />
                <StatCard label="Closing" value={String(stats.closing)} icon={Handshake} tone="success" />
                <StatCard label="Conversion Rate" value={`${stats.conversionRate}%`} icon={Percent} />
                <StatCard
                    label="Follow-up Terlewat"
                    value={String(stats.overdueFollowUp)}
                    icon={AlarmClock}
                    tone={stats.overdueFollowUp > 0 ? 'error' : 'default'}
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <SectionCard
                    title="Pipeline Funnel"
                    icon={Filter}
                    footer={`Lead LOST (${stats.lost}) tidak dihitung dalam funnel — cabang terminal, bukan tahap pipeline.`}
                >
                    {stats.total === 0 ? (
                        <EmptyState title="Belum ada data lead." />
                    ) : (
                        <ResponsiveContainer width="100%" height={280}>
                            <FunnelChart>
                                <Tooltip
                                    formatter={(value, _name, item) => [`${value} lead`, item?.payload?.name]}
                                    contentStyle={TOOLTIP_STYLE}
                                />
                                <Funnel dataKey="value" data={funnelData} isAnimationActive>
                                    <LabelList
                                        position="right"
                                        dataKey="name"
                                        fill="var(--color-foreground)"
                                        stroke="none"
                                    />
                                </Funnel>
                            </FunnelChart>
                        </ResponsiveContainer>
                    )}
                </SectionCard>

                <SectionCard title="Lead per Sumber" icon={Share2}>
                    {bySource.length === 0 ? (
                        <EmptyState title="Belum ada data lead." />
                    ) : (
                        <ResponsiveContainer width="100%" height={280}>
                            <BarChart data={bySource} layout="vertical" margin={{ left: 16 }}>
                                <CartesianGrid horizontal={false} stroke={VIZ.grid} />
                                <XAxis type="number" allowDecimals={false} tick={AXIS_TICK} axisLine={false} tickLine={false} />
                                <YAxis type="category" dataKey="source" width={90} tick={AXIS_TICK} axisLine={false} tickLine={false} />
                                <Tooltip
                                    formatter={(value) => [`${value} lead`, 'Jumlah']}
                                    cursor={{ fill: 'var(--color-daiku-gray)' }}
                                    contentStyle={TOOLTIP_STYLE}
                                />
                                <Bar dataKey="total" radius={[0, 4, 4, 0]} maxBarSize={24}>
                                    {bySource.map((entry) => (
                                        <Cell key={entry.source} fill={VIZ.series1} />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}
