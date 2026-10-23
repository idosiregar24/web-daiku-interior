import { quietSuccess } from '@/hooks/useFlashToasts';
import type { Material, PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

/**
 * Sprint 29 Sub 01 (K1/K2) — saves on Detail Proyek reload only what they
 * changed. ProjectController::show() computes every prop lazily, so a visit
 * with `only` costs just those props; the global "Menyimpan…" overlay treats
 * it as a background visit, so the button shows its own spinner (K6).
 *
 * The redirect after a POST/PUT/PATCH/DELETE keeps the partial headers, so
 * `back()` answers with the requested props (tested in LogisticsTest).
 */

/** Props of Pages/Projects/Show a save may reload. */
export type ProjectProp =
    | 'project'
    | 'execution'
    | 'milestones'
    | 'timelineItems'
    | 'tasks'
    | 'activityTarget'
    | 'projectMaterials'
    | 'qaForms'
    | 'itemQa'
    | 'progressLogs'
    | 'overtimeRequests'
    | 'termins'
    | 'documents'
    | 'budget'
    | 'statusNote'
    | 'allocationBreakdown'
    | 'supplierDebts';

/**
 * What each kind of save changes — the one map, so a new form picks its
 * entry instead of guessing. `project` carries `actual_progress` and the
 * phase; `execution` is the Eksekusi tab / stepper / header step.
 */
export const RELOAD = {
    /** Kebutuhan bahan, rencana bahan TM, terima / pakai / kembalikan bahan. */
    materials: ['projectMaterials', 'execution'],
    /** Daily target: buat, ubah, status, geser, tukar, hapus (a repair task done sends its QA item back to "Menunggu QA"). */
    tasks: ['tasks', 'execution', 'activityTarget', 'project', 'itemQa'],
    /** Technical Meeting: jadwal, notulen, peserta, jasa pengawas, TM Selesai. */
    meeting: ['execution', 'project', 'milestones'],
    /** Timeline: tambah, ubah, hapus, tandai selesai. */
    milestones: ['milestones', 'timelineItems', 'execution', 'qaForms', 'project', 'tasks', 'activityTarget'],
    /** Pengawas: laporan mingguan, temuan finishing, DP / pembayaran jasa (a pengecekan / closed finding moves QA items). */
    supervision: ['execution', 'project', 'itemQa'],
    /** Sprint 29 Sub 12 — QA per item RAB: Lolos / Perlu perbaikan (finding, maybe the project COMPLETED). */
    itemQa: ['itemQa', 'execution', 'project', 'statusNote'],
    /** Perpindahan fase oleh PM: Setujui Berangkat ke Lokasi (stepper, header, kartu Eksekusi). */
    phase: ['execution', 'project'],
    /** Catatan Progress. */
    progressLogs: ['progressLogs'],
    /** Anggaran: pos, alokasi, realisasi, overrun. */
    budget: ['budget'],
    /**
     * Sprint 29 Sub 08 — Upah Tukang per item (the card, the DP conditions and the fee live in `execution`).
     * Sub 10 — `project` too: the first Satuan Upah turns the project progress into the progres berbobot.
     * Sprint 38 — `timelineItems`: an item with a wage is the one a timeline's new target picks by itself.
     */
    wages: ['execution', 'project', 'timelineItems'],
    /** Sprint 32 — Pembagian Upah per Orang (only `execution.wageSplit` changes). */
    wageSplit: ['execution'],
    /** Termin & invoice. */
    billing: ['termins', 'documents', 'statusNote'],
} as const satisfies Record<string, readonly ProjectProp[]>;

interface ProjectVisitOptions {
    /** Rapid inline input: no centred success pop-up (K4) — the changed row is the feedback. */
    quiet?: boolean;
}

/**
 * Visit options for a save on Detail Proyek: `router.post(url, data, { ...projectVisit(RELOAD.tasks), onSuccess })`.
 * Always adds the shared `flash` (success / error message) and `navBadges` (menu counts).
 */
export function projectVisit(only: readonly ProjectProp[], { quiet = false }: ProjectVisitOptions = {}) {
    return {
        only: [...only, 'flash', 'navBadges'],
        preserveState: true,
        preserveScroll: true,
        ...(quiet ? quietSuccess : {}),
    };
}

export type CatalogMaterial = Pick<Material, 'id' | 'code' | 'name' | 'unit_id' | 'unit' | 'stock' | 'cost_price'>;
export type ReturnCatalogMaterial = Pick<Material, 'id' | 'name' | 'unit_id'>;

/**
 * The material catalog of Detail Proyek is `Inertia::optional()` — not in the
 * first load. A material form calls `load()` when it opens (or its search gets
 * focus); later partial visits keep it. Empty until loaded, and for viewers
 * without access (the server sends [] then).
 */
export function useProjectCatalog() {
    const { materialOptions, catalogOptions } = usePage<PageProps<{ materialOptions?: CatalogMaterial[]; catalogOptions?: ReturnCatalogMaterial[] }>>().props;
    const [loading, setLoading] = useState(false);
    const loaded = materialOptions !== undefined;

    const load = useCallback(() => {
        if (loaded || loading) return;

        router.reload({
            only: ['materialOptions', 'catalogOptions'],
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }, [loaded, loading]);

    return { materialOptions: materialOptions ?? [], catalogOptions: catalogOptions ?? [], loaded, loading, load };
}
