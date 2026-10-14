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
import { Textarea } from '@/Components/ui/textarea';
import { LetterDocument } from '@/Components/modules/quotation/LetterDocument';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { CompanyLetter, PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, FileDown, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { RequiredMark } from '@/Components/shared/RequiredMark';

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
    /** Sprint 15 — the company letter, same as the PDF. */
    letter: CompanyLetter;
}

/** QuotationService::publicState() — `rejected`: the client said "Tidak Setuju" (Sprint 23 K11). */
type PublicState = 'open' | 'approved' | 'rejected' | 'outdated' | 'expired' | 'unavailable';

/** App\Models\QuotationNote::MAX_LENGTH — mirrors RejectPublicQuotationRequest. */
const NOTE_MAX = 1000;

interface PublicQuotationProps {
    token: string;
    state: PublicState;
    quotation: PublicQuotation;
}

const STATE_NOTICE: Record<Exclude<PublicState, 'open' | 'approved' | 'rejected'>, string> = {
    outdated: 'Penawaran ini sudah diperbarui. Silakan minta link penawaran terbaru kepada Marketing kami.',
    expired: 'Masa berlaku penawaran ini sudah habis. Silakan hubungi Marketing kami untuk penawaran terbaru.',
    unavailable: 'Penawaran ini sedang tidak berlaku. Silakan hubungi Marketing kami.',
};

/**
 * Sprint 12 decisions #13–#14 — the client's offer, opened from the link
 * without logging in: the RAB as on the PDF, its payment scheme, and two
 * actions: "Setujui Penawaran" (confirmed with a mandatory tick) or —
 * Sprint 23 K11 — "Tidak Setuju" with a mandatory reason, which goes to
 * Marketing to revise the offer.
 */
export default function PublicQuotationPage({ token, state, quotation }: PublicQuotationProps) {
    const { flash } = usePage<PageProps>().props;
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [rejectOpen, setRejectOpen] = useState(false);
    const form = useForm<{ agree: boolean }>({ agree: false });
    const rejectForm = useForm<{ note: string }>({ note: '' });
    const noteMissing = rejectForm.data.note.trim().length < 3;

    function closeConfirm() {
        setConfirmOpen(false);
        form.reset();
        form.clearErrors();
    }

    // The dialog only makes sense while the offer can still be approved. Once
    // the page reloads as approved — by this click, or because the client had
    // already approved it from another tab/phone (the server then answers
    // "sudah disetujui") — or the link went stale, close it at once: the
    // page's own notice says what happened.
    useEffect(() => {
        if (state !== 'open') {
            closeConfirm();
            closeReject();
        }
    }, [state]);

    function closeReject() {
        setRejectOpen(false);
        rejectForm.reset();
        rejectForm.clearErrors();
    }

    function reject() {
        rejectForm.post(route('public.quotation.reject', token), {
            preserveScroll: true,
            onSuccess: closeReject,
        });
    }

    function approve() {
        form.post(route('public.quotation.approve', token), {
            preserveScroll: true,
            onSuccess: closeConfirm,
        });
    }

    return (
        <PublicLayout>
            <Head title={`${quotation.typeLabel} — ${quotation.client.name}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="space-y-4">
                {flash?.success && <Notice tone="success">{flash.success}</Notice>}
                {state === 'approved' && (
                    <Notice tone="success">
                        Penawaran ini telah Anda setujui{quotation.approvedAt ? ` pada ${formatDateTime(quotation.approvedAt)}` : ''}. Terima kasih.
                    </Notice>
                )}
                {state === 'rejected' && (
                    <Notice tone="info">Anda telah menyatakan tidak setuju dengan penawaran ini. Marketing kami akan menghubungi Anda untuk memperbaikinya.</Notice>
                )}
                {state !== 'open' && state !== 'approved' && state !== 'rejected' && <Notice tone="warning">{STATE_NOTICE[state]}</Notice>}

                {/* Sprint 15 — the same letter as the PDF (QuotationLetter). */}
                <LetterDocument letter={quotation.letter} />

                <div className="flex flex-col-reverse items-stretch gap-2 rounded-2xl bg-card p-4 ring-1 ring-daiku-border sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-daiku-muted">Ada yang ingin diubah? Pilih "Tidak Setuju" dan tuliskan alasannya, atau hubungi Marketing kami lewat WhatsApp.</p>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        {state !== 'outdated' && state !== 'unavailable' && (
                            <Button variant="outline" size="lg" asChild>
                                <a href={route('public.quotation.pdf', token)} target="_blank" rel="noopener noreferrer">
                                    <FileDown className="size-4" />
                                    Unduh PDF
                                </a>
                            </Button>
                        )}
                        {state === 'open' && (
                            <Button variant="outline" size="lg" onClick={() => setRejectOpen(true)}>
                                <XCircle className="size-4" />
                                Tidak Setuju
                            </Button>
                        )}
                        {state === 'open' && (
                            <Button size="lg" onClick={() => setConfirmOpen(true)}>
                                <CheckCircle2 className="size-4" />
                                Setujui Penawaran
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            <Dialog open={confirmOpen && state === 'open'} onOpenChange={(open) => (open ? setConfirmOpen(true) : closeConfirm())}>
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
                            <RequiredMark />
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

            <Dialog open={rejectOpen && state === 'open'} onOpenChange={(open) => (open ? setRejectOpen(true) : closeReject())}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Tidak Setuju dengan Penawaran</DialogTitle>
                        <DialogDescription>
                            Beri tahu kami apa yang perlu diubah pada {quotation.typeLabel} versi {quotation.version}. Marketing kami akan menghubungi Anda
                            dengan penawaran yang diperbaiki.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="reject-note">
                            Alasan / yang perlu diubah
                            <RequiredMark />
                        </Label>
                        <Textarea
                            id="reject-note"
                            rows={4}
                            maxLength={NOTE_MAX}
                            value={rejectForm.data.note}
                            onChange={(event) => rejectForm.setData('note', event.target.value)}
                            placeholder="mis. Harga lemari terlalu tinggi, saya ingin finishing HPL."
                        />
                        {rejectForm.errors.note && <p className="text-sm text-destructive">{rejectForm.errors.note}</p>}
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="button" onClick={reject} disabled={noteMissing || rejectForm.processing}>
                            Kirim
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </PublicLayout>
    );
}
