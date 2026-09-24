import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Material, Project, ProjectMaterial } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export interface MaterialPermissions {
    create: boolean;
    update: boolean;
    delete: boolean;
}

interface ProjectMaterialsPanelProps {
    project: Project;
    items: ProjectMaterial[];
    canView: boolean;
    permissions: MaterialPermissions;
    materialOptions: Pick<Material, 'id' | 'name' | 'unit' | 'stock'>[];
}

// Mirrors StoreProjectMaterialRequest / UpdateProjectMaterialRequest.
const schema = z.object({
    material_id: z.string().min(1, 'Material wajib dipilih'),
    qty_planned: z
        .string()
        .min(1, 'Jumlah kebutuhan wajib diisi')
        .refine((v) => Number.isInteger(Number(v)) && Number(v) >= 0, 'Harus bilangan bulat'),
});

type FormValues = z.infer<typeof schema>;

/**
 * PRD §4.8 "Kebutuhan Material Proyek" (CSV Sprint 6: "kebutuhan
 * material per proyek + input pemakaian"). Planned qty is entered here;
 * used qty accumulates from Logistics' stock-out movements
 * (StockService) and is read-only everywhere.
 */
export function ProjectMaterialsPanel({ project, items, canView, permissions, materialOptions }: ProjectMaterialsPanelProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<ProjectMaterial | null>(null);

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { material_id: '', qty_planned: '' } });

    useEffect(() => {
        if (dialogOpen) {
            form.reset(
                editing
                    ? { material_id: String(editing.material_id), qty_planned: String(editing.qty_planned) }
                    : { material_id: '', qty_planned: '' },
            );
        }
    }, [dialogOpen, editing]);

    if (!canView) {
        return (
            <Card>
                <CardContent className="py-10 text-center text-sm text-daiku-muted">
                    Anda tidak memiliki akses ke kebutuhan material proyek.
                </CardContent>
            </Card>
        );
    }

    const plannedCost = items.reduce((sum, item) => sum + item.qty_planned * Number(item.material?.cost_price ?? 0), 0);
    const usedCost = items.reduce((sum, item) => sum + item.qty_used * Number(item.material?.cost_price ?? 0), 0);

    function onSubmit(values: FormValues) {
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => setDialogOpen(false),
        };

        if (editing) {
            router.put(
                route('project-materials.update', { project_material: editing.id }),
                { qty_planned: Number(values.qty_planned) },
                options,
            );
        } else {
            router.post(
                route('projects.materials.store', { project: project.id }),
                { material_id: Number(values.material_id), qty_planned: Number(values.qty_planned) },
                options,
            );
        }
    }

    function remove(item: ProjectMaterial) {
        if (!confirm(`Hapus "${item.material?.name}" dari kebutuhan proyek?`)) return;
        router.delete(route('project-materials.destroy', { project_material: item.id }), { preserveScroll: true });
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm text-daiku-muted">
                    Estimasi biaya material: <span className="font-medium text-daiku-dark">{formatRupiah(plannedCost)}</span>
                    {' · '}Terpakai: <span className="font-medium text-daiku-dark">{formatRupiah(usedCost)}</span>
                </p>
                {permissions.create && (
                    <Button
                        size="sm"
                        onClick={() => {
                            setEditing(null);
                            setDialogOpen(true);
                        }}
                    >
                        <Plus className="size-4" />
                        Tambah Kebutuhan
                    </Button>
                )}
            </div>

            <div className="overflow-x-auto rounded-lg border border-daiku-border bg-white">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium">Material</th>
                            <th className="p-2 text-right font-medium">Rencana</th>
                            <th className="p-2 text-right font-medium">Terpakai</th>
                            <th className="w-40 p-2 text-left font-medium">Realisasi</th>
                            <th className="p-2 text-right font-medium">Stok Gudang</th>
                            {(permissions.update || permissions.delete) && <th className="p-2" />}
                        </tr>
                    </thead>
                    <tbody>
                        {items.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-6 text-center text-daiku-muted">
                                    Belum ada kebutuhan material untuk proyek ini.
                                </td>
                            </tr>
                        ) : (
                            items.map((item) => {
                                const unit = item.material?.unit ?? '';
                                const percent = item.qty_planned > 0 ? Math.round((item.qty_used / item.qty_planned) * 100) : null;
                                const over = item.qty_used > item.qty_planned;
                                const shortage = Math.max(item.qty_planned - item.qty_used - (item.material?.stock ?? 0), 0);

                                return (
                                    <tr key={item.id} className="border-t border-daiku-border">
                                        <td className="p-2 font-medium">{item.material?.name}</td>
                                        <td className="p-2 text-right tabular-nums">
                                            {item.qty_planned} {unit}
                                        </td>
                                        <td className={cn('p-2 text-right tabular-nums', over && 'font-medium text-error')}>
                                            {item.qty_used} {unit}
                                        </td>
                                        <td className="p-2">
                                            {percent === null ? (
                                                <span className="text-xs text-daiku-muted">Tidak direncanakan</span>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    <div
                                                        className="h-1.5 flex-1 overflow-hidden rounded-full bg-daiku-gray"
                                                        role="progressbar"
                                                        aria-valuenow={Math.min(percent, 100)}
                                                        aria-valuemin={0}
                                                        aria-valuemax={100}
                                                        aria-label={`Realisasi ${item.material?.name}`}
                                                    >
                                                        <div
                                                            className={cn('h-full rounded-full', over ? 'bg-error' : 'bg-daiku-yellow')}
                                                            style={{ width: `${Math.min(percent, 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className={cn('w-10 text-right text-xs tabular-nums', over ? 'text-error' : 'text-daiku-muted')}>
                                                        {percent}%
                                                    </span>
                                                </div>
                                            )}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            <span className={cn(shortage > 0 && 'font-medium text-warning')}>
                                                {item.material?.stock ?? 0} {unit}
                                            </span>
                                            {shortage > 0 && <p className="text-xs text-warning">kurang {shortage}</p>}
                                        </td>
                                        {(permissions.update || permissions.delete) && (
                                            <td className="p-2">
                                                <div className="flex justify-end gap-1">
                                                    {permissions.update && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Ubah rencana ${item.material?.name}`}
                                                            onClick={() => {
                                                                setEditing(item);
                                                                setDialogOpen(true);
                                                            }}
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                    )}
                                                    {permissions.delete && item.qty_used === 0 && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Hapus ${item.material?.name}`}
                                                            onClick={() => remove(item)}
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Ubah Rencana Material' : 'Tambah Kebutuhan Material'}</DialogTitle>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="material_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Material</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange} disabled={editing !== null}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih material" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {(editing?.material ? [editing.material] : materialOptions).map((material) => (
                                                    <SelectItem key={material.id} value={String(material.id)}>
                                                        {material.name} — stok {material.stock} {material.unit}
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
                                name="qty_planned"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jumlah Kebutuhan</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="1" inputMode="numeric" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Simpan
                                </Button>
                            </DialogFooter>
                        </form>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
