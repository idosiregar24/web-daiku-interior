import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { whatsappNumber } from '@/lib/phone';
import { Check, Copy, MessageCircle } from 'lucide-react';
import { useState } from 'react';

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
 */
export function ShareLinkPanel({ url, clientName, phone: leadPhone, typeLabel, validUntil }: ShareLinkPanelProps) {
    const [copied, setCopied] = useState(false);
    const phone = whatsappNumber(leadPhone);
    const message =
        `Halo ${clientName}, berikut ${typeLabel} dari kami. Silakan dibuka dan, bila sesuai, tekan "Setujui Penawaran":\n${url}` +
        (validUntil ? `\n\nPenawaran berlaku sampai ${validUntil}.` : '');

    async function copy() {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard blocked (http, permissions) — the field stays selectable.
        }
    }

    return (
        <div className="space-y-2">
            <div className="flex flex-col gap-2 sm:flex-row">
                <Input readOnly value={url} onFocus={(event) => event.currentTarget.select()} aria-label="Link penawaran untuk klien" />
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
