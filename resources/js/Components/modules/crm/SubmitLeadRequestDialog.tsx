import { SurveyFormFields, type SurveyFormValues } from '@/Components/modules/crm/SurveyFormFields';
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
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Lead } from '@/types';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type RequestType = 'SURVEY' | 'RAB_SURVEY' | 'RAB_DESAIN' | 'RAB_PROYEK';

const OPTIONS: { value: RequestType; label: string; hint: string; rab?: boolean }[] = [
    { value: 'SURVEY', label: 'Jadwalkan Survey', hint: 'Survey lokasi — gratis di Pekanbaru, luar kota wajib RAB Jasa Survey.' },
    { value: 'RAB_SURVEY', label: 'Minta RAB Jasa Survey', hint: 'Biaya survey luar Pekanbaru — dibayar sebelum survey berangkat.', rab: true },
    { value: 'RAB_DESAIN', label: 'Minta RAB Jasa Desain', hint: 'Biaya jasa desain — setelah dibayar, Kepala Desain menugaskan arsitek.', rab: true },
    { value: 'RAB_PROYEK', label: 'Minta RAB Proyek', hint: 'Penawaran pekerjaan — boleh tanpa desain dari Daiku.', rab: true },
];

const SUBMIT_LABEL: Record<RequestType, string> = {
    SURVEY: 'Jadwalkan Survey',
    RAB_SURVEY: 'Minta RAB',
    RAB_DESAIN: 'Minta RAB',
    RAB_PROYEK: 'Minta RAB',
};

const EMPTY_SURVEY: SurveyFormValues = { scheduled_at: '', address: '', maps_url: '', is_outside_pekanbaru: false };

interface SubmitLeadRequestDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Pick<Lead, 'id' | 'client_name' | 'address'>;
    /** Lead already in Pengajuan Desain/Survey — only the "Minta RAB …" options remain. */
    rabOnly?: boolean;
}

/**
 * Sprint 12 decision #5 — "Ajukan Desain/Survey" replaces "Deal Desain".
 * The lead moves to DEAL_DESAIN (shown as "Pengajuan Desain/Survey");
 * choosing a survey also schedules it; "Minta RAB …" asks the Estimator
 * for that RAB (decision #7 — the note is required). Mirrors
 * SubmitLeadRequestRequest.
 */
export function SubmitLeadRequestDialog({ open, onOpenChange, lead, rabOnly = false }: SubmitLeadRequestDialogProps) {
    const options = rabOnly ? OPTIONS.filter((option) => option.rab) : OPTIONS;
    const [type, setType] = useState<RequestType>(options[0].value);
    const [survey, setSurvey] = useState<SurveyFormValues>(EMPTY_SURVEY);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const isRab = type.startsWith('RAB_');

    useEffect(() => {
        if (open) {
            setType(rabOnly ? 'RAB_SURVEY' : 'SURVEY');
            setSurvey(EMPTY_SURVEY);
            setNote('');
            setErrors({});
        }
    }, [open, rabOnly]);

    function submit() {
        if (type === 'SURVEY' && !survey.scheduled_at) {
            setErrors({ scheduled_at: 'Jadwal survey wajib diisi.' });
            return;
        }

        if (isRab && !note.trim()) {
            setErrors({ note: 'Catatan untuk Estimator wajib diisi.' });
            return;
        }

        router.post(
            route('crm.leads.submitRequest', { lead: lead.id }),
            {
                type,
                note: note || null,
                ...(type === 'SURVEY'
                    ? {
                          scheduled_at: survey.scheduled_at,
                          address: survey.address || null,
                          maps_url: survey.maps_url || null,
                          is_outside_pekanbaru: survey.is_outside_pekanbaru,
                      }
                    : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (serverErrors) => setErrors(serverErrors),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{rabOnly ? 'Minta RAB' : 'Ajukan Desain/Survey'}</DialogTitle>
                    <DialogDescription>
                        {rabOnly
                            ? `Estimator mendapat notifikasi untuk menyusun RAB ${lead.client_name}.`
                            : `${lead.client_name} masuk tahap Pengajuan Desain/Survey.`}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    {options.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => setType(option.value)}
                            className={cn(
                                'rounded-lg border p-3 text-left text-sm transition-colors',
                                type === option.value ? 'border-daiku-yellow bg-daiku-yellow-light' : 'border-border hover:bg-daiku-gray/60',
                            )}
                        >
                            <p className="font-medium text-daiku-dark">{option.label}</p>
                            <p className="text-xs text-daiku-muted">{option.hint}</p>
                        </button>
                    ))}
                </div>

                {type === 'SURVEY' && (
                    <SurveyFormFields
                        values={survey}
                        onChange={setSurvey}
                        errors={errors}
                        locationEditable
                        leadAddress={lead.address}
                    />
                )}

                <div className="space-y-2">
                    <Label htmlFor="submit-request-note">{isRab ? 'Catatan untuk Estimator' : 'Catatan (opsional)'}</Label>
                    <Textarea
                        id="submit-request-note"
                        rows={isRab ? 3 : 2}
                        value={note}
                        placeholder={isRab ? 'mis. Kitchen set 3 m + backdrop TV, material HPL, budget ±30 juta' : undefined}
                        onChange={(event) => setNote(event.target.value)}
                    />
                    {errors.note && <p className="text-sm text-destructive">{errors.note}</p>}
                </div>
                {errors.type && <p className="text-sm text-destructive">{errors.type}</p>}

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button type="button" onClick={submit} disabled={processing}>
                        {SUBMIT_LABEL[type]}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
