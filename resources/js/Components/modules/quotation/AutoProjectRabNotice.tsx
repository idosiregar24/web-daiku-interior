import { Notice } from '@/Components/shared/Notice';
import { StatusChip } from '@/Components/shared/StatusChip';
import { formatDateTime } from '@/lib/format';
import type { QuotationStatus } from '@/types';
import { Link } from '@inertiajs/react';

/** Sprint 17 Sub 04 — `Quotation::runningProjectRabSummary()`: the lead's RAB Proyek still running. */
export interface RunningProjectRab {
    id: number;
    title: string;
    status: QuotationStatus;
    requested_at: string | null;
    /** Asked for automatically when the client approved the design (`requested_via = DESIGN_ACC`). */
    auto: boolean;
    /** `auto` and still DIMINTA / DRAFT — the notice shows only then. */
    pending_auto: boolean;
}

/** Why "Buat RAB → RAB Proyek / Lainnya" is unavailable while a RAB Proyek runs (the server refuses a second one). */
export function runningProjectRabReason(rab: RunningProjectRab): string {
    return rab.auto
        ? 'Permintaan RAB Proyek sudah otomatis dikirim ke Estimator — tidak perlu meminta lagi.'
        : `${rab.title} untuk klien ini masih berjalan — selesaikan atau batalkan dulu.`;
}

/**
 * Sprint 17 Sub 04 (T6) — on the lead and design pages, while the RAB
 * Proyek requested automatically by the design's client approval is still
 * waiting for / being drafted by the Estimator: no need to ask again.
 */
export function AutoProjectRabNotice({ rab, className }: { rab: RunningProjectRab | null; className?: string }) {
    if (!rab?.pending_auto) {
        return null;
    }

    return (
        <Notice tone="info" className={className}>
            Permintaan RAB Proyek sudah otomatis dikirim ke Estimator pada {formatDateTime(rab.requested_at)} — tidak perlu
            meminta lagi. <span className="inline-flex items-center gap-1 align-middle">Status: <StatusChip status={rab.status} /></span>{' '}
            <Link
                href={route('quotations.show', { quotation: rab.id })}
                className="font-medium underline decoration-daiku-yellow underline-offset-2"
            >
                Lihat RAB
            </Link>
        </Notice>
    );
}
