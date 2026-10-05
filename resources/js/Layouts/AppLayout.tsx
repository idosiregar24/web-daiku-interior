import { BrandLogoTile, BrandMark } from '@/Components/shared/BrandMark';
import { CommandMenu } from '@/Components/shared/CommandMenu';
import { ProjectOpeningPrompt } from '@/Components/modules/projects/ProjectOpeningPrompt';
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
import { openNotification } from '@/lib/notificationHref';
import { cn } from '@/lib/utils';
import type { PageProps, Role, User } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertOctagon,
    BadgeDollarSign,
    BarChart3,
    Bell,
    BellOff,
    CalendarClock,
    Check,
    CheckCheck,
    ChevronDown,
    ChevronsUpDown,
    Clock,
    ClipboardCheck,
    ClipboardList,
    FileCheck2,
    FileText,
    FolderKanban,
    Gavel,
    HandCoins,
    History,
    House,
    IdCard,
    LayoutDashboard,
    ListChecks,
    LogOut,
    type LucideIcon,
    Menu,
    Network,
    Package,
    Palette,
    Percent,
    PiggyBank,
    Receipt,
    ReceiptText,
    ScrollText,
    ShieldCheck,
    Target,
    User as UserIcon,
    UserRound,
    Users,
    UserCog,
    Database,
    Settings,
    Store,
    Wallet,
    WalletCards,
    Warehouse,
} from 'lucide-react';
import { Fragment, PropsWithChildren, ReactNode, useEffect, useMemo, useRef } from 'react';

/**
 * Topbar breadcrumb — PRD §8.3 "Top Bar: Breadcrumb + user avatar +
 * notification bell". The start of the trail is derived automatically
 * from the sidebar menu the current route belongs to (🏠 › group › menu);
 * a page only passes what comes AFTER its menu — a record name, a
 * "Tambah …" form, the active tab. The last entry is the current page.
 */
export type BreadcrumbEntry = {
    label: string;
    /** Link target: a parameterless route name… */
    routeName?: string;
    /** …or a ready URL, for routes with parameters (`route('projects.show', id)`). */
    href?: string;
};

export type NavItem = {
    label: string;
    icon: LucideIcon;
    /** Ziggy route name once the module controller exists; undefined = not built yet. */
    routeName?: string;
    /**
     * Ziggy pattern of the routes that belong to this menu (highlights it in
     * the sidebar and puts it in the breadcrumb). Defaults to the routeName
     * with `.index`/`.edit` widened to `.*` — so detail/create pages count.
     */
    match?: string;
    /** Restrict visibility to these roles; omit to show to everyone. */
    roles?: Role[];
    /** SDM: show only when the user is linked to an active employee row (`auth.user.has_employee`). */
    requiresEmployee?: boolean;
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
            // SDM "Milik Saya" — only for accounts linked to an employee row (decision #6/#11).
            { label: 'Kinerja Saya', icon: UserRound, routeName: 'my.index', match: 'my.*', requiresEmployee: true },
        ],
    },
    {
        label: 'Presales',
        items: [
            {
                label: 'CRM / Pipeline',
                icon: Users,
                routeName: 'crm.leads.index',
                // leads + the pipeline statistics page (crm.dashboard)
                match: 'crm.*',
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
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'FINANCE'],
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
                roles: ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF'],
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
                // Sprint 12 #20 — Marketing issues every invoice.
                label: 'Invoice',
                icon: ReceiptText,
                routeName: 'finance.invoices.index',
                match: 'finance.invoices.index',
                roles: ['CEO', 'MARKETING', 'FINANCE'],
            },
            {
                // Sprint 12 #21 — Finance's queue of payments to verify.
                label: 'Verifikasi Pembayaran',
                icon: FileCheck2,
                routeName: 'finance.invoices.verification',
                match: 'finance.invoices.verification',
                roles: ['CEO', 'FINANCE'],
            },
            {
                label: 'Upah Tukang',
                icon: Wallet,
                routeName: 'finance.staffPayments.index',
                roles: ['CEO', 'PM', 'FINANCE'],
            },
            {
                label: 'Penggajian',
                icon: WalletCards,
                routeName: 'finance.payroll.index',
                // Salaries are confidential — not PM (sprint-09 decision #7).
                roles: ['CEO', 'FINANCE'],
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
                label: 'Cicilan Aset',
                icon: CalendarClock,
                routeName: 'finance.assetInstallments.index',
                roles: ['CEO', 'PM', 'FINANCE', 'LOGISTICS'],
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
                // Sprint 11 Sub 4 — out-of-catalog requests; each role sees its own slice.
                label: 'Pengajuan Barang',
                icon: ClipboardList,
                routeName: 'logistics.material-requests.index',
                roles: ['CEO', 'PM', 'LOGISTICS', 'ESTIMATOR', 'FIELD_STAFF'],
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
        // SDM / HR — Sprint 10, outside the PRD (route group `module:hr`).
        label: 'SDM',
        items: [
            {
                label: 'Dashboard SDM',
                icon: LayoutDashboard,
                routeName: 'hr.dashboard',
                roles: ['CEO', 'HR'],
            },
            {
                label: 'Karyawan',
                icon: IdCard,
                routeName: 'hr.employees.index',
                roles: ['CEO', 'HR'],
            },
            {
                label: 'Divisi & Jabatan',
                icon: Network,
                routeName: 'hr.structure.index',
                roles: ['CEO', 'HR'],
            },
            { label: 'Kedisiplinan', icon: Gavel, routeName: 'hr.discipline.index', roles: ['CEO', 'HR'] },
            { label: 'Gaji', icon: BadgeDollarSign, routeName: 'hr.salary.index', match: 'hr.salary*', roles: ['CEO', 'HR'] },
            // Covers the template editor too (hr.kpi.templates.index).
            { label: 'KPI', icon: Target, routeName: 'hr.kpi.index', roles: ['CEO', 'HR'] },
            { label: 'Evaluasi', icon: ClipboardList, routeName: 'hr.reviews.index', roles: ['CEO', 'HR'] },
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
                // Vendor below lives under master-data.vendors.* — keep it from lighting this item.
                match: 'master-data.index',
                roles: ['SUPERADMIN'],
            },
            {
                label: 'Vendor',
                icon: Store,
                routeName: 'master-data.vendors.index',
                match: 'master-data.vendors.*',
                roles: ['CEO', 'SUPERADMIN'],
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
    DESIGNER: 'Arsitek',
    ESTIMATOR: 'Estimator',
    PM: 'Project Manager',
    QA: 'Quality Assurance',
    FINANCE: 'Finance',
    LOGISTICS: 'Logistik',
    FIELD_STAFF: 'Field Staff',
    SUPERADMIN: 'Super Admin',
    HR: 'SDM',
    ASISTEN_PM: 'Asisten PM',
    KEPALA_DESAIN: 'Kepala Desain',
};

/**
 * NAV_GROUPS filtered to what the current user's role may see — shared by
 * the sidebar, the topbar command menu and the Dashboard's module list so
 * all three always agree.
 */
export function useNavGroups(): NavGroup[] {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;
    // Every role held — a stacked role (Kepala Desain) sees its base role's menus too.
    const roles = auth.user?.roles ?? (role ? [role] : []);
    const rolesKey = roles.join(',');
    const hasEmployee = Boolean(auth.user?.has_employee);

    return useMemo(
        () =>
            NAV_GROUPS.map((group) => ({
                ...group,
                // SUPERADMIN is god-mode (see database/seeders/RoleSeeder.php) —
                // sees every nav item regardless of its `roles` list.
                items: group.items.filter(
                    (item) =>
                        (!item.requiresEmployee || hasEmployee) &&
                        (!item.roles || roles.includes('SUPERADMIN') || roles.some((held) => item.roles?.includes(held))),
                ),
            })).filter((group) => group.items.length > 0),
        [rolesKey, hasEmployee],
    );
}

/** Ziggy pattern of the routes a menu covers (see NavItem.match). */
function navPattern(item: NavItem): string | null {
    if (!item.routeName) return null;
    if (item.match) return item.match;

    return /\.(index|edit)$/.test(item.routeName) ? item.routeName.replace(/\.[^.]+$/, '.*') : item.routeName;
}

function isCurrentNav(item: NavItem): boolean {
    const pattern = navPattern(item);

    return Boolean(pattern && typeof route !== 'undefined' && route().current(pattern));
}

/**
 * The sidebar menu (and its group) the current page belongs to. Role-visible
 * menus first; a page reachable without its menu being visible (e.g. a
 * detail page opened from a notification) still gets a trail, just
 * without the menu link (`visible: false`).
 */
export function useActiveNav(): { group: NavGroup; item: NavItem; visible: boolean } | null {
    const groups = useNavGroups();
    const { url } = usePage();

    return useMemo(() => {
        for (const [list, visible] of [
            [groups, true],
            [NAV_GROUPS, false],
        ] as const) {
            for (const group of list) {
                const item = group.items.find(isCurrentNav);
                if (item) return { group, item, visible };
            }
        }

        return null;
        // `url` re-runs the match after every visit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [groups, url]);
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
    const navRef = useRef<HTMLElement>(null);

    // Menus low in the list (Logistik, Sistem…) would otherwise sit below
    // the fold — bring the active one into view.
    useEffect(() => {
        navRef.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, []);

    return (
        <nav ref={navRef} className="scrollbar-thin flex flex-1 flex-col gap-5 overflow-y-auto px-3 py-2">
            {groups.map((group) => (
                <div key={group.label}>
                    <p className="px-2.5 pb-1.5 text-[11px] font-semibold tracking-wider text-daiku-muted/80 uppercase">
                        {group.label}
                    </p>
                    <div className="flex flex-col gap-0.5">
                        {group.items.map((item) => {
                            const Icon = item.icon;
                            // Detail/create pages keep their parent menu highlighted.
                            const isActive = isCurrentNav(item);

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
                <BrandLogoTile className="h-9 max-w-32" />
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
                    Keluar
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
                                {(user.display_role ?? user.role) ? ROLE_LABEL[(user.display_role ?? user.role) as Role] : user.email}
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

type Crumb =
    | { kind: 'home' }
    | { kind: 'group'; group: NavGroup; current: NavItem }
    | { kind: 'page'; label: string; href?: string; icon?: LucideIcon };

/** Switch to a sibling menu of the same group straight from the breadcrumb. */
function GroupCrumb({ group, current }: { group: NavGroup; current: NavItem }) {
    const siblings = group.items.filter((item) => item.routeName);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className="-mx-1 flex items-center gap-1 rounded-md px-1 py-0.5 text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 data-[state=open]:bg-muted data-[state=open]:text-foreground"
                aria-label={`Menu lain di ${group.label}`}
            >
                {group.label}
                <ChevronDown className="size-3.5" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                    {group.label}
                </DropdownMenuLabel>
                {siblings.map((item) => {
                    const Icon = item.icon;
                    const active = item === current;

                    return (
                        <DropdownMenuItem key={item.label} asChild>
                            <Link
                                href={route(item.routeName!)}
                                aria-current={active ? 'page' : undefined}
                                className={cn(active && 'bg-daiku-yellow-light/60 font-medium')}
                            >
                                <Icon className={cn('size-4', active ? 'text-daiku-yellow-dark' : 'text-muted-foreground')} />
                                <span className="flex-1">{item.label}</span>
                                {active && <Check className="size-3.5 text-daiku-yellow-dark" />}
                            </Link>
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * 🏠 › group ▾ › menu › …page crumbs. The group/menu part comes from the
 * sidebar entry this route belongs to (useActiveNav), so every page gets
 * the same, complete trail; pages add only their own detail levels.
 * Phones show just the last two levels.
 */
function TopbarBreadcrumb({ extra, header }: { extra: BreadcrumbEntry[]; header?: ReactNode }) {
    const active = useActiveNav();
    const groups = useNavGroups();

    const crumbs: Crumb[] = [];
    const isDashboard = active?.item.routeName === 'dashboard' && extra.length === 0;

    if (!isDashboard) {
        crumbs.push({ kind: 'home' });
    }

    if (active) {
        const visibleGroup = groups.find((group) => group.label === active.group.label);
        if (active.group.label !== 'Utama' && visibleGroup) {
            crumbs.push({ kind: 'group', group: visibleGroup, current: active.item });
        }
        crumbs.push({
            kind: 'page',
            label: active.item.label,
            icon: active.item.icon,
            // A link only when there's a deeper level to come back from.
            href: extra.length > 0 && active.visible ? route(active.item.routeName!) : undefined,
        });
    }

    for (const entry of extra) {
        crumbs.push({
            kind: 'page',
            label: entry.label,
            href: entry.href ?? (entry.routeName ? route(entry.routeName) : undefined),
        });
    }

    if (crumbs.length <= 1 && header) {
        return <div className="truncate text-sm text-muted-foreground">{header}</div>;
    }

    return (
        <Breadcrumb className="min-w-0">
            <BreadcrumbList className="flex-nowrap gap-1.5 sm:gap-2">
                {crumbs.map((crumb, index) => {
                    const isLast = index === crumbs.length - 1;
                    // Phones: only the parent + current page.
                    const mobileHidden = index < crumbs.length - 2;
                    const key = crumb.kind === 'page' ? `${crumb.label}-${index}` : `${crumb.kind}-${index}`;

                    return (
                        <Fragment key={key}>
                            <BreadcrumbItem className={cn('min-w-0', mobileHidden && 'hidden sm:inline-flex')}>
                                {crumb.kind === 'home' && (
                                    <BreadcrumbLink asChild>
                                        <Link href={route('dashboard')} aria-label="Dashboard" className="flex items-center">
                                            <House className="size-4" />
                                        </Link>
                                    </BreadcrumbLink>
                                )}
                                {crumb.kind === 'group' && <GroupCrumb group={crumb.group} current={crumb.current} />}
                                {crumb.kind === 'page' &&
                                    (isLast ? (
                                        <BreadcrumbPage className="flex min-w-0 items-center gap-1.5 font-semibold text-foreground">
                                            {crumb.icon && <crumb.icon className="size-4 shrink-0 text-daiku-yellow-dark" />}
                                            <span className="truncate">{crumb.label}</span>
                                        </BreadcrumbPage>
                                    ) : crumb.href ? (
                                        <BreadcrumbLink asChild>
                                            <Link href={crumb.href} className="flex min-w-0 items-center gap-1.5">
                                                {crumb.icon && <crumb.icon className="size-4 shrink-0" />}
                                                <span className="max-w-48 truncate">{crumb.label}</span>
                                            </Link>
                                        </BreadcrumbLink>
                                    ) : (
                                        <span className="flex min-w-0 items-center gap-1.5 text-muted-foreground">
                                            {crumb.icon && <crumb.icon className="size-4 shrink-0" />}
                                            <span className="max-w-48 truncate">{crumb.label}</span>
                                        </span>
                                    ))}
                            </BreadcrumbItem>
                            {!isLast && <BreadcrumbSeparator className={cn(mobileHidden && 'hidden sm:list-item')} />}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}

function NotificationBell() {
    const { notifications, unreadNotificationsCount } = usePage<PageProps>().props;

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
                <TopbarBreadcrumb extra={breadcrumbs ?? []} header={header} />
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
            {/* Sprint 12 #19 — CEO only; renders nothing when no RAB Proyek waits. */}
            <ProjectOpeningPrompt />
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
