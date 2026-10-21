import { Button } from '@/Components/ui/button';
import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { RequiredMark } from '@/Components/shared/RequiredMark';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import type { Project, ProjectTeamOptions } from '@/types';
import { Plus, X } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { z } from 'zod';

/** Pengawas Select's "nobody" value (Radix Select can't hold ''). */
export const NO_SUPERVISOR = 'none';

/**
 * Sprint 23 K14/K15 — Kepala Tim (a user **or** a typed name — an outsider
 * or vendor) and Pengawas (QA/QC), shared by "Buka Proyek" and "Ubah
 * Proyek". Mirrors ValidatesProjectPeople: exactly one Kepala Tim source,
 * Pengawas optional. Sprint 29 Sub 06 — one or more Pengawas, all equal:
 * the first select + "Tambah Pengawas" (`supervisor_ids`, max 5).
 */
export const MAX_SUPERVISORS = 5;

export const teamFieldsShape = {
    team_lead_mode: z.enum(['user', 'name']),
    team_lead_id: z.string(),
    team_lead_name: z.string().max(255, 'Nama Kepala Tim maksimal 255 karakter'),
    supervisor_ids: z.array(z.string()).max(MAX_SUPERVISORS, `Maksimal ${MAX_SUPERVISORS} Pengawas per proyek`),
};

export interface TeamFieldValues {
    team_lead_mode: 'user' | 'name';
    team_lead_id: string;
    team_lead_name: string;
    supervisor_ids: string[];
}

/** `.superRefine(refineTeamLead)` — the "one of the two is required" rule, on the field that's showing. */
export function refineTeamLead(values: TeamFieldValues, ctx: z.RefinementCtx) {
    if (values.team_lead_mode === 'user' && !values.team_lead_id) {
        ctx.addIssue({ code: 'custom', path: ['team_lead_id'], message: 'Kepala Tim wajib dipilih' });
    }

    if (values.team_lead_mode === 'name' && !values.team_lead_name.trim()) {
        ctx.addIssue({ code: 'custom', path: ['team_lead_name'], message: 'Nama Kepala Tim wajib diisi' });
    }
}

/** Form defaults — from the project when editing. */
export function teamDefaults(project?: Pick<Project, 'team_lead_id' | 'team_lead_name' | 'supervisors'>): TeamFieldValues {
    return {
        team_lead_mode: project?.team_lead_name && !project.team_lead_id ? 'name' : 'user',
        team_lead_id: project?.team_lead_id ? String(project.team_lead_id) : '',
        team_lead_name: project?.team_lead_name ?? '',
        supervisor_ids: project?.supervisors?.length ? project.supervisors.map((person) => String(person.id)) : [NO_SUPERVISOR],
    };
}

/** What the server gets — exactly one Kepala Tim column filled. */
export function teamPayload(values: TeamFieldValues) {
    return {
        team_lead_id: values.team_lead_mode === 'user' && values.team_lead_id ? Number(values.team_lead_id) : null,
        team_lead_name: values.team_lead_mode === 'name' ? values.team_lead_name.trim() || null : null,
        supervisor_ids: [...new Set(values.supervisor_ids.filter((id) => id && id !== NO_SUPERVISOR))].map(Number),
    };
}

/** Server field → the form field showing its error (`supervisor_ids.1` → the Pengawas list). */
export function teamErrorField(key: string): keyof TeamFieldValues | undefined {
    const base = key.split('.')[0];

    return base === 'team_lead_id' || base === 'team_lead_name' || base === 'supervisor_ids' ? base : undefined;
}

interface ProjectTeamFieldsProps {
    options: ProjectTeamOptions;
    /** Edit Proyek — keeps a since-deactivated Kepala Tim / Pengawas selectable. */
    project?: Pick<Project, 'team_lead' | 'supervisors'>;
}

/** The Kepala Tim + Pengawas inputs, inside a `<Form>` whose values include TeamFieldValues. */
export function ProjectTeamFields({ options, project }: ProjectTeamFieldsProps) {
    const form = useFormContext<TeamFieldValues>();
    const mode = form.watch('team_lead_mode');
    const inactiveLead = project?.team_lead && !options.teamLeads.some((person) => person.id === project.team_lead?.id) ? project.team_lead : null;
    // Pengawas already on the project but since deactivated stay selectable on Edit Proyek.
    const inactiveSupervisors = (project?.supervisors ?? []).filter((current) => !options.supervisors.some((person) => person.id === current.id));

    function switchMode(next: TeamFieldValues['team_lead_mode']) {
        form.setValue('team_lead_mode', next);
        form.clearErrors(['team_lead_id', 'team_lead_name']);
    }

    return (
        <>
            <div className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    {/* A plain Label: FormLabel needs one FormField, and this heads two. */}
                    <Label>
                        Kepala Tim
                        <RequiredMark />
                    </Label>
                    <div role="group" aria-label="Sumber Kepala Tim" className="inline-flex rounded-lg bg-daiku-gray p-0.5">
                        {(
                            [
                                ['user', 'Pilih karyawan'],
                                ['name', 'Ketik nama'],
                            ] as const
                        ).map(([value, label]) => (
                            <Button
                                key={value}
                                type="button"
                                size="sm"
                                variant="ghost"
                                aria-pressed={mode === value}
                                onClick={() => switchMode(value)}
                                className={cn('h-7 px-2.5 text-xs', mode === value ? 'bg-background shadow-xs' : 'text-daiku-muted')}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                </div>
                {mode === 'user' ? (
                    <FormField
                        control={form.control}
                        name="team_lead_id"
                        render={({ field }) => (
                            <FormItem>
                                <Select value={field.value} onValueChange={field.onChange}>
                                    <FormControl>
                                        <SelectTrigger className="w-full" aria-label="Kepala Tim">
                                            <SelectValue placeholder="Pilih karyawan" />
                                        </SelectTrigger>
                                    </FormControl>
                                    <SelectContent>
                                        {inactiveLead && <SelectItem value={String(inactiveLead.id)}>{inactiveLead.name} (nonaktif)</SelectItem>}
                                        {options.teamLeads.map((person) => (
                                            <SelectItem key={person.id} value={String(person.id)}>
                                                {person.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FormDescription>Semua karyawan yang punya akun. Orang luar/vendor? Pilih "Ketik nama".</FormDescription>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                ) : (
                    <FormField
                        control={form.control}
                        name="team_lead_name"
                        render={({ field }) => (
                            <FormItem>
                                <FormControl>
                                    <Input {...field} aria-label="Nama Kepala Tim" placeholder="mis. Pak Darto (tim borongan)" maxLength={255} />
                                </FormControl>
                                <FormDescription>Untuk Kepala Tim dari luar perusahaan atau vendor.</FormDescription>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                )}
            </div>
            <FormField
                control={form.control}
                name="supervisor_ids"
                render={({ field }) => {
                    const values = field.value.length > 0 ? field.value : [NO_SUPERVISOR];
                    const set = (index: number, value: string) => field.onChange(values.map((current, i) => (i === index ? value : current)));
                    const remove = (index: number) => field.onChange(values.filter((_, i) => i !== index));
                    const canAdd = values.length < MAX_SUPERVISORS && values[0] !== NO_SUPERVISOR && !values.includes('');

                    return (
                        <FormItem>
                            <FormLabel>Pengawas (QA/QC)</FormLabel>
                            <div className="space-y-2">
                                {values.map((value, index) => (
                                    <div key={index} className="flex gap-2">
                                        <Select value={value || undefined} onValueChange={(next) => set(index, next)}>
                                            <FormControl>
                                                <SelectTrigger className="w-full" aria-label={index === 0 ? 'Pengawas' : `Pengawas ${index + 1}`}>
                                                    <SelectValue placeholder="Pilih Pengawas" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {index === 0 && <SelectItem value={NO_SUPERVISOR}>Belum ditentukan</SelectItem>}
                                                {[...inactiveSupervisors.map((person) => ({ ...person, inactive: true })), ...options.supervisors.map((person) => ({ ...person, inactive: false }))]
                                                    // One person once: the others' picks are hidden from this select.
                                                    .filter((person) => String(person.id) === value || !values.includes(String(person.id)))
                                                    .map((person) => (
                                                        <SelectItem key={person.id} value={String(person.id)}>
                                                            {person.inactive ? `${person.name} (nonaktif)` : person.name}
                                                        </SelectItem>
                                                    ))}
                                            </SelectContent>
                                        </Select>
                                        {index > 0 && (
                                            <Button type="button" variant="ghost" size="icon" aria-label={`Hapus Pengawas ${index + 1}`} onClick={() => remove(index)}>
                                                <X className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                            {canAdd && (
                                <Button type="button" variant="outline" size="sm" className="w-fit max-md:h-11" onClick={() => field.onChange([...values, ''])}>
                                    <Plus className="size-4" />
                                    Tambah Pengawas
                                </Button>
                            )}
                            <FormDescription>Staf QA/QC yang mengawasi pekerjaan proyek ini — semua Pengawas sama haknya.</FormDescription>
                            <FormMessage />
                        </FormItem>
                    );
                }}
            />
        </>
    );
}
