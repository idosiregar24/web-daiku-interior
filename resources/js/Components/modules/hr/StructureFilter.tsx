import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { Division } from '@/types';

const ALL = 'all';

interface StructureFilterProps {
    /** Divisi → Jabatan master (with `positions`). */
    structure: Division[];
    division: number | null;
    position: number | null;
    /** Picking a division clears the position; picking a position keeps its division. */
    onChange: (value: { division: number | null; position: number | null }) => void;
}

/**
 * Division + position filter shared by every SDM list (decision #10 — all
 * SDM recaps filter per divisi / per jabatan). Sends ids; the server
 * applies them with `Employee::scopeInStructure()`.
 */
export function StructureFilter({ structure, division, position, onChange }: StructureFilterProps) {
    const positions = (division ? structure.filter((d) => d.id === division) : structure).flatMap((d) => d.positions ?? []);

    return (
        <>
            <Select
                value={division ? String(division) : ALL}
                onValueChange={(value) => onChange({ division: value === ALL ? null : Number(value), position: null })}
            >
                <SelectTrigger className="sm:w-44" aria-label="Filter divisi">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>Semua divisi</SelectItem>
                    {structure.map((d) => (
                        <SelectItem key={d.id} value={String(d.id)}>
                            {d.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Select
                value={position ? String(position) : ALL}
                onValueChange={(value) => {
                    const id = value === ALL ? null : Number(value);
                    const owner = id ? structure.find((d) => d.positions?.some((p) => p.id === id)) : null;
                    onChange({ division: owner?.id ?? division, position: id });
                }}
            >
                <SelectTrigger className="sm:w-48" aria-label="Filter jabatan">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>Semua jabatan</SelectItem>
                    {positions.map((p) => (
                        <SelectItem key={p.id} value={String(p.id)}>
                            {p.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </>
    );
}
