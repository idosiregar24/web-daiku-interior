import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { InvoicePaymentProof } from '@/types';
import { ExternalLink, ImagePlus, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

/** Mirrors App\Models\InvoicePaymentProof (SubmitInvoiceProofRequest). */
export const MAX_PROOF_PHOTOS = 3;
export const MAX_PROOF_BYTES = 5 * 1024 * 1024;
export const PROOF_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
/** The server keeps at most 2000 px (InvoicePaymentProofService) — no point uploading more. */
const MAX_PROOF_SIDE = 2000;

/**
 * Shrink a phone photo before upload (JPEG, longest side ≤ 2000 px) so a
 * Marketing on mobile data sends a few hundred KB instead of 5 MB. The
 * server re-encodes it anyway (and strips its metadata). Falls back to the
 * original when the browser can't decode it or shrinking doesn't help.
 */
async function shrinkPhoto(file: File): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, MAX_PROOF_SIDE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const context = canvas.getContext('2d');
        if (!context) return file;
        // JPEG has no transparency — paint PNG/WEBP alpha white, not black.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob || blob.size >= file.size) return file;

        return new File([blob], 'bukti-bayar.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}

/**
 * Sprint 23 K12d — optional proof photos in "Tandai Klien Sudah Bayar"
 * (max 3, JPG/PNG/WEBP ≤ 5 MB). `error` = the server's message for
 * `payment_photos` / `payment_photos.N`.
 */
export function PaymentProofPicker({
    value,
    onChange,
    error,
}: {
    value: File[];
    onChange: (files: File[]) => void;
    error?: string;
}) {
    const fileRef = useRef<HTMLInputElement>(null);
    const [localError, setLocalError] = useState<string | null>(null);
    const [shrinking, setShrinking] = useState(false);

    const previews = useMemo(() => value.map((photo) => URL.createObjectURL(photo)), [value]);
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    async function add(files: FileList | null) {
        if (!files) return;
        const room = Math.max(MAX_PROOF_PHOTOS - value.length, 0);
        const images = Array.from(files).filter((file) => PROOF_TYPES.includes(file.type));

        setShrinking(true);
        const shrunk = await Promise.all(images.slice(0, room).map(shrinkPhoto));
        setShrinking(false);
        const valid = shrunk.filter((file) => file.size <= MAX_PROOF_BYTES);

        setLocalError(
            images.length < files.length || valid.length < shrunk.length
                ? 'Sebagian file dilewati — hanya foto JPG/PNG/WEBP (maks. 5 MB).'
                : images.length > room
                  ? `Maksimal ${MAX_PROOF_PHOTOS} foto.`
                  : null,
        );
        onChange([...value, ...valid]);
        if (fileRef.current) fileRef.current.value = '';
    }

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-foreground">
                Foto bukti bayar <span className="font-normal text-muted-foreground">({value.length}/{MAX_PROOF_PHOTOS})</span>
            </p>
            <p className="text-xs text-muted-foreground">Foto/tangkapan layar transfer — JPG, PNG, atau WEBP, maks. 5 MB. Hanya dilihat tim internal.</p>
            <div className="grid grid-cols-3 gap-2">
                {value.map((photo, index) => (
                    <div key={`${photo.name}-${index}`} className="relative aspect-square overflow-hidden rounded-md ring-1 ring-border">
                        <img src={previews[index]} alt={`Foto bukti ${index + 1}`} className="size-full object-cover" />
                        <button
                            type="button"
                            aria-label={`Hapus foto bukti ${index + 1}`}
                            onClick={() => onChange(value.filter((_, i) => i !== index))}
                            className="absolute top-1 right-1 flex size-7 items-center justify-center rounded-full bg-background/90 shadow-xs"
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>
                ))}
                {value.length < MAX_PROOF_PHOTOS && (
                    <button
                        type="button"
                        onClick={() => fileRef.current?.click()}
                        disabled={shrinking}
                        className="flex aspect-square flex-col items-center justify-center gap-1 rounded-md border border-dashed border-border text-xs text-muted-foreground hover:bg-daiku-gray/60"
                    >
                        <ImagePlus className="size-5" aria-hidden />
                        {shrinking ? 'Memproses…' : 'Tambah Foto'}
                    </button>
                )}
            </div>
            <input
                ref={fileRef}
                type="file"
                accept={PROOF_TYPES.join(',')}
                multiple
                className="hidden"
                onChange={(event) => void add(event.target.files)}
            />
            {(localError || error) && <p className="text-sm text-destructive">{localError ?? error}</p>}
        </div>
    );
}

/**
 * Sprint 23 K12d — the proof photos Finance checks: thumbnails of the
 * current submission (click to enlarge), and — when Finance sent an
 * earlier one back — those older photos as history. Photos come only
 * through `finance.invoices.proofs.show` (never a public URL).
 */
export function PaymentProofGallery({
    proofs,
    currentAttempt,
    compact = false,
    className,
}: {
    proofs: InvoicePaymentProof[] | undefined;
    /** Invoice.proof_attempt — photos of other attempts are history. */
    currentAttempt: number | undefined;
    /** Small thumbnails (list rows). */
    compact?: boolean;
    className?: string;
}) {
    const [open, setOpen] = useState<InvoicePaymentProof | null>(null);

    if (!proofs || proofs.length === 0) {
        return null;
    }

    const current = proofs.filter((proof) => proof.attempt === currentAttempt);
    const older = proofs.filter((proof) => proof.attempt !== currentAttempt);
    const thumb = compact ? 'size-12' : 'size-20';

    const strip = (items: InvoicePaymentProof[]) => (
        <ul className="flex flex-wrap gap-2">
            {items.map((proof, index) => (
                <li key={proof.id}>
                    <button
                        type="button"
                        onClick={() => setOpen(proof)}
                        className={cn('block overflow-hidden rounded-md ring-1 ring-border hover:ring-daiku-yellow-dark', thumb)}
                        aria-label={`Perbesar foto bukti ${index + 1}`}
                    >
                        <img src={proof.thumb_url} alt={`Foto bukti ${index + 1}`} loading="lazy" className="size-full object-cover" />
                    </button>
                </li>
            ))}
        </ul>
    );

    return (
        <div className={cn('space-y-2', className)}>
            {current.length > 0 && strip(current)}
            {older.length > 0 && (
                <details className="text-xs text-muted-foreground">
                    <summary className="cursor-pointer select-none">Foto kiriman sebelumnya ({older.length}) — ditolak Finance</summary>
                    <div className="mt-2 space-y-2">
                        {[...new Set(older.map((proof) => proof.attempt))].map((attempt) => (
                            <div key={attempt} className="space-y-1">
                                <p>Kiriman ke-{attempt}</p>
                                {strip(older.filter((proof) => proof.attempt === attempt))}
                            </div>
                        ))}
                    </div>
                </details>
            )}

            <Dialog open={open !== null} onOpenChange={(value) => !value && setOpen(null)}>
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Foto Bukti Bayar</DialogTitle>
                        <DialogDescription>
                            {open && `Kiriman ke-${open.attempt} · diunggah ${formatDateTime(open.created_at)}`}
                        </DialogDescription>
                    </DialogHeader>
                    {open && (
                        <div className="space-y-3">
                            <img src={open.url} alt="Foto bukti bayar" className="max-h-[70vh] w-full rounded-md bg-daiku-gray object-contain" />
                            <Button variant="outline" size="sm" asChild>
                                <a href={open.url} target="_blank" rel="noopener noreferrer">
                                    <ExternalLink className="size-4" />
                                    Buka ukuran penuh
                                </a>
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
