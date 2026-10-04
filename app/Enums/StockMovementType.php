<?php

namespace App\Enums;

/**
 * PRD §4.8 "Manajemen Stok" — penerimaan (IN) / barang keluar ke proyek
 * (OUT), plus Sprint 11's RETURN (a project's leftover going back into
 * stock; `project_id` = the project it came from) and MERGE_OUT /
 * MERGE_IN (a duplicate catalog item's stock moved onto the item it was
 * merged into, §5.5 Lapis 6).
 */
enum StockMovementType: string
{
    case In = 'IN';
    case Out = 'OUT';
    case Return = 'RETURN';
    case MergeOut = 'MERGE_OUT';
    case MergeIn = 'MERGE_IN';

    /** Does this movement add to the item's stock? */
    public function isIncoming(): bool
    {
        return in_array($this, [self::In, self::Return, self::MergeIn], true);
    }
}
