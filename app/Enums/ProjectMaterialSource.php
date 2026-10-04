<?php

namespace App\Enums;

/**
 * Sprint 11 Sub 3 — where a project material line comes from (§5.1):
 * GUDANG = taken from Logistics' stock, charged at the warehouse price;
 * PEMBELIAN = a catalog item bought for this project, charged at the
 * actual purchase price; CUSTOM = a one-off item that never enters the
 * catalog (approved by Logistics through a request, Sub 4).
 */
enum ProjectMaterialSource: string
{
    case Gudang = 'GUDANG';
    case Pembelian = 'PEMBELIAN';
    case Custom = 'CUSTOM';

    public function label(): string
    {
        return match ($this) {
            self::Gudang => 'Gudang',
            self::Pembelian => 'Pembelian',
            self::Custom => 'Custom',
        };
    }

    /** Lines that are bought (qty + actual price recorded) rather than issued from stock. */
    public function isPurchased(): bool
    {
        return $this !== self::Gudang;
    }
}
