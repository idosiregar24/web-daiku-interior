import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import type { ExecutionRequirement, ProjectExecution } from '@/types';
import { router } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Circle, ClipboardCheck, Loader2 } from 'lucide-react';
import { useState } from 'react';
import type { ProjectTabTarget } from './projectWaiting';

/** Where each condition is completed (Sprint 27 P1b). */
const FIX_TARGET: Record<string, ProjectTabTarget> = {
    materials: { tab: 'execution', anchor: 'tm-bahan' },
    wages: { tab: 'task', view: 'tukang' },
    targets: { tab: 'task', view: 'hari' },
};

function RequirementRow({
    item,
    onFix,
}: {
    item: ExecutionRequirement;
    /** Shown only to whoever completes it, when the target tab is theirs. */
    onFix?: () => void;
}) {
    const Icon = item.done ? CheckCircle2 : Circle;

    return (
        <li className={cn('flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:px-5', !item.done && 'bg-warning/5')}>
            <Icon className={cn('hidden size-6 shrink-0 sm:block', item.done ? 'text-success-ink' : 'text-warning-ink')} aria-hidden />
            <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <Icon className={cn('size-5 shrink-0 sm:hidden', item.done ? 'text-success-ink' : 'text-warning-ink')} aria-hidden />
                    {item.label}
                    <span className="sr-only">{item.done ? '(lengkap)' : '(belum lengkap)'}</span>
                </p>
                {!item.done && item.detail && <p className="mt-0.5 text-sm text-warning-ink">{item.detail}</p>}
                {!item.done && (item.missing?.length ?? 0) > 0 && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {item.missing!.join(', ')}
                        {(item.more ?? 0) > 0 && ` dan ${item.more} lainnya`}
                    </p>
                )}
            </div>
            {!item.done && onFix && (
                <Button variant="outline" size="sm" className="w-full sm:w-auto" onClick={onFix}>
                    Lengkapi
                    <ArrowRight className="size-4" />
                </Button>
            )}
        </li>
    );
}

/**
 * Sprint 27 P1 — the whiteboard's "Pengawas · Syarat*: Bahan ✓ · Upah ✓ ·
 * Daily target ✓" as its own card at PERSIAPAN: each condition says what
 * is missing and links to where it's done, and "Ajukan DP Pengawas" sits
 * here once all three are met (SupervisorPaymentService::dpRequirements()
 * — the server checks the same rule again on submit).
 */
export function DpRequirementsCard({
    projectId,
    execution,
    onOpen,
    canOpen,
}: {
    projectId: number;
    execution: ProjectExecution;
    onOpen?: (target: ProjectTabTarget) => void;
    canOpen?: (target: ProjectTabTarget) => boolean;
}) {
    const [busy, setBusy] = useState(false);
    const { dpRequirements: items, can, fee, payments } = execution;

    if (items.length === 0) {
        return null;
    }

    const ready = items.every((row) => row.done);
    const asked = payments.some((payment) => payment.kind === 'DP' && payment.status !== 'DITOLAK');
    // The ones who complete the conditions: the project's Pengawas (and the PM side that plans targets).
    const fixes = can.requestDp || can.manageTargets;
    const doneCount = items.filter((row) => row.done).length;

    return (
        <SectionCard
            title="Syarat DP Upah Tukang"
            description={`${doneCount} dari ${items.length} syarat lengkap · lengkap semua → Ajukan DP → PM menyetujui = Start Produksi.`}
            icon={ClipboardCheck}
            flush
            footer={
                can.requestDp && !asked ? (
                    <Button
                        className="w-full sm:w-auto"
                        disabled={!ready || busy}
                        onClick={() =>
                            // "Ajukan DP" — a request to the PM: keeps the pop-up, reloads the Eksekusi side only.
                            router.post(route('projects.supervisor-payments.dp', { project: projectId }), {}, {
                                ...projectVisit(RELOAD.supervision),
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                            })
                        }
                    >
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        Ajukan DP Upah{fee ? ` · ${formatRupiah(fee.dp_amount)}` : ''}
                    </Button>
                ) : asked ? (
                    <p className="text-sm text-muted-foreground">DP sudah diajukan — disetujui PM = Start Produksi.</p>
                ) : undefined
            }
        >
            <ul className="divide-y divide-border">
                {items.map((item) => {
                    // Sprint 31 Sub 07 — wages per RAB item are filled in Item RAB › Upah Tukang.
                    const target = item.key === 'wages' && execution.usesWorkItems ? { tab: 'items', view: 'upah' } : FIX_TARGET[item.key];
                    const openable = fixes && target && onOpen && (canOpen?.(target) ?? true);

                    return <RequirementRow key={item.key} item={item} onFix={openable ? () => onOpen(target) : undefined} />;
                })}
            </ul>
        </SectionCard>
    );
}
