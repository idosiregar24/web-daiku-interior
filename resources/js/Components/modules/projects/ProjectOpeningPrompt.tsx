import { OpenProjectDialog } from '@/Components/modules/projects/OpenProjectDialog';
import { Button } from '@/Components/ui/button';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

const SNOOZE_KEY = 'daiku.projectOpenings.snoozed';

function readSnoozed(): number[] {
    try {
        return JSON.parse(window.sessionStorage.getItem(SNOOZE_KEY) ?? '[]') as number[];
    } catch {
        return [];
    }
}

function writeSnoozed(ids: number[]) {
    try {
        window.sessionStorage.setItem(SNOOZE_KEY, JSON.stringify(ids));
    } catch {
        // Storage blocked — the pop-up simply comes back on the next page.
    }
}

/**
 * The "Buka Proyek" pop-up, on any page, for the oldest RAB Proyek the
 * client approved that has no project yet — Sprint 23 K13: shown to the PM
 * who approved that RAB (every PM when an Asisten PM approved it).
 * "Nanti" hides that one for this browser session; it stays listed under
 * Proyek → "Menunggu Dibuka".
 */
export function ProjectOpeningPrompt() {
    const { pendingProjectOpenings } = usePage<PageProps>().props;
    const [snoozed, setSnoozed] = useState<number[]>(() => (typeof window === 'undefined' ? [] : readSnoozed()));

    const opening = pendingProjectOpenings?.openings.find((candidate) => !snoozed.includes(candidate.id));

    if (!pendingProjectOpenings || !opening) {
        return null;
    }

    function snooze() {
        const next = [...snoozed, opening!.id];
        setSnoozed(next);
        writeSnoozed(next);
    }

    return (
        <OpenProjectDialog
            open
            onOpenChange={(open) => !open && snooze()}
            opening={opening}
            options={pendingProjectOpenings}
            secondaryAction={
                <Button type="button" variant="outline" onClick={snooze}>
                    Nanti
                </Button>
            }
        />
    );
}
