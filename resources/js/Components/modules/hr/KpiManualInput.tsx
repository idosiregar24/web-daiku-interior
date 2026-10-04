import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors App\Http\Requests\HR\UpdateKpiScoreRequest (empty = clear the value).
const schema = z.object({
    actual: z.string().refine((v) => v.trim() === '' || (!isNaN(Number(v)) && Number(v) >= 0), 'Nilai harus angka, minimal 0'),
});

type FormValues = z.infer<typeof schema>;

interface KpiManualInputProps {
    scoreId: number;
    actual: number | null;
    label: string;
}

/** Inline input for one MANUAL KPI actual (HR, OPEN month only). */
export function KpiManualInput({ scoreId, actual, label }: KpiManualInputProps) {
    const initial = actual === null ? '' : String(actual);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { actual: initial } });

    useEffect(() => {
        form.reset({ actual: initial });
    }, [initial]);

    function onSubmit(values: FormValues) {
        const value = values.actual.trim();

        router.patch(
            route('hr.kpi.scores.update', { kpi_score: scoreId }),
            { actual: value === '' ? null : Number(value) },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => form.setError('actual', { message: errors.actual ?? errors.period ?? 'Gagal menyimpan.' }),
            },
        );
    }

    const error = form.formState.errors.actual?.message;
    const dirty = form.watch('actual') !== initial;

    return (
        <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col items-end gap-1">
            <div className="flex items-center gap-1">
                <Input
                    type="number"
                    min="0"
                    step="any"
                    inputMode="decimal"
                    aria-label={`Nilai aktual ${label}`}
                    aria-invalid={!!error}
                    className="h-8 w-24 text-right tabular-nums"
                    placeholder="Isi"
                    {...form.register('actual')}
                />
                <Button
                    type="submit"
                    size="icon-sm"
                    variant={dirty ? 'default' : 'ghost'}
                    disabled={!dirty || form.formState.isSubmitting}
                    aria-label={`Simpan ${label}`}
                >
                    <Check className="size-4" />
                </Button>
            </div>
            {error && <p className="text-xs text-error-ink">{error}</p>}
        </form>
    );
}
