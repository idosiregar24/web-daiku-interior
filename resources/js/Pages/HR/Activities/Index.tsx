import { DAILY_ACTIVITY_VISIT, DailyActivityForm, type DailyActivityProjectOption } from '@/Components/modules/hr/DailyActivityForm';
import { DailyActivityList, dayLabel } from '@/Components/modules/hr/DailyActivityList';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useConfirm } from '@/hooks/useConfirm';
import AppLayout from '@/Layouts/AppLayout';
import type { DailyActivityEntry, DailyActivityReviewInfo } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarCheck, History, NotebookPen, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface HistoryDay {
    date: string;
    is_work_day: boolean;
    activities: DailyActivityEntry[];
    review: DailyActivityReviewInfo | null;
}

interface DailyActivityIndexProps {
    today: string;
    isWorkDay: boolean;
    activities: DailyActivityEntry[];
    history: HistoryDay[];
    projects: DailyActivityProjectOption[];
}

/**
 * Sprint 29 Sub 13 (I1) — "Aktivitas Harian": today on top (add, change,
 * remove — only today), the last 14 days below with SDM's check. Every
 * account except a Tukang.
 */
export default function DailyActivityIndex({ today, isWorkDay, activities, history, projects }: DailyActivityIndexProps) {
    const [editing, setEditing] = useState<DailyActivityEntry | null>(null);
    const { ask, dialog } = useConfirm();

    async function remove(entry: DailyActivityEntry) {
        if (!(await ask({ title: 'Hapus aktivitas?', description: 'Aktivitas ini dihapus dari catatan hari ini.' }))) return;

        router.delete(route('daily-activities.destroy', { daily_activity: entry.id }), DAILY_ACTIVITY_VISIT);
    }

    return (
        <AppLayout>
            <Head title="Aktivitas Harian" />

            <PageHeader
                title="Aktivitas Harian"
                icon={NotebookPen}
                description="Tulis apa yang Anda kerjakan hari ini. Bisa diubah atau dihapus sampai hari berganti; SDM memeriksanya pada hari kerja berikutnya."
            />

            <div className="grid gap-6">
                <SectionCard title={`Hari ini · ${dayLabel(today)}`} icon={CalendarCheck}>
                    <div className="space-y-5">
                        {!isWorkDay && <Notice tone="info">Hari ini hari libur — mengisi aktivitas tidak wajib.</Notice>}

                        <DailyActivityForm projects={projects} />

                        {activities.length === 0 ? (
                            <EmptyState title="Belum ada aktivitas hari ini" description="Aktivitas yang disimpan muncul di sini." />
                        ) : (
                            <DailyActivityList
                                activities={activities}
                                actions={(entry) => (
                                    <div className="flex gap-1">
                                        <Button variant="ghost" size="icon" className="size-11 sm:size-8" aria-label="Ubah aktivitas" onClick={() => setEditing(entry)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" className="size-11 sm:size-8" aria-label="Hapus aktivitas" onClick={() => remove(entry)}>
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            />
                        )}
                    </div>
                </SectionCard>

                <SectionCard title="Riwayat 14 hari" icon={History} description="Hari kerja yang lewat beserta hasil pemeriksaan SDM.">
                    {history.length === 0 ? (
                        <EmptyState title="Belum ada riwayat" />
                    ) : (
                        <ul className="divide-y divide-border">
                            {history.map((day) => (
                                <li key={day.date} className="space-y-2 py-3 first:pt-0 last:pb-0">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-sm font-medium text-foreground">
                                            {dayLabel(day.date)}
                                            {!day.is_work_day && <span className="font-normal text-muted-foreground"> · hari libur</span>}
                                        </span>
                                        <DayStatus day={day} />
                                    </div>
                                    {day.review?.status === 'PERLU_KLARIFIKASI' && day.review.note && (
                                        <Notice tone="warning">
                                            <span className="font-medium">Klarifikasi dari {day.review.reviewer ?? 'SDM'}:</span>{' '}
                                            <span className="whitespace-pre-line">{day.review.note}</span>
                                        </Notice>
                                    )}
                                    {day.activities.length > 0 ? (
                                        <DailyActivityList activities={day.activities} />
                                    ) : (
                                        <p className="text-sm text-muted-foreground">Tidak ada aktivitas yang ditulis.</p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <ResponsiveDialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Ubah Aktivitas</DialogTitle>
                        <DialogDescription>Hanya bisa diubah hari ini.</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <DailyActivityForm
                            key={editing.id}
                            projects={projects}
                            entry={editing}
                            onSaved={() => setEditing(null)}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                </ResponsiveDialogContent>
            </Dialog>

            {dialog}
        </AppLayout>
    );
}

/** SDM's check of a past day; an unchecked empty working day reads "Tidak Mengisi" already. */
function DayStatus({ day }: { day: HistoryDay }) {
    if (day.review) {
        return <StatusChip domain="dailyActivity" status={day.review.status} />;
    }

    if (day.activities.length === 0) {
        return <StatusChip domain="dailyActivity" status="TIDAK_MENGISI" />;
    }

    return <StatusChip domain="dailyActivity" status="BELUM_DIPERIKSA" />;
}
