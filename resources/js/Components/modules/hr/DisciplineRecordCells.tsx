import { StatusChip } from '@/Components/shared/StatusChip';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ExternalLink } from 'lucide-react';
import { DISCIPLINE_TYPE_LABEL, type DisciplineRecordRow, isSpType } from './DisciplineCommon';

/** Type chip + state (Berlaku / Kedaluwarsa / Dibatalkan) of one record — recap table and profile tab. */
export function DisciplineTypeCell({ record }: { record: DisciplineRecordRow }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <StatusChip
                status={record.type}
                label={DISCIPLINE_TYPE_LABEL[record.type]}
                className={cn(record.is_voided && 'line-through opacity-70')}
            />
            {record.is_voided && <StatusChip status="VOIDED" tone="neutral" label="Dibatalkan" />}
            {!record.is_voided && record.is_active_sp && <StatusChip status="ACTIVE_SP" tone="error" label="Berlaku" />}
            {!record.is_voided && !record.is_active_sp && isSpType(record.type) && (
                <StatusChip status="EXPIRED_SP" tone="neutral" label="Kedaluwarsa" />
            )}
        </div>
    );
}

/** Description, document link, and the cancellation note/target. */
export function DisciplineDescriptionCell({ record }: { record: DisciplineRecordRow }) {
    return (
        <div className="max-w-md space-y-1 text-sm">
            <p className={cn('whitespace-pre-line text-daiku-dark', record.is_voided && 'text-daiku-muted line-through')}>
                {record.type === 'PEMBATALAN' && record.voids
                    ? `Membatalkan ${DISCIPLINE_TYPE_LABEL[record.voids.type]} ${formatDate(record.voids.issued_on)}: `
                    : null}
                {record.description}
            </p>
            {record.link && (
                <a
                    href={record.link}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-xs text-daiku-dark underline decoration-daiku-yellow underline-offset-4"
                >
                    <ExternalLink className="size-3" />
                    Dokumen
                </a>
            )}
            {record.void && (
                <p className="text-xs text-daiku-muted">
                    Dibatalkan {formatDate(record.void.issued_on)}
                    {record.void.recorder && ` oleh ${record.void.recorder}`}: {record.void.reason}
                </p>
            )}
        </div>
    );
}
