<?php

namespace App\Enums;

/**
 * PRD §4.2 "Jenis Project" — shared between Design and Project (both
 * daiku_schema.sql `designs.jenis_project` and `projects.jenis_project`
 * use the same set).
 */
enum ProjectType: string
{
    case Toko = 'TOKO';
    case Cafe = 'CAFE';
    case Renovasi = 'RENOVASI';
    case KamarSet = 'KAMAR_SET';
    case KitchenSet = 'KITCHEN_SET';
    case Kantor = 'KANTOR';
    case Arsitektural = 'ARSITEKTURAL';
    case RuangTamuTv = 'RUANG_TAMU_TV';
    case RetailToko = 'RETAIL_TOKO';
    case Lainnya = 'LAINNYA';

    /** Sprint 20 — the name a client reads (company profile, portfolio). */
    public function label(): string
    {
        return match ($this) {
            self::Toko => 'Toko',
            self::Cafe => 'Cafe',
            self::Renovasi => 'Renovasi',
            self::KamarSet => 'Kamar Set',
            self::KitchenSet => 'Kitchen Set',
            self::Kantor => 'Kantor',
            self::Arsitektural => 'Desain Arsitektur',
            self::RuangTamuTv => 'Ruang Tamu & TV',
            self::RetailToko => 'Retail & Toko',
            self::Lainnya => 'Lainnya',
        };
    }

    /** @return list<array{value: string, label: string}> for a Select in the system UI. */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
