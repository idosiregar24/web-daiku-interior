import type { DailyActivityEntry } from '@/types';
import { format, parseISO } from 'date-fns';
import { id } from 'date-fns/locale';
import { FolderKanban } from 'lucide-react';
import type { ReactNode } from 'react';

/** "Selasa, 20 Oktober 2026" — a `Y-m-d` day. */
export function dayLabel(date: string): string {
    return format(parseISO(date), 'EEEE, d MMMM yyyy', { locale: id });
}

/** "14.05" — when an entry was written. */
function timeLabel(value: string | null): string | null {
    return value ? format(parseISO(value), 'HH.mm') : null;
}

/**
 * Sprint 29 Sub 13 — a day's Aktivitas Harian entries: the text as written
 * (line breaks kept), the project and the time. `actions` adds per-entry
 * buttons (today's own entries only).
 */
export function DailyActivityList({ activities, actions }: { activities: DailyActivityEntry[]; actions?: (entry: DailyActivityEntry) => ReactNode }) {
    return (
        <ul className="space-y-2">
            {activities.map((entry) => (
                <li key={entry.id} className="flex items-start gap-2 rounded-lg bg-daiku-gray/60 px-3 py-2">
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-sm whitespace-pre-line break-words text-foreground">{entry.description}</p>
                        <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                            {entry.project && (
                                <span className="inline-flex items-center gap-1">
                                    <FolderKanban className="size-3.5" />
                                    {entry.project.name}
                                </span>
                            )}
                            {timeLabel(entry.created_at) && <span>{timeLabel(entry.created_at)}</span>}
                        </p>
                    </div>
                    {actions?.(entry)}
                </li>
            ))}
        </ul>
    );
}
