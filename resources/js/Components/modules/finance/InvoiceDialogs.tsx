import { DatePicker } from '@/Components/shared/DatePicker';
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
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import type { BankAccount, Invoice, InvoiceType } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { type ReactNode, useEffect } from 'react';
import { type FieldValues, type Path, type UseFormReturn, useForm } from 'react-hook-form';
import { z } from 'zod';

/** App\Enums\InvoiceType::label(). */
export const INVOICE_TYPE_LABEL: Record<InvoiceType, string> = {
    JASA_SURVEY: 'Jasa Survey',
    JASA_DESAIN: 'Jasa Desain',
    DP: 'DP',
    TERMIN: 'Termin',
    PELUNASAN: 'Pelunasan',
    TAMBAHAN: 'Pekerjaan Tambahan',
};

type InvoiceRef = Pick<Invoice, 'id' | 'number' | 'amount'> & { lead?: { client_name: string } };

interface ShellProps<T extends FieldValues> {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    form: UseFormReturn<T>;
    onSubmit: (values: T) => void;
    submitLabel: string;
    destructive?: boolean;
    children: ReactNode;
}

function DialogShell<T extends FieldValues>({ open, onOpenChange, title, description, form, onSubmit, submitLabel, destructive, children }: ShellProps<T>) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {children}
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant={destructive ? 'destructive' : 'default'} disabled={form.formState.isSubmitting}>
                                {submitLabel}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function post<T extends FieldValues>(url: string, data: Record<string, string | number>, form: UseFormReturn<T>, close: () => void) {
    router.post(url, data, {
        preserveScroll: true,
        onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError(field as Path<T>, { message })),
        onSuccess: close,
    });
}

function describe(invoice: InvoiceRef) {
    return (
        <>
            Invoice <span className="font-medium text-foreground">{invoice.number}</span>
            {invoice.lead && ` — ${invoice.lead.client_name}`} · {formatRupiah(invoice.amount)}
        </>
    );
}

// ── Terbitkan (Marketing, from an approved service RAB) ──────────────────

const issueSchema = z.object({ due_date: z.date({ message: 'Tanggal jatuh tempo wajib diisi' }) });

export function IssueInvoiceDialog({
    open,
    onOpenChange,
    quotationId,
    label,
    amount,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quotationId: number;
    label: string;
    amount: string;
}) {
    const form = useForm<z.infer<typeof issueSchema>>({ resolver: zodResolver(issueSchema) });

    useEffect(() => {
        if (open) form.reset({ due_date: new Date(Date.now() + 3 * 86_400_000) });
    }, [open]);

    return (
        <DialogShell
            open={open}
            onOpenChange={onOpenChange}
            title={`Terbitkan Invoice ${label}`}
            description={`Tagihan 100% sebesar ${formatRupiah(amount)}. PDF-nya bisa dikirim ke klien setelah terbit.`}
            form={form}
            onSubmit={(values) =>
                post(route('quotations.invoices.store', { quotation: quotationId }), { due_date: format(values.due_date, 'yyyy-MM-dd') }, form, () =>
                    onOpenChange(false),
                )
            }
            submitLabel="Terbitkan Invoice"
        >
            <FormField
                control={form.control}
                name="due_date"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Jatuh Tempo</FormLabel>
                        <FormControl>
                            <DatePicker value={field.value} onChange={field.onChange} />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </DialogShell>
    );
}

// ── Bukti bayar (Marketing / Finance) ────────────────────────────────────

// Mirrors SubmitInvoiceProofRequest (http/https only).
const proofSchema = z.object({
    payment_proof_url: z
        .string()
        .trim()
        .min(1, 'Link bukti bayar wajib diisi')
        .max(500)
        .refine((v) => /^https?:\/\/\S+$/i.test(v), 'Link bukti bayar harus berupa URL http/https'),
});

export function InvoiceProofDialog({ open, onOpenChange, invoice }: { open: boolean; onOpenChange: (open: boolean) => void; invoice: InvoiceRef }) {
    const form = useForm<z.infer<typeof proofSchema>>({ resolver: zodResolver(proofSchema), defaultValues: { payment_proof_url: '' } });

    useEffect(() => {
        if (open) form.reset({ payment_proof_url: '' });
    }, [open]);

    return (
        <DialogShell
            open={open}
            onOpenChange={onOpenChange}
            title="Kirim Bukti Bayar"
            description={describe(invoice)}
            form={form}
            onSubmit={(values) => post(route('finance.invoices.proof', { invoice: invoice.id }), values, form, () => onOpenChange(false))}
            submitLabel="Kirim ke Finance"
        >
            <FormField
                control={form.control}
                name="payment_proof_url"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Link bukti transfer</FormLabel>
                        <FormControl>
                            <Input {...field} placeholder="https://drive.google.com/..." autoFocus />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </DialogShell>
    );
}

// ── Verifikasi (Finance) ─────────────────────────────────────────────────

const verifySchema = z.object({
    bank_account_id: z.string().min(1, 'Rekening penerima wajib dipilih'),
    paid_date: z.date({ message: 'Tanggal pembayaran wajib diisi' }),
});

export function InvoiceVerifyDialog({
    open,
    onOpenChange,
    invoice,
    bankAccounts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: InvoiceRef;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}) {
    const form = useForm<z.infer<typeof verifySchema>>({ resolver: zodResolver(verifySchema), defaultValues: { bank_account_id: '' } });

    useEffect(() => {
        if (open) form.reset({ bank_account_id: '', paid_date: new Date() });
    }, [open]);

    return (
        <DialogShell
            open={open}
            onOpenChange={onOpenChange}
            title="Verifikasi Pembayaran"
            description={<>{describe(invoice)} — dicatat sebagai pemasukan di rekening yang dipilih.</>}
            form={form}
            onSubmit={(values) =>
                post(
                    route('finance.invoices.verify', { invoice: invoice.id }),
                    { bank_account_id: Number(values.bank_account_id), paid_date: format(values.paid_date, 'yyyy-MM-dd') },
                    form,
                    () => onOpenChange(false),
                )
            }
            submitLabel="Verifikasi"
        >
            <FormField
                control={form.control}
                name="bank_account_id"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Rekening penerima</FormLabel>
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
                        <FormLabel>Tanggal uang masuk</FormLabel>
                        <FormControl>
                            <DatePicker value={field.value} onChange={field.onChange} />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </DialogShell>
    );
}

// ── Tolak (Finance) ──────────────────────────────────────────────────────

const rejectSchema = z.object({ reason: z.string().trim().min(1, 'Alasan penolakan wajib diisi').max(1000) });

export function InvoiceRejectDialog({ open, onOpenChange, invoice }: { open: boolean; onOpenChange: (open: boolean) => void; invoice: InvoiceRef }) {
    const form = useForm<z.infer<typeof rejectSchema>>({ resolver: zodResolver(rejectSchema), defaultValues: { reason: '' } });

    useEffect(() => {
        if (open) form.reset({ reason: '' });
    }, [open]);

    return (
        <DialogShell
            open={open}
            onOpenChange={onOpenChange}
            title="Tolak Bukti Bayar"
            description={<>{describe(invoice)} — kembali ke Marketing untuk dicek ulang.</>}
            form={form}
            onSubmit={(values) => post(route('finance.invoices.reject', { invoice: invoice.id }), values, form, () => onOpenChange(false))}
            submitLabel="Tolak"
            destructive
        >
            <FormField
                control={form.control}
                name="reason"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Alasan</FormLabel>
                        <FormControl>
                            <Textarea {...field} rows={3} autoFocus placeholder="mis. Dana belum masuk ke rekening BCA per hari ini." />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </DialogShell>
    );
}
