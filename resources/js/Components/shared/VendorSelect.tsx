import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { VendorOption } from '@/types';

interface VendorSelectProps {
    /** Vendor id as a string ('' = nothing picked) — react-hook-form friendly. */
    value: string;
    onChange: (value: string) => void;
    /** Active vendors from Master Vendor (`Vendor::options()`). */
    vendors: VendorOption[];
    /** Offer a "no vendor" choice (optional fields). */
    allowEmpty?: boolean;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
}

const NONE = '__none';

/**
 * Master Vendor dropdown (Sprint 11 Sub 2). Vendors are only added by the
 * CEO/SUPERADMIN in Data Master → Vendor; every other form picks here.
 */
export function VendorSelect({ value, onChange, vendors, allowEmpty, disabled, className, ...props }: VendorSelectProps) {
    return (
        <div className="space-y-1">
            <Select
                value={value === '' && allowEmpty ? NONE : value}
                onValueChange={(next) => onChange(next === NONE ? '' : next)}
                disabled={disabled}
            >
                <SelectTrigger className={className ?? 'w-full'} aria-label={props['aria-label'] ?? 'Vendor'}>
                    <SelectValue placeholder="Pilih vendor" />
                </SelectTrigger>
                <SelectContent>
                    {allowEmpty && <SelectItem value={NONE}>Tanpa vendor</SelectItem>}
                    {vendors.map((vendor) => (
                        <SelectItem key={vendor.id} value={String(vendor.id)}>
                            {vendor.name}
                            {vendor.type === 'JASA' && <span className="text-daiku-muted"> · Jasa</span>}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <p className="text-xs text-daiku-muted">
                Vendor belum terdaftar? Minta CEO menambahkannya di Data Master → Vendor.
            </p>
        </div>
    );
}
