import { EmptyState } from '@/Components/shared/EmptyState';
import { MoneyInput } from '@/Components/shared/MoneyInput';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import type { ProjectExecution, User, WorkItemRow } from '@/types';
import { router } from '@inertiajs/react';
import { Check, HardHat, Loader2 } from 'lucide-react';
import { useState } from 'react';

const TYPED = 'typed';

/**
 * Sprint 29 Sub 08 (B3, File A) — "Upah Tukang" per RAB item: the Pengawas
 * (or Admin Proyek) types the Satuan Upah beside the RAB price; subtotal,
 * bobot and total come from the server (WorkItemService — never computed
 * here). Each row saves when its field is left (quiet partial reload).
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
    const data = execution.workItems;
    const canEdit = execution.can.setWages;
    const producing = execution.phase === 'PRODUKSI' || execution.phase === 'PEMASANGAN';

    if (!data) return null;

    const { summary } = data;

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
                                            <WageField item={item} editable={canEdit && !(producing && item.unit_wage !== null && item.unit_wage > 0)} />
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
                                                <td className="px-3 py-2">
                                                    <WageField item={item} editable={canEdit && !(producing && item.unit_wage !== null && item.unit_wage > 0)} />
                                                </td>
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
        </SectionCard>
    );
}

/** One Satuan Upah: saved when the field is left or Enter is pressed (quiet, only `execution` reloads). */
function WageField({ item, editable }: { item: WorkItemRow; editable: boolean }) {
    const [value, setValue] = useState(item.unit_wage === null ? '' : String(item.unit_wage));
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!editable) {
        return <p className="text-right tabular-nums">{item.unit_wage ? formatRupiah(item.unit_wage) : '—'}</p>;
    }

    function save() {
        const next = value.trim() === '' ? null : Number(value);
        if (saving || next === item.unit_wage || (next === null && item.unit_wage === null)) return;

        router.put(route('projects.work-items.update', { workItem: item.id }), { unit_wage: next }, {
            ...projectVisit(RELOAD.wages, { quiet: true }),
            onStart: () => {
                setSaving(true);
                setError(null);
            },
            onFinish: () => setSaving(false),
            onSuccess: () => {
                setSaved(true);
                window.setTimeout(() => setSaved(false), 1500);
            },
            onError: (errors) => {
                setError(errors.unit_wage ?? Object.values(errors)[0] ?? 'Gagal disimpan.');
                setValue(item.unit_wage === null ? '' : String(item.unit_wage));
            },
        });
    }

    return (
        <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5">
                <MoneyInput
                    value={value}
                    onChange={setValue}
                    onBlur={save}
                    onKeyDown={(event) => event.key === 'Enter' && (event.preventDefault(), save())}
                    placeholder="Tanpa upah"
                    aria-label={`Satuan upah ${item.description}`}
                    className="text-right max-md:h-11"
                    disabled={saving}
                />
                <span className="w-4 shrink-0" aria-live="polite">
                    {saving ? <Loader2 className="size-4 animate-spin text-muted-foreground" /> : saved && <Check className="size-4 text-success-ink" aria-label="Tersimpan" />}
                </span>
            </div>
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
