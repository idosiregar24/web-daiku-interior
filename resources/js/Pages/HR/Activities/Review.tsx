import { DailyActivityClarifyDialog } from '@/Components/modules/hr/DailyActivityClarifyDialog';
import { DailyActivityList, dayLabel } from '@/Components/modules/hr/DailyActivityList';
import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { DataTable } from '@/Components/shared/DataTable';
import { DatePicker } from '@/Components/shared/DatePicker';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { quietSuccess } from '@/hooks/useFlashToasts';
import AppLayout from '@/Layouts/AppLayout';
import type { DailyActivityEntry, DailyActivityReviewInfo, DailyActivityReviewStatus } from '@/types';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { addDays, format, parseISO } from 'date-fns';
import { CheckCheck, ChevronLeft, ChevronRight, ClipboardCheck, Loader2, MessageSquareWarning, NotebookPen, UserX, Users } from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface ReviewRow {
    id: number;
    user_id: number;
    name: string;
    position: string | null;
    division: string | null;
    activities: DailyActivityEntry[];
    review: DailyActivityReviewInfo | null;
}

interface DailyActivityReviewProps {
    date: string;
    defaultDate: string;
    latestDate: string;
    isWorkDay: boolean;
    rows: ReviewRow[];
    summary: { total: number; filled: number; empty: number; unreviewed: number };
    canReview: boolean;
}

/** A per-row save reloads only the sheet (Sub 01 K2). */
const SHEET_VISIT = {
    only: ['rows', 'summary', 'flash', 'navBadges'],
    preserveState: true,
    preserveScroll: true,
};

/**
 * Sprint 29 Sub 13 (I2) — "Periksa Aktivitas": one day (default the
 * previous working day), every SDM employee with an account — their
 * entries or "Tidak mengisi". HR marks Sesuai / Perlu klarifikasi (note →
 * notification) / Tidak mengisi; the CEO and PMs read. Append-only: a new
 * check replaces the shown one, the history stays.
 */
export default function DailyActivityReview({ date, defaultDate, latestDate, isWorkDay, rows, summary, canReview }: DailyActivityReviewProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [clarifying, setClarifying] = useState<ReviewRow | null>(null);
    const [bulkOpen, setBulkOpen] = useState(false);

    const unreviewed = rows.filter((row) => row.review === null);
    const unreviewedFilled = unreviewed.filter((row) => row.activities.length > 0).length;
    const unreviewedEmpty = unreviewed.length - unreviewedFilled;

    function go(next: string) {
        router.get(route('daily-activities.review'), next === defaultDate ? {} : { date: next }, { preserveScroll: true, replace: true });
    }

    function shift(days: number) {
        go(format(addDays(parseISO(date), days), 'yyyy-MM-dd'));
    }

    function review(row: ReviewRow, status: DailyActivityReviewStatus, note?: string, setError?: (message: string) => void) {
        router.post(
            route('daily-activities.review.store'),
            { user_id: row.user_id, date, status, note: note ?? null },
            {
                ...SHEET_VISIT,
                ...quietSuccess,
                onStart: () => setBusy(`${row.user_id}`),
                onFinish: () => setBusy(null),
                onSuccess: () => setClarifying(null),
                onError: (errors: Record<string, string>) => setError?.(Object.values(errors)[0]),
            },
        );
    }

    function reviewAll() {
        router.post(
            route('daily-activities.review.bulk'),
            { date },
            {
                ...SHEET_VISIT,
                onStart: () => setBusy('all'),
                onFinish: () => setBusy(null),
                onSuccess: () => setBulkOpen(false),
            },
        );
    }

    /** Status buttons of a row — the grid's last column and the phone card share them. */
    function actionsOf(row: ReviewRow, block = false): ReactNode {
        if (!canReview) return null;

        const filled = row.activities.length > 0;
        const current = row.review?.status;
        const rowBusy = busy === `${row.user_id}`;
        const size = block ? 'h-11 flex-1' : '';

        return (
            <div className={block ? 'flex gap-2' : 'flex justify-end gap-1'}>
                {filled ? (
                    <Button size="sm" variant={current === 'SESUAI' ? 'secondary' : 'outline'} className={size} disabled={busy !== null || current === 'SESUAI'} onClick={() => review(row, 'SESUAI')}>
                        {rowBusy ? <Loader2 className="size-4 animate-spin" /> : <CheckCheck className="size-4" />}
                        Sesuai
                    </Button>
                ) : (
                    isWorkDay && (
                        <Button
                            size="sm"
                            variant={current === 'TIDAK_MENGISI' ? 'secondary' : 'outline'}
                            className={size}
                            disabled={busy !== null || current === 'TIDAK_MENGISI'}
                            onClick={() => review(row, 'TIDAK_MENGISI')}
                        >
                            {rowBusy ? <Loader2 className="size-4 animate-spin" /> : <UserX className="size-4" />}
                            Tidak mengisi
                        </Button>
                    )
                )}
                <Button size="sm" variant="outline" className={size} disabled={busy !== null} onClick={() => setClarifying(row)}>
                    <MessageSquareWarning className="size-4" />
                    Perlu klarifikasi
                </Button>
            </div>
        );
    }

    const columns: ColumnDef<ReviewRow>[] = [
        {
            accessorKey: 'name',
            header: 'Karyawan',
            cell: ({ row }) => <EmployeeCell row={row.original} />,
        },
        {
            id: 'activities',
            header: 'Aktivitas',
            enableSorting: false,
            cell: ({ row }) => <ActivitiesCell row={row.original} />,
        },
        {
            id: 'review',
            header: 'Pemeriksaan',
            enableSorting: false,
            cell: ({ row }) => <ReviewCell row={row.original} />,
        },
        ...(canReview
            ? [
                  {
                      id: 'actions',
                      header: '',
                      enableSorting: false,
                      cell: ({ row }) => actionsOf(row.original),
                  } satisfies ColumnDef<ReviewRow>,
              ]
            : []),
    ];

    const toolbar = (
        <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="icon" aria-label="Hari sebelumnya" onClick={() => shift(-1)}>
                <ChevronLeft className="size-4" />
            </Button>
            <DatePicker value={parseISO(date)} onChange={(next) => next && go(format(next, 'yyyy-MM-dd'))} className="w-auto sm:w-56" />
            <Button variant="outline" size="icon" aria-label="Hari berikutnya" disabled={date >= latestDate} onClick={() => shift(1)}>
                <ChevronRight className="size-4" />
            </Button>
            {date !== defaultDate && (
                <Button variant="ghost" size="sm" onClick={() => go(defaultDate)}>
                    Kembali ke hari kerja terakhir
                </Button>
            )}
        </div>
    );

    return (
        <AppLayout>
            <Head title="Periksa Aktivitas" />

            <PageHeader
                title="Periksa Aktivitas"
                icon={ClipboardCheck}
                description={`Aktivitas Harian karyawan · ${dayLabel(date)}.`}
                step={
                    canReview
                        ? summary.unreviewed > 0
                            ? `${summary.unreviewed} orang belum diperiksa — tandai Sesuai, minta klarifikasi, atau catat yang tidak mengisi.`
                            : summary.total > 0
                              ? 'Semua karyawan sudah diperiksa untuk hari ini.'
                              : undefined
                        : undefined
                }
                actions={
                    canReview &&
                    summary.unreviewed > 0 && (
                        <Button onClick={() => setBulkOpen(true)} disabled={busy !== null}>
                            <CheckCheck className="size-4" />
                            Tandai semua yang terisi Sesuai
                        </Button>
                    )
                }
            />

            {!isWorkDay && (
                <Notice tone="info" className="mb-4">
                    Hari libur — tidak wajib mengisi. Hanya karyawan yang menulis aktivitas yang ditampilkan.
                </Notice>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Karyawan" value={summary.total} icon={Users} />
                <StatCard label="Mengisi" value={summary.filled} icon={NotebookPen} tone={summary.filled > 0 ? 'success' : 'default'} />
                <StatCard label="Tidak Mengisi" value={summary.empty} icon={UserX} tone={summary.empty > 0 ? 'error' : 'default'} />
                <StatCard label="Belum Diperiksa" value={summary.unreviewed} icon={ClipboardCheck} tone={summary.unreviewed > 0 ? 'warning' : 'default'} />
            </div>

            <DataTable
                columns={columns}
                data={rows}
                toolbar={toolbar}
                emptyMessage={isWorkDay ? 'Belum ada karyawan ber-akun di SDM.' : 'Tidak ada yang menulis aktivitas di hari libur ini.'}
                mobileCard={(row) => (
                    <div className="space-y-3">
                        <div className="flex items-start justify-between gap-2">
                            <EmployeeCell row={row} />
                            <ReviewCell row={row} />
                        </div>
                        <ActivitiesCell row={row} />
                        {actionsOf(row, true)}
                    </div>
                )}
            />

            <DailyActivityClarifyDialog
                name={clarifying?.name ?? null}
                dateLabel={dayLabel(date)}
                busy={busy !== null}
                onOpenChange={(open) => !open && setClarifying(null)}
                onSubmit={(note, setError) => clarifying && review(clarifying, 'PERLU_KLARIFIKASI', note, setError)}
            />

            <ConfirmDialog
                open={bulkOpen}
                onOpenChange={setBulkOpen}
                icon={CheckCheck}
                title="Tandai semua yang terisi Sesuai?"
                description={
                    <>
                        {unreviewedFilled} orang yang mengisi ditandai <strong>Sesuai</strong>
                        {unreviewedEmpty > 0 && (
                            <>
                                {' '}
                                dan {unreviewedEmpty} orang yang tidak mengisi dicatat <strong>Tidak mengisi</strong>
                            </>
                        )}
                        . Yang sudah diperiksa tidak berubah.
                    </>
                }
                confirmLabel="Ya, tandai"
                busy={busy === 'all'}
                onConfirm={reviewAll}
            />
        </AppLayout>
    );
}

function EmployeeCell({ row }: { row: ReviewRow }) {
    return (
        <div className="min-w-0">
            <p className="font-medium text-foreground">{row.name}</p>
            <p className="text-xs text-muted-foreground">
                {row.position ?? '—'}
                {row.division && ` · ${row.division}`}
            </p>
        </div>
    );
}

function ActivitiesCell({ row }: { row: ReviewRow }) {
    if (row.activities.length === 0) {
        return <span className="text-sm text-muted-foreground">Tidak mengisi</span>;
    }

    return (
        <div className="max-w-xl">
            <DailyActivityList activities={row.activities} />
        </div>
    );
}

function ReviewCell({ row }: { row: ReviewRow }) {
    if (!row.review) {
        return <StatusChip domain="dailyActivity" status="BELUM_DIPERIKSA" />;
    }

    return (
        <div className="space-y-1">
            <StatusChip domain="dailyActivity" status={row.review.status} />
            {row.review.note && <p className="max-w-xs text-xs whitespace-pre-line text-muted-foreground">{row.review.note}</p>}
            {row.review.reviewer && <p className="text-xs text-muted-foreground">oleh {row.review.reviewer}</p>}
        </div>
    );
}
