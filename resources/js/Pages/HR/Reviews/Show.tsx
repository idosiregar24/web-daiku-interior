import { ReviewDecisionDialog } from '@/Components/modules/hr/ReviewDecisionDialog';
import { ReviewDetailView, ReviewDisciplineDetails, ReviewScoreTiles } from '@/Components/modules/hr/ReviewDetailView';
import {
    computeReviewScore,
    REVIEW_ASPECT_LABEL,
    REVIEW_ASPECTS,
    REVIEW_RECOMMENDATION_LABEL,
    REVIEW_RECOMMENDATIONS,
    REVIEW_STATUS_LABEL,
    type ReviewAspect,
    type ReviewDetail,
} from '@/Components/modules/hr/ReviewShared';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { ReviewRecommendation, ReviewWeights } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { BadgeDollarSign, Check, ClipboardList, FileDown, Gavel, RefreshCw, Save, Scale, Send, Sparkles, Undo2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const NONE = 'none';

const aspectSchema = z.number().int().min(1, 'Nilai harus 1 sampai 5.').max(5, 'Nilai harus 1 sampai 5.').nullable();
const weightSchema = z
    .string()
    .min(1, 'Wajib diisi.')
    .refine((v) => /^\d+$/.test(v) && Number(v) <= 100, 'Bilangan bulat 0–100.');

// Mirrors UpdatePerformanceReviewRequest (aspects may stay empty while drafting).
const schema = z
    .object({
        qualitative: z.object({ attitude: aspectSchema, teamwork: aspectSchema, initiative: aspectSchema, responsibility: aspectSchema }),
        weights: z.object({ kpi: weightSchema, qualitative: weightSchema, discipline: weightSchema }),
        recommendation: z.string(),
        notes: z.string().max(2000, 'Catatan maksimal 2000 karakter.'),
    })
    .superRefine((values, ctx) => {
        const total = Number(values.weights.kpi) + Number(values.weights.qualitative) + Number(values.weights.discipline);
        if (total !== 100) {
            ctx.addIssue({ code: 'custom', path: ['weights', 'kpi'], message: `Total bobot harus 100% (sekarang ${total}%).` });
        }
    });

type FormValues = z.infer<typeof schema>;

interface ReviewShowProps {
    review: ReviewDetail & {
        return_note: string | null;
        employee: { id: number; name: string; position: string | null; division: string | null; is_active: boolean; has_account: boolean };
    };
    canManage: boolean;
    canDecide: boolean;
    disciplinePenalty: Record<string, number>;
    kpiCap: number;
}

function toNumberWeights(weights: FormValues['weights']): ReviewWeights {
    return { kpi: Number(weights.kpi) || 0, qualitative: Number(weights.qualitative) || 0, discipline: Number(weights.discipline) || 0 };
}

/**
 * SDM (Sprint 10, §3.4) — one semester review. HR edits it while DRAFT
 * (live score preview with the server's formula), submits it; the CEO
 * approves or returns it. Read-only otherwise.
 */
export default function ReviewShow({ review, canManage, canDecide, disciplinePenalty }: ReviewShowProps) {
    const editable = canManage && review.status === 'DRAFT';
    const [decision, setDecision] = useState<'approve' | 'return' | null>(null);
    const [processing, setProcessing] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            qualitative: Object.fromEntries(REVIEW_ASPECTS.map((a) => [a, review.qualitative?.[a] ?? null])) as FormValues['qualitative'],
            weights: {
                kpi: String(review.weights.kpi),
                qualitative: String(review.weights.qualitative),
                discipline: String(review.weights.discipline),
            },
            recommendation: review.recommendation ?? NONE,
            notes: review.notes ?? '',
        },
    });

    const values = form.watch();
    const weightTotal = Number(values.weights.kpi || 0) + Number(values.weights.qualitative || 0) + Number(values.weights.discipline || 0);

    // Live preview — same formula as PerformanceReviewService::scores().
    const preview: ReviewDetail = useMemo(() => {
        if (!editable) return review;

        const weights = toNumberWeights(values.weights);
        const score = computeReviewScore(review.kpi_average, values.qualitative, review.discipline_score, weights);
        const recommendation = values.recommendation === NONE ? null : (values.recommendation as ReviewRecommendation);

        return {
            ...review,
            qualitative: values.qualitative,
            weights,
            effective_weights: score.effective,
            qualitative_score: score.qualitativeScore,
            final_score: score.finalScore,
            grade: score.grade,
            recommendation,
            recommendation_label: recommendation ? REVIEW_RECOMMENDATION_LABEL[recommendation] : null,
        };
    }, [editable, review, JSON.stringify(values)]);

    function payload(v: FormValues) {
        const weights = toNumberWeights(v.weights);

        return {
            qualitative: v.qualitative,
            weights: { kpi: weights.kpi, qualitative: weights.qualitative, discipline: weights.discipline },
            recommendation: v.recommendation === NONE ? null : v.recommendation,
            notes: v.notes || null,
        };
    }

    // Field errors go under their field; state errors (status, missing aspects) into one notice.
    function onError(errors: Record<string, string>) {
        const general: string[] = [];

        Object.entries(errors).forEach(([field, message]) => {
            if (field === 'weights') {
                form.setError('weights.kpi', { message });
            } else if (field.startsWith('qualitative.') || field.startsWith('weights.') || field === 'recommendation' || field === 'notes') {
                form.setError(field as Parameters<typeof form.setError>[0], { message });
            } else {
                general.push(message);
            }
        });

        setServerError(general.length > 0 ? general.join(' ') : null);
    }

    const requestOptions = {
        preserveScroll: true,
        onStart: () => {
            setProcessing(true);
            setServerError(null);
        },
        onFinish: () => setProcessing(false),
        onError,
    };

    const routeParams = { performance_review: review.id };

    function save(v: FormValues) {
        router.put(route('hr.reviews.update', routeParams), payload(v), requestOptions);
    }

    function saveAndSubmit(v: FormValues) {
        router.put(route('hr.reviews.update', routeParams), payload(v), {
            ...requestOptions,
            onSuccess: () => router.post(route('hr.reviews.submit', routeParams), {}, requestOptions),
        });
    }

    const canRequestSalary =
        canManage &&
        (review.status === 'APPROVED' || review.status === 'ACKNOWLEDGED') &&
        review.recommendation === 'NAIK_GAJI' &&
        route().has('hr.salary.index');

    return (
        <AppLayout breadcrumbs={[{ label: review.employee.name }, { label: review.period_label }]}>
            <Head title={`Evaluasi ${review.employee.name} — ${review.period_label}`} />

            <PageHeader
                title={`Evaluasi ${review.employee.name}`}
                icon={ClipboardList}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        {review.period_label}
                        <span className="text-daiku-muted">
                            · {review.employee.position ?? '—'}
                            {review.employee.division ? ` (${review.employee.division})` : ''}
                        </span>
                        <StatusChip status={review.status} label={REVIEW_STATUS_LABEL[review.status]} />
                    </span>
                }
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('hr.reviews.pdf', routeParams)} target="_blank" rel="noreferrer">
                                <FileDown className="size-4" />
                                Unduh PDF
                            </a>
                        </Button>
                        {canRequestSalary && (
                            <Button variant="outline" asChild>
                                <Link href={route('hr.salary.index', { request_for: review.employee.id, review: review.id })}>
                                    <BadgeDollarSign className="size-4" />
                                    Ajukan Kenaikan Gaji
                                </Link>
                            </Button>
                        )}
                        {canDecide && review.status === 'SUBMITTED' && (
                            <>
                                <Button variant="outline" onClick={() => setDecision('return')}>
                                    <Undo2 className="size-4" />
                                    Kembalikan Evaluasi
                                </Button>
                                <Button onClick={() => setDecision('approve')}>
                                    <Check className="size-4" />
                                    Setujui Evaluasi
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            <div className="mb-6 space-y-3">
                {serverError && <Notice tone="error">{serverError}</Notice>}
                {review.status === 'DRAFT' && review.return_note && (
                    <Notice tone="warning">
                        <span className="font-medium">Dikembalikan CEO:</span> {review.return_note}
                    </Notice>
                )}
                {review.status === 'SUBMITTED' && (
                    <Notice tone="info">
                        {canDecide ? 'Evaluasi ini menunggu persetujuan Anda.' : 'Evaluasi sudah diajukan dan menunggu persetujuan CEO.'}
                    </Notice>
                )}
                {review.status === 'APPROVED' && (
                    <Notice tone="success">
                        {review.employee.has_account
                            ? 'Disetujui CEO — menunggu karyawan mengonfirmasi sudah membaca.'
                            : 'Disetujui CEO. Karyawan belum punya akun sistem — serahkan PDF-nya secara langsung.'}
                    </Notice>
                )}
                {review.kpi_redistributed && (
                    <Notice tone="info">
                        Belum ada periode KPI yang ditutup di semester ini — bobot KPI dialihkan secara proporsional ke aspek kualitatif dan
                        kedisiplinan.
                    </Notice>
                )}
                {(review.status === 'APPROVED' || review.status === 'ACKNOWLEDGED') && review.recommendation === 'NAIK_GAJI' && (
                    <Notice tone="info">
                        Rekomendasi naik gaji tidak mengubah gaji otomatis — SDM mengajukan perubahan gaji, CEO yang menyetujui.
                    </Notice>
                )}
            </div>

            {editable ? (
                <div className="space-y-6">
                    <ReviewScoreTiles review={preview} />

                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(save)} className="space-y-6">
                            <div className="grid gap-6 lg:grid-cols-2">
                                <SectionCard title="Aspek Kualitatif" description="Skala 1 (kurang) – 5 (sangat baik)" icon={Sparkles}>
                                    <div className="space-y-4">
                                        {REVIEW_ASPECTS.map((aspect) => (
                                            <AspectField key={aspect} aspect={aspect} form={form} />
                                        ))}
                                    </div>
                                </SectionCard>

                                <div className="space-y-6">
                                    <SectionCard
                                        title="Kedisiplinan Semester Ini"
                                        description={`Nilai = 100 − ${disciplinePenalty.TEGURAN_LISAN ?? 10}×teguran − ${disciplinePenalty.SP1 ?? 20}×SP1 − ${disciplinePenalty.SP2 ?? 35}×SP2 − ${disciplinePenalty.SP3 ?? 50}×SP3`}
                                        icon={Gavel}
                                        action={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                                onClick={() => router.post(route('hr.reviews.refresh', routeParams), {}, requestOptions)}
                                            >
                                                <RefreshCw className="size-4" />
                                                Perbarui Data
                                            </Button>
                                        }
                                    >
                                        <ReviewDisciplineDetails review={review} />
                                        <p className="mt-4 text-xs text-muted-foreground">
                                            "Perbarui data" menarik ulang rata-rata KPI dan catatan kedisiplinan terbaru.
                                        </p>
                                    </SectionCard>

                                    <SectionCard
                                        title="Bobot Nilai Akhir"
                                        description="Dalam persen, total harus 100"
                                        icon={Scale}
                                        action={
                                            <span className={cn('text-sm font-semibold tabular-nums', weightTotal === 100 ? 'text-success-ink' : 'text-error-ink')}>
                                                Total {weightTotal}%
                                            </span>
                                        }
                                    >
                                        <div className="grid grid-cols-3 gap-4">
                                            {(
                                                [
                                                    ['kpi', 'KPI'],
                                                    ['qualitative', 'Kualitatif'],
                                                    ['discipline', 'Kedisiplinan'],
                                                ] as const
                                            ).map(([key, label]) => (
                                                <FormField
                                                    key={key}
                                                    control={form.control}
                                                    name={`weights.${key}`}
                                                    render={({ field }) => (
                                                        <FormItem>
                                                            <FormLabel>{label} (%)</FormLabel>
                                                            <FormControl>
                                                                <Input type="number" min="0" max="100" step="1" inputMode="numeric" {...field} />
                                                            </FormControl>
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                            ))}
                                        </div>
                                    </SectionCard>
                                </div>
                            </div>

                            <SectionCard title="Rekomendasi & Catatan" icon={ClipboardList}>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        control={form.control}
                                        name="recommendation"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Rekomendasi</FormLabel>
                                                <Select value={field.value} onValueChange={field.onChange}>
                                                    <FormControl>
                                                        <SelectTrigger className="w-full">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                    </FormControl>
                                                    <SelectContent>
                                                        <SelectItem value={NONE}>Belum dipilih</SelectItem>
                                                        {REVIEW_RECOMMENDATIONS.map((r) => (
                                                            <SelectItem key={r} value={r}>
                                                                {REVIEW_RECOMMENDATION_LABEL[r]}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                <FormDescription>Hanya saran — tidak mengubah gaji otomatis.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="notes"
                                        render={({ field }) => (
                                            <FormItem className="sm:row-span-2">
                                                <FormLabel>Catatan (opsional)</FormLabel>
                                                <FormControl>
                                                    <Textarea {...field} rows={4} placeholder="Kekuatan, area perbaikan, target semester depan." />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </div>
                            </SectionCard>

                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <Button type="submit" variant="outline" disabled={processing}>
                                    <Save className="size-4" />
                                    Simpan Draf
                                </Button>
                                <Button type="button" disabled={processing} onClick={form.handleSubmit(saveAndSubmit)}>
                                    <Send className="size-4" />
                                    Simpan & Ajukan ke CEO
                                </Button>
                            </div>
                        </form>
                    </Form>
                </div>
            ) : (
                <ReviewDetailView review={review} />
            )}

            {decision && (
                <ReviewDecisionDialog
                    open={decision !== null}
                    onOpenChange={(open) => !open && setDecision(null)}
                    reviewId={review.id}
                    employeeName={review.employee.name}
                    periodLabel={review.period_label}
                    decision={decision}
                />
            )}
        </AppLayout>
    );
}

/** One qualitative aspect as a 1–5 segmented control. */
function AspectField({ aspect, form }: { aspect: ReviewAspect; form: ReturnType<typeof useForm<FormValues>> }) {
    return (
        <FormField
            control={form.control}
            name={`qualitative.${aspect}`}
            render={({ field }) => (
                <FormItem>
                    <FormLabel>{REVIEW_ASPECT_LABEL[aspect]}</FormLabel>
                    <div role="radiogroup" aria-label={REVIEW_ASPECT_LABEL[aspect]} className="flex gap-1.5">
                        {[1, 2, 3, 4, 5].map((n) => (
                            <Button
                                key={n}
                                type="button"
                                role="radio"
                                aria-checked={field.value === n}
                                variant={field.value === n ? 'default' : 'outline'}
                                size="sm"
                                className="w-10 tabular-nums"
                                onClick={() => field.onChange(field.value === n ? null : n)}
                            >
                                {n}
                            </Button>
                        ))}
                    </div>
                    <FormMessage />
                </FormItem>
            )}
        />
    );
}
