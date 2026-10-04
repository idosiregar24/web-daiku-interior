import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { MaterialCategory } from '@/types';

type CategoryOption = Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'> & { is_active?: boolean };

interface MaterialCategorySelectProps {
    value: string;
    onChange: (value: string) => void;
    categories: CategoryOption[];
    disabled?: boolean;
}

/**
 * Sprint 11 decision #11 — material categories come from Data Master
 * (SUPERADMIN). Inactive ones only show when they're the current value.
 */
export function MaterialCategorySelect({ value, onChange, categories, disabled }: MaterialCategorySelectProps) {
    const options = categories.filter((category) => category.is_active !== false || String(category.id) === value);

    return (
        <Select value={value} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger className="w-full" aria-label="Kategori">
                <SelectValue placeholder="Pilih kategori" />
            </SelectTrigger>
            <SelectContent>
                {options.map((category) => (
                    <SelectItem key={category.id} value={String(category.id)}>
                        {category.name} <span className="text-daiku-muted">· {category.code_prefix}</span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
