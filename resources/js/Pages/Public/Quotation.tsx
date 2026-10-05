import { Notice } from '@/Components/shared/Notice';
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
import { Label } from '@/Components/ui/label';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate, formatDateTime, formatQty, formatRupiah } from '@/lib/format';
import type { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

/** App\Http\Resources\PublicQuotationResource — the whitelist the client may see. */
interface PublicQuotation {
    company: { name: string; address: string | null; phone: string | null; email: string | null };
    client: { name: string; address: string | null };
    type: string;
    typeLabel: string;
    number: string;
    version: number;
    sentAt: string | null;
    validUntil: string | null;
    sections: {
        name: string;
        subtotal: number;
        items: {
            description: string;
            dimLength: number | null;
            dimWidthHeight: number | null;
            qty: number;
            unit: string | null;
            unitPrice: number;
            total: number;
        }[];
    }[];
    itemsTotal: number;
    discount: number;
    roundedTotal: number | null;
    total: number;
    paymentTerms: {
        sequence: number;
        label: string;
        percentage: number;
        amount: number;
        trigger: string;
        dueDate: string | null;
        milestone: string | null;
    }[];
    approvedAt: string | null;
}

/** QuotationService::publicState(). */
type PublicState = 'open' | 'approved' | 'outdated' | 'expired' | 'unavailable';

interface PublicQuotationProps {
    token: string;
    state: PublicState;
    quotation: PublicQuotation;
}

const STATE_NOTICE: Record<Exclude<PublicState, 'open' | 'approved'>, string> = {
    outdated: 'Penawaran ini sudah diperbarui. Silakan minta link penawaran terbaru kepada Marketing kami.',
    expired: 'Masa berlaku penawaran ini sudah habis. Silakan hubungi Marketing kami untuk penawaran terbaru.',
    unavailable: 'Penawaran ini sedang tidak berlaku. Silakan hubungi Marketing kami.',
};

function sectionLetter(index: number): string {
    return index < 26 ? String.fromCharCode(65 + index) : String(index + 1);
}

/**
 * Sprint 12 decisions #13–#14 — the client's offer, opened from the link
 * without logging in: the RAB as on the PDF, its payment scheme, and one
 * action, "Setujui Penawaran", confirmed with a mandatory tick. Revisions
 * go through WhatsApp with Marketing, so there is no reject button.
 */
export default function PublicQuotationPage({ token, state, quotation }: PublicQuotationProps) {
    const { flash } = usePage<PageProps>().props;
    const [confirmOpen, setConfirmOpen] = useState(false);
    const form = useForm<{ agree: boolean }>({ agree: false });

    function approve() {
        form.post(route('public.quotation.approve', token), {
            preserveScroll: true,
            onSuccess: () => setConfirmOpen(false),
        });
    }

    return (
        <PublicLayout>
            <Head title={`${quotation.typeLabel} — ${quotation.client.name}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <article className="overflow-hidden rounded-2xl bg-card shadow-xl shadow-daiku-dark/5 ring-1 ring-daiku-border">
                <div className="border-b-2 border-daiku-yellow px-5 py-6 sm:px-8">
                    <p className="text-xs font-semibold tracking-wider text-daiku-muted uppercase">Surat Penawaran</p>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-daiku-dark">{quotation.typeLabel}</h1>
                    <dl className="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-daiku-muted">Kepada</dt>
                            <dd className="font-medium text-daiku-dark">{quotation.client.name}</dd>
                            {quotation.client.address && <dd className="whitespace-pre-line text-daiku-muted">{quotation.client.address}</dd>}
                        </div>
                        <div>
                            <dt className="text-daiku-muted">Nomor</dt>
                            <dd className="font-medium text-daiku-dark">
                                {quotation.number} · versi {quotation.version}
                            </dd>
                            <dd className="text-daiku-muted">
                                {quotation.sentAt && `Dikirim ${formatDate(quotation.sentAt)}`}
                                {quotation.validUntil && ` · berlaku sampai ${formatDate(quotation.validUntil)}`}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="space-y-5 px-5 py-6 sm:px-8">
                    {flash?.success && <Notice tone="success">{flash.success}</Notice>}
                    {state === 'approved' && (
                        <Notice tone="success">
                            Penawaran ini telah Anda setujui{quotation.approvedAt ? ` pada ${formatDateTime(quotation.approvedAt)}` : ''}. Terima kasih.
                        </Notice>
                    )}
                    {state !== 'open' && state !== 'approved' && <Notice tone="warning">{STATE_NOTICE[state]}</Notice>}

                    {quotation.sections.map((section, sectionIndex) => (
                        <section key={`${section.name}-${sectionIndex}`}>
                            <h2 className="mb-2 text-sm font-semibold text-daiku-dark">
                                {sectionLetter(sectionIndex)}. {section.name}
                            </h2>

                            {/* Phone: one card per item. */}
                            <ul className="divide-y divide-daiku-border rounded-xl ring-1 ring-daiku-border sm:hidden">
                                {section.items.map((item, index) => (
                                    <li key={index} className="px-3 py-2.5 text-sm">
                                        <div className="flex justify-between gap-3">
                                            <span className="font-medium text-daiku-dark">
                                                {index + 1}. {item.description}
                                            </span>
                                            <span className="shrink-0 font-medium">{formatRupiah(item.total)}</span>
                                        </div>
                                        <p className="text-xs text-daiku-muted">
                                            {(item.dimLength !== null || item.dimWidthHeight !== null) &&
                                                `${formatQty(item.dimLength ?? 0)} × ${formatQty(item.dimWidthHeight ?? 0)} · `}
                                            {formatQty(item.qty)} {item.unit} × {formatRupiah(item.unitPrice)}
                                        </p>
                                    </li>
                                ))}
                                <li className="flex justify-between px-3 py-2 text-sm font-semibold">
                                    <span>Subtotal</span>
                                    <span>{formatRupiah(section.subtotal)}</span>
                                </li>
                            </ul>

                            {/* Tablet & desktop: the Excel layout. */}
                            <div className="hidden overflow-x-auto rounded-xl ring-1 ring-daiku-border sm:block">
                                <table className="w-full text-sm">
                                    <thead className="bg-daiku-yellow-light/70 text-[11px] tracking-wider text-daiku-muted uppercase">
                                        <tr>
                                            <th className="w-10 px-3 py-2 text-left font-semibold">No</th>
                                            <th className="px-3 py-2 text-left font-semibold">Item</th>
                                            <th className="w-16 px-3 py-2 text-right font-semibold">P</th>
                                            <th className="w-16 px-3 py-2 text-right font-semibold">T/L</th>
                                            <th className="w-24 px-3 py-2 text-right font-semibold">Volume</th>
                                            <th className="w-32 px-3 py-2 text-right font-semibold">Harga</th>
                                            <th className="w-36 px-3 py-2 text-right font-semibold">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {section.items.map((item, index) => (
                                            <tr key={index} className="border-t border-daiku-border">
                                                <td className="px-3 py-2 text-daiku-muted">{index + 1}</td>
                                                <td className="px-3 py-2 text-daiku-dark">{item.description}</td>
                                                <td className="px-3 py-2 text-right">{item.dimLength === null ? '' : formatQty(item.dimLength)}</td>
                                                <td className="px-3 py-2 text-right">{item.dimWidthHeight === null ? '' : formatQty(item.dimWidthHeight)}</td>
                                                <td className="px-3 py-2 text-right">
                                                    {formatQty(item.qty)} {item.unit}
                                                </td>
                                                <td className="px-3 py-2 text-right">{formatRupiah(item.unitPrice)}</td>
                                                <td className="px-3 py-2 text-right font-medium">{formatRupiah(item.total)}</td>
                                            </tr>
                                        ))}
                                        <tr className="border-t border-daiku-border bg-daiku-gray/60 font-semibold">
                                            <td colSpan={6} className="px-3 py-2 text-right">
                                                Subtotal {section.name}
                                            </td>
                                            <td className="px-3 py-2 text-right">{formatRupiah(section.subtotal)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ))}

                    <dl className="ml-auto grid max-w-sm gap-2 rounded-xl bg-daiku-gray/60 p-4 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-daiku-muted">Total</dt>
                            <dd>{formatRupiah(quotation.itemsTotal)}</dd>
                        </div>
                        {quotation.discount > 0 && (
                            <div className="flex justify-between">
                                <dt className="text-daiku-muted">Diskon</dt>
                                <dd>− {formatRupiah(quotation.discount)}</dd>
                            </div>
                        )}
                        {quotation.roundedTotal !== null && (
                            <div className="flex justify-between">
                                <dt className="text-daiku-muted">Pembulatan</dt>
                                <dd>{formatRupiah(quotation.roundedTotal)}</dd>
                            </div>
                        )}
                        <div className="flex justify-between border-t border-daiku-border pt-2 text-base font-semibold text-daiku-dark">
                            <dt>Grand Total</dt>
                            <dd>{formatRupiah(quotation.total)}</dd>
                        </div>
                    </dl>

                    {quotation.paymentTerms.length > 0 && (
                        <section>
                            <h2 className="mb-2 text-sm font-semibold text-daiku-dark">Skema Pembayaran</h2>
                            <ul className="divide-y divide-daiku-border rounded-xl ring-1 ring-daiku-border">
                                {quotation.paymentTerms.map((term) => (
                                    <li key={term.sequence} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-3 py-2.5 text-sm">
                                        <span>
                                            <span className="font-medium text-daiku-dark">
                                                {term.sequence}. {term.label}
                                            </span>{' '}
                                            <span className="text-daiku-muted">({term.percentage.toLocaleString('id-ID')}%)</span>
                                            <span className="block text-xs text-daiku-muted">
                                                {term.trigger}
                                                {term.dueDate && ` — ${formatDate(term.dueDate)}`}
                                                {term.milestone && ` — ${term.milestone}`}
                                            </span>
                                        </span>
                                        <span className="font-medium">{formatRupiah(term.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {state === 'open' && (
                        <div className="flex flex-col items-stretch gap-2 border-t border-daiku-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-daiku-muted">Ada yang ingin diubah? Hubungi Marketing kami lewat WhatsApp.</p>
                            <Button size="lg" onClick={() => setConfirmOpen(true)}>
                                <CheckCircle2 className="size-4" />
                                Setujui Penawaran
                            </Button>
                        </div>
                    )}
                </div>

                <div className="border-t border-daiku-border px-5 py-4 text-xs text-daiku-muted sm:px-8">
                    {quotation.company.name}
                    {quotation.company.address && ` · ${quotation.company.address}`}
                    {quotation.company.phone && ` · ${quotation.company.phone}`}
                    {quotation.company.email && ` · ${quotation.company.email}`}
                </div>
            </article>

            <Dialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    setConfirmOpen(open);
                    if (!open) form.reset();
                }}
            >
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Setujui Penawaran</DialogTitle>
                        <DialogDescription>
                            {quotation.typeLabel} {quotation.number} versi {quotation.version} — {formatRupiah(quotation.total)}, termasuk skema
                            pembayarannya.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="agree"
                            checked={form.data.agree}
                            onCheckedChange={(checked) => form.setData('agree', checked === true)}
                        />
                        <Label htmlFor="agree" className="leading-snug font-normal">
                            Saya telah membaca dan menyetujui penawaran ini.
                        </Label>
                    </div>
                    {form.errors.agree && <p className="text-sm text-destructive">{form.errors.agree}</p>}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="button" onClick={approve} disabled={!form.data.agree || form.processing}>
                            Setujui
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </PublicLayout>
    );
}
