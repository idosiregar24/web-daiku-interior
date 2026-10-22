import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { cn } from '@/lib/utils';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { Fragment, useState } from 'react';
import { KpiActivityBreakdown } from './KpiActivityBreakdown';
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
 * weight is spread over the scored rows (see KpiService). A row with a
 * breakdown (Aktivitas Harian, Sprint 29 Sub 14) opens its day list.
 */
export function KpiScoreTable({ rows, total, editable = false, hasAccount = true }: KpiScoreTableProps) {
    const [openId, setOpenId] = useState<number | null>(null);

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
                    {rows.map((row) => {
                        const open = openId === row.id;

                        return (
                            <Fragment key={row.id}>
                                <tr>
                                    <td className="px-3 py-2">
                                        {row.detail ? (
                                            <button
                                                type="button"
                                                onClick={() => setOpenId(open ? null : row.id)}
                                                aria-expanded={open}
                                                className="flex items-center gap-1 text-left font-medium text-foreground underline-offset-4 decoration-daiku-yellow hover:underline"
                                            >
                                                {row.indicatorName}
                                                {open ? (
                                                    <ChevronUp className="size-4 text-daiku-muted" />
                                                ) : (
                                                    <ChevronDown className="size-4 text-daiku-muted" />
                                                )}
                                                <span className="sr-only">— rincian hari</span>
                                            </button>
                                        ) : (
                                            <p className="font-medium text-foreground">{row.indicatorName}</p>
                                        )}
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
                                    <td className="px-3 py-2 text-right text-daiku-muted tabular-nums">
                                        {formatKpiValue(row.weight, '%')}
                                    </td>
                                    <td
                                        className={cn(
                                            'px-3 py-2 text-right font-medium tabular-nums',
                                            row.weightedScore === null && 'text-daiku-muted',
                                        )}
                                    >
                                        {formatKpiValue(row.weightedScore)}
                                    </td>
                                </tr>
                                {open && row.detail && (
                                    <tr>
                                        <td colSpan={6} className="bg-daiku-gray/40 px-3 py-3">
                                            <KpiActivityBreakdown detail={row.detail} metricKey={row.metricKey} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        );
                    })}
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
