import {
    FormulaHint,
    formatPercent,
    membersSchema,
    TeamMembersEditor,
    toMembersPayload,
    toMemberValues,
    type TeamFormValues,
} from '@/Components/modules/projects/TeamMembersEditor';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormLabel } from '@/Components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { copyText } from '@/lib/clipboard';
import { formatDate, formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import type { ProjectExecution, WageSplitOverview } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, router } from '@inertiajs/react';
import { Building2, Copy, Loader2, ReceiptText, Settings2, Users } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';

const NO_TEMPLATE = '__none';

const schema = z.object({ name: z.string(), leader_name: z.string(), members: membersSchema });

type Team = WageSplitOverview['teams'][number];
type Slip = WageSplitOverview['slips'][number];

/**
 * Sprint 32 — Item RAB › Pembagian Upah (File D, `Upah Tim Interior
 * DAIKU.xlsx`): a help for a Kepala Tim who can't split the pay himself. Per
 * Kepala Tim the Pengawas picks a Tim Tukang template (or types the people);
 * every DP / pencairan then shows each person's part, ready to copy to
 * WhatsApp. Numbers come from WageSplitService — the editor only previews.
 */
export function WageSplitPanel({ projectId, projectName, execution }: { projectId: number; projectName: string; execution: ProjectExecution }) {
    const data = execution.wageSplit;
    const canEdit = execution.can.splitWages;
    const [editing, setEditing] = useState<Team | null>(null);

    if (!data) return null;

    // The newest payment's part of a team — the editor's preview amount.
    const latestAmount = (label: string) => data.slips.flatMap((slip) => slip.teams).find((team) => team.label === label)?.amount ?? null;

    return (
        <div className="space-y-6">
            <Notice tone="info">
                Alat bantu untuk Kepala Tim: upah tetap dibayar Finance ke Pengawas, lalu diserahkan ke Kepala Tim. Di sini terlihat bagian tiap orang dari
                setiap DP / pencairan.
            </Notice>

            <SectionCard
                title="Anggota per Kepala Tim"
                icon={Users}
                description={canEdit ? 'Pilih template tim untuk tiap Kepala Tim, lalu sesuaikan bila susunan di proyek ini berbeda.' : 'Susunan anggota yang diatur Pengawas.'}
                action={
                    canEdit ? (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('work-teams.index')}>
                                <Settings2 className="size-4" />
                                Kelola Template
                            </Link>
                        </Button>
                    ) : undefined
                }
            >
                {data.teams.length === 0 ? (
                    <EmptyState title="Belum ada item berupah." description="Isi Satuan Upah & Kepala Tim di Item RAB › Upah Tukang dulu." />
                ) : (
                    <div className="mb-4 grid gap-4 md:grid-cols-2">
                        {data.teams.map((team) => (
                            <div key={team.label} className="rounded-xl border border-daiku-border p-4">
                                <div className="mb-2 flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="font-semibold text-foreground">{team.label}</p>
                                        <p className="text-xs text-daiku-muted">
                                            Kepala Tim · {team.members ? (team.template ? `dari template ${team.template}` : 'diisi manual') : 'belum diatur'}
                                        </p>
                                    </div>
                                    {canEdit && (
                                        <Button size="sm" variant={team.members ? 'outline' : 'default'} onClick={() => setEditing(team)}>
                                            {team.members ? 'Ubah' : 'Atur Anggota'}
                                        </Button>
                                    )}
                                </div>
                                {team.members ? (
                                    <ul className="divide-y divide-daiku-border text-sm">
                                        {team.members.map((member, index) => (
                                            <li key={index} className="flex items-center justify-between gap-3 py-1.5">
                                                <MemberName member={member} />
                                                <span className="shrink-0 font-semibold tabular-nums">{formatPercent(member.percent)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-daiku-muted">Bagian per orang belum bisa dihitung.</p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
                {data.teams.some((team) => team.members) && <FormulaHint example={teamExample(data)} />}
            </SectionCard>

            <SectionCard title="Slip Pembagian per Pencairan" icon={ReceiptText} description="Setiap DP / pencairan Upah Tukang, dibagi per orang. Salin lalu kirim ke grup WhatsApp tim.">
                {data.slips.length === 0 ? (
                    <EmptyState title="Belum ada DP atau pencairan." description="Slip muncul begitu Pengawas mengajukan DP / pengecekan." />
                ) : (
                    <div className="space-y-5">
                        {data.slips.map((slip) => (
                            <SlipCard key={slip.id} slip={slip} projectName={projectName} />
                        ))}
                        <FormulaHint withAmount example={slipExample(data)} />
                    </div>
                )}
            </SectionCard>

            {editing && (
                <SplitDialog
                    projectId={projectId}
                    team={editing}
                    data={data}
                    previewAmount={latestAmount(editing.label)}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

/** The first person of the first team with members — the hint's worked example. */
function teamExample(data: WageSplitOverview) {
    const members = data.teams.find((team) => team.members)?.members ?? [];
    const person = members.find((member) => !member.company);
    if (!person) return null;

    return { who: person.name ?? person.level ?? 'anggota', weight: person.weight, total: members.reduce((sum, member) => sum + member.weight, 0) };
}

/** The first person of the newest slip's first split team. */
function slipExample(data: WageSplitOverview) {
    const team = data.slips.flatMap((slip) => slip.teams).find((share) => share.rows);
    const person = team?.rows?.find((row) => !row.company);
    if (!team?.rows || !person) return null;

    return {
        who: person.name ?? person.level ?? 'anggota',
        weight: person.weight,
        total: team.rows.reduce((sum, row) => sum + row.weight, 0),
        amount: team.amount,
        part: person.amount,
    };
}

function MemberName({ member }: { member: { name: string | null; level: string | null; company: boolean } }) {
    if (member.company) {
        return (
            <span className="inline-flex items-center gap-1.5 text-daiku-muted">
                <Building2 className="size-3.5" />
                {member.level ?? 'Perusahaan'}
            </span>
        );
    }

    return (
        <span className="min-w-0">
            <span className="font-medium text-foreground">{member.name ?? member.level}</span>
            {member.name && member.level && <span className="ml-1.5 text-xs text-daiku-muted">{member.level}</span>}
        </span>
    );
}

function slipText(projectName: string, slip: Slip, team: Slip['teams'][number]): string {
    const lines = (team.rows ?? []).map((row) => {
        const who = row.company ? (row.level ?? 'Perusahaan') : `${row.name ?? row.level}${row.name && row.level ? ` (${row.level})` : ''}`;

        return `• ${who} ${formatPercent(row.percent)} — ${formatRupiah(row.amount)}`;
    });

    return [`*Pembagian Upah — ${projectName}*`, `${slip.label} · ${team.label}`, `Total tim: ${formatRupiah(team.amount)}`, '', ...lines].join('\n');
}

function SlipCard({ slip, projectName }: { slip: Slip; projectName: string }) {
    async function copy(team: Slip['teams'][number]) {
        if (await copyText(slipText(projectName, slip, team))) {
            toast.success('Slip disalin — tempel di WhatsApp.');
        } else {
            toast.error('Gagal menyalin.');
        }
    }

    return (
        <div className="rounded-xl border border-daiku-border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-daiku-border bg-daiku-yellow-light/60 px-4 py-2.5">
                <div className="min-w-0">
                    <p className="font-semibold text-foreground">{slip.label}</p>
                    <p className="text-xs text-daiku-muted">{formatDate(slip.date)}</p>
                </div>
                <div className="flex items-center gap-2">
                    <StatusChip domain="supervisorPayment" status={slip.status} />
                    <span className="font-semibold tabular-nums">{formatRupiah(slip.amount)}</span>
                </div>
            </div>
            <div className="divide-y divide-daiku-border">
                {slip.teams.map((team) => (
                    <div key={team.label} className="px-4 py-3">
                        <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm font-medium text-foreground">
                                {team.label} · <span className="tabular-nums">{formatRupiah(team.amount)}</span>
                            </p>
                            {team.rows && (
                                <Button variant="outline" size="sm" onClick={() => copy(team)}>
                                    <Copy className="size-4" />
                                    Salin untuk WhatsApp
                                </Button>
                            )}
                        </div>
                        {team.rows ? (
                            <ul className="text-sm">
                                {team.rows.map((row, index) => (
                                    <li key={index} className="flex items-center justify-between gap-3 py-1">
                                        <MemberName member={row} />
                                        <span className="shrink-0 tabular-nums">
                                            <span className="mr-2 text-xs text-daiku-muted">{formatPercent(row.percent)}</span>
                                            <span className={row.company ? 'text-daiku-muted' : 'font-semibold text-foreground'}>{formatRupiah(row.amount)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-daiku-muted">Anggota tim ini belum diatur — bagian per orang belum bisa dihitung.</p>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

function SplitDialog({
    projectId,
    team,
    data,
    previewAmount,
    onClose,
}: {
    projectId: number;
    team: Team;
    data: WageSplitOverview;
    previewAmount: number | null;
    onClose: () => void;
}) {
    const [templateId, setTemplateId] = useState<string>(() => String(data.templates.find((template) => template.name === team.template)?.id ?? NO_TEMPLATE));
    const [saving, setSaving] = useState(false);
    const form = useForm<TeamFormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', leader_name: '', members: team.members ? toMemberValues(team.members) : [] },
    });

    function pickTemplate(value: string) {
        setTemplateId(value);
        const template = data.templates.find((option) => String(option.id) === value);
        if (template) form.setValue('members', toMemberValues(template.members), { shouldDirty: true });
    }

    function save(values: TeamFormValues) {
        router.put(
            route('projects.wage-splits.save', { project: projectId }),
            { team_label: team.label, work_team_id: templateId === NO_TEMPLATE ? null : Number(templateId), members: toMembersPayload(values.members) },
            {
                ...projectVisit(RELOAD.wageSplit, { quiet: true }),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof TeamFormValues, { message })),
                onSuccess: onClose,
            },
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <ResponsiveDialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Anggota · {team.label}</DialogTitle>
                    <DialogDescription>
                        Hanya untuk proyek ini — template di Pengaturan tidak berubah.
                        {previewAmount ? ` Angka rupiah = contoh dari pencairan terakhir (${formatRupiah(previewAmount)}).` : ''}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(save)} className="space-y-4">
                        <div className="space-y-2">
                            <FormLabel>Mulai dari template</FormLabel>
                            {data.templates.length === 0 ? (
                                <p className="text-sm text-daiku-muted">
                                    Belum ada template.{' '}
                                    <Link href={route('work-teams.index')} className="font-medium text-foreground underline decoration-daiku-yellow underline-offset-2">
                                        Buat di Pengaturan › Tim Tukang
                                    </Link>{' '}
                                    atau isi langsung di bawah.
                                </p>
                            ) : (
                                <Select value={templateId} onValueChange={pickTemplate}>
                                    <SelectTrigger className="w-full sm:w-72">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NO_TEMPLATE}>Tanpa template (isi sendiri)</SelectItem>
                                        {data.templates.map((template) => (
                                            <SelectItem key={template.id} value={String(template.id)}>
                                                {template.name}
                                                {template.leader_name ? ` · ${template.leader_name}` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>
                        <div className="space-y-2">
                            <FormLabel required>Anggota</FormLabel>
                            <TeamMembersEditor form={form} levels={data.levels} previewAmount={previewAmount} />
                        </div>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={saving}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
