import { type CropAspect, type CropOutput, ImageCropDialog } from '@/Components/shared/ImageCropDialog';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { BrandAsset } from '@/types';
import { router } from '@inertiajs/react';
import { ImageUp, Loader2, Trash2 } from 'lucide-react';
import { type ReactNode, useRef, useState } from 'react';

/**
 * Crop frame per asset. Output sizes stay inside UploadBrandingAssetRequest's
 * limits (logo ≥ 32 px tall even at 3:1, login image ≥ 600 px).
 */
const CROP: Record<BrandAsset, { title: string; aspects: CropAspect[]; output: CropOutput }> = {
    logo: {
        title: 'Crop Logo',
        aspects: [
            { label: 'Persegi 1:1', value: 1 },
            { label: 'Lebar 3:1', value: 3 },
        ],
        output: { mime: 'image/png', minWidth: 128, maxWidth: 512, allowFit: true },
    },
    favicon: {
        title: 'Crop Favicon',
        aspects: [{ label: 'Persegi 1:1', value: 1 }],
        output: { mime: 'image/png', minWidth: 128, maxWidth: 128, allowFit: true },
    },
    login_image: {
        title: 'Crop Gambar Halaman Login',
        aspects: [{ label: 'Potret 4:5 (panel login)', value: 4 / 5 }],
        output: { mime: 'image/jpeg', minWidth: 600, maxWidth: 1200, quality: 0.88 },
    },
};

/** Mirrors UploadBrandingAssetRequest — checked client-side before sending. */
export const ASSET_RULES: Record<BrandAsset, { accept: string; types: string[]; maxKb: number; hint: string }> = {
    logo: {
        accept: '.png,.jpg,.jpeg',
        types: ['image/png', 'image/jpeg'],
        maxKb: 2048,
        hint: 'PNG/JPG, maks. 2 MB. Disarankan PNG transparan, persegi.',
    },
    favicon: {
        accept: '.png,.ico',
        types: ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
        maxKb: 512,
        hint: 'PNG/ICO, maks. 512 KB. Ideal 64×64 piksel.',
    },
    login_image: {
        accept: '.jpg,.jpeg,.png,.webp',
        types: ['image/jpeg', 'image/png', 'image/webp'],
        maxKb: 5120,
        hint: 'JPG/PNG/WEBP, maks. 5 MB, minimal 600×600 piksel.',
    },
};

interface BrandAssetCardProps {
    asset: BrandAsset;
    title: string;
    description: string;
    /** Current served URL, or null when nothing is uploaded. */
    url: string | null;
    /** How the preview box frames the image. */
    preview: (url: string) => ReactNode;
    /** Shown in the preview box when nothing is uploaded. */
    placeholder: ReactNode;
}

/**
 * One uploadable brand asset in Pengaturan Situs: preview, upload/replace
 * (click or drag-and-drop → crop dialog) and remove. Uploads right after
 * the crop is confirmed — no separate save step — through
 * settings.assets.store / .destroy.
 */
export function BrandAssetCard({ asset, title, description, url, preview, placeholder }: BrandAssetCardProps) {
    const rules = ASSET_RULES[asset];
    const inputRef = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [dragging, setDragging] = useState(false);
    /** Picked file waiting in the crop dialog. */
    const [pending, setPending] = useState<File | null>(null);

    function resetInput() {
        if (inputRef.current) inputRef.current.value = '';
    }

    /** Type check, then hand the file to the crop dialog. */
    function pick(file: File | undefined) {
        if (!file) return;

        const extensionOk = rules.accept.split(',').some((ext) => file.name.toLowerCase().endsWith(ext));
        if (!rules.types.includes(file.type) && !extensionOk) {
            setError(`Format tidak didukung. ${rules.hint}`);
            resetInput();
            return;
        }

        setError(null);
        setPending(file);
    }

    function upload(file: File) {
        setPending(null);
        resetInput();

        // Size is checked on what is actually sent (a crop is usually smaller).
        if (file.size > rules.maxKb * 1024) {
            setError(`Ukuran file terlalu besar. ${rules.hint}`);
            return;
        }

        router.post(
            route('settings.assets.store', { asset }),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setBusy(true),
                onError: (errors) => setError(errors.file ?? 'Gagal mengunggah gambar.'),
                onFinish: () => setBusy(false),
            },
        );
    }

    function remove() {
        if (!confirm(`Hapus ${title.toLowerCase()}? Tampilan akan kembali ke bawaan.`)) return;

        router.delete(route('settings.assets.destroy', { asset }), {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    }

    return (
        <div className="flex flex-col rounded-xl border border-border bg-card">
            <button
                type="button"
                onClick={() => inputRef.current?.click()}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    pick(event.dataTransfer.files[0]);
                }}
                disabled={busy}
                aria-label={`${url ? 'Ganti' : 'Unggah'} ${title}`}
                className={cn(
                    'relative flex h-36 items-center justify-center overflow-hidden rounded-t-xl border-b border-border bg-daiku-gray/60 transition-colors',
                    'hover:bg-daiku-gray focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                    dragging && 'bg-daiku-yellow-light',
                )}
            >
                {url ? preview(url) : placeholder}
                {busy && (
                    <span className="absolute inset-0 flex items-center justify-center bg-background/70">
                        <Loader2 className="size-5 animate-spin text-muted-foreground" />
                    </span>
                )}
            </button>

            <div className="flex flex-1 flex-col gap-3 p-4">
                <div>
                    <p className="text-sm font-semibold text-foreground">{title}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
                    <p className="mt-1.5 text-[11px] text-muted-foreground/90">{rules.hint}</p>
                </div>

                {error && <p className="text-xs text-error-ink">{error}</p>}

                <div className="mt-auto flex items-center gap-2">
                    <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => inputRef.current?.click()}>
                        <ImageUp className="size-3.5" />
                        {url ? 'Ganti' : 'Unggah'}
                    </Button>
                    {url && (
                        <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={remove} className="text-error-ink hover:text-error-ink">
                            <Trash2 className="size-3.5" />
                            Hapus
                        </Button>
                    )}
                </div>
            </div>

            <input
                ref={inputRef}
                type="file"
                accept={rules.accept}
                className="hidden"
                onChange={(event) => pick(event.target.files?.[0])}
            />

            <ImageCropDialog
                file={pending}
                title={CROP[asset].title}
                aspects={CROP[asset].aspects}
                output={CROP[asset].output}
                onConfirm={upload}
                onUseOriginal={upload}
                onCancel={() => {
                    setPending(null);
                    resetInput();
                }}
            />
        </div>
    );
}
