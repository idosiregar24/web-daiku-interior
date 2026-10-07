import { SurveyFormFields, type SurveyFormValues } from '@/Components/modules/crm/SurveyFormFields';
import { EMPTY_REFERENCES, RabReferenceFields, type RabReferences, referencePayload } from '@/Components/modules/quotation/RabReferenceFields';
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
import { cn } from '@/lib/utils';
import type { Lead } from '@/types';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { RequiredMark } from '@/Components/shared/RequiredMark';

type RequestType = 'SURVEY' | 'RAB_SURVEY' | 'RAB_DESAIN' | 'RAB_PROYEK' | 'RAB_LAINNYA';

const OPTIONS: { value: RequestType; label: string; hint: string; rab?: boolean }[] = [
    { value: 'SURVEY', label: 'Jadwalkan Survey', hint: 'Survey lokasi — gratis di Pekanbaru, luar kota wajib RAB Jasa Survey.' },
    { value: 'RAB_SURVEY', label: 'RAB Jasa Survey', hint: 'Biaya survey luar Pekanbaru — dibayar sebelum survey berangkat.', rab: true },
    { value: 'RAB_DESAIN', label: 'RAB Jasa Desain', hint: 'Biaya jasa desain — setelah dibayar, Kepala Desain menugaskan arsitek.', rab: true },
    { value: 'RAB_PROYEK', label: 'RAB Proyek', hint: 'Penawaran pekerjaan — boleh tanpa desain dari Daiku.', rab: true },
    // Sprint 14 Sub 02 — any other job, with its own name; runs exactly like a RAB Proyek.
    { value: 'RAB_LAINNYA', label: 'RAB Lainnya', hint: 'Pekerjaan lain dengan nama sendiri (mis. Renovasi Pagar) — alurnya sama dengan RAB Proyek.', rab: true },
];

const SUBMIT_LABEL: Record<RequestType, string> = {
    SURVEY: 'Jadwalkan Survey',
    RAB_SURVEY: 'Buat RAB',
    RAB_DESAIN: 'Buat RAB',
    RAB_PROYEK: 'Buat RAB',
    RAB_LAINNYA: 'Buat RAB',
};

const EMPTY_SURVEY: SurveyFormValues = { scheduled_at: '', address: '', maps_url: '', is_outside_pekanbaru: false };

interface SubmitLeadRequestDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Pick<Lead, 'id' | 'client_name' | 'address' | 'is_outside_home_city'>;
    /** Lead already in Pengajuan Desain/Survey — only the RAB options remain ("Buat RAB"). */
    rabOnly?: boolean;
    /** Sprint 14 Sub 02 — custom names used before, suggested for "RAB Lainnya". */
    customRabNames?: string[];
}

/**
 * Sprint 12 decision #5 — "Ajukan Desain/Survey" replaces "Deal Desain".
 * The lead moves to DEAL_DESAIN (shown as "Pengajuan Desain/Survey");
 * choosing a survey also schedules it; "Minta RAB …" asks the Estimator
 * for that RAB (decision #7 — the note is required). Mirrors
 * SubmitLeadRequestRequest.
 */
export function SubmitLeadRequestDialog({ open, onOpenChange, lead, rabOnly = false, customRabNames = [] }: SubmitLeadRequestDialogProps) {
    const options = rabOnly ? OPTIONS.filter((option) => option.rab) : OPTIONS;
    const [type, setType] = useState<RequestType>(options[0].value);
    const [survey, setSurvey] = useState<SurveyFormValues>(EMPTY_SURVEY);
    const [note, setNote] = useState('');
    const [customName, setCustomName] = useState('');
    // Sprint 14 Sub 01 — links & photos for the Estimator.
    const [references, setReferences] = useState<RabReferences>(EMPTY_REFERENCES);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const isRab = type.startsWith('RAB_');

    useEffect(() => {
        if (open) {
            setType(rabOnly ? 'RAB_SURVEY' : 'SURVEY');
            // Sprint 16 Sub 08 (K15): a lead outside the home city starts as "Luar Pekanbaru".
            setSurvey({ ...EMPTY_SURVEY, is_outside_pekanbaru: lead.is_outside_home_city ?? false });
            setNote('');
            setCustomName('');
            setReferences(EMPTY_REFERENCES);
            setErrors({});
        }
    }, [open, rabOnly]);

    function submit() {
        if (type === 'SURVEY' && !survey.scheduled_at) {
            setErrors({ scheduled_at: 'Jadwal survey wajib diisi.' });
            return;
        }

        if (type === 'RAB_LAINNYA' && customName.trim().length < 3) {
            setErrors({ custom_name: 'Isi nama RAB-nya, mis. "Renovasi Pagar".' });
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
                ...(isRab ? referencePayload(references) : {}),
                ...(type === 'RAB_LAINNYA' ? { custom_name: customName.trim() } : {}),
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
                    <DialogTitle>{rabOnly ? 'Buat RAB' : 'Ajukan Desain/Survey'}</DialogTitle>
                    <DialogDescription>
                        {rabOnly
                            ? `Pilih jenis RAB untuk ${lead.client_name} — Estimator yang menyusun isinya.`
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

                {type === 'RAB_LAINNYA' && (
                    <div className="space-y-2">
                        <Label htmlFor="submit-request-custom-name">Nama RAB<RequiredMark /></Label>
                        <Input
                            id="submit-request-custom-name"
                            list="custom-rab-names"
                            maxLength={100}
                            value={customName}
                            placeholder="mis. Renovasi Pagar, Maintenance AC"
                            onChange={(event) => setCustomName(event.target.value)}
                        />
                        {/* Names used before — one job keeps one spelling. */}
                        <datalist id="custom-rab-names">
                            {customRabNames.map((name) => (
                                <option key={name} value={name} />
                            ))}
                        </datalist>
                        {errors.custom_name && <p className="text-sm text-destructive">{errors.custom_name}</p>}
                    </div>
                )}

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
                    <Label htmlFor="submit-request-note">{isRab ? 'Catatan untuk Estimator' : 'Catatan'}{isRab && <RequiredMark />}</Label>
                    <Textarea
                        id="submit-request-note"
                        rows={isRab ? 4 : 2}
                        maxLength={2000}
                        value={note}
                        placeholder={
                            isRab
                                ? 'Apa yang dikerjakan, ukuran/luas, gaya, bahan, budget klien, tenggat — mis. Kitchen set 3 m + backdrop TV, HPL putih doff, budget ±30 juta, mulai Desember.'
                                : undefined
                        }
                        onChange={(event) => setNote(event.target.value)}
                    />
                    {errors.note && <p className="text-sm text-destructive">{errors.note}</p>}
                </div>

                {isRab && <RabReferenceFields value={references} onChange={setReferences} errors={errors} />}
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
