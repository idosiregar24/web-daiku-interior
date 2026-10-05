import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SearchInput } from '@/Components/shared/SearchInput';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import {
    INVOICE_TYPE_LABEL,
    InvoiceProofDialog,
    InvoiceRejectDialog,
    InvoiceVerifyDialog,
} from '@/Components/modules/finance/InvoiceDialogs';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import type { BankAccount, Invoice, InvoiceStatus, InvoiceType, PaginatedData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink, FileCheck2, FileDown, ReceiptText } from 'lucide-react';
import { useState } from 'react';

interface InvoiceIndexProps {
    /** `verification` = Finance's queue (MENUNGGU_VERIFIKASI only). */
    mode: 'all' | 'verification';
    invoices: PaginatedData<Invoice>;
    filters: { status?: string; type?: string; search?: string };
    canVerify: boolean;
    canSubmitProof: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

const STATUS_LABEL: Record<InvoiceStatus, string> = {
    DITERBITKAN: 'Diterbitkan',
    MENUNGGU_VERIFIKASI: 'Menunggu Verifikasi',
    TERVERIFIKASI: 'Terverifikasi',
};

type Action = { kind: 'proof' | 'verify' | 'reject'; invoice: Invoice };

/**
 * Sprint 12 decisions #20–#21 — "Invoice": Marketing follows up what it
 * issued (issuing itself happens on the approved RAB), adds the client's
 * payment proof; "Verifikasi Pembayaran": Finance's queue, oldest proof
 * first — verify (books the income) or reject with a reason.
 */
export default function InvoiceIndex({ mode, invoices, filters, canVerify, canSubmitProof, bankAccounts }: InvoiceIndexProps) {
    const [action, setAction] = useState<Action | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const isQueue = mode === 'verification';
    const routeName = isQueue ? 'finance.invoices.verification' : 'finance.invoices.index';

    function applyFilter(next: Partial<InvoiceIndexProps['filters']>) {
        router.get(route(routeName), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    const close = (open: boolean) => !open && setAction(null);

    return (
        <AppLayout>
            <Head title={isQueue ? 'Verifikasi Pembayaran' : 'Invoice'} />

            <PageHeader
                title={isQueue ? 'Verifikasi Pembayaran' : 'Invoice'}
                icon={isQueue ? FileCheck2 : ReceiptText}
                description={
                    isQueue
                        ? 'Invoice yang sudah dilampiri bukti bayar — cek uangnya masuk, lalu verifikasi atau tolak.'
                        : 'Semua invoice yang diterbitkan Marketing: Jasa Survey, Jasa Desain, DP, dan termin.'
                }
            />

            <TableCard
                pagination={invoices}
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <SearchInput
                            placeholder="Cari nomor / klien..."
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') applyFilter({ search: search || undefined });
                            }}
                            onBlur={() => applyFilter({ search: search || undefined })}
                            className="sm:max-w-xs"
                        />
                        {!isQueue && (
                            <Select value={filters.status ?? 'all'} onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}>
                                <SelectTrigger className="sm:w-52">
                                    <SelectValue placeholder="Semua status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua status</SelectItem>
                                    {(Object.keys(STATUS_LABEL) as InvoiceStatus[]).map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {STATUS_LABEL[status]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <Select value={filters.type ?? 'all'} onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}>
                            <SelectTrigger className="sm:w-52">
                                <SelectValue placeholder="Semua jenis" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua jenis</SelectItem>
                                {(Object.keys(INVOICE_TYPE_LABEL) as InvoiceType[]).map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {INVOICE_TYPE_LABEL[type]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                }
            >
                <table className="w-full min-w-[60rem] text-sm">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            <th className="px-4 py-2.5 text-left font-semibold">Nomor</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Klien</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Jenis</th>
                            <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Jatuh Tempo</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                            <th className="w-64 px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.data.length === 0 ? (
                            <tr>
                                <td colSpan={7} className="p-0">
                                    <EmptyState title={isQueue ? 'Tidak ada pembayaran yang menunggu verifikasi.' : 'Belum ada invoice.'} />
                                </td>
                            </tr>
                        ) : (
                            invoices.data.map((invoice) => (
                                <tr key={invoice.id} className="border-t border-daiku-border align-top">
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-daiku-dark">{invoice.number}</p>
                                        <p className="text-xs text-daiku-muted">
                                            {formatDate(invoice.issued_at)} · {invoice.issuer?.name ?? '—'}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3">
                                        {invoice.quotation ? (
                                            <Link href={route('quotations.show', { quotation: invoice.quotation.id })} className="hover:underline">
                                                {invoice.lead?.client_name}
                                            </Link>
                                        ) : (
                                            invoice.lead?.client_name
                                        )}
                                    </td>
                                    <td className="px-4 py-3">{INVOICE_TYPE_LABEL[invoice.type]}</td>
                                    <td className="px-4 py-3 text-right font-medium">{formatRupiah(invoice.amount)}</td>
                                    <td className="px-4 py-3">{formatDate(invoice.due_date)}</td>
                                    <td className="px-4 py-3">
                                        <StatusChip status={invoice.status} label={STATUS_LABEL[invoice.status]} />
                                        {invoice.status === 'TERVERIFIKASI' && (
                                            <p className="mt-1 text-xs text-daiku-muted">
                                                {invoice.bank_account?.label} · {formatDate(invoice.paid_date)}
                                            </p>
                                        )}
                                        {invoice.status === 'DITERBITKAN' && invoice.reject_reason && (
                                            <p className="mt-1 text-xs text-error-ink">
                                                Bukti ditolak {formatDateTime(invoice.rejected_at)}: {invoice.reject_reason}
                                            </p>
                                        )}
                                        {invoice.payment_proof_url && invoice.status !== 'DITERBITKAN' && (
                                            <a
                                                href={invoice.payment_proof_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="mt-1 inline-flex items-center gap-1 text-xs font-medium underline decoration-daiku-yellow underline-offset-4"
                                            >
                                                Bukti bayar
                                                <ExternalLink className="size-3" aria-hidden />
                                            </a>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <Button variant="outline" size="sm" asChild>
                                                <a href={route('finance.invoices.pdf', { invoice: invoice.id })} target="_blank" rel="noopener noreferrer">
                                                    <FileDown className="size-4" />
                                                    Unduh PDF
                                                </a>
                                            </Button>
                                            {canSubmitProof && invoice.status === 'DITERBITKAN' && (
                                                <Button size="sm" variant="outline" onClick={() => setAction({ kind: 'proof', invoice })}>
                                                    Kirim Bukti Bayar
                                                </Button>
                                            )}
                                            {canVerify && invoice.status === 'MENUNGGU_VERIFIKASI' && (
                                                <>
                                                    <Button size="sm" variant="outline" onClick={() => setAction({ kind: 'reject', invoice })}>
                                                        Tolak Pembayaran
                                                    </Button>
                                                    <Button size="sm" onClick={() => setAction({ kind: 'verify', invoice })}>
                                                        Verifikasi Pembayaran
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
            </TableCard>

            {action?.kind === 'proof' && <InvoiceProofDialog open onOpenChange={close} invoice={action.invoice} />}
            {action?.kind === 'verify' && <InvoiceVerifyDialog open onOpenChange={close} invoice={action.invoice} bankAccounts={bankAccounts} />}
            {action?.kind === 'reject' && <InvoiceRejectDialog open onOpenChange={close} invoice={action.invoice} />}
        </AppLayout>
    );
}
