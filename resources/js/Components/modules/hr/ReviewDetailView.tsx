import {
    formatScore,
    gradeTone,
    REVIEW_ASPECT_LABEL,
    REVIEW_ASPECTS,
    REVIEW_KPI_CAP,
    type ReviewDetail,
} from '@/Components/modules/hr/ReviewShared';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { formatDateTime } from '@/lib/format';
import { Award, Gavel, MessageSquareText, Sparkles, Target, Users } from 'lucide-react';

/** The four score tiles: final score + grade, KPI, qualitative, discipline. */
export function ReviewScoreTiles({ review }: { review: ReviewDetail }) {
    const w = review.weights;
    const effective = review.effective_weights;

    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Nilai Akhir"
                icon={Award}
                tone={gradeTone(review.grade)}
                value={review.final_score === null ? '—' : `${formatScore(review.final_score, 2)} · ${review.grade}`}
                hint={review.final_score === null ? 'Lengkapi keempat aspek kualitatif' : 'Grade A ≥ 90 · B ≥ 80 · C ≥ 70 · D ≥ 60'}
            />
            <StatCard
                label={`KPI · bobot ${w.kpi}%`}
                icon={Target}
                value={formatScore(review.kpi_average, 2)}
                hint={
                    review.kpi_average === null
                        ? 'Belum ada bulan KPI yang ditutup — bobot dialihkan'
                        : `Rata-rata ${review.kpi_months} bulan ditutup${review.kpi_capped ? ` · dihitung maks. ${REVIEW_KPI_CAP}` : ''}`
                }
            />
            <StatCard
                label={`Kualitatif · bobot ${review.kpi_redistributed && effective ? formatScore(effective.qualitative) : w.qualitative}%`}
                icon={Users}
                value={formatScore(review.qualitative_score, 2)}
                hint="Rata-rata 4 aspek (skala 1–5) × 20"
            />
            <StatCard
                label={`Kedisiplinan · bobot ${review.kpi_redistributed && effective ? formatScore(effective.discipline) : w.discipline}%`}
                icon={Gavel}
                tone={review.discipline_score !== null && review.discipline_score < 100 ? 'warning' : 'default'}
                value={formatScore(review.discipline_score, 2)}
                hint="100 − teguran/SP yang terbit di semester ini"
            />
        </div>
    );
}

export function ReviewDisciplineDetails({ review }: { review: ReviewDetail }) {
    const s = review.discipline_summary;

    return (
        <DetailList className="sm:grid-cols-3">
            <DetailItem label="Teguran lisan">{s?.teguran_lisan ?? 0}</DetailItem>
            <DetailItem label="SP1 / SP2 / SP3">
                {s?.sp1 ?? 0} / {s?.sp2 ?? 0} / {s?.sp3 ?? 0}
            </DetailItem>
            <DetailItem label="Catatan">{s?.catatan ?? 0}</DetailItem>
            <DetailItem label="SP berlaku di akhir semester" className="sm:col-span-3">
                {s?.active_sp_at_end ?? 'Tidak ada'}
            </DetailItem>
        </DetailList>
    );
}

/**
 * Read-only body of a review (detail page when not editable, the profile
 * tab, "Milik Saya"). Score tiles + aspects + discipline + recommendation
 * + approval trail.
 */
export function ReviewDetailView({ review, showTiles = true }: { review: ReviewDetail; showTiles?: boolean }) {
    return (
        <div className="space-y-6">
            {showTiles && <ReviewScoreTiles review={review} />}

            <div className="grid gap-6 lg:grid-cols-2">
                <SectionCard title="Aspek Kualitatif" description="Skala 1 (kurang) – 5 (sangat baik)" icon={Sparkles}>
                    <DetailList>
                        {REVIEW_ASPECTS.map((aspect) => (
                            <DetailItem key={aspect} label={REVIEW_ASPECT_LABEL[aspect]} valueClassName="tabular-nums">
                                {review.qualitative?.[aspect] ? `${review.qualitative[aspect]} / 5` : '—'}
                            </DetailItem>
                        ))}
                    </DetailList>
                </SectionCard>

                <SectionCard title="Kedisiplinan Semester Ini" description="Catatan efektif (tidak dibatalkan) yang terbit di semester ini" icon={Gavel}>
                    <ReviewDisciplineDetails review={review} />
                </SectionCard>
            </div>

            <SectionCard title="Rekomendasi & Catatan" icon={MessageSquareText}>
                <DetailList>
                    <DetailItem label="Rekomendasi">{review.recommendation_label ?? '—'}</DetailItem>
                    <DetailItem label="Penilai (SDM)">{review.reviewer?.name ?? '—'}</DetailItem>
                    <DetailItem label="Catatan" className="sm:col-span-2" valueClassName="whitespace-pre-line">
                        {review.notes ?? '—'}
                    </DetailItem>
                    <DetailItem label="Diajukan">{review.submitted_at ? formatDateTime(review.submitted_at) : '—'}</DetailItem>
                    <DetailItem label="Disetujui CEO">
                        {review.approved_at ? `${review.approver?.name ?? 'CEO'} · ${formatDateTime(review.approved_at)}` : '—'}
                    </DetailItem>
                    <DetailItem label="Dikonfirmasi karyawan">
                        {review.acknowledged_at ? formatDateTime(review.acknowledged_at) : '—'}
                    </DetailItem>
                </DetailList>
            </SectionCard>
        </div>
    );
}
