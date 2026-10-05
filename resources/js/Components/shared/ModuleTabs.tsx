import { UnderlineTabsList } from '@/Components/shared/UnderlineTabsList';
import { Tabs, TabsTrigger } from '@/Components/ui/tabs';
import { NavBadge, useActiveNav, useNavBadge } from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

/**
 * Tab bar of a hub menu (Sprint 13 #1) — e.g. Penagihan [Termin | Invoice
 * | Verifikasi Pembayaran]. Every tab is its own route, so each one is an
 * Inertia <Link>, not local state; the tabs, their order and the role
 * filtering come from the sidebar entry (`NavItem.tabs` in AppLayout).
 * Place it right under the page's <PageHeader />. Renders nothing when
 * the role sees only one tab of the hub (the menu is then that page).
 */
export function ModuleTabs({ className }: { className?: string }) {
    const active = useActiveNav();
    const badgeOf = useNavBadge();

    if (!active?.visible || !active.item.tabs || !active.tab) {
        return null;
    }

    return (
        <Tabs value={active.tab.routeName} className={cn('-mt-2 mb-6', className)}>
            <UnderlineTabsList aria-label={active.item.label}>
                {active.item.tabs.map((tab) => (
                    <TabsTrigger key={tab.routeName} value={tab.routeName} asChild>
                        <Link href={route(tab.routeName)}>
                            <tab.icon />
                            {tab.label}
                            <NavBadge count={badgeOf(tab)} />
                        </Link>
                    </TabsTrigger>
                ))}
            </UnderlineTabsList>
        </Tabs>
    );
}
