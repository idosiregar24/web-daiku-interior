import { CharCounter } from '@/Components/modules/settings/CharCounter';
import { EmptyState } from '@/Components/shared/EmptyState';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import type { PortfolioPhoto } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Images, ImageUp, Loader2, Pencil, Star, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors UploadPortfolioPhotosRequest — checked before sending, so a bad
// file doesn't cost a 15 MB upload to be refused.
const ACCEPT = '.jpg,.jpeg,.png,.webp';
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 15 * 1024 * 1024;
const MIN_WIDTH = 600;
const MIN_HEIGHT = 400;
const MAX_SIDE = 8000;

/** Natural size of an image file; null when the browser can't decode it (the server decides then). */
function readSize(file: File): Promise<{ width: number; height: number } | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            resolve({ width: image.naturalWidth, height: image.naturalHeight });
            URL.revokeObjectURL(url);
        };
        image.onerror = () => {
            resolve(null);
            URL.revokeObjectURL(url);
        };
        image.src = url;
    });
}

async function checkFile(file: File): Promise<string | null> {
    if (!TYPES.includes(file.type) && !/\.(jpe?g|png|webp)$/i.test(file.name)) {
        return `${file.name}: foto harus berformat JPG, PNG, atau WEBP.`;
    }

    if (file.size > MAX_BYTES) {
        return `${file.name}: ukuran tiap foto maksimal 15 MB.`;
    }

    const size = await readSize(file);
    if (size && (size.width < MIN_WIDTH || size.height < MIN_HEIGHT || size.width > MAX_SIDE || size.height > MAX_SIDE)) {
        return `${file.name}: foto minimal 600×400 piksel, maksimal 8000 piksel (ukurannya ${size.width}×${size.height}).`;
    }

    return null;
}

// Mirrors UpdatePortfolioPhotoRequest.
const photoSchema = z.object({
    alt: z.string().max(200, 'Deskripsi foto maksimal 200 karakter.'),
    caption: z.string().max(200, 'Keterangan maksimal 200 karakter.'),
});

type PhotoValues = z.infer<typeof photoSchema>;

function PhotoDialog({ itemId, photo, onClose }: { itemId: number; photo: PortfolioPhoto | null; onClose: () => void }) {
    const form = useForm<PhotoValues>({
        resolver: zodResolver(photoSchema),
        values: { alt: photo?.alt ?? '', caption: photo?.caption ?? '' },
    });
    const [alt, caption] = form.watch(['alt', 'caption']);

    function onSubmit(values: PhotoValues) {
        if (!photo) return;

        router.patch(
            route('settings.portfolio.photos.update', { portfolio: itemId, photo: photo.id }),
            { alt: values.alt.trim() || null, caption: values.caption.trim() || null },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof PhotoValues, { message })),
            },
        );
    }

    return (
        <Dialog open={photo !== null} onOpenChange={(open) => !open && onClose()}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Keterangan Foto</DialogTitle>
                    <DialogDescription>Membantu Google memahami foto dan pengunjung yang memakai pembaca layar.</DialogDescription>
                </DialogHeader>
                {photo && <img src={photo.thumb_url} alt="" className="aspect-4/3 w-full rounded-lg object-cover" />}
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="alt"
                            render={({ field }) => (
                                <FormItem>
                                    <div className="flex items-center justify-between gap-2">
                                        <FormLabel>Deskripsi Foto</FormLabel>
                                        <CharCounter value={alt} max={200} />
                                    </div>
                                    <FormControl>
                                        <Input {...field} autoFocus placeholder="mis. Kitchen set putih doff dengan island marmer di Panam" />
                                    </FormControl>
                                    <FormDescription>Apa yang terlihat di foto. Tidak tampil sebagai teks; dibaca Google dan pembaca layar.</FormDescription>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="caption"
                            render={({ field }) => (
                                <FormItem>
                                    <div className="flex items-center justify-between gap-2">
                                        <FormLabel>Keterangan</FormLabel>
                                        <CharCounter value={caption} max={200} />
                                    </div>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. HPL putih doff, top table granit" />
                                    </FormControl>
                                    <FormDescription>Tampil di bawah foto saat dibuka di galeri situs.</FormDescription>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit">Simpan</Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}

interface PortfolioPhotoManagerProps {
    itemId: number;
    photos: PortfolioPhoto[];
    coverPhotoId: number | null;
    /** UploadPortfolioPhotosRequest::MAX_FILES — per upload, not in total. */
    maxFiles: number;
    /** A published item keeps at least one photo (publishing requires one). */
    published: boolean;
}

/**
 * Sprint 20 Sub 04 — the photos of a portfolio item: multi-upload with
 * progress (resized to WebP + a thumbnail and stripped of EXIF/GPS on the
 * server), cover, order, description and delete. Every action saves at
 * once — independent of the item form's "Simpan".
 */
export function PortfolioPhotoManager({ itemId, photos, coverPhotoId, maxFiles, published }: PortfolioPhotoManagerProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [dragging, setDragging] = useState(false);
    /** null = idle; 0–100 while sending; 100 while the server re-encodes. */
    const [progress, setProgress] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<PortfolioPhoto | null>(null);

    // The server falls back to the first photo while no cover is picked.
    const coverId = coverPhotoId ?? photos[0]?.id ?? null;
    const missingAlt = photos.filter((photo) => !photo.alt).length;
    const lastPublishedPhoto = published && photos.length === 1;

    async function pick(list: FileList | null) {
        const files = Array.from(list ?? []);
        if (inputRef.current) inputRef.current.value = '';
        if (files.length === 0) return;

        if (files.length > maxFiles) {
            setErrors([`Maksimal ${maxFiles} foto sekali unggah — pilih ${maxFiles} dulu, sisanya di unggahan berikutnya.`]);
            return;
        }

        const problems = (await Promise.all(files.map(checkFile))).filter((problem): problem is string => problem !== null);
        if (problems.length > 0) {
            setErrors(problems);
            return;
        }

        setErrors([]);
        router.post(
            route('settings.portfolio.photos.store', { portfolio: itemId }),
            { photos: files },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setProgress(0),
                onProgress: (event) => setProgress(Math.round(event?.percentage ?? 0)),
                onError: (bag) => setErrors(Object.values(bag)),
                onFinish: () => setProgress(null),
            },
        );
    }

    /** Cover, reorder and delete share one busy flag — one change at a time. */
    const visit = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    function move(index: number, offset: -1 | 1) {
        const ids = photos.map((photo) => photo.id);
        [ids[index], ids[index + offset]] = [ids[index + offset], ids[index]];
        router.patch(route('settings.portfolio.photos.reorder', { portfolio: itemId }), { ids }, visit);
    }

    function destroy(photo: PortfolioPhoto, position: number) {
        const note = photo.id === coverId && photos.length > 1 ? ' Foto sampul akan pindah ke foto berikutnya.' : '';
        if (!confirm(`Hapus foto ${position}? File-nya ikut terhapus dan tidak bisa dikembalikan.${note}`)) return;
        router.delete(route('settings.portfolio.photos.destroy', { portfolio: itemId, photo: photo.id }), visit);
    }

    const uploading = progress !== null;

    return (
        <SectionCard
            title={`Foto (${photos.length})`}
            icon={Images}
            description="Foto pertama di urutan tampil lebih dulu di galeri; foto sampul tampil di daftar portofolio dan beranda."
        >
            <div className="space-y-4">
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
                        void pick(event.dataTransfer.files);
                    }}
                    disabled={uploading}
                    className={cn(
                        'flex w-full flex-col items-center gap-1.5 rounded-xl border border-dashed border-daiku-border bg-daiku-gray/60 px-4 py-6 text-center transition-colors',
                        'hover:bg-daiku-gray focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-wait',
                        dragging && 'bg-daiku-yellow-light',
                    )}
                >
                    {uploading ? <Loader2 className="size-6 animate-spin text-daiku-muted" /> : <ImageUp className="size-6 text-daiku-muted" />}
                    <span className="text-sm font-medium text-foreground">
                        {uploading ? (progress < 100 ? `Mengunggah… ${progress}%` : 'Memproses foto…') : 'Klik atau seret foto ke sini'}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        JPG/PNG/WEBP, maks. 15 MB per foto, minimal 600×400 piksel, {maxFiles} foto sekali unggah. Diperkecil ke WebP dan data
                        lokasi (GPS) dihapus otomatis.
                    </span>
                </button>
                {uploading && <ProgressBar value={progress} label="Progres unggah foto" />}

                <input ref={inputRef} type="file" multiple accept={ACCEPT} className="hidden" onChange={(event) => void pick(event.target.files)} />

                {errors.length > 0 && (
                    <ul className="space-y-1 text-xs text-error-ink">
                        {errors.map((error) => (
                            <li key={error}>{error}</li>
                        ))}
                    </ul>
                )}

                {photos.length === 0 ? (
                    <EmptyState icon={Images} title="Belum ada foto" description="Portofolio baru bisa diterbitkan setelah minimal satu foto diunggah." />
                ) : (
                    <>
                        {missingAlt > 0 && (
                            <p className="text-xs text-warning-ink">
                                {missingAlt} foto belum punya deskripsi — isi lewat tombol pensil agar foto ikut terbaca Google.
                            </p>
                        )}
                        {lastPublishedPhoto && (
                            <p className="text-xs text-muted-foreground">
                                Portofolio yang terbit perlu minimal satu foto — unggah foto lain atau tarik dari situs sebelum menghapus foto ini.
                            </p>
                        )}
                        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {photos.map((photo, index) => {
                                const isCover = photo.id === coverId;

                                return (
                                    <li key={photo.id} className="overflow-hidden rounded-xl border border-border bg-card">
                                        <div className="relative">
                                            <img
                                                src={photo.thumb_url}
                                                alt={photo.alt ?? `Foto ${index + 1}`}
                                                loading="lazy"
                                                className="aspect-4/3 w-full object-cover"
                                            />
                                            <span className="absolute top-2 left-2 rounded-md bg-background/90 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums">
                                                {index + 1}
                                            </span>
                                            {isCover && (
                                                <span className="absolute top-2 right-2 inline-flex items-center gap-1 rounded-md bg-daiku-yellow px-1.5 py-0.5 text-[11px] font-semibold text-daiku-dark">
                                                    <Star className="size-3 fill-current" aria-hidden />
                                                    Sampul
                                                </span>
                                            )}
                                        </div>
                                        <div className="space-y-2 p-2">
                                            <p className={cn('line-clamp-2 min-h-8 text-xs', photo.alt ? 'text-muted-foreground' : 'text-warning-ink')}>
                                                {photo.alt ?? 'Deskripsi belum diisi'}
                                            </p>
                                            <div className="flex flex-wrap items-center gap-0.5">
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    disabled={busy || index === 0}
                                                    onClick={() => move(index, -1)}
                                                    aria-label={`Pindahkan foto ${index + 1} ke depan`}
                                                    title="Pindah ke depan"
                                                >
                                                    <ArrowLeft className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    disabled={busy || index === photos.length - 1}
                                                    onClick={() => move(index, 1)}
                                                    aria-label={`Pindahkan foto ${index + 1} ke belakang`}
                                                    title="Pindah ke belakang"
                                                >
                                                    <ArrowRight className="size-4" />
                                                </Button>
                                                {!isCover && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            router.patch(route('settings.portfolio.photos.cover', { portfolio: itemId, photo: photo.id }), {}, visit)
                                                        }
                                                        aria-label={`Jadikan foto ${index + 1} sampul`}
                                                        title="Jadikan sampul"
                                                    >
                                                        <Star className="size-4" />
                                                    </Button>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    onClick={() => setEditing(photo)}
                                                    aria-label={`Ubah keterangan foto ${index + 1}`}
                                                    title="Ubah keterangan"
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    disabled={busy || lastPublishedPhoto}
                                                    onClick={() => destroy(photo, index + 1)}
                                                    aria-label={`Hapus foto ${index + 1}`}
                                                    title={lastPublishedPhoto ? 'Foto terakhir portofolio yang terbit — tarik dari situs dulu' : 'Hapus'}
                                                    className="ml-auto"
                                                >
                                                    <Trash2 className="size-4 text-error-ink" />
                                                </Button>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
            </div>

            <PhotoDialog itemId={itemId} photo={editing} onClose={() => setEditing(null)} />
        </SectionCard>
    );
}
