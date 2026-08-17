import { PageHeader } from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import type { LeadStatus } from '@/types';
import { Head, Link } from '@inertiajs/react';
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

function StatTile({ label, value, tone }: { label: string; value: string; tone?: 'success' | 'error' | 'default' }) {
    const toneClass = tone === 'success' ? 'text-success' : tone === 'error' ? 'text-error' : 'text-daiku-dark';

    return (
        <Card>
            <CardContent className="pt-6">
                <p className="text-xs text-daiku-muted">{label}</p>
                <p className={`mt-1 text-2xl font-semibold ${toneClass}`}>{value}</p>
            </CardContent>
        </Card>
    );
}

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
            breadcrumbs={[
                { label: 'CRM', routeName: 'crm.leads.index' },
                { label: 'Statistik Pipeline' },
            ]}
        >
            <Head title="Statistik Pipeline" />

            <PageHeader
                title="Statistik Pipeline"
                description="Ringkasan funnel dan performa lead Marketing."
                actions={
                    <Link href={route('crm.leads.index')} className="text-sm font-medium text-daiku-dark hover:underline">
                        ← Kembali ke Data Lead
                    </Link>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatTile label="Total Lead" value={String(stats.total)} />
                <StatTile label="Closing" value={String(stats.closing)} tone="success" />
                <StatTile label="Conversion Rate" value={`${stats.conversionRate}%`} />
                <StatTile label="Follow-up Terlewat" value={String(stats.overdueFollowUp)} tone={stats.overdueFollowUp > 0 ? 'error' : 'default'} />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Pipeline Funnel</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {stats.total === 0 ? (
                            <p className="py-10 text-center text-sm text-daiku-muted">Belum ada data lead.</p>
                        ) : (
                            <ResponsiveContainer width="100%" height={280}>
                                <FunnelChart>
                                    <Tooltip
                                        formatter={(value, _name, item) => [`${value} lead`, item?.payload?.name]}
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
                        <p className="mt-2 text-xs text-daiku-muted">
                            Lead LOST ({stats.lost}) tidak dihitung dalam funnel — cabang terminal, bukan tahap pipeline.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Lead per Sumber</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {bySource.length === 0 ? (
                            <p className="py-10 text-center text-sm text-daiku-muted">Belum ada data lead.</p>
                        ) : (
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={bySource} layout="vertical" margin={{ left: 16 }}>
                                    <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-daiku-border" />
                                    <XAxis type="number" allowDecimals={false} tick={{ fontSize: 12 }} />
                                    <YAxis type="category" dataKey="source" width={90} tick={{ fontSize: 12 }} />
                                    <Tooltip formatter={(value) => [`${value} lead`, 'Jumlah']} />
                                    <Bar dataKey="total" radius={[0, 4, 4, 0]} maxBarSize={24}>
                                        {bySource.map((entry) => (
                                            <Cell key={entry.source} fill="var(--color-daiku-yellow)" />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
