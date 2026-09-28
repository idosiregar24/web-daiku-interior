import { BrandMark } from '@/Components/shared/BrandMark';
import { CommandMenu } from '@/Components/shared/CommandMenu';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Badge } from '@/Components/ui/badge';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/Components/ui/breadcrumb';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/Components/ui/sheet';
import { Toaster } from '@/Components/ui/sonner';
import { useFlashToasts } from '@/hooks/useFlashToasts';
import { useRealtimeNotifications } from '@/hooks/useRealtimeNotifications';
import { formatRelative } from '@/lib/format';
import { notificationHref } from '@/lib/notificationHref';
import { cn } from '@/lib/utils';
import type { AppNotification, PageProps, Role, User } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertOctagon,
    BarChart3,
    Bell,
    BellOff,
    CheckCheck,
    ChevronsUpDown,
    Clock,
    ClipboardCheck,
    FileText,
    FolderKanban,
    HandCoins,
    History,
    LayoutDashboard,
    ListChecks,
    LogOut,
    type LucideIcon,
    Menu,
    Package,
    Palette,
    Percent,
    PiggyBank,
    Receipt,
    ScrollText,
    ShieldCheck,
    User as UserIcon,
    Users,
    UserCog,
    Database,
    Settings,
    Wallet,
    Warehouse,
} from 'lucide-react';
import { Fragment, PropsWithChildren, ReactNode, useMemo } from 'react';

/**
 * Topbar breadcrumb trail — PRD §8.3 "Top Bar: Breadcrumb + user avatar +
 * notification bell". Last entry (or any entry without `routeName`) renders
 * as the current, non-clickable page.
 */
export type BreadcrumbEntry = {
    label: string;
    routeName?: string;
};

export type NavItem = {
    label: string;
    icon: LucideIcon;
    /** Ziggy route name once the module controller exists; undefined = not built yet. */
    routeName?: string;
    /** Restrict visibility to these roles; omit to show to everyone. */
    roles?: Role[];
};

export type NavGroup = {
    label: string;
    items: NavItem[];
};

// Sidebar structure mirrors PRD section 4 (System Modules) grouped by
// division. Modules without a `routeName` yet render disabled — their
// controllers/pages land in later phases per the roadmap (PRD 10.2).
// `roles` mirrors the RBAC matrix (PRD §7.1) — a group/item is hidden
// entirely (not just disabled) for roles with no access ("-") to every
// item in it. Read access ("R") is enough to see the item; only routes
// gate the finer Create/Update/Delete distinctions server-side.
const NAV_GROUPS: NavGroup[] = [
    {
        label: 'Utama',
        items: [
            { label: 'Dashboard', icon: LayoutDashboard, routeName: 'dashboard' },
        ],
    },
    {
        label: 'Presales',
        items: [
            {
                label: 'CRM / Pipeline',
                icon: Users,
                routeName: 'crm.leads.index',
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM'],
            },
            {
                label: 'Desain',
                icon: Palette,
                routeName: 'design.index',
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA'],
            },
            {
                label: 'Quotation',
                icon: FileText,
                routeName: 'quotations.index',
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'FINANCE'],
            },
        ],
    },
    {
        label: 'Eksekusi',
        items: [
            {
                label: 'Proyek',
                icon: FolderKanban,
                routeName: 'projects.index',
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF'],
            },
            {
                label: 'Task',
                icon: ListChecks,
                routeName: 'tasks.index',
                roles: ['CEO', 'PM', 'FIELD_STAFF'],
            },
            {
                label: 'Form Harian',
                icon: ClipboardCheck,
                routeName: 'daily-forms.index',
                roles: ['CEO', 'PM', 'FIELD_STAFF'],
            },
            {
                label: 'Lembur',
                icon: Clock,
                routeName: 'overtime.index',
                roles: ['CEO', 'PM', 'FINANCE', 'FIELD_STAFF'],
            },
            {
                label: 'QA',
                icon: ShieldCheck,
                routeName: 'qa-forms.index',
                roles: ['CEO', 'PM', 'QA'],
            },
        ],
    },
    {
        label: 'Operasional',
        items: [
            {
                label: 'Cash Flow',
                icon: Wallet,
                routeName: 'finance.dashboard',
                roles: ['CEO', 'PM', 'FINANCE'],
            },
            {
                label: 'Transaksi',
                icon: Wallet,
                routeName: 'finance.transactions.index',
                roles: ['CEO', 'PM', 'FINANCE'],
            },
            {
                label: 'Termin',
                icon: Wallet,
                routeName: 'finance.termins.index',
                roles: ['CEO', 'FINANCE'],
            },
            {
                label: 'Upah Tukang',
                icon: Wallet,
                routeName: 'finance.staffPayments.index',
                roles: ['CEO', 'PM', 'FINANCE'],
            },
            {
                label: 'Pinjaman Tukang',
                icon: HandCoins,
                routeName: 'finance.staffLoans.index',
                roles: ['CEO', 'PM', 'FINANCE'],
            },
            {
                label: 'Hutang Supplier',
                icon: Receipt,
                routeName: 'finance.supplierDebts.index',
                roles: ['CEO', 'PM', 'FINANCE'],
            },
            {
                label: 'Alokasi Persentase',
                icon: Percent,
                routeName: 'finance.allocations.index',
                roles: ['CEO', 'FINANCE'],
            },
            {
                label: 'Dana Family Gathering',
                icon: PiggyBank,
                routeName: 'family-fund.index',
                roles: ['CEO', 'FINANCE'],
            },
            {
                label: 'Penalti',
                icon: AlertOctagon,
                routeName: 'penalties.index',
                roles: ['CEO', 'PM', 'FINANCE', 'FIELD_STAFF'],
            },
        ],
    },
    {
        label: 'Logistik',
        items: [
            {
                label: 'Material',
                icon: Package,
                routeName: 'logistics.materials.index',
                roles: ['CEO', 'ESTIMATOR', 'PM', 'LOGISTICS'],
            },
            {
                label: 'Riwayat Stok',
                icon: History,
                routeName: 'logistics.stock-movements.index',
                roles: ['CEO', 'PM', 'LOGISTICS'],
            },
            {
                label: 'Aset Inventaris',
                icon: Warehouse,
                routeName: 'logistics.assets.index',
                roles: ['CEO', 'PM', 'FINANCE', 'LOGISTICS'],
            },
        ],
    },
    {
        label: 'Eksekutif',
        items: [
            {
                label: 'Analytics',
                icon: BarChart3,
                routeName: 'analytics.index',
                roles: ['CEO'],
            },
            {
                label: 'Audit Trail',
                icon: ScrollText,
                routeName: 'audit-logs.index',
                roles: ['CEO'],
            },
            {
                label: 'User Management',
                icon: UserCog,
                routeName: 'users.index',
                roles: ['CEO'],
            },
        ],
    },
    {
        label: 'Sistem',
        items: [
            {
                label: 'Data Master',
                icon: Database,
                routeName: 'master-data.index',
                roles: ['SUPERADMIN'],
            },
            {
                label: 'Pengaturan Situs',
                icon: Settings,
                routeName: 'settings.edit',
                roles: ['CEO', 'SUPERADMIN'],
            },
        ],
    },
];

/** Display names for the Spatie role codes (UI only — never compared against). */
export const ROLE_LABEL: Record<Role, string> = {
    CEO: 'CEO',
    MARKETING: 'Marketing',
    DESIGNER: 'Desainer',
    ESTIMATOR: 'Estimator',
    PM: 'Project Manager',
    QA: 'Quality Assurance',
    FINANCE: 'Finance',
    LOGISTICS: 'Logistik',
    FIELD_STAFF: 'Field Staff',
    SUPERADMIN: 'Super Admin',
};

/**
 * NAV_GROUPS filtered to what the current user's role may see — shared by
 * the sidebar, the topbar command menu and the Dashboard's module list so
 * all three always agree.
 */
export function useNavGroups(): NavGroup[] {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;

    return useMemo(
        () =>
            NAV_GROUPS.map((group) => ({
                ...group,
                // SUPERADMIN is god-mode (see database/seeders/RoleSeeder.php) —
                // sees every nav item regardless of its `roles` list.
                items: group.items.filter(
                    (item) =>
                        !item.roles ||
                        role === 'SUPERADMIN' ||
                        (role && item.roles.includes(role)),
                ),
            })).filter((group) => group.items.length > 0),
        [role],
    );
}

function initials(name: string) {
    return name
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function SidebarNav() {
    const groups = useNavGroups();

    return (
        <nav className="scrollbar-thin flex flex-1 flex-col gap-5 overflow-y-auto px-3 py-2">
            {groups.map((group) => (
                <div key={group.label}>
                    <p className="px-2.5 pb-1.5 text-[11px] font-semibold tracking-wider text-daiku-muted/80 uppercase">
                        {group.label}
                    </p>
                    <div className="flex flex-col gap-0.5">
                        {group.items.map((item) => {
                            const Icon = item.icon;
                            const isActive =
                                item.routeName &&
                                typeof route !== 'undefined' &&
                                route().current(item.routeName);

                            if (!item.routeName) {
                                return (
                                    <span
                                        key={item.label}
                                        className="flex h-8 cursor-not-allowed items-center justify-between rounded-lg px-2.5 text-[13px] text-daiku-muted/70"
                                        title="Segera hadir"
                                    >
                                        <span className="flex items-center gap-2.5">
                                            <Icon className="size-4" />
                                            {item.label}
                                        </span>
                                        <Badge
                                            variant="secondary"
                                            className="text-[10px] font-normal"
                                        >
                                            Segera
                                        </Badge>
                                    </span>
                                );
                            }

                            return (
                                <Link
                                    key={item.label}
                                    href={route(item.routeName)}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={cn(
                                        'group relative flex h-8 items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium transition-colors',
                                        isActive
                                            ? 'bg-background text-foreground shadow-xs ring-1 ring-border'
                                            : 'text-daiku-dark/75 hover:bg-background/70 hover:text-foreground',
                                    )}
                                >
                                    {isActive && (
                                        <span
                                            aria-hidden
                                            className="absolute top-1/2 -left-3 h-5 w-1 -translate-y-1/2 rounded-r-full bg-daiku-yellow"
                                        />
                                    )}
                                    <Icon
                                        className={cn(
                                            'size-4 shrink-0 transition-colors',
                                            isActive
                                                ? 'text-daiku-yellow-dark'
                                                : 'text-daiku-muted group-hover:text-foreground',
                                        )}
                                    />
                                    <span className="truncate">{item.label}</span>
                                </Link>
                            );
                        })}
                    </div>
                </div>
            ))}
        </nav>
    );
}

function SidebarBrand() {
    const { site } = usePage<PageProps>().props;

    return (
        <Link href={route('dashboard')} className="flex shrink-0 items-center gap-3 px-5 pt-5 pb-4">
            {site.logoUrl ? (
                <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-background p-1 shadow-xs ring-1 ring-border">
                    <BrandMark className="size-full" />
                </span>
            ) : (
                <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-daiku-yellow shadow-xs">
                    <BrandMark className="size-5" fallbackClassName="fill-daiku-dark" />
                </span>
            )}
            <span className="min-w-0 leading-tight">
                <span className="block truncate text-sm font-semibold tracking-tight text-daiku-dark">
                    {site.name}
                </span>
                <span className="block truncate text-[11px] text-daiku-muted">{site.tagline}</span>
            </span>
        </Link>
    );
}

/** Profile + logout entries, shared by the sidebar user card and the mobile topbar avatar. */
function UserMenuContent({ user, align }: { user: User; align: 'start' | 'end' }) {
    return (
        <DropdownMenuContent align={align} side={align === 'start' ? 'top' : 'bottom'} className="w-60">
            <DropdownMenuLabel>
                <p className="font-medium text-foreground">{user.name}</p>
                <p className="truncate text-xs font-normal text-muted-foreground">{user.email}</p>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link href={route('profile.edit')}>
                    <UserIcon className="size-4" />
                    Profil Saya
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem asChild variant="destructive">
                <Link href={route('logout')} method="post" as="button" className="w-full">
                    <LogOut className="size-4" />
                    Log Out
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    );
}

function UserAvatar({ user, className }: { user: User; className?: string }) {
    return (
        <Avatar className={cn('size-8', className)}>
            <AvatarFallback className="bg-daiku-yellow text-xs font-semibold text-daiku-dark">
                {initials(user.name)}
            </AvatarFallback>
        </Avatar>
    );
}

function SidebarUser() {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <div className="shrink-0 p-3">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className="flex w-full items-center gap-2.5 rounded-xl bg-background p-2 text-left shadow-xs ring-1 ring-border transition-colors hover:shadow-sm"
                    >
                        <UserAvatar user={user} />
                        <span className="min-w-0 flex-1 leading-tight">
                            <span className="block truncate text-[13px] font-semibold text-foreground">
                                {user.name}
                            </span>
                            <span className="block truncate text-[11px] text-muted-foreground">
                                {user.role ? ROLE_LABEL[user.role] : user.email}
                            </span>
                        </span>
                        <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                    </button>
                </DropdownMenuTrigger>
                <UserMenuContent user={user} align="start" />
            </DropdownMenu>
        </div>
    );
}

function SidebarContents() {
    return (
        <>
            <SidebarBrand />
            <SidebarNav />
            <SidebarUser />
        </>
    );
}

function TopbarBreadcrumb({ breadcrumbs }: { breadcrumbs: BreadcrumbEntry[] }) {
    return (
        <Breadcrumb className="min-w-0">
            <BreadcrumbList className="flex-nowrap">
                {breadcrumbs.map((item, index) => {
                    const isLast = index === breadcrumbs.length - 1;

                    return (
                        <Fragment key={item.label}>
                            <BreadcrumbItem className={cn(!isLast && 'hidden sm:inline-flex')}>
                                {isLast || !item.routeName ? (
                                    <BreadcrumbPage
                                        className={cn(
                                            'truncate',
                                            isLast ? 'font-semibold text-foreground' : 'text-muted-foreground',
                                        )}
                                    >
                                        {item.label}
                                    </BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink asChild>
                                        <Link href={route(item.routeName)}>{item.label}</Link>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && <BreadcrumbSeparator className="hidden sm:list-item" />}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}

function NotificationBell() {
    const { notifications, unreadNotificationsCount } = usePage<PageProps>().props;

    function openNotification(notification: AppNotification) {
        const href = notificationHref(notification);

        router.patch(
            route('notifications.markAsRead', { notification: notification.id }),
            {},
            { preserveScroll: true, onSuccess: () => href && router.visit(href) },
        );
    }

    function markAllAsRead() {
        router.patch(route('notifications.markAllAsRead'), {}, { preserveScroll: true });
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="icon" className="relative" aria-label="Notifikasi">
                    <Bell className="size-4" />
                    {/* Live via useRealtimeNotifications when Soketi is on,
                        otherwise refreshed on every Inertia visit. */}
                    {unreadNotificationsCount > 0 && (
                        <span className="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-error px-1 text-[10px] font-semibold text-background ring-2 ring-background">
                            {unreadNotificationsCount > 99 ? '99+' : unreadNotificationsCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-88 p-0">
                <div className="flex items-center justify-between gap-2 border-b border-border px-3 py-2.5">
                    <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        Notifikasi
                        {unreadNotificationsCount > 0 && (
                            <span className="rounded-md bg-daiku-yellow-light px-1.5 py-0.5 text-[11px] font-medium text-daiku-yellow-dark tabular-nums">
                                {unreadNotificationsCount} baru
                            </span>
                        )}
                    </p>
                    {unreadNotificationsCount > 0 && (
                        <button
                            type="button"
                            onClick={markAllAsRead}
                            className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                        >
                            <CheckCheck className="size-3.5" />
                            Tandai semua dibaca
                        </button>
                    )}
                </div>
                {notifications.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-3 py-8 text-center">
                        <span className="flex size-9 items-center justify-center rounded-full bg-daiku-gray text-daiku-muted">
                            <BellOff className="size-4" />
                        </span>
                        <p className="text-sm text-muted-foreground">Belum ada notifikasi.</p>
                    </div>
                ) : (
                    <div className="scrollbar-thin flex max-h-96 flex-col overflow-y-auto p-1">
                        {notifications.map((notification) => (
                            <button
                                key={notification.id}
                                type="button"
                                onClick={() => openNotification(notification)}
                                className="flex gap-3 rounded-md p-2.5 text-left text-sm transition-colors hover:bg-daiku-yellow-light/70"
                            >
                                <span className="mt-1.5 size-2 shrink-0 rounded-full bg-daiku-yellow" />
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium text-foreground">{notification.title}</span>
                                    <span className="line-clamp-2 block text-xs text-muted-foreground">
                                        {notification.message}
                                    </span>
                                    <span className="mt-1 block text-[11px] text-muted-foreground/80">
                                        {formatRelative(notification.created_at)}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                )}
                <div className="border-t border-border p-1">
                    <DropdownMenuItem asChild>
                        <Link href={route('notifications.index')} className="justify-center text-sm font-medium">
                            Lihat semua notifikasi
                        </Link>
                    </DropdownMenuItem>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function Topbar({
    breadcrumbs,
    header,
}: {
    breadcrumbs?: BreadcrumbEntry[];
    header?: ReactNode;
}) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const groups = useNavGroups();
    const commandGroups = useMemo(
        () =>
            groups
                .map((group) => ({
                    label: group.label,
                    items: group.items.flatMap((item) =>
                        item.routeName ? [{ label: item.label, icon: item.icon, routeName: item.routeName }] : [],
                    ),
                }))
                .filter((group) => group.items.length > 0),
        [groups],
    );

    useRealtimeNotifications(user?.id);

    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center justify-between gap-3 border-b border-border bg-background/85 px-4 backdrop-blur-md sm:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <Sheet>
                    <SheetTrigger asChild>
                        <Button variant="ghost" size="icon" className="-ml-1.5 lg:hidden" aria-label="Buka navigasi">
                            <Menu className="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="left" className="w-72 gap-0 bg-daiku-gray p-0">
                        <SheetTitle className="sr-only">Navigasi</SheetTitle>
                        <SidebarContents />
                    </SheetContent>
                </Sheet>
                {breadcrumbs && breadcrumbs.length > 0 ? (
                    <TopbarBreadcrumb breadcrumbs={breadcrumbs} />
                ) : (
                    <div className="truncate text-sm text-muted-foreground">{header}</div>
                )}
            </div>

            <div className="flex shrink-0 items-center gap-2">
                <CommandMenu groups={commandGroups} className="w-8 justify-center px-0 md:w-56 md:justify-start md:px-2.5" />
                <NotificationBell />

                {user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button type="button" className="rounded-full lg:hidden" aria-label="Menu akun">
                                <UserAvatar user={user} />
                            </button>
                        </DropdownMenuTrigger>
                        <UserMenuContent user={user} align="end" />
                    </DropdownMenu>
                )}
            </div>
        </header>
    );
}

export default function AppLayout({
    breadcrumbs,
    header,
    children,
}: PropsWithChildren<{ breadcrumbs?: BreadcrumbEntry[]; header?: ReactNode }>) {
    useFlashToasts();

    return (
        <div className="flex h-screen overflow-hidden bg-daiku-gray">
            <Toaster position="top-right" richColors closeButton />
            <aside className="hidden h-full w-64 shrink-0 flex-col lg:flex">
                <SidebarContents />
            </aside>

            <div className="flex h-full min-w-0 flex-1 flex-col lg:py-2 lg:pr-2">
                <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-background lg:rounded-2xl lg:shadow-sm lg:ring-1 lg:ring-border">
                    <main className="scrollbar-thin relative flex-1 overflow-y-auto">
                        <Topbar breadcrumbs={breadcrumbs} header={header} />
                        <div className="mx-auto w-full max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </div>
    );
}
