import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export interface HeatmapData {
    columns: { key: string; label: string }[];
    rows: { projectId: number; name: string; cells: number[]; total: number }[];
    max: number;
}

// One hue, light → dark (dataviz: sequential = single hue). Zero stays
// the neutral surface so "nothing overdue" never reads as a value.
const STEPS = [
    'bg-viz-seq-1 text-daiku-dark',
    'bg-viz-seq-2 text-daiku-dark',
    'bg-viz-seq-3 text-daiku-dark',
    'bg-viz-seq-4 text-white',
    'bg-viz-seq-5 text-white',
    'bg-viz-seq-6 text-white',
];

function stepFor(value: number, max: number) {
    if (value === 0 || max === 0) {
        return 'bg-daiku-gray text-daiku-muted';
    }

    return STEPS[Math.min(STEPS.length - 1, Math.ceil((value / max) * STEPS.length) - 1)];
}

/**
 * PRD §4.10 "Overdue Tasks Heatmap: Visual task yang terlambat per
 * proyek" — project × week the task fell due. Every cell prints its
 * count, so identity never depends on color alone and the grid doubles
 * as its own table view.
 */
export function OverdueHeatmap({ data }: { data: HeatmapData }) {
    if (data.rows.length === 0) {
        return <p className="py-8 text-center text-sm text-daiku-muted">Tidak ada task yang melewati deadline di proyek aktif.</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-separate border-spacing-0.5 text-xs">
                <caption className="sr-only">Jumlah task terlambat per proyek per minggu jatuh tempo</caption>
                <thead>
                    <tr>
                        <th scope="col" className="min-w-40 p-1 text-left font-medium text-daiku-muted">
                            Proyek
                        </th>
                        {data.columns.map((column) => (
                            <th key={column.key} scope="col" className="p-1 text-center font-medium whitespace-nowrap text-daiku-muted">
                                {column.label}
                            </th>
                        ))}
                        <th scope="col" className="p-1 text-right font-medium text-daiku-muted">
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {data.rows.map((row) => (
                        <tr key={row.projectId}>
                            <th scope="row" className="max-w-48 truncate p-1 text-left font-medium text-daiku-dark">
                                <Link href={route('projects.show', { project: row.projectId })} className="hover:underline">
                                    {row.name}
                                </Link>
                            </th>
                            {row.cells.map((value, index) => (
                                <td
                                    key={data.columns[index].key}
                                    title={`${row.name} — minggu ${data.columns[index].label}: ${value} task terlambat`}
                                    className={cn('h-8 min-w-11 rounded-sm text-center font-medium tabular-nums', stepFor(value, data.max))}
                                >
                                    {value > 0 ? value : ''}
                                </td>
                            ))}
                            <td className="p-1 text-right font-semibold tabular-nums text-daiku-dark">{row.total}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="mt-3 flex items-center justify-end gap-2 text-xs text-daiku-muted" aria-hidden>
                Sedikit
                {STEPS.map((step) => (
                    <span key={step} className={cn('size-3 rounded-sm', step.split(' ')[0])} />
                ))}
                Banyak
            </div>
        </div>
    );
}
