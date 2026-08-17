import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { OvertimeRequest, PageProps, PaginatedData, Project } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface OvertimeIndexProps {
    overtimeRequests: PaginatedData<OvertimeRequest>;
    filters: { status?: string };
    projects: Pick<Project, 'id' | 'name'>[];
    canSubmit: boolean;
    canPmDecide: boolean;
    canFinanceDecide: boolean;
}

const STATUS_OPTIONS = ['PENDING', 'APPROVED_PM', 'APPROVED_FINANCE', 'REJECTED'];

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

const requestSchema = z.object({
    project_id: z.string().min(1, 'Proyek wajib dipilih'),
    hours: z.string().min(1, 'Jam wajib diisi').refine((v) => !isNaN(Number(v)) && Number(v) >= 0.5, 'Jam tidak valid'),
    rate_per_hour: z.string().min(1, 'Rate wajib diisi').refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Rate tidak valid'),
    work_date: z.date({ message: 'Tanggal wajib diisi' }),
    reason: z.string().min(1, 'Alasan wajib diisi'),
});

type RequestFormValues = z.infer<typeof requestSchema>;

function RequestOvertimeDialog({ open, onOpenChange, projects }: { open: boolean; onOpenChange: (open: boolean) => void; projects: Pick<Project, 'id' | 'name'>[] }) {
    const form = useForm<RequestFormValues>({
        resolver: zodResolver(requestSchema),
        defaultValues: { project_id: '', hours: '', rate_per_hour: '25000', work_date: new Date(), reason: '' },
    });

    function onSubmit(values: RequestFormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof RequestFormValues, { message });
            });
        };

        router.post(
            route('overtime.store'),
            {
                project_id: Number(values.project_id),
                hours: Number(values.hours),
                rate_per_hour: Number(values.rate_per_hour),
                work_date: format(values.work_date, 'yyyy-MM-dd'),
                reason: values.reason,
            },
            { onError, onSuccess: () => { onOpenChange(false); form.reset(); } },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Ajukan Lembur</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="project_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Proyek</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih proyek" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {projects.map((project) => (
                                                <SelectItem key={project.id} value={String(project.id)}>
                                                    {project.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="hours"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jam Lembur</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0.5" step="0.5" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="rate_per_hour"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Rate/Jam (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="1000" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="work_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Tanggal</FormLabel>
                                    <FormControl>
                                        <DatePicker value={field.value} onChange={field.onChange} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="reason"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Alasan / Keterangan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} />
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
                                Ajukan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}

const decisionSchema = z.object({ note: z.string().optional() });
type DecisionFormValues = z.infer<typeof decisionSchema>;

function DecisionDialog({
    overtime,
    stage,
    decision,
    onOpenChange,
}: {
    overtime: OvertimeRequest;
    stage: 'pm' | 'finance';
    decision: 'approve' | 'reject';
    onOpenChange: (open: boolean) => void;
}) {
    const isReject = decision === 'reject';
    const form = useForm<DecisionFormValues>({
        resolver: zodResolver(isReject ? decisionSchema.extend({ note: z.string().min(1, 'Catatan wajib diisi') }) : decisionSchema),
        defaultValues: { note: '' },
    });

    function onSubmit(values: DecisionFormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof DecisionFormValues, { message });
            });
        };

        const routeName = `overtime.${stage}${decision === 'approve' ? 'Approve' : 'Reject'}`;

        router.post(
            route(routeName, { overtime_request: overtime.id }),
            { decision, note: values.note || null },
            { onError, onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isReject ? 'Tolak' : 'Setujui'} Lembur — {overtime.staff?.name}
                    </DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <p className="text-sm text-daiku-muted">
                            {overtime.hours} jam × {formatRupiah(overtime.rate_per_hour)} = {formatRupiah(overtime.total_amount)}
                            {stage === 'finance' && !isReject && ' — akan dicatat sebagai pengeluaran (EXPENSE).'}
                        </p>
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan {isReject ? '' : '(opsional)'}</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} autoFocus />
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
                            <Button type="submit" variant={isReject ? 'destructive' : 'default'} disabled={form.formState.isSubmitting}>
                                {isReject ? 'Tolak' : 'Setujui'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * PRD §4.5/§6.6 "Alur Pengajuan Lembur" — one role-adaptive page covering
 * all three Week 6 CSV frontend tasks (Tukang's request form, PM's
 * approval queue, Finance's approval queue), same pattern as
 * Design/Quotation Show pages' role-adaptive rendering.
 */
export default function OvertimeIndex({ overtimeRequests, filters, projects, canSubmit, canPmDecide, canFinanceDecide }: OvertimeIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const isFieldStaff = auth.user?.role === 'FIELD_STAFF';

    const [requestOpen, setRequestOpen] = useState(false);
    const [decision, setDecision] = useState<{ overtime: OvertimeRequest; stage: 'pm' | 'finance'; decision: 'approve' | 'reject' } | null>(null);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('overtime.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Lembur' }]}>
            <Head title="Lembur" />

            <PageHeader
                title="Lembur"
                description={
                    isFieldStaff
                        ? 'Pengajuan lembur Anda.'
                        : 'Pengajuan lembur seluruh tukang — approval berurutan PM lalu Finance.'
                }
                actions={
                    canSubmit && (
                        <Button onClick={() => setRequestOpen(true)}>
                            <Plus className="size-4" />
                            Ajukan Lembur
                        </Button>
                    )
                }
            />

            <div className="mb-4">
                <Select
                    value={filters.status ?? 'all'}
                    onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                >
                    <SelectTrigger className="sm:w-56">
                        <SelectValue placeholder="Semua status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua status</SelectItem>
                        {STATUS_OPTIONS.map((status) => (
                            <SelectItem key={status} value={status}>
                                {status.replace(/_/g, ' ')}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="overflow-hidden rounded-lg border border-daiku-border">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            {!isFieldStaff && <th className="p-2 text-left font-medium">Tukang</th>}
                            <th className="p-2 text-left font-medium">Proyek</th>
                            <th className="p-2 text-left font-medium">Tanggal</th>
                            <th className="p-2 text-left font-medium">Jam</th>
                            <th className="p-2 text-right font-medium">Total</th>
                            <th className="p-2 text-left font-medium">Status</th>
                            <th className="w-48 p-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {overtimeRequests.data.length === 0 ? (
                            <tr>
                                <td colSpan={7} className="p-6 text-center text-daiku-muted">
                                    Belum ada pengajuan lembur.
                                </td>
                            </tr>
                        ) : (
                            overtimeRequests.data.map((overtime) => (
                                <tr key={overtime.id} className="border-t border-daiku-border">
                                    {!isFieldStaff && <td className="p-2 font-medium">{overtime.staff?.name ?? '—'}</td>}
                                    <td className="p-2 text-daiku-muted">{overtime.project?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">
                                        {new Date(overtime.work_date).toLocaleDateString('id-ID')}
                                    </td>
                                    <td className="p-2 text-daiku-muted">{overtime.hours} jam</td>
                                    <td className="p-2 text-right font-medium text-daiku-dark">{formatRupiah(overtime.total_amount)}</td>
                                    <td className="p-2">
                                        <StatusChip status={overtime.status} />
                                    </td>
                                    <td className="p-2">
                                        <div className="flex justify-end gap-1">
                                            {canPmDecide && overtime.status === 'PENDING' && (
                                                <>
                                                    <Button variant="outline" size="sm" onClick={() => setDecision({ overtime, stage: 'pm', decision: 'reject' })}>
                                                        Tolak
                                                    </Button>
                                                    <Button size="sm" onClick={() => setDecision({ overtime, stage: 'pm', decision: 'approve' })}>
                                                        Setujui
                                                    </Button>
                                                </>
                                            )}
                                            {canFinanceDecide && overtime.status === 'APPROVED_PM' && (
                                                <>
                                                    <Button variant="outline" size="sm" onClick={() => setDecision({ overtime, stage: 'finance', decision: 'reject' })}>
                                                        Tolak
                                                    </Button>
                                                    <Button size="sm" onClick={() => setDecision({ overtime, stage: 'finance', decision: 'approve' })}>
                                                        Setujui
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {canSubmit && <RequestOvertimeDialog open={requestOpen} onOpenChange={setRequestOpen} projects={projects} />}
            {decision && (
                <DecisionDialog
                    overtime={decision.overtime}
                    stage={decision.stage}
                    decision={decision.decision}
                    onOpenChange={(open) => !open && setDecision(null)}
                />
            )}
        </AppLayout>
    );
}
