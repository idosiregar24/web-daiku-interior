import { shrinkPhoto } from '@/Components/modules/finance/PaymentProofPhotos';
import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatProgress } from '@/lib/progress';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import type { ItemQaOverview, User, WorkItemQaStatus } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Camera, CircleCheck, ExternalLink, Image as ImageIcon, Loader2, ShieldCheck, Wrench, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { RepairTaskDialog } from './SiteFindingsCard';

type Item = ItemQaOverview['items'][number];

/** Mirrors App\Models\WorkItemQaCheck (DecideWorkItemQaRequest). */
const MAX_PHOTO_BYTES = 8 * 1024 * 1024;

/** Work first: waiting, then to repair, then not yet 100 %, passed last. */
const STATUS_ORDER: Record<WorkItemQaStatus, number> = { MENUNGGU: 0, PERBAIKAN: 1, BELUM: 2, LOLOS: 3 };

// Mirrors DecideWorkItemQaRequest (note required for PERBAIKAN).
const decisionSchema = z
    .object({
        decision: z.enum(['LOLOS', 'PERBAIKAN']),
        note: z.string().max(1000, 'Catatan maksimal 1000 karakter.'),
        photo_url: z.union([z.literal(''), z.string().url('Link foto harus URL yang valid.').max(500)]),
    })
    .superRefine((values, context) => {
        if (values.decision === 'PERBAIKAN' && values.note.trim() === '') {
            context.addIssue({ code: 'custom', path: ['note'], message: 'Tulis apa yang perlu diperbaiki.' });
        }
    });

/**
 * Sprint 29 Sub 12 (G1) — Timeline & QC › "QC Item": QA per item RAB. An
 * item at 100 % in the pengecekan waits for the Pengawas: Lolos (several at
 * once) or Perlu perbaikan — a finding that can become a repair task; once
 * it closes the item waits for QA again. Every item with a wage passed (and
 * no open finding) = the project completes. Built from the `itemQa` prop only.
 */
export function WorkItemQaPanel({
    projectId,
    itemQa,
    fieldStaff,
}: {
    projectId: number;
    itemQa: ItemQaOverview;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    const { items, summary, can } = itemQa;
    const [selected, setSelected] = useState<number[]>([]);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [passing, setPassing] = useState(false);
    const [checking, setChecking] = useState<Item | null>(null);
    const [repairing, setRepairing] = useState<{ id: number; description: string } | null>(null);
    const [resolving, setResolving] = useState<number | null>(null);

    const sorted = useMemo(() => [...items].sort((a, b) => STATUS_ORDER[a.status] - STATUS_ORDER[b.status]), [items]);
    const waiting = useMemo(() => items.filter((item) => item.status === 'MENUNGGU'), [items]);
    // A reload may drop items that are no longer waiting.
    const picked = selected.filter((id) => waiting.some((item) => item.id === id));
    const allPicked = waiting.length > 0 && picked.length === waiting.length;

    if (items.length === 0) {
        return (
            <EmptyState
                icon={ShieldCheck}
                className="rounded-xl border border-dashed border-border"
                title="Belum ada item berupah."
                description="QA per item RAB berjalan setelah Pengawas mengisi Satuan Upah tiap item."
            />
        );
    }

    function toggle(id: number, checked: boolean) {
        setSelected((current) => (checked ? [...current, id] : current.filter((value) => value !== id)));
    }

    function passSelected() {
        router.post(
            route('projects.item-qa.decide', { project: projectId }),
            { decision: 'LOLOS', item_ids: picked },
            {
                // A decision — keeps the success pop-up, reloads only the QC side.
                ...projectVisit(RELOAD.itemQa),
                onStart: () => setPassing(true),
                onFinish: () => {
                    setPassing(false);
                    setConfirmOpen(false);
                },
                onSuccess: () => setSelected([]),
            },
        );
    }

    function resolveFinding(findingId: number) {
        router.post(route('site-findings.resolve', { siteFinding: findingId }), {}, {
            ...projectVisit(RELOAD.itemQa),
            onStart: () => setResolving(findingId),
            onFinish: () => setResolving(null),
        });
    }

    function actionsOf(item: Item, block = false) {
        const finding = item.latest?.finding;
        const openFinding = item.status === 'PERBAIKAN' && finding?.status === 'TERBUKA' ? finding : null;
        const buttonClass = block ? 'w-full sm:w-auto' : undefined;

        return (
            <div className={cn('flex flex-wrap gap-2', !block && 'justify-end')}>
                {can.decide && item.status === 'MENUNGGU' && (
                    <Button size="sm" className={buttonClass} onClick={() => setChecking(item)}>
                        <ShieldCheck className="size-4" />
                        Periksa
                    </Button>
                )}
                {can.decide && item.status === 'LOLOS' && (
                    <Button size="sm" variant="ghost" className={buttonClass} onClick={() => setChecking(item)}>
                        Perlu Perbaikan
                    </Button>
                )}
                {openFinding && can.repair && !openFinding.task && (
                    <Button size="sm" variant="outline" className={buttonClass} onClick={() => setRepairing({ id: openFinding.id, description: openFinding.description })}>
                        <Wrench className="size-4" />
                        Jadikan Task Perbaikan
                    </Button>
                )}
                {openFinding && can.resolve && (
                    <Button size="sm" variant="ghost" className={buttonClass} disabled={resolving !== null} onClick={() => resolveFinding(openFinding.id)}>
                        {resolving === openFinding.id && <Loader2 className="size-4 animate-spin" />}
                        Sudah Diperbaiki
                    </Button>
                )}
            </div>
        );
    }

    const selectable = (item: Item) => can.decide && item.status === 'MENUNGGU';

    return (
        <div className="space-y-4">
            <div className="rounded-xl border border-border p-4">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-medium text-foreground">
                        {summary.lolos} dari {summary.total} item lolos QA
                    </span>
                    <span className="text-daiku-muted">·</span>
                    <StatusChip domain="itemQa" status="MENUNGGU" waitingOn={null} label={`Menunggu ${summary.menunggu}`} />
                    <StatusChip domain="itemQa" status="PERBAIKAN" waitingOn={null} label={`Perbaikan ${summary.perbaikan}`} />
                    <StatusChip domain="itemQa" status="BELUM" waitingOn={null} label={`Belum 100 % ${summary.belum}`} />
                </div>
                <ProgressBar className="mt-3" value={summary.total > 0 ? (summary.lolos / summary.total) * 100 : 0} tone="success" label="Item lolos QA" />
                <p className="mt-2 text-xs text-daiku-muted">
                    Item yang mencapai 100 % di pengecekan menunggu QA Pengawas. Proyek selesai saat semua item lolos QA dan tidak ada temuan terbuka.
                </p>
            </div>

            {can.decide && waiting.length > 0 && (
                <Notice tone="info">
                    {waiting.length} item menunggu QA — centang beberapa lalu <strong>Lolos QA</strong>, atau <strong>Periksa</strong> satu per satu untuk
                    catatan, foto, atau perlu perbaikan.
                </Notice>
            )}

            <TableCard
                toolbar={
                    can.decide && waiting.length > 0 ? (
                        <div className="flex flex-wrap items-center gap-3">
                            <Label className="flex items-center gap-2 text-sm font-normal">
                                <Checkbox
                                    checked={allPicked}
                                    onCheckedChange={(checked) => setSelected(checked ? waiting.map((item) => item.id) : [])}
                                    aria-label="Pilih semua yang menunggu QA"
                                />
                                Pilih semua yang menunggu ({waiting.length})
                            </Label>
                            <Button size="sm" className="w-full sm:ml-auto sm:w-auto" disabled={picked.length === 0 || passing} onClick={() => setConfirmOpen(true)}>
                                <CircleCheck className="size-4" />
                                Lolos QA{picked.length > 0 ? ` (${picked.length})` : ''}
                            </Button>
                        </div>
                    ) : undefined
                }
            >
                {/* Phone: one card per item (Sprint 13 pattern). */}
                <ul className="divide-y divide-border md:hidden">
                    {sorted.map((item) => (
                        <li key={item.id} className="space-y-2 px-4 py-3">
                            <div className="flex items-start gap-3">
                                {selectable(item) && (
                                    <Checkbox
                                        className="mt-1 size-5"
                                        checked={picked.includes(item.id)}
                                        onCheckedChange={(checked) => toggle(item.id, checked === true)}
                                        aria-label={`Pilih ${item.description}`}
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-foreground">{item.description}</p>
                                    <p className="text-xs text-daiku-muted">
                                        {item.volume_label} · {item.team_lead} · progres {formatProgress(item.progress)}
                                    </p>
                                </div>
                                <StatusChip domain="itemQa" status={item.status} />
                            </div>
                            <LatestCheck item={item} />
                            {actionsOf(item, true)}
                        </li>
                    ))}
                </ul>

                <table className="hidden w-full text-sm md:table">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            {can.decide && <th className="w-10 px-4 py-2.5" aria-label="Pilih" />}
                            <th className="px-4 py-2.5 text-left font-semibold">Item RAB</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Kepala Tim</th>
                            <th className="px-4 py-2.5 text-right font-semibold">Progres</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Status QA</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Pemeriksaan Terakhir</th>
                            <th className="px-4 py-2.5" aria-label="Aksi" />
                        </tr>
                    </thead>
                    <tbody>
                        {sorted.map((item) => (
                            <tr key={item.id} className="border-t border-border align-top">
                                {can.decide && (
                                    <td className="px-4 py-3">
                                        {selectable(item) && (
                                            <Checkbox
                                                checked={picked.includes(item.id)}
                                                onCheckedChange={(checked) => toggle(item.id, checked === true)}
                                                aria-label={`Pilih ${item.description}`}
                                            />
                                        )}
                                    </td>
                                )}
                                <td className="px-4 py-3">
                                    <p className="font-medium text-foreground">{item.description}</p>
                                    <p className="text-xs text-daiku-muted">{item.volume_label}</p>
                                </td>
                                <td className="px-4 py-3 text-daiku-muted">{item.team_lead}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatProgress(item.progress)}</td>
                                <td className="px-4 py-3">
                                    <StatusChip domain="itemQa" status={item.status} />
                                </td>
                                <td className="max-w-80 px-4 py-3">
                                    <LatestCheck item={item} />
                                </td>
                                <td className="px-4 py-3">{actionsOf(item)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableCard>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                busy={passing}
                icon={CircleCheck}
                title={`Loloskan QA ${picked.length} item?`}
                description="Item yang lolos dianggap selesai. Bila ternyata ada yang salah, tandai Perlu Perbaikan dari barisnya."
                confirmLabel="Ya, lolos QA"
                onConfirm={passSelected}
            >
                <ul className="max-h-40 w-full overflow-y-auto text-left text-sm text-daiku-muted">
                    {waiting
                        .filter((item) => picked.includes(item.id))
                        .map((item) => (
                            <li key={item.id}>• {item.description}</li>
                        ))}
                </ul>
            </ConfirmDialog>

            <ItemCheckDialog projectId={projectId} item={checking} onOpenChange={(open) => !open && setChecking(null)} />
            <RepairTaskDialog finding={repairing} fieldStaff={fieldStaff} reload={RELOAD.itemQa} onOpenChange={(open) => !open && setRepairing(null)} />
        </div>
    );
}

/** The newest decision of an item: note, who/when, evidence, and its repair finding. */
function LatestCheck({ item }: { item: Item }) {
    const latest = item.latest;

    if (!latest) {
        return <span className="text-xs text-daiku-muted">{item.status === 'MENUNGGU' ? 'Belum diperiksa' : '—'}</span>;
    }

    const finding = latest.finding;

    return (
        <div className="space-y-1 text-xs">
            {latest.note && <p className="text-sm text-foreground">{latest.note}</p>}
            <p className="text-daiku-muted">
                {latest.decision === 'LOLOS' ? 'Lolos' : 'Perlu perbaikan'} · {latest.checker ?? '—'} · {formatDateTime(latest.at)}
                {item.checks > 1 && ` · pemeriksaan ke-${item.checks}`}
            </p>
            {(latest.photo_href || latest.photo_url) && (
                <p className="flex flex-wrap gap-3">
                    {latest.photo_href && (
                        <a href={latest.photo_href} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-info-ink underline">
                            <ImageIcon className="size-3" />
                            Foto
                        </a>
                    )}
                    {latest.photo_url && (
                        <a href={latest.photo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-info-ink underline">
                            <ExternalLink className="size-3" />
                            Link
                        </a>
                    )}
                </p>
            )}
            {finding && latest.decision === 'PERBAIKAN' && (
                <p className="text-daiku-muted">
                    Temuan {finding.status === 'TERBUKA' ? 'terbuka' : 'selesai — menunggu QA ulang'}
                    {finding.task && ` · Perbaikan: ${finding.task.assignee ?? '—'}, ${formatDate(finding.task.due_date)}`}
                </p>
            )}
        </div>
    );
}

/** "Periksa" one item: Lolos / Perlu perbaikan + note, link and a photo from the phone camera. */
function ItemCheckDialog({ projectId, item, onOpenChange }: { projectId: number; item: Item | null; onOpenChange: (open: boolean) => void }) {
    const [saving, setSaving] = useState(false);
    const [photo, setPhoto] = useState<File | null>(null);
    const [photoError, setPhotoError] = useState<string | undefined>();
    const fileRef = useRef<HTMLInputElement>(null);
    // A passed item can only be sent back to repair.
    const repairOnly = item?.status === 'LOLOS';
    const form = useForm<z.infer<typeof decisionSchema>>({
        resolver: zodResolver(decisionSchema),
        defaultValues: { decision: 'LOLOS', note: '', photo_url: '' },
    });
    const decision = form.watch('decision');

    useEffect(() => {
        if (item) {
            form.reset({ decision: item.status === 'LOLOS' ? 'PERBAIKAN' : 'LOLOS', note: '', photo_url: '' });
            setPhoto(null);
            setPhotoError(undefined);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item]);

    async function pickPhoto(files: FileList | null) {
        const file = files?.[0];
        if (fileRef.current) fileRef.current.value = '';
        if (!file) return;

        const shrunk = await shrinkPhoto(file, 'qa-item.jpg');
        if (shrunk.size > MAX_PHOTO_BYTES) {
            setPhotoError('Ukuran foto maksimal 8 MB.');
            return;
        }

        setPhotoError(undefined);
        setPhoto(shrunk);
    }

    function onSubmit(values: z.infer<typeof decisionSchema>) {
        if (!item) return;

        router.post(
            route('projects.item-qa.decide', { project: projectId }),
            { decision: values.decision, item_ids: [item.id], note: values.note || null, photo_url: values.photo_url || null, ...(photo ? { photo } : {}) },
            {
                ...projectVisit(RELOAD.itemQa),
                // A file makes Inertia send multipart/form-data.
                forceFormData: photo !== null,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => {
                        if (field === 'photo') {
                            setPhotoError(message);
                        } else if (field === 'note' || field === 'photo_url') {
                            form.setError(field, { message });
                        } else {
                            form.setError('decision', { message });
                        }
                    }),
            },
        );
    }

    return (
        <Dialog open={item !== null} onOpenChange={(open) => !saving && onOpenChange(open)}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{repairOnly ? 'Tandai Perlu Perbaikan' : 'Periksa Item'}</DialogTitle>
                    <DialogDescription>
                        {item?.description} · {item?.volume_label} · {item?.team_lead}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {!repairOnly && (
                            <FormField
                                control={form.control}
                                name="decision"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Hasil QA</FormLabel>
                                        <div className="grid grid-cols-2 gap-2">
                                            {(['LOLOS', 'PERBAIKAN'] as const).map((value) => (
                                                <Button
                                                    key={value}
                                                    type="button"
                                                    variant={field.value === value ? (value === 'LOLOS' ? 'default' : 'destructive') : 'outline'}
                                                    className="h-12"
                                                    aria-pressed={field.value === value}
                                                    onClick={() => field.onChange(value)}
                                                >
                                                    {value === 'LOLOS' ? <CircleCheck className="size-4" /> : <Wrench className="size-4" />}
                                                    {value === 'LOLOS' ? 'Lolos' : 'Perlu Perbaikan'}
                                                </Button>
                                            ))}
                                        </div>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required={decision === 'PERBAIKAN'}>{decision === 'PERBAIKAN' ? 'Yang perlu diperbaiki' : 'Catatan'}</FormLabel>
                                    <FormControl>
                                        <Textarea
                                            {...field}
                                            rows={3}
                                            placeholder={decision === 'PERBAIKAN' ? 'Mis. engsel pintu atas miring, HPL sisi kiri terkelupas' : 'Mis. rapi, sesuai gambar kerja'}
                                        />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="space-y-2">
                            <Label>Foto</Label>
                            {photo ? (
                                <div className="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm">
                                    <ImageIcon className="size-4 text-daiku-muted" />
                                    <span className="min-w-0 flex-1 truncate">{photo.name}</span>
                                    <Button type="button" size="icon" variant="ghost" className="size-8" aria-label="Hapus foto" onClick={() => setPhoto(null)}>
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ) : (
                                <Button type="button" variant="outline" className="h-12 w-full" onClick={() => fileRef.current?.click()}>
                                    <Camera className="size-4" />
                                    Ambil / Pilih Foto
                                </Button>
                            )}
                            {/* `capture` opens the back camera on a phone; a laptop gets the file picker. */}
                            <input
                                ref={fileRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                className="hidden"
                                onChange={(event) => void pickPhoto(event.target.files)}
                            />
                            {photoError && <p className="text-sm text-destructive">{photoError}</p>}
                        </div>
                        <FormField
                            control={form.control}
                            name="photo_url"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Link foto / video</FormLabel>
                                    <FormControl>
                                        <Input {...field} inputMode="url" placeholder="https://…" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        {decision === 'PERBAIKAN' && (
                            <Notice tone="warning">Temuan dicatat dan bisa dijadikan task perbaikan. Setelah temuan selesai, item kembali menunggu QA.</Notice>
                        )}
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline" disabled={saving}>
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={saving} variant={decision === 'PERBAIKAN' ? 'destructive' : 'default'}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                {decision === 'PERBAIKAN' ? 'Simpan Perlu Perbaikan' : 'Lolos QA'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
