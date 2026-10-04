import { Notice } from '@/Components/shared/Notice';
import { Button } from '@/Components/ui/button';
import type { SimilarMaterial } from '@/hooks/useSimilarMaterials';
import { formatQty } from '@/lib/format';
import { AlertTriangle, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';

interface SimilarMaterialsPanelProps {
    items: SimilarMaterial[];
    exactId: number | null;
    /** "Pakai ini" — only where picking an existing item makes sense (request review). */
    onUse?: (material: SimilarMaterial) => void;
    /** The "Tetap buat barang baru — alasan" field, shown when similar (not exact) items exist. */
    reasonField?: ReactNode;
}

/**
 * Sprint 11 §5.5 Lapis 3 — the warning shown before a new catalog item is
 * saved: an exact match is refused outright, similar ones need a reason.
 */
export function SimilarMaterialsPanel({ items, exactId, onUse, reasonField }: SimilarMaterialsPanelProps) {
    if (items.length === 0 && exactId === null) {
        return null;
    }

    const exact = items.find((item) => item.id === exactId);

    return (
        <Notice tone={exactId ? 'error' : 'warning'} icon={exactId ? XCircle : AlertTriangle} className="items-start">
            <div className="w-full space-y-2">
                <p className="font-medium">
                    {exactId
                        ? `Barang ini sudah ada di katalog${exact ? `: ${exact.code} ${exact.name}` : ''} — pakai barang itu.`
                        : 'Barang serupa sudah ada:'}
                </p>
                {items.length > 0 && (
                    <ul className="space-y-1">
                        {items.map((item) => (
                            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    <span className="font-medium">{item.code}</span> {item.name} ({item.unit?.code}) · stok {formatQty(item.stock)}
                                </span>
                                {onUse && (
                                    <Button type="button" size="sm" variant="outline" onClick={() => onUse(item)}>
                                        Pakai ini
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {!exactId && reasonField}
            </div>
        </Notice>
    );
}
