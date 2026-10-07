import { Notice } from '@/Components/shared/Notice';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { copyText } from '@/lib/clipboard';
import { whatsappNumber } from '@/lib/phone';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { Check, Copy, MessageCircle } from 'lucide-react';
import { useId, useState } from 'react';
import { toast } from 'sonner';

interface ShareLinkPanelProps {
    url: string;
    clientName: string;
    /** The lead's mobile number (`08…`), null when it only has an email. */
    phone: string | null;
    typeLabel: string;
    validUntil: string | null;
}

/**
 * Sprint 12 decision #13 — Marketing copies the client's link or opens
 * WhatsApp with it prefilled (number from the lead's No. HP).
 * Sprint 17 Sub 01 — copying works on plain HTTP too (`copyText`), and a
 * warning shows while APP_URL is a local address a client's phone can't reach.
 */
export function ShareLinkPanel({ url, clientName, phone: leadPhone, typeLabel, validUntil }: ShareLinkPanelProps) {
    const [copied, setCopied] = useState(false);
    const inputId = useId();
    const { appUrlIsLocal } = usePage<PageProps>().props;
    const phone = whatsappNumber(leadPhone);
    const message =
        `Halo ${clientName}, berikut ${typeLabel} dari kami. Silakan dibuka dan, bila sesuai, tekan "Setujui Penawaran":\n${url}` +
        (validUntil ? `\n\nPenawaran berlaku sampai ${validUntil}.` : '');

    async function copy() {
        if (await copyText(url)) {
            setCopied(true);
            toast.success('Link disalin.');
            window.setTimeout(() => setCopied(false), 2000);

            return;
        }

        // Both clipboard routes failed — select the link so it can be copied by hand.
        const input = document.getElementById(inputId) as HTMLInputElement | null;
        input?.focus();
        input?.select();
        toast.error('Gagal menyalin — tekan lama pada link untuk menyalin.');
    }

    return (
        <div className="space-y-2">
            {appUrlIsLocal && (
                <Notice tone="warning">Link ini memakai alamat lokal — hanya bisa dibuka di komputer ini, tidak dari HP klien.</Notice>
            )}
            <div className="flex flex-col gap-2 sm:flex-row">
                <Input
                    id={inputId}
                    readOnly
                    value={url}
                    onFocus={(event) => event.currentTarget.select()}
                    aria-label="Link penawaran untuk klien"
                />
                <div className="flex shrink-0 gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={copy}>
                        {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                        {copied ? 'Tersalin' : 'Salin Link'}
                    </Button>
                    <Button type="button" size="sm" asChild>
                        <a
                            href={phone ? `https://wa.me/${phone}?text=${encodeURIComponent(message)}` : `https://wa.me/?text=${encodeURIComponent(message)}`}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <MessageCircle className="size-4" />
                            Kirim via WhatsApp
                        </a>
                    </Button>
                </div>
            </div>
            {!phone && <p className="text-xs text-daiku-muted">Lead belum punya No. HP — pilih penerima di WhatsApp.</p>}
        </div>
    );
}
