import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { MoneyInput } from '@/Components/shared/MoneyInput';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import type { ProjectExecution } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { CheckCircle2, Circle, Plus, Users, X } from 'lucide-react';
import { useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors SaveTechnicalMeetingRequest.
const schema = z.object({
    meeting_date: z.string().min(1, 'Tanggal TM wajib diisi.'),
    notes: z.string().max(10000, 'Notulen terlalu panjang.').optional(),
    attendees: z
        .array(z.object({ user_id: z.string().optional(), name: z.string().max(120).optional() }))
        .max(50, 'Maksimal 50 peserta.'),
    fee_total: z.string().min(1, 'Nilai jasa pengawas wajib diisi.'),
    dp_percent: z.string().min(1, 'DP % wajib diisi.').refine((value) => Number(value) >= 0 && Number(value) <= 100, 'DP antara 0–100%.'),
    end_date: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

/** A ✓ / ○ checklist line (TM Selesai, syarat DP). */
export function RequirementList({ items }: { items: { key: string; label: string; done: boolean }[] }) {
    return (
        <ul className="space-y-1.5 text-sm">
            {items.map((item) => (
                <li key={item.key} className="flex items-center gap-2">
                    {item.done ? <CheckCircle2 className="size-4 text-success-ink" /> : <Circle className="size-4 text-muted-foreground" />}
                    <span className={item.done ? 'text-foreground' : 'text-muted-foreground'}>{item.label}</span>
                </li>
            ))}
        </ul>
    );
}

/**
 * Sprint 24 D3 — the Technical Meeting: the PM (or Asisten PM) records date,
 * minutes, attendees (an account or a typed name), the Jasa Pengawas and
 * the project's Tanggal Selesai; the Timeline is on its own tab and the
 * materials are the Pengawas's (Material tab). "TM Selesai" once the
 * checklist is complete.
 */
export function TechnicalMeetingCard({ projectId, execution }: { projectId: number; execution: ProjectExecution }) {
    const { meeting, fee, can } = execution;
    const editable = can.saveMeeting;

    if (!editable) {
        if (!meeting) {
            return (
                <SectionCard title="Technical Meeting" icon={Users}>
                    <p className="text-sm text-muted-foreground">
                        {execution.phase === 'GAMBAR_KERJA' ? 'Dimulai setelah gambar kerja selesai.' : 'Belum dicatat · PM.'}
                    </p>
                    {execution.meetingRequirements.length > 0 && (
                        <div className="mt-4">
                            <RequirementList items={execution.meetingRequirements} />
                        </div>
                    )}
                </SectionCard>
            );
        }

        return (
            <SectionCard title="Technical Meeting" icon={Users}>
                <DetailList>
                    <DetailItem label="Tanggal TM">{formatDate(meeting.meeting_date)}</DetailItem>
                    <DetailItem label="Peserta">{meeting.attendees.map((row) => row.label).join(', ') || '—'}</DetailItem>
                    {fee && (
                        <DetailItem label="Jasa Pengawas">
                            {formatRupiah(fee.total_amount)} · DP {fee.dp_percent}% ({formatRupiah(fee.dp_amount)})
                        </DetailItem>
                    )}
                    <DetailItem label="Selesai">{meeting.completed_at ? `${formatDateTime(meeting.completed_at)} · ${meeting.completer ?? ''}` : 'Belum'}</DetailItem>
                </DetailList>
                {meeting.notes && <p className="mt-4 text-sm whitespace-pre-line text-foreground">{meeting.notes}</p>}
                {execution.meetingRequirements.length > 0 && (
                    <div className="mt-4">
                        <RequirementList items={execution.meetingRequirements} />
                    </div>
                )}
            </SectionCard>
        );
    }

    return <TechnicalMeetingForm projectId={projectId} execution={execution} />;
}

function TechnicalMeetingForm({ projectId, execution }: { projectId: number; execution: ProjectExecution }) {
    const { meeting, fee } = execution;
    const [completing, setCompleting] = useState(false);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            meeting_date: meeting?.meeting_date ?? new Date().toISOString().slice(0, 10),
            notes: meeting?.notes ?? '',
            attendees: meeting?.attendees.length
                ? meeting.attendees.map((row) => ({ user_id: row.user_id ? String(row.user_id) : '', name: row.name ?? '' }))
                : [{ user_id: '', name: '' }],
            fee_total: fee ? String(fee.total_amount) : '',
            dp_percent: fee ? String(fee.dp_percent) : '10',
            end_date: execution.defaultEndDate?.slice(0, 10) ?? '',
        },
    });
    const attendees = useFieldArray({ control: form.control, name: 'attendees' });
    const done = execution.meetingRequirements.every((row) => row.done);

    function onSubmit(values: FormValues) {
        router.put(
            route('projects.technical-meeting.save', { project: projectId }),
            {
                ...values,
                fee_total: Number(values.fee_total),
                dp_percent: Number(values.dp_percent),
                end_date: values.end_date || null,
                attendees: values.attendees
                    .filter((row) => row.user_id || row.name?.trim())
                    .map((row) => (row.user_id ? { user_id: Number(row.user_id) } : { name: row.name?.trim() })),
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([key, message]) =>
                        form.setError((key.startsWith('attendees') ? 'attendees' : key) as keyof FormValues, { message }),
                    ),
            },
        );
    }

    function complete() {
        router.post(route('projects.technical-meeting.complete', { project: projectId }), {}, {
            preserveScroll: true,
            onStart: () => setCompleting(true),
            onFinish: () => setCompleting(false),
        });
    }

    return (
        <SectionCard
            title="Technical Meeting"
            description="Isi notulen, peserta, jasa pengawas & tanggal selesai. Timeline diisi di tab Timeline, bahan oleh Pengawas di tab Material."
            icon={Users}
        >
            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            control={form.control}
                            name="meeting_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Tanggal TM</FormLabel>
                                    <FormControl>
                                        <Input type="date" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="end_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Tanggal Selesai Proyek</FormLabel>
                                    <FormControl>
                                        <Input type="date" {...field} />
                                    </FormControl>
                                    <p className="text-xs text-muted-foreground">Wajib sebelum TM Selesai — dasar pengingat H-30%.</p>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                    </div>

                    <FormField
                        control={form.control}
                        name="notes"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>Notulen</FormLabel>
                                <FormControl>
                                    <Textarea {...field} rows={5} placeholder="Hasil rapat: urutan kerja, bahan, catatan tiap divisi…" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />

                    <div className="space-y-2">
                        <FormLabel>Peserta</FormLabel>
                        {attendees.fields.map((row, index) => (
                            <div key={row.id} className="flex flex-col gap-2 sm:flex-row">
                                <FormField
                                    control={form.control}
                                    name={`attendees.${index}.user_id`}
                                    render={({ field }) => (
                                        <Select value={field.value || 'none'} onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}>
                                            <SelectTrigger className="sm:w-56" aria-label="Pilih peserta">
                                                <SelectValue placeholder="Pilih akun" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">— Ketik nama —</SelectItem>
                                                {execution.attendeeOptions.map((user) => (
                                                    <SelectItem key={user.id} value={String(user.id)}>
                                                        {user.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                />
                                {!form.watch(`attendees.${index}.user_id`) && (
                                    <FormField
                                        control={form.control}
                                        name={`attendees.${index}.name`}
                                        render={({ field }) => <Input {...field} placeholder="Nama (orang luar / vendor)" maxLength={120} className="flex-1" />}
                                    />
                                )}
                                <Button type="button" variant="ghost" size="icon" aria-label="Hapus peserta" onClick={() => attendees.remove(index)}>
                                    <X className="size-4" />
                                </Button>
                            </div>
                        ))}
                        <Button type="button" variant="outline" size="sm" onClick={() => attendees.append({ user_id: '', name: '' })}>
                            <Plus className="size-4" />
                            Tambah peserta
                        </Button>
                        {form.formState.errors.attendees?.message && <p className="text-sm text-error-ink">{form.formState.errors.attendees.message}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            control={form.control}
                            name="fee_total"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Nilai Jasa Pengawas</FormLabel>
                                    <FormControl>
                                        <MoneyInput value={field.value} onChange={field.onChange} placeholder="0" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="dp_percent"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>DP Pengawas (%)</FormLabel>
                                    <FormControl>
                                        <Input type="number" min={0} max={100} step="0.5" inputMode="decimal" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                    </div>

                    <div className="flex flex-wrap justify-end gap-2">
                        <Button type="submit" variant="outline" disabled={form.formState.isSubmitting}>
                            Simpan TM
                        </Button>
                    </div>
                </form>
            </Form>

            <div className="mt-6 rounded-lg bg-daiku-gray/60 p-4">
                <p className="mb-3 text-sm font-medium text-foreground">Syarat TM Selesai</p>
                <RequirementList items={execution.meetingRequirements} />
                <Button className="mt-4 w-full sm:w-auto" disabled={!done || completing} onClick={complete}>
                    TM Selesai
                </Button>
                {!done && <p className="mt-2 text-xs text-muted-foreground">Lengkapi semua syarat di atas (simpan TM dulu).</p>}
            </div>
        </SectionCard>
    );
}
