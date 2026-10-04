import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';

export interface SurveyFormValues {
    scheduled_at: string;
    address: string;
    maps_url: string;
    is_outside_pekanbaru: boolean;
}

interface SurveyFormFieldsProps {
    values: SurveyFormValues;
    onChange: (values: SurveyFormValues) => void;
    errors: Partial<Record<keyof SurveyFormValues, string>>;
    /** Inside/outside Pekanbaru is set once, when scheduling (LeadSurveyRequest). */
    locationEditable: boolean;
    /** The lead's own address — used when the survey's is left empty. */
    leadAddress: string | null;
}

/**
 * Sprint 12 decision #3 — the fields of a site survey, shared by "Jadwalkan
 * Survey", "Ubah Jadwal" and the "Ajukan Desain/Survey" dialog. Mirrors
 * App\Http\Requests\CRM\LeadSurveyRequest.
 */
export function SurveyFormFields({ values, onChange, errors, locationEditable, leadAddress }: SurveyFormFieldsProps) {
    const set = (patch: Partial<SurveyFormValues>) => onChange({ ...values, ...patch });

    return (
        <div className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor="survey-scheduled-at">Jadwal Survey</Label>
                <Input
                    id="survey-scheduled-at"
                    type="datetime-local"
                    value={values.scheduled_at}
                    onChange={(event) => set({ scheduled_at: event.target.value })}
                />
                {errors.scheduled_at && <p className="text-sm text-destructive">{errors.scheduled_at}</p>}
            </div>
            <div className="space-y-2">
                <Label htmlFor="survey-address">Alamat Survey</Label>
                <Textarea
                    id="survey-address"
                    rows={2}
                    value={values.address}
                    placeholder={leadAddress ? `Kosongkan untuk memakai alamat lead: ${leadAddress}` : 'Alamat lokasi survey'}
                    onChange={(event) => set({ address: event.target.value })}
                />
                {errors.address && <p className="text-sm text-destructive">{errors.address}</p>}
            </div>
            <div className="space-y-2">
                <Label htmlFor="survey-maps">Link Google Maps</Label>
                <Input
                    id="survey-maps"
                    value={values.maps_url}
                    placeholder="Kosongkan untuk memakai link Maps lead"
                    onChange={(event) => set({ maps_url: event.target.value })}
                />
                {errors.maps_url && <p className="text-sm text-destructive">{errors.maps_url}</p>}
            </div>
            {locationEditable && (
                <label className="flex items-start justify-between gap-3 rounded-lg border border-daiku-border p-3">
                    <span className="text-sm">
                        <span className="font-medium text-foreground">Lokasi di luar Pekanbaru</span>
                        <span className="block text-xs text-daiku-muted">
                            Survey luar kota wajib RAB Jasa Survey dan dibayar dulu — status menunggu pembayaran sampai
                            diverifikasi Finance.
                        </span>
                    </span>
                    <Switch
                        checked={values.is_outside_pekanbaru}
                        onCheckedChange={(checked) => set({ is_outside_pekanbaru: checked })}
                    />
                </label>
            )}
        </div>
    );
}

/** "2026-10-12T10:00" for an `<input type="datetime-local">` from an ISO/DB datetime. */
export function toDateTimeLocal(value: string | null | undefined): string {
    if (!value) return '';
    const date = new Date(value);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
