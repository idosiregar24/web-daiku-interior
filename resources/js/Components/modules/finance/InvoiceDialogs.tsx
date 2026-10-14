import { DatePicker } from '@/Components/shared/DatePicker';
import { StatusChip } from '@/Components/shared/StatusChip';
import { PaymentProofGallery, PaymentProofPicker } from '@/Components/modules/finance/PaymentProofPhotos';
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
import { cn } from '@/lib/utils';
import type { BankAccount, Invoice, InvoiceType } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Banknote, ExternalLink } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
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

type InvoiceRef = Pick<Invoice, 'id' | 'number' | 'amount'> &
    Partial<Pick<Invoice, 'payment_label' | 'status_label' | 'proof_attempt'>> & { lead?: { client_name: string } };

/**
 * Sprint 23 K12b — an invoice's status chip: MENUNGGU_VERIFIKASI reads
 * "Menunggu Konfirmasi Pembayaran DP · Proyek Kopi Oz · Finance" (the
 * sentence is built once server-side — Invoice::confirmationLabel(), sent
 * as `status_label`), the others the invoice domain's wording + holder.
 */
export function InvoiceStatusChip({ invoice, className }: { invoice: Pick<Invoice, 'status'> & Partial<Pick<Invoice, 'status_label'>>; className?: string }) {
    return <StatusChip domain="invoice" status={invoice.status} label={invoice.status_label ?? undefined} className={className} />;
}

/**
 * Sprint 23 K12 — what the invoice bills: "DP", "Termin 2 dari 4 · 30%",
 * "Pelunasan", "Jasa Desain"… (Invoice::paymentTypeLabel()) + the project
 * (or client) underneath.
 */
export function InvoicePaymentType({
    invoice,
    showSubject = true,
}: {
    invoice: Pick<Invoice, 'type'> & Partial<Pick<Invoice, 'payment_type_label' | 'project' | 'lead'>>;
    showSubject?: boolean;
}) {
    return (
        <span className="inline-flex flex-col items-start gap-0.5">
            <StatusChip status={invoice.type} tone="neutral" label={invoice.payment_type_label ?? INVOICE_TYPE_LABEL[invoice.type]} className="text-foreground" />
            {showSubject && (invoice.project?.name || invoice.lead?.client_name) && (
                <span className="text-xs text-daiku-muted">{invoice.project?.name ?? invoice.lead?.client_name}</span>
            )}
        </span>
    );
}

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
            {invoice.payment_label ? ` — ${invoice.payment_label}` : invoice.lead && ` — ${invoice.lead.client_name}`} · {formatRupiah(invoice.amount)}
        </>
    );
}

// ── Terbitkan (Marketing — approved service RAB or project termin) ───────

const issueSchema = z.object({ due_date: z.date({ message: 'Tanggal jatuh tempo wajib diisi' }) });

export function IssueInvoiceDialog({
    open,
    onOpenChange,
    action,
    label,
    amount,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** `quotations.invoices.store` (service RAB) or `finance.termins.invoices.store` (project termin). */
    action: string;
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
            description={`Tagihan sebesar ${formatRupiah(amount)}. PDF-nya bisa dikirim ke klien setelah terbit.`}
            form={form}
            onSubmit={(values) =>
                post(action, { due_date: format(values.due_date, 'yyyy-MM-dd') }, form, () => onOpenChange(false))
            }
            submitLabel="Terbitkan Invoice"
        >
            <FormField
                control={form.control}
                name="due_date"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel required>Jatuh Tempo</FormLabel>
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

// ── Tandai klien sudah bayar (Marketing / Finance) ───────────────────────

// Mirrors SubmitInvoiceProofRequest (Sprint 19 Sub 01): both optional, the link http/https only.
const proofSchema = z.object({
    payment_proof_url: z
        .string()
        .trim()
        .max(500, 'Link bukti bayar maksimal 500 karakter')
        .refine((v) => v === '' || /^https?:\/\/\S+$/i.test(v), 'Link bukti bayar harus berupa URL http/https'),
    payment_note: z.string().trim().max(500, 'Catatan pembayaran maksimal 500 karakter'),
});

export function InvoiceProofDialog({ open, onOpenChange, invoice }: { open: boolean; onOpenChange: (open: boolean) => void; invoice: InvoiceRef }) {
    const form = useForm<z.infer<typeof proofSchema>>({
        resolver: zodResolver(proofSchema),
        defaultValues: { payment_proof_url: '', payment_note: '' },
    });
    // Sprint 23 K12d — optional proof photos (files, so outside the Zod values).
    const [photos, setPhotos] = useState<File[]>([]);
    const [photoError, setPhotoError] = useState<string | undefined>();

    useEffect(() => {
        if (open) {
            form.reset({ payment_proof_url: '', payment_note: '' });
            setPhotos([]);
            setPhotoError(undefined);
        }
    }, [open]);

    function submit(values: z.infer<typeof proofSchema>) {
        setPhotoError(undefined);
        // Files make Inertia send multipart/form-data.
        router.post(
            route('finance.invoices.proof', { invoice: invoice.id }),
            { ...values, payment_photos: photos },
            {
                preserveScroll: true,
                forceFormData: photos.length > 0,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => {
                        if (field.startsWith('payment_photos')) {
                            setPhotoError(message);
                        } else {
                            form.setError(field as Path<z.infer<typeof proofSchema>>, { message });
                        }
                    }),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <DialogShell
            open={open}
            onOpenChange={onOpenChange}
            title="Tandai Klien Sudah Bayar"
            description={<>{describe(invoice)} — Finance mencocokkannya dengan mutasi rekening sebelum konfirmasi.</>}
            form={form}
            onSubmit={submit}
            submitLabel="Kirim ke Finance"
        >
            <PaymentProofPicker value={photos} onChange={setPhotos} error={photoError} />
            <FormField
                control={form.control}
                name="payment_proof_url"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Link bukti bayar</FormLabel>
                        <FormControl>
                            <Input {...field} placeholder="https://drive.google.com/..." />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <FormField
                control={form.control}
                name="payment_note"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Catatan pembayaran</FormLabel>
                        <FormControl>
                            <Textarea {...field} rows={2} placeholder="mis. Transfer BCA a.n. Budi, 12 Okt" />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </DialogShell>
    );
}

/**
 * Sprint 19 Sub 01 — what came with "Tandai Klien Sudah Bayar": the proof
 * link (or a reminder that there is none, so Finance checks the bank
 * statement) and the payment note. Shown on Finance's list & verify dialog.
 */
export function InvoicePaymentInfo({
    invoice,
    showMissingLink = true,
    compactPhotos = false,
    className,
}: {
    invoice: Partial<Pick<Invoice, 'payment_proof_url' | 'payment_note' | 'payment_proofs' | 'proof_attempt'>>;
    /** false once verified — the bank statement was already checked. */
    showMissingLink?: boolean;
    /** Small photo thumbnails (list rows). */
    compactPhotos?: boolean;
    className?: string;
}) {
    const hasCurrentPhotos = (invoice.payment_proofs ?? []).some((proof) => proof.attempt === invoice.proof_attempt);

    return (
        <div className={cn('space-y-1 text-xs', className)}>
            {/* Sprint 23 K12d — the photos Marketing uploaded (current submission + earlier ones as history). */}
            <PaymentProofGallery proofs={invoice.payment_proofs} currentAttempt={invoice.proof_attempt} compact={compactPhotos} />
            {invoice.payment_proof_url ? (
                <a
                    href={invoice.payment_proof_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 font-medium underline decoration-daiku-yellow underline-offset-4"
                >
                    Link bukti bayar
                    <ExternalLink className="size-3" aria-hidden />
                </a>
            ) : (
                showMissingLink &&
                !hasCurrentPhotos && <p className="text-warning-ink">Tidak ada link/foto bukti — cocokkan dengan mutasi rekening.</p>
            )}
            {invoice.payment_note && (
                <p className="whitespace-pre-line text-daiku-muted">
                    <span className="font-medium text-foreground">Catatan:</span> {invoice.payment_note}
                </p>
            )}
        </div>
    );
}

/**
 * Sprint 17 Sub 07 — "Tandai Klien Sudah Bayar" (Sprint 19 Sub 01 name)
 * right where an invoice is shown (RAB page, project termins & documents),
 * not only on the Invoice menu. Renders nothing unless the invoice still
 * waits for the client's payment; a confirmation Finance sent back shows
 * its reason above the button.
 */
export function InvoiceProofButton({
    invoice,
    canSubmit,
}: {
    invoice: InvoiceRef & Pick<Invoice, 'status'> & Partial<Pick<Invoice, 'reject_reason'>>;
    canSubmit: boolean;
}) {
    const [open, setOpen] = useState(false);

    if (!canSubmit || invoice.status !== 'DITERBITKAN') {
        return null;
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            {invoice.reject_reason && <span className="text-xs text-error-ink">Ditolak Finance: {invoice.reject_reason}</span>}
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                <Banknote className="size-4" />
                Tandai Klien Sudah Bayar
            </Button>
            {open && <InvoiceProofDialog open onOpenChange={setOpen} invoice={invoice} />}
        </span>
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
    invoice: InvoiceRef & Partial<Pick<Invoice, 'payment_proof_url' | 'payment_note' | 'payment_proofs'>>;
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
            // Sprint 23 K12b — "Menunggu Konfirmasi Pembayaran DP · Proyek Kopi Oz".
            title={invoice.status_label ?? 'Verifikasi Pembayaran'}
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
            <InvoicePaymentInfo invoice={invoice} className="rounded-lg bg-daiku-gray px-3.5 py-3" />
            <FormField
                control={form.control}
                name="bank_account_id"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel required>Rekening penerima</FormLabel>
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
                        <FormLabel required>Tanggal uang masuk</FormLabel>
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
                        <FormLabel required>Alasan</FormLabel>
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
