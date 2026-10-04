import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { cn } from '@/lib/utils';
import { KpiManualInput } from './KpiManualInput';
import { formatKpiValue, KPI_DIRECTION_LABEL, KPI_SOURCE_LABEL, type KpiScoreRow } from './KpiTypes';

interface KpiScoreTableProps {
    rows: KpiScoreRow[];
    total: number | null;
    /** HR on an OPEN month: MANUAL actuals become inline inputs. */
    editable?: boolean;
    /** Employee has no linked account — explains empty AUTO values. */
    hasAccount?: boolean;
}

/**
 * Indicator breakdown of one employee-month: target, actual, score (0–120),
 * weight and weighted score. Rows without an actual are unscored and their
 * weight is spread over the scored rows (see KpiService).
 */
export function KpiScoreTable({ rows, total, editable = false, hasAccount = true }: KpiScoreTableProps) {
    return (
        <div className="overflow-x-auto rounded-xl ring-1 ring-border">
            <table className="w-full min-w-[640px] text-sm">
                <thead className={TABLE_HEAD_CLASS}>
                    <tr>
                        <th className="px-3 py-2 text-left font-medium">Indikator</th>
                        <th className="px-3 py-2 text-right font-medium">Target</th>
                        <th className="px-3 py-2 text-right font-medium">Aktual</th>
                        <th className="px-3 py-2 text-right font-medium">Skor</th>
                        <th className="px-3 py-2 text-right font-medium">Bobot</th>
                        <th className="px-3 py-2 text-right font-medium">Nilai</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((row) => (
                        <tr key={row.id}>
                            <td className="px-3 py-2">
                                <p className="font-medium text-foreground">{row.indicatorName}</p>
                                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-daiku-muted">
                                    <StatusChip
                                        status={row.source}
                                        tone={row.source === 'AUTO' ? 'info' : 'neutral'}
                                        label={KPI_SOURCE_LABEL[row.source]}
                                    />
                                    {row.metricLabel && <span>{row.metricLabel}</span>}
                                    <span>· {KPI_DIRECTION_LABEL[row.direction]}</span>
                                </div>
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatKpiValue(row.target, row.unit)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {editable && row.source === 'MANUAL' ? (
                                    <KpiManualInput scoreId={row.id} actual={row.actual} label={row.indicatorName} />
                                ) : row.actual === null ? (
                                    <span className="text-xs text-daiku-muted">
                                        {row.source === 'AUTO' ? (hasAccount ? 'Tidak ada data' : 'Tanpa akun') : 'Belum diisi'}
                                    </span>
                                ) : (
                                    formatKpiValue(row.actual, row.unit)
                                )}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatKpiValue(row.score)}</td>
                            <td className="px-3 py-2 text-right text-daiku-muted tabular-nums">{formatKpiValue(row.weight, '%')}</td>
                            <td
                                className={cn(
                                    'px-3 py-2 text-right font-medium tabular-nums',
                                    row.weightedScore === null && 'text-daiku-muted',
                                )}
                            >
                                {formatKpiValue(row.weightedScore)}
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t border-border bg-daiku-gray/60">
                        <td colSpan={5} className="px-3 py-2 text-right text-xs font-medium tracking-wider text-daiku-muted uppercase">
                            Total
                        </td>
                        <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatKpiValue(total)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
