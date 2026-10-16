import { TaskActionSheet } from '@/Components/modules/tasks/TaskActionSheet';
import { type FieldTask, TaskCard } from '@/Components/modules/tasks/TaskCard';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PushOptIn } from '@/Components/shared/PushOptIn';
import AppLayout from '@/Layouts/AppLayout';
import type { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarCheck, CalendarDays } from 'lucide-react';
import { useMemo, useState } from 'react';

interface TodayIndexProps {
    isWorkDay: boolean;
    /** "21:00" — penalty run & submission cutoff (config/daiku.php). */
    penaltyAt: string;
    canSubmitDailyForm: boolean;
    /** YYYY-MM-DD in WIB. */
    today: string;
    tasks: FieldTask[];
    /** Non-working day only: next week's tasks. */
    upcoming: FieldTask[];
    /** Sprint 24 D7 — today's targets (due today or OVER) not reported yet. */
    missingToday: number;
}

function greeting(hour: number) {
    if (hour >= 4 && hour < 11) return 'Selamat pagi';
    if (hour >= 11 && hour < 15) return 'Selamat siang';
    if (hour >= 15 && hour < 18) return 'Selamat sore';

    return 'Selamat malam';
}

function dayLabel(date: string) {
    return new Date(`${date.slice(0, 10)}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
}

/**
 * Sprint 13 H2/H3/H11 — "Hari Ini", the Tukang's first screen: today's
 * open tasks as cards with the daily-form mark, a warning while forms are
 * missing before the penalty hour, and one tap to update a task (status +
 * form in a single save). On Sunday — no penalty — next week's tasks.
 */
export default function TodayIndex({ isWorkDay, penaltyAt, canSubmitDailyForm, today, tasks, upcoming, missingToday }: TodayIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const [active, setActive] = useState<FieldTask | null>(null);
    // Sprint 24 D7 — counted on the server (Task::awaitingDailyForm, same as the queue & penalty).
    const missing = missingToday;

    /** Sprint 24 D7 — the big ✔: the target is done, and that is today's report. */
    function check(task: FieldTask) {
        router.patch(route('tasks.updateStatus', { task: task.id }), { status: 'DONE' }, { preserveScroll: true });
    }

    const upcomingByDay = useMemo(() => {
        const groups = new Map<string, FieldTask[]>();
        for (const task of upcoming) {
            const day = (task.due_date ?? '').slice(0, 10);
            groups.set(day, [...(groups.get(day) ?? []), task]);
        }

        return [...groups.entries()];
    }, [upcoming]);

    const firstName = auth.user.name.split(' ')[0];

    return (
        <AppLayout>
            <Head title="Hari Ini" />

            <div className="mb-5">
                <p className="text-sm text-muted-foreground">{dayLabel(today)}</p>
                <h1 className="text-xl font-semibold tracking-tight text-foreground">
                    {greeting(new Date().getHours())}, {firstName}
                </h1>
            </div>

            {/* Sprint 18 — a Tukang's phone rings for new tasks and the 21:00 form deadline. */}
            <PushOptIn hideWhenOn className="mb-4" />

            {isWorkDay ? (
                <>
                    {missing > 0 && canSubmitDailyForm && (
                        <Notice tone="warning" className="mb-4">
                            <strong>{missing} target hari ini belum dilaporkan</strong> · centang yang selesai, atau ketuk tugas untuk isi kendala — sebelum {penaltyAt} WIB.
                        </Notice>
                    )}
                    {missing > 0 && !canSubmitDailyForm && (
                        <Notice tone="error" className="mb-4">
                            Batas {penaltyAt} WIB sudah lewat — laporan target hari ini sudah ditutup.
                        </Notice>
                    )}
                    {missing === 0 && tasks.length > 0 && (
                        <Notice tone="success" className="mb-4">
                            Semua target hari ini sudah dilaporkan. Terima kasih!
                        </Notice>
                    )}

                    {tasks.length === 0 ? (
                        <EmptyState
                            icon={CalendarCheck}
                            className="rounded-xl border border-dashed border-border"
                            title="Tidak ada tugas aktif."
                            description="Tugas dari PM akan muncul di sini."
                        />
                    ) : (
                        <div className="flex flex-col gap-3">
                            {tasks.map((task) => (
                                <TaskCard key={task.id} task={task} onOpen={setActive} onCheck={check} />
                            ))}
                        </div>
                    )}
                </>
            ) : (
                <>
                    <Notice tone="info" className="mb-4">
                        Hari ini libur — tidak ada form harian. Berikut tugas Anda minggu depan.
                    </Notice>

                    {upcomingByDay.length === 0 ? (
                        <EmptyState
                            icon={CalendarDays}
                            className="rounded-xl border border-dashed border-border"
                            title="Belum ada tugas untuk minggu depan."
                        />
                    ) : (
                        <div className="flex flex-col gap-5">
                            {upcomingByDay.map(([day, dayTasks]) => (
                                <section key={day}>
                                    <h2 className="mb-2 text-sm font-semibold text-foreground capitalize">{dayLabel(day)}</h2>
                                    <div className="flex flex-col gap-3">
                                        {dayTasks.map((task) => (
                                            <TaskCard key={task.id} task={task} onOpen={setActive} />
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </div>
                    )}
                </>
            )}

            <TaskActionSheet task={active} canSubmitDailyForm={canSubmitDailyForm} onOpenChange={(open) => !open && setActive(null)} />
        </AppLayout>
    );
}
