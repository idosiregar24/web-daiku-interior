import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { ImagePlus, Link2, Plus, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

/** Mirrors App\Models\QuotationReference limits (ValidatesRabReferences). */
export const MAX_REFERENCE_LINKS = 5;
export const MAX_REFERENCE_PHOTOS = 8;
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
/** Longest side after shrinking — plenty to read a room, a fraction of a phone photo's size. */
const MAX_PHOTO_SIDE = 1600;

/**
 * Shrink a phone photo before upload (JPEG, longest side ≤ 1600 px):
 * a 4–6 MB camera shot becomes a few hundred KB, so 8 photos fit the
 * server's request limit (PHP post_max_size / nginx client_max_body_size)
 * and a Marketing on mobile data uploads quickly. Falls back to the
 * original file when the browser can't decode it or shrinking doesn't help.
 */
async function shrinkPhoto(file: File): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, MAX_PHOTO_SIDE / Math.max(bitmap.width, bitmap.height));
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

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
        if (!blob || blob.size >= file.size) return file;

        return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}

export interface RabReferences {
    links: string[];
    photos: File[];
}

export const EMPTY_REFERENCES: RabReferences = { links: [], photos: [] };

/** The request payload part — files make Inertia send multipart automatically. */
export function referencePayload(references: RabReferences) {
    return { reference_links: references.links, reference_photos: references.photos };
}

function isHttpUrl(value: string): boolean {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

/**
 * Sprint 14 Sub 01 — reference links and photos sent with a RAB request
 * ("Minta RAB …", "Minta RAB Tambahan"), so the Estimator has what the
 * client showed without asking again. Photos stay internal (never on the
 * client's link). `errors` = the Inertia error bag of the request.
 */
export function RabReferenceFields({
    value,
    onChange,
    errors,
}: {
    value: RabReferences;
    onChange: (next: RabReferences) => void;
    errors: Record<string, string>;
}) {
    const [draft, setDraft] = useState('');
    const [localError, setLocalError] = useState<string | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);

    const previews = useMemo(() => value.photos.map((photo) => URL.createObjectURL(photo)), [value.photos]);
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    const serverError = Object.entries(errors).find(([key]) => key.startsWith('reference_'))?.[1];

    function addLink() {
        const url = draft.trim();
        if (!url) return;
        if (!isHttpUrl(url)) {
            setLocalError('Link harus diawali http:// atau https://');
            return;
        }
        if (value.links.length >= MAX_REFERENCE_LINKS) {
            setLocalError(`Maksimal ${MAX_REFERENCE_LINKS} link.`);
            return;
        }
        setLocalError(null);
        setDraft('');
        if (!value.links.includes(url)) onChange({ ...value, links: [...value.links, url] });
    }

    const [shrinking, setShrinking] = useState(false);

    async function addPhotos(files: FileList | null) {
        if (!files) return;
        const room = Math.max(MAX_REFERENCE_PHOTOS - value.photos.length, 0);
        const images = Array.from(files).filter((file) => PHOTO_TYPES.includes(file.type));
        const notImages = files.length - images.length;

        setShrinking(true);
        const shrunk = await Promise.all(images.slice(0, room).map(shrinkPhoto));
        setShrinking(false);
        const valid = shrunk.filter((file) => file.size <= MAX_PHOTO_BYTES);

        setLocalError(
            notImages > 0 || valid.length < shrunk.length
                ? 'Sebagian file dilewati — hanya foto JPG/PNG/WEBP (maks. 5 MB).'
                : images.length > room
                  ? `Maksimal ${MAX_REFERENCE_PHOTOS} foto.`
                  : null,
        );
        onChange({ ...value, photos: [...value.photos, ...valid] });
        if (fileRef.current) fileRef.current.value = '';
    }

    return (
        <div className="space-y-3 rounded-lg border border-border p-3">
            <div>
                <p className="text-sm font-medium text-foreground">Referensi (opsional)</p>
                <p className="text-xs text-muted-foreground">
                    Link (Google Drive, Pinterest, Instagram, Maps, video) dan foto lokasi/contoh dari klien — hanya untuk tim internal.
                </p>
            </div>

            <div className="space-y-2">
                <Label htmlFor="rab-reference-link" className="text-xs">
                    Link referensi ({value.links.length}/{MAX_REFERENCE_LINKS})
                </Label>
                <div className="flex gap-2">
                    <Input
                        id="rab-reference-link"
                        type="url"
                        inputMode="url"
                        value={draft}
                        placeholder="https://…"
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                addLink();
                            }
                        }}
                    />
                    <Button type="button" variant="outline" onClick={addLink} disabled={value.links.length >= MAX_REFERENCE_LINKS}>
                        <Plus className="size-4" />
                        Tambah
                    </Button>
                </div>
                {value.links.length > 0 && (
                    <ul className="space-y-1">
                        {value.links.map((link) => (
                            <li key={link} className="flex items-center gap-2 rounded-md bg-daiku-gray px-2 py-1 text-xs">
                                <Link2 className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="min-w-0 flex-1 truncate">{link}</span>
                                <button
                                    type="button"
                                    aria-label="Hapus link"
                                    onClick={() => onChange({ ...value, links: value.links.filter((item) => item !== link) })}
                                    className="text-muted-foreground hover:text-foreground"
                                >
                                    <X className="size-3.5" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="space-y-2">
                <p className="text-xs font-medium">
                    Foto referensi ({value.photos.length}/{MAX_REFERENCE_PHOTOS}) — JPG/PNG/WEBP, otomatis dikecilkan sebelum diunggah
                </p>
                <div className="grid grid-cols-4 gap-2">
                    {value.photos.map((photo, index) => (
                        <div key={`${photo.name}-${index}`} className="relative aspect-square overflow-hidden rounded-md ring-1 ring-border">
                            <img src={previews[index]} alt={photo.name} className="size-full object-cover" />
                            <button
                                type="button"
                                aria-label={`Hapus ${photo.name}`}
                                onClick={() => onChange({ ...value, photos: value.photos.filter((_, i) => i !== index) })}
                                className="absolute top-1 right-1 flex size-6 items-center justify-center rounded-full bg-background/90 shadow-xs"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    ))}
                    {value.photos.length < MAX_REFERENCE_PHOTOS && (
                        <button
                            type="button"
                            onClick={() => fileRef.current?.click()}
                            className="flex aspect-square flex-col items-center justify-center gap-1 rounded-md border border-dashed border-border text-xs text-muted-foreground hover:bg-daiku-gray/60"
                        >
                            <ImagePlus className="size-5" />
                            {shrinking ? 'Memproses…' : 'Tambah'}
                        </button>
                    )}
                </div>
                <input
                    ref={fileRef}
                    type="file"
                    accept={PHOTO_TYPES.join(',')}
                    multiple
                    className="hidden"
                    onChange={(event) => void addPhotos(event.target.files)}
                />
            </div>

            {(localError || serverError) && <p className="text-sm text-destructive">{localError ?? serverError}</p>}
        </div>
    );
}
