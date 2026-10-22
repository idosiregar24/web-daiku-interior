import { EmptyState } from '@/Components/shared/EmptyState';
import { MoneyInput } from '@/Components/shared/MoneyInput';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import type { ProjectExecution, User, WorkItemRow } from '@/types';
import { router } from '@inertiajs/react';
import { Check, HardHat, Loader2 } from 'lucide-react';
import { useState } from 'react';

const TYPED = 'typed';

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

/**
 * The RAB items with the Satuan Upah column on the right — the card above
 * (Item RAB › Upah Tukang) and the Pengawas's part of the TM card (Sprint 33).
 */
export function WorkItemWageTable({
    projectId,
    execution,
    fieldStaff,
}: {
    projectId: number;
    execution: ProjectExecution;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    const data = execution.workItems;
    const canEdit = execution.can.setWages;
    const producing = execution.phase === 'PRODUKSI' || execution.phase === 'PEMASANGAN';
    // Sprint 33 — typed values wait for one "Simpan" (only the rows that were changed).
    const [draft, setDraft] = useState<Record<number, string>>({});
    const [errors, setErrors] = useState<Record<number, string>>({});
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    if (!data) return null;

    const { summary } = data;
    const items = data.sections.flatMap((section) => section.items);
    const serverValue = (item: WorkItemRow) => (item.unit_wage === null ? '' : String(item.unit_wage));
    const valueOf = (item: WorkItemRow) => draft[item.id] ?? serverValue(item);
    const asNumber = (value: string) => (value.trim() === '' ? null : Number(value));
    const changed = items.filter((item) => item.id in draft && asNumber(draft[item.id]) !== item.unit_wage);
    const editable = (item: WorkItemRow) => canEdit && !(producing && item.unit_wage !== null && item.unit_wage > 0);

    function edit(item: WorkItemRow, value: string) {
        setDraft((rows) => ({ ...rows, [item.id]: value }));
        setErrors((rows) => {
            const { [item.id]: _removed, ...rest } = rows;
            return rest;
        });
        setSaved(false);
    }

    function save() {
        if (saving || changed.length === 0) return;

        router.put(
            route('projects.work-items.wages', { project: projectId }),
            { items: changed.map((item) => ({ id: item.id, unit_wage: asNumber(draft[item.id]) })) },
            {
                ...projectVisit(RELOAD.wages, { quiet: true }),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setDraft({});
                    setErrors({});
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
                dirty={changed.some((row) => row.id === item.id)}
                error={errors[item.id]}
                disabled={saving}
                onChange={(value) => edit(item, value)}
                onEnter={save}
            />
        );
    }

    return (
        <>
            {data.sections.length === 0 ? (
                <EmptyState title="Proyek ini belum punya item RAB." />
            ) : (
                <div className="space-y-5">
                    {data.sections.map((section) => (
                        <div key={section.id} className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-semibold text-foreground">{section.title}</p>
                                {canEdit && <SectionTeamLead projectId={projectId} itemIds={section.items.map((item) => item.id)} fieldStaff={fieldStaff} />}
                            </div>
                            <ul className="space-y-2 md:hidden">
                                {section.items.map((item) => (
                                    <li key={item.id} className="rounded-lg border border-border p-3">
                                        <p className="text-sm font-medium text-foreground">{item.description}</p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {item.volume_label} {item.unit} · RAB {formatRupiah(item.rab_subtotal)}
                                            {item.team_lead && ` · ${item.team_lead}`}
                                        </p>
                                        <div className="mt-2 flex items-center gap-2">
                                            {field(item)}
                                            <p className="shrink-0 text-right text-sm font-medium tabular-nums">{formatRupiah(item.wage_subtotal)}</p>
                                        </div>
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
                                            <th className="px-3 py-2 text-right font-semibold">Bobot</th>
                                            <th className="px-3 py-2 text-left font-semibold">Kepala Tim</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {section.items.map((item) => (
                                            <tr key={item.id} className="border-t border-border">
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
                                                <td className="px-3 py-2 text-muted-foreground">{item.team_lead ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </TableCard>
                        </div>
                    ))}

                    {canEdit && items.some(editable) && (
                        <div className="sticky bottom-3 z-10 flex flex-col gap-2 rounded-lg bg-background p-3 shadow-md ring-1 ring-border sm:flex-row sm:items-center sm:justify-between">
                            <p className={cn('text-sm', changed.length > 0 ? 'font-medium text-warning-ink' : 'text-muted-foreground')} aria-live="polite">
                                {changed.length > 0 ? (
                                    `${changed.length} upah belum disimpan — subtotal & total diperbarui setelah disimpan.`
                                ) : saved ? (
                                    <span className="inline-flex items-center gap-1 font-medium text-success-ink">
                                        Tersimpan
                                        <Check className="size-4" />
                                    </span>
                                ) : (
                                    'Isi satuan upah tiap item, lalu tekan Simpan.'
                                )}
                            </p>
                            <div className="flex gap-2">
                                {changed.length > 0 && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={saving}
                                        onClick={() => {
                                            setDraft({});
                                            setErrors({});
                                        }}
                                    >
                                        Batalkan
                                    </Button>
                                )}
                                <Button type="button" className="max-md:h-11 max-md:flex-1" disabled={saving || changed.length === 0} onClick={save}>
                                    {saving && <Loader2 className="size-4 animate-spin" />}
                                    {saving ? 'Menyimpan…' : changed.length > 0 ? `Simpan ${changed.length} upah` : 'Simpan'}
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

/** "Terapkan ke bagian ini" — one Kepala Tim (a tukang, or a typed name) for every item of the section. */
function SectionTeamLead({ projectId, itemIds, fieldStaff }: { projectId: number; itemIds: number[]; fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[] }) {
    const [choice, setChoice] = useState('');
    const [name, setName] = useState('');
    const [saving, setSaving] = useState(false);
    const ready = choice !== '' && (choice !== TYPED || name.trim() !== '');

    function apply() {
        if (!ready || saving) return;

        router.post(
            route('projects.work-items.teamLead', { project: projectId }),
            { item_ids: itemIds, team_lead_id: choice === TYPED ? null : Number(choice), team_lead_name: choice === TYPED ? name.trim() : null },
            { ...projectVisit(RELOAD.wages, { quiet: true }), onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Select value={choice} onValueChange={setChoice}>
                <SelectTrigger className="h-9 w-44" aria-label="Kepala Tim bagian ini">
                    <SelectValue placeholder="Kepala Tim…" />
                </SelectTrigger>
                <SelectContent>
                    {fieldStaff
                        .filter((person) => person.is_active !== false)
                        .map((person) => (
                            <SelectItem key={person.id} value={String(person.id)}>
                                {person.name}
                            </SelectItem>
                        ))}
                    <SelectItem value={TYPED}>Ketik nama…</SelectItem>
                </SelectContent>
            </Select>
            {choice === TYPED && <Input value={name} onChange={(event) => setName(event.target.value)} placeholder="Nama Kepala Tim" className="h-9 w-40" />}
            <Button size="sm" variant="outline" disabled={!ready || saving} onClick={apply}>
                {saving && <Loader2 className="size-4 animate-spin" />}
                Terapkan ke bagian ini
            </Button>
        </div>
    );
}
