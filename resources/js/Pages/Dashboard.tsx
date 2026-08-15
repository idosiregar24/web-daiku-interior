import { StatusChip } from '@/Components/shared/StatusChip';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { Lead, PageProps, User } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

type FollowUpLead = Pick<Lead, 'id' | 'client_name' | 'contact' | 'status' | 'follow_up_date'> & {
    assignee?: Pick<User, 'id' | 'name'>;
};

interface DashboardProps {
    followUps: FollowUpLead[];
}

/** PRD §4.1 follow-up reminder — CEO/MARKETING/SUPERADMIN only, see DashboardController::index(). */
function FollowUpReminder({ followUps }: { followUps: FollowUpLead[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <AlertTriangle className="size-4 text-warning" />
                    Follow-up Lead
                </CardTitle>
            </CardHeader>
            <CardContent>
                {followUps.length === 0 ? (
                    <p className="text-sm text-daiku-muted">
                        Tidak ada follow-up yang jatuh tempo dalam 3 hari ke depan.
                    </p>
                ) : (
                    <ul className="divide-y divide-daiku-border">
                        {followUps.map((lead) => {
                            const isOverdue = lead.follow_up_date && new Date(lead.follow_up_date) < new Date();

                            return (
                                <li key={lead.id} className="flex items-center justify-between gap-4 py-3">
                                    <div className="min-w-0">
                                        <Link
                                            href={route('crm.leads.index')}
                                            className="truncate text-sm font-medium text-daiku-dark hover:underline"
                                        >
                                            {lead.client_name}
                                        </Link>
                                        <p className="truncate text-xs text-daiku-muted">
                                            {lead.contact} · PIC {lead.assignee?.name ?? '—'}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <StatusChip status={lead.status} />
                                        <span className={isOverdue ? 'text-xs font-medium text-error' : 'text-xs text-daiku-muted'}>
                                            {lead.follow_up_date
                                                ? new Date(lead.follow_up_date).toLocaleDateString('id-ID')
                                                : '—'}
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ followUps }: DashboardProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;
    const showFollowUps = role === 'CEO' || role === 'MARKETING' || role === 'SUPERADMIN';

    return (
        <AppLayout breadcrumbs={[{ label: 'Dashboard' }]}>
            <Head title="Dashboard" />

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Selamat datang, {auth.user.name}</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-daiku-muted">
                        Ini adalah fondasi Daiku Interior Enterprise System — CRM,
                        Desain, Quotation, Project Management, Finance, dan modul
                        lainnya akan dibangun di atas struktur ini pada fase
                        berikutnya (lihat PRD bagian 10).
                    </CardContent>
                </Card>

                {showFollowUps && <FollowUpReminder followUps={followUps} />}
            </div>
        </AppLayout>
    );
}
