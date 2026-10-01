import { Button } from '@/Components/ui/button';
import type { PageProps, Role } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

type DashboardLinkButtonProps = {
    routeName: string;
    label: string;
    icon: LucideIcon;
    /** Roles allowed on the target route (mirror its `role:` middleware). SUPERADMIN is always allowed. */
    roles: Role[];
};

/**
 * PageHeader action on a module index page that opens that division's
 * dashboard — same pattern as "Statistik Pipeline" on CRM. Hidden for roles
 * the dashboard route would answer with 403.
 */
export function DashboardLinkButton({ routeName, label, icon: Icon, roles }: DashboardLinkButtonProps) {
    const role = usePage<PageProps>().props.auth.user?.role;

    if (!role || (role !== 'SUPERADMIN' && !roles.includes(role))) {
        return null;
    }

    return (
        <Button variant="outline" asChild>
            <Link href={route(routeName)}>
                <Icon className="size-4" />
                {label}
            </Link>
        </Button>
    );
}
