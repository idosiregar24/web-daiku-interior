import { SurveyFormFields, type SurveyFormValues, toDateTimeLocal } from '@/Components/modules/crm/SurveyFormFields';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Lead, LeadFollowUp, LeadSurvey } from '@/types';
import { router } from '@inertiajs/react';
import { startOfToday } from 'date-fns';
import { CalendarCheck, MapPin, PhoneCall, Plus } from 'lucide-react';
import { useState } from 'react';

type NoteAction =
    | { kind: 'complete-follow-up'; followUp: LeadFollowUp }
    | { kind: 'complete-survey'; survey: LeadSurvey }
    | { kind: 'cancel-survey'; survey: LeadSurvey };

interface LeadTimelineProps {
    lead: Pick<Lead, 'id' | 'status' | 'address'> & { follow_ups: LeadFollowUp[]; surveys: LeadSurvey[] };
    canManage: boolean;
    /** LeadFollowUp::SUGGEST_LOST_FROM — from this FU number on, suggest marking the lead Lost. */
    suggestLostFrom: number;
}

/**
 * Sprint 12 decisions #2–#3 on the lead detail: follow-ups FU-1, FU-2, …
 * (schedule, mark done with a result note) and site surveys (schedule,
 * reschedule, finish, cancel with a reason). An outside-Pekanbaru survey
 * waits for its payment to be verified — it can't be finished by hand.
 */
export function LeadTimeline({ lead, canManage, suggestLostFrom }: LeadTimelineProps) {
    const isClosed = lead.status === 'LOST' || lead.status === 'CLOSING';
    const canWrite = canManage && !isClosed;
    const [followUpOpen, setFollowUpOpen] = useState(false);
    const [followUpDate, setFollowUpDate] = useState('');
    const [surveyDialog, setSurveyDialog] = useState<{ survey: LeadSurvey | null } | null>(null);
    const [surveyValues, setSurveyValues] = useState<SurveyFormValues>({ scheduled_at: '', address: '', maps_url: '', is_outside_pekanbaru: false });
    const [noteAction, setNoteAction] = useState<NoteAction | null>(null);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const nextSequence = (lead.follow_ups.at(-1)?.sequence ?? 0) + 1;
    const suggestLost = !isClosed && nextSequence >= suggestLostFrom;
    const today = startOfToday();

    const visit = (method: 'post' | 'put', url: string, data: Record<string, unknown>, onDone: () => void) =>
        router[method](url, data as never, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (serverErrors) => setErrors(serverErrors),
            onSuccess: () => {
                setErrors({});
                onDone();
            },
        });

    function openSurvey(survey: LeadSurvey | null) {
        setErrors({});
        setSurveyValues(
            survey
                ? { scheduled_at: toDateTimeLocal(survey.scheduled_at), address: survey.address ?? '', maps_url: survey.maps_url ?? '', is_outside_pekanbaru: survey.is_outside_pekanbaru }
                : { scheduled_at: '', address: '', maps_url: '', is_outside_pekanbaru: false },
        );
        setSurveyDialog({ survey });
    }

    function submitSurvey() {
        const survey = surveyDialog?.survey;
        const payload = {
            scheduled_at: surveyValues.scheduled_at,
            address: surveyValues.address || null,
            maps_url: surveyValues.maps_url || null,
            ...(survey ? {} : { is_outside_pekanbaru: surveyValues.is_outside_pekanbaru }),
        };

        if (survey) {
            visit('put', route('crm.surveys.update', { survey: survey.id }), payload, () => setSurveyDialog(null));
        } else {
            visit('post', route('crm.surveys.store', { lead: lead.id }), payload, () => setSurveyDialog(null));
        }
    }

    function submitNote() {
        if (!noteAction) return;

        const done = () => setNoteAction(null);

        if (noteAction.kind === 'complete-follow-up') {
            visit('post', route('crm.follow-ups.complete', { follow_up: noteAction.followUp.id }), { result_note: note }, done);
        } else if (noteAction.kind === 'complete-survey') {
            visit('post', route('crm.surveys.complete', { survey: noteAction.survey.id }), { result_note: note }, done);
        } else {
            visit('post', route('crm.surveys.cancel', { survey: noteAction.survey.id }), { reason: note }, done);
        }
    }

    const noteCopy: Record<NoteAction['kind'], { title: string; label: string; submit: string }> = {
        'complete-follow-up': { title: 'Tandai Follow-up Selesai', label: 'Hasil follow-up', submit: 'Simpan Hasil' },
        'complete-survey': { title: 'Tandai Survey Selesai', label: 'Hasil survey', submit: 'Simpan Hasil' },
        'cancel-survey': { title: 'Batalkan Survey', label: 'Alasan pembatalan', submit: 'Batalkan Survey' },
    };

    return (
        <div className="mt-6 grid gap-6 lg:grid-cols-2">
            <SectionCard
                title="Follow-up"
                icon={PhoneCall}
                description="FU-1, FU-2, … — jadwal dan hasil tiap follow-up."
                action={
                    canWrite && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                setFollowUpDate('');
                                setErrors({});
                                setFollowUpOpen(true);
                            }}
                        >
                            <Plus className="size-4" />
                            Tambah Follow-up
                        </Button>
                    )
                }
            >
                {suggestLost && (
                    <Notice tone="warning" className="mb-4">
                        Sudah {nextSequence - 1} kali follow-up — pertimbangkan tandai lead ini Lost.
                    </Notice>
                )}
                {lead.follow_ups.length === 0 ? (
                    <EmptyState title="Belum ada follow-up." />
                ) : (
                    <ol className="divide-y divide-border">
                        {lead.follow_ups.map((followUp) => {
                            const overdue = !followUp.done_at && new Date(followUp.scheduled_date) < today;

                            return (
                                <li key={followUp.id} className="flex items-start justify-between gap-3 py-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-daiku-dark">
                                            FU-{followUp.sequence}{' '}
                                            <span className={cn('font-normal', overdue ? 'text-error-ink' : 'text-daiku-muted')}>
                                                · {formatDate(followUp.scheduled_date)}
                                                {overdue && ' (terlewat)'}
                                            </span>
                                        </p>
                                        {followUp.done_at ? (
                                            <p className="text-sm whitespace-pre-line text-foreground">{followUp.result_note}</p>
                                        ) : (
                                            <p className="text-xs text-daiku-muted">Belum dilakukan</p>
                                        )}
                                    </div>
                                    {followUp.done_at ? (
                                        <StatusChip status="SELESAI" />
                                    ) : (
                                        canWrite && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setNote('');
                                                    setErrors({});
                                                    setNoteAction({ kind: 'complete-follow-up', followUp });
                                                }}
                                            >
                                                Tandai Selesai
                                            </Button>
                                        )
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </SectionCard>

            <SectionCard
                title="Survey"
                icon={MapPin}
                description="Survey lokasi — bisa lebih dari sekali."
                action={
                    canWrite && (
                        <Button size="sm" variant="outline" onClick={() => openSurvey(null)}>
                            <CalendarCheck className="size-4" />
                            Jadwalkan Survey
                        </Button>
                    )
                }
            >
                {lead.surveys.length === 0 ? (
                    <EmptyState title="Belum ada survey." />
                ) : (
                    <ol className="divide-y divide-border">
                        {lead.surveys.map((survey) => {
                            const open = ['DIJADWALKAN', 'MENUNGGU_BAYAR', 'SIAP'].includes(survey.status);

                            return (
                                <li key={survey.id} className="space-y-2 py-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-daiku-dark">
                                                Survey #{survey.sequence}{' '}
                                                <span className="font-normal text-daiku-muted">· {formatDateTime(survey.scheduled_at)}</span>
                                            </p>
                                            <p className="text-xs text-daiku-muted">
                                                {survey.is_outside_pekanbaru ? 'Luar Pekanbaru' : 'Pekanbaru'}
                                                {survey.address && ` · ${survey.address}`}
                                            </p>
                                            {survey.maps_url && (
                                                <a
                                                    href={survey.maps_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-xs underline decoration-daiku-yellow underline-offset-2"
                                                >
                                                    Buka di Google Maps
                                                </a>
                                            )}
                                        </div>
                                        <StatusChip status={survey.status} />
                                    </div>
                                    {survey.status === 'MENUNGGU_BAYAR' && (
                                        <p className="text-xs text-warning-ink">
                                            Menunggu pembayaran RAB Jasa Survey diverifikasi Finance sebelum berangkat.
                                        </p>
                                    )}
                                    {survey.result_note && <p className="text-sm whitespace-pre-line">{survey.result_note}</p>}
                                    {survey.cancel_reason && <p className="text-sm text-daiku-muted">Dibatalkan: {survey.cancel_reason}</p>}
                                    {canWrite && open && (
                                        <div className="flex flex-wrap gap-2">
                                            {survey.status !== 'MENUNGGU_BAYAR' && (
                                                <Button
                                                    size="sm"
                                                    onClick={() => {
                                                        setNote('');
                                                        setErrors({});
                                                        setNoteAction({ kind: 'complete-survey', survey });
                                                    }}
                                                >
                                                    Tandai Selesai
                                                </Button>
                                            )}
                                            <Button size="sm" variant="outline" onClick={() => openSurvey(survey)}>
                                                Ubah Jadwal
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    setNote('');
                                                    setErrors({});
                                                    setNoteAction({ kind: 'cancel-survey', survey });
                                                }}
                                            >
                                                Batalkan Survey
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </SectionCard>

            <Dialog open={followUpOpen} onOpenChange={setFollowUpOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Tambah Follow-up (FU-{nextSequence})</DialogTitle>
                        {suggestLost && <DialogDescription>Sudah {nextSequence - 1} kali follow-up — pertimbangkan tandai Lost.</DialogDescription>}
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="follow-up-date">Tanggal follow-up</Label>
                        <Input id="follow-up-date" type="date" value={followUpDate} onChange={(event) => setFollowUpDate(event.target.value)} />
                        {errors.scheduled_date && <p className="text-sm text-destructive">{errors.scheduled_date}</p>}
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            disabled={processing}
                            onClick={() => visit('post', route('crm.follow-ups.store', { lead: lead.id }), { scheduled_date: followUpDate }, () => setFollowUpOpen(false))}
                        >
                            Jadwalkan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={surveyDialog !== null} onOpenChange={(open) => !open && setSurveyDialog(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{surveyDialog?.survey ? `Ubah Jadwal Survey #${surveyDialog.survey.sequence}` : 'Jadwalkan Survey'}</DialogTitle>
                    </DialogHeader>
                    <SurveyFormFields
                        values={surveyValues}
                        onChange={setSurveyValues}
                        errors={errors}
                        locationEditable={!surveyDialog?.survey}
                        leadAddress={lead.address}
                    />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="button" disabled={processing} onClick={submitSurvey}>
                            Simpan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={noteAction !== null} onOpenChange={(open) => !open && setNoteAction(null)}>
                <DialogContent className="max-w-md">
                    {noteAction && (
                        <>
                            <DialogHeader>
                                <DialogTitle>{noteCopy[noteAction.kind].title}</DialogTitle>
                            </DialogHeader>
                            <div className="space-y-2">
                                <Label htmlFor="timeline-note">{noteCopy[noteAction.kind].label}</Label>
                                <Textarea id="timeline-note" rows={3} value={note} onChange={(event) => setNote(event.target.value)} />
                                {(errors.result_note || errors.reason) && (
                                    <p className="text-sm text-destructive">{errors.result_note ?? errors.reason}</p>
                                )}
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    variant={noteAction.kind === 'cancel-survey' ? 'destructive' : 'default'}
                                    disabled={processing}
                                    onClick={submitNote}
                                >
                                    {noteCopy[noteAction.kind].submit}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
