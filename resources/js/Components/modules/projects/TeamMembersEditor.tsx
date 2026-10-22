import { Button } from '@/Components/ui/button';
import { FormControl, FormField, FormItem, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CareerLevelOption, WageSplitMember } from '@/types';
import { Building2, Plus, Sigma, Trash2 } from 'lucide-react';
import { useFieldArray, type UseFormReturn } from 'react-hook-form';
import { z } from 'zod';

const CUSTOM = '__custom';

// Mirrors SaveWorkTeamRequest::memberRules() + WageSplitService::cleanMembers().
export const membersSchema = z
    .array(
        z.object({
            name: z.string().trim().max(120, 'Nama maksimal 120 karakter.'),
            level: z.string().trim().max(60),
            weight: z.string().refine((v) => v.trim() !== '' && Number(v) > 0 && Number(v) <= 1000, 'Bobot harus lebih dari 0.'),
            company: z.boolean(),
        }),
    )
    .max(30, 'Maksimal 30 baris.')
    .superRefine((rows, ctx) => {
        if (!rows.some((row) => !row.company)) {
            ctx.addIssue({ code: 'custom', path: ['root'], message: 'Isi minimal satu anggota tim.' });
        }
        rows.forEach((row, i) => {
            if (!row.company && row.name.trim() === '' && row.level.trim() === '') {
                ctx.addIssue({ code: 'custom', path: [i, 'name'], message: 'Isi nama atau tingkatan.' });
            }
        });
    });

export type MemberValues = z.infer<typeof membersSchema>[number];

/** The form shape both dialogs share (the project split ignores `name` / `leader_name`). */
export interface TeamFormValues {
    name: string;
    leader_name: string;
    members: MemberValues[];
}

export function toMemberValues(members: WageSplitMember[]): MemberValues[] {
    return members.map((member) => ({ name: member.name ?? '', level: member.level ?? '', weight: String(member.weight), company: member.company }));
}

export function toMembersPayload(rows: MemberValues[]) {
    return rows.map((row) => ({
        name: row.company ? null : row.name.trim() || null,
        level: row.level.trim() || null,
        weight: Number(row.weight),
        company: row.company,
    }));
}

/**
 * The same split as WageSplitService::split() — whole rupiah, the rest to the
 * largest remainders — for the editor's preview only (slips come from the server).
 */
export function previewSplit(amount: number, weights: number[]): number[] {
    const total = weights.reduce((sum, w) => sum + (w > 0 ? w : 0), 0);
    const rupiah = Math.round(amount);
    if (total <= 0 || rupiah <= 0) return weights.map(() => 0);

    const raw = weights.map((w) => (rupiah * Math.max(w, 0)) / total);
    const parts = raw.map(Math.floor);
    const order = raw.map((value, i) => ({ i, rest: value - Math.floor(value) })).sort((a, b) => b.rest - a.rest || a.i - b.i);
    let left = rupiah - parts.reduce((sum, p) => sum + p, 0);
    for (const { i } of order) {
        if (left <= 0) break;
        parts[i]++;
        left--;
    }

    return parts;
}

export function formatPercent(value: number): string {
    return `${value.toLocaleString('id-ID', { maximumFractionDigits: 2 })} %`;
}

/**
 * Sprint 32 — the formula of WageSplitService, spelled out small under a
 * team or a slip; `example` = one person's numbers worked through.
 */
export function FormulaHint({
    withAmount = false,
    example,
}: {
    withAmount?: boolean;
    example?: { who: string; weight: number; total: number; amount?: number | null; part?: number | null } | null;
}) {
    const percent = example && example.total > 0 ? Math.round((example.weight / example.total) * 10000) / 100 : null;

    return (
        <div className="flex gap-2 rounded-lg bg-daiku-gray px-3 py-2 text-xs leading-relaxed text-daiku-muted">
            <Sigma className="mt-0.5 size-3.5 shrink-0" />
            <div className="space-y-0.5">
                <p>
                    <span className="font-medium text-foreground">% orang</span> = bobot orang ÷ total bobot tim × 100
                </p>
                {withAmount && (
                    <p>
                        <span className="font-medium text-foreground">Bagian orang</span> = bagian tim di pencairan × % orang — dibulatkan ke rupiah, sisa
                        pembulatan ke pecahan terbesar supaya totalnya pas
                    </p>
                )}
                {example && percent !== null && (
                    <p className="tabular-nums">
                        Contoh {example.who}: {example.weight.toLocaleString('id-ID')} ÷ {example.total.toLocaleString('id-ID')} × 100 ={' '}
                        {formatPercent(percent)}
                        {withAmount && example.amount ? ` → ${formatRupiah(example.amount)} × ${formatPercent(percent)} ≈ ${formatRupiah(example.part)}` : ''}
                    </p>
                )}
            </div>
        </div>
    );
}

/**
 * Sprint 32 — the members of a Tukang team: one row per person (name,
 * tingkatan → its default bobot, bobot) with the live share, plus the
 * "Perusahaan" row (File D). Quick-add buttons per tingkatan so a Pengawas
 * builds a team in a few taps; `previewAmount` shows what each would get.
 */
export function TeamMembersEditor({
    form,
    levels,
    previewAmount,
}: {
    form: UseFormReturn<TeamFormValues>;
    levels: CareerLevelOption[];
    previewAmount?: number | null;
}) {
    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'members' });
    const rows = form.watch('members');
    const weights = rows.map((row) => Number(row.weight) || 0);
    const total = weights.reduce((sum, w) => sum + (w > 0 ? w : 0), 0);
    const amounts = previewAmount ? previewSplit(previewAmount, weights) : null;
    const company = levels.find((level) => level.is_company);
    const hasCompany = rows.some((row) => row.company);
    const rootError = form.formState.errors.members?.root?.message ?? form.formState.errors.members?.message;

    function add(level: CareerLevelOption | null) {
        append({ name: '', level: level?.name ?? '', weight: level ? String(level.weight) : '', company: level?.is_company ?? false });
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="mr-1 text-xs font-medium text-daiku-muted">Tambah:</span>
                {levels
                    .filter((level) => !level.is_company)
                    .map((level) => (
                        <Button key={level.id} type="button" variant="outline" size="sm" onClick={() => add(level)}>
                            <Plus className="size-3.5" />
                            {level.name}
                        </Button>
                    ))}
                <Button type="button" variant="outline" size="sm" onClick={() => add(null)}>
                    <Plus className="size-3.5" />
                    Lainnya
                </Button>
                {company && !hasCompany && (
                    <Button type="button" variant="outline" size="sm" onClick={() => add(company)}>
                        <Building2 className="size-3.5" />
                        Bagian {company.name}
                    </Button>
                )}
            </div>

            {fields.length === 0 ? (
                <p className="rounded-lg border border-dashed border-daiku-border px-3 py-4 text-center text-sm text-daiku-muted">
                    Belum ada anggota — tekan tombol tingkatan di atas untuk menambah orang.
                </p>
            ) : (
                <ul className="space-y-2">
                    <li className="hidden grid-cols-[minmax(0,1fr)_9rem_5rem_5.5rem_2rem] gap-2 px-1 text-[11px] font-medium uppercase tracking-wide text-daiku-muted sm:grid">
                        <span>Nama</span>
                        <span>Tingkatan</span>
                        <span className="text-center">Bobot</span>
                        <span className="text-right">{amounts ? 'Bagian' : 'Persen'}</span>
                        <span />
                    </li>
                    {fields.map((field, index) => {
                        const row = rows[index];
                        const share = total > 0 && weights[index] > 0 ? (weights[index] / total) * 100 : 0;

                        return (
                            <li
                                key={field.id}
                                className={cn(
                                    'grid grid-cols-[minmax(0,1fr)_2rem] gap-2 rounded-lg border border-daiku-border p-2 sm:grid-cols-[minmax(0,1fr)_9rem_5rem_5.5rem_2rem] sm:items-start sm:border-0 sm:p-0',
                                    row?.company && 'bg-daiku-gray/60 sm:bg-transparent',
                                )}
                            >
                                {row?.company ? (
                                    <div className="flex h-9 items-center gap-2 rounded-md bg-daiku-gray px-3 text-sm text-daiku-muted">
                                        <Building2 className="size-4 shrink-0" />
                                        Bagian {row.level || 'Perusahaan'}
                                    </div>
                                ) : (
                                    <FormField
                                        control={form.control}
                                        name={`members.${index}.name`}
                                        render={({ field: input }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <Input {...input} placeholder="Nama tukang" aria-label={`Nama anggota ${index + 1}`} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    className="mt-0.5 sm:order-last"
                                    aria-label={`Hapus baris ${index + 1}`}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2 className="size-4 text-error-ink" />
                                </Button>
                                <div className="col-span-2 grid grid-cols-[minmax(0,1fr)_5rem_5.5rem] gap-2 sm:col-span-1 sm:contents">
                                    {row?.company ? (
                                        <span className="hidden sm:block" />
                                    ) : (
                                        <Select
                                            value={levels.some((level) => level.name === row?.level && !level.is_company) ? row.level : CUSTOM}
                                            onValueChange={(value) => {
                                                const level = levels.find((option) => option.name === value);
                                                form.setValue(`members.${index}.level`, level?.name ?? '', { shouldDirty: true });
                                                if (level) form.setValue(`members.${index}.weight`, String(level.weight), { shouldDirty: true, shouldValidate: true });
                                            }}
                                        >
                                            <SelectTrigger className="w-full" aria-label={`Tingkatan anggota ${index + 1}`}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {levels
                                                    .filter((level) => !level.is_company)
                                                    .map((level) => (
                                                        <SelectItem key={level.id} value={level.name}>
                                                            {level.name} · {level.weight}
                                                        </SelectItem>
                                                    ))}
                                                <SelectItem value={CUSTOM}>Lainnya (bobot sendiri)</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    )}
                                    <FormField
                                        control={form.control}
                                        name={`members.${index}.weight`}
                                        render={({ field: input }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <Input
                                                        {...input}
                                                        inputMode="decimal"
                                                        className="text-center tabular-nums"
                                                        aria-label={`Bobot anggota ${index + 1}`}
                                                        onChange={(event) => input.onChange(event.target.value.replace(',', '.').replace(/[^\d.]/g, ''))}
                                                    />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <div className="flex h-9 flex-col items-end justify-center text-right leading-tight">
                                        <span className="text-sm font-semibold tabular-nums text-foreground">{formatPercent(Math.round(share * 100) / 100)}</span>
                                        {amounts && <span className="text-[11px] tabular-nums text-daiku-muted">{formatRupiah(amounts[index])}</span>}
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                    <li className="flex items-center justify-between border-t border-daiku-border px-1 pt-2 text-sm">
                        <span className="text-daiku-muted">
                            {rows.filter((row) => !row.company).length} orang · total bobot <span className="tabular-nums">{total.toLocaleString('id-ID')}</span>
                        </span>
                        <span className="font-semibold tabular-nums">{amounts ? formatRupiah(previewAmount) : '100 %'}</span>
                    </li>
                </ul>
            )}
            {rootError && <p className="text-sm text-error-ink">{rootError}</p>}
            {fields.length > 0 && (
                <FormulaHint
                    withAmount={!!amounts}
                    example={(() => {
                        const index = rows.findIndex((row, i) => !row.company && weights[i] > 0);
                        if (index < 0) return null;
                        const row = rows[index];

                        return { who: row.name.trim() || row.level || 'anggota 1', weight: weights[index], total, amount: previewAmount, part: amounts?.[index] };
                    })()}
                />
            )}
        </div>
    );
}
