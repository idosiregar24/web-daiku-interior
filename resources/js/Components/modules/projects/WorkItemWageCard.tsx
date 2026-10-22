import { EmptyState } from '@/Components/shared/EmptyState';
import { MoneyInput } from '@/Components/shared/MoneyInput';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import type { ProjectExecution, User, WorkItemRow, WorkTeamTemplate } from '@/types';
import { router } from '@inertiajs/react';
import { Check, HardHat, Loader2 } from 'lucide-react';
import { useState } from 'react';

/**
 * Sprint 29 Sub 08 (B3, File A) — "Upah Tukang" per RAB item: the Pengawas
 * (or Admin Proyek) types the Satuan Upah beside the RAB price; subtotal,
 * bobot and total come from the server (WorkItemService — never computed
 * here). Sprint 33: the changed rows are saved together with one "Simpan".
 * Read-only for the PM / CEO / Finance, and once a wage is locked after
 * Start Produksi.
 */
export function WorkItemWageCard({
    projectId,
    execution,
    fieldStaff,
}: {
    projectId: number;
    execution: ProjectExecution;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    const canEdit = execution.can.setWages;
    const producing = execution.phase === 'PRODUKSI' || execution.phase === 'PEMASANGAN';

    if (!execution.workItems) return null;

    return (
        <SectionCard
            title="Upah Tukang"
            icon={HardHat}
            description={
                canEdit
                    ? `Isi satuan upah di samping harga RAB — subtotal = satuan upah × volume. Item tanpa upah (barang jadi) boleh kosong.${producing ? ' Setelah Start Produksi upah yang sudah diisi terkunci.' : ''}`
                    : 'Upah per item RAB yang diisi Pengawas — dasar DP & pencairan upah.'
            }
        >
            <WorkItemWageTable projectId={projectId} execution={execution} fieldStaff={fieldStaff} />
        </SectionCard>
    );
}

/** Sprint 37 — an item's Tim: a Tim Tukang template, a Tukang as Kepala Tim, or a typed name. */
type TeamChoice = { kind: 'team'; id: number } | { kind: 'user'; id: number } | { kind: 'typed'; name: string } | { kind: 'none' };

type Person = Pick<User, 'id' | 'name' | 'is_active'>;

function serverTeam(item: WorkItemRow): TeamChoice {
    if (item.work_team_id) return { kind: 'team', id: item.work_team_id };
    if (item.team_lead_id) return { kind: 'user', id: item.team_lead_id };
    if (item.team_lead_name) return { kind: 'typed', name: item.team_lead_name };
    return { kind: 'none' };
}

function sameTeam(a: TeamChoice, b: TeamChoice): boolean {
    if (a.kind !== b.kind) return false;
    if (a.kind === 'typed' && b.kind === 'typed') return a.name.trim() === b.name.trim();
    if ((a.kind === 'team' || a.kind === 'user') && (b.kind === 'team' || b.kind === 'user')) return a.id === b.id;
    return true;
}

/** What the server stores for a choice (WorkItemService::update()). */
function teamPayload(choice: TeamChoice) {
    switch (choice.kind) {
        case 'team':
            return { work_team_id: choice.id };
        case 'user':
            return { work_team_id: null, team_lead_id: choice.id, team_lead_name: null };
        case 'typed':
            return { work_team_id: null, team_lead_id: null, team_lead_name: choice.name.trim() };
        default:
            return { work_team_id: null, team_lead_id: null, team_lead_name: null };
    }
}

/**
 * The RAB items with the Satuan Upah column on the right — the card above
 * (Item RAB › Upah Tukang) and the Pengawas's part of the TM card (Sprint 33).
 * Sprint 37 — each item gets its Tim (a Tim Tukang template: its ketua is the
 * Kepala Tim and its members go to Pembagian Upah) or just a Kepala Tim; saved
 * with the same "Simpan" as the wages.
 */
export function WorkItemWageTable({
    projectId,
    execution,
    fieldStaff,
}: {
    projectId: number;
    execution: ProjectExecution;
    fieldStaff: Person[];
}) {
    const data = execution.workItems;
    const canEdit = execution.can.setWages;
    const templates = execution.wageSplit?.templates ?? [];
    const producing = execution.phase === 'PRODUKSI' || execution.phase === 'PEMASANGAN';
    // Sprint 33 — typed values wait for one "Simpan" (only the rows that were changed).
    const [draft, setDraft] = useState<Record<number, string>>({});
    const [teams, setTeams] = useState<Record<number, TeamChoice>>({});
    const [errors, setErrors] = useState<Record<number, string>>({});
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    if (!data) return null;

    const { summary } = data;
    const items = data.sections.flatMap((section) => section.items);
    const serverValue = (item: WorkItemRow) => (item.unit_wage === null ? '' : String(item.unit_wage));
    const valueOf = (item: WorkItemRow) => draft[item.id] ?? serverValue(item);
    const teamOf = (item: WorkItemRow) => teams[item.id] ?? serverTeam(item);
    const asNumber = (value: string) => (value.trim() === '' ? null : Number(value));
    const wageChanged = (item: WorkItemRow) => item.id in draft && asNumber(draft[item.id]) !== item.unit_wage;
    // A typed name still empty is not a change yet.
    const teamChanged = (item: WorkItemRow) => {
        const choice = teams[item.id];
        return choice !== undefined && !(choice.kind === 'typed' && choice.name.trim() === '') && !sameTeam(choice, serverTeam(item));
    };
    const changed = items.filter((item) => wageChanged(item) || teamChanged(item));
    const editable = (item: WorkItemRow) => canEdit && !(producing && item.unit_wage !== null && item.unit_wage > 0);

    function touched(item: WorkItemRow) {
        setErrors((rows) => {
            const { [item.id]: _removed, ...rest } = rows;
            return rest;
        });
        setSaved(false);
    }

    function edit(item: WorkItemRow, value: string) {
        setDraft((rows) => ({ ...rows, [item.id]: value }));
        touched(item);
    }

    function pickTeam(rows: WorkItemRow[], choice: TeamChoice) {
        setTeams((current) => ({ ...current, ...Object.fromEntries(rows.map((item) => [item.id, choice])) }));
        rows.forEach(touched);
    }

    function reset() {
        setDraft({});
        setTeams({});
        setErrors({});
    }

    function save() {
        if (saving || changed.length === 0) return;

        router.put(
            route('projects.work-items.wages', { project: projectId }),
            {
                items: changed.map((item) => ({
                    id: item.id,
                    unit_wage: asNumber(valueOf(item)),
                    ...(teamChanged(item) ? teamPayload(teamOf(item)) : {}),
                })),
            },
            {
                ...projectVisit(RELOAD.wages, { quiet: true }),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    reset();
                    setSaved(true);
                },
                onError: (response) => {
                    const byItem: Record<number, string> = {};
                    Object.entries(response).forEach(([key, message]) => {
                        const match = key.match(/^items\.(\d+)\./);
                        const item = match ? changed[Number(match[1])] : undefined;
                        if (item) byItem[item.id] = message;
                    });
                    setErrors(byItem);
                },
            },
        );
    }

    function field(item: WorkItemRow) {
        return (
            <WageField
                item={item}
                editable={editable(item)}
                value={valueOf(item)}
                dirty={wageChanged(item)}
                error={errors[item.id]}
                disabled={saving}
                onChange={(value) => edit(item, value)}
                onEnter={save}
            />
        );
    }

    function team(item: WorkItemRow) {
        if (!canEdit) return <TeamLabel item={item} />;

        return (
            <TeamPicker
                value={teamOf(item)}
                templates={templates}
                fieldStaff={fieldStaff}
                dirty={teamChanged(item)}
                disabled={saving}
                label={`Tim ${item.description}`}
                onChange={(choice) => pickTeam([item], choice)}
            />
        );
    }

    return (
        <>
            {data.sections.length === 0 ? (
                <EmptyState title="Proyek ini belum punya item RAB." />
            ) : (
                <div className="space-y-5">
                    {canEdit && (
                        <p className="text-xs text-muted-foreground">
                            Kolom <span className="font-medium text-foreground">Tim</span>: pilih template{' '}
                            <span className="font-medium text-foreground">Tim Tukang</span> — ketuanya jadi Kepala Tim item itu dan anggotanya otomatis masuk
                            Pembagian Upah — atau pilih satu tukang sebagai Kepala Tim saja.
                        </p>
                    )}
                    {data.sections.map((section) => (
                        <div key={section.id} className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-semibold text-foreground">{section.title}</p>
                                {canEdit && section.items.length > 1 && (
                                    <SectionTeam templates={templates} fieldStaff={fieldStaff} disabled={saving} onApply={(choice) => pickTeam(section.items, choice)} />
                                )}
                            </div>
                            <ul className="space-y-2 md:hidden">
                                {section.items.map((item) => (
                                    <li key={item.id} className="space-y-2 rounded-lg border border-border p-3">
                                        <div>
                                            <p className="text-sm font-medium text-foreground">{item.description}</p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {item.volume_label} {item.unit} · RAB {formatRupiah(item.rab_subtotal)}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {field(item)}
                                            <p className="shrink-0 text-right text-sm font-medium tabular-nums">{formatRupiah(item.wage_subtotal)}</p>
                                        </div>
                                        {team(item)}
                                    </li>
                                ))}
                            </ul>
                            <TableCard className="hidden md:block">
                                <table className="w-full text-sm">
                                    <thead className={TABLE_HEAD_CLASS}>
                                        <tr>
                                            <th className="px-3 py-2 text-left font-semibold">Uraian</th>
                                            <th className="px-3 py-2 text-right font-semibold">Volume</th>
                                            <th className="px-3 py-2 text-right font-semibold">Harga RAB</th>
                                            <th className="px-3 py-2 text-right font-semibold">Subtotal RAB</th>
                                            <th className="w-44 px-3 py-2 text-right font-semibold">Satuan Upah</th>
                                            <th className="px-3 py-2 text-right font-semibold">Subtotal Upah</th>
                                            <th className="px-3 py-2 text-right font-semibold">
                                                Bobot
                                                {/* Same formula as WorkItemService::row(). */}
                                                <span className="block text-[10px] font-normal tracking-normal normal-case">Subtotal RAB ÷ Σ RAB berupah</span>
                                            </th>
                                            <th className="w-56 px-3 py-2 text-left font-semibold">Tim / Kepala Tim</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {section.items.map((item) => (
                                            <tr key={item.id} className="border-t border-border align-top">
                                                <td className="px-3 py-2 text-foreground">{item.description}</td>
                                                <td className="px-3 py-2 text-right whitespace-nowrap text-muted-foreground tabular-nums">
                                                    {item.volume_label} {item.unit}
                                                </td>
                                                <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatRupiah(item.unit_price)}</td>
                                                <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatRupiah(item.rab_subtotal)}</td>
                                                <td className="px-3 py-2">{field(item)}</td>
                                                <td className="px-3 py-2 text-right font-medium whitespace-nowrap tabular-nums">{item.wage_subtotal > 0 ? formatRupiah(item.wage_subtotal) : '—'}</td>
                                                <td className="px-3 py-2 text-right whitespace-nowrap text-muted-foreground tabular-nums">
                                                    {item.weight !== null ? `${item.weight.toLocaleString('id-ID')}%` : '—'}
                                                </td>
                                                <td className="px-3 py-2">{team(item)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </TableCard>
                        </div>
                    ))}

                    {canEdit && (
                        <div className="sticky bottom-3 z-10 flex flex-col gap-2 rounded-lg bg-background p-3 shadow-md ring-1 ring-border sm:flex-row sm:items-center sm:justify-between">
                            <p className={cn('text-sm', changed.length > 0 ? 'font-medium text-warning-ink' : 'text-muted-foreground')} aria-live="polite">
                                {changed.length > 0 ? (
                                    `${changed.length} item belum disimpan — subtotal, total & Pembagian Upah diperbarui setelah disimpan.`
                                ) : saved ? (
                                    <span className="inline-flex items-center gap-1 font-medium text-success-ink">
                                        Tersimpan
                                        <Check className="size-4" />
                                    </span>
                                ) : (
                                    'Isi satuan upah & tim tiap item, lalu tekan Simpan.'
                                )}
                            </p>
                            <div className="flex gap-2">
                                {changed.length > 0 && (
                                    <Button type="button" variant="outline" disabled={saving} onClick={reset}>
                                        Batalkan
                                    </Button>
                                )}
                                <Button type="button" className="max-md:h-11 max-md:flex-1" disabled={saving || changed.length === 0} onClick={save}>
                                    {saving && <Loader2 className="size-4 animate-spin" />}
                                    {saving ? 'Menyimpan…' : changed.length > 0 ? `Simpan ${changed.length} item` : 'Simpan'}
                                </Button>
                            </div>
                        </div>
                    )}

                    <div className="grid gap-3 rounded-lg bg-daiku-gray/60 p-4 sm:grid-cols-3">
                        <div>
                            <p className="text-xs text-muted-foreground">Total Upah Tukang</p>
                            <p className="text-lg font-semibold tabular-nums">{formatRupiah(summary.total_wage)}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Item berupah</p>
                            <p className="text-sm font-medium">
                                {summary.paid_items} dari {summary.items}
                                {summary.unpaid_items > 0 && <span className="text-muted-foreground"> · {summary.unpaid_items} tanpa upah</span>}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Per Kepala Tim</p>
                            {summary.per_team_lead.length === 0 ? (
                                <p className="text-sm text-muted-foreground">—</p>
                            ) : (
                                <ul className="text-sm">
                                    {summary.per_team_lead.map((row) => (
                                        <li key={row.label} className="flex justify-between gap-2">
                                            <span>{row.label}</span>
                                            <span className="font-medium tabular-nums">{formatRupiah(row.total)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

/** One Satuan Upah input; the table's "Simpan" sends it (Enter = Simpan). A changed row is marked. */
function WageField({
    item,
    editable,
    value,
    dirty,
    error,
    disabled,
    onChange,
    onEnter,
}: {
    item: WorkItemRow;
    editable: boolean;
    value: string;
    dirty: boolean;
    error?: string;
    disabled: boolean;
    onChange: (value: string) => void;
    onEnter: () => void;
}) {
    if (!editable) {
        return <p className="text-right tabular-nums">{item.unit_wage ? formatRupiah(item.unit_wage) : '—'}</p>;
    }

    return (
        <div className="min-w-0 flex-1">
            <MoneyInput
                value={value}
                onChange={onChange}
                onKeyDown={(event) => event.key === 'Enter' && (event.preventDefault(), onEnter())}
                placeholder="Tanpa upah"
                aria-label={`Satuan upah ${item.description}`}
                aria-invalid={Boolean(error)}
                className={cn('text-right max-md:h-11', dirty && 'bg-daiku-yellow-light ring-1 ring-daiku-yellow')}
                disabled={disabled}
            />
            {error && <p className="mt-1 text-xs font-medium text-error-ink">{error}</p>}
        </div>
    );
}

/** Read-only: "Tim Budi · Budi Tukang", or only the Kepala Tim. */
function TeamLabel({ item }: { item: WorkItemRow }) {
    if (!item.team_lead) return <span className="text-muted-foreground">—</span>;

    return (
        <span className="text-muted-foreground">
            {item.work_team && <span className="font-medium text-foreground">{item.work_team} · </span>}
            {item.team_lead}
        </span>
    );
}

const TYPED = 'typed';

function encode(choice: TeamChoice): string {
    switch (choice.kind) {
        case 'team':
            return `team:${choice.id}`;
        case 'user':
            return `user:${choice.id}`;
        case 'typed':
            return TYPED;
        default:
            return '';
    }
}

function decode(value: string, typedName: string): TeamChoice {
    if (value === TYPED) return { kind: 'typed', name: typedName };
    const [kind, id] = value.split(':');
    return kind === 'team' ? { kind: 'team', id: Number(id) } : { kind: 'user', id: Number(id) };
}

/**
 * The Tim of an item: a Tim Tukang template (⚙ Pengaturan › Tim Tukang), a
 * Tukang as Kepala Tim, or a typed name (an outside team).
 */
function TeamPicker({
    value,
    templates,
    fieldStaff,
    dirty,
    disabled,
    label,
    onChange,
}: {
    value: TeamChoice;
    templates: WorkTeamTemplate[];
    fieldStaff: Person[];
    dirty: boolean;
    disabled: boolean;
    label: string;
    onChange: (choice: TeamChoice) => void;
}) {
    const template = value.kind === 'team' ? templates.find((team) => team.id === value.id) : undefined;
    const people = fieldStaff.filter((person) => person.is_active !== false || (value.kind === 'user' && value.id === person.id));

    return (
        <div className="min-w-0 space-y-1">
            <Select value={encode(value)} onValueChange={(next) => onChange(decode(next, value.kind === 'typed' ? value.name : ''))} disabled={disabled}>
                <SelectTrigger
                    className={cn('h-9 w-full max-md:h-11', dirty && 'bg-daiku-yellow-light ring-1 ring-daiku-yellow')}
                    aria-label={label}
                >
                    <SelectValue placeholder="Pilih tim…" />
                </SelectTrigger>
                <SelectContent>
                    {templates.length > 0 && (
                        <SelectGroup>
                            <SelectLabel>Tim Tukang</SelectLabel>
                            {templates.map((team) => (
                                <SelectItem key={`team-${team.id}`} value={`team:${team.id}`}>
                                    {team.name}
                                    {team.leader_name && <span className="text-muted-foreground"> · {team.leader_name}</span>}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    )}
                    {templates.length > 0 && <SelectSeparator />}
                    <SelectGroup>
                        <SelectLabel>Kepala Tim saja</SelectLabel>
                        {people.map((person) => (
                            <SelectItem key={`user-${person.id}`} value={`user:${person.id}`}>
                                {person.name}
                            </SelectItem>
                        ))}
                        <SelectItem value={TYPED}>Ketik nama…</SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
            {value.kind === 'typed' && (
                <Input
                    value={value.name}
                    onChange={(event) => onChange({ kind: 'typed', name: event.target.value })}
                    placeholder="Nama Kepala Tim"
                    aria-label={`${label} — nama Kepala Tim`}
                    className="h-9 max-md:h-11"
                    disabled={disabled}
                />
            )}
            {template && (
                <p className="text-xs text-muted-foreground">
                    {template.members.filter((member) => !member.company).length} orang
                    {dirty && ' · anggota disalin ke Pembagian Upah saat disimpan'}
                </p>
            )}
        </div>
    );
}

/** "Terapkan ke bagian ini" — one Tim for every item of the section (still saved with "Simpan"). */
function SectionTeam({
    templates,
    fieldStaff,
    disabled,
    onApply,
}: {
    templates: WorkTeamTemplate[];
    fieldStaff: Person[];
    disabled: boolean;
    onApply: (choice: TeamChoice) => void;
}) {
    const [choice, setChoice] = useState<TeamChoice>({ kind: 'none' });
    const ready = choice.kind !== 'none' && (choice.kind !== 'typed' || choice.name.trim() !== '');

    return (
        <div className="flex flex-wrap items-start gap-2">
            <div className="w-52">
                <TeamPicker value={choice} templates={templates} fieldStaff={fieldStaff} dirty={false} disabled={disabled} label="Tim bagian ini" onChange={setChoice} />
            </div>
            <Button size="sm" variant="outline" className="h-9" disabled={!ready || disabled} onClick={() => onApply(choice)}>
                Terapkan ke bagian ini
            </Button>
        </div>
    );
}
