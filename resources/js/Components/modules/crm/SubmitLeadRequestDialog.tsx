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

type RequestType = 'SURVEY' | 'DESAIN';

const OPTIONS: { value: RequestType | 'RAB'; label: string; hint: string; disabled?: boolean }[] = [
    { value: 'SURVEY', label: 'Jadwalkan Survey', hint: 'Survey lokasi — gratis di Pekanbaru, luar kota wajib RAB Jasa Survey.' },
    { value: 'DESAIN', label: 'Ajukan Desain', hint: 'Lanjut ke tim desain tanpa survey.' },
    { value: 'RAB', label: 'Minta RAB Jasa Survey / Jasa Desain / Proyek', hint: 'Segera — dibuka di tahap berikutnya.', disabled: true },
];

const EMPTY_SURVEY: SurveyFormValues = { scheduled_at: '', address: '', maps_url: '', is_outside_pekanbaru: false };

interface SubmitLeadRequestDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Pick<Lead, 'id' | 'client_name' | 'address'>;
}

/**
 * Sprint 12 decision #5 — "Ajukan Desain/Survey" replaces "Deal Desain".
 * The lead moves to DEAL_DESAIN (shown as "Pengajuan Desain/Survey");
 * choosing a survey also schedules it. Mirrors SubmitLeadRequestRequest.
 */
export function SubmitLeadRequestDialog({ open, onOpenChange, lead }: SubmitLeadRequestDialogProps) {
    const [type, setType] = useState<RequestType>('SURVEY');
    const [survey, setSurvey] = useState<SurveyFormValues>(EMPTY_SURVEY);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setType('SURVEY');
            setSurvey(EMPTY_SURVEY);
            setNote('');
            setErrors({});
        }
    }, [open]);

    function submit() {
        if (type === 'SURVEY' && !survey.scheduled_at) {
            setErrors({ scheduled_at: 'Jadwal survey wajib diisi.' });
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
                    <DialogTitle>Ajukan Desain/Survey</DialogTitle>
                    <DialogDescription>{lead.client_name} masuk tahap Pengajuan Desain/Survey.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    {OPTIONS.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            disabled={option.disabled}
                            onClick={() => !option.disabled && setType(option.value as RequestType)}
                            className={cn(
                                'rounded-lg border p-3 text-left text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-60',
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
                    <Label htmlFor="submit-request-note">Catatan (opsional)</Label>
                    <Textarea id="submit-request-note" rows={2} value={note} onChange={(event) => setNote(event.target.value)} />
                </div>
                {errors.type && <p className="text-sm text-destructive">{errors.type}</p>}

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button type="button" onClick={submit} disabled={processing}>
                        {type === 'SURVEY' ? 'Jadwalkan Survey' : 'Ajukan Desain'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
