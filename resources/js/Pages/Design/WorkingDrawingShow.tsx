import { AssignDesignDialog } from '@/Components/modules/design/AssignDesignDialog';
import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime } from '@/lib/format';
import type { WorkingDrawing } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, DraftingCompass, ExternalLink, Info, Link2, UserCog } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface WorkingDrawingShowProps {
    drawing: WorkingDrawing;
    canAssign: boolean;
    canWork: boolean;
    architects: { id: number; name: string }[];
}

// Mirrors UpdateWorkingDrawingLinksRequest (links one per line).
const schema = z.object({
    drawing_urls: z
        .string()
        .refine(
            (value) =>
                value
                    .split('\n')
                    .map((line) => line.trim())
                    .filter(Boolean)
                    .every((line) => /^https?:\/\/\S+$/i.test(line)),
            'Setiap link harus berupa URL yang valid (http/https).',
        ),
    note: z.string().max(2000, 'Catatan maksimal 2000 karakter.').optional(),
});

function stepOf(drawing: WorkingDrawing): string | undefined {
    switch (drawing.status) {
        case 'MENUNGGU_PENUGASAN':
            return 'Tugaskan arsitek gambar kerja ini · Kepala Desain.';
        case 'DIKERJAKAN':
            return 'Simpan link gambar kerja lalu tekan "Gambar Kerja Selesai" · Arsitek.';
        default:
            return undefined;
    }
}

/**
 * Sprint 24 D2 — one project's Gambar Kerja: assigned like a design, links
 * saved by the team, final at "Gambar Kerja Selesai" (no client approval)
 * — the project then moves on to the Technical Meeting.
 */
export default function WorkingDrawingShow({ drawing, canAssign, canWork, architects }: WorkingDrawingShowProps) {
    const [assigning, setAssigning] = useState(false);
    const [completing, setCompleting] = useState(false);
    const [confirmingComplete, setConfirmingComplete] = useState(false);
    const working = drawing.status === 'DIKERJAKAN';
    const urls = drawing.drawing_urls ?? [];
    const form = useForm<z.infer<typeof schema>>({
        resolver: zodResolver(schema),
        defaultValues: { drawing_urls: urls.join('\n'), note: drawing.note ?? '' },
    });

    function onSubmit(values: z.infer<typeof schema>) {
        router.put(
            route('design.working-drawings.links.update', { workingDrawing: drawing.id }),
            {
                drawing_urls: values.drawing_urls
                    .split('\n')
                    .map((line) => line.trim())
                    .filter(Boolean),
                note: values.note || null,
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([key, message]) => form.setError(key.startsWith('drawing_urls') ? 'drawing_urls' : 'note', { message })),
            },
        );
    }

    function complete() {
        router.post(route('design.working-drawings.complete', { workingDrawing: drawing.id }), {}, {
            onStart: () => setCompleting(true),
            onSuccess: () => setConfirmingComplete(false),
            onFinish: () => setCompleting(false),
        });
    }

    return (
        <AppLayout breadcrumbs={[{ label: drawing.project?.name ?? `Gambar Kerja #${drawing.id}` }]}>
            <Head title={`Gambar Kerja · ${drawing.project?.name ?? ''}`} />
            <PageHeader
                title={`Gambar Kerja · ${drawing.project?.name ?? ''}`}
                icon={DraftingCompass}
                description={drawing.project?.lead?.client_name ? `Klien: ${drawing.project.lead.client_name}` : undefined}
                step={stepOf(drawing)}
                actions={
                    <>
                        <StatusChip domain="workingDrawing" status={drawing.status} />
                        {canAssign && (
                            <Button variant="outline" onClick={() => setAssigning(true)}>
                                <UserCog className="size-4" />
                                {drawing.pic_id ? 'Ubah Penugasan' : 'Tugaskan Arsitek'}
                            </Button>
                        )}
                        {canWork && working && (
                            <Button disabled={urls.length === 0 || completing} onClick={() => setConfirmingComplete(true)} title={urls.length === 0 ? 'Simpan minimal satu link dulu' : undefined}>
                                <CheckCircle2 className="size-4" />
                                Gambar Kerja Selesai
                            </Button>
                        )}
                    </>
                }
            />

            <ConfirmDialog
                open={confirmingComplete}
                onOpenChange={setConfirmingComplete}
                busy={completing}
                onConfirm={complete}
                icon={CheckCircle2}
                title="Gambar kerja selesai?"
                description="Hasilnya final (tanpa ACC klien) dan proyek lanjut ke tahap Technical Meeting. PM, Asisten PM, dan Pengawas (QA/QC) diberi tahu untuk menjadwalkan TM."
                confirmLabel="Ya, selesai"
            />

            <div className="grid gap-6 lg:grid-cols-2">
                <SectionCard title="Informasi" icon={Info}>
                    <DetailList>
                        <DetailItem label="Proyek">
                            {drawing.project ? (
                                <Link href={route('projects.show', { project: drawing.project.id, tab: 'execution' })} className="font-medium hover:underline">
                                    {drawing.project.name}
                                </Link>
                            ) : (
                                '—'
                            )}
                        </DetailItem>
                        <DetailItem label="Project Manager">{drawing.project?.pm?.name ?? '—'}</DetailItem>
                        <DetailItem label="Pengawas (QA/QC)">{drawing.project?.supervisors?.map((person) => person.name).join(', ') || '—'}</DetailItem>
                        <DetailItem label="PIC Arsitek">{drawing.pic?.name ?? 'Belum ditugaskan'}</DetailItem>
                        <DetailItem label="Asisten">{drawing.staff?.map((member) => member.name).join(', ') || '—'}</DetailItem>
                        <DetailItem label="Mulai · Deadline">
                            {formatDate(drawing.start_date)} · {formatDate(drawing.deadline)}
                        </DetailItem>
                        {drawing.completed_at && (
                            <DetailItem label="Selesai">
                                {formatDateTime(drawing.completed_at)} · {drawing.completer?.name}
                            </DetailItem>
                        )}
                    </DetailList>
                </SectionCard>

                <SectionCard
                    title="Link Gambar Kerja"
                    icon={Link2}
                    description={drawing.links_updated_at ? `Terakhir disimpan oleh ${drawing.links_updater?.name ?? '—'}, ${formatDateTime(drawing.links_updated_at)}` : undefined}
                >
                    {canWork && working ? (
                        <Form {...form}>
                            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                                <FormField
                                    control={form.control}
                                    name="drawing_urls"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Link (satu per baris)</FormLabel>
                                            <FormControl>
                                                <Textarea {...field} rows={4} placeholder="https://drive.google.com/…" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="note"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Catatan</FormLabel>
                                            <FormControl>
                                                <Textarea {...field} rows={2} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <Button type="submit" variant="outline" disabled={form.formState.isSubmitting}>
                                    Simpan Link
                                </Button>
                            </form>
                        </Form>
                    ) : urls.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada link.</p>
                    ) : (
                        <div className="space-y-2 text-sm">
                            {urls.map((url) => (
                                <a key={url} href={url} target="_blank" rel="noreferrer" className="flex items-center gap-1 truncate text-info-ink underline">
                                    <ExternalLink className="size-3.5 shrink-0" />
                                    {url}
                                </a>
                            ))}
                            {drawing.note && <p className="text-muted-foreground">{drawing.note}</p>}
                        </div>
                    )}
                </SectionCard>
            </div>

            {canAssign && (
                <AssignDesignDialog
                    open={assigning}
                    onOpenChange={setAssigning}
                    design={{
                        id: drawing.id,
                        client_name: drawing.project?.name ?? '',
                        pic_id: drawing.pic_id,
                        staff: (drawing.staff ?? []).map((member) => ({ ...member, pivot: { role_note: null } })),
                        start_date: drawing.start_date,
                        target_hari: drawing.target_hari,
                    }}
                    architects={architects}
                    submitUrl={route('design.working-drawings.assign', { workingDrawing: drawing.id })}
                    subject="Gambar Kerja"
                />
            )}
        </AppLayout>
    );
}
