import ApplicationLogo from '@/Components/ApplicationLogo';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Badge } from '@/Components/ui/badge';
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
import type { PageProps, Role } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    Clock,
    FileText,
    FolderKanban,
    LayoutDashboard,
    ListChecks,
    LogOut,
    type LucideIcon,
    Menu,
    Package,
    Palette,
    ShieldCheck,
    User as UserIcon,
    Users,
    UserCog,
    Wallet,
} from 'lucide-react';
import { PropsWithChildren, ReactNode } from 'react';

type NavItem = {
    label: string;
    icon: LucideIcon;
    /** Ziggy route name once the module controller exists; undefined = not built yet. */
    routeName?: string;
    /** Restrict visibility to these roles; omit to show to everyone. */
    roles?: Role[];
};

type NavGroup = {
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
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA'],
            },
            {
                label: 'Quotation',
                icon: FileText,
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
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF'],
            },
            {
                label: 'Task',
                icon: ListChecks,
                roles: ['CEO', 'PM', 'FIELD_STAFF'],
            },
            {
                label: 'Lembur',
                icon: Clock,
                roles: ['CEO', 'PM', 'FINANCE', 'FIELD_STAFF'],
            },
            {
                label: 'QA',
                icon: ShieldCheck,
                roles: ['CEO', 'PM', 'QA'],
            },
        ],
    },
    {
        label: 'Operasional',
        items: [
            {
                label: 'Finance',
                icon: Wallet,
                roles: ['CEO', 'PM', 'FINANCE'],
            },
            {
                label: 'Logistik',
                icon: Package,
                roles: ['CEO', 'ESTIMATOR', 'PM', 'LOGISTICS'],
            },
        ],
    },
    {
        label: 'Eksekutif',
        items: [
            {
                label: 'Analytics',
                icon: BarChart3,
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
];

function initials(name: string) {
    return name
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function SidebarNav() {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;

    return (
        <nav className="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-4">
            {NAV_GROUPS.map((group) => {
                const items = group.items.filter(
                    (item) => !item.roles || (role && item.roles.includes(role)),
                );

                if (items.length === 0) {
                    return null;
                }

                return (
                    <div key={group.label}>
                        <p className="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-daiku-muted">
                            {group.label}
                        </p>
                        <div className="flex flex-col gap-1">
                            {items.map((item) => {
                                const Icon = item.icon;
                                const isActive =
                                    item.routeName &&
                                    typeof route !== 'undefined' &&
                                    route().current(item.routeName);

                                if (!item.routeName) {
                                    return (
                                        <span
                                            key={item.label}
                                            className="flex cursor-not-allowed items-center justify-between rounded-lg px-3 py-2 text-sm text-daiku-muted/70"
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
                                        className={`flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                                            isActive
                                                ? 'bg-daiku-yellow text-daiku-dark'
                                                : 'text-daiku-dark hover:bg-daiku-yellow-light'
                                        }`}
                                    >
                                        <Icon className="size-4" />
                                        {item.label}
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                );
            })}
        </nav>
    );
}

function SidebarBrand() {
    return (
        <Link href={route('dashboard')} className="flex items-center gap-2.5 px-4 py-5">
            <span className="flex size-8 items-center justify-center rounded-md bg-daiku-yellow">
                <ApplicationLogo className="size-5 fill-daiku-dark" />
            </span>
            <span className="text-sm font-semibold tracking-tight text-daiku-dark">
                Daiku Interior
            </span>
        </Link>
    );
}

function Topbar({ header }: { header?: ReactNode }) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    return (
        <header className="flex h-16 shrink-0 items-center justify-between border-b border-daiku-border bg-white px-4 sm:px-6">
            <div className="flex items-center gap-3">
                <Sheet>
                    <SheetTrigger asChild>
                        <Button variant="ghost" size="icon" className="lg:hidden">
                            <Menu className="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="left" className="w-64 bg-white p-0">
                        <SheetTitle className="sr-only">Navigasi</SheetTitle>
                        <SidebarBrand />
                        <SidebarNav />
                    </SheetContent>
                </Sheet>
                <div className="text-sm text-daiku-muted">{header}</div>
            </div>

            <div className="flex items-center gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="relative">
                            <Bell className="size-5" />
                            {/* Live badge count wires up once notifications (PRD 4.9)
                                are broadcast over Echo/Soketi — see resources/js/lib/echo.ts */}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-72">
                        <DropdownMenuLabel>Notifikasi</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <p className="px-2 py-4 text-center text-sm text-daiku-muted">
                            Belum ada notifikasi.
                        </p>
                    </DropdownMenuContent>
                </DropdownMenu>

                {user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                className="flex items-center gap-2 px-2"
                            >
                                <Avatar className="size-7">
                                    <AvatarFallback className="bg-daiku-yellow text-xs font-semibold text-daiku-dark">
                                        {initials(user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <span className="hidden text-sm font-medium sm:inline">
                                    {user.name}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>
                                <p className="font-medium">{user.name}</p>
                                <p className="text-xs font-normal text-daiku-muted">
                                    {user.email}
                                </p>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild>
                                <Link href={route('profile.edit')}>
                                    <UserIcon className="size-4" />
                                    Profil Saya
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild variant="destructive">
                                <Link href={route('logout')} method="post" as="button">
                                    <LogOut className="size-4" />
                                    Log Out
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </header>
    );
}

export default function AppLayout({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    return (
        <div className="flex min-h-screen bg-daiku-gray">
            <aside className="hidden w-64 shrink-0 flex-col border-r border-daiku-border bg-white lg:flex">
                <SidebarBrand />
                <SidebarNav />
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <Topbar header={header} />
                <main className="flex-1 p-4 sm:p-6">{children}</main>
            </div>
        </div>
    );
}
