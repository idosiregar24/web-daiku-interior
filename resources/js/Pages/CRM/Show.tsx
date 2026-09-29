import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { ConfirmDealDialog } from '@/Components/modules/crm/ConfirmDealDialog';
import { LeadFormDialog } from '@/Components/modules/crm/LeadFormDialog';
import { LeadStatusDialog } from '@/Components/modules/crm/LeadStatusDialog';
import { OpenDesignDialog } from '@/Components/modules/crm/OpenDesignDialog';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import type {
    Design,
    Lead,
    LeadCategoryOption,
    LeadSourceOption,
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
    Palette,
    PenLine,
    UserRound,
    Workflow,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

type LeadDetail = Omit<Lead, 'design'> & {
    design: (Pick<Design, 'id' | 'status' | 'deadline' | 'client_acc'> & { pic?: Pick<User, 'id' | 'name'> | null }) | null;
    quotation: Pick<Quotation, 'id' | 'status' | 'total_amount' | 'version'> | null;
    project: (Pick<Project, 'id' | 'name' | 'status' | 'contract_value'> & { pm?: Pick<User, 'id' | 'name'> | null }) | null;
};

interface LeadShowProps {
    lead: LeadDetail;
    /** Null when the viewer's role has no "CRM – Pipeline Log" read access (PRD §7.1). */
    pipelineLogs: PipelineLogEntry[] | null;
    canManage: boolean;
    canOpenDesign: boolean;
    marketers: Pick<User, 'id' | 'name'>[];
    projectManagers: Pick<User, 'id' | 'name'>[];
    designers: Pick<User, 'id' | 'name'>[];
    leadSources: Pick<LeadSourceOption, 'id' | 'name'>[];
    leadCategories: Pick<LeadCategoryOption, 'id' | 'name'>[];
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
    canOpenDesign,
    marketers,
    projectManagers,
    designers,
    leadSources,
    leadCategories,
}: LeadShowProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [statusOpen, setStatusOpen] = useState(false);
    const [dealOpen, setDealOpen] = useState(false);
    const [designOpen, setDesignOpen] = useState(false);

    const isClosed = lead.status === 'LOST' || lead.status === 'CLOSING';
    const isOverdue = !!lead.follow_up_date && !isClosed && new Date(lead.follow_up_date) < startOfToday();
    const sourceName = lead.lead_source?.name ?? lead.source;

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'CRM' },
                { label: 'Data Lead', routeName: 'crm.leads.index' },
                { label: lead.client_name },
            ]}
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
                                Edit Lead
                            </Button>
                            <Button variant="outline" disabled={isClosed} onClick={() => setStatusOpen(true)}>
                                Ubah Status
                            </Button>
                            {lead.status === 'DEAL_DESAIN' && (
                                <Button onClick={() => setDealOpen(true)}>Konfirmasi Deal</Button>
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
                    Jadwal follow-up {formatDate(lead.follow_up_date)} sudah lewat.
                </Notice>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <SectionCard title="Informasi Klien" icon={UserRound} className="lg:col-span-2">
                    <DetailList>
                        <DetailItem label="Nama Klien" valueClassName="font-medium">
                            {lead.client_name}
                        </DetailItem>
                        <DetailItem label="Kontak">{lead.contact}</DetailItem>
                        <DetailItem label="Kota">{lead.city || '—'}</DetailItem>
                        <DetailItem label="Gender">{lead.gender || '—'}</DetailItem>
                        <DetailItem label="Sumber Lead">{sourceName}</DetailItem>
                        <DetailItem label="Kategori Customer">
                            {lead.lead_category?.name ?? lead.category ?? '—'}
                        </DetailItem>
                        <DetailItem label="Layanan">{lead.service || '—'}</DetailItem>
                        <DetailItem label="PIC Marketing">{lead.assignee?.name ?? '—'}</DetailItem>
                        <DetailItem
                            label="Jadwal Follow-up"
                            valueClassName={isOverdue ? 'font-medium text-error-ink' : undefined}
                        >
                            {formatDate(lead.follow_up_date)}
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
                            ) : lead.status === 'DEAL_DESAIN' ? (
                                canOpenDesign ? (
                                    <Button size="sm" variant="outline" className="mt-1" onClick={() => setDesignOpen(true)}>
                                        Buka Desain
                                    </Button>
                                ) : (
                                    'Menunggu Designer membuka proyek desain.'
                                )
                            ) : (
                                'Dibuka setelah lead berstatus Deal Desain.'
                            )}
                        </StageRow>
                        <StageRow
                            icon={FileText}
                            label="Quotation"
                            status={lead.quotation?.status}
                            href={lead.quotation ? route('quotations.show', { quotation: lead.quotation.id }) : undefined}
                        >
                            {lead.quotation
                                ? `Versi ${lead.quotation.version} · ${formatRupiah(lead.quotation.total_amount)}`
                                : 'Dibuat otomatis setelah desain di-ACC klien.'}
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
                            ) : (
                                'Dibuat saat deal dikonfirmasi.'
                            )}
                        </StageRow>
                    </ul>
                </SectionCard>
            </div>

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
                                            {log.from_status && (
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
                    />
                    <LeadStatusDialog open={statusOpen} onOpenChange={setStatusOpen} lead={lead} />
                    <ConfirmDealDialog
                        open={dealOpen}
                        onOpenChange={setDealOpen}
                        lead={lead}
                        projectManagers={projectManagers}
                    />
                </>
            )}
            {canOpenDesign && (
                <OpenDesignDialog open={designOpen} onOpenChange={setDesignOpen} lead={lead} designers={designers} />
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
