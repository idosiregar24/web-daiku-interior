import { Notice } from '@/Components/shared/Notice';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { copyText } from '@/lib/clipboard';
import { whatsappNumber } from '@/lib/phone';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { Check, Copy, MessageCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/** The read-only link field's id — "Salin Link" selects it when the clipboard is unavailable. */
export const SHARE_LINK_INPUT_ID = 'quotation-share-link';

interface ShareLinkProps {
    url: string;
    clientName: string;
    /** The lead's mobile number (`08…`), null when it only has an email. */
    phone: string | null;
    typeLabel: string;
    validUntil: string | null;
}

/**
 * Sprint 12 decision #13 / Sprint 23 K4b — "Salin Link" + "Kirim via
 * WhatsApp" (number from the lead's No. HP, message prefilled) in the
 * quotation page's header. Sprint 17 Sub 01 — copying works on plain HTTP
 * too (`copyText`); when it can't, the link field below is selected.
 */
export function ShareLinkActions({ url, clientName, phone: leadPhone, typeLabel, validUntil }: ShareLinkProps) {
    const [copied, setCopied] = useState(false);
    const phone = whatsappNumber(leadPhone);
    const message =
        `Halo ${clientName}, berikut ${typeLabel} dari kami. Silakan dibuka lalu pilih "Setujui Penawaran", atau "Tidak Setuju" bila ada yang perlu diubah:\n${url}` +
        (validUntil ? `\n\nPenawaran berlaku sampai ${validUntil}.` : '');

    async function copy() {
        if (await copyText(url)) {
            setCopied(true);
            toast.success('Link disalin.');
            window.setTimeout(() => setCopied(false), 2000);

            return;
        }

        // Both clipboard routes failed — select the link so it can be copied by hand.
        const input = document.getElementById(SHARE_LINK_INPUT_ID) as HTMLInputElement | null;
        input?.focus();
        input?.select();
        toast.error('Gagal menyalin — tekan lama pada link di kartu "Keputusan Klien" untuk menyalin.');
    }

    return (
        <>
            <Button type="button" variant="outline" onClick={copy}>
                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                {copied ? 'Tersalin' : 'Salin Link'}
            </Button>
            <Button type="button" asChild>
                <a
                    href={phone ? `https://wa.me/${phone}?text=${encodeURIComponent(message)}` : `https://wa.me/?text=${encodeURIComponent(message)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <MessageCircle className="size-4" />
                    Kirim via WhatsApp
                </a>
            </Button>
        </>
    );
}

/** The client's link as information (the actions are in the header): the field to copy by hand, warnings. */
export function ShareLinkPanel({ url, phone }: Pick<ShareLinkProps, 'url' | 'phone'>) {
    const { appUrlIsLocal } = usePage<PageProps>().props;

    return (
        <div className="space-y-2">
            {appUrlIsLocal && <Notice tone="warning">Link ini memakai alamat lokal — hanya bisa dibuka di komputer ini, tidak dari HP klien.</Notice>}
            <Input
                id={SHARE_LINK_INPUT_ID}
                readOnly
                value={url}
                onFocus={(event) => event.currentTarget.select()}
                aria-label="Link penawaran untuk klien"
            />
            {!whatsappNumber(phone) && <p className="text-xs text-daiku-muted">Lead belum punya No. HP — pilih penerima di WhatsApp.</p>}
        </div>
    );
}
