import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import type { Project, ProjectExecution } from '@/types';
import { router } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { useState } from 'react';

/**
 * Sprint 24 D11 — the PM's "Setujui Berangkat ke Lokasi" (→ PEMASANGAN),
 * locked below the threshold. Sprint 26 H4c/H6d — one component for the
 * Eksekusi card, the page header and the Ringkasan's "Giliran Anda".
 * Renders nothing once approved or when this viewer may not approve.
 * Sprint 28 K5 — asks first: the phase move can't be undone.
 */
export function DepartureButton({
    project,
    execution,
    className,
    size,
}: {
    project: Pick<Project, 'id'>;
    execution: Pick<ProjectExecution, 'progress' | 'threshold' | 'departure' | 'can'>;
    className?: string;
    size?: 'default' | 'sm';
}) {
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);

    if (execution.departure || !execution.can.approveDeparture) {
        return null;
    }

    const reached = execution.progress >= execution.threshold;

    function approve() {
        router.post(
            route('projects.departure.approve', { project: project.id }),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onSuccess: () => setConfirming(false),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <>
            <Button
                className={className}
                size={size}
                disabled={!reached || busy}
                title={reached ? undefined : `Terbuka mulai actual activity ${execution.threshold}% (sekarang ${execution.progress}%).`}
                onClick={() => setConfirming(true)}
            >
                <MapPin className="size-4" />
                Setujui Berangkat ke Lokasi
            </Button>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                busy={busy}
                onConfirm={approve}
                icon={MapPin}
                title="Setujui berangkat ke lokasi?"
                description={`Actual activity ${execution.progress}%. Proyek pindah dari Produksi ke Pemasangan dan tidak bisa dikembalikan. Logistik, Pengawas (QA/QC), Kepala Tim, dan Asisten PM diberi tahu.`}
                confirmLabel="Ya, berangkat"
            />
        </>
    );
}
