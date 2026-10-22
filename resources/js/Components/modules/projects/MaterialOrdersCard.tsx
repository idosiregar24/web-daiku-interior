import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { formatQty } from '@/lib/format';
import type { ProjectMaterial } from '@/types';
import { ArrowRight, Truck } from 'lucide-react';
import { summarizeArrivals } from './materialArrival';
import { MaterialStatusChip } from './MaterialStatusChip';
import type { ProjectTabTarget } from './projectWaiting';

/** Lines named under the bar — the rest is "dan n lainnya". */
const SHOWN = 3;

/**
 * Sprint 27 P3 — the whiteboard's "Start Produksi → Pemesanan bahan
 * (Logistik)": how far the planned materials have arrived, so the PM and
 * the Pengawas see it in Eksekusi. Read from the page's `projectMaterials`
 * (viewers of the Material tab only); ordering itself stays in Material.
 */
export function MaterialOrdersCard({
    lines,
    onOpen,
    canOpen,
}: {
    lines: ProjectMaterial[];
    onOpen?: (target: ProjectTabTarget) => void;
    canOpen?: (target: ProjectTabTarget) => boolean;
}) {
    const summary = summarizeArrivals(lines);
    const target: ProjectTabTarget = { tab: 'material' };
    const approved = summary.total - summary.request;
    const percent = approved > 0 ? Math.floor((summary.complete / approved) * 100) : 0;

    return (
        <SectionCard
            title="Pemesanan Bahan"
            description="Start Produksi → Logistik memesan / mengeluarkan bahan dari gudang."
            icon={Truck}
            action={
                onOpen &&
                (canOpen?.(target) ?? true) && (
                    <Button variant="outline" size="sm" onClick={() => onOpen(target)}>
                        Lihat Material
                        <ArrowRight className="size-4" />
                    </Button>
                )
            }
        >
            {summary.total === 0 ? (
                <p className="text-sm text-muted-foreground">Belum ada rencana bahan.</p>
            ) : (
                <div className="space-y-3">
                    <p className="text-sm text-foreground">
                        <span className="font-semibold">
                            {summary.complete} dari {approved}
                        </span>{' '}
                        bahan lengkap datang
                        {summary.partial > 0 && <span className="text-warning-ink"> · {summary.partial} sebagian</span>}
                        {summary.waiting > 0 && <span className="text-warning-ink"> · {summary.waiting} belum datang</span>}
                        {summary.ordered > 0 && <span className="text-info-ink"> ({summary.ordered} sudah dipesan)</span>}
                        {summary.request > 0 && <span className="text-muted-foreground"> · {summary.request} pengajuan menunggu</span>}
                    </p>
                    <ProgressBar value={percent} tone={percent === 100 ? 'success' : 'default'} label="Bahan yang sudah lengkap datang" />
                    {summary.open.length > 0 && (
                        <ul className="space-y-1 text-sm">
                            {summary.open.slice(0, SHOWN).map((line) => (
                                <li key={line.id} className="flex items-start justify-between gap-3">
                                    <span className="min-w-0">
                                        <span className="block truncate text-foreground">{line.display_name}</span>
                                        <MaterialStatusChip line={line} unit={line.unit?.code} inline />
                                    </span>
                                    <span className="shrink-0 text-muted-foreground tabular-nums">
                                        {formatQty(line.qty_received)} / {formatQty(line.qty_planned)} {line.unit?.code ?? ''}
                                    </span>
                                </li>
                            ))}
                            {summary.open.length > SHOWN && (
                                <li className="text-xs text-muted-foreground">dan {summary.open.length - SHOWN} lainnya</li>
                            )}
                        </ul>
                    )}
                </div>
            )}
        </SectionCard>
    );
}
