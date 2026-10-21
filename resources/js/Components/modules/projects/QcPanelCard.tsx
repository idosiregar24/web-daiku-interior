import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { ProjectExecution } from '@/types';
import { ArrowRight, CalendarCheck, ListChecks, MapPin, SearchCheck, ShieldCheck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { todayWib } from './taskTargetRows';

type Tone = 'success' | 'warning' | 'error' | 'muted';

interface QcRow {
    key: string;
    icon: LucideIcon;
    title: string;
    status: string;
    tone: Tone;
    /** Waits on this viewer — tinted like "Giliran Anda". */
    mine: boolean;
    anchor: string;
    extra?: ReactNode;
}

const STATUS_INK: Record<Tone, string> = {
    success: 'text-success-ink',
    warning: 'text-warning-ink',
    error: 'text-error-ink',
    muted: 'text-muted-foreground',
};

/**
 * Scrolls to a card of this tab; one folded in a history row opens through
 * the tab's `hashchange` handling (Sprint 26 H2f).
 */
function jump(anchor: string) {
    const element = document.getElementById(anchor);

    if (element && element.childElementCount > 0) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });

        return;
    }

    if (window.location.hash === `#${anchor}`) {
        history.replaceState(history.state, '', window.location.pathname + window.location.search);
    }
    window.location.hash = anchor;
}

function weeklyRow(execution: ProjectExecution): QcRow | null {
    const week = execution.currentWeek;

    if (!week) return null;

    const report = execution.weeklyReports.find((row) => row.week_start === week.week_start);
    const due = week.is_report_day ? ' · batas hari ini' : week.is_due ? ' · lewat Jumat' : ' · paling lambat Jumat';
    const base = { key: 'weekly', icon: CalendarCheck, title: `Pengecekan mingguan · minggu ke-${week.week_number}`, anchor: 'laporan-mingguan' };

    switch (report?.status) {
        case 'DIAJUKAN':
            return { ...base, status: 'Laporan diajukan — menunggu PM', tone: 'warning', mine: execution.can.decide };
        case 'DISETUJUI':
            return { ...base, status: 'Laporan disetujui PM', tone: 'success', mine: false };
        case 'DITOLAK':
            return { ...base, status: 'Laporan ditolak — perbaiki lalu kirim lagi', tone: 'error', mine: execution.can.submitWeekly };
        default:
            return {
                ...base,
                status: `Laporan belum diisi${due}`,
                // Sprint 29 Sub 02 — may be sent from Monday; warning from the report day on.
                tone: week.is_due ? 'warning' : 'muted',
                mine: execution.can.submitWeekly,
            };
    }
}

function dailyRow(execution: ProjectExecution): QcRow | null {
    const data = execution.targetVsActual;

    if (!data) return null;

    const today = todayWib();
    const inWeek = data.days.includes(today);
    let target = 0;
    let actual = 0;
    let rolled = 0;
    for (const row of data.rows) {
        const cell = row.cells[today];
        if (!cell) continue;
        target += cell.target;
        actual += cell.actual;
        rolled += cell.rolled;
    }

    return {
        key: 'daily',
        icon: ListChecks,
        title: 'Pengecekan daily target · hari ini',
        status: !inWeek
            ? 'Lihat minggu berjalan di Target vs Actual'
            : target === 0 && actual === 0
              ? 'Tidak ada target hari ini'
              : `${actual} dari ${target} target selesai${rolled > 0 ? ` · ↻ ${rolled} bergulir` : ''}`,
        tone: !inWeek || target === 0 ? 'muted' : actual >= target ? 'success' : 'warning',
        mine: false,
        anchor: 'target-vs-actual',
    };
}

function progressRow(execution: ProjectExecution): QcRow {
    const reached = execution.progress >= execution.threshold;

    return {
        key: 'progress',
        icon: MapPin,
        title: `Pengecekan ${execution.threshold}% · sebelum berangkat ke lokasi`,
        status: execution.departure
            ? 'Berangkat ke lokasi disetujui PM'
            : reached
              ? `Actual ${execution.progress}% — siap, menunggu persetujuan PM`
              : `Actual ${execution.progress}% dari ${execution.threshold}%`,
        tone: execution.departure ? 'success' : reached ? 'warning' : 'muted',
        mine: !execution.departure && reached && execution.can.approveDeparture,
        anchor: 'berangkat',
        extra: !execution.departure && (
            <ProgressBar value={execution.progress} tone={reached ? 'success' : 'default'} label="Actual activity proyek" className="mt-2 max-w-xs" />
        ),
    };
}

function findingsRow(execution: ProjectExecution): QcRow {
    const open = execution.findings.filter((row) => row.status === 'TERBUKA').length;

    return {
        key: 'findings',
        icon: SearchCheck,
        title: 'Pengecekan finishing di lokasi',
        status: open > 0 ? `${open} temuan terbuka — menahan QC timeline & penyelesaian` : execution.findings.length > 0 ? 'Semua temuan selesai' : 'Belum ada temuan',
        tone: open > 0 ? 'error' : execution.findings.length > 0 ? 'success' : 'muted',
        mine: open > 0 && execution.can.resolveFinding,
        anchor: 'temuan',
    };
}

/**
 * Sprint 27 P2 — the whiteboard's "QC" branch as one card at PRODUKSI /
 * PEMASANGAN: pengecekan mingguan (paling lambat Jumat) · daily target · 70% · finishing
 * di lokasi, each with its state and a link to its card. Everything is read
 * from `execution` (no new data); the work stays in the cards themselves.
 */
export function QcPanelCard({ execution }: { execution: ProjectExecution }) {
    const rows = [
        weeklyRow(execution),
        dailyRow(execution),
        progressRow(execution),
        execution.phase === 'PEMASANGAN' ? findingsRow(execution) : null,
    ].filter((row): row is QcRow => row !== null);

    return (
        <SectionCard
            title="QC Pengawas"
            description="Pengecekan Pengawas (QA/QC) selama produksi — mingguan (paling lambat Jumat), daily target, 70% sebelum berangkat, finishing di lokasi."
            icon={ShieldCheck}
            flush
        >
            <ul className="divide-y divide-border">
                {rows.map((row) => {
                    const Icon = row.icon;

                    return (
                        <li key={row.key} className={cn('flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:px-5', row.mine && 'bg-warning/10')}>
                            <Icon className="hidden size-5 shrink-0 text-muted-foreground sm:block" aria-hidden />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-foreground">{row.title}</p>
                                <p className={cn('mt-0.5 text-sm', STATUS_INK[row.tone])}>{row.status}</p>
                                {row.extra}
                            </div>
                            <Button variant="outline" size="sm" className="w-full sm:w-auto" onClick={() => jump(row.anchor)}>
                                Buka
                                <ArrowRight className="size-4" />
                            </Button>
                        </li>
                    );
                })}
            </ul>
        </SectionCard>
    );
}
