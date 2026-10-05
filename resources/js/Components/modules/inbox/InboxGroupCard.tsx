import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { formatDate, formatRelative } from '@/lib/format';
import type { InboxGroup, InboxIcon } from '@/types';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeDollarSign,
    CalendarClock,
    ClipboardCheck,
    ClipboardList,
    Clock,
    FileText,
    FolderKanban,
    ListChecks,
    type LucideIcon,
    Palette,
    PhoneCall,
    PiggyBank,
    ReceiptText,
    ShieldCheck,
    Target,
    ChevronRight,
} from 'lucide-react';

const ICONS: Record<InboxIcon, LucideIcon> = {
    quotation: FileText,
    project: FolderKanban,
    budget: PiggyBank,
    salary: BadgeDollarSign,
    review: ClipboardList,
    material: ClipboardList,
    qa: ShieldCheck,
    overtime: Clock,
    followup: PhoneCall,
    termin: CalendarClock,
    invoice: ReceiptText,
    design: Palette,
    kpi: Target,
    task: ListChecks,
    dailyform: ClipboardCheck,
};

/**
 * One "Perlu Tindakan" queue (Sprint 13 #4): count, the oldest few items
 * (each opens its own page) and "Lihat semua" to the filtered list page.
 * Used by the Perlu Tindakan page and the Dashboard summary.
 */
export function InboxGroupCard({ group, limit }: { group: InboxGroup; limit?: number }) {
    const items = limit ? group.items.slice(0, limit) : group.items;
    const rest = group.count - items.length;

    return (
        <SectionCard
            title={
                <span className="flex items-center gap-2">
                    {group.label}
                    <span className="rounded-md bg-daiku-yellow-light px-1.5 py-0.5 text-[11px] font-semibold text-daiku-yellow-dark tabular-nums">
                        {group.count}
                    </span>
                </span>
            }
            description={group.description}
            icon={ICONS[group.icon]}
            flush
            action={
                <Button variant="ghost" size="sm" asChild>
                    <Link href={group.href}>
                        Lihat semua
                        <ArrowRight className="size-3.5" />
                    </Link>
                </Button>
            }
            footer={
                rest > 0 ? (
                    <Link href={group.href} className="text-xs text-muted-foreground hover:text-foreground">
                        +{rest} lainnya
                    </Link>
                ) : undefined
            }
        >
            <ul className="divide-y divide-border">
                {items.map((item) => (
                    <li key={item.id}>
                        <Link
                            href={item.href}
                            className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-daiku-yellow-light/60 sm:px-5"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium text-foreground">{item.title}</span>
                                {item.subtitle && (
                                    <span className="block truncate text-xs text-muted-foreground">{item.subtitle}</span>
                                )}
                            </span>
                            {item.at && (
                                <span className="shrink-0 text-[11px] text-muted-foreground/80" title={formatDate(item.at)}>
                                    {formatRelative(item.at)}
                                </span>
                            )}
                            <ChevronRight className="size-4 shrink-0 text-muted-foreground/60" />
                        </Link>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}
