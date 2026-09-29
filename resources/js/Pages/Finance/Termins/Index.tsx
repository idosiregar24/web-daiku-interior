import { formatRupiah } from '@/lib/format';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { EmptyState } from '@/Components/shared/EmptyState';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { TerminCalendar } from '@/Components/modules/finance/TerminCalendar';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, PageProps, PaginatedData, Termin } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { CalendarClock, CalendarDays, FileDown, List } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

type BankAccountOption = Pick<BankAccount, 'id' | 'label'>;

interface TerminIndexProps {
    termins: PaginatedData<Termin>;
    filters: { status?: string };
    calendarTermins: Termin[];
    calendarMonth: string;
    /** Only filled for FINANCE/SUPERADMIN (the "Catat Pembayaran" dialog). */
    bankAccounts: BankAccountOption[];
}

function formatDate(value: string) {
    return new Date(value).toLocaleDateString('id-ID');
}

function today() {
    return format(new Date(), 'yyyy-MM-dd');
}

/** "Dibayar Sebagian" is derived, not a DB status — mirrors Termin::isPartiallyPaid(). */
function isPartiallyPaid(termin: Termin) {
    return Number(termin.dp_amount) + Number(termin.pelunasan) > 0 && Number(termin.sisa_piutang) > 0;
}

/**
 * Mirrors RecordTerminPaymentRequest, plus the row-level rules of
 * TerminService::recordPayment() (amount <= sisa piutang, no DP after
 * pelunasan) — the server re-checks all of them.
 */
function buildPaymentSchema(termin: Termin) {
    const sisa = Number(termin.sisa_piutang);
    const hasPelunasan = Number(termin.pelunasan) > 0;

    return z
        .object({
            type: z.enum(['DP', 'PELUNASAN'], { message: 'Jenis pembayaran wajib dipilih' }),
            amount: z
                .string()
                .min(1, 'Nominal pembayaran wajib diisi')
                .refine((v) => !isNaN(Number(v)), 'Nominal pembayaran harus berupa angka')
                .refine((v) => Number(v) > 0, 'Nominal pembayaran harus lebih dari 0')
                .refine((v) => Number(v) <= sisa, `Nominal pembayaran melebihi sisa piutang (${formatRupiah(sisa)})`),
            bank_account_id: z.string().min(1, 'Rekening penerima wajib dipilih'),
            paid_date: z
                .string()
                .min(1, 'Tanggal pembayaran wajib diisi')
                .refine((v) => v <= today(), 'Tanggal pembayaran tidak boleh di masa depan'),
        })
        .refine((values) => !(values.type === 'DP' && hasPelunasan), {
            path: ['type'],
            message: 'DP tidak bisa dicatat — termin ini sudah menerima pelunasan',
        });
}

type PaymentFormValues = z.infer<ReturnType<typeof buildPaymentSchema>>;

const PAYMENT_FIELDS: (keyof PaymentFormValues)[] = ['type', 'amount', 'bank_account_id', 'paid_date'];

function RecordPaymentDialog({
    termin,
    bankAccounts,
    onClose,
}: {
    termin: Termin;
    bankAccounts: BankAccountOption[];
    onClose: () => void;
}) {
    const form = useForm<PaymentFormValues>({
        resolver: zodResolver(buildPaymentSchema(termin)),
        defaultValues: {
            type: 'PELUNASAN',
            amount: String(Number(termin.sisa_piutang)),
            bank_account_id: termin.bank_account_id ? String(termin.bank_account_id) : '',
            paid_date: today(),
        },
    });

    const dpAllowed = Number(termin.pelunasan) === 0;

    function onSubmit(values: PaymentFormValues) {
        router.post(
            route('finance.termins.recordPayment', { termin: termin.id }),
            {
                type: values.type,
                amount: Number(values.amount),
                bank_account_id: Number(values.bank_account_id),
                paid_date: values.paid_date,
            },
            {
                preserveScroll: true,
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        // `status` (lunas/terkunci) has no input of its own — show it on the amount field.
                        const target = PAYMENT_FIELDS.includes(field as keyof PaymentFormValues)
                            ? (field as keyof PaymentFormValues)
                            : 'amount';
                        form.setError(target, { message });
                    });
                },
                onSuccess: onClose,
            },
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Catat Pembayaran Termin #{termin.termin_number}</DialogTitle>
                    <DialogDescription>
                        {termin.project?.name ?? '—'} · Nominal {formatRupiah(termin.amount)} · Sisa piutang{' '}
                        {formatRupiah(termin.sisa_piutang)}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="type"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Jenis Pembayaran</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih jenis" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value="DP" disabled={!dpAllowed}>
                                                DP (uang muka)
                                            </SelectItem>
                                            <SelectItem value="PELUNASAN">Pelunasan</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="amount"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nominal (Rp)</FormLabel>
                                    <FormControl>
                                        <Input type="number" min="0" step="0.01" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="bank_account_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Rekening Penerima</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih rekening" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {bankAccounts.map((account) => (
                                                <SelectItem key={account.id} value={String(account.id)}>
                                                    {account.label}
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
                            name="paid_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Tanggal Pembayaran</FormLabel>
                                    <FormControl>
                                        <Input type="date" max={today()} {...field} />
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
                                Simpan Pembayaran
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * "Termin list page Finance: status chip + tombol mark as paid"
 * (.claude/plan/sprint-04.md Ido Week 8) — PRD §7.1 "Finance – Termin"
 * row (CEO read, Finance RU). PM sees/schedules termins through the
 * project-scoped Finance tab instead (Projects/Show.tsx) — see
 * ProjectController::show()'s docblock. Calendar tab is PRD §8.4's
 * "Finance: ... termin calendar view" — see TerminCalendar's docblock.
 * DP/Pelunasan/Sisa Piutang + "Catat Pembayaran" = PRD §4.7 "DP +
 * pelunasan, sisa piutang otomatis terhitung" (TerminService::recordPayment()).
 */
export default function TerminIndex({ termins, filters, calendarTermins, calendarMonth, bankAccounts }: TerminIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canMarkPaid = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [payingTermin, setPayingTermin] = useState<Termin | null>(null);
    const [tab, setTab] = useState('list');

    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('finance.termins.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: tab === 'calendar' ? 'Kalender' : 'List' }]}>
            <Head title="Termin" />

            <PageHeader title="Termin" icon={CalendarClock} description="Jadwal pembayaran termin seluruh proyek (selalu Sabtu)." />

            <Tabs value={tab} onValueChange={setTab}>
                <TabsList>
                    <TabsTrigger value="list" className="px-3">
                        <List />
                        List
                    </TabsTrigger>
                    <TabsTrigger value="calendar" className="px-3">
                        <CalendarDays />
                        Kalender
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="list" className="mt-4">
                    <TableCard
                        pagination={termins}
                        toolbar={
                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                <Select
                                    value={filters.status ?? 'all'}
                                    onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                                >
                                    <SelectTrigger className="sm:w-56">
                                        <SelectValue placeholder="Semua status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua status</SelectItem>
                                        <SelectItem value="SCHEDULED">Terjadwal</SelectItem>
                                        <SelectItem value="INVOICED">Invoice Terbit</SelectItem>
                                        <SelectItem value="PAID">Sudah Dibayar</SelectItem>
                                        <SelectItem value="OVERDUE">Terlambat</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        }
                    >
                        <table className="w-full text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Proyek</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Termin</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Rekening</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Jadwal</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">DP</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Pelunasan</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Sisa Piutang</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                    <th className="w-48 px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {termins.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={10} className="p-0">
                                            <EmptyState title="Belum ada termin." />
                                        </td>
                                    </tr>
                                ) : (
                                    termins.data.map((termin) => (
                                        <tr key={termin.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                            <td className="px-4 py-3 font-medium whitespace-nowrap">{termin.project?.name ?? '—'}</td>
                                            <td className="px-4 py-3 text-daiku-muted">
                                                #{termin.termin_number} ({termin.percentage}%)
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">{termin.bank_account?.label ?? '—'}</td>
                                            <td className="px-4 py-3 text-daiku-muted">{formatDate(termin.scheduled_date)}</td>
                                            <td className="px-4 py-3 text-right font-medium text-daiku-dark">{formatRupiah(termin.amount)}</td>
                                            <td className="px-4 py-3 text-right text-daiku-muted">{formatRupiah(termin.dp_amount)}</td>
                                            <td className="px-4 py-3 text-right text-daiku-muted">{formatRupiah(termin.pelunasan)}</td>
                                            <td className="px-4 py-3 text-right font-medium text-daiku-dark">
                                                {formatRupiah(termin.sisa_piutang)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap items-center gap-1">
                                                    <StatusChip status={termin.status} />
                                                    {isPartiallyPaid(termin) && (
                                                        <StatusChip status="PARTIAL" label="Dibayar Sebagian" />
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Button variant="outline" size="icon-sm" asChild>
                                                        <a href={route('finance.termins.pdf', { termin: termin.id })} target="_blank" rel="noopener noreferrer">
                                                            <FileDown className="size-4" />
                                                        </a>
                                                    </Button>
                                                    {canMarkPaid && termin.status !== 'PAID' && (
                                                        <Button variant="outline" size="sm" onClick={() => setPayingTermin(termin)}>
                                                            Catat Pembayaran
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </TableCard>
                </TabsContent>

                <TabsContent value="calendar" className="mt-4">
                    <TerminCalendar termins={calendarTermins} month={calendarMonth} canMarkPaid={canMarkPaid} />
                </TabsContent>
            </Tabs>

            {canMarkPaid && payingTermin && (
                <RecordPaymentDialog
                    key={payingTermin.id}
                    termin={payingTermin}
                    bankAccounts={bankAccounts}
                    onClose={() => setPayingTermin(null)}
                />
            )}
        </AppLayout>
    );
}
