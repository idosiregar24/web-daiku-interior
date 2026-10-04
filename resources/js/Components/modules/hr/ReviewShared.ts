import type { ReviewGrade, ReviewQualitative, ReviewRecommendation, ReviewStatus, ReviewWeights, User } from '@/types';

/**
 * SDM (Sprint 10, §3.4) — shapes and helpers shared by the Evaluasi pages
 * and the employee profile tab. Data shapes mirror
 * PerformanceReviewService::present() / forEmployee(); the score helpers
 * mirror PerformanceReviewService::scores()/finalScore() so the form can
 * preview the final score live (the server always recomputes).
 */

export type ReviewAspect = keyof ReviewQualitative;

export const REVIEW_ASPECTS: ReviewAspect[] = ['attitude', 'teamwork', 'initiative', 'responsibility'];

export const REVIEW_ASPECT_LABEL: Record<ReviewAspect, string> = {
    attitude: 'Sikap',
    teamwork: 'Kerja sama',
    initiative: 'Inisiatif',
    responsibility: 'Tanggung jawab',
};

export const REVIEW_RECOMMENDATIONS: ReviewRecommendation[] = ['NAIK_GAJI', 'BONUS', 'PEMBINAAN', 'SP', 'TIDAK_ADA'];

export const REVIEW_RECOMMENDATION_LABEL: Record<ReviewRecommendation, string> = {
    NAIK_GAJI: 'Naik gaji',
    BONUS: 'Bonus',
    PEMBINAAN: 'Pembinaan',
    SP: 'Surat peringatan (SP)',
    TIDAK_ADA: 'Tidak ada',
};

export const REVIEW_STATUSES: ReviewStatus[] = ['DRAFT', 'SUBMITTED', 'APPROVED', 'ACKNOWLEDGED'];

export const REVIEW_STATUS_LABEL: Record<ReviewStatus, string> = {
    DRAFT: 'Draf',
    SUBMITTED: 'Menunggu CEO',
    APPROVED: 'Disetujui',
    ACKNOWLEDGED: 'Sudah dibaca',
};

export const REVIEW_DEFAULT_WEIGHTS: ReviewWeights = { kpi: 60, qualitative: 25, discipline: 15 };

/** The KPI part of the final score is capped here (KPI totals go up to 120). */
export const REVIEW_KPI_CAP = 100;

export interface ReviewDisciplineSummary {
    teguran_lisan: number;
    sp1: number;
    sp2: number;
    sp3: number;
    catatan: number;
    active_sp_at_end: string | null;
}

/** PerformanceReviewService::present(). `return_note` is absent on the employee's own view. */
export interface ReviewDetail {
    id: number;
    employee_id: number;
    year: number;
    semester: 1 | 2;
    period_label: string;
    status: ReviewStatus;
    status_label: string;
    kpi_average: number | null;
    kpi_months: number;
    kpi_capped: boolean;
    kpi_redistributed: boolean;
    discipline_summary: ReviewDisciplineSummary | null;
    discipline_score: number | null;
    qualitative: Partial<Record<ReviewAspect, number | null>> | null;
    qualitative_score: number | null;
    weights: ReviewWeights;
    effective_weights: ReviewWeights | null;
    final_score: number | null;
    grade: ReviewGrade | null;
    recommendation: ReviewRecommendation | null;
    recommendation_label: string | null;
    notes: string | null;
    return_note?: string | null;
    reviewer: Pick<User, 'id' | 'name'> | null;
    submitted_at: string | null;
    approver: Pick<User, 'id' | 'name'> | null;
    approved_at: string | null;
    acknowledged_at: string | null;
    created_at: string | null;
}

export interface ReviewSemesterRef {
    year: number;
    semester: 1 | 2;
    label: string;
    review_id: number | null;
}

export function gradeFromScore(score: number): ReviewGrade {
    if (score >= 90) return 'A';
    if (score >= 80) return 'B';
    if (score >= 70) return 'C';
    if (score >= 60) return 'D';

    return 'E';
}

/** Weights actually applied — without a KPI average its share is spread over the other two. */
export function effectiveWeights(weights: ReviewWeights, withoutKpi: boolean): ReviewWeights | null {
    if (!withoutKpi) return weights;

    const rest = weights.qualitative + weights.discipline;
    if (rest <= 0) return null;

    return { kpi: 0, qualitative: (weights.qualitative / rest) * 100, discipline: (weights.discipline / rest) * 100 };
}

export function qualitativeScore(qualitative: Partial<Record<ReviewAspect, number | null>> | null): number | null {
    const values = REVIEW_ASPECTS.map((aspect) => qualitative?.[aspect] ?? null);
    if (values.some((value) => value === null || value < 1 || value > 5)) return null;

    return Math.round(((values as number[]).reduce((sum, v) => sum + v, 0) / REVIEW_ASPECTS.length / 5) * 100 * 100) / 100;
}

export function computeReviewScore(
    kpiAverage: number | null,
    qualitative: Partial<Record<ReviewAspect, number | null>> | null,
    disciplineScore: number | null,
    weights: ReviewWeights,
): { qualitativeScore: number | null; finalScore: number | null; grade: ReviewGrade | null; effective: ReviewWeights | null } {
    const qScore = qualitativeScore(qualitative);
    const effective = effectiveWeights(weights, kpiAverage === null);

    if (qScore === null || effective === null) {
        return { qualitativeScore: qScore, finalScore: null, grade: null, effective };
    }

    const kpi = kpiAverage === null ? 0 : Math.min(kpiAverage, REVIEW_KPI_CAP);
    const discipline = disciplineScore ?? 100;
    const final = Math.round(((effective.kpi * kpi + effective.qualitative * qScore + effective.discipline * discipline) / 100) * 100) / 100;

    return { qualitativeScore: qScore, finalScore: final, grade: gradeFromScore(final), effective };
}

export function formatScore(value: number | null | undefined, digits = 1): string {
    if (value === null || value === undefined) return '—';

    return value.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: digits });
}

export function gradeTone(grade: ReviewGrade | null): 'success' | 'info' | 'warning' | 'error' | 'default' {
    switch (grade) {
        case 'A':
        case 'B':
            return 'success';
        case 'C':
            return 'info';
        case 'D':
            return 'warning';
        case 'E':
            return 'error';
        default:
            return 'default';
    }
}
