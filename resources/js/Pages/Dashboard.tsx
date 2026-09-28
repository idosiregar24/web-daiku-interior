import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AppLayout, { ROLE_LABEL, useNavGroups } from '@/Layouts/AppLayout';
import { formatDate, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Lead, PageProps, User } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Bell, CalendarClock, ChevronRight, LayoutGrid, PhoneCall } from 'lucide-react';

type FollowUpLead = Pick<Lead, 'id' | 'client_name' | 'contact' | 'status' | 'follow_up_date'> & {
    assignee?: Pick<User, 'id' | 'name'>;
};

interface DashboardProps {
    followUps: FollowUpLead[];
}

function greeting(hour: number) {
    if (hour >= 4 && hour < 11) return 'Selamat pagi';
    if (hour >= 11 && hour < 15) return 'Selamat siang';
    if (hour >= 15 && hour < 18) return 'Selamat sore';

    return 'Selamat malam';
}

function isOverdue(lead: FollowUpLead) {
    return Boolean(lead.follow_up_date && new Date(lead.follow_up_date) < new Date());
}

/** PRD §4.1 follow-up reminder — CEO/MARKETING/SUPERADMIN only, see DashboardController::index(). */
function FollowUpReminder({ followUps }: { followUps: FollowUpLead[] }) {
    return (
        <SectionCard
            title="Follow-up Lead"
            description="Lead yang jatuh tempo follow-up dalam 3 hari ke depan."
            icon={PhoneCall}
            flush
            action={
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('crm.leads.index')}>
                        Data Lead
                        <ArrowRight className="size-3.5" />
                    </Link>
                </Button>
            }
        >
            {followUps.length === 0 ? (
                <EmptyState
                    icon={CalendarClock}
                    title="Tidak ada follow-up yang jatuh tempo dalam 3 hari ke depan."
                />
            ) : (
                <ul className="divide-y divide-border">
                    {followUps.map((lead) => {
                        const overdue = isOverdue(lead);

                        return (
                            <li
                                key={lead.id}
                                className="flex items-center justify-between gap-4 px-4 py-3 transition-colors hover:bg-daiku-gray/60 sm:px-5"
                            >
                                <div className="flex min-w-0 items-center gap-3">
                                    <span
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                            overdue ? 'bg-error/10 text-error-ink' : 'bg-daiku-yellow-light text-daiku-yellow-dark',
                                        )}
                                    >
                                        {lead.client_name.slice(0, 1).toUpperCase()}
                                    </span>
                                    <div className="min-w-0">
                                        <Link
                                            href={route('crm.leads.index')}
                                            className="block truncate text-sm font-medium text-foreground hover:underline"
                                        >
                                            {lead.client_name}
                                        </Link>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {lead.contact} · PIC {lead.assignee?.name ?? '—'}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-3">
                                    <StatusChip status={lead.status} className="hidden sm:inline-flex" />
                                    <span
                                        className={cn(
                                            'min-w-24 text-right text-xs tabular-nums',
                                            overdue ? 'font-medium text-error-ink' : 'text-muted-foreground',
                                        )}
                                    >
                                        {lead.follow_up_date
                                            ? new Date(lead.follow_up_date).toLocaleDateString('id-ID')
                                            : '—'}
                                        {overdue && <span className="block text-[11px] font-normal">Terlewat</span>}
                                    </span>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </SectionCard>
    );
}

/** Every module the current role can open, grouped like the sidebar. */
function ModuleDirectory() {
    const groups = useNavGroups().filter((group) => group.label !== 'Utama');

    return (
        <SectionCard
            title="Modul Anda"
            description="Akses cepat ke modul sesuai peran Anda."
            icon={LayoutGrid}
        >
            <div className="gap-4 sm:columns-2 xl:columns-3">
                {groups.map((group) => (
                    <div key={group.label} className="mb-4 break-inside-avoid last:mb-0">
                        <p className="mb-1.5 px-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            {group.label}
                        </p>
                        <div className="flex flex-col gap-1">
                            {group.items.map((item) => {
                                const Icon = item.icon;

                                if (!item.routeName) {
                                    return null;
                                }

                                return (
                                    <Link
                                        key={item.label}
                                        href={route(item.routeName)}
                                        className="group flex items-center gap-2.5 rounded-lg border border-border px-2.5 py-2 text-sm font-medium text-foreground transition-colors hover:border-daiku-muted/40 hover:bg-daiku-yellow-light/60"
                                    >
                                        <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-daiku-gray text-daiku-muted transition-colors group-hover:bg-daiku-yellow group-hover:text-daiku-dark">
                                            <Icon className="size-3.5" />
                                        </span>
                                        <span className="flex-1 truncate">{item.label}</span>
                                        <ChevronRight className="size-4 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" />
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>
        </SectionCard>
    );
}

export default function Dashboard({ followUps }: DashboardProps) {
    const { auth, notifications, unreadNotificationsCount, site } = usePage<PageProps>().props;
    const role = auth.user?.role;
    const showFollowUps = role === 'CEO' || role === 'MARKETING' || role === 'SUPERADMIN';
    const moduleCount = useNavGroups().reduce(
        (sum, group) => sum + group.items.filter((item) => item.routeName && item.routeName !== 'dashboard').length,
        0,
    );
    const overdueCount = followUps.filter(isOverdue).length;
    const now = new Date();

    return (
        <AppLayout breadcrumbs={[{ label: 'Dashboard' }]}>
            <Head title="Dashboard" />

            <Card className="relative mb-6 gap-0 overflow-hidden py-0">
                <div
                    aria-hidden
                    className="bg-grid-pattern absolute inset-0 [mask-image:linear-gradient(to_left,black,transparent_75%)]"
                />
                <div aria-hidden className="absolute -top-24 -right-16 size-72 rounded-full bg-daiku-yellow/25 blur-3xl" />
                <div className="relative p-5 sm:p-6">
                    <div>
                        <p className="text-xs font-medium text-muted-foreground">
                            <span className="capitalize">
                                {now.toLocaleDateString('id-ID', {
                                    weekday: 'long',
                                    day: 'numeric',
                                    month: 'long',
                                    year: 'numeric',
                                })}
                            </span>
                            {role && <> · {ROLE_LABEL[role]}</>}
                        </p>
                        <h1 className="mt-1 text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                            {greeting(now.getHours())}, {auth.user.name}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Selamat datang di {site.name} {site.tagline} — ringkasan pekerjaan Anda hari ini.
                        </p>
                    </div>
                </div>
            </Card>

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Notifikasi belum dibaca"
                    value={unreadNotificationsCount}
                    icon={Bell}
                    hint={unreadNotificationsCount > 0 ? 'Perlu ditindaklanjuti' : 'Semua sudah dibaca'}
                />
                {showFollowUps && (
                    <StatCard
                        label="Follow-up 3 hari ke depan"
                        value={followUps.length}
                        icon={CalendarClock}
                        hint="Lead menunggu dihubungi"
                    />
                )}
                {showFollowUps && (
                    <StatCard
                        label="Follow-up terlewat"
                        value={overdueCount}
                        icon={AlertTriangle}
                        tone={overdueCount > 0 ? 'error' : 'default'}
                        hint={overdueCount > 0 ? 'Segera hubungi klien' : 'Semua sesuai jadwal'}
                    />
                )}
                <StatCard label="Modul dapat diakses" value={moduleCount} icon={LayoutGrid} hint="Sesuai hak akses peran Anda" />
            </div>

            <div className="grid gap-6 xl:grid-cols-3">
                <div className="flex flex-col gap-6 xl:col-span-2">
                    {showFollowUps && <FollowUpReminder followUps={followUps} />}
                    <ModuleDirectory />
                </div>

                <SectionCard
                    title="Notifikasi Terbaru"
                    description="Belum dibaca, paling baru di atas."
                    icon={Bell}
                    flush
                    className="self-start"
                    action={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('notifications.index')}>
                                Semua
                                <ArrowRight className="size-3.5" />
                            </Link>
                        </Button>
                    }
                >
                    {notifications.length === 0 ? (
                        <EmptyState icon={Bell} title="Belum ada notifikasi baru." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {notifications.slice(0, 6).map((notification) => (
                                <li key={notification.id} className="flex gap-3 px-4 py-3 sm:px-5">
                                    <span className="mt-1.5 size-2 shrink-0 rounded-full bg-daiku-yellow" />
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-foreground">{notification.title}</p>
                                        <p className="line-clamp-2 text-xs text-muted-foreground">{notification.message}</p>
                                        <p className="mt-1 text-[11px] text-muted-foreground/80" title={formatDate(notification.created_at)}>
                                            {formatRelative(notification.created_at)}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}
