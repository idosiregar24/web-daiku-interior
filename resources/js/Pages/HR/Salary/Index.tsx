import { AXIS_TICK, GRID_PROPS, RupiahTooltip, VIZ } from '@/Components/modules/analytics/chartTheme';
import { salaryChangeColumns } from '@/Components/modules/hr/SalaryChangeColumns';
import { SalaryChangeDialog } from '@/Components/modules/hr/SalaryChangeDialog';
import type { SalaryChangeRow, SalaryEmployeeOption } from '@/Components/modules/hr/SalaryCommon';
import { type SalaryDecision, SalaryDecisionDialog } from '@/Components/modules/hr/SalaryDecisionDialog';
import { StructureFilter } from '@/Components/modules/hr/StructureFilter';
import { DataTable } from '@/Components/shared/DataTable';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { UnderlineTabsList } from '@/Components/shared/UnderlineTabsList';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { formatRupiah, formatRupiahCompact } from '@/lib/format';
import type { Division, PaginatedData, SalaryChangeStatus } from '@/types';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { BadgeDollarSign, BarChart3, Building2, CalendarClock, Clock, History, Plus, Users, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type Tab = 'changes' | 'recap';

interface MonthTotal {
    period: string;
    label: string;
    total: number;
    count: number;
}

interface BreakdownRow {
    name: string;
    division: string | null;
    employees: number;
    base_salary: number;
    allowance: number;
    deduction: number;
    total: number;
}

interface SalaryIndexProps {
    changes: PaginatedData<SalaryChangeRow>;
    filters: { status: SalaryChangeStatus | null; division: number | null; position: number | null; month: string; tab: Tab };
    summary: {
        pending_count: number;
        scheduled_count: number;
        paid_last_month: number;
        last_month_label: string;
        base_salary_total: number;
    };
    recap: {
        months: MonthTotal[];
        breakdown: { period: string; label: string; total: number; by_division: BreakdownRow[]; by_position: BreakdownRow[] };
    };
    structure: Division[];
    canManage: boolean;
    canDecide: boolean;
    employees: SalaryEmployeeOption[];
    /** `?request_for=&review=` — opens the request dialog prefilled (review "naik gaji" shortcut). */
    prefill: { employee_id: number; performance_review_id: number | null } | null;
}

const TAB_LABEL: Record<Tab, string> = { changes: 'Perubahan Gaji', recap: 'Rekap Beban Gaji' };
const ALL = 'all';
const STATUS_LABEL: Record<SalaryChangeStatus, string> = { PENDING: 'Menunggu CEO', APPROVED: 'Disetujui', REJECTED: 'Ditolak' };

/**
 * SDM (Sprint 10, decision #3, §3.2) — base-salary changes (HR requests,
 * CEO approves/rejects) and the salary-cost recap per month and per
 * division/position. Finance still pays salaries on Penggajian.
 */
export default function SalaryIndex({ changes, filters, summary, recap, structure, canManage, canDecide, employees, prefill }: SalaryIndexProps) {
    const [tab, setTab] = useState<Tab>(filters.tab);
    const [requestOpen, setRequestOpen] = useState(prefill !== null);
    const [requestFor, setRequestFor] = useState(prefill);
    const [deciding, setDeciding] = useState<SalaryChangeRow | null>(null);
    const [decision, setDecision] = useState<SalaryDecision>('approve');

    useEffect(() => {
        if (prefill) {
            setRequestFor(prefill);
            setRequestOpen(true);
        }
    }, [prefill?.employee_id, prefill?.performance_review_id]);

    function visit(next: Partial<SalaryIndexProps['filters']>) {
        const merged = { ...filters, tab, ...next };

        router.get(
            route('hr.salary.index'),
            {
                status: merged.status ?? undefined,
                division: merged.division ?? undefined,
                position: merged.position ?? undefined,
                month: merged.month,
                tab: merged.tab === 'recap' ? 'recap' : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function openRequest() {
        setRequestFor(null);
        setRequestOpen(true);
    }

    function closeRequest(open: boolean) {
        setRequestOpen(open);

        // Drop ?request_for / ?review so a reload doesn't reopen the dialog.
        if (!open && new URLSearchParams(window.location.search).has('request_for')) visit({});
    }

    const columns = salaryChangeColumns({
        showEmployee: true,
        onDecide: canDecide
            ? (change, next) => {
                  setDecision(next);
                  setDeciding(change);
              }
            : undefined,
    });

    const breakdownColumns = (label: string, showDivision: boolean): ColumnDef<BreakdownRow>[] => [
        {
            accessorKey: 'name',
            header: label,
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.name}</p>
                    {showDivision && row.original.division && <p className="text-xs text-daiku-muted">{row.original.division}</p>}
                </div>
            ),
        },
        {
            accessorKey: 'employees',
            header: () => <div className="text-right">Karyawan</div>,
            cell: ({ row }) => <div className="text-right tabular-nums">{row.original.employees}</div>,
        },
        {
            accessorKey: 'base_salary',
            header: () => <div className="text-right">Gaji Pokok</div>,
            cell: ({ row }) => <div className="text-right tabular-nums">{formatRupiah(row.original.base_salary)}</div>,
        },
        {
            id: 'adjustments',
            header: () => <div className="text-right">Tunjangan / Potongan</div>,
            enableSorting: false,
            cell: ({ row }) => (
                <div className="text-right text-xs tabular-nums">
                    <p className="text-success-ink">+ {formatRupiah(row.original.allowance)}</p>
                    <p className="text-error-ink">− {formatRupiah(row.original.deduction)}</p>
                </div>
            ),
        },
        {
            accessorKey: 'total',
            header: () => <div className="text-right">Total Dibayar</div>,
            cell: ({ row }) => <div className="text-right font-semibold text-daiku-dark tabular-nums">{formatRupiah(row.original.total)}</div>,
        },
    ];

    const hasFilter = Boolean(filters.status || filters.division || filters.position);
    const months = recap.months;
    const chartData = months.map((m) => ({ ...m, short: m.label.replace(/^(\w{3})\w*\s(\d{2})(\d{2})$/, '$1 $3') }));
    const twelveMonthTotal = months.reduce((sum, m) => sum + m.total, 0);

    return (
        <AppLayout breadcrumbs={[{ label: TAB_LABEL[tab] }]}>
            <Head title="Gaji" />

            <PageHeader
                title="Gaji"
                icon={BadgeDollarSign}
                description="Perubahan gaji pokok diajukan SDM dan disetujui CEO. Pembayaran gaji tetap dilakukan Finance di Penggajian."
                actions={
                    canManage && (
                        <Button onClick={openRequest} disabled={employees.length === 0}>
                            <Plus className="size-4" />
                            Ajukan Perubahan Gaji
                        </Button>
                    )
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Menunggu Keputusan CEO"
                    value={summary.pending_count}
                    icon={Clock}
                    tone={summary.pending_count > 0 ? 'warning' : 'default'}
                />
                <StatCard label="Terjadwal Berlaku" value={summary.scheduled_count} icon={CalendarClock} hint="Disetujui, menunggu tanggal berlaku" />
                <StatCard label={`Gaji Dibayar · ${summary.last_month_label}`} value={formatRupiah(summary.paid_last_month)} icon={Wallet} />
                <StatCard label="Total Gaji Pokok Aktif" value={formatRupiah(summary.base_salary_total)} icon={Users} hint="Per bulan, karyawan aktif" />
            </div>

            <Tabs
                value={tab}
                onValueChange={(value) => {
                    setTab(value as Tab);
                    visit({ tab: value as Tab });
                }}
            >
                <UnderlineTabsList>
                    <TabsTrigger value="changes">
                        <History />
                        Perubahan Gaji
                    </TabsTrigger>
                    <TabsTrigger value="recap">
                        <BarChart3 />
                        Rekap Beban Gaji
                    </TabsTrigger>
                </UnderlineTabsList>

                <TabsContent value="changes" className="mt-6">
                    <DataTable
                        columns={columns}
                        data={changes.data}
                        pagination={changes}
                        emptyMessage={hasFilter ? 'Tidak ada pengajuan yang cocok dengan filter.' : 'Belum ada pengajuan perubahan gaji.'}
                        toolbar={
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Select
                                    value={filters.status ?? ALL}
                                    onValueChange={(value) => visit({ status: value === ALL ? null : (value as SalaryChangeStatus) })}
                                >
                                    <SelectTrigger className="sm:w-44" aria-label="Filter status">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>Semua status</SelectItem>
                                        {(Object.keys(STATUS_LABEL) as SalaryChangeStatus[]).map((status) => (
                                            <SelectItem key={status} value={status}>
                                                {STATUS_LABEL[status]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <StructureFilter
                                    structure={structure}
                                    division={filters.division}
                                    position={filters.position}
                                    onChange={(value) => visit(value)}
                                />
                            </div>
                        }
                    />
                </TabsContent>

                <TabsContent value="recap" className="mt-6 space-y-6">
                    <SectionCard
                        title="Beban Gaji per Bulan"
                        description={`Total gaji dibayar 12 bulan terakhir: ${formatRupiah(twelveMonthTotal)}.`}
                        icon={BarChart3}
                    >
                        {twelveMonthTotal > 0 ? (
                            <div
                                className="h-64"
                                role="img"
                                aria-label={`Beban gaji per bulan: ${months.map((m) => `${m.label} ${formatRupiah(m.total)}`).join(', ')}`}
                            >
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={chartData}
                                        margin={{ left: 4, right: 8, top: 8 }}
                                        onClick={(state) => {
                                            const index = Number(state?.activeTooltipIndex ?? -1);

                                            if (index >= 0 && chartData[index]) visit({ month: chartData[index].period, tab: 'recap' });
                                        }}
                                    >
                                        <CartesianGrid {...GRID_PROPS} />
                                        <XAxis dataKey="short" tick={AXIS_TICK} axisLine={false} tickLine={false} />
                                        <YAxis
                                            tick={AXIS_TICK}
                                            axisLine={false}
                                            tickLine={false}
                                            width={64}
                                            tickFormatter={(value: number) => formatRupiahCompact(value)}
                                        />
                                        <Tooltip
                                            cursor={{ fill: 'var(--color-daiku-gray)' }}
                                            content={<RupiahTooltip />}
                                            labelFormatter={(_, payload) => payload?.[0]?.payload?.label ?? ''}
                                        />
                                        <Bar
                                            dataKey="total"
                                            name="Gaji dibayar"
                                            fill={VIZ.series1}
                                            radius={[4, 4, 0, 0]}
                                            maxBarSize={36}
                                            isAnimationActive={false}
                                            className="cursor-pointer"
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <EmptyState title="Belum ada gaji yang dibayarkan dalam 12 bulan terakhir." icon={Wallet} />
                        )}
                    </SectionCard>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-daiku-muted">
                            Rincian {recap.breakdown.label}: <span className="font-semibold text-daiku-dark">{formatRupiah(recap.breakdown.total)}</span>.
                            Divisi & jabatan mengikuti posisi karyawan saat ini.
                        </p>
                        <Select value={filters.month} onValueChange={(value) => visit({ month: value, tab: 'recap' })}>
                            <SelectTrigger className="sm:w-48" aria-label="Pilih bulan rincian">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[...months].reverse().map((m) => (
                                    <SelectItem key={m.period} value={m.period}>
                                        {m.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-6 xl:grid-cols-2">
                        <DataTable
                            columns={breakdownColumns('Divisi', false)}
                            data={recap.breakdown.by_division}
                            emptyMessage={`Belum ada gaji dibayar untuk ${recap.breakdown.label}.`}
                            toolbar={
                                <p className="flex items-center gap-2 text-sm font-medium text-daiku-dark">
                                    <Building2 className="size-4 text-daiku-muted" />
                                    Per Divisi
                                </p>
                            }
                        />
                        <DataTable
                            columns={breakdownColumns('Jabatan', true)}
                            data={recap.breakdown.by_position}
                            emptyMessage={`Belum ada gaji dibayar untuk ${recap.breakdown.label}.`}
                            toolbar={
                                <p className="flex items-center gap-2 text-sm font-medium text-daiku-dark">
                                    <Users className="size-4 text-daiku-muted" />
                                    Per Jabatan
                                </p>
                            }
                        />
                    </div>
                </TabsContent>
            </Tabs>

            {canManage && (
                <SalaryChangeDialog
                    open={requestOpen}
                    onOpenChange={closeRequest}
                    employees={employees}
                    employeeId={requestFor?.employee_id ?? null}
                    performanceReviewId={requestFor?.performance_review_id ?? null}
                />
            )}
            {canDecide && (
                <SalaryDecisionDialog change={deciding} decision={decision} onOpenChange={(open) => !open && setDeciding(null)} />
            )}
        </AppLayout>
    );
}
