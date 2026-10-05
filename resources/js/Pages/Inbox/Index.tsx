import { InboxGroupCard } from '@/Components/modules/inbox/InboxGroupCard';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Card } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import type { InboxGroup } from '@/types';
import { Head } from '@inertiajs/react';
import { CheckCheck, Inbox } from 'lucide-react';

interface InboxIndexProps {
    groups: InboxGroup[];
}

/**
 * Sprint 13 #4 — "Perlu Tindakan": what waits for the signed-in user right
 * now, one card per queue (busiest first). A queue empties itself as its
 * items are processed on their own pages; notifications (the bell) are
 * events, this page is the current state.
 */
export default function InboxIndex({ groups }: InboxIndexProps) {
    const total = groups.reduce((sum, group) => sum + group.count, 0);

    return (
        <AppLayout>
            <Head title="Perlu Tindakan" />

            <PageHeader
                title="Perlu Tindakan"
                icon={Inbox}
                description={
                    total > 0
                        ? `${total} hal menunggu Anda — yang paling lama menunggu tampil paling atas di tiap kelompok.`
                        : 'Pekerjaan yang menunggu giliran Anda muncul di sini.'
                }
            />

            {groups.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={CheckCheck}
                        title="Semua beres"
                        description="Tidak ada yang menunggu persetujuan atau tindakan Anda saat ini."
                    />
                </Card>
            ) : (
                <div className="grid items-start gap-6 lg:grid-cols-2">
                    {groups.map((group) => (
                        <InboxGroupCard key={group.key} group={group} />
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
