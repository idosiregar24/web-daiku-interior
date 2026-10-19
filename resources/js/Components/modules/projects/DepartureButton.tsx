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

    if (execution.departure || !execution.can.approveDeparture) {
        return null;
    }

    const reached = execution.progress >= execution.threshold;

    return (
        <Button
            className={className}
            size={size}
            disabled={!reached || busy}
            title={reached ? undefined : `Terbuka mulai actual activity ${execution.threshold}% (sekarang ${execution.progress}%).`}
            onClick={() =>
                router.post(
                    route('projects.departure.approve', { project: project.id }),
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setBusy(true),
                        onFinish: () => setBusy(false),
                    },
                )
            }
        >
            <MapPin className="size-4" />
            Setujui Berangkat ke Lokasi
        </Button>
    );
}
