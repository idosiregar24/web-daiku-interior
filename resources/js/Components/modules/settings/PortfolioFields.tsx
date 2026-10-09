import type { PortfolioBaseValues } from '@/Components/modules/settings/companyProfileSchema';
import { CitySelect } from '@/Components/shared/CitySelect';
import { StatusChip } from '@/Components/shared/StatusChip';
import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import type { CityOption, ProjectTypeOption } from '@/types';
import { ShieldAlert, ShieldCheck } from 'lucide-react';
import type { Control, FieldPath } from 'react-hook-form';

/** Terbit / Draf — whether the item is on the public site. */
export function PublishChip({ published }: { published: boolean }) {
    return published ? <StatusChip status="PUBLISHED" label="Terbit" tone="success" /> : <StatusChip status="DRAFT" label="Draf" tone="neutral" />;
}

/** Whether the client agreed to the project being shown — required to publish. */
export function ConsentMark({ consent, className }: { consent: boolean; className?: string }) {
    const Icon = consent ? ShieldCheck : ShieldAlert;

    return (
        <span className={cn('inline-flex items-center gap-1 text-xs', consent ? 'text-success-ink' : 'text-warning-ink', className)}>
            <Icon className="size-3.5" aria-hidden />
            {consent ? 'Izin klien ada' : 'Belum ada izin klien'}
        </span>
    );
}

/**
 * Title, type, city, district and year of a portfolio item — shared by
 * the "Tambah Portofolio" dialog and the edit form (both mirror
 * StorePortfolioItemRequest for these fields).
 */
export function PortfolioBaseFields<T extends PortfolioBaseValues>({
    control,
    projectTypes,
    cities,
    autoFocus,
}: {
    control: Control<T>;
    projectTypes: ProjectTypeOption[];
    cities: CityOption[];
    autoFocus?: boolean;
}) {
    const name = (field: keyof PortfolioBaseValues) => field as FieldPath<T>;

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <FormField
                control={control}
                name={name('title')}
                render={({ field }) => (
                    <FormItem className="sm:col-span-2">
                        <FormLabel required>Judul</FormLabel>
                        <FormControl>
                            <Input {...field} value={field.value as string} autoFocus={autoFocus} placeholder="mis. Kitchen Set Putih Doff dengan Island" />
                        </FormControl>
                        <FormDescription>Tampil di situs dan jadi alamat halamannya. Jangan tulis nama klien.</FormDescription>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <FormField
                control={control}
                name={name('project_type')}
                render={({ field }) => (
                    <FormItem>
                        <FormLabel required>Jenis Proyek</FormLabel>
                        <Select value={field.value as string} onValueChange={field.onChange}>
                            <FormControl>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Pilih jenis" />
                                </SelectTrigger>
                            </FormControl>
                            <SelectContent>
                                {projectTypes.map((type) => (
                                    <SelectItem key={type.value} value={type.value}>
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <FormDescription>Menentukan halaman layanan tempat portofolio ini ikut tampil.</FormDescription>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <FormField
                control={control}
                name={name('year')}
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Tahun Selesai</FormLabel>
                        <FormControl>
                            <Input
                                {...field}
                                value={field.value as string}
                                inputMode="numeric"
                                maxLength={4}
                                placeholder={String(new Date().getFullYear())}
                                onChange={(event) => field.onChange(event.target.value.replace(/\D/g, ''))}
                            />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <FormField
                control={control}
                name={name('city_id')}
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Kota</FormLabel>
                        <FormControl>
                            <CitySelect value={field.value as string} onChange={field.onChange} cities={cities} />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <FormField
                control={control}
                name={name('location_label')}
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Kawasan</FormLabel>
                        <FormControl>
                            <Input {...field} value={field.value as string} placeholder="mis. Panam" />
                        </FormControl>
                        <FormDescription>Nama daerah saja — bukan alamat lengkap klien.</FormDescription>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </div>
    );
}
