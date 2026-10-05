import { EmptyState } from '@/Components/shared/EmptyState';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import AppLayout from '@/Layouts/AppLayout';
import type { Division, Position } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { Building2, Network, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface StructureIndexProps {
    divisions: (Division & { positions: Position[]; positions_count: number })[];
    canManage: boolean;
}

// Mirrors DivisionRequest / PositionRequest.
const divisionSchema = z.object({
    name: z.string().trim().min(1, 'Nama divisi wajib diisi.').max(100, 'Nama divisi maksimal 100 karakter.'),
    sort_order: z.string().refine((v) => v === '' || (Number.isInteger(Number(v)) && Number(v) >= 0), 'Urutan harus angka ≥ 0.'),
    is_active: z.boolean(),
});

const positionSchema = divisionSchema.extend({
    name: z.string().trim().min(1, 'Nama jabatan wajib diisi.').max(100, 'Nama jabatan maksimal 100 karakter.'),
    division_id: z.string().min(1, 'Divisi wajib dipilih.'),
});

type DivisionValues = z.infer<typeof divisionSchema>;
type PositionValues = z.infer<typeof positionSchema>;

function submitErrors<T extends Record<string, unknown>>(setError: (field: keyof T, error: { message: string }) => void) {
    return (errors: Record<string, string>) =>
        Object.entries(errors).forEach(([field, message]) => setError(field as keyof T, { message }));
}

/**
 * SDM (Sprint 10, decision #10) — Divisi → Jabatan. Every employee and KPI
 * template points to a position here; nothing is typed free-hand. Used
 * divisions/positions are deactivated, not deleted (server-enforced).
 */
export default function StructureIndex({ divisions, canManage }: StructureIndexProps) {
    const [divisionDialog, setDivisionDialog] = useState<{ open: boolean; division: Division | null }>({ open: false, division: null });
    const [positionDialog, setPositionDialog] = useState<{ open: boolean; position: Position | null; divisionId: number | null }>({
        open: false,
        position: null,
        divisionId: null,
    });

    function destroyDivision(division: Division) {
        if (!confirm(`Hapus divisi "${division.name}"?`)) return;
        router.delete(route('hr.divisions.destroy', { division: division.id }), { preserveScroll: true });
    }

    function destroyPosition(position: Position) {
        if (!confirm(`Hapus jabatan "${position.name}"?`)) return;
        router.delete(route('hr.positions.destroy', { position: position.id }), { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Divisi & Jabatan" />

            <PageHeader
                title="Divisi & Jabatan"
                icon={Network}
                description="Struktur jabatan resmi Daiku. Jabatan karyawan dan template KPI dipilih dari sini — tidak diketik bebas."
                actions={
                    canManage && (
                        <Button onClick={() => setDivisionDialog({ open: true, division: null })}>
                            <Plus className="size-4" />
                            Tambah Divisi
                        </Button>
                    )
                }
            />

            <ModuleTabs />

            {divisions.length === 0 ? (
                <EmptyState
                    className="rounded-xl border border-dashed border-border"
                    title="Belum ada divisi."
                    description="Tambahkan divisi dulu, lalu jabatan di dalamnya."
                />
            ) : (
                <div className="space-y-6">
                    {divisions.map((division) => (
                        <SectionCard
                            key={division.id}
                            title={
                                <span className="flex items-center gap-2">
                                    {division.name}
                                    {!division.is_active && <StatusChip status="INACTIVE" tone="neutral" label="Nonaktif" />}
                                </span>
                            }
                            description={`${division.positions.length} jabatan`}
                            icon={Building2}
                            action={
                                canManage && (
                                    <div className="flex gap-1">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setPositionDialog({ open: true, position: null, divisionId: division.id })}
                                        >
                                            <Plus className="size-4" />
                                            Jabatan
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Edit divisi ${division.name}`}
                                            onClick={() => setDivisionDialog({ open: true, division })}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        {division.positions_count === 0 && (
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Hapus divisi ${division.name}`}
                                                onClick={() => destroyDivision(division)}
                                            >
                                                <Trash2 className="size-4 text-error-ink" />
                                            </Button>
                                        )}
                                    </div>
                                )
                            }
                            flush
                        >
                            {division.positions.length === 0 ? (
                                <EmptyState title="Belum ada jabatan di divisi ini." />
                            ) : (
                                <TableCard className="rounded-none border-0 ring-0 shadow-none">
                                    <table className="w-full text-sm">
                                        <thead className={TABLE_HEAD_CLASS}>
                                            <tr>
                                                <th className="px-4 py-2.5 text-left font-semibold">Jabatan</th>
                                                <th className="px-4 py-2.5 text-right font-semibold">Karyawan</th>
                                                <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                                {canManage && <th className="w-24 px-4 py-2.5" />}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {division.positions.map((position) => (
                                                <tr key={position.id} className="border-t border-border hover:bg-daiku-gray/60">
                                                    <td className="px-4 py-3 font-medium">{position.name}</td>
                                                    <td className="px-4 py-3 text-right tabular-nums">{position.employees_count ?? 0}</td>
                                                    <td className="px-4 py-3">
                                                        <StatusChip
                                                            status={position.is_active ? 'ACTIVE_POSITION' : 'INACTIVE_POSITION'}
                                                            tone={position.is_active ? 'success' : 'neutral'}
                                                            label={position.is_active ? 'Aktif' : 'Nonaktif'}
                                                        />
                                                    </td>
                                                    {canManage && (
                                                        <td className="px-4 py-3">
                                                            <div className="flex justify-end gap-1">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon-sm"
                                                                    aria-label={`Edit jabatan ${position.name}`}
                                                                    onClick={() => setPositionDialog({ open: true, position, divisionId: division.id })}
                                                                >
                                                                    <Pencil className="size-4" />
                                                                </Button>
                                                                {(position.employees_count ?? 0) === 0 && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon-sm"
                                                                        aria-label={`Hapus jabatan ${position.name}`}
                                                                        onClick={() => destroyPosition(position)}
                                                                    >
                                                                        <Trash2 className="size-4 text-error-ink" />
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </TableCard>
                            )}
                        </SectionCard>
                    ))}
                </div>
            )}

            {canManage && (
                <>
                    <DivisionDialog
                        open={divisionDialog.open}
                        division={divisionDialog.division}
                        onOpenChange={(open) => setDivisionDialog((state) => ({ ...state, open }))}
                    />
                    <PositionDialog
                        open={positionDialog.open}
                        position={positionDialog.position}
                        divisionId={positionDialog.divisionId}
                        divisions={divisions}
                        onOpenChange={(open) => setPositionDialog((state) => ({ ...state, open }))}
                    />
                </>
            )}
        </AppLayout>
    );
}

function DivisionDialog({ open, division, onOpenChange }: { open: boolean; division: Division | null; onOpenChange: (open: boolean) => void }) {
    const form = useForm<DivisionValues>({
        resolver: zodResolver(divisionSchema),
        defaultValues: { name: '', sort_order: '', is_active: true },
    });

    useEffect(() => {
        if (!open) return;
        form.reset({ name: division?.name ?? '', sort_order: division ? String(division.sort_order) : '', is_active: division?.is_active ?? true });
    }, [open, division]);

    function onSubmit(values: DivisionValues) {
        const payload = {
            name: values.name,
            sort_order: values.sort_order === '' ? null : Number(values.sort_order),
            ...(division ? { is_active: values.is_active } : {}),
        };
        const options = { preserveScroll: true, onError: submitErrors<DivisionValues>(form.setError), onSuccess: () => onOpenChange(false) };

        if (division) {
            router.put(route('hr.divisions.update', { division: division.id }), payload, options);
        } else {
            router.post(route('hr.divisions.store'), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{division ? 'Edit Divisi' : 'Tambah Divisi'}</DialogTitle>
                    <DialogDescription>Divisi mengelompokkan jabatan, mis. Desain, Presales, Proyek.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Divisi</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus placeholder="mis. Desain" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <SortOrderField control={form.control} />
                        {division && <ActiveField control={form.control} description="Divisi nonaktif tidak bisa dipilih untuk jabatan baru." />}
                        <DialogActions submitting={form.formState.isSubmitting} />
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function PositionDialog({
    open,
    position,
    divisionId,
    divisions,
    onOpenChange,
}: {
    open: boolean;
    position: Position | null;
    divisionId: number | null;
    divisions: Division[];
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<PositionValues>({
        resolver: zodResolver(positionSchema),
        defaultValues: { name: '', division_id: '', sort_order: '', is_active: true },
    });

    useEffect(() => {
        if (!open) return;
        form.reset({
            name: position?.name ?? '',
            division_id: String(position?.division_id ?? divisionId ?? ''),
            sort_order: position ? String(position.sort_order) : '',
            is_active: position?.is_active ?? true,
        });
    }, [open, position, divisionId]);

    function onSubmit(values: PositionValues) {
        const payload = {
            name: values.name,
            division_id: Number(values.division_id),
            sort_order: values.sort_order === '' ? null : Number(values.sort_order),
            ...(position ? { is_active: values.is_active } : {}),
        };
        const options = { preserveScroll: true, onError: submitErrors<PositionValues>(form.setError), onSuccess: () => onOpenChange(false) };

        if (position) {
            router.put(route('hr.positions.update', { position: position.id }), payload, options);
        } else {
            router.post(route('hr.positions.store'), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{position ? 'Edit Jabatan' : 'Tambah Jabatan'}</DialogTitle>
                    <DialogDescription>Nama jabatan tidak boleh kembar dalam satu divisi.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="division_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Divisi</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih divisi" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {divisions
                                                .filter((d) => d.is_active || String(d.id) === field.value)
                                                .map((d) => (
                                                    <SelectItem key={d.id} value={String(d.id)}>
                                                        {d.name}
                                                    </SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Jabatan</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus placeholder="mis. Desainer Interior" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <SortOrderField control={form.control} />
                        {position && <ActiveField control={form.control} description="Jabatan nonaktif tidak bisa dipilih untuk karyawan baru." />}
                        <DialogActions submitting={form.formState.isSubmitting} />
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any -- shared by two forms with the same field names
function SortOrderField({ control }: { control: any }) {
    return (
        <FormField
            control={control}
            name="sort_order"
            render={({ field }) => (
                <FormItem>
                    <FormLabel>Urutan (opsional)</FormLabel>
                    <FormControl>
                        <Input type="number" min="0" inputMode="numeric" {...field} />
                    </FormControl>
                    <FormDescription>Angka kecil tampil lebih dulu.</FormDescription>
                    <FormMessage />
                </FormItem>
            )}
        />
    );
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any -- shared by two forms with the same field names
function ActiveField({ control, description }: { control: any; description: string }) {
    return (
        <FormField
            control={control}
            name="is_active"
            render={({ field }) => (
                <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                    <div>
                        <FormLabel className="cursor-pointer">Aktif</FormLabel>
                        <FormDescription>{description}</FormDescription>
                    </div>
                    <FormControl>
                        <Switch checked={field.value} onCheckedChange={field.onChange} />
                    </FormControl>
                </FormItem>
            )}
        />
    );
}

function DialogActions({ submitting }: { submitting: boolean }) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline">
                    Batal
                </Button>
            </DialogClose>
            <Button type="submit" disabled={submitting}>
                Simpan
            </Button>
        </DialogFooter>
    );
}
