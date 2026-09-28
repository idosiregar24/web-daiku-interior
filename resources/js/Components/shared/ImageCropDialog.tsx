import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { RotateCcw, ZoomIn, ZoomOut } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';

export interface CropAspect {
    label: string;
    /** width / height */
    value: number;
}

export interface CropOutput {
    mime: 'image/png' | 'image/jpeg';
    /** Output width bounds in px; height follows the aspect. */
    minWidth: number;
    maxWidth: number;
    /** JPEG quality 0–1 (ignored for PNG). */
    quality?: number;
    /**
     * Let the image zoom out past "cover" so all of it fits in the frame
     * (the empty area stays transparent) — for logos. Photos keep cover.
     */
    allowFit?: boolean;
}

interface ImageCropDialogProps {
    /** The picked file; the dialog is open while this is set. */
    file: File | null;
    title: string;
    aspects: CropAspect[];
    output: CropOutput;
    /** Resolves with the cropped image, already named/typed for upload. */
    onConfirm: (cropped: File) => void;
    /** Upload the picked file untouched instead. */
    onUseOriginal?: (original: File) => void;
    onCancel: () => void;
}

const MAX_ZOOM = 4;

interface View {
    zoom: number;
    x: number;
    y: number;
}

/**
 * Crop-before-upload dialog: drag (mouse/touch) or arrow keys to move, the
 * slider / mouse wheel / +− keys to zoom, preset frame shapes. The frame
 * IS the output — what's inside it is drawn to a canvas at the output size.
 */
export function ImageCropDialog({ file, title, aspects, output, onConfirm, onUseOriginal, onCancel }: ImageCropDialogProps) {
    const [aspect, setAspect] = useState(aspects[0].value);
    const [src, setSrc] = useState<string | null>(null);
    const [natural, setNatural] = useState<{ w: number; h: number } | null>(null);
    const [frame, setFrame] = useState<{ w: number; h: number } | null>(null);
    const [view, setView] = useState<View>({ zoom: 1, x: 0, y: 0 });
    const [busy, setBusy] = useState(false);
    // Callback ref: the dialog content mounts in a portal, so observe the
    // frame whenever the element itself appears.
    const [frameEl, setFrameEl] = useState<HTMLDivElement | null>(null);
    const imgRef = useRef<HTMLImageElement>(null);
    const drag = useRef<{ id: number; x: number; y: number } | null>(null);

    // Object URL for the picked file; reset shape on every new file.
    useEffect(() => {
        if (!file) {
            setSrc(null);
            return;
        }
        const url = URL.createObjectURL(file);
        setSrc(url);
        setNatural(null);
        setAspect(aspects[0].value);

        return () => URL.revokeObjectURL(url);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [file]);

    // Measure the frame (it's sized by CSS from the aspect).
    useLayoutEffect(() => {
        if (!frameEl) return;
        const measure = () => setFrame({ w: frameEl.clientWidth, h: frameEl.clientHeight });
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(frameEl);

        return () => observer.disconnect();
    }, [frameEl]);

    // "cover" scale: the image fills the frame at zoom 1.
    const baseScale = natural && frame ? Math.max(frame.w / natural.w, frame.h / natural.h) : 1;
    const fitZoom = natural && frame ? Math.min(frame.w / natural.w, frame.h / natural.h) / baseScale : 1;
    const minZoom = output.allowFit ? Math.min(1, fitZoom) : 1;
    // Logos start fully visible; photos start filling the frame.
    const startZoom = output.allowFit ? minZoom : 1;

    const clampView = useCallback(
        (next: View): View => {
            if (!natural || !frame) return next;
            const zoom = Math.min(MAX_ZOOM, Math.max(minZoom, next.zoom));
            const dw = natural.w * baseScale * zoom;
            const dh = natural.h * baseScale * zoom;
            // Bigger than the frame → no gaps at the edges; smaller → stay inside.
            const clampAxis = (v: number, size: number, box: number) =>
                size >= box ? Math.min(0, Math.max(box - size, v)) : Math.max(0, Math.min(box - size, v));

            return { zoom, x: clampAxis(next.x, dw, frame.w), y: clampAxis(next.y, dh, frame.h) };
        },
        [natural, frame, baseScale, minZoom],
    );

    // Centre the image whenever the frame shape or image changes.
    useEffect(() => {
        if (!natural || !frame) return;
        const zoom = startZoom;
        const dw = natural.w * baseScale * zoom;
        const dh = natural.h * baseScale * zoom;
        setView(clampView({ zoom, x: (frame.w - dw) / 2, y: (frame.h - dh) / 2 }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [natural, frame?.w, frame?.h]);

    /** Zoom keeping the frame centre (or a pointer position) fixed. */
    function zoomTo(zoom: number, anchor?: { x: number; y: number }) {
        if (!natural || !frame) return;
        const ax = anchor?.x ?? frame.w / 2;
        const ay = anchor?.y ?? frame.h / 2;
        setView((current) => {
            const ratio = Math.min(MAX_ZOOM, Math.max(minZoom, zoom)) / current.zoom;

            return clampView({
                zoom: current.zoom * ratio,
                x: ax - (ax - current.x) * ratio,
                y: ay - (ay - current.y) * ratio,
            });
        });
    }

    function pan(dx: number, dy: number) {
        setView((current) => clampView({ ...current, x: current.x + dx, y: current.y + dy }));
    }

    async function applyCrop() {
        const img = imgRef.current;
        if (!img || !natural || !frame) return;
        setBusy(true);

        // Output at the crop's native resolution, within the allowed bounds.
        const nativeWidth = frame.w / (baseScale * view.zoom);
        const width = Math.round(Math.min(output.maxWidth, Math.max(output.minWidth, nativeWidth)));
        const height = Math.round(width / aspect);
        const k = width / frame.w;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            setBusy(false);
            return;
        }
        ctx.imageSmoothingQuality = 'high';
        if (output.mime === 'image/jpeg') {
            // JPEG has no alpha — fill with the page background token so
            // transparent pixels don't turn black.
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--background').trim() || 'white';
            ctx.fillRect(0, 0, width, height);
        }
        const scale = baseScale * view.zoom * k;
        ctx.drawImage(img, view.x * k, view.y * k, natural.w * scale, natural.h * scale);

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, output.mime, output.quality ?? 0.9));
        setBusy(false);
        if (!blob) return;

        const base = (file?.name ?? 'gambar').replace(/\.[^.]+$/, '');
        const extension = output.mime === 'image/png' ? 'png' : 'jpg';
        onConfirm(new File([blob], `${base}-crop.${extension}`, { type: output.mime }));
    }

    const displayWidth = natural ? natural.w * baseScale * view.zoom : 0;
    const displayHeight = natural ? natural.h * baseScale * view.zoom : 0;
    const zoomPercent = Math.round(view.zoom * 100);

    return (
        <Dialog open={file !== null} onOpenChange={(open) => !open && onCancel()}>
            <DialogContent className="gap-5 sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        Geser gambar untuk mengatur posisi, gunakan slider atau scroll untuk zoom. Area di dalam bingkai
                        yang akan diunggah.
                    </DialogDescription>
                </DialogHeader>

                {aspects.length > 1 && (
                    <div className="flex flex-wrap gap-2" role="group" aria-label="Bentuk bingkai">
                        {aspects.map((option) => (
                            <Button
                                key={option.label}
                                type="button"
                                size="sm"
                                variant={option.value === aspect ? 'default' : 'outline'}
                                aria-pressed={option.value === aspect}
                                onClick={() => setAspect(option.value)}
                            >
                                {option.label}
                            </Button>
                        ))}
                    </div>
                )}

                <div className="flex justify-center rounded-lg bg-daiku-dark/90 p-4">
                    <div
                        ref={setFrameEl}
                        tabIndex={0}
                        role="application"
                        aria-label="Area crop — geser dengan panah, zoom dengan + dan −"
                        className="relative w-full cursor-grab touch-none overflow-hidden rounded-md bg-[repeating-conic-gradient(var(--color-daiku-gray)_0%_25%,var(--color-background)_0%_50%)] bg-size-[16px_16px] outline-none select-none focus-visible:ring-3 focus-visible:ring-ring/60 active:cursor-grabbing"
                        style={{ aspectRatio: aspect, maxHeight: '55vh', maxWidth: `calc(55vh * ${aspect})` }}
                        onPointerDown={(event) => {
                            event.currentTarget.setPointerCapture(event.pointerId);
                            drag.current = { id: event.pointerId, x: event.clientX, y: event.clientY };
                        }}
                        onPointerMove={(event) => {
                            if (!drag.current || drag.current.id !== event.pointerId) return;
                            pan(event.clientX - drag.current.x, event.clientY - drag.current.y);
                            drag.current = { id: event.pointerId, x: event.clientX, y: event.clientY };
                        }}
                        onPointerUp={() => (drag.current = null)}
                        onPointerCancel={() => (drag.current = null)}
                        onWheel={(event) => {
                            const rect = event.currentTarget.getBoundingClientRect();
                            zoomTo(view.zoom * (event.deltaY < 0 ? 1.08 : 1 / 1.08), {
                                x: event.clientX - rect.left,
                                y: event.clientY - rect.top,
                            });
                        }}
                        onKeyDown={(event) => {
                            const step = event.shiftKey ? 40 : 10;
                            const actions: Record<string, () => void> = {
                                ArrowLeft: () => pan(step, 0),
                                ArrowRight: () => pan(-step, 0),
                                ArrowUp: () => pan(0, step),
                                ArrowDown: () => pan(0, -step),
                                '+': () => zoomTo(view.zoom * 1.1),
                                '=': () => zoomTo(view.zoom * 1.1),
                                '-': () => zoomTo(view.zoom / 1.1),
                            };
                            if (actions[event.key]) {
                                event.preventDefault();
                                actions[event.key]();
                            }
                        }}
                    >
                        {src && (
                            <img
                                ref={imgRef}
                                src={src}
                                alt=""
                                draggable={false}
                                onLoad={(event) =>
                                    setNatural({ w: event.currentTarget.naturalWidth, h: event.currentTarget.naturalHeight })
                                }
                                className={cn('pointer-events-none absolute top-0 left-0 max-w-none', !natural && 'invisible')}
                                style={{ width: displayWidth, height: displayHeight, transform: `translate(${view.x}px, ${view.y}px)` }}
                            />
                        )}
                        {/* rule-of-thirds guides */}
                        <div aria-hidden className="pointer-events-none absolute inset-0 grid grid-cols-3 grid-rows-3">
                            {Array.from({ length: 9 }).map((_, i) => (
                                <span key={i} className="border-[0.5px] border-background/40" />
                            ))}
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <Button type="button" variant="ghost" size="icon-sm" aria-label="Perkecil" onClick={() => zoomTo(view.zoom / 1.2)}>
                        <ZoomOut />
                    </Button>
                    <input
                        type="range"
                        min={minZoom}
                        max={MAX_ZOOM}
                        step={0.01}
                        value={view.zoom}
                        onChange={(event) => zoomTo(Number(event.target.value))}
                        aria-label="Zoom"
                        className="h-1.5 flex-1 cursor-pointer accent-daiku-yellow-dark"
                    />
                    <Button type="button" variant="ghost" size="icon-sm" aria-label="Perbesar" onClick={() => zoomTo(view.zoom * 1.2)}>
                        <ZoomIn />
                    </Button>
                    <span className="w-12 text-right text-xs text-muted-foreground tabular-nums">{zoomPercent}%</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            if (!natural || !frame) return;
                            const dw = natural.w * baseScale * startZoom;
                            const dh = natural.h * baseScale * startZoom;
                            setView(clampView({ zoom: startZoom, x: (frame.w - dw) / 2, y: (frame.h - dh) / 2 }));
                        }}
                    >
                        <RotateCcw className="size-3.5" />
                        Reset
                    </Button>
                </div>

                <DialogFooter className="sm:justify-between">
                    {onUseOriginal && file ? (
                        <Button type="button" variant="ghost" onClick={() => onUseOriginal(file)} disabled={busy}>
                            Unggah tanpa crop
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-col-reverse gap-2 sm:flex-row">
                        <Button type="button" variant="outline" onClick={onCancel} disabled={busy}>
                            Batal
                        </Button>
                        <Button type="button" onClick={applyCrop} disabled={busy || !natural}>
                            Terapkan & Unggah
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
