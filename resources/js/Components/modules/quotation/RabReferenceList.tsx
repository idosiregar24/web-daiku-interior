import { SectionCard } from '@/Components/shared/SectionCard';
import type { QuotationReference } from '@/types';
import { ExternalLink, Images } from 'lucide-react';

function hostOf(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

/**
 * Sprint 14 Sub 01 — the links and photos sent with the RAB request, for
 * the Estimator building it and the reviewers. Internal only: the client's
 * link and PDF never show these. Photos open full size in a new tab.
 */
export function RabReferenceList({ references, requester }: { references: QuotationReference[]; requester?: string }) {
    const links = references.filter((reference) => reference.kind === 'LINK' && reference.url);
    const photos = references.filter((reference) => reference.kind === 'PHOTO' && reference.photo_url);

    if (links.length === 0 && photos.length === 0) {
        return null;
    }

    return (
        <SectionCard
            title="Referensi Permintaan"
            description={`Dari ${requester ?? 'Marketing'} — hanya untuk tim internal, tidak terlihat oleh klien.`}
            icon={Images}
            className="mb-6"
        >
            {links.length > 0 && (
                <ul className="mb-4 space-y-1.5 last:mb-0">
                    {links.map((link) => (
                        <li key={link.id}>
                            <a
                                href={link.url!}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex max-w-full items-center gap-1.5 text-sm text-foreground underline decoration-daiku-yellow underline-offset-2"
                            >
                                <ExternalLink className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="truncate">
                                    <span className="font-medium">{hostOf(link.url!)}</span>
                                    <span className="text-muted-foreground"> · {link.url}</span>
                                </span>
                            </a>
                        </li>
                    ))}
                </ul>
            )}
            {photos.length > 0 && (
                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    {photos.map((photo) => (
                        <a
                            key={photo.id}
                            href={photo.photo_url!}
                            target="_blank"
                            rel="noopener noreferrer"
                            title={photo.original_name ?? 'Foto referensi'}
                            className="aspect-square overflow-hidden rounded-lg ring-1 ring-border"
                        >
                            <img src={photo.photo_url!} alt={photo.original_name ?? 'Foto referensi'} loading="lazy" className="size-full object-cover" />
                        </a>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}
