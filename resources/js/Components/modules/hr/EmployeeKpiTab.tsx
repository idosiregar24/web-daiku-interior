import { KpiScoreTable } from '@/Components/modules/hr/KpiScoreTable';
import { KpiTrendChart } from '@/Components/modules/hr/KpiTrendChart';
import {
    formatKpiValue,
    KPI_SOURCE_LABEL,
    kpiTotalLabel,
    kpiTotalTone,
    type KpiScoreRow,
} from '@/Components/modules/hr/KpiTypes';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import type { Employee, KpiDirection, KpiIndicatorSource, KpiPeriodStatus } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronUp, ExternalLink, Target, TrendingUp } from 'lucide-react';
import { useState } from 'react';

/**
 * Data shape returned by KpiService::forEmployee() (passed straight through
 * by HR\EmployeeController::show() and the "Milik Saya" page). With
 * `closedOnly` the server already dropped OPEN months (decision #6).
 */
export interface EmployeeKpiData {
    template: {
        isActive: boolean;
        positionName: string;
        indicators: {
            name: string;
            source: KpiIndicatorSource;
            metricLabel: string | null;
            target: number;
            weight: number;
            direction: KpiDirection;
            unit: string | null;
        }[];
    } | null;
    hasAccount: boolean;
    /** Newest first, up to 12. */
    periods: {
        id: number;
        period: string;
        label: string;
        status: KpiPeriodStatus;
        total: number | null;
        rows: KpiScoreRow[];
    }[];
    /** Oldest first, for the chart. */
    trend: { period: string; label: string; total: number | null }[];
}

export interface EmployeeKpiTabProps {
    employee: Pick<Employee, 'id' | 'name' | 'is_active'>;
    data: EmployeeKpiData;
    /** HR (or SUPERADMIN) may act; false on the CEO's view and on "Milik Saya". */
    canManage: boolean;
    /** The employee's own read-only view ("Milik Saya"). */
    selfView?: boolean;
}

const PERIOD_LABEL = { OPEN: 'Terbuka', CLOSED: 'Ditutup' } as const;

/** KPI tab of the employee profile: monthly totals trend + per-month breakdown. */
export function EmployeeKpiTab({ employee, data, canManage, selfView = false }: EmployeeKpiTabProps) {
    const periods = data?.periods ?? [];
    const [expanded, setExpanded] = useState<number | null>(periods[0]?.id ?? null);

    return (
        <div className="space-y-6">
            {!selfView && !data?.template && (
                <Notice tone="info">Jabatan {employee.name} belum punya template KPI — atur di halaman Template KPI.</Notice>
            )}
            {!selfView && data?.template && !data.hasAccount && data.template.indicators.some((row) => row.source === 'AUTO') && (
                <Notice tone="warning">
                    {employee.name} tidak punya akun sistem, jadi indikator otomatis tidak bisa dihitung. Bobotnya dibagi ke indikator lain.
                </Notice>
            )}

            {periods.length === 0 ? (
                <SectionCard title="KPI Bulanan" icon={Target}>
                    <EmptyState
                        title={selfView ? 'Belum ada periode KPI yang ditutup.' : 'Belum ada nilai KPI.'}
                        description={selfView ? 'Nilai KPI tampil di sini setelah SDM menutup periode bulanannya.' : undefined}
                        icon={Target}
                    />
                </SectionCard>
            ) : (
                <>
                    {data.trend.length > 1 && (
                        <SectionCard
                            title="Total KPI per bulan"
                            description="Skala 0–120. Garis putus-putus = 100 (sesuai target)."
                            icon={TrendingUp}
                        >
                            <KpiTrendChart
                                name="Total KPI"
                                ariaLabel={`Grafik total KPI ${employee.name} per bulan`}
                                data={data.trend.map((point) => ({ label: point.label, value: point.total }))}
                            />
                        </SectionCard>
                    )}

                    <SectionCard title="Rincian per bulan" icon={Target} flush>
                        <ul className="divide-y divide-border">
                            {periods.map((period) => {
                                const open = expanded === period.id;

                                return (
                                    <li key={period.id} className="px-4 py-3">
                                        <div className="flex flex-wrap items-center gap-3">
                                            <button
                                                type="button"
                                                onClick={() => setExpanded(open ? null : period.id)}
                                                aria-expanded={open}
                                                className="flex min-w-0 flex-1 items-center gap-2 text-left"
                                            >
                                                {open ? <ChevronUp className="size-4 text-daiku-muted" /> : <ChevronDown className="size-4 text-daiku-muted" />}
                                                <span className="font-medium text-foreground">{period.label}</span>
                                                {!selfView && <StatusChip status={period.status} label={PERIOD_LABEL[period.status]} />}
                                            </button>
                                            <span className="font-semibold tabular-nums">{formatKpiValue(period.total)}</span>
                                            <StatusChip status="KPI_TOTAL" tone={kpiTotalTone(period.total)} label={kpiTotalLabel(period.total)} />
                                            {canManage && !selfView && (
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={route('hr.kpi.index', { period: period.period })}>
                                                        <ExternalLink className="size-4" />
                                                        Halaman KPI
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                        {open && (
                                            <div className="mt-3">
                                                <KpiScoreTable rows={period.rows} total={period.total} hasAccount={data.hasAccount} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </SectionCard>
                </>
            )}

            {data?.template && (
                <SectionCard
                    title={`Template KPI — ${data.template.positionName}`}
                    description={data.template.isActive ? 'Indikator yang berlaku untuk periode berikutnya.' : 'Template nonaktif.'}
                    icon={Target}
                >
                    <ul className="space-y-1 text-sm">
                        {data.template.indicators.map((row) => (
                            <li key={row.name} className="flex flex-wrap items-center gap-2">
                                <span className="font-medium text-foreground">{row.name}</span>
                                <span className="text-daiku-muted">
                                    {KPI_SOURCE_LABEL[row.source]}
                                    {row.metricLabel ? ` · ${row.metricLabel}` : ''} · target {formatKpiValue(row.target, row.unit)} · bobot{' '}
                                    {formatKpiValue(row.weight, '%')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}
        </div>
    );
}
