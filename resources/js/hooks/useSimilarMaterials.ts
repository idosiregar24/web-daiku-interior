import type { UnitOption } from '@/types';
import { useEffect, useState } from 'react';

export interface SimilarMaterial {
    id: number;
    code: string;
    name: string;
    stock: number;
    cost_price: string;
    unit_id: number;
    unit: UnitOption | null;
    category: string | null;
    similarity: number;
}

export interface MaterialIdentityInput {
    material_category_id?: string | number | null;
    base_name?: string | null;
    spec?: string | null;
    brand?: string | null;
    unit_id?: string | number | null;
}

/**
 * Sprint 11 §5.5 Lapis 3 — "Barang serupa sudah ada" while Logistics
 * types a new catalog item. Debounced read-only lookup against
 * `logistics.materials.similar` (MaterialCatalogService::findSimilar());
 * the server repeats the check on save, so this is guidance, not the gate.
 */
export function useSimilarMaterials(identity: MaterialIdentityInput, enabled: boolean, excludeId?: number) {
    const [items, setItems] = useState<SimilarMaterial[]>([]);
    const [exactId, setExactId] = useState<number | null>(null);
    const key = JSON.stringify([identity.material_category_id, identity.base_name, identity.spec, identity.brand, identity.unit_id, excludeId]);

    useEffect(() => {
        if (!enabled || !identity.base_name || identity.base_name.trim().length < 2) {
            setItems([]);
            setExactId(null);
            return;
        }

        let cancelled = false;
        const timeout = setTimeout(() => {
            window.axios
                .get<{ exact_id: number | null; items: SimilarMaterial[] }>(route('logistics.materials.similar'), {
                    params: {
                        material_category_id: identity.material_category_id || undefined,
                        base_name: identity.base_name,
                        spec: identity.spec || undefined,
                        brand: identity.brand || undefined,
                        unit_id: identity.unit_id || undefined,
                        exclude_id: excludeId,
                    },
                })
                .then(({ data }) => {
                    if (cancelled) return;
                    setItems(data.items);
                    setExactId(data.exact_id);
                })
                .catch(() => {
                    if (cancelled) return;
                    setItems([]);
                    setExactId(null);
                });
        }, 400);

        return () => {
            cancelled = true;
            clearTimeout(timeout);
        };
    }, [key, enabled]);

    return { items, exactId };
}
