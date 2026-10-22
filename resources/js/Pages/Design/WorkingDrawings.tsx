import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { architectOf } from '@/lib/waitingOn';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import type { PaginatedData, WorkingDrawing, WorkingDrawingStatus } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { DraftingCompass } from 'lucide-react';

interface WorkingDrawingsProps {
    drawings: PaginatedData<WorkingDrawing>;
    filters: { status?: string | null };
}

const STATUS_OPTIONS: { value: WorkingDrawingStatus; label: string }[] = [
    { value: 'MENUNGGU_PENUGASAN', label: 'Menunggu Penugasan' },
    { value: 'DIKERJAKAN', label: 'Dikerjakan' },
    { value: 'SELESAI', label: 'Selesai' },
];

const columns: ColumnDef<WorkingDrawing>[] = [
    {
        id: 'project',
        header: 'Proyek',
        cell: ({ row }) => (
            <Link href={route('design.working-drawings.show', { workingDrawing: row.original.id })} className="font-medium hover:underline">
                {row.original.project?.name ?? `Gambar Kerja #${row.original.id}`}
            </Link>
        ),
    },
    { id: 'client', header: 'Klien', cell: ({ row }) => row.original.project?.lead?.client_name ?? '—' },
    { id: 'pic', header: 'PIC Arsitek', cell: ({ row }) => row.original.pic?.name ?? '—' },
    { id: 'deadline', header: 'Deadline', cell: ({ row }) => formatDate(row.original.deadline) },
    { id: 'status', header: 'Status', cell: ({ row }) => <StatusChip domain="workingDrawing" status={row.original.status} waitingOn={architectOf(row.original.pic?.name)} /> },
];

/** Sprint 24 D2 — the Gambar Kerja of every project (Desain hub tab). */
export default function WorkingDrawings({ drawings, filters }: WorkingDrawingsProps) {
    return (
        <AppLayout>
            <Head title="Gambar Kerja" />
            <PageHeader
                title="Gambar Kerja"
                icon={DraftingCompass}
                description="Tiap proyek yang dibuka masuk ke sini — Kepala Desain menugaskan arsitek, tim menyimpan link lalu menandai selesai (tanpa persetujuan klien)."
            />
            <ModuleTabs />

            <DataTable
                columns={columns}
                data={drawings.data}
                pagination={drawings}
                toolbar={
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(value) =>
                            router.get(route('design.working-drawings.index'), value === 'all' ? {} : { status: value }, { preserveState: true, replace: true })
                        }
                    >
                        <SelectTrigger className="w-full sm:w-56" aria-label="Filter status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua status</SelectItem>
                            {STATUS_OPTIONS.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                }
                mobileCard={(drawing) => (
                    <Link href={route('design.working-drawings.show', { workingDrawing: drawing.id })} className="block space-y-1">
                        <span className="block font-medium text-foreground">{drawing.project?.name}</span>
                        <span className="block text-xs text-muted-foreground">
                            {drawing.pic?.name ?? 'Belum ditugaskan'} · deadline {formatDate(drawing.deadline)}
                        </span>
                        <StatusChip domain="workingDrawing" status={drawing.status} waitingOn={architectOf(drawing.pic?.name)} />
                    </Link>
                )}
                emptyMessage="Belum ada gambar kerja."
            />
        </AppLayout>
    );
}
