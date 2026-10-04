import { ReviewDetailView } from '@/Components/modules/hr/ReviewDetailView';
import {
    formatScore,
    REVIEW_STATUS_LABEL,
    type ReviewDetail,
    type ReviewSemesterRef,
} from '@/Components/modules/hr/ReviewShared';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Employee } from '@/types';
import { Link, router } from '@inertiajs/react';
import { BookCheck, ChevronDown, ClipboardList, ExternalLink, FileDown, Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * Data shape returned by PerformanceReviewService::forEmployee() (passed
 * straight through by HR\EmployeeController::show() and the "Milik Saya"
 * page, which calls it with finalOnly — APPROVED/ACKNOWLEDGED only, no
 * `return_note`). `current`/`previous` back the "Buat evaluasi" shortcut.
 */
export interface EmployeeReviewsData {
    reviews: ReviewDetail[];
    current: ReviewSemesterRef;
    previous: ReviewSemesterRef;
}

export interface EmployeeReviewsTabProps {
    employee: Pick<Employee, 'id' | 'name' | 'is_active'>;
    data: EmployeeReviewsData;
    /** HR (or SUPERADMIN) may act; false on the CEO's view and on "Milik Saya". */
    canManage: boolean;
    /** The employee's own read-only view ("Milik Saya"). */
    selfView?: boolean;
}

/** Evaluasi tab of the employee profile (and of "Milik Saya"). */
export function EmployeeReviewsTab({ employee, data, canManage, selfView = false }: EmployeeReviewsTabProps) {
    const reviews = data?.reviews ?? [];
    const [openId, setOpenId] = useState<number | null>(() => (selfView ? (reviews.find((r) => r.status === 'APPROVED')?.id ?? null) : null));
    const [processing, setProcessing] = useState<string | null>(null);

    // Semesters without a review yet that HR may start from here (last completed first).
    const missing = canManage && !selfView && employee.is_active ? [data?.previous, data?.current].filter((s): s is ReviewSemesterRef => !!s && !s.review_id) : [];

    function create(semester: ReviewSemesterRef) {
        router.post(
            route('hr.reviews.store'),
            { employee_id: employee.id, year: semester.year, semester: semester.semester },
            { preserveScroll: true, onStart: () => setProcessing(semester.label), onFinish: () => setProcessing(null) },
        );
    }

    function acknowledge(review: ReviewDetail) {
        router.post(
            route('my.reviews.acknowledge', { performance_review: review.id }),
            {},
            { preserveScroll: true, onStart: () => setProcessing(`ack-${review.id}`), onFinish: () => setProcessing(null) },
        );
    }

    const pendingAck = selfView ? reviews.filter((r) => r.status === 'APPROVED') : [];

    return (
        <div className="space-y-4">
            {pendingAck.length > 0 && (
                <Notice tone="info">
                    Ada {pendingAck.length} evaluasi yang belum Anda konfirmasi. Baca detailnya, lalu klik "Saya sudah membaca".
                </Notice>
            )}

            <SectionCard
                title="Evaluasi Kinerja"
                description={selfView ? 'Evaluasi semester yang sudah disetujui CEO.' : 'Semester 1 = Jan–Jun, Semester 2 = Jul–Des.'}
                icon={ClipboardList}
                flush
                action={
                    missing.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {missing.map((semester) => (
                                <Button key={semester.label} size="sm" variant="outline" disabled={processing !== null} onClick={() => create(semester)}>
                                    <Plus className="size-4" />
                                    Buat evaluasi {semester.label}
                                </Button>
                            ))}
                        </div>
                    )
                }
            >
                {reviews.length === 0 ? (
                    <EmptyState
                        title="Belum ada evaluasi."
                        description={selfView ? 'Evaluasi muncul di sini setelah disetujui CEO.' : undefined}
                        className="py-10"
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {reviews.map((review) => {
                            const open = openId === review.id;

                            return (
                                <li key={review.id}>
                                    <button
                                        type="button"
                                        className="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-daiku-gray/60 sm:px-5"
                                        aria-expanded={open}
                                        onClick={() => setOpenId(open ? null : review.id)}
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium text-foreground">{review.period_label}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {review.recommendation_label ? `Rekomendasi: ${review.recommendation_label}` : 'Rekomendasi belum diisi'}
                                                {review.approved_at && ` · disetujui ${formatDate(review.approved_at)}`}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-sm font-semibold tabular-nums">
                                                {formatScore(review.final_score, 2)}
                                                {review.grade && <span className="ml-1.5">· {review.grade}</span>}
                                            </p>
                                        </div>
                                        <StatusChip status={review.status} label={REVIEW_STATUS_LABEL[review.status]} />
                                        <ChevronDown className={cn('size-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} />
                                    </button>

                                    {open && (
                                        <div className="space-y-4 border-t border-border bg-daiku-gray/40 px-4 py-4 sm:px-5">
                                            {!selfView && review.status === 'DRAFT' && review.return_note && (
                                                <Notice tone="warning">
                                                    <span className="font-medium">Dikembalikan CEO:</span> {review.return_note}
                                                </Notice>
                                            )}
                                            <div className="flex flex-wrap gap-2">
                                                {!selfView && (
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={route('hr.reviews.show', { performance_review: review.id })}>
                                                            <ExternalLink className="size-4" />
                                                            {canManage && review.status === 'DRAFT' ? 'Buka & Isi Evaluasi' : 'Buka Detail'}
                                                        </Link>
                                                    </Button>
                                                )}
                                                <Button size="sm" variant="outline" asChild>
                                                    <a
                                                        href={route(selfView ? 'my.reviews.pdf' : 'hr.reviews.pdf', { performance_review: review.id })}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <FileDown className="size-4" />
                                                        PDF
                                                    </a>
                                                </Button>
                                                {canManage &&
                                                    !selfView &&
                                                    review.recommendation === 'NAIK_GAJI' &&
                                                    (review.status === 'APPROVED' || review.status === 'ACKNOWLEDGED') &&
                                                    route().has('hr.salary.index') && (
                                                        <Button size="sm" variant="outline" asChild>
                                                            <Link href={route('hr.salary.index', { request_for: employee.id, review: review.id })}>
                                                                Ajukan Kenaikan Gaji
                                                            </Link>
                                                        </Button>
                                                    )}
                                                {selfView && review.status === 'APPROVED' && (
                                                    <Button size="sm" disabled={processing !== null} onClick={() => acknowledge(review)}>
                                                        <BookCheck className="size-4" />
                                                        Saya sudah membaca
                                                    </Button>
                                                )}
                                            </div>
                                            <ReviewDetailView review={review} />
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </SectionCard>
        </div>
    );
}
