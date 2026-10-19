import type { ProgressLog } from '@/types';
import { EmptyState } from '@/Components/shared/EmptyState';
import { formatDate } from '@/lib/format';
import { Link2 } from 'lucide-react';

function formatDateTime(value: string) {
    return new Date(value).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * "Progress timeline component: log kronologis per proyek"
 * (.claude/plan/sprint-04.md Jonathan Week 7) — newest first (see
 * Project::progressLogs()'s `latest('log_date')` ordering).
 */
export function ProgressTimeline({ logs }: { logs: ProgressLog[] }) {
    if (logs.length === 0) {
        return (
            <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada catatan progress." />
        );
    }

    return (
        <ol className="relative">
            {logs.map((log, index) => {
                const isLast = index === logs.length - 1;

                return (
                    <li key={log.id} className="relative flex gap-4 pb-6 last:pb-0">
                        {!isLast && (
                            <span
                                className="absolute top-5 left-[9px] w-px bg-daiku-border"
                                style={{ height: 'calc(100% - 0.5rem)' }}
                                aria-hidden
                            />
                        )}
                        <span className="z-10 mt-1 size-5 shrink-0 rounded-full border-2 border-info bg-card" aria-hidden />
                        <div className="min-w-0 flex-1 rounded-xl border border-border bg-card p-3.5 shadow-xs">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-medium text-daiku-dark">
                                    {formatDate(log.log_date)}
                                    {/* Logs written before Sprint 24 D11 still carry the PM's typed percentage. */}
                                    {log.percentage !== null && (
                                        <span className="ml-2 text-xs font-normal text-daiku-muted">(dicatat {log.percentage}%)</span>
                                    )}
                                </p>
                                <p className="text-xs text-daiku-muted">{formatDateTime(log.created_at)}</p>
                            </div>
                            <p className="mt-1 text-sm text-foreground">{log.description}</p>
                            <p className="mt-1 text-xs text-daiku-muted">Oleh: {log.logger?.name ?? '—'}</p>
                            {log.ref_urls && log.ref_urls.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {log.ref_urls.map((url) => (
                                        <a
                                            key={url}
                                            href={url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="flex items-center gap-1 text-xs text-info-ink hover:underline"
                                        >
                                            <Link2 className="size-3" />
                                            Referensi
                                        </a>
                                    ))}
                                </div>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
