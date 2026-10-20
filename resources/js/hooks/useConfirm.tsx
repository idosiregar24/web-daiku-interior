import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { useLastDefined } from '@/hooks/useLastDefined';
import { type ReactNode, useCallback, useState } from 'react';

interface ConfirmOptions {
    title: string;
    description: ReactNode;
    /** Defaults to "Ya, hapus". */
    confirmLabel?: string;
    /** Defaults to `danger` — most callers confirm a delete. */
    tone?: 'default' | 'danger';
}

type PendingConfirm = ConfirmOptions & { resolve: (confirmed: boolean) => void };

/**
 * Sprint 28 K16 — the browser's `confirm()` as the Daiku ConfirmDialog:
 *
 *   const { ask, dialog } = useConfirm();
 *   async function remove(row) {
 *       if (!(await ask({ title: 'Hapus vendor?', description: `"${row.name}" dihapus.` }))) return;
 *       router.delete(…);
 *   }
 *   return <>…{dialog}</>;
 *
 * For flows with their own busy state / variants use ConfirmDialog directly.
 */
export function useConfirm() {
    const [pending, setPending] = useState<PendingConfirm | null>(null);
    // Keeps the text while the dialog animates closed (K12).
    const shown = useLastDefined(pending);

    const ask = useCallback((options: ConfirmOptions) => new Promise<boolean>((resolve) => setPending({ ...options, resolve })), []);

    function settle(confirmed: boolean) {
        pending?.resolve(confirmed);
        setPending(null);
    }

    const dialog = (
        <ConfirmDialog
            open={pending !== null}
            onOpenChange={(open) => !open && settle(false)}
            onConfirm={() => settle(true)}
            tone={shown?.tone ?? 'danger'}
            title={shown?.title ?? ''}
            description={shown?.description ?? ''}
            confirmLabel={shown?.confirmLabel ?? 'Ya, hapus'}
        />
    );

    return { ask, dialog };
}
