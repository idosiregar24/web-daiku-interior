import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Button } from '@/Components/ui/button';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import {
    QuotationDecisionDialog,
    type QuotationDecisionGate,
} from '@/Components/modules/quotation/QuotationDecisionDialog';
import { QuotationExpiryNotice } from '@/Components/modules/quotation/QuotationExpiryNotice';
import { QuotationRevisionHistory } from '@/Components/modules/quotation/QuotationRevisionHistory';
import AppLayout from '@/Layouts/AppLayout';
import { UnitSelect } from '@/Components/shared/UnitSelect';
import { parseQty, quantityField } from '@/lib/quantity';
import type { Quotation, QuotationApproval, QuotationRevisionReason, UnitOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    Calculator,
    FileClock,
    FileDown,
    FileText,
    Handshake,
    History,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

interface QuotationShowProps {
    quotation: Quotation & { lead: { id: number; client_name: string } };
    canManage: boolean;
    canCeoDecide: boolean;
    canPmDecide: boolean;
    /** CEO/Marketing — records the client's rejection (`quotations.clientReject`) and confirms the deal on the lead. */
    canClientDecide: boolean;
    /** QuotationService::VALIDITY_DAYS */
    validityDays: number;
    /** Active Master Satuan units for the RAB builder (empty once the quotation left DRAFT). */
    units: UnitOption[];
}

const itemSchema = z.object({
    description: z.string().min(1, 'Deskripsi wajib diisi'),
    qty: quantityField('Qty'),
    unit_id: z.string().min(1, 'Satuan wajib dipilih'),
    unit_price: z
        .string()
        .min(1, 'Harga wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Harga tidak valid'),
});

const schema = z.object({
    items: z.array(itemSchema).min(1, 'Tambahkan minimal satu item RAB'),
});

type FormValues = z.infer<typeof schema>;

const REVISION_REASON_TEXT: Record<QuotationRevisionReason, string> = {
    CEO_REJECTED: 'ditolak CEO',
    PM_REJECTED: 'ditolak PM',
    CLIENT_REJECTED: 'ditolak klien',
};

function approverLabel(approval: QuotationApproval): string {
    const name = approval.approver?.name ?? '—';

    return approval.approver_role === 'CLIENT' ? `Klien (dicatat oleh ${name})` : `${name} (${approval.approver_role})`;
}

/**
 * RAB builder — add/remove item + auto-calculated totals
 * (.claude/plan/sprint-02.md Week 4, Ido task 5). Reached from the Design
 * page's Client ACC trigger. Only editable while DRAFT (see
 * QuotationService::replaceItems()). CEO→PM approval (Sprint 3 Week 5),
 * then — once SENT_TO_CLIENT — the client's side (Sprint 9): validity
 * period, "Klien Menolak" (back to DRAFT as a new version) and the
 * revision history of every rejected version.
 */
export default function QuotationShow({
    quotation,
    canManage,
    canCeoDecide,
    canPmDecide,
    canClientDecide,
    validityDays,
    units,
}: QuotationShowProps) {
    const [decisionDialog, setDecisionDialog] = useState<{
        role: QuotationDecisionGate;
        decision: 'approve' | 'reject';
    } | null>(null);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            items: (quotation.items ?? []).map((item) => ({
                description: item.description,
                qty: String(item.qty),
                unit_id: String(item.unit_id),
                unit_price: String(item.unit_price),
            })),
        },
    });

    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'items' });
    const watchedItems = form.watch('items');

    const total = watchedItems.reduce((sum, item) => {
        const qty = parseQty(item.qty) || 0;
        const price = Number(item.unit_price) || 0;

        return sum + qty * price;
    }, 0);

    const isDraft = quotation.status === 'DRAFT';
    const editable = canManage && isDraft;
    const isSentToClient = quotation.status === 'SENT_TO_CLIENT';
    const revisions = quotation.revisions ?? [];
    // The version the Estimator is revising right now, if the last one was rejected.
    const lastRevision = isDraft ? revisions.find((revision) => revision.version === quotation.version - 1) : undefined;

    function onError(errors: Record<string, string>) {
        Object.entries(errors).forEach(([field, message]) => {
            form.setError(field as keyof FormValues, { message });
        });
    }

    function onSave(values: FormValues) {
        router.put(
            route('quotations.items.update', { quotation: quotation.id }),
            {
                items: values.items.map((item) => ({
                    description: item.description,
                    qty: parseQty(item.qty),
                    unit_id: Number(item.unit_id),
                    unit_price: Number(item.unit_price),
                })),
            },
            { onError },
        );
    }

    function onSubmitForReview() {
        router.post(route('quotations.submit', { quotation: quotation.id }), {}, { onError });
    }

    return (
        <AppLayout
            breadcrumbs={[{ label: quotation.lead.client_name }, { label: `Versi ${quotation.version}` }]}
        >
            <Head title={`Quotation — ${quotation.lead.client_name}`} />

            <PageHeader
                title={`Quotation: ${quotation.lead.client_name}`}
                icon={FileText}
                description={
                    quotation.valid_until
                        ? `Versi ${quotation.version} · berlaku sampai ${formatDate(quotation.valid_until)}.`
                        : `Versi ${quotation.version} · dibuat dari desain yang sudah di-ACC klien.`
                }
                actions={
                    <div className="flex items-center gap-2">
                        <StatusChip status={quotation.status} />
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('quotations.pdf', { quotation: quotation.id })} target="_blank" rel="noopener noreferrer">
                                <FileDown className="size-4" />
                                Export PDF
                            </a>
                        </Button>
                    </div>
                }
            />

            <QuotationExpiryNotice quotation={quotation} className="mb-6" />

            {lastRevision && (
                <Notice tone="info" className="mb-6">
                    Versi {quotation.version} adalah revisi: versi {lastRevision.version}{' '}
                    {REVISION_REASON_TEXT[lastRevision.reason]}
                    {lastRevision.note ? ` — “${lastRevision.note}”` : ''}. Perbarui RAB lalu submit ulang ke CEO.
                </Notice>
            )}

            <SectionCard title="Rincian RAB" icon={Calculator} description="Item pekerjaan, volume, dan harga satuan penawaran.">
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSave)} className="space-y-4">
                        <TableCard>
                            <table className="w-full text-sm">
                                <thead className={TABLE_HEAD_CLASS}>
                                    <tr>
                                        <th className="px-3 py-2.5 text-left font-semibold">Deskripsi</th>
                                        <th className="w-24 px-4 py-2.5 text-left font-semibold">Qty</th>
                                        <th className="w-28 px-4 py-2.5 text-left font-semibold">Satuan</th>
                                        <th className="w-40 px-4 py-2.5 text-left font-semibold">Harga Satuan</th>
                                        <th className="w-40 px-4 py-2.5 text-right font-semibold">Total</th>
                                        {editable && <th className="w-12 px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {fields.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="p-0">
                                                <EmptyState title="Belum ada item RAB." />
                                            </td>
                                        </tr>
                                    ) : (
                                        fields.map((item, index) => {
                                            const qty = parseQty(watchedItems[index]?.qty ?? '') || 0;
                                            const price = Number(watchedItems[index]?.unit_price) || 0;

                                            return (
                                                <tr key={item.id} className="border-t border-daiku-border align-top">
                                                    <td className="px-3 py-2">
                                                        <FormField
                                                            control={form.control}
                                                            name={`items.${index}.description`}
                                                            render={({ field }) => (
                                                                <FormItem>
                                                                    <FormControl>
                                                                        <Input {...field} disabled={!editable} placeholder="mis. Kitchen Set Custom" />
                                                                    </FormControl>
                                                                    <FormMessage />
                                                                </FormItem>
                                                            )}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <FormField
                                                            control={form.control}
                                                            name={`items.${index}.qty`}
                                                            render={({ field }) => (
                                                                <FormItem>
                                                                    <FormControl>
                                                                        <Input type="number" min="0.01" step="0.01" inputMode="decimal" {...field} disabled={!editable} />
                                                                    </FormControl>
                                                                    <FormMessage />
                                                                </FormItem>
                                                            )}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <FormField
                                                            control={form.control}
                                                            name={`items.${index}.unit_id`}
                                                            render={({ field }) => (
                                                                <FormItem>
                                                                    <FormControl>
                                                                        <UnitSelect
                                                                            value={field.value}
                                                                            onChange={field.onChange}
                                                                            units={units}
                                                                            current={quotation.items?.find((line) => String(line.unit_id) === field.value)?.unit}
                                                                            disabled={!editable}
                                                                        />
                                                                    </FormControl>
                                                                    <FormMessage />
                                                                </FormItem>
                                                            )}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <FormField
                                                            control={form.control}
                                                            name={`items.${index}.unit_price`}
                                                            render={({ field }) => (
                                                                <FormItem>
                                                                    <FormControl>
                                                                        <Input type="number" min="0" step="0.01" {...field} disabled={!editable} />
                                                                    </FormControl>
                                                                    <FormMessage />
                                                                </FormItem>
                                                            )}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-medium text-daiku-dark">
                                                        {formatRupiah(qty * price)}
                                                    </td>
                                                    {editable && (
                                                        <td className="px-3 py-2">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                onClick={() => remove(index)}
                                                            >
                                                                <Trash2 className="size-4 text-error-ink" />
                                                            </Button>
                                                        </td>
                                                    )}
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-border bg-daiku-gray/70">
                                        <td colSpan={4} className="px-4 py-3 text-right font-semibold">
                                            Total
                                        </td>
                                        <td className="px-3 py-2 text-right font-semibold text-daiku-dark">
                                            {formatRupiah(total)}
                                        </td>
                                        {editable && <td />}
                                    </tr>
                                </tfoot>
                            </table>
                        </TableCard>

                        {form.formState.errors.items?.message && (
                            <p className="text-sm text-destructive">{form.formState.errors.items.message}</p>
                        )}

                        {editable && (
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => append({ description: '', qty: '1', unit_id: '', unit_price: '0' })}
                                >
                                    <Plus className="size-4" />
                                    Tambah Item
                                </Button>

                                <div className="flex gap-2">
                                    <Button type="submit" variant="outline" disabled={form.formState.isSubmitting}>
                                        Simpan RAB
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={onSubmitForReview}
                                        disabled={fields.length === 0}
                                    >
                                        Submit ke CEO
                                    </Button>
                                </div>
                            </div>
                        )}

                        {!isDraft && (
                            <p className="text-sm text-daiku-muted">
                                Quotation sudah disubmit — item RAB tidak bisa diubah lagi.
                            </p>
                        )}
                    </form>
                </Form>
            </SectionCard>

            {(canCeoDecide || canPmDecide) && (quotation.status === 'SUBMITTED' || quotation.status === 'CEO_REVIEW') && (
                <SectionCard title="Approval" icon={BadgeCheck} className="mt-6">
                    {canCeoDecide && quotation.status === 'SUBMITTED' && (
                        <div className="flex items-center gap-2">
                            <p className="flex-1 text-sm text-daiku-muted">Menunggu review CEO.</p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setDecisionDialog({ role: 'CEO', decision: 'reject' })}
                            >
                                Tolak
                            </Button>
                            <Button size="sm" onClick={() => setDecisionDialog({ role: 'CEO', decision: 'approve' })}>
                                Setujui (CEO)
                            </Button>
                        </div>
                    )}
                    {canPmDecide && quotation.status === 'CEO_REVIEW' && (
                        <div className="flex items-center gap-2">
                            <p className="flex-1 text-sm text-daiku-muted">CEO sudah menyetujui — menunggu review PM.</p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setDecisionDialog({ role: 'PM', decision: 'reject' })}
                            >
                                Tolak
                            </Button>
                            <Button size="sm" onClick={() => setDecisionDialog({ role: 'PM', decision: 'approve' })}>
                                Setujui (PM)
                            </Button>
                        </div>
                    )}
                    {!canCeoDecide && quotation.status === 'SUBMITTED' && (
                        <p className="text-sm text-daiku-muted">Menunggu review CEO.</p>
                    )}
                    {!canPmDecide && quotation.status === 'CEO_REVIEW' && (
                        <p className="text-sm text-daiku-muted">CEO sudah menyetujui — menunggu review PM.</p>
                    )}
                </SectionCard>
            )}

            {isSentToClient && (
                <SectionCard
                    title="Keputusan Klien"
                    icon={Handshake}
                    description={`Penawaran berlaku ${validityDays} hari sejak disetujui PM.`}
                    className="mt-6"
                >
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <p className="flex-1 text-sm text-daiku-muted">
                            Disetujui CEO &amp; PM dan dikirim ke klien — berlaku sampai{' '}
                            <span className="font-medium text-foreground">{formatDate(quotation.valid_until)}</span>.{' '}
                            {canClientDecide
                                ? 'Klien setuju? Konfirmasi deal dari halaman lead. Klien minta revisi? Catat penolakannya.'
                                : 'Menunggu keputusan klien.'}
                        </p>
                        {canClientDecide && (
                            <div className="flex shrink-0 gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setDecisionDialog({ role: 'CLIENT', decision: 'reject' })}
                                >
                                    Klien Menolak
                                </Button>
                                <Button size="sm" asChild>
                                    <Link href={route('crm.leads.show', { lead: quotation.lead.id })}>
                                        Konfirmasi Deal
                                        <ArrowRight className="size-3.5" aria-hidden />
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>
                </SectionCard>
            )}

            {quotation.approvals && quotation.approvals.length > 0 && (
                <SectionCard title="Riwayat Approval" icon={History} className="mt-6" contentClassName="space-y-3">
                    {quotation.approvals.map((approval) => (
                        <div key={approval.id} className="flex items-start justify-between gap-4 border-b border-border pb-3 last:border-0 last:pb-0">
                            <div>
                                <p className="text-sm font-medium text-daiku-dark">{approverLabel(approval)}</p>
                                {approval.note && <p className="text-sm text-daiku-muted">{approval.note}</p>}
                                <p className="text-xs text-daiku-muted">
                                    Versi {approval.version} · {formatDateTime(approval.created_at)}
                                </p>
                            </div>
                            <StatusChip status={approval.status} />
                        </div>
                    ))}
                </SectionCard>
            )}

            {revisions.length > 0 && (
                <SectionCard
                    title="Riwayat Revisi"
                    icon={FileClock}
                    description="Versi yang ditolak beserta RAB-nya saat itu, dibandingkan dengan versi saat ini."
                    className="mt-6"
                    flush
                >
                    <QuotationRevisionHistory
                        revisions={revisions}
                        currentTotal={quotation.total_amount}
                        currentVersion={quotation.version}
                    />
                </SectionCard>
            )}

            {decisionDialog && (
                <QuotationDecisionDialog
                    open={!!decisionDialog}
                    onOpenChange={(open) => !open && setDecisionDialog(null)}
                    quotation={quotation}
                    role={decisionDialog.role}
                    decision={decisionDialog.decision}
                    validityDays={validityDays}
                />
            )}
        </AppLayout>
    );
}
