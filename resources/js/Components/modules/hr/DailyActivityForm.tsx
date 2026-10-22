import { SavedHint, useSavedHint } from '@/Components/shared/SavedHint';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { quietSuccess } from '@/hooks/useFlashToasts';
import type { DailyActivityEntry } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** Mirrors DailyActivityRequest. */
export const DAILY_ACTIVITY_MAX = 5000;

const schema = z.object({
    description: z
        .string()
        .trim()
        .min(1, 'Aktivitas wajib diisi.')
        .max(DAILY_ACTIVITY_MAX, `Aktivitas maksimal ${DAILY_ACTIVITY_MAX} karakter.`),
    project_id: z.string(),
});

type FormValues = z.infer<typeof schema>;

const NO_PROJECT = 'none';

export interface DailyActivityProjectOption {
    id: number;
    name: string;
}

/**
 * Sprint 29 Sub 13 — Aktivitas Harian: what the page reloads after a save
 * (today's list only; history and the shared flash/badges ride along).
 */
export const DAILY_ACTIVITY_VISIT = {
    only: ['activities', 'flash', 'navBadges'],
    preserveState: true,
    preserveScroll: true,
    ...quietSuccess,
};

interface DailyActivityFormProps {
    projects: DailyActivityProjectOption[];
    /** Editing an entry of today; omitted = add a new one (the form stays open — Sub 01 K4). */
    entry?: DailyActivityEntry;
    onSaved?: () => void;
    onCancel?: () => void;
}

/**
 * One entry: free text (several lines) and an optional running project.
 * Adding keeps the form open and empties it, focus back in the text box;
 * Ctrl/⌘ + Enter saves. No success pop-up — "Tersimpan ✓" beside the button.
 */
export function DailyActivityForm({ projects, entry, onSaved, onCancel }: DailyActivityFormProps) {
    const [saving, setSaving] = useState(false);
    const saved = useSavedHint();
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            description: entry?.description ?? '',
            project_id: entry?.project ? String(entry.project.id) : NO_PROJECT,
        },
    });

    function onSubmit(values: FormValues) {
        const data = {
            description: values.description,
            project_id: values.project_id === NO_PROJECT ? null : Number(values.project_id),
        };
        const options = {
            ...DAILY_ACTIVITY_VISIT,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) =>
                    form.setError(field === 'project_id' ? 'project_id' : 'description', { message }),
                ),
            onSuccess: () => {
                if (!entry) {
                    form.reset({ description: '', project_id: values.project_id });
                    form.setFocus('description');
                }
                saved.flash();
                onSaved?.();
            },
        };

        if (entry) {
            router.put(route('daily-activities.update', { daily_activity: entry.id }), data, options);
        } else {
            router.post(route('daily-activities.store'), data, options);
        }
    }

    return (
        <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-3">
                <FormField
                    control={form.control}
                    name="description"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel required>Aktivitas</FormLabel>
                            <FormControl>
                                <Textarea
                                    {...field}
                                    rows={4}
                                    maxLength={DAILY_ACTIVITY_MAX}
                                    placeholder={'mis. Survey lokasi klien di Medan Johor\nMenyusun laporan stok bulanan'}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                                            event.preventDefault();
                                            void form.handleSubmit(onSubmit)();
                                        }
                                    }}
                                />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <FormField
                        control={form.control}
                        name="project_id"
                        render={({ field }) => (
                            <FormItem className="flex-1">
                                <FormLabel>Proyek</FormLabel>
                                <Select value={field.value} onValueChange={field.onChange}>
                                    <FormControl>
                                        <SelectTrigger className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                    </FormControl>
                                    <SelectContent>
                                        <SelectItem value={NO_PROJECT}>Tanpa proyek</SelectItem>
                                        {projects.map((project) => (
                                            <SelectItem key={project.id} value={String(project.id)}>
                                                {project.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
                        <SavedHint shown={saved.shown && !entry} />
                        {onCancel && (
                            <Button type="button" variant="outline" className="h-11 sm:h-9" disabled={saving} onClick={onCancel}>
                                Batal
                            </Button>
                        )}
                        <Button type="submit" className="h-11 sm:h-9" disabled={saving}>
                            {saving && <Loader2 className="size-4 animate-spin" />}
                            {entry ? 'Simpan Perubahan' : 'Simpan & tambah lagi'}
                        </Button>
                    </div>
                </div>
            </form>
        </Form>
    );
}
