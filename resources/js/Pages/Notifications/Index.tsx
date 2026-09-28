import { PageHeader } from '@/Components/shared/PageHeader';
import { EmptyState } from '@/Components/shared/EmptyState';
import { TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime, formatRelative } from '@/lib/format';
import { notificationHref } from '@/lib/notificationHref';
import { cn } from '@/lib/utils';
import type { AppNotification, PageProps, PaginatedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';

interface NotificationIndexProps {
    items: PaginatedData<AppNotification>;
    filters: { unread: boolean };
}

/**
 * PRD §4.9 notification history — "Mark as read per notifikasi atau mark
 * all as read", "Riwayat notifikasi tersimpan 90 hari". Own rows only
 * (NotificationController scopes every query to the current user).
 */
export default function NotificationIndex({ items, filters }: NotificationIndexProps) {
    const { unreadNotificationsCount } = usePage<PageProps>().props;

    function open(notification: AppNotification) {
        const href = notificationHref(notification);
        const visit = () => href && router.visit(href);

        if (notification.is_read) {
            visit();

            return;
        }

        router.patch(
            route('notifications.markAsRead', { notification: notification.id }),
            {},
            { preserveScroll: true, onSuccess: visit },
        );
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Notifikasi' }]}>
            <Head title="Notifikasi" />

            <PageHeader
                title="Notifikasi"
                icon={Bell}
                description="Riwayat notifikasi 90 hari terakhir."
                actions={
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={unreadNotificationsCount === 0}
                        onClick={() => router.patch(route('notifications.markAllAsRead'), {}, { preserveScroll: true })}
                    >
                        <CheckCheck className="size-4" />
                        Tandai semua dibaca
                    </Button>
                }
            />

            <TableCard
                pagination={items}
                toolbar={
                    <Tabs
                        value={filters.unread ? 'unread' : 'all'}
                        onValueChange={(value) =>
                            router.get(route('notifications.index'), value === 'unread' ? { unread: 1 } : {}, {
                                preserveState: true,
                                replace: true,
                            })
                        }
                    >
                        <TabsList>
                            <TabsTrigger value="all">Semua</TabsTrigger>
                            <TabsTrigger value="unread">Belum dibaca ({unreadNotificationsCount})</TabsTrigger>
                        </TabsList>
                    </Tabs>
                }
            >
                {items.data.length === 0 ? (
                    <EmptyState
                        icon={Bell}
                        title={filters.unread ? 'Tidak ada notifikasi yang belum dibaca.' : 'Belum ada notifikasi.'}
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {items.data.map((notification) => (
                            <li key={notification.id}>
                                <button
                                    type="button"
                                    onClick={() => open(notification)}
                                    className={cn(
                                        'flex w-full items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-daiku-gray/60',
                                        !notification.is_read && 'bg-daiku-yellow-light/40',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'mt-1.5 size-2 shrink-0 rounded-full',
                                            notification.is_read ? 'bg-daiku-border' : 'bg-daiku-yellow',
                                        )}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-baseline justify-between gap-3">
                                            <span
                                                className={cn(
                                                    'text-sm text-daiku-dark',
                                                    !notification.is_read && 'font-semibold',
                                                )}
                                            >
                                                {notification.title}
                                            </span>
                                            <time
                                                dateTime={notification.created_at}
                                                title={formatDateTime(notification.created_at)}
                                                className="shrink-0 text-xs text-daiku-muted"
                                            >
                                                {formatRelative(notification.created_at)}
                                            </time>
                                        </span>
                                        <span className="mt-0.5 block text-sm text-daiku-muted">
                                            {notification.message}
                                        </span>
                                        {!notification.is_read && <span className="sr-only">(belum dibaca)</span>}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </TableCard>
        </AppLayout>
    );
}
