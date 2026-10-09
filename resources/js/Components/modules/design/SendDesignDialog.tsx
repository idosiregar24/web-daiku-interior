import { Notice } from '@/Components/shared/Notice';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { copyText } from '@/lib/clipboard';
import { whatsappNumber } from '@/lib/phone';
import { cn } from '@/lib/utils';
import type { Design } from '@/types';
import { router } from '@inertiajs/react';
import { Check, Copy, ExternalLink, MessageCircle } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { toast } from 'sonner';

type SendableDesign = Pick<Design, 'id' | 'status' | 'revision_count' | 'design_urls' | 'ready_for_client_at' | 'ready_note'>;

interface SendDesignDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    design: SendableDesign;
    clientName: string;
    /** The lead's mobile number (`08…`); null when it only has an email. */
    phone: string | null;
}

function defaultMessage(design: SendableDesign, clientName: string): string {
    const what = design.status === 'REVISI_DESAIN' ? `desain hasil revisi ke-${design.revision_count}` : 'desain';

    return (
        `Halo ${clientName}, berikut ${what} dari kami:\n${(design.design_urls ?? []).join('\n')}\n\n` +
        'Silakan dicek. Kabari kami bila ada yang ingin diubah, atau bila desainnya sudah sesuai.'
    );
}

/**
 * Sprint 22 K6 — Marketing sends the architect's links to the client:
 * "Kirim via WhatsApp" opens WhatsApp to the lead's number with an
 * editable message and records the design as sent in the same click;
 * "Tandai Terkirim" records a send done another way — after copying a
 * link or the whole message it becomes the main button, since a copy is
 * not a send. The system itself never messages the client.
 */
export function SendDesignDialog({ open, onOpenChange, design, clientName, phone: leadPhone }: SendDesignDialogProps) {
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);
    /** What was copied last ('message' or a link) — a copy makes "Tandai Terkirim" the main button. */
    const [copied, setCopied] = useState<string | null>(null);
    const messageId = useId();
    const phone = whatsappNumber(leadPhone);
    const links = design.design_urls ?? [];
    const waHref = `https://wa.me/${phone ?? ''}?text=${encodeURIComponent(message)}`;

    useEffect(() => {
        if (open) {
            setMessage(defaultMessage(design, clientName));
            setCopied(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    async function copy(text: string, key: string, what: string) {
        if (await copyText(text)) {
            setCopied(key);
            toast.success(`${what} disalin — setelah dikirim ke klien, tekan Tandai Terkirim.`);

            return;
        }

        toast.error('Gagal menyalin — pilih teksnya lalu salin manual.');
    }

    function markSent() {
        if (submitting) return;

        setSubmitting(true);
        router.post(route('design.sendToClient', { design: design.id }), {}, {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Kirim Desain ke Klien</DialogTitle>
                    <DialogDescription>
                        Kirim link desain ke <span className="font-medium text-foreground">{clientName}</span>. Status menjadi menunggu
                        persetujuan klien.
                    </DialogDescription>
                </DialogHeader>

                {design.ready_for_client_at ? (
                    design.ready_note && (
                        <Notice tone="info">
                            <span className="font-medium">Catatan arsitek:</span> {design.ready_note}
                        </Notice>
                    )
                ) : (
                    <Notice tone="warning">
                        Arsitek belum menandai desain ini siap dikirim. Pastikan link di bawah sudah versi terbaru.
                    </Notice>
                )}

                <div className="space-y-1.5">
                    <p className="text-sm font-medium">Link desain</p>
                    <ul className="space-y-1 text-sm">
                        {links.map((url) => (
                            <li key={url} className="flex min-w-0 items-center gap-1">
                                <a
                                    href={url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex min-w-0 items-center gap-1 text-daiku-dark underline decoration-daiku-yellow underline-offset-2"
                                >
                                    <span className="truncate">{url}</span>
                                    <ExternalLink className="size-3.5 shrink-0" />
                                </a>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    className="shrink-0"
                                    onClick={() => copy(url, url, 'Link')}
                                    aria-label="Salin link"
                                    title="Salin link"
                                >
                                    {copied === url ? <Check className="size-4 text-success-ink" /> : <Copy className="size-4" />}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="space-y-1.5">
                    <div className="flex items-center justify-between gap-2">
                        <Label htmlFor={messageId}>Pesan WhatsApp</Label>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => copy(message, 'message', 'Pesan')}
                            disabled={message.trim() === ''}
                        >
                            {copied === 'message' ? <Check className="size-4" /> : <Copy className="size-4" />}
                            {copied === 'message' ? 'Tersalin' : 'Salin Pesan'}
                        </Button>
                    </div>
                    <Textarea id={messageId} value={message} onChange={(event) => setMessage(event.target.value)} rows={7} />
                    {!phone && <p className="text-xs text-daiku-muted">Lead belum punya No. HP — pilih penerima di WhatsApp.</p>}
                </div>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button type="button" variant={copied ? 'default' : 'outline'} onClick={markSent} disabled={submitting}>
                        Tandai Terkirim
                    </Button>
                    <Button type="button" variant={copied ? 'outline' : 'default'} asChild>
                        {/* The link opens WhatsApp in a new tab; the same click records the send. */}
                        <a
                            href={waHref}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={markSent}
                            aria-disabled={submitting || message.trim() === ''}
                            className={cn((submitting || message.trim() === '') && 'pointer-events-none opacity-50')}
                        >
                            <MessageCircle className="size-4" />
                            Kirim via WhatsApp
                        </a>
                    </Button>
                </DialogFooter>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
