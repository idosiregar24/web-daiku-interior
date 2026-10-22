import { StatusChip } from '@/Components/shared/StatusChip';
import { cn } from '@/lib/utils';
import { formatKpiValue, type KpiActivityDetail } from './KpiTypes';

interface KpiActivityBreakdownProps {
    detail: KpiActivityDetail;
    /** Which of the two metrics the row is — its own figure is highlighted. */
    metricKey: string | null;
}

const rate = (part: number, whole: number) => (whole > 0 ? Math.round((part / whole) * 10000) / 100 : null);

function dayLabel(
    date: string,
    options: Intl.DateTimeFormatOptions = {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    },
): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('id-ID', options);
}

/**
 * Sprint 29 Sub 14 — what is behind "% hari kerja mengisi aktivitas harian"
 * and "% aktivitas dinilai Sesuai": the counted days of the month (frozen
 * with the score), each filled or not and HR's newest check.
 */
export function KpiActivityBreakdown({ detail, metricKey }: KpiActivityBreakdownProps) {
    const fillRate = rate(detail.filledWorkDays, detail.workDays);
    const okRate = rate(detail.sesuai, detail.reviewed);
    const isFill = metricKey === 'daily_activity_fill_rate';
    const nothingYet = detail.to < detail.from;

    const figures = [
        {
            label: 'Hari kerja terisi',
            value: `${detail.filledWorkDays} dari ${detail.workDays}`,
            result: formatKpiValue(fillRate, '%'),
            active: isFill,
        },
        {
            label: 'Dinilai Sesuai',
            value: `${detail.sesuai} dari ${detail.reviewed} diperiksa`,
            result: formatKpiValue(okRate, '%'),
            active: !isFill,
        },
        { label: 'Perlu klarifikasi', value: String(detail.klarifikasi) },
        { label: 'Tidak mengisi', value: String(detail.tidakMengisi) },
        { label: 'Belum diperiksa', value: String(detail.unreviewed) },
    ];

    return (
        <div className="space-y-3 text-sm">
            <p className="text-xs text-daiku-muted">
                {nothingYet
                    ? 'Belum ada hari yang dihitung bulan ini.'
                    : `Dihitung ${dayLabel(detail.from, { day: 'numeric', month: 'short' })} – ${dayLabel(detail.to, { day: 'numeric', month: 'short', year: 'numeric' })}`}
                {detail.fromJoinDate && ' (sejak tanggal bergabung)'}. Hari ini belum dihitung; hari libur tidak termasuk hari kerja
                {detail.filledHolidays > 0 && ` — ${detail.filledHolidays} hari libur terisi ikut diperiksa`}.
            </p>

            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                {figures.map((figure) => (
                    <div
                        key={figure.label}
                        className={cn('rounded-lg px-3 py-2 ring-1 ring-border', figure.active && 'bg-daiku-yellow-light')}
                    >
                        <dt className="text-xs text-daiku-muted">{figure.label}</dt>
                        <dd className="font-medium text-foreground tabular-nums">
                            {figure.value}
                            {figure.result && <span className="ml-1 text-daiku-muted">· {figure.result}</span>}
                        </dd>
                    </div>
                ))}
            </dl>

            {detail.days.length > 0 && (
                <ul className="divide-y divide-border rounded-lg ring-1 ring-border">
                    {detail.days.map((day) => (
                        <li key={day.date} className="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5">
                            <span className="min-w-28 text-foreground">
                                {dayLabel(day.date)}
                                {!day.workDay && <span className="text-daiku-muted"> · libur</span>}
                            </span>
                            <span className={cn('flex-1 text-xs', day.entries > 0 ? 'text-foreground' : 'text-error-ink')}>
                                {day.entries > 0 ? `${day.entries} aktivitas` : 'Tidak mengisi'}
                            </span>
                            <StatusChip domain="dailyActivity" status={day.review ?? 'BELUM_DIPERIKSA'} waitingOn={null} />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
