import { SurveyFormFields, type SurveyFormValues, toDateTimeLocal } from '@/Components/modules/crm/SurveyFormFields';
import { Notice } from '@/Components/shared/Notice';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { formatDateTime } from '@/lib/format';
import type { LeadSurvey } from '@/types';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, CalendarClock, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';

/** QuotationController::surveyPanel() — a RAB Jasa Survey's survey (CEO / Marketing only). */
export interface QuotationSurveyPanel {
    paid: boolean;
    survey: Pick<LeadSurvey, 'id' | 'lead_id' | 'sequence' | 'scheduled_at' | 'address' | 'maps_url' | 'is_outside_pekanbaru' | 'status'> | null;
    canSchedule: boolean;
    canReschedule: boolean;
    leadAddress: string | null;
}

interface QuotationNextStepCardProps {
    quotationId: number;
    lead: { id: number; client_name: string };
    canOpenLead: boolean;
    surveyPanel: QuotationSurveyPanel | null;
}

const EMPTY: SurveyFormValues = { scheduled_at: '', address: '', maps_url: '', is_outside_pekanbaru: true };

/**
 * Sprint 19 Sub 03 — the way from a RAB to its client's lead, and for a paid
 * RAB Jasa Survey the survey itself: scheduled or rescheduled right here
 * (the lead's `crm.surveys.*` routes, same rules), so Marketing doesn't
 * hunt for the lead in another menu. `?survey=new` (the P1 "Survey Lunas"
 * notification and its "Perlu Tindakan" item) opens the dialog.
 */
export function QuotationNextStepCard({ quotationId, lead, canOpenLead, surveyPanel }: QuotationNextStepCardProps) {
    const [open, setOpen] = useState(false);
    const [values, setValues] = useState<SurveyFormValues>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const survey = surveyPanel?.survey ?? null;

    function openDialog() {
        setErrors({});
        setValues(
            survey
                ? { scheduled_at: toDateTimeLocal(survey.scheduled_at), address: survey.address ?? '', maps_url: survey.maps_url ?? '', is_outside_pekanbaru: survey.is_outside_pekanbaru }
                : EMPTY,
        );
        setOpen(true);
    }

    useEffect(() => {
        if (surveyPanel?.canSchedule && new URLSearchParams(window.location.search).get('survey') === 'new') {
            openDialog();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [quotationId, surveyPanel?.canSchedule]);

    function submit() {
        const payload = {
            scheduled_at: values.scheduled_at,
            address: values.address || null,
            maps_url: values.maps_url || null,
            // A RAB Jasa Survey only exists for a survey outside Pekanbaru.
            ...(survey ? {} : { is_outside_pekanbaru: true }),
        };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (serverErrors: Record<string, string>) => setErrors(serverErrors),
            onSuccess: () => {
                setErrors({});
                setOpen(false);
            },
        };

        if (survey) {
            router.put(route('crm.surveys.update', { survey: survey.id }), payload, options);
        } else {
            router.post(route('crm.surveys.store', { lead: lead.id }), payload, options);
        }
    }

    return (
        <SectionCard title="Klien & langkah berikutnya" icon={UserRound} className="mt-6">
            <div className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm">
                        <span className="text-daiku-muted">Klien:</span> <span className="font-medium text-foreground">{lead.client_name}</span>
                    </p>
                    {canOpenLead && (
                        <Link
                            href={route('crm.leads.show', { lead: lead.id })}
                            className="inline-flex items-center gap-1 text-sm font-medium text-foreground underline decoration-daiku-yellow underline-offset-4 hover:decoration-2"
                        >
                            Buka halaman lead
                            <ArrowRight className="size-3.5" aria-hidden />
                        </Link>
                    )}
                </div>

                {surveyPanel && !surveyPanel.paid && !survey && (
                    <Notice tone="info">Jadwal survey bisa dibuat setelah pembayaran RAB ini diverifikasi Finance.</Notice>
                )}

                {survey && (
                    <div className="flex flex-col gap-3 rounded-lg border border-daiku-border p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 text-sm">
                            <p className="flex flex-wrap items-center gap-2 font-medium text-foreground">
                                <CalendarClock className="size-4 text-muted-foreground" aria-hidden />
                                Survey #{survey.sequence} · {formatDateTime(survey.scheduled_at)}
                                <StatusChip status={survey.status} />
                            </p>
                            {survey.address && <p className="mt-0.5 text-xs text-daiku-muted">{survey.address}</p>}
                            {survey.status === 'MENUNGGU_BAYAR' && (
                                <p className="mt-0.5 text-xs text-daiku-muted">Menunggu pembayaran diverifikasi Finance.</p>
                            )}
                        </div>
                        {surveyPanel?.canReschedule && (
                            <Button size="sm" variant="outline" className="w-full sm:w-auto" onClick={openDialog}>
                                Atur Ulang Jadwal
                            </Button>
                        )}
                    </div>
                )}

                {surveyPanel?.canSchedule && (
                    <div className="flex flex-col gap-3 rounded-lg bg-daiku-yellow-light p-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-foreground">
                            Pembayaran survey sudah diverifikasi Finance. Tentukan jadwal survey sekarang — CEO dan PM akan diberi tahu.
                        </p>
                        <Button size="sm" className="w-full shrink-0 sm:w-auto" onClick={openDialog}>
                            <CalendarClock className="size-4" />
                            Jadwalkan Survey
                        </Button>
                    </div>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <ResponsiveDialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{survey ? `Atur Ulang Jadwal Survey #${survey.sequence}` : 'Jadwalkan Survey'}</DialogTitle>
                        <DialogDescription>
                            {lead.client_name} — survey luar Pekanbaru. CEO dan semua PM menerima notifikasi jadwalnya.
                        </DialogDescription>
                    </DialogHeader>
                    <SurveyFormFields
                        values={values}
                        onChange={setValues}
                        errors={errors}
                        locationEditable={false}
                        leadAddress={surveyPanel?.leadAddress ?? null}
                    />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="button" disabled={processing || !values.scheduled_at} onClick={submit}>
                            Simpan Jadwal
                        </Button>
                    </DialogFooter>
                </ResponsiveDialogContent>
            </Dialog>
        </SectionCard>
    );
}
