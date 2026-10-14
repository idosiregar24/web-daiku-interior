import { Button } from '@/Components/ui/button';
import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { RequiredMark } from '@/Components/shared/RequiredMark';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import type { Project, ProjectTeamOptions } from '@/types';
import { useFormContext } from 'react-hook-form';
import { z } from 'zod';

/** Pengawas Select's "nobody" value (Radix Select can't hold ''). */
export const NO_SUPERVISOR = 'none';

/**
 * Sprint 23 K14/K15 — Kepala Tim (a user **or** a typed name — an outsider
 * or vendor) and Pengawas (QA/QC), shared by "Buka Proyek" and "Ubah
 * Proyek". Mirrors ValidatesProjectPeople: exactly one Kepala Tim source,
 * Pengawas optional.
 */
export const teamFieldsShape = {
    team_lead_mode: z.enum(['user', 'name']),
    team_lead_id: z.string(),
    team_lead_name: z.string().max(255, 'Nama Kepala Tim maksimal 255 karakter'),
    supervisor_id: z.string(),
};

export interface TeamFieldValues {
    team_lead_mode: 'user' | 'name';
    team_lead_id: string;
    team_lead_name: string;
    supervisor_id: string;
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
export function teamDefaults(project?: Pick<Project, 'team_lead_id' | 'team_lead_name' | 'supervisor_id'>): TeamFieldValues {
    return {
        team_lead_mode: project?.team_lead_name && !project.team_lead_id ? 'name' : 'user',
        team_lead_id: project?.team_lead_id ? String(project.team_lead_id) : '',
        team_lead_name: project?.team_lead_name ?? '',
        supervisor_id: project?.supervisor_id ? String(project.supervisor_id) : NO_SUPERVISOR,
    };
}

/** What the server gets — exactly one Kepala Tim column filled. */
export function teamPayload(values: TeamFieldValues) {
    return {
        team_lead_id: values.team_lead_mode === 'user' && values.team_lead_id ? Number(values.team_lead_id) : null,
        team_lead_name: values.team_lead_mode === 'name' ? values.team_lead_name.trim() || null : null,
        supervisor_id: values.supervisor_id === NO_SUPERVISOR ? null : Number(values.supervisor_id),
    };
}

/** Server field → the form field showing its error. */
export const TEAM_ERROR_FIELD: Record<string, keyof TeamFieldValues> = {
    team_lead_id: 'team_lead_id',
    team_lead_name: 'team_lead_name',
    supervisor_id: 'supervisor_id',
};

interface ProjectTeamFieldsProps {
    options: ProjectTeamOptions;
    /** Edit Proyek — keeps a since-deactivated Kepala Tim / Pengawas selectable. */
    project?: Pick<Project, 'team_lead' | 'supervisor'>;
}

/** The Kepala Tim + Pengawas inputs, inside a `<Form>` whose values include TeamFieldValues. */
export function ProjectTeamFields({ options, project }: ProjectTeamFieldsProps) {
    const form = useFormContext<TeamFieldValues>();
    const mode = form.watch('team_lead_mode');
    const inactiveLead = project?.team_lead && !options.teamLeads.some((person) => person.id === project.team_lead?.id) ? project.team_lead : null;
    const inactiveSupervisor =
        project?.supervisor && !options.supervisors.some((person) => person.id === project.supervisor?.id) ? project.supervisor : null;

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
                name="supervisor_id"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Pengawas (QA/QC)</FormLabel>
                        <Select value={field.value} onValueChange={field.onChange}>
                            <FormControl>
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                                <SelectItem value={NO_SUPERVISOR}>Belum ditentukan</SelectItem>
                                {inactiveSupervisor && (
                                    <SelectItem value={String(inactiveSupervisor.id)}>{inactiveSupervisor.name} (nonaktif)</SelectItem>
                                )}
                                {options.supervisors.map((person) => (
                                    <SelectItem key={person.id} value={String(person.id)}>
                                        {person.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <FormDescription>Staf QA/QC yang mengawasi pekerjaan proyek ini.</FormDescription>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </>
    );
}
