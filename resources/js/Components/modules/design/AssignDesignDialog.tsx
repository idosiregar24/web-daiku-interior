import { DatePicker } from '@/Components/shared/DatePicker';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { DesignStaffMember } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors AssignDesignRequest.
const schema = z
    .object({
        pic_id: z.string().min(1, 'PIC arsitek wajib dipilih'),
        assistant_ids: z.array(z.string()).max(20, 'Maksimal 20 asisten per desain.'),
        start_date: z.date({ message: 'Tanggal mulai wajib diisi' }),
        target_hari: z
            .string()
            .regex(/^\d+$/, 'Target hari wajib diisi')
            .refine((value) => Number(value) >= 1 && Number(value) <= 365, 'Target 1–365 hari.'),
    })
    .refine((values) => !values.assistant_ids.includes(values.pic_id), {
        path: ['assistant_ids'],
        message: 'PIC tidak perlu dipilih lagi sebagai asisten.',
    });

type FormValues = z.infer<typeof schema>;
type Person = { id: number; name: string };

export interface AssignableDesign {
    id: number;
    client_name: string;
    pic_id?: number | null;
    staff?: DesignStaffMember[];
    start_date?: string | null;
    target_hari?: number | null;
}

interface AssignDesignDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    design: AssignableDesign;
    /** Active architects (DESIGNER) — the Kepala Desain is one of them. */
    architects: Person[];
}

/**
 * Sprint 12 decision #15 — the Kepala Desain's "Tugaskan Desain": PIC
 * architect (themself allowed), assistants and the timeline. Reopened as
 * "Ubah Penugasan" while the design is still being worked on.
 */
export function AssignDesignDialog({ open, onOpenChange, design, architects }: AssignDesignDialogProps) {
    const me = usePage().props.auth.user;
    const reassigning = design.pic_id != null;

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { pic_id: '', assistant_ids: [], start_date: undefined, target_hari: '' },
    });

    useEffect(() => {
        if (open) {
            form.reset({
                pic_id: design.pic_id ? String(design.pic_id) : '',
                assistant_ids: (design.staff ?? []).map((member) => String(member.id)),
                start_date: design.start_date ? new Date(design.start_date) : new Date(),
                target_hari: design.target_hari ? String(design.target_hari) : '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, design.id]);

    const picId = form.watch('pic_id');

    function onSubmit(values: FormValues) {
        router.post(
            route('design.assign', { design: design.id }),
            {
                pic_id: Number(values.pic_id),
                assistant_ids: values.assistant_ids.map(Number),
                start_date: format(values.start_date, 'yyyy-MM-dd'),
                target_hari: Number(values.target_hari),
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => {
                        const key = field.startsWith('assistant_ids') ? 'assistant_ids' : field;
                        form.setError((key in values ? key : 'pic_id') as keyof FormValues, { message });
                    }),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{reassigning ? 'Ubah Penugasan' : 'Tugaskan Desain'}</DialogTitle>
                    <DialogDescription>
                        Desain <span className="font-medium text-foreground">{design.client_name}</span> — pilih PIC arsitek (boleh Anda
                        sendiri), asisten, dan timeline.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="pic_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>PIC Arsitek</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih arsitek" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {architects.map((architect) => (
                                                <SelectItem key={architect.id} value={String(architect.id)}>
                                                    {architect.name}
                                                    {architect.id === me.id ? ' (Anda)' : ''}
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
                            name="assistant_ids"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Asisten Arsitek</FormLabel>
                                    <div className="max-h-40 space-y-2 overflow-y-auto rounded-lg border border-border p-3">
                                        {architects.filter((architect) => String(architect.id) !== picId).length === 0 ? (
                                            <p className="text-sm text-daiku-muted">Tidak ada arsitek lain.</p>
                                        ) : (
                                            architects
                                                .filter((architect) => String(architect.id) !== picId)
                                                .map((architect) => {
                                                    const id = String(architect.id);
                                                    const checked = field.value.includes(id);

                                                    return (
                                                        <label key={architect.id} className="flex items-center gap-2 text-sm">
                                                            <Checkbox
                                                                checked={checked}
                                                                onCheckedChange={(next) =>
                                                                    field.onChange(next ? [...field.value, id] : field.value.filter((value) => value !== id))
                                                                }
                                                            />
                                                            {architect.name}
                                                        </label>
                                                    );
                                                })
                                        )}
                                    </div>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="start_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal Mulai</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="target_hari"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Target (hari)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min={1} max={365} {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <p className="text-xs text-daiku-muted">Deadline dihitung otomatis: tanggal mulai + target hari.</p>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                {reassigning ? 'Simpan Penugasan' : 'Tugaskan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
