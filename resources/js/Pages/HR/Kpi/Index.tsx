import { KpiOpenPeriodDialog } from '@/Components/modules/hr/KpiOpenPeriodDialog';
import { KpiScoreTable } from '@/Components/modules/hr/KpiScoreTable';
import { KpiTrendChart } from '@/Components/modules/hr/KpiTrendChart';
import {
    formatKpiValue,
    kpiTotalLabel,
    kpiTotalTone,
    type KpiBoardRow,
    type KpiCompanyTrendPoint,
    type KpiPeriodOption,
    type KpiPeriodProgress,
} from '@/Components/modules/hr/KpiTypes';
import { StructureFilter } from '@/Components/modules/hr/StructureFilter';
import { DataTable } from '@/Components/shared/DataTable';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime } from '@/lib/format';
import type { Division } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Calculator, CalendarPlus, ClipboardList, Lock, Target, TrendingUp, UserCheck, UserX } from 'lucide-react';
import { useMemo, useState } from 'react';

interface KpiIndexProps {
    periods: KpiPeriodOption[];
    selected: (KpiPeriodOption & { progress: KpiPeriodProgress }) | null;
    board: KpiBoardRow[];
    trend: KpiCompanyTrendPoint[];
    filters: { division: number | null; position: number | null };
    structure: Division[];
    currentMonth: string;
    canManage: boolean;
}

const PERIOD_LABEL = { OPEN: 'Terbuka', CLOSED: 'Ditutup' } as const;

/**
 * SDM (Sprint 10, §3.3) — monthly KPI: pick a month, HR computes AUTO
 * values, fills MANUAL ones and closes the month (locked for good). The
 * CEO reads the same page without actions.
 */
export default function KpiIndex({ periods, selected, board, trend, filters, structure, currentMonth, canManage }: KpiIndexProps) {
    const [openDialog, setOpenDialog] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    // compute/close/manual refusals come back as a validation error on `period`.
    const periodError = (usePage().props.errors as Record<string, string | undefined>).period;

    const isOpen = selected?.status === 'OPEN';
    const editable = canManage && isOpen;
    // Derived from props so a saved manual value shows up immediately.
    const detail = useMemo(() => board.find((row) => row.employeeId === detailId) ?? null, [board, detailId]);
    const scored = board.filter((row) => row.total !== null);
    const average = scored.length ? scored.reduce((sum, row) => sum + (row.total ?? 0), 0) / scored.length : null;

    function visit(next: { period?: string; division?: number | null; position?: number | null }) {
        const merged = { period: selected?.period, ...filters, ...next };

        router.get(
            route('hr.kpi.index'),
            {
                period: merged.period ?? undefined,
                division: merged.division ?? undefined,
                position: merged.position ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function post(routeName: 'hr.kpi.periods.compute' | 'hr.kpi.periods.close', onDone?: () => void) {
        if (!selected) return;
        router.post(
            route(routeName, { kpi_period: selected.id }),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    onDone?.();
                },
            },
        );
    }

    const columns: ColumnDef<KpiBoardRow>[] = [
        {
            accessorKey: 'rank',
            header: 'Peringkat',
            cell: ({ row }) => (
                <span className="tabular-nums">
                    <span className="font-semibold text-foreground">{row.original.rank}</span>
                    <span className="text-daiku-muted"> / {row.original.rankOf}</span>
                </span>
            ),
        },
        {
            accessorKey: 'name',
            header: 'Karyawan',
            cell: ({ row }) => (
                <div>
                    <button
                        type="button"
                        onClick={() => setDetailId(row.original.employeeId)}
                        className="font-medium text-daiku-dark underline-offset-4 hover:underline decoration-daiku-yellow text-left"
                    >
                        {row.original.name}
                    </button>
                    {!row.original.hasAccount && <p className="text-xs text-daiku-muted">Tanpa akun sistem</p>}
                </div>
            ),
        },
        {
            id: 'position',
            accessorFn: (row) => `${row.divisionName} ${row.positionName}`,
            header: 'Jabatan',
            cell: ({ row }) => (
                <div>
                    <p>{row.original.positionName}</p>
                    <p className="text-xs text-daiku-muted">{row.original.divisionName}</p>
                </div>
            ),
        },
        {
            id: 'total',
            accessorFn: (row) => row.total ?? -1,
            header: 'Total',
            cell: ({ row }) => <span className="font-semibold tabular-nums">{formatKpiValue(row.original.total)}</span>,
        },
        {
            id: 'status',
            header: 'Status',
            enableSorting: false,
            cell: ({ row }) =>
                row.original.manualMissing > 0 ? (
                    <StatusChip status="KPI_MANUAL_MISSING" tone="warning" label={`${row.original.manualMissing} nilai manual kosong`} />
                ) : (
                    <StatusChip
                        status="KPI_TOTAL"
                        tone={kpiTotalTone(row.original.total)}
                        label={kpiTotalLabel(row.original.total)}
                    />
                ),
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) => (
                <div className="flex justify-end">
                    <Button variant="ghost" size="sm" onClick={() => setDetailId(row.original.employeeId)}>
                        {editable ? 'Rincian & isi' : 'Rincian'}
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AppLayout>
            <Head title="KPI Karyawan" />

            <PageHeader
                title="KPI Karyawan"
                icon={Target}
                description="Penilaian KPI bulanan per jabatan. Indikator otomatis dihitung dari aktivitas di sistem, indikator manual diisi SDM; periode yang ditutup terkunci."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href={route('hr.kpi.templates.index')}>
                                <ClipboardList className="size-4" />
                                Template KPI
                            </Link>
                        </Button>
                        {canManage && (
                            <Button onClick={() => setOpenDialog(true)}>
                                <CalendarPlus className="size-4" />
                                Buka Periode
                            </Button>
                        )}
                    </>
                }
            />

            {!selected ? (
                <SectionCard title="Belum ada periode KPI">
                    <EmptyState
                        title="Belum ada periode KPI."
                        description="Periode bulan berjalan dibuka otomatis setiap tanggal 1, atau SDM bisa membukanya sekarang."
                        icon={Target}
                    />
                </SectionCard>
            ) : (
                <div className="space-y-6">
                    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                        <Select value={selected.period} onValueChange={(period) => visit({ period })}>
                            <SelectTrigger className="sm:w-52" aria-label="Pilih periode">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {periods.map((period) => (
                                    <SelectItem key={period.id} value={period.period}>
                                        {period.label} · {PERIOD_LABEL[period.status]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <StatusChip status={selected.status} label={PERIOD_LABEL[selected.status]} />
                        {selected.status === 'CLOSED' && (
                            <span className="text-sm text-daiku-muted">
                                Ditutup {selected.closerName ? `oleh ${selected.closerName} ` : ''}
                                {formatDateTime(selected.closedAt)}
                            </span>
                        )}
                        {editable && (
                            <div className="flex gap-2 sm:ml-auto">
                                <Button variant="outline" disabled={busy} onClick={() => post('hr.kpi.periods.compute')}>
                                    <Calculator className="size-4" />
                                    Hitung KPI
                                </Button>
                                <Button disabled={busy || !selected.progress.computed} onClick={() => setConfirmClose(true)}>
                                    <Lock className="size-4" />
                                    Tutup Periode
                                </Button>
                            </div>
                        )}
                    </div>

                    {periodError && <Notice tone="error">{periodError}</Notice>}
                    {isOpen && !selected.progress.computed && (
                        <Notice tone="info">
                            Periode {selected.label} belum dihitung.{' '}
                            {canManage ? 'Klik "Hitung" untuk menyiapkan skor semua karyawan yang jabatannya punya template KPI aktif.' : 'Menunggu SDM menghitung.'}
                        </Notice>
                    )}
                    {isOpen && selected.progress.manualMissing > 0 && (
                        <Notice tone="warning">
                            {selected.progress.manualMissing} nilai indikator manual belum diisi — periode belum bisa ditutup sebelum lengkap.
                        </Notice>
                    )}
                    {isOpen && selected.progress.computed && (
                        <Notice tone="info">
                            Nilai otomatis dihitung ulang setiap kali "Hitung" diklik dan saat periode ditutup. Setelah ditutup, koreksi data
                            operasional tidak lagi mengubah skor bulan ini.
                        </Notice>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard label="Rata-rata total" value={formatKpiValue(average)} icon={TrendingUp} hint="Skala 0–120, 100 = sesuai target" />
                        <StatCard label="Karyawan dinilai" value={selected.progress.employees} icon={UserCheck} />
                        <StatCard
                            label="Nilai manual kosong"
                            value={selected.progress.manualMissing}
                            icon={ClipboardList}
                            tone={selected.progress.manualMissing > 0 ? 'warning' : 'default'}
                        />
                        <StatCard
                            label="Indikator otomatis tanpa data"
                            value={selected.progress.autoMissing}
                            icon={UserX}
                            hint="Bobotnya dibagi ke indikator lain"
                        />
                    </div>

                    <DataTable
                        columns={columns}
                        data={board}
                        emptyMessage={
                            selected.progress.computed ? 'Tidak ada karyawan yang cocok dengan filter.' : 'Periode ini belum dihitung.'
                        }
                        toolbar={
                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                <StructureFilter
                                    structure={structure}
                                    division={filters.division}
                                    position={filters.position}
                                    onChange={(value) => visit(value)}
                                />
                            </div>
                        }
                    />

                    <SectionCard
                        title="Rata-rata KPI perusahaan per bulan"
                        description="Rata-rata total karyawan yang dinilai, 12 periode terakhir. Garis putus-putus = 100 (sesuai target)."
                        icon={TrendingUp}
                    >
                        {trend.some((point) => point.average !== null) ? (
                            <KpiTrendChart
                                name="Rata-rata total"
                                ariaLabel="Grafik rata-rata KPI perusahaan per bulan"
                                data={trend.map((point) => ({
                                    label: point.label,
                                    value: point.average,
                                    note: `${point.employees} karyawan · ${PERIOD_LABEL[point.status]}`,
                                }))}
                            />
                        ) : (
                            <EmptyState title="Belum ada nilai KPI." icon={TrendingUp} />
                        )}
                    </SectionCard>
                </div>
            )}

            <Dialog open={detail !== null} onOpenChange={(open) => !open && setDetailId(null)}>
                <DialogContent className="sm:max-w-3xl">
                    {detail && selected && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {detail.name} — {selected.label}
                                </DialogTitle>
                                <DialogDescription>
                                    {detail.positionName} · {detail.divisionName} · peringkat {detail.rank} dari {detail.rankOf}
                                </DialogDescription>
                            </DialogHeader>
                            {!detail.hasAccount && detail.autoMissing > 0 && (
                                <Notice tone="warning">
                                    Karyawan ini tidak punya akun sistem, jadi indikator otomatis tidak bisa dihitung. Bobotnya dibagi ke indikator lain.
                                </Notice>
                            )}
                            <KpiScoreTable rows={detail.rows} total={detail.total} editable={editable} hasAccount={detail.hasAccount} />
                            <DialogFooter>
                                <Button variant="outline" asChild>
                                    <Link href={route('hr.employees.show', { employee: detail.employeeId })}>Profil karyawan</Link>
                                </Button>
                                <DialogClose asChild>
                                    <Button>Tutup</Button>
                                </DialogClose>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={confirmClose} onOpenChange={setConfirmClose}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Tutup periode {selected?.label}?</DialogTitle>
                        <DialogDescription>
                            Nilai otomatis dihitung ulang sekali lagi, lalu semua skor bulan ini dikunci permanen. Karyawan bisa melihat KPI
                            bulan ini di menu Milik Saya setelah ditutup.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button disabled={busy} onClick={() => post('hr.kpi.periods.close', () => setConfirmClose(false))}>
                            <Lock className="size-4" />
                            Tutup & Kunci
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {canManage && <KpiOpenPeriodDialog open={openDialog} onOpenChange={setOpenDialog} currentMonth={currentMonth} />}
        </AppLayout>
    );
}
