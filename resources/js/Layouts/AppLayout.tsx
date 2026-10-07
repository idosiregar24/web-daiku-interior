import { BottomNav } from '@/Components/shared/BottomNav';
import { BrandLogoTile, BrandMark } from '@/Components/shared/BrandMark';
import { CommandMenu } from '@/Components/shared/CommandMenu';
import { PushOptIn } from '@/Components/shared/PushOptIn';
import { type QuickCreateItem, QuickCreateMenu } from '@/Components/shared/QuickCreateMenu';
import { StatusChip } from '@/Components/shared/StatusChip';
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
import { rememberMenu } from '@/lib/recentMenus';
import { cn } from '@/lib/utils';
import type { PageProps, Role, User } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertOctagon,
    ArrowLeftRight,
    BadgeDollarSign,
    Banknote,
    BarChart3,
    Bell,
    BellRing,
    BellOff,
    CalendarCheck,
    CalendarClock,
    CalendarRange,
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
    Globe,
    HandCoins,
    History,
    House,
    IdCard,
    Inbox,
    Landmark,
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
    SlidersHorizontal,
    Target,
    TrendingUp,
    User as UserIcon,
    UserRound,
    Users,
    UserCog,
    Database,
    Settings,
    Settings2,
    Store,
    Wallet,
    WalletCards,
    Warehouse,
} from 'lucide-react';
import { Fragment, lazy, PropsWithChildren, ReactNode, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';

// Sprint 12 #19 "Buka Proyek" pop-up — CEO only, and only while an approved
// RAB Proyek waits. Loaded on demand (Sprint 13 H8): its form, date picker
// and selects would otherwise ride along on every page, the Tukang's too.
const ProjectOpeningPrompt = lazy(() =>
    import('@/Components/modules/projects/ProjectOpeningPrompt').then((module) => ({ default: module.ProjectOpeningPrompt })),
);

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

/** One page of a hub menu (Sprint 13 #1) — rendered by `ModuleTabs`. */
export type NavTab = {
    label: string;
    icon: LucideIcon;
    routeName: string;
    /** Ziggy pattern of the routes of this tab — same default as NavItem.match. */
    match?: string;
    /** Restrict this tab to these roles; omit to show it to everyone who sees the hub. */
    roles?: Role[];
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
    /** The menu's name for a given primary role — Sprint 13 H9: a Tukang reads "Tugas", not "Task". */
    roleLabels?: Partial<Record<Role, string>>;
    /**
     * Hub (Sprint 13 #1): sibling pages shown as tabs under the page header
     * (`<ModuleTabs />`). A hub has no routeName of its own — useNavGroups
     * points it at the first tab the role may open, and a role left with a
     * single tab gets an ordinary menu named after that tab (no tab bar).
     */
    tabs?: NavTab[];
};

export type NavGroup = {
    label: string;
    items: NavItem[];
    /** Pinned to the bottom of the sidebar, without a header (⚙ Pengaturan, Sprint 13 #2). */
    pinned?: boolean;
};

// Sidebar structure mirrors PRD section 4 (System Modules) grouped by
// division. Modules without a `routeName` yet render disabled — their
// controllers/pages land in later phases per the roadmap (PRD 10.2).
// `roles` mirrors the RBAC matrix (PRD §7.1) — a group/item is hidden
// entirely (not just disabled) for roles with no access ("-") to every
// item in it. Read access ("R") is enough to see the item; only routes
// gate the finer Create/Update/Delete distinctions server-side.
// Sprint 13: related pages are folded into hubs (`tabs`) and every setup
// page sits in the pinned ⚙ Pengaturan hub — routes and gates unchanged.
// Collapsible group labels are whitelisted server-side
// (UpdateNavPreferenceRequest::GROUPS) — keep both lists in sync.
const NAV_GROUPS: NavGroup[] = [
    {
        label: 'Utama',
        items: [
            { label: 'Dashboard', icon: LayoutDashboard, routeName: 'dashboard' },
            // Sprint 13 H2 — the Tukang's first screen (also the bottom bar's first button).
            { label: 'Hari Ini', icon: CalendarCheck, routeName: 'today.index', roles: ['FIELD_STAFF'] },
            // Sprint 13 #4 — every role; its badge is the total of every queue.
            { label: 'Perlu Tindakan', icon: Inbox, routeName: 'inbox.index' },
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
                roleLabels: { FIELD_STAFF: 'Tugas' },
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
        label: 'Keuangan',
        items: [
            {
                label: 'Cash Flow',
                icon: Wallet,
                tabs: [
                    { label: 'Ringkasan', icon: TrendingUp, routeName: 'finance.dashboard', roles: ['CEO', 'PM', 'FINANCE'] },
                    { label: 'Transaksi', icon: ArrowLeftRight, routeName: 'finance.transactions.index', roles: ['CEO', 'PM', 'FINANCE'] },
                ],
            },
            {
                label: 'Penagihan',
                icon: ReceiptText,
                tabs: [
                    { label: 'Termin', icon: CalendarClock, routeName: 'finance.termins.index', roles: ['CEO', 'FINANCE'] },
                    // Sprint 12 #20 — Marketing issues every invoice.
                    {
                        label: 'Invoice',
                        icon: ReceiptText,
                        routeName: 'finance.invoices.index',
                        match: 'finance.invoices.index',
                        roles: ['CEO', 'MARKETING', 'FINANCE'],
                    },
                    // Sprint 12 #21 — Finance's queue of payments to verify.
                    {
                        label: 'Verifikasi Pembayaran',
                        icon: FileCheck2,
                        routeName: 'finance.invoices.verification',
                        match: 'finance.invoices.verification',
                        roles: ['CEO', 'FINANCE'],
                    },
                ],
            },
            {
                label: 'Pembayaran Staf',
                icon: WalletCards,
                tabs: [
                    { label: 'Upah Tukang', icon: Banknote, routeName: 'finance.staffPayments.index', roles: ['CEO', 'PM', 'FINANCE'] },
                    // Salaries are confidential — not PM (sprint-09 decision #7).
                    { label: 'Penggajian', icon: WalletCards, routeName: 'finance.payroll.index', roles: ['CEO', 'FINANCE'] },
                    { label: 'Pinjaman Tukang', icon: HandCoins, routeName: 'finance.staffLoans.index', roles: ['CEO', 'PM', 'FINANCE'] },
                    { label: 'Penalti', icon: AlertOctagon, routeName: 'penalties.index', roles: ['CEO', 'PM', 'FINANCE', 'FIELD_STAFF'] },
                ],
            },
            {
                label: 'Kewajiban',
                icon: Landmark,
                tabs: [
                    { label: 'Hutang Supplier', icon: Receipt, routeName: 'finance.supplierDebts.index', roles: ['CEO', 'PM', 'FINANCE'] },
                    {
                        label: 'Cicilan Aset',
                        icon: CalendarRange,
                        routeName: 'finance.assetInstallments.index',
                        roles: ['CEO', 'PM', 'FINANCE', 'LOGISTICS'],
                    },
                ],
            },
            {
                label: 'Dana Family Gathering',
                icon: PiggyBank,
                routeName: 'family-fund.index',
                roles: ['CEO', 'FINANCE'],
            },
        ],
    },
    {
        label: 'Logistik',
        items: [
            {
                label: 'Material',
                icon: Package,
                tabs: [
                    { label: 'Katalog', icon: Package, routeName: 'logistics.materials.index', roles: ['CEO', 'ESTIMATOR', 'PM', 'LOGISTICS'] },
                    { label: 'Riwayat Stok', icon: History, routeName: 'logistics.stock-movements.index', roles: ['CEO', 'PM', 'LOGISTICS'] },
                ],
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
            { label: 'Gaji', icon: BadgeDollarSign, routeName: 'hr.salary.index', match: 'hr.salary*', roles: ['CEO', 'HR'] },
            {
                label: 'Kinerja',
                icon: Target,
                tabs: [
                    // The template editor (hr.kpi.templates.*) lives in ⚙ Pengaturan.
                    { label: 'KPI', icon: Target, routeName: 'hr.kpi.index', match: 'hr.kpi.index', roles: ['CEO', 'HR'] },
                    { label: 'Evaluasi', icon: ClipboardList, routeName: 'hr.reviews.index', roles: ['CEO', 'HR'] },
                    { label: 'Kedisiplinan', icon: Gavel, routeName: 'hr.discipline.index', roles: ['CEO', 'HR'] },
                ],
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
        ],
    },
    {
        // Sprint 13 #2 — every setup page in one hub, apart from daily work.
        label: 'Pengaturan',
        pinned: true,
        items: [
            {
                label: 'Pengaturan',
                icon: Settings,
                tabs: [
                    { label: 'Pengguna', icon: UserCog, routeName: 'users.index', roles: ['CEO'] },
                    {
                        label: 'Data Master',
                        icon: Database,
                        routeName: 'master-data.index',
                        // Vendor below lives under master-data.vendors.* — keep it from lighting this tab.
                        match: 'master-data.index',
                        roles: ['SUPERADMIN'],
                    },
                    { label: 'Vendor', icon: Store, routeName: 'master-data.vendors.index', roles: ['CEO', 'SUPERADMIN'] },
                    { label: 'Alokasi Persentase', icon: Percent, routeName: 'finance.allocations.index', roles: ['CEO', 'FINANCE'] },
                    { label: 'Divisi & Jabatan', icon: Network, routeName: 'hr.structure.index', roles: ['CEO', 'HR'] },
                    {
                        label: 'Template KPI',
                        icon: SlidersHorizontal,
                        routeName: 'hr.kpi.templates.index',
                        match: 'hr.kpi.templates.*',
                        roles: ['CEO', 'HR'],
                    },
                    { label: 'Situs', icon: Globe, routeName: 'settings.edit', roles: ['CEO', 'SUPERADMIN'] },
                ],
            },
        ],
    },
];

/**
 * Sprint 13 #10 — one menu structure, but each role meets its own work
 * first. Groups listed here come first in this order; the rest follow in
 * NAV_GROUPS order. Keyed by the primary role (a stacked role follows its
 * base role — `auth.user.role` is never a stacked one).
 */
/**
 * Sprint 13 #6 — the topbar "+ Buat" list: one entry per add action, each
 * opening its page with `?create=1` (the page opens its own add dialog —
 * useCreateParam). `roles` mirror who the page offers that button to;
 * SUPERADMIN only where the page does too. Hidden when nothing is left.
 */
const QUICK_CREATE: (QuickCreateItem & { roles: Role[] })[] = [
    { label: 'Lead baru', icon: Users, routeName: 'crm.leads.index', roles: ['CEO', 'MARKETING', 'SUPERADMIN'] },
    { label: 'Transaksi', icon: ArrowLeftRight, routeName: 'finance.transactions.index', roles: ['FINANCE', 'SUPERADMIN'] },
    {
        label: 'Pengajuan barang',
        icon: ClipboardList,
        routeName: 'logistics.material-requests.index',
        roles: ['ESTIMATOR', 'PM', 'FIELD_STAFF', 'SUPERADMIN'],
    },
    // Only a Tukang submits overtime (OvertimeController's canSubmit).
    { label: 'Pengajuan lembur', icon: Clock, routeName: 'overtime.index', roles: ['FIELD_STAFF'] },
    { label: 'Material katalog', icon: Package, routeName: 'logistics.materials.index', roles: ['LOGISTICS', 'SUPERADMIN'] },
    { label: 'Karyawan', icon: IdCard, routeName: 'hr.employees.index', roles: ['HR', 'SUPERADMIN'] },
];

const ROLE_GROUP_ORDER: Partial<Record<Role, string[]>> = {
    CEO: ['Utama', 'Eksekutif', 'Keuangan', 'Presales', 'Eksekusi', 'Logistik', 'SDM'],
    FINANCE: ['Utama', 'Keuangan', 'Eksekusi', 'Logistik'],
    LOGISTICS: ['Utama', 'Logistik', 'Eksekusi', 'Keuangan'],
    HR: ['Utama', 'SDM'],
    PM: ['Utama', 'Eksekusi', 'Presales', 'Logistik', 'Keuangan'],
    ASISTEN_PM: ['Utama', 'Eksekusi', 'Presales', 'Logistik'],
    FIELD_STAFF: ['Utama', 'Eksekusi', 'Logistik', 'Keuangan'],
    QA: ['Utama', 'Eksekusi', 'Presales'],
    ESTIMATOR: ['Utama', 'Presales', 'Logistik'],
};

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
 * all three always agree. Hubs keep only the tabs the role may open, and
 * groups come in the role's order (ROLE_GROUP_ORDER).
 */
export function useNavGroups(): NavGroup[] {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;
    // Every role held — a stacked role (Kepala Desain) sees its base role's menus too.
    const roles = auth.user?.roles ?? (role ? [role] : []);
    const rolesKey = roles.join(',');
    const hasEmployee = Boolean(auth.user?.has_employee);

    return useMemo(() => {
        // SUPERADMIN is god-mode (see database/seeders/RoleSeeder.php) —
        // sees every nav item regardless of its `roles` list.
        const allowed = (entry: { roles?: Role[] }) =>
            !entry.roles || roles.includes('SUPERADMIN') || roles.some((held) => entry.roles?.includes(held));

        const groups = NAV_GROUPS.map((group) => ({
            ...group,
            items: group.items.flatMap((item): NavItem[] => {
                if ((item.requiresEmployee && !hasEmployee) || !allowed(item)) return [];
                if (!item.tabs) {
                    const roleLabel = role ? item.roleLabels?.[role] : undefined;

                    return [roleLabel ? { ...item, label: roleLabel } : item];
                }

                const tabs = item.tabs.filter(allowed);
                if (tabs.length === 0) return [];
                // A single tab left: an ordinary menu named after it, no tab bar.
                if (tabs.length === 1) {
                    const [only] = tabs;
                    return [{ label: only.label, icon: only.icon, routeName: only.routeName, match: only.match }];
                }

                return [{ ...item, routeName: tabs[0].routeName, tabs }];
            }),
        })).filter((group) => group.items.length > 0);

        const order = (role && ROLE_GROUP_ORDER[role]) || [];
        const rank = (label: string) => (order.includes(label) ? order.indexOf(label) : order.length);

        // Array#sort is stable — unlisted groups keep their NAV_GROUPS order.
        return groups.sort((a, b) => rank(a.label) - rank(b.label));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rolesKey, role, hasEmployee]);
}

/** Ziggy pattern of the routes a menu or tab covers (see NavItem.match). */
function navPattern(entry: { routeName?: string; match?: string }): string | null {
    if (!entry.routeName) return null;
    if (entry.match) return entry.match;

    return /\.(index|edit)$/.test(entry.routeName) ? entry.routeName.replace(/\.[^.]+$/, '.*') : entry.routeName;
}

function isCurrentRoute(entry: { routeName?: string; match?: string }): boolean {
    const pattern = navPattern(entry);

    return Boolean(pattern && typeof route !== 'undefined' && route().current(pattern));
}

/** A hub is current when one of its tabs is. */
function isCurrentNav(item: NavItem): boolean {
    return item.tabs ? item.tabs.some(isCurrentRoute) : isCurrentRoute(item);
}

/**
 * The sidebar menu (and its group, and the hub tab) the current page
 * belongs to. Role-visible menus first; a page reachable without its menu
 * being visible (e.g. a detail page opened from a notification) still gets
 * a trail, just without the menu link (`visible: false`).
 */
export function useActiveNav(): { group: NavGroup; item: NavItem; tab: NavTab | null; visible: boolean } | null {
    const groups = useNavGroups();
    const { url } = usePage();

    return useMemo(() => {
        for (const [list, visible] of [
            [groups, true],
            [NAV_GROUPS, false],
        ] as const) {
            for (const group of list) {
                const item = group.items.find(isCurrentNav);
                if (item) return { group, item, tab: item.tabs?.find(isCurrentRoute) ?? null, visible };
            }
        }

        return null;
        // `url` re-runs the match after every visit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [groups, url]);
}

/**
 * Sprint 13 #5 — items waiting behind a menu or a hub tab: the
 * "Perlu Tindakan" counts (`navBadges`, route name → count). A hub sums
 * its tabs; Perlu Tindakan itself shows the grand total.
 */
export function useNavBadge(): (entry: { routeName?: string; tabs?: NavTab[] }) => number {
    const { navBadges } = usePage<PageProps>().props;

    return useCallback(
        (entry) => {
            const badges = navBadges ?? {};

            if (entry.routeName === 'inbox.index') {
                return Object.values(badges).reduce((sum, count) => sum + count, 0);
            }

            if (entry.tabs) {
                return entry.tabs.reduce((sum, tab) => sum + (badges[tab.routeName] ?? 0), 0);
            }

            return entry.routeName ? (badges[entry.routeName] ?? 0) : 0;
        },
        [navBadges],
    );
}

/**
 * Sprint 13 H1 — a Tukang (primary role FIELD_STAFF) gets the phone
 * layout below `lg`: bottom navigation, no hamburger sidebar, and the
 * plain words of their screens ("Tugas"). Pages use it for their labels.
 */
export function useIsFieldStaff(): boolean {
    return usePage<PageProps>().props.auth.user?.role === 'FIELD_STAFF';
}

/** The count chip of a menu, tab or folded group. */
export function NavBadge({ count, className }: { count: number; className?: string }) {
    if (count <= 0) return null;

    return (
        <span
            className={cn(
                'ml-auto shrink-0 rounded-md bg-daiku-yellow-light px-1.5 py-px text-[11px] font-semibold text-daiku-yellow-dark tabular-nums',
                className,
            )}
            aria-label={`${count} perlu tindakan`}
        >
            {count > 99 ? '99+' : count}
        </span>
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

// Latest folded-groups list of this browser session. The save runs in the
// background, so a page visited before it lands still gets the server's
// older value in its props — this one wins until the next full load.
let localCollapsed: string[] | null = null;
let saveTimer: ReturnType<typeof setTimeout> | undefined;

/**
 * Sprint 13 #7 — the groups this user folded, saved to
 * `users.nav_preferences` (so they follow the user to the phone). Changes
 * show at once; the save is debounced and sent with axios, not an Inertia
 * visit, so it never cancels the page visit the user is making.
 */
function useCollapsedGroups(): [string[], (label: string) => void] {
    const { auth } = usePage<PageProps>().props;
    const [collapsed, setCollapsed] = useState<string[]>(
        () => localCollapsed ?? auth.user?.nav_preferences?.collapsed_groups ?? [],
    );

    function toggle(label: string) {
        const next = collapsed.includes(label) ? collapsed.filter((value) => value !== label) : [...collapsed, label];
        localCollapsed = next;
        setCollapsed(next);

        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            window.axios
                .patch(route('profile.nav-preferences.update'), { collapsed_groups: next })
                .catch(() => {
                    // Not worth a toast — the sidebar still works; it just
                    // won't remember this fold on the next full load.
                });
        }, 600);
    }

    return [collapsed, toggle];
}

function NavLink({ item }: { item: NavItem }) {
    const Icon = item.icon;
    const badge = useNavBadge()(item);
    // Detail/create pages (and every tab of a hub) keep their menu highlighted.
    const isActive = isCurrentNav(item);

    if (!item.routeName) {
        return (
            <span
                className="flex h-8 cursor-not-allowed items-center justify-between rounded-lg px-2.5 text-[13px] text-daiku-muted/70"
                title="Segera hadir"
            >
                <span className="flex items-center gap-2.5">
                    <Icon className="size-4" />
                    {item.label}
                </span>
                <Badge variant="secondary" className="text-[10px] font-normal">
                    Segera
                </Badge>
            </span>
        );
    }

    return (
        <Link
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
                    isActive ? 'text-daiku-yellow-dark' : 'text-daiku-muted group-hover:text-foreground',
                )}
            />
            <span className="truncate">{item.label}</span>
            <NavBadge count={badge} />
        </Link>
    );
}

function SidebarNav() {
    const groups = useNavGroups().filter((group) => !group.pinned);
    const activeGroup = useActiveNav()?.group.label;
    const [collapsed, toggle] = useCollapsedGroups();
    const badgeOf = useNavBadge();
    const navRef = useRef<HTMLElement>(null);

    // Menus low in the list (Logistik, SDM…) would otherwise sit below
    // the fold — bring the active one into view.
    useEffect(() => {
        navRef.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, []);

    return (
        <nav ref={navRef} className="scrollbar-thin flex flex-1 flex-col gap-3 overflow-y-auto px-3 py-2">
            {groups.map((group) => {
                // The group of the page on screen never folds.
                const isActiveGroup = group.label === activeGroup;
                const open = isActiveGroup || !collapsed.includes(group.label);
                const listId = `nav-group-${group.label.toLowerCase()}`;

                return (
                    <div key={group.label}>
                        <button
                            type="button"
                            onClick={() => toggle(group.label)}
                            disabled={isActiveGroup}
                            aria-expanded={open}
                            aria-controls={listId}
                            title={isActiveGroup ? 'Grup halaman yang sedang dibuka' : open ? 'Lipat grup' : 'Buka grup'}
                            className="group/header flex w-full items-center justify-between rounded-md px-2.5 pt-1 pb-1.5 text-[11px] font-semibold tracking-wider text-daiku-muted/80 uppercase transition-colors outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-default disabled:hover:text-daiku-muted/80"
                        >
                            {group.label}
                            {/* A folded group still tells what waits inside it. */}
                            {!open && (
                                <NavBadge
                                    count={group.items.reduce((sum, item) => sum + badgeOf(item), 0)}
                                    className="mr-1.5 tracking-normal"
                                />
                            )}
                            <ChevronDown
                                aria-hidden
                                className={cn(
                                    'size-3.5 transition-transform',
                                    !open && '-rotate-90',
                                    isActiveGroup && 'opacity-0',
                                )}
                            />
                        </button>
                        {open && (
                            <div id={listId} className="flex flex-col gap-0.5">
                                {group.items.map((item) => (
                                    <NavLink key={item.label} item={item} />
                                ))}
                            </div>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}

/** ⚙ Pengaturan — setup pages, pinned above the user card (Sprint 13 #2). */
function SidebarPinned() {
    const items = useNavGroups()
        .filter((group) => group.pinned)
        .flatMap((group) => group.items);

    if (items.length === 0) return null;

    return (
        <div className="mx-3 flex shrink-0 flex-col gap-0.5 border-t border-border pt-2">
            {items.map((item) => (
                <NavLink key={item.label} item={item} />
            ))}
        </div>
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
            <DropdownMenuItem asChild>
                <Link href={route('profile.notifications.edit')}>
                    <BellRing className="size-4" />
                    Pengaturan Notifikasi
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
            <SidebarPinned />
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
        // Utama and the pinned ⚙ Pengaturan have no group crumb — the menu says it all.
        if (active.group.label !== 'Utama' && !active.group.pinned && visibleGroup) {
            crumbs.push({ kind: 'group', group: visibleGroup, current: active.item });
        }
        const deeper = extra.length > 0;
        crumbs.push({
            kind: 'page',
            label: active.item.label,
            icon: active.item.icon,
            // A link only when there's a deeper level to come back from
            // (a hub's tab counts — the menu goes to its first tab).
            href: (deeper || active.tab) && active.visible && active.item.routeName ? route(active.item.routeName) : undefined,
        });
        // Hub tab = its own level, after the menu (🏠 › Keuangan › Penagihan › Invoice).
        if (active.tab) {
            crumbs.push({
                kind: 'page',
                label: active.tab.label,
                icon: active.tab.icon,
                href: deeper && active.visible ? route(active.tab.routeName) : undefined,
            });
        }
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

/**
 * Sprint 13 #4 / D2 — the topbar entry to "Perlu Tindakan", numbered with
 * every queue's total. Beside the bell, not replacing it: notifications
 * are events, this is what waits for you now.
 */
function InboxButton() {
    const total = useNavBadge()({ routeName: 'inbox.index' });

    return (
        <Button variant="outline" size="icon" className="relative" asChild>
            <Link href={route('inbox.index')} aria-label={total > 0 ? `Perlu Tindakan (${total})` : 'Perlu Tindakan'} title="Perlu Tindakan">
                <Inbox className="size-4" />
                {total > 0 && (
                    <span className="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-daiku-yellow px-1 text-[10px] font-semibold text-daiku-dark ring-2 ring-background">
                        {total > 99 ? '99+' : total}
                    </span>
                )}
            </Link>
        </Button>
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
                    {/* Live via useRealtimeNotifications (Reverb, or its 60 s poll),
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
                {/* Sprint 18 Sub 04 — only while this device isn't ringing yet. */}
                <PushOptIn variant="compact" />
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
                                <span
                                    className={cn(
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        notification.priority === 'CLIENT_WAITING' ? 'bg-error' : 'bg-daiku-yellow',
                                    )}
                                />
                                <span className="min-w-0 flex-1">
                                    {/* Sprint 18 — P1 sorts first (HandleInertiaRequests) and says why. */}
                                    {notification.priority === 'CLIENT_WAITING' && (
                                        <StatusChip status="CLIENT_WAITING" label="Segera · klien menunggu" tone="error" className="mb-1" />
                                    )}
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
                <div className="flex border-t border-border p-1">
                    <DropdownMenuItem asChild className="flex-1">
                        <Link href={route('notifications.index')} className="justify-center text-sm font-medium">
                            Lihat semua notifikasi
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link href={route('profile.notifications.edit')} aria-label="Pengaturan Notifikasi" title="Pengaturan Notifikasi">
                            <Settings2 className="size-4" />
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
                    // A hub lists every tab, named "Hub › Tab" so both words find it.
                    items: group.items.flatMap((item) =>
                        item.tabs
                            ? item.tabs.map((tab) => ({ label: `${item.label} › ${tab.label}`, icon: tab.icon, routeName: tab.routeName }))
                            : item.routeName
                              ? [{ label: item.label, icon: item.icon, routeName: item.routeName }]
                              : [],
                    ),
                }))
                .filter((group) => group.items.length > 0),
        [groups],
    );

    useRealtimeNotifications(user?.id);
    // A Tukang navigates with the bottom bar on a phone (BottomNav).
    const isFieldStaff = useIsFieldStaff();

    // Sprint 13 #11 — "Terakhir dibuka" in the command menu (per device).
    const active = useActiveNav();
    const recentRoute = active?.visible ? (active.tab?.routeName ?? active.item.routeName) : undefined;
    useEffect(() => {
        if (recentRoute && recentRoute !== 'dashboard') rememberMenu(recentRoute);
    }, [recentRoute]);

    const heldRoles = user?.roles ?? (user?.role ? [user.role] : []);
    const quickCreate = QUICK_CREATE.filter((item) => item.roles.some((role) => heldRoles.includes(role)));

    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center justify-between gap-3 border-b border-border bg-background/85 px-4 backdrop-blur-md sm:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <Sheet>
                    <SheetTrigger asChild>
                        <Button variant="ghost" size="icon" className={cn('-ml-1.5 lg:hidden', isFieldStaff && 'hidden')} aria-label="Buka navigasi">
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
                <CommandMenu groups={commandGroups} className="w-8 justify-center px-0 md:w-72 md:justify-start md:px-2.5" />
                <QuickCreateMenu items={quickCreate} />
                <InboxButton />
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
    const isFieldStaff = useIsFieldStaff();
    const { pendingProjectOpenings } = usePage<PageProps>().props;

    return (
        <div className="flex h-screen overflow-hidden bg-daiku-gray">
            <Toaster position="top-right" richColors closeButton />
            {/* Sprint 12 #19 — CEO only; renders nothing when no RAB Proyek waits. */}
            {pendingProjectOpenings && (
                <Suspense fallback={null}>
                    <ProjectOpeningPrompt />
                </Suspense>
            )}
            <aside className="hidden h-full w-64 shrink-0 flex-col lg:flex">
                <SidebarContents />
            </aside>

            <div className="flex h-full min-w-0 flex-1 flex-col lg:py-2 lg:pr-2">
                <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-background lg:rounded-2xl lg:shadow-sm lg:ring-1 lg:ring-border">
                    <main className="scrollbar-thin relative flex-1 overflow-y-auto">
                        <Topbar breadcrumbs={breadcrumbs} header={header} />
                        {/* Room for the Tukang's bottom bar on a phone. */}
                        <div
                            className={cn(
                                'mx-auto w-full max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8',
                                isFieldStaff && 'pb-[calc(6rem+env(safe-area-inset-bottom))] lg:pb-8',
                            )}
                        >
                            {children}
                        </div>
                    </main>
                </div>
            </div>
            {isFieldStaff && <BottomNav />}
        </div>
    );
}
