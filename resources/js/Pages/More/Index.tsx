import { Card } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { AlertOctagon, ChevronRight, ClipboardList, FolderKanban, LogOut, type LucideIcon, User } from 'lucide-react';

interface MoreIndexProps {
    penaltyThisMonth: { month: string; count: number; total: number; unpaid: number };
}

const LINKS: { label: string; hint: string; icon: LucideIcon; routeName: string }[] = [
    { label: 'Penalti', hint: 'Riwayat penalti Anda', icon: AlertOctagon, routeName: 'penalties.index' },
    { label: 'Pengajuan Barang', hint: 'Minta barang yang tidak ada di gudang', icon: ClipboardList, routeName: 'logistics.material-requests.index' },
    { label: 'Proyek', hint: 'Proyek tempat Anda bekerja', icon: FolderKanban, routeName: 'projects.index' },
    { label: 'Profil', hint: 'Nama, email, kata sandi', icon: User, routeName: 'profile.edit' },
];

const ROW_CLASS = 'flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left transition-colors active:bg-daiku-yellow-light/70';

/**
 * Sprint 13 H1/H10 — "Lainnya", the fourth button of the Tukang's phone
 * navigation: this month's penalty total in plain sight, then the pages
 * a Tukang opens less often. Light on purpose (H8): no charts, no tables.
 */
export default function MoreIndex({ penaltyThisMonth }: MoreIndexProps) {
    const hasPenalty = penaltyThisMonth.total > 0;

    return (
        <AppLayout>
            <Head title="Lainnya" />

            <h1 className="mb-4 text-xl font-semibold tracking-tight text-foreground">Lainnya</h1>

            <Link href={route('penalties.index')} className="mb-6 block">
                <Card className={cn('gap-1 px-4 py-4', hasPenalty && 'bg-error/5')}>
                    <p className="text-sm text-muted-foreground">Penalti {penaltyThisMonth.month}</p>
                    <p className={cn('text-3xl font-semibold tracking-tight tabular-nums', hasPenalty ? 'text-error-ink' : 'text-foreground')}>
                        {formatRupiah(penaltyThisMonth.total)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {hasPenalty
                            ? `${penaltyThisMonth.count} kali · ${formatRupiah(penaltyThisMonth.unpaid)} belum dibayar`
                            : 'Tidak ada penalti bulan ini. Pertahankan!'}
                    </p>
                </Card>
            </Link>

            <Card className="gap-0 divide-y divide-border overflow-hidden py-0">
                {LINKS.map((link) => (
                    <Link key={link.routeName} href={route(link.routeName)} className={ROW_CLASS}>
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-daiku-gray text-daiku-muted">
                            <link.icon className="size-5" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block font-medium text-foreground">{link.label}</span>
                            <span className="block truncate text-xs text-muted-foreground">{link.hint}</span>
                        </span>
                        <ChevronRight className="size-5 text-muted-foreground/60" />
                    </Link>
                ))}
                <Link href={route('logout')} method="post" as="button" className={cn(ROW_CLASS, 'text-error-ink')}>
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-error/10">
                        <LogOut className="size-5" />
                    </span>
                    <span className="flex-1 font-medium">Keluar</span>
                </Link>
            </Card>
        </AppLayout>
    );
}
