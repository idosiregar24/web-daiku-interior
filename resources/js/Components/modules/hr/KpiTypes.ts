import type { DailyActivityReviewStatus, KpiDirection, KpiIndicatorSource, KpiPeriodStatus } from '@/types';

/**
 * SDM (Sprint 10, §3.3) — view models KpiService sends to the KPI pages
 * and the employee KPI tab (camelCase read models, not raw rows).
 */

/** One indicator row of one employee-month (snapshot from `kpi_scores`). */
export interface KpiScoreRow {
    id: number;
    indicatorName: string;
    source: KpiIndicatorSource;
    metricKey: string | null;
    metricLabel: string | null;
    /** '%', 'hari', 'jumlah' (AUTO metrics); null for MANUAL. */
    unit: string | null;
    direction: KpiDirection;
    target: number;
    weight: number;
    actual: number | null;
    /** Snapshot breakdown behind an AUTO actual — only the Aktivitas Harian metrics have one (Sprint 29 Sub 14). */
    detail: KpiActivityDetail | null;
    /** 0–120; null = no actual (weight redistributed). */
    score: number | null;
    weightedScore: number | null;
}

/** One counted day of "Aktivitas Harian" (KpiMetricRegistry::loadDailyActivityMonths). */
export interface KpiActivityDay {
    /** YYYY-MM-DD */
    date: string;
    /** false = holiday, listed only when something was written. */
    workDay: boolean;
    entries: number;
    /** HR's newest check; null = not checked. */
    review: DailyActivityReviewStatus | null;
}

/** Month breakdown of the two Aktivitas Harian metrics, frozen with the score. */
export interface KpiActivityDetail {
    kind: 'daily_activity';
    /** Inclusive range counted (join date … yesterday); `to` before `from` = nothing yet. */
    from: string;
    to: string;
    fromJoinDate: boolean;
    workDays: number;
    filledWorkDays: number;
    filledHolidays: number;
    /** Filled days HR checked — the base of "% Sesuai". */
    reviewed: number;
    sesuai: number;
    klarifikasi: number;
    tidakMengisi: number;
    unreviewed: number;
    days: KpiActivityDay[];
}

/** One employee's month on the KPI page. */
export interface KpiBoardRow {
    employeeId: number;
    name: string;
    hasAccount: boolean;
    positionId: number | null;
    positionName: string;
    divisionId: number | null;
    divisionName: string;
    total: number | null;
    manualMissing: number;
    autoMissing: number;
    /** Rank within the position for this month. */
    rank: number;
    rankOf: number;
    rows: KpiScoreRow[];
}

export interface KpiPeriodProgress {
    id: number;
    period: string;
    label: string;
    computed: boolean;
    employees: number;
    manualMissing: number;
    autoMissing: number;
}

export interface KpiPeriodOption {
    id: number;
    period: string;
    label: string;
    status: KpiPeriodStatus;
    closedAt: string | null;
    closerName: string | null;
}

export interface KpiCompanyTrendPoint {
    period: string;
    label: string;
    status: KpiPeriodStatus;
    average: number | null;
    employees: number;
}

/** An AUTO metric the system can compute (KpiMetricRegistry::options()). */
export interface KpiMetricOption {
    key: string;
    label: string;
    unit: string;
    direction: KpiDirection;
    roles: string[];
    positionHints: string[];
    description: string;
}

export interface KpiTemplateIndicatorInput {
    id: number;
    name: string;
    source: KpiIndicatorSource;
    metric_key: string | null;
    target: number;
    weight: number;
    direction: KpiDirection;
}

export interface KpiTemplatePosition {
    id: number;
    name: string;
    isActive: boolean;
    employees: number;
    /** Active employees with no linked account — they get no AUTO values. */
    withoutAccount: number;
    /** Roles of the linked accounts — used to suggest metrics. */
    roles: string[];
    template: { id: number; isActive: boolean; indicators: KpiTemplateIndicatorInput[] } | null;
}

export interface KpiTemplateDivision {
    id: number;
    name: string;
    isActive: boolean;
    positions: KpiTemplatePosition[];
}

export const KPI_MAX_SCORE = 120;

export const KPI_SOURCE_LABEL: Record<KpiIndicatorSource, string> = {
    AUTO: 'Otomatis',
    MANUAL: 'Manual',
};

export const KPI_DIRECTION_LABEL: Record<KpiDirection, string> = {
    HIGHER_BETTER: 'Makin tinggi makin baik',
    LOWER_BETTER: 'Makin rendah makin baik',
};

const NUMBER = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });

/** "85,5", "12 hari", "90%" — em dash for no value. */
export function formatKpiValue(value: number | null | undefined, unit?: string | null): string {
    if (value === null || value === undefined) return '—';
    const text = NUMBER.format(value);
    if (unit === '%') return `${text}%`;
    if (unit === 'hari') return `${text} hari`;
    return text;
}

/** Tone of a month total (0–120 scale, 100 = on target). */
export function kpiTotalTone(total: number | null): 'success' | 'info' | 'warning' | 'neutral' {
    if (total === null) return 'neutral';
    if (total >= 100) return 'success';
    if (total >= 80) return 'info';
    return 'warning';
}

export function kpiTotalLabel(total: number | null): string {
    if (total === null) return 'Belum ada nilai';
    if (total >= 100) return 'Tercapai';
    if (total >= 80) return 'Mendekati target';
    return 'Di bawah target';
}
