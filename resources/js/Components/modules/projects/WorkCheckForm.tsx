import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { Notice } from '@/Components/shared/Notice';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import { formatPercent, parsePercent, previewWorkCheck } from '@/lib/workCheck';
import type { WorkCheck } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Check, Eraser, HandCoins, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useForm, useWatch, type UseFormReturn } from 'react-hook-form';
import { z } from 'zod';

type Entry = { percent: string; note: string };
type FormValues = {
    notes: string;
    links: string;
    entries: Record<string, Entry>;
};
type DraftState = 'idle' | 'saving' | 'saved' | 'error';

/**
 * Sprint 31 Sub 07 — what the Lembar Progres gets to put this form's input
 * into its own table (the today column, like typing in the Excel cell): a
 * compact field per item and the live preview (new progress per item, the
 * progres berbobot).
 */
export interface SheetInput {
    field: (item: WorkCheck['items'][number]) => ReactNode;
    preview: ReturnType<typeof previewWorkCheck>;
}

const QUICK = [10, 20, 50] as const;
const DRAFT_DELAY = 1500;

/**
 * Mirrors SubmitWeeklyReportRequest + WorkItemService::recordProgress():
 * notes ≥ 5 characters; per item the % added keeps Σ inside 0–100 and a
 * negative % (a correction) needs a note.
 */
function schemaFor(items: WorkCheck['items']) {
    const byId = new Map(items.map((item) => [String(item.id), item]));

    return z
        .object({
            notes: z.string().trim().min(5, 'Catatan pengecekan minimal 5 karakter.').max(5000, 'Catatan maksimal 5000 karakter.'),
            links: z.string(),
            entries: z.record(z.string(), z.object({ percent: z.string(), note: z.string() })),
        })
        .superRefine((values, ctx) => {
            Object.entries(values.entries).forEach(([id, entry]) => {
                const item = byId.get(id);
                const raw = entry.percent.trim().replace(',', '.');
                if (!item || raw === '') return;

                const added = Number(raw);
                if (!Number.isFinite(added) || !/^-?\d+(\.\d{1,2})?$/.test(raw)) {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['entries', id, 'percent'],
                        message: 'Persen harus angka (maks. 2 desimal).',
                    });
                    return;
                }

                const after = Math.round((item.progress + added) * 100) / 100;
                if (after < 0 || after > 100) {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['entries', id, 'percent'],
                        message: `Harus 0–100 % (sisa ${formatPercent(100 - item.progress)}).`,
                    });
                }
                if (added < 0 && entry.note.trim() === '') {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['entries', id, 'note'],
                        message: 'Koreksi (persen minus) wajib diberi catatan.',
                    });
                }
            });
        });
}

function defaultsFrom(check: WorkCheck): FormValues {
    const draft = check.draft;

    return {
        notes: draft?.notes ?? '',
        links: (draft?.links ?? []).join('\n'),
        entries: Object.fromEntries(
            check.items.map((item) => {
                const saved = draft?.entries[String(item.id)];

                return [
                    String(item.id),
                    {
                        percent: saved?.percent !== null && saved?.percent !== undefined ? String(saved.percent) : '',
                        note: saved?.note ?? '',
                    },
                ];
            }),
        ),
    };
}

function payloadOf(values: FormValues, check: WorkCheck, onlyFilled: boolean) {
    return {
        notes: values.notes,
        links: values.links
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean),
        progress: check.items
            .map((item) => {
                const entry = values.entries[String(item.id)];
                const percent = entry?.percent.trim() === '' ? null : parsePercent(entry?.percent);

                return {
                    item_id: item.id,
                    percent,
                    note: entry?.note.trim() || null,
                };
            })
            .filter((row) => (onlyFilled ? row.percent !== null && row.percent !== 0 : row.percent !== null || row.note !== null)),
    };
}

/**
 * Sprint 29 Sub 09 (C1) — "Pengecekan % per item" (File B "CEK n"): for each
 * RAB item with a wage, the % added this time; the live summary (progres
 * berbobot, upah boleh cair, sudah cair, cair kali ini, per Kepala Tim) is a
 * preview of WorkItemService. The draft saves itself (Pengawas or Admin
 * Proyek); only the Pengawas sends it — that is the weekly report and the
 * request of "cair kali ini" to the PM in one step.
 */
export function WorkCheckForm({
    projectId,
    check,
    weekLabel,
    canSubmit,
    canDraft,
    blockedReason,
    rejectedNote,
    renderSheet,
}: {
    projectId: number;
    check: WorkCheck;
    /** "Minggu ke-3" / "Minggu ke-3 · Pengecekan ke-2" — the pengecekan this form sends (Sprint 31). */
    weekLabel: string;
    /** The project's Pengawas, and this week's report may be (re)sent. */
    canSubmit: boolean;
    canDraft: boolean;
    /** Why it can't be sent now (already sent this week…), shown instead of the button. */
    blockedReason?: string | null;
    rejectedNote?: string | null;
    /** Sprint 31 Sub 07 — the wide-screen table is the caller's (the Lembar Progres); phones keep the input cards. */
    renderSheet?: (input: SheetInput) => ReactNode;
}) {
    const schema = useMemo(() => schemaFor(check.items), [check.items]);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: defaultsFrom(check),
    });
    const [draftState, setDraftState] = useState<DraftState>('idle');
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);
    const timer = useRef<number | null>(null);
    const sendingRef = useRef(false);

    const entries = useWatch({ control: form.control, name: 'entries' });
    const filled = Object.values(entries ?? {}).filter((entry) => (entry?.percent ?? '').trim() !== '').length;
    // A draft left from an earlier day (never sent) — its % are not today's work.
    const draftDay = check.draft?.updated_at ? check.draft.updated_at.slice(0, 10) : null;
    const staleDraft = draftDay !== null && draftDay < new Date().toLocaleDateString('sv-SE') && filled > 0;

    /** "Kosongkan isian": every % and correction note emptied (the notes stay); the draft saves itself. */
    function clearEntries() {
        check.items.forEach((item) => {
            form.setValue(`entries.${item.id}.percent`, '', { shouldDirty: true });
            form.setValue(`entries.${item.id}.note`, '', { shouldDirty: true });
        });
        form.clearErrors('entries');
    }
    const added = useMemo(() => Object.fromEntries(Object.entries(entries ?? {}).map(([id, entry]) => [Number(id), parsePercent(entry?.percent)])), [entries]);
    const preview = useMemo(() => previewWorkCheck(check, added), [check, added]);
    const { summary } = check;
    const seesMoney = summary.already_paid !== null;

    // Draft saved automatically (K3/K4: no pop-up, no reload of the page's props).
    useEffect(() => {
        if (!canDraft) return;

        const subscription = form.watch(() => {
            if (sendingRef.current) return;
            if (timer.current) window.clearTimeout(timer.current);
            timer.current = window.setTimeout(saveDraft, DRAFT_DELAY);
        });

        return () => {
            subscription.unsubscribe();
            if (timer.current) window.clearTimeout(timer.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [canDraft, projectId]);

    function saveDraft() {
        timer.current = null;
        if (sendingRef.current) return;

        router.put(route('projects.work-check.draft', { project: projectId }), payloadOf(form.getValues(), check, false), {
            ...projectVisit([], { quiet: true }),
            // Never cancels (nor is cancelled by) the Pengawas's "Kirim".
            async: true,
            onStart: () => setDraftState('saving'),
            onSuccess: () => setDraftState('saved'),
            onError: () => setDraftState('error'),
        });
    }

    function send() {
        const values = form.getValues();
        const payload = payloadOf(values, check, true);
        sendingRef.current = true;
        if (timer.current) window.clearTimeout(timer.current);

        router.post(route('projects.weekly-reports.store', { project: projectId }), payload, {
            // A money request to the PM — a step: keeps the pop-up; reloads the Eksekusi side only.
            ...projectVisit(RELOAD.supervision),
            onStart: () => setSending(true),
            onFinish: () => {
                setSending(false);
                sendingRef.current = false;
            },
            onSuccess: () => setConfirming(false),
            onError: (errors) => {
                setConfirming(false);
                Object.entries(errors).forEach(([key, message]) => {
                    const match = key.match(/^progress\.(\d+)\.(percent|note)$/);
                    if (match) {
                        const row = payload.progress[Number(match[1])];
                        if (row) form.setError(`entries.${row.item_id}.${match[2] as 'percent' | 'note'}`, { message });
                    } else {
                        form.setError(key.startsWith('links') ? 'links' : key === 'notes' ? 'notes' : 'root', { message });
                    }
                });
            },
        });
    }

    return (
        <Form {...form}>
            <form onSubmit={form.handleSubmit(() => setConfirming(true))} className="mb-6 space-y-4 rounded-lg bg-daiku-gray/60 p-3 sm:p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm font-semibold text-foreground">
                        Pengecekan % per item · {weekLabel}
                        <span className="ml-2 font-normal text-muted-foreground">
                            {filled > 0 ? `${filled} dari ${check.items.length} item diisi` : 'belum ada yang diisi'}
                        </span>
                    </p>
                    <div className="flex items-center gap-2">
                        {canDraft && <DraftBadge state={draftState} check={check} />}
                        {canDraft && filled > 0 && (
                            <Button type="button" size="sm" variant="ghost" onClick={clearEntries}>
                                <Eraser className="size-4" />
                                Kosongkan isian
                            </Button>
                        )}
                    </div>
                </div>
                {staleDraft && (
                    <Notice tone="warning">
                        Isian ini draf dari {formatDateTime(check.draft!.updated_at!)} yang belum dikirim — periksa dulu, atau tekan{' '}
                        <span className="font-medium">Kosongkan isian</span> lalu isi hanya item yang dikerjakan hari ini.
                    </Notice>
                )}
                {rejectedNote && (
                    <Notice tone="error">Ditolak PM: {rejectedNote} — persen yang sudah dikirim tetap tercatat; perbaiki lalu kirim ulang.</Notice>
                )}

                <ItemsTable check={check} form={form} preview={preview} renderSheet={renderSheet} />

                <Summary check={check} preview={preview} seesMoney={seesMoney} />

                <FormField
                    control={form.control}
                    name="notes"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel required>Catatan pengecekan</FormLabel>
                            <FormControl>
                                <Textarea {...field} rows={3} placeholder="Kualitas, bahan, kendala tim…" />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                <FormField
                    control={form.control}
                    name="links"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>Link foto / dokumen (satu per baris)</FormLabel>
                            <FormControl>
                                <Textarea {...field} rows={2} placeholder="https://drive.google.com/…" />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                {form.formState.errors.root?.message && <p className="text-sm font-medium text-error-ink">{form.formState.errors.root.message}</p>}

                {canSubmit ? (
                    <Button type="submit" className="min-h-11 w-full sm:w-auto" disabled={sending}>
                        {sending && <Loader2 className="size-4 animate-spin" />}
                        Kirim Pengecekan
                        {seesMoney && preview.payableNow !== null && preview.payableNow > 0 ? ` · Ajukan ${formatRupiah(preview.payableNow)}` : ''}
                    </Button>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {blockedReason ?? 'Pengawas proyek yang mengirim pengecekan ini ke PM — isian Anda tersimpan sebagai draf.'}
                    </p>
                )}
            </form>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Kirim pengecekan ${weekLabel.toLowerCase()}?`}
                description={
                    preview.payableNow && preview.payableNow > 0
                        ? `Persen per item dicatat dan upah ${formatRupiah(preview.payableNow)} diajukan ke PM. Persen yang terkirim tidak bisa diubah — koreksi dengan persen minus + catatan.`
                        : 'Persen per item dicatat dan laporan dikirim ke PM. Tidak ada upah baru yang bisa diajukan minggu ini.'
                }
                confirmLabel="Kirim ke PM"
                icon={HandCoins}
                busy={sending}
                onConfirm={send}
            >
                {preview.shares.length > 1 && <SharesList shares={preview.shares} className="w-full text-left" />}
            </ConfirmDialog>
        </Form>
    );
}

function DraftBadge({ state, check }: { state: DraftState; check: WorkCheck }) {
    if (state === 'saving') {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground" aria-live="polite">
                <Loader2 className="size-3 animate-spin" /> Menyimpan draf…
            </span>
        );
    }
    if (state === 'saved') {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-success-ink" aria-live="polite">
                <Check className="size-3" /> Draf tersimpan
            </span>
        );
    }
    if (state === 'error') {
        return <span className="text-xs font-medium text-error-ink">Draf gagal disimpan — periksa isian.</span>;
    }

    return check.draft?.updated_at ? (
        <span className="text-xs text-muted-foreground">
            Draf {formatDateTime(check.draft.updated_at)}
            {check.draft.updated_by && ` · ${check.draft.updated_by}`}
        </span>
    ) : (
        <span className="text-xs text-muted-foreground">Draf tersimpan otomatis</span>
    );
}

function ItemsTable({
    check,
    form,
    preview,
    renderSheet,
}: {
    check: WorkCheck;
    form: UseFormReturn<FormValues>;
    preview: ReturnType<typeof previewWorkCheck>;
    renderSheet?: (input: SheetInput) => ReactNode;
}) {
    return (
        <>
            <ul className="space-y-2 md:hidden">
                {check.items.map((item) => (
                    <li key={item.id} className="rounded-lg border border-border bg-background p-3">
                        <p className="text-sm font-medium text-foreground">{item.description}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {item.volume_label} · {item.team_lead} · sebelumnya {formatPercent(item.progress)}
                        </p>
                        <PercentField item={item} form={form} />
                        <p className="mt-2 text-xs text-muted-foreground">
                            Progres baru <span className="font-semibold text-foreground tabular-nums">{formatPercent(preview.progress[item.id])}</span>
                        </p>
                    </li>
                ))}
            </ul>
            {renderSheet ? (
                <div className="hidden md:block">{renderSheet({ field: (item) => <CompactPercentField item={item} form={form} />, preview })}</div>
            ) : (
            <TableCard className="hidden md:block">
                <table className="w-full text-sm">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            <th className="px-3 py-2 text-left font-semibold">Uraian</th>
                            <th className="px-3 py-2 text-left font-semibold">Kepala Tim</th>
                            <th className="px-3 py-2 text-right font-semibold">Sebelumnya</th>
                            <th className="px-3 py-2 text-left font-semibold">Tambahan %</th>
                            <th className="px-3 py-2 text-right font-semibold">Progres baru</th>
                        </tr>
                    </thead>
                    <tbody>
                        {check.items.map((item) => (
                            <tr key={item.id} className="border-t border-border align-top">
                                <td className="px-3 py-2 text-foreground">
                                    {item.description}
                                    <span className="block text-xs text-muted-foreground">{item.volume_label}</span>
                                </td>
                                <td className="px-3 py-2 text-muted-foreground">{item.team_lead}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{formatPercent(item.progress)}</td>
                                <td className="px-3 py-2">
                                    <PercentField item={item} form={form} />
                                </td>
                                <td className="px-3 py-2 text-right font-medium tabular-nums">{formatPercent(preview.progress[item.id])}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableCard>
            )}
        </>
    );
}

/** Sprint 31 Sub 07 — the today cell of the Lembar Progres: just the % (Enter / Tab to the next item); a minus asks its note below. */
function CompactPercentField({ item, form }: { item: WorkCheck['items'][number]; form: UseFormReturn<FormValues> }) {
    const name = `entries.${item.id}` as const;
    const value = useWatch({ control: form.control, name: `${name}.percent` }) ?? '';
    const done = item.progress >= 100;

    return (
        <div className="space-y-1">
            <FormField
                control={form.control}
                name={`${name}.percent`}
                render={({ field }) => (
                    <div className="relative mx-auto w-20">
                        <Input
                            {...field}
                            inputMode="decimal"
                            placeholder={done ? '✓' : '0'}
                            disabled={done}
                            aria-label={`Tambahan persen ${item.description}`}
                            className="h-10 border-daiku-yellow-dark bg-background pr-6 text-center text-base font-semibold tabular-nums focus-visible:border-foreground"
                        />
                        {!done && <span className="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-xs font-medium text-daiku-muted">%</span>}
                    </div>
                )}
            />
            <FormMessageFor form={form} id={item.id} />
            {parsePercent(value) < 0 && (
                <FormField
                    control={form.control}
                    name={`${name}.note`}
                    render={({ field }) => (
                        <FormItem>
                            <FormControl>
                                <Input {...field} maxLength={500} className="h-8 w-40 text-xs" placeholder="Catatan koreksi *" aria-label="Catatan koreksi" />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
            )}
        </div>
    );
}

/** Tambahan % (0 … sisa; minus = koreksi + catatan) with quick buttons +10 / +20 / +50 / 100 %. */
function PercentField({ item, form }: { item: WorkCheck['items'][number]; form: UseFormReturn<FormValues> }) {
    const name = `entries.${item.id}` as const;
    const value = useWatch({ control: form.control, name: `${name}.percent` }) ?? '';
    const rest = Math.round((100 - item.progress) * 100) / 100;
    const negative = parsePercent(value) < 0;
    const done = rest <= 0;

    function set(next: number) {
        form.setValue(`${name}.percent`, String(Math.min(rest, Math.max(0, Math.round(next * 100) / 100))), { shouldDirty: true, shouldValidate: true });
    }

    return (
        <div className="mt-2 space-y-1.5 md:mt-0">
            <div className="flex flex-wrap items-center gap-1.5">
                <FormField
                    control={form.control}
                    name={`${name}.percent`}
                    render={({ field }) => (
                        <Input
                            {...field}
                            inputMode="decimal"
                            placeholder={done ? 'Selesai' : '0'}
                            aria-label={`Tambahan persen ${item.description}`}
                            className="h-11 w-20 text-right tabular-nums md:h-9"
                        />
                    )}
                />
                {!done &&
                    QUICK.filter((step) => step < rest).map((step) => (
                        <Button
                            key={step}
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-11 px-2.5 md:h-9"
                            onClick={() => set(parsePercent(value) + step)}
                        >
                            +{step}
                        </Button>
                    ))}
                {!done && (
                    <Button type="button" size="sm" variant="outline" className="h-11 px-2.5 md:h-9" onClick={() => set(rest)}>
                        100 %
                    </Button>
                )}
            </div>
            <FormMessageFor form={form} id={item.id} />
            {negative && (
                <FormField
                    control={form.control}
                    name={`${name}.note`}
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel required className="text-xs">
                                Catatan koreksi
                            </FormLabel>
                            <FormControl>
                                <Input {...field} maxLength={500} className="h-9" placeholder="Mis. salah input minggu lalu" />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
            )}
        </div>
    );
}

function FormMessageFor({ form, id }: { form: UseFormReturn<FormValues>; id: number }) {
    const message = form.formState.errors.entries?.[String(id)]?.percent?.message;

    return message ? <p className="text-xs font-medium text-error-ink">{message}</p> : null;
}

function Summary({ check, preview, seesMoney }: { check: WorkCheck; preview: ReturnType<typeof previewWorkCheck>; seesMoney: boolean }) {
    const { summary } = check;

    return (
        <div className="space-y-3 rounded-lg bg-background p-3 ring-1 ring-border sm:p-4">
            <div className={cn('grid gap-3', seesMoney ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-2')}>
                <Figure
                    label="Progres proyek (berbobot)"
                    value={formatPercent(preview.weightedProgress)}
                    hint={`sebelumnya ${formatPercent(summary.weighted_progress)}`}
                />
                <Figure label="Upah boleh cair" value={formatRupiah(preview.payable)} hint={`dari total ${formatRupiah(summary.total_wage)}`} />
                {seesMoney && <Figure label="Sudah cair" value={formatRupiah(summary.already_paid)} hint="DP + pencairan sebelumnya" />}
                {seesMoney && <Figure label="Cair kali ini" value={formatRupiah(preview.payableNow)} strong />}
            </div>
            {preview.teams.length > 0 && (
                <div>
                    <p className="mb-1 text-xs font-medium text-muted-foreground">Per Kepala Tim</p>
                    <ul className="divide-y divide-border text-sm">
                        {preview.teams.map((team) => {
                            const share = preview.shares.find((row) => row.label === team.label);

                            return (
                                <li key={team.label} className="flex flex-wrap items-center justify-between gap-x-3 py-1.5">
                                    <span className="font-medium text-foreground">{team.label}</span>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        progres {formatPercent(team.progress)} · boleh cair {formatRupiah(team.payable)}
                                        {seesMoney && (
                                            <>
                                                {' '}
                                                · kali ini <span className="font-semibold text-foreground">{formatRupiah(share?.amount ?? 0)}</span>
                                            </>
                                        )}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    {seesMoney && preview.teams.length > 1 && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Bagian "kali ini" = upah boleh cair tim dikurangi bagian tim dari yang sudah cair (dibagi menurut total upah tim).
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

function Figure({ label, value, hint, strong }: { label: string; value: string; hint?: string; strong?: boolean }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn('tabular-nums', strong ? 'text-lg font-semibold text-foreground' : 'text-sm font-medium text-foreground')}>{value}</p>
            {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
        </div>
    );
}

/** "Putra Rp 4.551.500 · Yusuf Rp 1.287.000" — a payment's split per Kepala Tim. */
export function SharesList({ shares, className }: { shares: { label: string; amount: number }[]; className?: string }) {
    if (shares.length === 0) return null;

    return (
        <ul className={cn('space-y-0.5 text-xs text-muted-foreground', className)}>
            {shares.map((share) => (
                <li key={share.label} className="flex justify-between gap-3">
                    <span>{share.label}</span>
                    <span className="font-medium text-foreground tabular-nums">{formatRupiah(share.amount)}</span>
                </li>
            ))}
        </ul>
    );
}
