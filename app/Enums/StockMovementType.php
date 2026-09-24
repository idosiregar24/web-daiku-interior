<?php

namespace App\Enums;

/** PRD §4.8 "Manajemen Stok" — penerimaan (IN) / pemakaian per proyek (OUT). */
enum StockMovementType: string
{
    case In = 'IN';
    case Out = 'OUT';
}
