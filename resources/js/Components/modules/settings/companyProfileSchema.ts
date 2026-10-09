import { z } from 'zod';

// Sprint 20 — Zod pieces shared by the company profile settings pages
// (Profil Publik, Portofolio). Each mirrors its Form Request's rules + messages.

const NEXT_YEAR = new Date().getFullYear() + 1;

/** '' or a whole number within [min, max] — a `nullable|integer|min|max` rule on a text input. */
export const optionalInt = (min: number, max: number, messages: { integer: string; min: string; max: string }) =>
    z
        .string()
        .trim()
        .refine((value) => value === '' || /^\d+$/.test(value), messages.integer)
        .refine((value) => value === '' || !/^\d+$/.test(value) || Number(value) >= min, messages.min)
        .refine((value) => value === '' || !/^\d+$/.test(value) || Number(value) <= max, messages.max);

/** '' → null, else the number — what the server's nullable integer expects. */
export const intOrNull = (value: string) => (value.trim() === '' ? null : Number(value));

// Mirrors App\Http\Requests\CompanyProfile\StorePortfolioItemRequest — the
// fields of the "Tambah Portofolio" dialog; the edit form adds the rest.
export const portfolioBaseSchema = z.object({
    title: z.string().trim().min(1, 'Judul portofolio wajib diisi.').max(150, 'Judul maksimal 150 karakter.'),
    project_type: z.string().min(1, 'Jenis proyek wajib dipilih.'),
    /** City id as a string ('' = none) — CitySelect's value. */
    city_id: z.string(),
    location_label: z.string().max(100, 'Lokasi maksimal 100 karakter.'),
    year: optionalInt(1990, NEXT_YEAR, {
        integer: 'Tahun harus berupa angka.',
        min: 'Tahun minimal 1990.',
        max: 'Tahun tidak boleh melewati tahun depan.',
    }),
});

export type PortfolioBaseValues = z.infer<typeof portfolioBaseSchema>;

export function portfolioBasePayload(values: PortfolioBaseValues) {
    return {
        title: values.title.trim(),
        project_type: values.project_type,
        city_id: values.city_id === '' ? null : Number(values.city_id),
        location_label: values.location_label.trim() === '' ? null : values.location_label.trim(),
        year: intOrNull(values.year),
    };
}
