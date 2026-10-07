import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { LeadFormDialog } from '@/Components/modules/crm/LeadFormDialog';
import { LeadStatusDialog } from '@/Components/modules/crm/LeadStatusDialog';
import { LeadTimeline } from '@/Components/modules/crm/LeadTimeline';
import { SubmitLeadRequestDialog } from '@/Components/modules/crm/SubmitLeadRequestDialog';
import { AutoProjectRabNotice, type RunningProjectRab } from '@/Components/modules/quotation/AutoProjectRabNotice';
import { RabHistoryCard } from '@/Components/modules/quotation/RabHistoryCard';
import { QuotationDecisionDialog } from '@/Components/modules/quotation/QuotationDecisionDialog';
import { isQuotationExpired } from '@/Components/modules/quotation/QuotationExpiryNotice';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import type {
    Design,
    Lead,
    CityOption,
    LeadCategoryOption,
    LeadFollowUp,
    LeadSourceOption,
    LeadSurvey,
    PipelineLogEntry,
    Project,
    Quotation,
    User,
} from '@/types';
import { Head, Link } from '@inertiajs/react';
import { startOfToday } from 'date-fns';
import {
    ArrowRight,
    FileText,
    FolderKanban,
    History,
    type LucideIcon,
    MapPin,
    Palette,
    PenLine,
    Send,
    UserRound,
    Workflow,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

type LeadDetail = Omit<Lead, 'design' | 'quotation' | 'follow_ups' | 'surveys'> & {
    follow_ups: LeadFollowUp[];
    surveys: LeadSurvey[];
    design: (Pick<Design, 'id' | 'status' | 'deadline' | 'client_acc'> & { pic?: Pick<User, 'id' | 'name'> | null }) | null;
    quotation: Pick<Quotation, 'id' | 'type' | 'custom_name' | 'status' | 'total_amount' | 'version' | 'valid_until' | 'client_approved_at'> | null;
    /** Sprint 12 #6 / Sprint 14 Sub 02 — every RAB of the lead ("Riwayat RAB"), newest first. */
    quotations: (Pick<Quotation, 'id' | 'type' | 'custom_name' | 'parent_quotation_id' | 'status' | 'total_amount' | 'version' | 'created_at'> & {
        letter_number?: string | null;
        /** Sprint 15 — the RAB's own client link (CEO/Marketing only). */
        client_url?: string | null;
    })[];
    project: (Pick<Project, 'id' | 'name' | 'status' | 'contract_value'> & { pm?: Pick<User, 'id' | 'name'> | null }) | null;
};

interface LeadShowProps {
    lead: LeadDetail;
    /** Null when the viewer's role has no "CRM – Pipeline Log" read access (PRD §7.1). */
    pipelineLogs: PipelineLogEntry[] | null;
    canManage: boolean;
    /** LeadFollowUp::SUGGEST_LOST_FROM (Sprint 12 #2). */
    suggestLostFrom: number;
    marketers: Pick<User, 'id' | 'name'>[];
    leadSources: Pick<LeadSourceOption, 'id' | 'name'>[];
    leadCategories: Pick<LeadCategoryOption, 'id' | 'name'>[];
    cities: CityOption[];
    /** Sprint 14 Sub 02 — custom RAB names used before ("Buat RAB → Lainnya" suggestions). */
    customRabNames: string[];
    /** Sprint 17 Sub 04 — the lead's running RAB Proyek, if any. */
    projectRab: RunningProjectRab | null;
}

/**
 * Lead detail (PRD §4.1) — reached by clicking a client name on the CRM
 * index. Client data, what the lead turned into downstream (Design →
 * Quotation → Project), and the pipeline history log.
 */
export default function LeadShow({
    lead,
    pipelineLogs,
    canManage,
    suggestLostFrom,
    marketers,
    leadSources,
    leadCategories,
    cities,
    customRabNames,
    projectRab,
}: LeadShowProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [statusOpen, setStatusOpen] = useState(false);
    const [clientRejectOpen, setClientRejectOpen] = useState(false);
    const [requestOpen, setRequestOpen] = useState(false);
    // Sprint 12 #7 — the same dialog with only the "Minta RAB …" options, once the lead is past FOLLOW_UP.
    const [rabOnly, setRabOnly] = useState(false);

    const isClosed = lead.status === 'LOST' || lead.status === 'CLOSING';
    const quotationExpired = isQuotationExpired(lead.quotation);
    // Sprint 12: the earliest follow-up (FU-n) not done yet.
    const nextFollowUp = lead.follow_ups.filter((followUp) => !followUp.done_at).sort((a, b) => a.scheduled_date.localeCompare(b.scheduled_date))[0];
    const isOverdue = !!nextFollowUp && !isClosed && new Date(nextFollowUp.scheduled_date) < startOfToday();
    const sourceName = lead.lead_source?.name ?? lead.source;

    return (
        <AppLayout
            breadcrumbs={[{ label: lead.client_name }]}
        >
            <Head title={`Lead — ${lead.client_name}`} />

            <PageHeader
                title={lead.client_name}
                icon={UserRound}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusChip status={lead.status} />
                        <StatusChip status={lead.priority} />
                        <span>
                            Dibuat {formatDate(lead.created_at)}
                            {lead.creator && ` oleh ${lead.creator.name}`}
                        </span>
                    </span>
                }
                actions={
                    canManage && (
                        <>
                            <Button variant="outline" onClick={() => setFormOpen(true)}>
                                <PenLine className="size-4" />
                                Ubah Lead
                            </Button>
                            <Button variant="outline" disabled={isClosed} onClick={() => setStatusOpen(true)}>
                                Ubah Status
                            </Button>
                            {lead.status === 'FOLLOW_UP' && (
                                <Button
                                    onClick={() => {
                                        setRabOnly(false);
                                        setRequestOpen(true);
                                    }}
                                >
                                    <Send className="size-4" />
                                    Ajukan Desain/Survey
                                </Button>
                            )}
                            {lead.status === 'DEAL_DESAIN' && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setRabOnly(true);
                                        setRequestOpen(true);
                                    }}
                                >
                                    <FileText className="size-4" />
                                    Buat RAB
                                </Button>
                            )}
                            {lead.quotation?.status === 'SENT_TO_CLIENT' && (
                                <Button variant="outline" onClick={() => setClientRejectOpen(true)}>
                                    Catat Penolakan Klien
                                </Button>
                            )}
                        </>
                    )
                }
            />

            {lead.status === 'LOST' && (
                <Notice tone="error" className="mb-6">
                    Lead ini berstatus LOST{lead.lost_reason ? ` — alasan: ${lead.lost_reason}` : ''}. Lead LOST
                    tidak bisa diubah kembali; buat lead baru jika klien kembali.
                </Notice>
            )}
            {isOverdue && (
                <Notice tone="warning" className="mb-6">
                    Jadwal FU-{nextFollowUp?.sequence} ({formatDate(nextFollowUp?.scheduled_date)}) sudah lewat.
                </Notice>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <SectionCard title="Informasi Klien" icon={UserRound} className="lg:col-span-2">
                    <DetailList>
                        <DetailItem label="Nama Klien" valueClassName="font-medium">
                            {lead.client_name}
                        </DetailItem>
                        <DetailItem label="No. HP">{formatPhone(lead.phone) || '—'}</DetailItem>
                        <DetailItem label="Email">{lead.email || '—'}</DetailItem>
                        <DetailItem label="Kota">{lead.city?.name ?? '—'}</DetailItem>
                        <DetailItem label="Pertama Dihubungi">{formatDate(lead.first_contacted_at)}</DetailItem>
                        <DetailItem label="Masuk Sistem">{formatDate(lead.created_at)}</DetailItem>
                        <DetailItem label="Alamat" className="sm:col-span-2" valueClassName="whitespace-pre-line">
                            {lead.address || '—'}
                            {lead.maps_url && (
                                <a
                                    href={lead.maps_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="ml-2 inline-flex items-center gap-1 text-sm underline decoration-daiku-yellow underline-offset-2"
                                >
                                    <MapPin className="size-3.5" aria-hidden />
                                    Buka di Google Maps
                                </a>
                            )}
                        </DetailItem>
                        <DetailItem label="Gender">{lead.gender || '—'}</DetailItem>
                        <DetailItem label="Sumber Lead">{sourceName}</DetailItem>
                        <DetailItem label="Kategori Customer">
                            {lead.lead_category?.name ?? lead.category ?? '—'}
                        </DetailItem>
                        <DetailItem label="Layanan">{lead.service || '—'}</DetailItem>
                        <DetailItem label="PIC Marketing">{lead.assignee?.name ?? '—'}</DetailItem>
                        <DetailItem
                            label="Follow-up Berikutnya"
                            valueClassName={isOverdue ? 'font-medium text-error-ink' : undefined}
                        >
                            {nextFollowUp ? `FU-${nextFollowUp.sequence} · ${formatDate(nextFollowUp.scheduled_date)}` : '—'}
                        </DetailItem>
                        <DetailItem label="Terakhir Diperbarui">{formatDateTime(lead.updated_at)}</DetailItem>
                        <DetailItem label="Detail Order" className="sm:col-span-2" valueClassName="whitespace-pre-line">
                            {lead.order_detail || '—'}
                        </DetailItem>
                        <DetailItem label="Catatan" className="sm:col-span-2" valueClassName="whitespace-pre-line">
                            {lead.notes || '—'}
                        </DetailItem>
                    </DetailList>
                </SectionCard>

                <SectionCard
                    title="Tahap Lanjutan"
                    icon={Workflow}
                    description="Desain, penawaran, dan proyek dari lead ini."
                    flush
                >
                    <ul className="divide-y divide-border">
                        <StageRow
                            icon={Palette}
                            label="Desain"
                            status={lead.design?.status}
                            href={lead.design ? route('design.show', { design: lead.design.id }) : undefined}
                        >
                            {lead.design ? (
                                <>
                                    PIC {lead.design.pic?.name ?? '—'} · Deadline {formatDate(lead.design.deadline)}
                                    {lead.design.client_acc && ' · Sudah di-ACC klien'}
                                </>
                            ) : (
                                'Dibuka otomatis setelah klien menyetujui RAB Jasa Desain (lewat "Buat RAB").'
                            )}
                        </StageRow>
                        <StageRow
                            icon={FileText}
                            label="Quotation"
                            status={lead.quotation?.status}
                            href={lead.quotation ? route('quotations.show', { quotation: lead.quotation.id }) : undefined}
                        >
                            {lead.quotation ? (
                                <>
                                    Versi {lead.quotation.version} · {formatRupiah(lead.quotation.total_amount)}
                                    {lead.quotation.valid_until && (
                                        <>
                                            {' · '}
                                            <span className={quotationExpired ? 'font-medium text-error-ink' : undefined}>
                                                {quotationExpired ? 'kedaluwarsa' : 'berlaku'} s.d. {formatDate(lead.quotation.valid_until)}
                                            </span>
                                        </>
                                    )}
                                </>
                            ) : (
                                'Diminta Marketing atau dibuat otomatis setelah desain di-ACC klien.'
                            )}
                        </StageRow>
                        <StageRow
                            icon={FolderKanban}
                            label="Proyek"
                            status={lead.project?.status}
                            href={lead.project ? route('projects.show', { project: lead.project.id }) : undefined}
                        >
                            {lead.project ? (
                                <>
                                    {lead.project.name} · PM {lead.project.pm?.name ?? '—'} · Nilai kontrak{' '}
                                    {formatRupiah(lead.project.contract_value)}
                                </>
                            ) : lead.quotation?.status === 'CLIENT_APPROVED' ? (
                                <>
                                    RAB Proyek disetujui klien {formatDateTime(lead.quotation.client_approved_at)} — menunggu CEO membuka
                                    proyeknya (Proyek → Menunggu Dibuka).
                                </>
                            ) : (
                                'Dibuka setelah klien menyetujui RAB Proyek lewat link penawaran.'
                            )}
                        </StageRow>
                    </ul>
                </SectionCard>
            </div>

            {/* Sprint 14 Sub 02 — every RAB of this client, one section per kind. */}
            <div className="mt-6">
                <AutoProjectRabNotice rab={projectRab} className="mb-4" />
                <RabHistoryCard quotations={lead.quotations} />
            </div>

            <LeadTimeline lead={lead} canManage={canManage} suggestLostFrom={suggestLostFrom} />

            {pipelineLogs && (
                <SectionCard
                    title="Riwayat Pipeline"
                    icon={History}
                    description="Setiap perubahan status, siapa yang mengubah, dan kapan."
                    className="mt-6"
                    flush
                >
                    {pipelineLogs.length === 0 ? (
                        <EmptyState title="Belum ada riwayat status." />
                    ) : (
                        <ol className="divide-y divide-border">
                            {pipelineLogs.map((log) => (
                                <li
                                    key={log.id}
                                    className="flex flex-col gap-1.5 px-4 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4 sm:px-5"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {/* A note-only entry (e.g. Sprint 17 "Desain disetujui klien") keeps the status: one chip. */}
                                            {log.from_status && log.from_status !== log.to_status && (
                                                <>
                                                    <StatusChip status={log.from_status} />
                                                    <ArrowRight className="size-3.5 text-muted-foreground" aria-label="menjadi" />
                                                </>
                                            )}
                                            <StatusChip status={log.to_status} />
                                        </div>
                                        {log.note && <p className="text-sm whitespace-pre-line text-foreground">{log.note}</p>}
                                    </div>
                                    <p className="shrink-0 text-xs text-muted-foreground">
                                        {log.changed_by_name ?? 'Sistem'} · {formatDateTime(log.created_at)}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    )}
                </SectionCard>
            )}

            {canManage && (
                <>
                    <LeadFormDialog
                        open={formOpen}
                        onOpenChange={setFormOpen}
                        editing={lead}
                        marketers={marketers}
                        leadSources={leadSources}
                        leadCategories={leadCategories}
                        cities={cities}
                    />
                    <LeadStatusDialog open={statusOpen} onOpenChange={setStatusOpen} lead={lead} />
                    <SubmitLeadRequestDialog
                        open={requestOpen}
                        onOpenChange={setRequestOpen}
                        lead={lead}
                        rabOnly={rabOnly}
                        customRabNames={customRabNames}
                        projectRab={projectRab}
                    />
                    {lead.quotation && (
                        <QuotationDecisionDialog
                            open={clientRejectOpen}
                            onOpenChange={setClientRejectOpen}
                            quotation={lead.quotation}
                            role="CLIENT"
                            decision="reject"
                            clientName={lead.client_name}
                        />
                    )}
                </>
            )}
        </AppLayout>
    );
}

function StageRow({
    icon: Icon,
    label,
    status,
    href,
    children,
}: {
    icon: LucideIcon;
    label: string;
    status?: string;
    href?: string;
    children: ReactNode;
}) {
    return (
        <li className="flex items-start gap-3 px-4 py-3.5 sm:px-5">
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
            <div className="min-w-0 flex-1">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-medium text-foreground">{label}</p>
                    {status && <StatusChip status={status} />}
                </div>
                <div className="mt-0.5 text-xs text-muted-foreground">{children}</div>
                {href && (
                    <Link
                        href={href}
                        className="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-foreground underline decoration-daiku-yellow underline-offset-4 hover:decoration-2"
                    >
                        Lihat detail
                        <ArrowRight className="size-3" aria-hidden />
                    </Link>
                )}
            </div>
        </li>
    );
}
