import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { formatDateTime } from '@/lib/format';
import type { QuotationNote } from '@/types';
import { MessageSquareText } from 'lucide-react';

const KIND_LABEL: Record<QuotationNote['kind'], string> = {
    CEO: 'Catatan CEO',
    CLIENT: 'Klien (link penawaran)',
    MARKETING: 'Marketing',
};

const KIND_TONE = { CEO: 'info', CLIENT: 'warning', MARKETING: 'neutral' } as const;

/**
 * Sprint 23 K9 / K11 — the RAB's notes, newest first: the CEO's
 * information-only notes, the client's "Tidak Setuju" reason and what
 * Marketing added before forwarding it. Shown to the Estimator, PM side,
 * Marketing and CEO (the controller sends none to anyone else).
 */
export function QuotationNotesCard({ notes, currentVersion }: { notes: QuotationNote[]; currentVersion: number }) {
    if (notes.length === 0) {
        return null;
    }

    return (
        <SectionCard
            title="Catatan RAB"
            icon={MessageSquareText}
            description="Catatan CEO bersifat informasi — tidak mengubah alur RAB."
            className="mt-6"
            contentClassName="space-y-3"
        >
            {[...notes].reverse().map((note) => (
                <div key={note.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusChip status={note.kind} label={KIND_LABEL[note.kind]} tone={KIND_TONE[note.kind]} />
                        <span className="text-xs text-daiku-muted">
                            {note.author?.name ?? 'Klien'} · versi {note.version}
                            {note.version !== currentVersion && ' (sebelumnya)'} · {formatDateTime(note.created_at)}
                        </span>
                    </div>
                    <p className="mt-1 text-sm whitespace-pre-line text-foreground">{note.note}</p>
                </div>
            ))}
        </SectionCard>
    );
}
