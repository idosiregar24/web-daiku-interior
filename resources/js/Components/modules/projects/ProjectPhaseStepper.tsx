import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Dialog, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { domainStatusLabel, waitingOn } from '@/lib/waitingOn';
import type { Project, ProjectExecution, ProjectPhase } from '@/types';
import { Check, ChevronRight, Minus, Pause } from 'lucide-react';
import { useState } from 'react';

/** App\Enums\ProjectPhase in order, then the project's end. */
const PHASES: ProjectPhase[] = ['GAMBAR_KERJA', 'TM', 'PERSIAPAN', 'PRODUKSI', 'PEMASANGAN'];
const DONE_KEY = 'SELESAI';

type StepState = 'done' | 'current' | 'paused' | 'upcoming' | 'skipped';

interface Step {
    key: ProjectPhase | typeof DONE_KEY;
    label: string;
    state: StepState;
    /** Under the label: whose turn (current), the date it finished (done), "dilewati" (legacy). */
    note: string | null;
}

/** When each phase was left — only for viewers who receive the Eksekusi data. */
function finishedOn(execution: ProjectExecution | null): Partial<Record<ProjectPhase, string | null>> {
    if (!execution) return {};

    const dp = execution.payments.find((payment) => payment.kind === 'DP' && (payment.status === 'DISETUJUI_PM' || payment.status === 'DIBAYAR'));

    return {
        GAMBAR_KERJA: execution.workingDrawing?.completed_at ?? null,
        TM: execution.meeting?.completed_at ?? null,
        PERSIAPAN: dp?.decided_at ?? null,
        PRODUKSI: execution.departure?.approved_at ?? null,
    };
}

function buildSteps(project: Pick<Project, 'status' | 'phase'>, execution: ProjectExecution | null): Step[] {
    const finished = finishedOn(execution);
    const completed = project.status === 'COMPLETED';
    const current = completed ? PHASES.length : PHASES.indexOf(project.phase ?? 'PRODUKSI');
    // Sprint 24 D1 — a project opened before the flow existed starts at PRODUKSI.
    const legacy = execution?.isLegacy ?? false;

    const steps: Step[] = PHASES.map((phase, index) => {
        if (index < current) {
            const skipped = legacy && index < PHASES.indexOf('PRODUKSI');
            const date = finished[phase];

            return {
                key: phase,
                label: domainStatusLabel('projectPhase', phase) ?? phase,
                state: skipped ? 'skipped' : 'done',
                note: skipped ? 'dilewati' : date ? formatDate(date) : null,
            };
        }

        if (index === current) {
            const paused = project.status === 'ON_HOLD';

            return {
                key: phase,
                label: domainStatusLabel('projectPhase', phase) ?? phase,
                state: paused ? 'paused' : 'current',
                note: paused ? 'Ditahan' : (waitingOn('projectPhase', phase) ?? null),
            };
        }

        return { key: phase, label: domainStatusLabel('projectPhase', phase) ?? phase, state: 'upcoming', note: null };
    });

    steps.push({ key: DONE_KEY, label: 'Selesai', state: completed ? 'done' : 'upcoming', note: null });

    return steps;
}

const DOT_CLASS: Record<StepState, string> = {
    done: 'bg-success/15 text-success-ink',
    current: 'bg-daiku-yellow text-daiku-dark',
    paused: 'bg-warning/15 text-warning-ink',
    upcoming: 'bg-daiku-gray text-muted-foreground',
    skipped: 'bg-daiku-gray text-muted-foreground',
};

function StepDot({ step, index }: { step: Step; index: number }) {
    return (
        <span
            className={cn('flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums', DOT_CLASS[step.state])}
            aria-hidden
        >
            {step.state === 'done' ? (
                <Check className="size-4" />
            ) : step.state === 'paused' ? (
                <Pause className="size-3.5" />
            ) : step.state === 'skipped' ? (
                <Minus className="size-3.5" />
            ) : (
                index + 1
            )}
        </span>
    );
}

function StepText({ step }: { step: Step }) {
    return (
        <span className="min-w-0">
            <span
                className={cn(
                    'block text-sm leading-tight',
                    step.state === 'current' || step.state === 'paused' ? 'font-semibold text-foreground' : 'text-muted-foreground',
                    step.state === 'done' && 'text-foreground',
                )}
            >
                {step.label}
            </span>
            {step.note && (
                <span className={cn('mt-0.5 block text-xs', step.state === 'paused' ? 'text-warning-ink' : 'text-muted-foreground')}>
                    {step.state === 'current' ? `giliran ${step.note}` : step.note}
                </span>
            )}
        </span>
    );
}

const STATE_SR: Record<StepState, string> = {
    done: 'selesai',
    current: 'sedang berjalan',
    paused: 'ditahan',
    upcoming: 'belum',
    skipped: 'dilewati',
};

/**
 * Sprint 26 H1 — where the project is in the flow, like an order tracker:
 * Gambar Kerja → TM → Persiapan → Produksi → Pemasangan → Selesai, the
 * running step with whose turn it is. Built from `project.phase`/`status`,
 * so every role that opens the project sees it (dates only come with the
 * Eksekusi data). Hidden for a cancelled project — its notice says enough.
 */
export function ProjectPhaseStepper({
    project,
    execution,
    className,
}: {
    project: Pick<Project, 'status' | 'phase'>;
    execution: ProjectExecution | null;
    className?: string;
}) {
    const [open, setOpen] = useState(false);

    if (project.status === 'CANCELLED') {
        return null;
    }

    const steps = buildSteps(project, execution);
    const currentIndex = steps.findIndex((step) => step.state === 'current' || step.state === 'paused');
    const shown = currentIndex === -1 ? steps.length - 1 : currentIndex;
    const active = steps[shown];

    return (
        <nav aria-label="Tahap proyek" className={cn('mb-6', className)}>
            {/* md and up — the whole track. */}
            <ol className="hidden items-start md:flex">
                {steps.map((step, index) => (
                    <li key={step.key} className="flex min-w-0 flex-1 items-start last:flex-none" aria-current={index === currentIndex ? 'step' : undefined}>
                        <span className="flex min-w-0 items-start gap-2 pr-2">
                            <StepDot step={step} index={index} />
                            <StepText step={step} />
                            <span className="sr-only">({STATE_SR[step.state]})</span>
                        </span>
                        {index < steps.length - 1 && (
                            <span
                                className={cn(
                                    'mt-3.5 h-0.5 min-w-4 flex-1 rounded-full',
                                    step.state === 'done' || step.state === 'skipped' ? 'bg-success/40' : 'bg-border',
                                )}
                                aria-hidden
                            />
                        )}
                    </li>
                ))}
            </ol>

            {/* Phone — one line + a 6-part bar; tap for the list. */}
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="flex w-full items-center gap-3 rounded-xl bg-daiku-gray/60 px-3 py-2.5 text-left ring-1 ring-border ring-inset md:hidden"
            >
                <StepDot step={active} index={shown} />
                <span className="min-w-0 flex-1">
                    <span className="block text-xs text-muted-foreground">
                        Tahap {shown + 1} dari {steps.length}
                    </span>
                    <StepText step={active} />
                    <span className="mt-2 flex gap-1" aria-hidden>
                        {steps.map((step) => (
                            <span
                                key={step.key}
                                className={cn(
                                    'h-1 flex-1 rounded-full',
                                    step.state === 'done' || step.state === 'skipped'
                                        ? 'bg-success/50'
                                        : step.state === 'current'
                                          ? 'bg-daiku-yellow'
                                          : step.state === 'paused'
                                            ? 'bg-warning/60'
                                            : 'bg-border',
                                )}
                            />
                        ))}
                    </span>
                </span>
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <ResponsiveDialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Tahap Proyek</DialogTitle>
                        <DialogDescription>Alur dari gambar kerja sampai proyek selesai.</DialogDescription>
                    </DialogHeader>
                    <ol className="space-y-3">
                        {steps.map((step, index) => (
                            <li key={step.key} className="flex items-start gap-3" aria-current={index === currentIndex ? 'step' : undefined}>
                                <StepDot step={step} index={index} />
                                <StepText step={step} />
                                <span className="sr-only">({STATE_SR[step.state]})</span>
                            </li>
                        ))}
                    </ol>
                </ResponsiveDialogContent>
            </Dialog>
        </nav>
    );
}
