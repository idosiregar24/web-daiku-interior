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
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { useConfirm } from '@/hooks/useConfirm';
import AppLayout from '@/Layouts/AppLayout';
import type { CareerLevelOption, WorkTeamTemplate } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { Building2, Loader2, Pencil, Plus, Scale, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors App\Http\Requests\Projects\SaveWorkTeamRequest.
const schema = z.object({
    name: z.string().trim().min(1, 'Nama tim wajib diisi.').max(120, 'Nama tim maksimal 120 karakter.'),
    leader_name: z.string().trim().max(120, 'Nama ketua maksimal 120 karakter.'),
    members: membersSchema,
});

interface WorkTeamIndexProps {
    levels: CareerLevelOption[];
    templates: WorkTeamTemplate[];
    canEditLevels: boolean;
}

/**
 * Sprint 32 — ⚙ Pengaturan › Tim Tukang (File D, `Upah Tim Interior
 * DAIKU.xlsx`): the team templates a Pengawas picks per Kepala Tim on a
 * project to split the Upah Tukang per person, and the default bobot per
 * tingkatan karir (CEO only).
 */
export default function WorkTeamIndex({ levels, templates, canEditLevels }: WorkTeamIndexProps) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<WorkTeamTemplate | null>(null);
    const { ask, dialog } = useConfirm();

    const form = useForm<TeamFormValues>({ resolver: zodResolver(schema), defaultValues: { name: '', leader_name: '', members: [] } });

    function openForm(team: WorkTeamTemplate | null) {
        setEditing(team);
        const leader = levels.find((level) => !level.is_company);
        const company = levels.find((level) => level.is_company);
        form.reset(
            team
                ? { name: team.name, leader_name: team.leader_name ?? '', members: toMemberValues(team.members) }
                : {
                      name: '',
                      leader_name: '',
                      // A new team starts like File D: the Ketua Tim and the company's share.
                      members: [
                          ...(leader ? [{ name: '', level: leader.name, weight: String(leader.weight), company: false }] : []),
                          ...(company ? [{ name: '', level: company.name, weight: String(company.weight), company: true }] : []),
                      ],
                  },
        );
        setOpen(true);
    }

    function onSubmit(values: TeamFormValues) {
        const payload = { name: values.name.trim(), leader_name: values.leader_name.trim() || null, members: toMembersPayload(values.members) };
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof TeamFormValues, { message })),
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            router.put(route('work-teams.update', { workTeam: editing.id }), payload, options);
        } else {
            router.post(route('work-teams.store'), payload, options);
        }
    }

    async function destroy(team: WorkTeamTemplate) {
        if (!(await ask({ title: 'Hapus tim?', description: `Template "${team.name}" dihapus. Pembagian upah yang sudah dipakai di proyek tetap tersimpan.`, tone: 'danger' }))) return;
        router.delete(route('work-teams.destroy', { workTeam: team.id }), { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Tim Tukang" />

            <PageHeader
                title="Tim Tukang"
                icon={Users}
                description="Susunan anggota tiap tim tukang beserta bobotnya. Pengawas memilih tim ini di proyek untuk membagi upah per orang."
                actions={
                    <Button size="sm" onClick={() => openForm(null)}>
                        <Plus className="size-4" />
                        Tambah Tim
                    </Button>
                }
            />

            <ModuleTabs />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="space-y-4">
                    {templates.length === 0 ? (
                        <SectionCard title="Template Tim" icon={Users}>
                            <EmptyState
                                title="Belum ada tim."
                                description="Buat satu template per tim (mis. Tim Putra): isi nama anggota dan tingkatannya — persentase dihitung otomatis."
                                action={
                                    <Button size="sm" onClick={() => openForm(null)}>
                                        <Plus className="size-4" />
                                        Tambah Tim
                                    </Button>
                                }
                            />
                        </SectionCard>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            {templates.map((team) => (
                                <SectionCard
                                    key={team.id}
                                    title={team.name}
                                    icon={Users}
                                    description={`${team.leader_name ? `Ketua: ${team.leader_name} · ` : ''}${team.members.filter((m) => !m.company).length} orang`}
                                    action={
                                        <div className="flex gap-1">
                                            <Button variant="ghost" size="icon-sm" aria-label={`Ubah ${team.name}`} onClick={() => openForm(team)}>
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button variant="ghost" size="icon-sm" aria-label={`Hapus ${team.name}`} onClick={() => destroy(team)}>
                                                <Trash2 className="size-4 text-error-ink" />
                                            </Button>
                                        </div>
                                    }
                                >
                                    <MemberList members={team.members} />
                                </SectionCard>
                            ))}
                        </div>
                    )}
                </div>

                <div className="space-y-4">
                    <CareerLevelsCard levels={levels} canEdit={canEditLevels} />
                    <FormulaHint
                        withAmount
                        example={(() => {
                            const members = templates[0]?.members ?? [];
                            const person = members.find((member) => !member.company);

                            return person
                                ? { who: person.name ?? person.level ?? 'anggota', weight: person.weight, total: members.reduce((sum, m) => sum + m.weight, 0) }
                                : null;
                        })()}
                    />
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <ResponsiveDialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Ubah ${editing.name}` : 'Tambah Tim'}</DialogTitle>
                        <DialogDescription>Tambah orang lewat tombol tingkatan — bobotnya terisi sendiri dan bisa diubah. Persen = bobot ÷ total bobot tim.</DialogDescription>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    control={form.control}
                                    name="name"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>Nama Tim</FormLabel>
                                            <FormControl>
                                                <Input {...field} autoFocus placeholder="mis. Tim Putra" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="leader_name"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Kepala Tim</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder="mis. Putra" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                            <div className="space-y-2">
                                <FormLabel required>Anggota</FormLabel>
                                <TeamMembersEditor form={form} levels={levels} />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Simpan
                                </Button>
                            </DialogFooter>
                        </form>
                    </Form>
                </ResponsiveDialogContent>
            </Dialog>
            {dialog}
        </AppLayout>
    );
}

function MemberList({ members }: { members: WorkTeamTemplate['members'] }) {
    return (
        <ul className="divide-y divide-daiku-border text-sm">
            {members.map((member, index) => (
                <li key={index} className="flex items-center justify-between gap-3 py-1.5">
                    <span className="min-w-0">
                        {member.company ? (
                            <span className="inline-flex items-center gap-1.5 text-daiku-muted">
                                <Building2 className="size-3.5" />
                                {member.level ?? 'Perusahaan'}
                            </span>
                        ) : (
                            <>
                                <span className="font-medium text-foreground">{member.name ?? member.level}</span>
                                {member.name && member.level && <span className="ml-1.5 text-xs text-daiku-muted">{member.level}</span>}
                            </>
                        )}
                    </span>
                    <span className="shrink-0 tabular-nums text-daiku-muted">
                        <span className="text-xs">bobot {member.weight.toLocaleString('id-ID')} · </span>
                        <span className="font-semibold text-foreground">{formatPercent(member.percent)}</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** File D "Master Persentasi" — the bobot a tingkatan starts with; the CEO changes it. */
function CareerLevelsCard({ levels, canEdit }: { levels: CareerLevelOption[]; canEdit: boolean }) {
    const [values, setValues] = useState<Record<number, string>>(() => Object.fromEntries(levels.map((level) => [level.id, String(level.weight)])));
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dirty = levels.some((level) => Number(values[level.id]) !== level.weight);

    function save() {
        router.put(
            route('work-teams.levels.update'),
            { levels: levels.map((level) => ({ id: level.id, name: level.name, weight: Number(values[level.id]) })) },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: setErrors,
                onSuccess: () => setErrors({}),
            },
        );
    }

    return (
        <SectionCard
            title="Bobot Tingkatan"
            icon={Scale}
            description="Bobot awal tiap tingkatan saat menambah orang ke tim. Mengubahnya tidak mengubah tim yang sudah ada."
            footer={
                canEdit ? (
                    <Button size="sm" className="w-full" disabled={!dirty || saving} onClick={save}>
                        {saving && <Loader2 className="size-4 animate-spin" />}
                        Simpan Bobot
                    </Button>
                ) : undefined
            }
        >
            <ul className="space-y-2">
                {levels.map((level, index) => (
                    <li key={level.id} className="flex items-center justify-between gap-3 text-sm">
                        <span className="inline-flex items-center gap-1.5 text-foreground">
                            {level.is_company && <Building2 className="size-3.5 text-daiku-muted" />}
                            {level.name}
                        </span>
                        {canEdit ? (
                            <Input
                                value={values[level.id] ?? ''}
                                inputMode="decimal"
                                aria-label={`Bobot ${level.name}`}
                                aria-invalid={!!errors[`levels.${index}.weight`]}
                                className="h-8 w-20 text-center tabular-nums"
                                onChange={(event) => setValues((prev) => ({ ...prev, [level.id]: event.target.value.replace(',', '.').replace(/[^\d.]/g, '') }))}
                            />
                        ) : (
                            <span className="font-semibold tabular-nums">{level.weight.toLocaleString('id-ID')}</span>
                        )}
                    </li>
                ))}
            </ul>
            {Object.keys(errors).length > 0 && (
                <Notice tone="error" className="mt-3">
                    Bobot harus angka lebih dari 0.
                </Notice>
            )}
        </SectionCard>
    );
}
