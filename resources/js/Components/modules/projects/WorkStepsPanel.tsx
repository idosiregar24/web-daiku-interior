import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { WorkSteps } from '@/types';
import { ArrowRight, Check, ChevronDown, ListChecks, RefreshCw } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import type { ProjectTabTarget } from './projectWaiting';

type Step = WorkSteps['items'][number];

const COLLAPSED_KEY = 'daiku.workSteps.collapsed';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * Sprint 33 — "Langkah Kerja" at the top of Detail Proyek: the running
 * phase's work in the order it is filled (ProjectStepsService), so the next
 * thing is always in view — "Kerjakan" opens the tab / card where it is done.
 * Desktop: the steps side by side; phone: a list. Can be folded to one line
 * (remembered per browser).
 */
export function WorkStepsPanel({
    steps,
    onOpen,
    canOpen,
    actions = {},
}: {
    steps: WorkSteps;
    onOpen: (target: ProjectTabTarget) => void;
    canOpen: (target: ProjectTabTarget) => boolean;
    /** The buttons of steps that are one action (`step.action`), when this viewer may press them. */
    actions?: Partial<Record<NonNullable<Step['action']>, ReactNode>>;
}) {
    const [collapsed, setCollapsed] = useState(readCollapsed);
    const current = steps.items.find((step) => step.state === 'current' && step.mine) ?? steps.items.find((step) => step.state === 'current');
    const doneTitles = steps.items.filter((step) => step.state === 'done').map((step) => step.title);

    function toggle() {
        setCollapsed((value) => {
            try {
                window.localStorage.setItem(COLLAPSED_KEY, value ? '0' : '1');
            } catch {
                // Private mode — folding just isn't remembered.
            }

            return !value;
        });
    }

    if (steps.items.length === 0) return null;

    return (
        <section aria-label="Langkah kerja" className="mb-6 rounded-xl bg-background ring-1 ring-border">
            <button type="button" onClick={toggle} className="flex w-full items-center gap-3 px-4 py-3 text-left" aria-expanded={!collapsed}>
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-daiku-yellow-light text-daiku-dark">
                    <ListChecks className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-semibold text-foreground">
                        Langkah Kerja · {steps.phase_label}
                        <span className="ml-2 font-normal text-daiku-muted tabular-nums">
                            {steps.done}/{steps.total} selesai
                        </span>
                    </span>
                    {collapsed && current && (
                        <span className="block truncate text-xs text-daiku-muted">
                            Sekarang: <span className="font-medium text-foreground">{current.title}</span> · {current.who}
                        </span>
                    )}
                </span>
                <ChevronDown className={cn('size-4 shrink-0 text-daiku-muted transition-transform', !collapsed && 'rotate-180')} />
            </button>

            {!collapsed && (
                <div className="border-t border-border px-4 pt-3 pb-4">
                    {/* Phones: finished steps fold into one line, so the open ones are in view without scrolling. */}
                    {doneTitles.length > 0 && (
                        <p className="mb-2 flex items-start gap-1.5 text-xs text-daiku-muted lg:hidden">
                            <Check className="mt-0.5 size-3.5 shrink-0 text-success-ink" />
                            <span>
                                Selesai: <span className="text-foreground">{doneTitles.join(' · ')}</span>
                            </span>
                        </p>
                    )}
                    <ol className="grid gap-2 lg:auto-cols-fr lg:grid-flow-col">
                        {steps.items.map((step, index) => (
                            <StepItem
                                key={step.key}
                                step={step}
                                number={index + 1}
                                onOpen={onOpen}
                                canOpen={canOpen}
                                action={step.action ? actions[step.action] : undefined}
                            />
                        ))}
                    </ol>
                    {steps.next && (
                        <p className="mt-3 text-xs text-daiku-muted">
                            Berikutnya: <span className="font-medium text-foreground">{steps.next.title}</span>
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

function StepItem({
    step,
    number,
    onOpen,
    canOpen,
    action,
}: {
    step: Step;
    number: number;
    onOpen: (target: ProjectTabTarget) => void;
    canOpen: (target: ProjectTabTarget) => boolean;
    action?: ReactNode;
}) {
    const isCurrent = step.state === 'current';
    const openable = canOpen(step.target) && step.state !== 'todo';

    return (
        <li
            className={cn(
                'flex flex-col gap-2 rounded-lg p-2.5',
                isCurrent ? 'bg-daiku-yellow-light ring-2 ring-daiku-yellow' : 'bg-daiku-gray/50',
                step.state === 'todo' && 'opacity-70',
                step.state === 'done' && 'hidden lg:flex',
            )}
        >
            <div className="flex items-center gap-2">
                <StepMarker state={step.state} number={number} />
                {isCurrent && step.mine && <span className="ml-auto rounded-full bg-daiku-yellow px-2 py-0.5 text-[10px] font-semibold text-daiku-dark">Giliran Anda</span>}
            </div>
            <div className="min-w-0 flex-1 space-y-0.5">
                <p className={cn('text-sm leading-snug', step.state === 'done' ? 'text-daiku-muted' : 'font-medium text-foreground')}>{step.title}</p>
                <p className="text-xs text-daiku-muted">
                    {step.who}
                    {step.state === 'optional' && <span className="ml-1.5 rounded bg-info/10 px-1.5 py-0.5 text-[10px] font-medium text-info-ink">Tidak menahan</span>}
                </p>
                {step.detail && <p className={cn('text-xs', isCurrent ? 'text-foreground' : 'text-daiku-muted')}>{step.detail}</p>}
            </div>
            {action && step.state !== 'done' ? (
                <div className="shrink-0 self-center lg:self-start">{action}</div>
            ) : openable && (
                <Button
                    size="sm"
                    variant={isCurrent && step.mine ? 'default' : 'outline'}
                    className={cn('self-start', isCurrent && step.mine && 'w-full lg:w-auto')}
                    onClick={() => onOpen(step.target)}
                >
                    {isCurrent && step.mine ? 'Kerjakan' : 'Buka'}
                    <ArrowRight className="size-4" />
                </Button>
            )}
        </li>
    );
}

function StepMarker({ state, number }: { state: Step['state']; number: number }) {
    if (state === 'done') {
        return (
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-success/15 text-success-ink" aria-label="Selesai">
                <Check className="size-3.5" />
            </span>
        );
    }

    if (state === 'ongoing') {
        return (
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-info/15 text-info-ink" aria-label="Rutin">
                <RefreshCw className="size-3.5" />
            </span>
        );
    }

    return (
        <span
            className={cn(
                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                state === 'current' ? 'bg-daiku-yellow text-daiku-dark' : 'bg-background text-daiku-muted ring-1 ring-border',
            )}
        >
            {number}
        </span>
    );
}
