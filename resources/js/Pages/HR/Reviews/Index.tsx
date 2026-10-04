import { ReviewCreateDialog, type ReviewEmployeeOption } from '@/Components/modules/hr/ReviewCreateDialog';
import { formatScore, REVIEW_STATUS_LABEL, REVIEW_STATUSES, REVIEW_RECOMMENDATION_LABEL } from '@/Components/modules/hr/ReviewShared';
import { StructureFilter } from '@/Components/modules/hr/StructureFilter';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { Division, ReviewGrade, ReviewRecommendation, ReviewStatus } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CheckCheck, CircleCheck, ClipboardList, FilePen, Hourglass, Plus, UsersRound } from 'lucide-react';
import { useState } from 'react';

interface ReviewRow {
    id: number;
    employee: { id: number; name: string; position: string | null; division: string | null };
    year: number;
    semester: 1 | 2;
    period_label: string;
    final_score: number | null;
    grade: ReviewGrade | null;
    recommendation: ReviewRecommendation | null;
    status: ReviewStatus;
    has_return_note: boolean;
    reviewer: string | null;
}

interface Filters {
    year: number;
    semester: 1 | 2 | null;
    status: ReviewStatus | null;
    division: number | null;
    position: number | null;
}

interface ReviewsIndexProps {
    reviews: ReviewRow[];
    counts: Record<ReviewStatus, number>;
    filters: Filters;
    years: number[];
    current: { year: number; semester: 1 | 2 };
    previous: { year: number; semester: 1 | 2 };
    structure: Division[];
    canManage: boolean;
    employees: ReviewEmployeeOption[];
    existing: string[];
}

const ALL = 'all';

const STATUS_ICON = { DRAFT: FilePen, SUBMITTED: Hourglass, APPROVED: CircleCheck, ACKNOWLEDGED: CheckCheck } as const;

/**
 * SDM (Sprint 10, §3.4) — semester performance reviews. HR writes them
 * (DRAFT → SUBMITTED), the CEO approves or returns, the employee confirms
 * reading. Default view: the last completed semester.
 */
export default function ReviewsIndex({
    reviews,
    counts,
    filters,
    years,
    current,
    previous,
    structure,
    canManage,
    employees,
    existing,
}: ReviewsIndexProps) {
    const [dialog, setDialog] = useState<'single' | 'bulk' | null>(null);

    function applyFilter(next: Partial<Filters>) {
        const merged = { ...filters, ...next };

        router.get(
            route('hr.reviews.index'),
            {
                year: merged.year,
                semester: merged.semester ?? ALL,
                status: merged.status ?? undefined,
                division: merged.division ?? undefined,
                position: merged.position ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const columns: ColumnDef<ReviewRow>[] = [
        {
            id: 'employee',
            accessorFn: (row) => row.employee.name,
            header: 'Karyawan',
            cell: ({ row }) => (
                <Link
                    href={route('hr.reviews.show', { performance_review: row.original.id })}
                    className="font-medium text-daiku-dark underline-offset-4 hover:underline decoration-daiku-yellow"
                >
                    {row.original.employee.name}
                </Link>
            ),
        },
        {
            id: 'position',
            accessorFn: (row) => row.employee.position ?? '',
            header: 'Jabatan',
            cell: ({ row }) => (
                <div>
                    <p>{row.original.employee.position ?? '—'}</p>
                    <p className="text-xs text-daiku-muted">{row.original.employee.division ?? ''}</p>
                </div>
            ),
        },
        {
            id: 'period',
            accessorFn: (row) => row.year * 10 + row.semester,
            header: 'Semester',
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.period_label}</span>,
        },
        {
            accessorKey: 'final_score',
            header: 'Nilai Akhir',
            cell: ({ row }) => <span className="tabular-nums">{formatScore(row.original.final_score, 2)}</span>,
        },
        {
            accessorKey: 'grade',
            header: 'Grade',
            cell: ({ row }) => <span className="font-semibold">{row.original.grade ?? '—'}</span>,
        },
        {
            accessorKey: 'recommendation',
            header: 'Rekomendasi',
            cell: ({ row }) => (
                <span className="text-daiku-muted">
                    {row.original.recommendation ? REVIEW_RECOMMENDATION_LABEL[row.original.recommendation] : '—'}
                </span>
            ),
        },
        {
            accessorKey: 'status',
            header: 'Status',
            cell: ({ row }) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusChip status={row.original.status} label={REVIEW_STATUS_LABEL[row.original.status]} />
                    {row.original.status === 'DRAFT' && row.original.has_return_note && (
                        <StatusChip status="RETURNED" tone="warning" label="Dikembalikan" />
                    )}
                </div>
            ),
        },
    ];

    const createDefaults = filters.semester ? { year: filters.year, semester: filters.semester } : previous;

    return (
        <AppLayout>
            <Head title="Evaluasi Kinerja" />

            <PageHeader
                title="Evaluasi Kinerja"
                icon={ClipboardList}
                description="Evaluasi per semester: rata-rata KPI bulanan yang sudah ditutup, kedisiplinan, dan aspek kualitatif. Disusun SDM, disetujui CEO."
                actions={
                    canManage && (
                        <>
                            <Button variant="outline" onClick={() => setDialog('bulk')}>
                                <UsersRound className="size-4" />
                                Buat untuk Semua Karyawan
                            </Button>
                            <Button onClick={() => setDialog('single')}>
                                <Plus className="size-4" />
                                Buat Evaluasi
                            </Button>
                        </>
                    )
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {REVIEW_STATUSES.map((status) => (
                    <StatCard
                        key={status}
                        label={REVIEW_STATUS_LABEL[status]}
                        icon={STATUS_ICON[status]}
                        value={counts[status] ?? 0}
                        tone={status === 'SUBMITTED' && counts[status] > 0 ? 'warning' : 'default'}
                        hint={filters.semester ? `Semester ${filters.semester} ${filters.year}` : `Tahun ${filters.year}`}
                    />
                ))}
            </div>

            <DataTable
                columns={columns}
                data={reviews}
                emptyMessage="Belum ada evaluasi untuk filter ini."
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Select value={String(filters.year)} onValueChange={(value) => applyFilter({ year: Number(value) })}>
                            <SelectTrigger className="sm:w-28" aria-label="Filter tahun">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {years.map((year) => (
                                    <SelectItem key={year} value={String(year)}>
                                        {year}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.semester ? String(filters.semester) : ALL}
                            onValueChange={(value) => applyFilter({ semester: value === ALL ? null : (Number(value) as 1 | 2) })}
                        >
                            <SelectTrigger className="sm:w-44" aria-label="Filter semester">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Semua semester</SelectItem>
                                <SelectItem value="1">Semester 1 (Jan–Jun)</SelectItem>
                                <SelectItem value="2">Semester 2 (Jul–Des)</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.status ?? ALL}
                            onValueChange={(value) => applyFilter({ status: value === ALL ? null : (value as ReviewStatus) })}
                        >
                            <SelectTrigger className="sm:w-40" aria-label="Filter status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Semua status</SelectItem>
                                {REVIEW_STATUSES.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {REVIEW_STATUS_LABEL[status]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <StructureFilter
                            structure={structure}
                            division={filters.division}
                            position={filters.position}
                            onChange={(value) => applyFilter(value)}
                        />
                    </div>
                }
            />

            {canManage && dialog && (
                <ReviewCreateDialog
                    open={dialog !== null}
                    onOpenChange={(open) => !open && setDialog(null)}
                    mode={dialog}
                    employees={employees}
                    existing={existing}
                    years={years}
                    current={current}
                    defaults={createDefaults}
                />
            )}
        </AppLayout>
    );
}
